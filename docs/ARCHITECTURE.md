# Architecture & Roadmap

## Repositories
- `liquor-backend` (Laravel 13, PHP 8.4): JSON API under `/api/v1`, Sanctum token auth, admin API under `/api/v1/admin`.
- `liquor-frontend` (Next.js 16 App Router, TS strict, Tailwind 4): storefront + admin CMS UI. Independently deployable; talks to the API only.

## Backend layout
Models in `app/Models`; business rules in `app/Services/<Module>`; thin controllers in `app/Http/Controllers/Api/V1`; Form Requests, API Resources and Enums in their standard folders.

| Module | Responsibility |
| --- | --- |
| Administration | Roles, permissions, audit log, settings |
| Catalog | Categories, brands, products, variants, images |
| Inventory | Stock levels, reservations, movement ledger |
| (planned) Cart, Checkout, Orders, Payments, Shipping, Promotions, Content, Blog, Reviews, Reporting | See roadmap |

## Key decisions
- **Money**: stored as integer kobo (`*_kobo` BIGINT). No floats anywhere in price arithmetic. API serialises both kobo and a formatted NGN string.
- **Identifiers**: auto-increment ids internally; public URLs use unique slugs. Orders (later) get an unguessable public reference to prevent enumeration.
- **Authorization**: roles -> permissions (DB tables, seeded). Admin routes use a `permission:<slug>` middleware backed by `User::hasPermission()`; policies are added per resource. Frontend role checks are cosmetic only.
- **Inventory**: every change goes through `StockAdjuster`, which runs in a transaction with `lockForUpdate()` on the variant row and writes an `inventory_movements` row (variant, delta, reason, admin, timestamp). `on_hand` and `reserved` live on the variant; available = on_hand - reserved.
- **Audit**: `AuditLogger::record()` for sensitive admin actions.
- **Settings**: typed key/value `settings` table for global/business config only (never catalog data), cached.
- **Database portability**: migrations run on MySQL 8 (production) and SQLite (tests/local). No MySQL-only syntax.
- **Payments (planned)**: `PaymentGateway` interface with Paystack and Flutterwave adapters over Laravel's HTTP client (no SDK dependency); server-side verification, signature-checked idempotent webhooks, amount/currency checked against the stored order.
- **Compliance (planned)**: configurable minimum age, product/zone restrictions and notices via settings; a pluggable `AgeVerifier` contract. A self-declared age gate is not proof of age. Nigerian federal/state licensing, advertising and delivery rules must be verified with qualified local counsel before launch.

## Roadmap
1. **Foundation** (done): Sanctum auth, RBAC, audit logger, settings repository, Nigerian phone normaliser.
   **Catalog read API + inventory ledger** (done): products/variants/images, categories, brands (models only), public product + category endpoints, `StockAdjuster`.
   **Cart, delivery zones, checkout, stock reservation** (done, see contract below). **Payments** (Paystack + Flutterwave, webhooks, reconciliation, refunds) are built (see below). Discounts and fulfilment/order management are built (see below).
2. **Catalog & CMS** (remaining): admin CRUD + CSV import/export, brand endpoints, cache invalidation, collections, homepage builder, pages, journal.
3. **Commerce**: search, cart, delivery zones, checkout, orders, Paystack, Flutterwave, webhooks, refunds.
4. **Customer experience**: accounts, wishlist, tracking, discovery, gifting.
5. **Hardening**: caching, SEO endpoints, rate limits, reporting, deployment docs.
6. **Frontend**: design tokens + system, storefront pages, admin CMS UI, tests (Vitest + Playwright).

## Local environment notes
MySQL is not installed on the dev machine used for scaffolding, so dev and tests use SQLite. Run the suite against MySQL 8 in CI before release.

## Cart and checkout API contract (v1)
- **Cart identity:** signed-in customers (Bearer token) use their own cart; guests send `X-Cart-Token: <uuid>` (returned in the `X-Cart-Token` header and `data.token` when a cart is created). Items are only ever reached through the caller's own cart. On login/register, send the guest token to merge it into the customer's cart (quantities add, capped per line).
- **Endpoints:** `GET /cart` (optional `?state=&city=` for a delivery estimate), `POST /cart/items {variant_id, quantity}`, `PATCH|DELETE /cart/items/{id}`, `DELETE /cart`, `GET /delivery/zones`, `POST /checkout`, `GET /orders/{reference}/status`, `GET /me/orders`, `GET /me/orders/{reference}`.
- **Server-authoritative:** carts are re-priced and re-validated from the database on every read. Each line carries `issues` (`unavailable`, `out_of_stock`, `insufficient_stock`, `over_line_limit`), `max_quantity`, and `price_changed_from` when the live price differs from when it was added. Nothing monetary from the client is used.
- **Checkout** requires an `Idempotency-Key` header (16-64 chars `[A-Za-z0-9_-]`), unique per cart. Same key + same body returns the original order (`200`, `Idempotent-Replayed: true`); same key + different body is `409 idempotency_key_reused`. A new key on a cart with an unpaid order cancels that order and releases its stock. The cart is kept so a failed payment can be retried.
- **Stock:** checkout reserves stock (`reserved`) for `commerce.reservation_minutes` (default 30). `orders:expire-pending` (scheduled every minute) expires unpaid orders and releases stock. `OrderLifecycle::markPaid` (internal, called only after gateway verification) converts the reservation into a sale with a ledger row.
- **Errors** are `{message, code, details?}`: `cart_empty`, `cart_has_issues` (details.lines), `insufficient_stock`, `variant_unavailable`, `quantity_limit`, `order_limit`, `delivery_unavailable`, `age_not_verified`, `idempotency_key_reused`, plus `422` validation errors.
- **Settings** (`settings` table, group `commerce`, overriding `config/commerce.php`): `minimum_age` (18), `reservation_minutes`, `max_quantity_per_line`, `max_units_per_order`. These defaults are placeholders: confirm minimum age, limits and delivery restrictions with qualified local counsel.
- **Age check** goes through the `AgeVerifier` contract. The default only records a self-declaration (and checks an account date of birth if present). That is not proof of age; bind a stronger verifier if required.
- **Not yet built:** payment initialisation/verification/webhooks, late payment after a reservation expired (must re-reserve or refund), customer cancellation, order emails. Row locks (`lockForUpdate`) are no-ops on SQLite, so true concurrent checkout has only been exercised sequentially in tests; run a concurrency test against MySQL 8 before launch.

## Payments
Implemented against the gateways' official docs (Paystack `paystack.com/docs/api`, Flutterwave v3 `developer.flutterwave.com`). No SDKs; Laravel's HTTP client only.

**Design.** `App\Contracts\PaymentGateway` is implemented by `PaystackGateway`, `FlutterwaveGateway` (and a development-only `FakeGateway`). Everything gateway-specific (URLs, field names, units, signature scheme) stays in the adapters: Paystack amounts are **kobo**, Flutterwave amounts are **naira** (`Money::toNairaString` / `fromNaira`, integer math only).

**Flow.** `POST /orders/{ref}/payments {gateway}` (owner only) creates a `payments` row from the order's *stored* total and returns the gateway page. The browser returns to `/payment/return/{our reference}`, which calls `POST /payments/{ref}/verify`. Confirmation can also arrive by webhook or by the `payments:reconcile` job (every 5 minutes). All three go through one place, `PaymentVerifier`, which asks the gateway server-to-server and settles under a row lock, so it is safe to call repeatedly and concurrently.

**What is checked before an order is marked paid.** Gateway status is success; the gateway's reference equals ours; currency equals the order's; amount equals the stored amount exactly (Paystack: `requested_amount`, because `amount` can include customer-borne fees); the stored amount equals the order total. Any difference parks the payment in `review` (money received, order **not** fulfilled) and writes an audit entry. Nothing in a redirect URL or webhook body is trusted.

**Edge cases.** Duplicate payment for an already-paid order -> `review: duplicate_payment`. Payment arriving after the reservation expired -> order is fulfilled if every item is still in stock (`OrderLifecycle::markPaidLate`), else `review: late_payment_unfulfillable`. Failed/abandoned payments can still be upgraded if the gateway later reports success.

**Webhooks** (`POST /webhooks/paystack|flutterwave`): authenticated before anything else; fail closed when the secret is unset; deduplicated by `webhook_events.dedupe_key`; return 401 (bad signature), 200 (done / ignorable) or 503 (could not verify now, please retry). Paystack: `x-paystack-signature` = HMAC-SHA512 of the raw body. Flutterwave: `verif-hash` header equals the secret hash you set in the dashboard.

**Refunds.** `POST /admin/payments/{ref}/refunds` (`orders.refund`, `Idempotency-Key` required). Pending and processed refunds both count against the payment, so over-refunding is impossible; a gateway rejection frees the amount again. Gateway confirmations arrive by refund webhook. Stock is **not** restocked automatically.

**Staff:** `GET /admin/payments?state=review` is the queue of money needing a human decision.

**Configuration (env):** `PAYSTACK_SECRET_KEY`, `FLUTTERWAVE_SECRET_KEY`, `FLUTTERWAVE_SECRET_HASH`, `FRONTEND_URL`. Gateways without credentials are never offered; staff can switch gateways off in settings (`payments.enabled_gateways`). `PAYMENTS_FAKE_GATEWAY=true` enables a local simulator (ignored in production).

**Queue worker.** The order-confirmation email is queued: run `php artisan queue:work` (and the scheduler: `php artisan schedule:work` locally, a cron in production) or confirmations, order expiry and payment reconciliation will not run.

### Go-live checklist (not yet done: no real gateway keys were available)
1. Put the **test** keys in the environment; place a test order for each gateway and pay with the gateway's test card. Confirm the order becomes paid via both the return page and, separately, the webhook.
2. Register the webhook URLs over HTTPS: `{API}/api/v1/webhooks/paystack` and `{API}/api/v1/webhooks/flutterwave`; set the Flutterwave secret hash to match `FLUTTERWAVE_SECRET_HASH`.
3. Confirm in test mode that Paystack `requested_amount` equals the amount initialised (the amount check depends on it) and that a customer-borne fee does not break verification.
4. Test a refund on each gateway; confirm the refund webhook updates the refund.
5. Run the scheduler and a queue worker; confirm `payments:reconcile` settles a payment whose webhook you deliberately blocked.
6. Switch to live keys only after the above, and never log or commit keys.

## Discounts (coupon codes)
Customers type a code in the cart; staff manage codes at `/api/v1/admin/discounts` (`promotions.manage`: marketing-manager, store-manager, super-admin).

**Types.** `percentage` (1-100, optional `max_discount_kobo` cap), `fixed` (kobo off) and `free_delivery`. **Scope:** whole order, chosen products, or chosen categories (a category also covers its sub-categories). **Limits:** minimum order value, start/end dates, total uses, uses per customer, on/off switch.

**Rules, in the order they are checked** (`App\Services\Promotions\DiscountEngine`): the code exists and is switched on (unknown and switched-off read identically, so codes cannot be probed) -> it has started and not ended -> total limit -> per-customer limit -> at least one cart item matches -> the *eligible items'* subtotal meets the minimum -> it actually changes the price (a free-delivery code on an already-free delivery, or a code that works out to zero, is refused with a plain message rather than "applied").

**Stacking: one code per order. Codes never stack with each other.** A discount can never exceed the eligible items' total, so an order total cannot go below the delivery fee. Percentages round **down** to whole kobo. Discounts apply to the *current selling price* (a product's sale price is already in it; there is no "exclude sale items" switch yet). The free-delivery threshold on a delivery zone is judged on the subtotal **before** discounts. A free-delivery code zeroes the delivery fee (the waived amount is kept on the redemption).

**Invalid combinations are refused when staff save** (all on the backend, `DiscountRequest`): percent only for percentage codes; amount only for fixed; a cap only for percentage; free delivery only order-wide; products/categories scope needs a non-empty list and an order-wide code must not have one; end after start; per-customer limit not above the total; unique code (stored upper-case, matched case-insensitively). On update, anything not sent keeps its value and the rules run on the combined result, so a change cannot leave a contradictory discount.

**Where the code lives.** `PUT /cart/discount {code}` checks the code against the live cart and only then stores it on the cart; `DELETE /cart/discount` removes it. Every cart read re-checks it: a code that has stopped working is reported in `data.discount.problem` and does **not** reduce the price. Checkout takes the code from the *cart* (never from the request body), re-checks it under a row lock on the discount, and refuses the order (`discount_expired`, `discount_exhausted`, ...) rather than quietly charging a different total. The code is part of the idempotency fingerprint. The order keeps `discount_code`, `discount_kobo` and the (possibly zero) `delivery_fee_kobo`; the gateway is asked for the discounted `total_kobo`.

**Usage ledger** (`discount_redemptions`, one row per order): `reserved` when the order is placed (so parallel checkouts cannot both take the last use), `redeemed` when payment is verified, `released` when the order is cancelled or expires (including a paid order cancelled before dispatch). Only reserved and redeemed rows count against limits. A superseding checkout cancels the earlier unpaid order first, so a customer's own abandoned attempt never blocks them. A discount that has ever been used cannot be deleted (409); switch it off instead.

**Per-customer limit: what it can and cannot do.** It counts by signed-in account *and* by checkout email (lower-cased). A guest can still use a different email address, so treat it as a courtesy limit, not fraud prevention; use total limits and short windows for generous offers.

**Abuse limits.** `PUT /cart/discount` allows 10 tries a minute per shopper (cart token or user) plus 300 a minute across everyone (the storefront server shares one IP).

**Not built:** automatic discounts (no code needed), buy-X-get-Y, excluding sale items, per-line discount allocation on receipts (the receipt shows one discount line with the code), partial refunds that pro-rate a discount, and the SQLite test database cannot prove `lockForUpdate` on the usage count; run a parallel-checkout test against MySQL 8 before launch.

## Admin customers
`GET /admin/customers` (search `q` by name, email or normalised phone), `GET /admin/customers/{id}` (profile, addresses, stats, last 20 orders, notes), `POST /admin/customers/{id}/notes`, `DELETE /admin/customers/{id}/notes/{note}`; permission `customers.manage`. A customer is a user with no staff role: staff are never listed or opened here, and passwords and tokens are never returned. Spend counts paid orders and does not subtract refunds. Support notes (`customer_notes`) are internal, cleaned of markup *before* validation (a markup-only note is rejected, not saved empty), audited on add and delete, and looked up through their customer. Order notes and cancellation reasons are cleaned the same way.

## Admin settings
`GET|PUT /admin/settings` (`settings.manage`: store manager, super admin) holds the business details shown on receipts (`business.*`: name, address, phone, email, registration number, receipt footer) and the ordering limits (`commerce.*`: minimum age, reservation minutes, max per item, max per order). They are saved together and validated together. A blank business name falls back to the app name on receipts; the registration number is free text and must only be what the business can show. **The minimum age can be raised but never set below 18**; the age check is a self-declaration, and anything stricter is for qualified local counsel. The per-order limit cannot be lower than the per-item limit. Text is cleaned of markup, and each save writes one audit entry listing what changed (from and to). Delivery zones stay on `/admin/delivery-zones` (`shipping.manage`: order manager too).

## Admin staff and roles
`GET /admin/staff`, `GET /admin/roles`, `POST /admin/staff` (invite), `PATCH /admin/staff/{id}` (roles, name), `POST /admin/staff/{id}/deactivate|reactivate|resend-invite`. Permission `staff.manage` is held by **no seeded role except super-admin (via "*")**: whoever can assign roles can give themselves everything, so this is deliberately not delegated by default. Staff are users with at least one role (a user with none is just a customer, so removing every role is refused); customers are never opened here, and an email that already has any account cannot be invited.

**Invitations** create the account with a random password nobody knows and email a single-use password-reset link (`StaffInvitationNotification`, queued: run a queue worker). The link expires with the normal reset window (`auth.passwords.users.expire`, 60 minutes); use "resend invitation" for a fresh one. Setting a password signs out any other session.

**Guard rails:** you cannot change your own roles or deactivate yourself; the last *active* super administrator cannot be demoted or deactivated (checked even when the actor is a delegated staff manager). Deactivating sets `is_active = false`, deletes every token at once, and admin routes also reject inactive users, so a token that somehow survives still fails. Role changes apply on the next request. Every action is audited (`staff.invited`, `staff.roles_changed` with from and to, `staff.deactivated`, `staff.reactivated`, `staff.invite_resent`).

**Not built:** custom roles and per-permission editing, two-factor authentication for staff, a sign-in history, and forcing a password change.

## Product photos
`POST /admin/products/{product}/images` takes a multipart `file` (or, still supported, an https `url`), `alt` optionally; `PUT /admin/images/{image}` edits the description; `POST /admin/images/{image}/primary` makes it the main image and renumbers the rest; `DELETE /admin/images/{image}` removes it. Needs `catalog.manage`; each action is audited.

**What is accepted:** JPEG, PNG or WebP, 400 to 4500 pixels on each side, 5 MB or less, ten images per product. SVG and GIF are refused (SVG can carry script). Both limits are checked in the browser for a quick message and again by the API.

**Every upload is re-encoded, never stored as sent** (`App\Services\Catalog\ProductImageStore`): decoded with GD, rotated per the phone's EXIF orientation, scaled so the longest side is at most 1600 px (never enlarged), and saved as WebP (quality 85, transparency kept). That strips metadata such as GPS positions and discards anything hidden in the file. The 4500 px ceiling exists because GD decodes the full bitmap in memory (about 80 MB at 20 megapixels); very large photos (some 48 MP phone modes) must be exported smaller first.

**Where files live:** `config('filesystems.product_images_disk')` (env `PRODUCT_IMAGES_DISK`, `cloudinary` in `.env.example`; `public` also works locally after `php artisan storage:link`). The database stores only the path; the public address is worked out when read, so moving to another domain or a CDN needs no data change. `APP_URL` must be the API's public address. Files are deleted with their image, with the product, and are copied (not shared) when a product is duplicated.

**Not built:** several sizes per photo (Next's image optimiser resizes on the fly), cropping, drag-to-reorder (use "make main image"), alt-text enforcement, and a media library shared across products. Cloudinary (`App\Services\Catalog\CloudinaryAdapter`, no SDK; needs `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`) is tested against mocked HTTP only, not a live account.

## Reports
`GET /admin/reports/sales|products|discounts|stock` (permission `reports.view`: store manager, marketing manager, super admin). Read-only. Query: `from`, `to` (YYYY-MM-DD, both days included), `group` (day, week, month), and for products `sort` (revenue, units) and `limit` (max 50). Default range is the last 30 days; at most 366 days.

**Definitions (so the numbers can be trusted and argued with):**
- **Everything counts PAID orders, on the day they were paid, in Nigerian time (Africa/Lagos).** An order paid at 23:30 UTC belongs to the next Lagos day. Ranges include the whole of both end dates.
- A paid order that was later **cancelled** has status cancelled and is left out entirely (with its refund). **Refunds** shown are processed refunds against orders that still count (partial refunds); pending and failed refunds are not subtracted.
- **Sales** = what customers paid (items - discount + delivery). **Average order** = sales / orders in whole kobo, rounded down. Weeks start on Monday. Quiet days, weeks and months are included as zero rows.
- **Top products** are grouped by SKU using the names recorded at the time of sale; revenue is the items' own price *before* any discount code or delivery fee.
- **Discount usage** counts only redeemed uses on paid orders in range; the order totals shown are what those customers paid.
- **Low stock** is about *now*, not the date range: active items of active products whose available stock (on hand minus held for unpaid orders) is at or below the item's alert level.

**Scale:** sales are totalled in PHP over at most a year of paid orders (so the same code runs on SQLite and MySQL). That is fine for thousands of orders a month; if volume grows large, move the bucketing into SQL or a summary table. Not built: profit (cost prices are not used), per-category and per-customer reports, scheduled email reports.

## Reviews, wishlist, best sellers and newsletter
**Reviews** (`reviews` table). `GET /products/{slug}/reviews` is public and returns approved reviews, a summary (count, average, 1–5 distribution) and a shortened author name ("Ada O."). `GET /me/products/{slug}/review` says whether the signed-in customer can review; `POST /products/{slug}/reviews` creates a **pending** review. Rules: the customer must have a *paid* order containing the product (403 `review_requires_purchase`), one review per customer per product (409 `already_reviewed`), rating 1–5, body 10–2000 characters, 5 submissions a minute. Nothing is public until staff approve it: `GET /admin/reviews?status=`, `POST /admin/reviews/{id}/approve|reject`, `DELETE`, all `catalog.manage` and audited. **Photos, replies, taking one down:** a review may carry up to 3 photos (`photos[]`, multipart; JPEG/PNG/WebP, 5 MB, 200–4500 px), re-encoded to WebP like product photos and stored on the same disk. Staff can post one public reply per review (`PUT|DELETE /admin/reviews/{id}/reply`), and customers can delete their own review (`DELETE /me/products/{slug}/review`); photo files are removed when a review or its product is deleted. Product list/detail responses carry `rating: {average, count} | null` from approved reviews only.

**Wishlist** (`wishlist_items`). Signed-in only: `GET /me/wishlist` (products), `GET /me/wishlist/slugs`, `PUT|DELETE /me/wishlist/{slug}` (both idempotent). Unpublished products can't be saved.

**Best sellers.** `GET /products/best-sellers?limit=` ranks products by units in *paid, not cancelled* orders from the last 90 days. Empty until something has sold, and the storefront hides the row then.

**Newsletter** (`newsletter_subscribers`), double opt-in. `POST /newsletter` (email + `consent: true`) stores the consent wording and time and emails a confirmation link; it answers identically for known addresses and sends at most one email an hour per address. `POST /newsletter/confirm` and `/unsubscribe` take the emailed token. `GET /admin/newsletter` (`promotions.manage`) lists confirmed subscribers with totals. **Campaigns** (`newsletter_campaigns`, `promotions.manage`): `GET|POST /admin/newsletter/campaigns`, `PUT|DELETE .../{id}` (drafts only), `POST .../{id}/test` (emails only the signed-in staff member) and `POST .../{id}/send`. Sending needs `expected_recipients` equal to the current subscriber count (409 `recipients_changed` otherwise), is claimed atomically so a double-click cannot mail anyone twice (409 `campaign_sent`), goes only to confirmed, still-subscribed people, and puts a personal unsubscribe link and a `List-Unsubscribe` header on every email. Emails are queued, so a queue worker and real mail settings are required; there is no per-recipient delivery log or open/click tracking. **Not built:** a data-deletion request flow, and any legal review of the consent wording.

## What each staff role can open
| Role | Admin areas |
| --- | --- |
| Super Administrator | Unlimited (`*`). The only role that can manage staff |
| Store Manager | Owns the store: everything except Staff |
| Product Manager | Products, stock alerts, Reviews |
| Content Editor | Pages |
| Order Manager | Orders, Delivery zones |
| Customer Support | Orders (view), Customers, Reviews |
| Marketing Manager | Reports, Discounts, Newsletter and Campaigns, Pages, Reviews |

Review moderation has its own permission, `reviews.moderate` (store manager, product manager, customer support, marketing manager). Roles are defined in `RolesAndPermissionsSeeder::ROLES`; after changing it run `php artisan db:seed --class=RolesAndPermissionsSeeder` to update an existing database.

Every role lands on a dashboard with the numbers it can act on (orders for order roles; sales, stock, reviews waiting, customers, subscribers or page counts for the others). `tests/Feature/StaffDashboardAccessTest.php` calls each area as each role and fails if a role can open something outside its row, or if a seeded role is missing from the table.

**Pages (CMS).** `content.manage` (content editor, marketing manager, store manager, super admin) writes information pages: `GET|POST /admin/pages`, `GET|PUT|DELETE /admin/pages/{id}`, audited. Content is plain text (markup is stripped); a line starting `## ` is a heading. Only *published* pages are public: `GET /pages` (footer links) and `GET /pages/{slug}`, shown on the shop at `/info/{slug}`. **Not built:** rich text, images inside pages, banners or the home-page layout, page version history.

**Local demo staff.** `php artisan db:seed --class=DemoRoleUsersSeeder` (also part of `db:seed` outside production) creates `{role-slug}@example.test` for each role with a fresh random password, written to `storage/app/private/demo-staff-credentials.txt` (git-ignored). Set `DEMO_STAFF_PASSWORD` to use one shared password instead. It refuses to run in production.


## Staff sign-in is separate from customer sign-in
`POST /auth/staff/login` signs in only accounts that hold a role; a customer, a stranger and a wrong password all get the identical "These credentials do not match our records." answer, so it cannot reveal who works here. `POST /auth/login` (customers) now refuses staff accounts with "Staff accounts sign in at the staff portal, not here." (shown only after a correct password, so it does not reveal staff by email alone). Staff tokens last 8 hours. Both endpoints share the `auth` rate limit. A person who both works here and shops needs two accounts (two emails).

On the storefront the portal is `/staff/login`. It sets its own `lq_staff_session` cookie (httpOnly), which only the admin area reads; the customer cookie gives no admin access and staff sign-in does not sign anyone in to the shop. Invited staff still set their password through the reset link, then sign in at `/staff/login`.

## Identifiers are UUIDs
Every primary and foreign key in the application is a UUID (version 7, time-ordered, generated by Eloquent's `HasUuids` on every model, so newer rows sort after older ones). That includes the Sanctum token's own id, so a bearer token reads `{uuid}|{secret}` (`App\Models\PersonalAccessToken`). Route constraints are `whereUuid`, request rules are `uuid`, and the API returns ids as strings. A numeric or malformed id in a path is a 404, and in a body a validation error. **Exceptions:** Laravel's own plumbing keeps its internal keys (`jobs`, `job_batches`, `failed_jobs`, `cache`, `sessions`, `migrations`, `password_reset_tokens`). Customer-facing references (order `reference`, product `slug`) are unchanged.

The migrations were rewritten in place (the app had no live data), so an existing database must be rebuilt: `php artisan migrate:fresh --seed`. `tests/Feature/UuidIdentifiersTest.php` fails if any application table gets an integer key. Uploaded-file paths already contain the owning record's id, so photos uploaded before the change are orphaned by a rebuild.

## Business logo
`POST /admin/settings/logo` (multipart `file`) and `DELETE /admin/settings/logo`, permission `settings.manage` (store manager, super admin), audited (`settings.logo_updated`, `settings.logo_removed`). PNG, JPEG or WebP, 2 MB or less, 100 to 4000 px on each side; SVG and PDF are refused (SVG can carry script). It is re-encoded to WebP (transparency kept, longest side 800 px) on the product-photo disk (`PRODUCT_IMAGES_DISK`, Cloudinary by default), stored as `business.logo_path`, and replacing or removing it deletes the old file. The address is returned as `logo_url` in `GET /settings/public` (`business.name` is `null` until staff set a name, so the shop keeps its own default), in the admin settings, and in the receipt's `business` block. Saving the text settings never touches the logo. **Not built:** a separate favicon or app icon (the installed-app icons stay as built), a dark-mode variant, and cropping.

## Deleting is soft
Nothing a person deletes is removed from the database. These records carry a `deleted_at` column (Eloquent `SoftDeletes`) and simply disappear from every normal query: products, variants, product photos, brands, categories, delivery zones, discounts, pages, newsletter campaigns, reviews (and their photos), customer notes, saved addresses and wishlist entries. `tests/Feature/SoftDeleteTest.php` checks each delete endpoint, and fails if any model loses the trait or any application code calls `forceDelete`.

- **Cascades:** deleting a product also soft-deletes its variants, photos, reviews and wishlist entries; restoring it brings back what went with it (children deleted separately more than a minute earlier stay deleted). Deleting a review takes its photos along.
- **Files stay:** uploaded photos are no longer removed from storage when their record is deleted, so a restore shows them again. (Replacing the business logo still removes the old logo file: that is an overwrite, not a deletion.)
- **Names stay taken:** a deleted record keeps its slug, SKU, discount code, delivery state/city and a customer's one-review-per-product slot. New slugs get a numeric suffix; an explicit duplicate SKU, code or zone is refused with a message that says it may belong to a deleted record. A customer who deleted a review and writes again reuses that row with a clean slate; saving a product to the wishlist again restores the entry.
- **Restoring:** there is no admin screen yet. `php artisan trash:list [type]` shows deleted records and `php artisan trash:restore {type} {id}` brings one back (types: product, variant, image, brand, category, zone, discount, page, campaign, review, review-image, note, address, wishlist).
- **Deliberately not soft:** login tokens (a logout or password reset must really revoke them), cart lines and abandoned guest carts (throwaway shopping state; a deleted variant drops out of carts), and the role, permission and discount-target links edited through staff and discount forms (they are settings, recorded in the audit log). Customers, staff, orders, payments and refunds are never deleted at all.

## Suspending customers
`POST /admin/customers/{id}/suspend` (optional `reason`, up to 300 characters, markup stripped) and `POST /admin/customers/{id}/reactivate`, permission `customers.manage` (customer support, store manager, super admin), customers only (staff are 404 here). Suspending sets `is_active = false`, records `suspended_at` and `suspension_reason`, deletes every login token at once, adds a support note ("Account suspended: reason") and audits `customer.suspended`; reactivating clears all that and adds a note and `customer.reactivated`. Both are idempotent. A suspended customer who gives the right password at sign-in is told "This account has been suspended. Please contact us for help."; a wrong password still gets the generic message, so it can't reveal who has an account. Every signed-in route also rejects an inactive account (`EnsureAccountActive`, 401 `account_inactive`) in case a token survives. `GET /admin/customers` shows `is_active` and `suspended_at` and filters with `?status=active|suspended`. Orders, addresses and history are untouched, and a suspended person can still check out as a guest with an email address (suspension is account-level). Deactivated staff keep the generic answer at the staff portal.

## Suspended customers and guest checkout
Checkout refuses an email that belongs to a suspended customer (inactive, no roles, compared case-insensitively) with 403 `account_suspended`. This runs after the idempotent-replay lookup, so a retry of an order already placed still returns that order.

## Product search
`GET /products?q=` splits the text into up to 6 words. Every word must match at least one of: name, short description, description, tasting notes, country of origin, brand name, category name or parent category name. Without an explicit `sort`, results rank name matches first, then brand/category matches, then the rest.

## Newsletter delivery log
Each send writes one `newsletter_deliveries` row per subscriber (`queued`, `sent`, `failed`; unique per campaign and subscriber). The `NotificationSent` listener marks rows sent; the notification's `failed()` hook stores a truncated error with email addresses redacted. `GET /admin/newsletter/campaigns/{id}/deliveries?status=` lists them, `POST .../retry-failed` (5 per minute) re-queues only the failures.

## Trash screen
`GET /admin/trash?type=` lists soft-deleted records the caller may manage; `POST /admin/trash/{type}/{id}/restore` restores one (404 if the role cannot manage that type, 409 `parent_deleted` if its parent is still deleted) and audits `trash.restored`. Each type is gated by its own permission (catalogue, content, promotions, shipping, reviews, customers). Addresses, wishlist items and review photos are not listed there; use `trash:restore`.

## Category images, brand logos and page pictures
`POST/DELETE /admin/categories/{id}/image` and `/admin/brands/{id}/logo` (catalog.manage) store a re-encoded WebP through the same disk as product images (Cloudinary in production). An uploaded file wins over the older URL fields. `POST /admin/pages/images` (content.manage, 30 per minute) stores a picture for use in a page. Page bodies are a small Markdown subset rendered safely by the storefront (no raw HTML). The shop's favicon and app icons are built from the business logo via a same-origin `/brand-icon/{size}` route.

## Home page content
`GET/PUT /admin/homepage` (content.manage) edits the announcement strip, hero text and buttons, hero picture (`POST/DELETE /admin/homepage/hero-image`) and which sections show (best sellers, new arrivals, on sale, how it works, promises). Values are public settings returned by `/settings/public` under `homepage`. Links must be a site path or an https URL; text has markup stripped.

## Customer data deletion
Customers ask from their account (`POST /me/deletion-request`, password required, idempotent; `DELETE` withdraws). Staff with `customers.manage` see `GET /admin/deletion-requests` and either complete or reject (note required). Completing is refused with 409 `open_orders` while the customer has an order that is unpaid or paid but not delivered. Erasure revokes tokens, deletes carts, scrubs and soft-deletes addresses, notes, reviews (and review photo files) and wishlist, removes the newsletter subscription, and anonymises the user row (name "Deleted customer", email `deleted-{id}@deleted.invalid`, no phone or birth date, random password, inactive, `anonymized_at` set). Orders and payments are kept untouched for tax and accounting. The customer is emailed at their original address. **The retention policy and the consent wording need legal review before launch.**

## Reports at scale
The sales report streams paid orders with `lazy(1000)` and folds them into running totals and per-period buckets, so memory stays flat as order counts grow.
