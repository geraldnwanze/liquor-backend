<?php

use App\Enums\OrderStatus;
use App\Enums\RedemptionState;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DeliveryZone;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderLifecycle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    configureGateways();
    Http::preventStrayRequests();
    ikejaZone();
});

/** A guest cart holding `quantity` of one ₦10,000 item. @return array{0: string, 1: Product} */
function tenThousandCart(int $quantity = 2, array $product = []): array
{
    $variant = sellableVariant(['price_kobo' => 1_000_000, 'on_hand' => 50], $product);

    return [addToCart($variant, $quantity), $variant->product];
}

function applyCode(string $token, string $code, string $query = ''): TestResponse
{
    return guest($token)->putJson('/api/v1/cart/discount'.$query, ['code' => $code]);
}

const LAGOS = '?state=Lagos&city=Ikeja';

/** A real pending order (made through checkout), for tests that need an order row to hang a redemption on. @param array<string, mixed> $attributes */
function someOrder(array $attributes = []): Order
{
    [$order] = payableOrder();
    if ($attributes) {
        $order->forceFill($attributes)->save();
    }

    return $order;
}

describe('applying a code to the cart', function () {
    it('takes a percentage off in whole kobo, rounding down', function () {
        Discount::factory()->percentage(10)->create(['code' => 'TEN']);
        [$token] = tenThousandCart(2); // ₦20,000

        applyCode($token, 'TEN')->assertOk()
            ->assertJsonPath('data.discount.code', 'TEN')->assertJsonPath('data.discount.applied', true)
            ->assertJsonPath('data.discount.amount.kobo', 200_000)->assertJsonPath('data.subtotal.kobo', 2_000_000);

        $odd = sellableVariant(['price_kobo' => 335, 'on_hand' => 5]);
        $oddToken = addToCart($odd, 1);
        applyCode($oddToken, 'TEN')->assertOk()->assertJsonPath('data.discount.amount.kobo', 33); // 33.5 -> 33
    });

    it('caps a percentage at the maximum, and a fixed amount at what the items cost', function () {
        Discount::factory()->percentage(50, 300_000)->create(['code' => 'HALFCAP']);
        Discount::factory()->fixed(9_000_000)->create(['code' => 'BIGFIXED']);
        [$token] = tenThousandCart(2);

        applyCode($token, 'HALFCAP')->assertOk()->assertJsonPath('data.discount.amount.kobo', 300_000);
        applyCode($token, 'BIGFIXED')->assertOk()->assertJsonPath('data.discount.amount.kobo', 2_000_000); // never below zero
    });

    it('ignores case and surrounding spaces in the code', function () {
        Discount::factory()->fixed(100_000)->create(['code' => 'WELCOME']);
        [$token] = tenThousandCart();

        applyCode($token, '  welcome ')->assertOk()->assertJsonPath('data.discount.code', 'WELCOME');
    });

    it('treats unknown and switched-off codes identically and stores neither', function () {
        Discount::factory()->inactive()->create(['code' => 'OFF']);
        [$token] = tenThousandCart();

        $unknown = applyCode($token, 'NOSUCH')->assertUnprocessable()->assertJsonPath('code', 'discount_invalid');
        $off = applyCode($token, 'OFF')->assertUnprocessable()->assertJsonPath('code', 'discount_invalid');
        expect($off->json('message'))->toBe($unknown->json('message'));
        guest($token)->getJson('/api/v1/cart')->assertJsonPath('data.discount', null);
    });

    it('refuses codes that have not started, have ended, are used up, or were used by this customer', function () {
        Discount::factory()->between(now()->addDay(), null)->create(['code' => 'SOON']);
        Discount::factory()->between(null, now()->subMinute())->create(['code' => 'GONE']);
        $full = Discount::factory()->create(['code' => 'FULL', 'usage_limit' => 1]);
        $user = User::factory()->create();
        $mine = Discount::factory()->create(['code' => 'ONCE', 'per_customer_limit' => 1]);
        $order = someOrder(['user_id' => $user->id]);
        DiscountRedemption::factory()->create(['discount_id' => $full->id, 'order_id' => someOrder()->id]);
        DiscountRedemption::factory()->create(['discount_id' => $mine->id, 'order_id' => $order->id, 'user_id' => $user->id, 'email' => $user->email]);
        [$token] = tenThousandCart();

        applyCode($token, 'SOON')->assertUnprocessable()->assertJsonPath('code', 'discount_not_started');
        applyCode($token, 'GONE')->assertUnprocessable()->assertJsonPath('code', 'discount_expired');
        applyCode($token, 'FULL')->assertUnprocessable()->assertJsonPath('code', 'discount_exhausted');

        asStaff($user)->postJson('/api/v1/cart/items', ['variant_id' => sellableVariant(['on_hand' => 5])->id, 'quantity' => 1])->assertCreated();
        asStaff($user)->putJson('/api/v1/cart/discount', ['code' => 'ONCE'])->assertUnprocessable()->assertJsonPath('code', 'discount_customer_limit');
    });

    it('does not count released uses against a limit', function () {
        $discount = Discount::factory()->create(['code' => 'LIMITED', 'usage_limit' => 1]);
        DiscountRedemption::factory()->create(['discount_id' => $discount->id, 'order_id' => someOrder()->id, 'state' => RedemptionState::Released]);
        [$token] = tenThousandCart();

        applyCode($token, 'LIMITED')->assertOk();
    });

    it('enforces the minimum on the items it covers', function () {
        Discount::factory()->fixed(100_000)->create(['code' => 'MIN', 'min_order_kobo' => 3_000_000]);
        [$token] = tenThousandCart(2); // ₦20,000 < ₦30,000

        applyCode($token, 'MIN')->assertUnprocessable()->assertJsonPath('code', 'discount_min_order')->assertJsonPath('details.min_order_kobo', 3_000_000);
        addToCart(anotherTenThousandItem(), 1, $token);
        applyCode($token, 'MIN')->assertOk();
    });

    it('limits a product code to those products', function () {
        $cart = sellableVariant(['price_kobo' => 1_000_000, 'on_hand' => 50]);
        $other = sellableVariant(['price_kobo' => 500_000, 'on_hand' => 50]);
        Discount::factory()->percentage(20)->forProducts([$cart->product])->create(['code' => 'ONLYONE']);
        $token = addToCart($cart, 1);
        addToCart($other, 2, $token); // ₦10,000 target + ₦10,000 other

        applyCode($token, 'ONLYONE')->assertOk()->assertJsonPath('data.discount.amount.kobo', 200_000); // 20% of ₦10,000 only

        $elsewhere = addToCart(sellableVariant(['price_kobo' => 500_000, 'on_hand' => 5]), 1);
        applyCode($elsewhere, 'ONLYONE')->assertUnprocessable()->assertJsonPath('code', 'discount_not_applicable');
    });

    it('applies a category code to products in its sub-categories too', function () {
        $spirits = Category::factory()->create();
        $rum = Category::factory()->create(['parent_id' => $spirits->id]);
        $wine = Category::factory()->create();
        Discount::factory()->percentage(10)->forCategories([$spirits])->create(['code' => 'SPIRITS']);
        $token = addToCart(sellableVariant(['price_kobo' => 1_000_000, 'on_hand' => 9], ['category_id' => $rum->id]), 1);
        applyCode($token, 'SPIRITS')->assertOk()->assertJsonPath('data.discount.amount.kobo', 100_000);

        $wineToken = addToCart(sellableVariant(['price_kobo' => 1_000_000, 'on_hand' => 9], ['category_id' => $wine->id]), 1);
        applyCode($wineToken, 'SPIRITS')->assertUnprocessable()->assertJsonPath('code', 'discount_not_applicable');
    });

    it('waives the delivery fee for a free delivery code once the area is known', function () {
        Discount::factory()->freeDelivery()->create(['code' => 'FREESHIP']);
        [$token] = tenThousandCart();

        applyCode($token, 'FREESHIP')->assertOk()->assertJsonPath('data.discount.free_delivery', true)->assertJsonPath('data.discount.amount.kobo', 0);
        guest($token)->getJson('/api/v1/cart'.LAGOS)->assertOk()
            ->assertJsonPath('data.delivery.fee.kobo', 0)->assertJsonPath('data.delivery.fee_waived.kobo', 150_000);
    });

    it('says so when delivery is already free instead of pretending the code helped', function () {
        Discount::factory()->freeDelivery()->create(['code' => 'FREESHIP']);
        [$token] = tenThousandCart(); // ₦20,000
        DeliveryZone::query()->update(['free_threshold_kobo' => 1_000_000]);

        applyCode($token, 'FREESHIP', LAGOS)->assertUnprocessable()->assertJsonPath('code', 'discount_delivery_already_free');
    });

    it('reports a stored code that stopped working, and does not reduce the price', function () {
        $discount = Discount::factory()->fixed(100_000)->create(['code' => 'FADING']);
        [$token] = tenThousandCart();
        applyCode($token, 'FADING')->assertOk();
        $discount->update(['ends_at' => now()->subMinute()]);

        guest($token)->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.discount.applied', false)->assertJsonPath('data.discount.problem.code', 'discount_expired')
            ->assertJsonPath('data.discount.amount.kobo', 0);
    });

    it('can be removed, and needs a cart with something in it', function () {
        Discount::factory()->fixed(100_000)->create(['code' => 'GONEAGAIN']);
        [$token] = tenThousandCart();
        applyCode($token, 'GONEAGAIN')->assertOk();

        guest($token)->deleteJson('/api/v1/cart/discount')->assertOk()->assertJsonPath('data.discount', null);
        applyCode((string) Str::uuid(), 'GONEAGAIN')->assertUnprocessable()->assertJsonPath('code', 'cart_empty');
    });

    it('rejects malformed codes', function () {
        [$token] = tenThousandCart();

        applyCode($token, 'has spaces!')->assertUnprocessable()->assertJsonValidationErrors('code');
        applyCode($token, str_repeat('A', 40))->assertUnprocessable();
    });

    it('rate-limits code guessing per shopper', function () {
        [$token] = tenThousandCart();
        [$someoneElse] = tenThousandCart();

        foreach (range(1, 10) as $i) {
            applyCode($token, 'GUESS'.$i)->assertUnprocessable();
        }
        applyCode($token, 'GUESS11')->assertStatus(429);
        applyCode($someoneElse, 'GUESS1')->assertUnprocessable(); // another shopper is not locked out
    });
});

describe('placing an order with a code', function () {
    it('charges the discounted total, keeps the code on the order and reserves a use', function () {
        $discount = Discount::factory()->percentage(10)->create(['code' => 'TEN']);
        [$token] = tenThousandCart(2); // ₦20,000 + ₦1,500 delivery
        applyCode($token, 'TEN')->assertOk();

        $res = checkout($token)->assertCreated()
            ->assertJsonPath('data.discount.kobo', 200_000)->assertJsonPath('data.discount_code', 'TEN')->assertJsonPath('data.total.kobo', 1_950_000);

        $order = Order::firstWhere('reference', $res->json('data.reference'));
        expect($order->subtotal_kobo)->toBe(2_000_000)->and($order->total_kobo)->toBe(2_000_000 - 200_000 + 150_000);
        $this->assertDatabaseHas('discount_redemptions', ['discount_id' => $discount->id, 'order_id' => $order->id, 'state' => 'reserved', 'amount_kobo' => 200_000]);
    });

    it('waives delivery for a free delivery code and records what was waived', function () {
        $discount = Discount::factory()->freeDelivery()->create(['code' => 'FREESHIP']);
        [$token] = tenThousandCart();
        applyCode($token, 'FREESHIP')->assertOk();

        $res = checkout($token)->assertCreated()->assertJsonPath('data.delivery_fee.kobo', 0)->assertJsonPath('data.total.kobo', 2_000_000);

        $this->assertDatabaseHas('discount_redemptions', ['discount_id' => $discount->id, 'amount_kobo' => 0, 'delivery_waived_kobo' => 150_000]);
        expect($res->json('data.discount_code'))->toBe('FREESHIP');
    });

    it('sends the gateway the discounted amount, never the full price', function () {
        Discount::factory()->fixed(500_000)->create(['code' => 'FIVEK']);
        [$token] = tenThousandCart(2);
        applyCode($token, 'FIVEK')->assertOk();
        $order = Order::firstWhere('reference', checkout($token)->assertCreated()->json('data.reference'));

        $payment = initPayment($order, $token);

        expect($payment->amount_kobo)->toBe($order->total_kobo)->toBe(2_000_000 - 500_000 + 150_000);
    });

    it('replays an idempotent retry without a second use, but not with a different code', function () {
        $discount = Discount::factory()->percentage(10)->create(['code' => 'TEN']);
        Discount::factory()->percentage(20)->create(['code' => 'TWENTY']);
        [$token] = tenThousandCart();
        applyCode($token, 'TEN')->assertOk();

        checkout($token, key: 'same-key-0123456789')->assertCreated();
        checkout($token, key: 'same-key-0123456789')->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        expect(DiscountRedemption::where('discount_id', $discount->id)->count())->toBe(1);

        applyCode($token, 'TWENTY')->assertOk();
        checkout($token, key: 'same-key-0123456789')->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
    });

    it('refuses the order, and reserves no stock, when the code stopped working after it was applied', function () {
        $discount = Discount::factory()->fixed(100_000)->create(['code' => 'LASTCALL']);
        $variant = sellableVariant(['price_kobo' => 1_000_000, 'on_hand' => 5]);
        $token = addToCart($variant, 1);
        applyCode($token, 'LASTCALL')->assertOk();
        $discount->update(['ends_at' => now()->subMinute()]);

        checkout($token)->assertUnprocessable()->assertJsonPath('code', 'discount_expired');

        expect(Order::count())->toBe(0)->and($variant->refresh()->reserved)->toBe(0);
    });

    it('lets only as many orders through as the total limit allows, then frees the use if one is abandoned', function () {
        $discount = Discount::factory()->fixed(100_000)->create(['code' => 'ONLYONE', 'usage_limit' => 1]);
        [$first] = tenThousandCart();
        [$second] = tenThousandCart();
        applyCode($first, 'ONLYONE')->assertOk();
        applyCode($second, 'ONLYONE')->assertOk(); // nobody has placed an order yet

        $a = Order::firstWhere('reference', checkout($first, ['email' => 'a@example.com'])->assertCreated()->json('data.reference'));
        checkout($second, ['email' => 'b@example.com'])->assertUnprocessable()->assertJsonPath('code', 'discount_exhausted');

        app(OrderLifecycle::class)->expire($a);
        expect($discount->activeUseCount())->toBe(0);
        checkout($second, ['email' => 'b@example.com'])->assertCreated();
    });

    it('holds a customer to the per-customer limit by email, even as a guest with a fresh cart', function () {
        Discount::factory()->fixed(100_000)->create(['code' => 'ONCEEACH', 'per_customer_limit' => 1]);
        [$first] = tenThousandCart();
        [$second] = tenThousandCart();
        applyCode($first, 'ONCEEACH')->assertOk();
        applyCode($second, 'ONCEEACH')->assertOk();

        checkout($first, ['email' => 'Ada@Example.com'])->assertCreated();
        checkout($second, ['email' => 'ada@example.com'])->assertUnprocessable()->assertJsonPath('code', 'discount_customer_limit');
        checkout($second, ['email' => 'someone.else@example.com'])->assertCreated();
    });

    it('does not count a customer\'s own unpaid order again when they check out a second time', function () {
        Discount::factory()->fixed(100_000)->create(['code' => 'ONCEEACH', 'per_customer_limit' => 1]);
        [$token] = tenThousandCart();
        applyCode($token, 'ONCEEACH')->assertOk();

        checkout($token, key: 'first-key-0123456789')->assertCreated();
        checkout($token, key: 'second-key-012345678')->assertCreated(); // supersedes the first, which gives its use back

        expect(DiscountRedemption::where('state', RedemptionState::Reserved)->count())->toBe(1)
            ->and(DiscountRedemption::where('state', RedemptionState::Released)->count())->toBe(1);
    });

    it('marks the use redeemed on payment and gives it back when a paid order is cancelled', function () {
        Discount::factory()->fixed(100_000)->create(['code' => 'FIVEK']);
        [$token] = tenThousandCart();
        applyCode($token, 'FIVEK')->assertOk();
        $order = Order::firstWhere('reference', checkout($token)->assertCreated()->json('data.reference'));

        app(OrderLifecycle::class)->markPaid($order);
        expect($order->discountRedemption->refresh()->state)->toBe(RedemptionState::Redeemed);

        app(OrderLifecycle::class)->cancelPaid($order->refresh(), null, 'test');
        expect($order->discountRedemption->refresh()->state)->toBe(RedemptionState::Released)->and($order->refresh()->status)->toBe(OrderStatus::Cancelled);
    });

    it('shows the code and the discount on the receipt', function () {
        Discount::factory()->fixed(100_000)->create(['code' => 'FIVEK']);
        [$token] = tenThousandCart();
        applyCode($token, 'FIVEK')->assertOk();
        $order = Order::firstWhere('reference', checkout($token)->assertCreated()->json('data.reference'));
        app(OrderLifecycle::class)->markPaid($order);

        asStaff(orderStaff('order-manager'))->getJson("/api/v1/admin/orders/{$order->reference}/receipt")->assertOk()
            ->assertJsonPath('data.discount_code', 'FIVEK')->assertJsonPath('data.discount.kobo', 100_000);
    });
});

/** A fresh variant to top the cart up in the minimum-order test. */
function anotherTenThousandItem(): ProductVariant
{
    return sellableVariant(['price_kobo' => 1_000_000, 'on_hand' => 50]);
}

function discountPayload(array $over = []): array
{
    return array_merge([
        'code' => 'spring10', 'name' => 'Spring offer', 'type' => 'percentage', 'percent' => 10, 'scope' => 'order', 'is_active' => true,
    ], $over);
}

describe('managing discounts as staff', function () {
    it('keeps the discount admin to the people allowed to run promotions', function () {
        $discount = Discount::factory()->create();

        $this->getJson('/api/v1/admin/discounts')->assertUnauthorized();
        asStaff(User::factory()->create())->getJson('/api/v1/admin/discounts')->assertForbidden();
        asStaff(orderStaff('order-manager'))->getJson('/api/v1/admin/discounts')->assertForbidden();
        asStaff(orderStaff('product-manager'))->postJson('/api/v1/admin/discounts', discountPayload())->assertForbidden();
        asStaff(orderStaff('customer-support'))->deleteJson("/api/v1/admin/discounts/{$discount->id}")->assertForbidden();
        asStaff(orderStaff('marketing-manager'))->getJson('/api/v1/admin/discounts')->assertOk();
    });

    it('creates a discount, upper-cases the code and writes an audit entry', function () {
        $res = asStaff(orderStaff('marketing-manager'))->postJson('/api/v1/admin/discounts', discountPayload(['min_order_kobo' => 500_000, 'usage_limit' => 100, 'per_customer_limit' => 1]))
            ->assertCreated()->assertJsonPath('data.code', 'SPRING10')->assertJsonPath('data.percent', 10)->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('discounts', ['code' => 'SPRING10', 'type' => 'percentage', 'value' => 10, 'min_order_kobo' => 500_000]);
        expect(AuditLog::where('action', 'discount.created')->count())->toBe(1)->and($res->json('data.uses'))->toBe(0);
    });

    it('creates product and category discounts with their targets', function () {
        $product = Product::factory()->create();
        $category = Category::factory()->create();
        $staff = orderStaff('marketing-manager');

        asStaff($staff)->postJson('/api/v1/admin/discounts', discountPayload(['code' => 'PRODS', 'scope' => 'products', 'product_ids' => [$product->id]]))
            ->assertCreated()->assertJsonPath('data.products.0.id', $product->id);
        asStaff($staff)->postJson('/api/v1/admin/discounts', discountPayload(['code' => 'CATS', 'type' => 'fixed', 'percent' => null, 'amount_kobo' => 200_000, 'scope' => 'categories', 'category_ids' => [$category->id]]))
            ->assertCreated()->assertJsonPath('data.categories.0.id', $category->id)->assertJsonPath('data.amount_kobo', 200_000);
    });

    it('refuses combinations that make no sense', function (array $override, string $field) {
        $product = Product::factory()->create();
        Discount::factory()->create(['code' => 'TAKEN']);
        $payload = json_decode(str_replace('"__product__"', json_encode((string) $product->id), json_encode(array_merge(discountPayload(), $override))), true);

        asStaff(orderStaff('marketing-manager'))->postJson('/api/v1/admin/discounts', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'a percentage with no percent' => [['percent' => null], 'percent'],
        'more than 100 percent' => [['percent' => 101], 'percent'],
        'a fixed amount with a percent as well' => [['type' => 'fixed', 'amount_kobo' => 100_000, 'percent' => 10], 'percent'],
        'a fixed amount with no amount' => [['type' => 'fixed', 'percent' => null], 'amount_kobo'],
        'a cap on a fixed amount' => [['type' => 'fixed', 'percent' => null, 'amount_kobo' => 100_000, 'max_discount_kobo' => 50_000], 'max_discount_kobo'],
        'free delivery with a percent' => [['type' => 'free_delivery'], 'percent'],
        'free delivery aimed at categories' => [['type' => 'free_delivery', 'percent' => null, 'scope' => 'categories', 'category_ids' => ['00000000-0000-7000-8000-000000000000']], 'scope'],
        'a products scope with no products' => [['scope' => 'products'], 'product_ids'],
        'an order-wide code that lists products' => [['product_ids' => ['__product__']], 'product_ids'],
        'a product that does not exist' => [['scope' => 'products', 'product_ids' => ['00000000-0000-7000-8000-000000000000']], 'product_ids.0'],
        'an end date before the start' => [['starts_at' => '2026-12-01', 'ends_at' => '2026-11-01'], 'ends_at'],
        'one customer allowed more than the total' => [['usage_limit' => 5, 'per_customer_limit' => 6], 'per_customer_limit'],
        'a duplicate code' => [['code' => 'taken'], 'code'],
        'a badly formed code' => [['code' => 'no spaces allowed'], 'code'],
        'an unknown type' => [['type' => 'bogus'], 'type'],
    ]);

    it('updates only what is sent and re-checks the whole discount', function () {
        $staff = orderStaff('marketing-manager');
        $discount = Discount::factory()->percentage(10)->create(['code' => 'EDITME', 'usage_limit' => 50]);

        asStaff($staff)->patchJson("/api/v1/admin/discounts/{$discount->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.percent', 10)->assertJsonPath('data.usage_limit', 50);

        // Switching type without the new amount is refused, and leaves the discount untouched.
        asStaff($staff)->patchJson("/api/v1/admin/discounts/{$discount->id}", ['type' => 'fixed'])->assertUnprocessable()->assertJsonValidationErrors('amount_kobo');
        expect($discount->refresh()->type->value)->toBe('percentage');

        asStaff($staff)->patchJson("/api/v1/admin/discounts/{$discount->id}", ['type' => 'fixed', 'amount_kobo' => 250_000])->assertOk()
            ->assertJsonPath('data.type', 'fixed')->assertJsonPath('data.percent', null)->assertJsonPath('data.amount_kobo', 250_000);
        expect(AuditLog::where('action', 'discount.updated')->count())->toBe(2);
    });

    it('lists, searches and filters by status, and shows usage on one discount', function () {
        $staff = orderStaff('marketing-manager');
        $live = Discount::factory()->create(['code' => 'LIVE1', 'name' => 'Live one']);
        Discount::factory()->between(now()->addWeek(), null)->create(['code' => 'LATER']);
        Discount::factory()->between(null, now()->subDay())->create(['code' => 'OLD']);
        Discount::factory()->inactive()->create(['code' => 'OFF']);
        $order = someOrder();
        DiscountRedemption::factory()->create(['discount_id' => $live->id, 'order_id' => $order->id, 'amount_kobo' => 123_000, 'state' => RedemptionState::Redeemed]);

        foreach (['active' => 'LIVE1', 'scheduled' => 'LATER', 'expired' => 'OLD', 'disabled' => 'OFF'] as $status => $code) {
            asStaff($staff)->getJson("/api/v1/admin/discounts?status={$status}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $code);
        }
        asStaff($staff)->getJson('/api/v1/admin/discounts?q=live')->assertOk()->assertJsonCount(1, 'data');

        asStaff($staff)->getJson("/api/v1/admin/discounts/{$live->id}")->assertOk()
            ->assertJsonPath('data.uses', 1)->assertJsonPath('data.given.kobo', 123_000)->assertJsonPath('data.recent_redemptions.0.order', $order->reference);
    });

    it('deletes an unused discount, but not one that has been used', function () {
        $staff = orderStaff('marketing-manager');
        $unused = Discount::factory()->create();
        $used = Discount::factory()->create();
        DiscountRedemption::factory()->create(['discount_id' => $used->id, 'order_id' => someOrder()->id]);

        asStaff($staff)->deleteJson("/api/v1/admin/discounts/{$unused->id}")->assertNoContent();
        asStaff($staff)->deleteJson("/api/v1/admin/discounts/{$used->id}")->assertStatus(409)->assertJsonPath('code', 'discount_has_history');
        expect(Discount::count())->toBe(1)->and(AuditLog::where('action', 'discount.deleted')->count())->toBe(1);
    });

    it('lets promotions staff find products and categories without any catalogue access', function () {
        $staff = orderStaff('marketing-manager');
        $gin = sellableVariant(['sku' => 'GIN-LONDON-750'], ['name' => 'Aviation Gin'])->product;
        sellableVariant([], ['name' => 'Something Else']);
        Category::factory()->create(['name' => 'Spirits']);

        asStaff($staff)->getJson('/api/v1/admin/products')->assertForbidden(); // no catalogue access
        asStaff($staff)->getJson('/api/v1/admin/discounts/targets?q=avia')->assertOk()
            ->assertJsonCount(1, 'data.products')->assertJsonPath('data.products.0.id', $gin->id)->assertJsonFragment(['name' => 'Spirits']);
        asStaff($staff)->getJson('/api/v1/admin/discounts/targets?q=GIN-LONDON')->assertOk()->assertJsonCount(1, 'data.products');
        asStaff($staff)->getJson('/api/v1/admin/discounts/targets')->assertOk()->assertJsonCount(0, 'data.products');
        asStaff(orderStaff('product-manager'))->getJson('/api/v1/admin/discounts/targets')->assertForbidden();
    });
});
