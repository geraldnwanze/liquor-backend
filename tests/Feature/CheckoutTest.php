<?php

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\User;
use App\Services\Administration\SettingsRepository;

beforeEach(fn () => ikejaZone());

it('creates a pending-payment order with server-calculated totals and reserves stock', function () {
    $variant = sellableVariant(['price_kobo' => 2_500_000, 'sale_price_kobo' => 2_000_000, 'on_hand' => 10]);
    $token = addToCart($variant, 3);

    $res = checkout($token, ['total_kobo' => 1, 'subtotal_kobo' => 1, 'delivery_fee_kobo' => 0, 'unit_price' => 1])->assertCreated();

    $res->assertJsonPath('data.status', 'pending_payment')
        ->assertJsonPath('data.payment_status', 'pending')
        ->assertJsonPath('data.subtotal.kobo', 6_000_000)
        ->assertJsonPath('data.delivery_fee.kobo', 150_000)
        ->assertJsonPath('data.total.kobo', 6_150_000)
        ->assertJsonPath('data.total.formatted', '₦61,500')
        ->assertJsonPath('data.customer.phone', '+2348031234567')
        ->assertJsonPath('data.items.0.unit_price.kobo', 2_000_000)
        ->assertJsonPath('data.delivery.zone', 'Lagos Mainland (demo)');
    expect($res->json('data.reference'))->toMatch('/^LQ[A-HJKMNP-Z2-9]{10}$/')->and($res->headers->get('Idempotent-Replayed'))->toBe('false');

    $variant->refresh();
    expect($variant->reserved)->toBe(3)->and($variant->on_hand)->toBe(10)->and($variant->available())->toBe(7);
    $order = Order::firstWhere('reference', $res->json('data.reference'));
    expect($order->reservation_expires_at->isFuture())->toBeTrue()->and($order->items->first()->product_name)->toBe($variant->product->name);
});

it('prices from the database at checkout time, not from when the item was added', function () {
    $variant = sellableVariant(['price_kobo' => 2_500_000]);
    $token = addToCart($variant, 2);
    $variant->update(['price_kobo' => 2_800_000]);

    checkout($token)->assertCreated()->assertJsonPath('data.subtotal.kobo', 5_600_000);
});

it('is idempotent: a retry returns the same order and reserves stock only once', function () {
    $variant = sellableVariant();
    $token = addToCart($variant, 2);

    $first = checkout($token)->assertCreated();
    $retry = checkout($token)->assertOk();

    expect($retry->json('data.reference'))->toBe($first->json('data.reference'))->and($retry->headers->get('Idempotent-Replayed'))->toBe('true');
    expect(Order::count())->toBe(1)->and($variant->refresh()->reserved)->toBe(2);
});

it('rejects a reused key with different details', function () {
    $token = addToCart(sellableVariant());
    checkout($token)->assertCreated();

    checkout($token, ['address' => '99 Different Street'])->assertConflict()->assertJsonPath('code', 'idempotency_key_reused');
    expect(Order::count())->toBe(1);
});

it('scopes idempotency keys to the cart, so a leaked key cannot expose or replay another order', function () {
    $a = addToCart(sellableVariant());
    $b = addToCart(sellableVariant());

    $orderA = checkout($a, [], 'shared-key-0123456789')->assertCreated()->json('data.reference');
    $orderB = checkout($b, ['name' => 'Someone Else'], 'shared-key-0123456789')->assertCreated();

    expect($orderB->json('data.reference'))->not->toBe($orderA)->and($orderB->json('data.customer.name'))->toBe('Someone Else');
    expect(Order::count())->toBe(2);
});

it('requires a well-formed Idempotency-Key header', function () {
    $token = addToCart(sellableVariant());

    guest($token)->withoutHeader('Idempotency-Key')->postJson('/api/v1/checkout', checkoutPayload())->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    checkout($token, [], 'short')->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    checkout($token, [], 'has spaces and symbols!!')->assertUnprocessable();
    expect(Order::count())->toBe(0);
});

it('fails clearly for an empty or missing cart', function () {
    guest()->withHeader('Idempotency-Key', 'idem-key-0123456789')->postJson('/api/v1/checkout', checkoutPayload())->assertUnprocessable()->assertJsonPath('code', 'cart_empty');

    $token = addToCart(sellableVariant());
    $itemId = guest($token)->getJson('/api/v1/cart')->json('data.items.0.id');
    guest($token)->deleteJson("/api/v1/cart/items/{$itemId}");
    checkout($token)->assertUnprocessable()->assertJsonPath('code', 'cart_empty');
});

it('re-checks stock at checkout and creates no order or reservation when it fails', function () {
    $variant = sellableVariant(['on_hand' => 5]);
    $token = addToCart($variant, 4);
    $variant->forceFill(['on_hand' => 2])->save();

    checkout($token)->assertUnprocessable()
        ->assertJsonPath('code', 'cart_has_issues')
        ->assertJsonPath('details.lines.0.issues', ['insufficient_stock'])
        ->assertJsonPath('details.lines.0.available', 2);

    expect(Order::count())->toBe(0)->and($variant->refresh()->reserved)->toBe(0);
});

it('blocks products that were archived after being added to the cart', function () {
    $variant = sellableVariant();
    $token = addToCart($variant);
    $variant->product->update(['status' => 'archived']);

    checkout($token)->assertUnprocessable()->assertJsonPath('details.lines.0.issues', ['unavailable']);
});

it('never oversells the last unit: the second buyer is refused', function () {
    $variant = sellableVariant(['on_hand' => 1]);
    $first = addToCart($variant);
    $second = addToCart($variant); // cart advisory check passes: nothing is reserved yet

    checkout($first, [], 'first-key-0123456789')->assertCreated();
    checkout($second, [], 'second-key-012345678')->assertUnprocessable()->assertJsonPath('code', 'cart_has_issues');

    expect(Order::count())->toBe(1)->and($variant->refresh()->reserved)->toBe(1)->and($variant->available())->toBe(0);
});

it('refuses addresses we do not deliver to, using city zones first and state-wide zones as fallback', function () {
    $token = addToCart(sellableVariant());

    checkout($token, ['state' => 'Kano', 'city' => 'Kano'])->assertUnprocessable()->assertJsonPath('code', 'delivery_unavailable');

    DeliveryZone::factory()->statewide('Oyo')->create(['name' => 'Oyo (demo)', 'fee_kobo' => 300_000]);
    checkout($token, ['state' => 'oyo', 'city' => 'Ibadan'], 'oyo-key-01234567890')->assertCreated()->assertJsonPath('data.delivery_fee.kobo', 300_000)->assertJsonPath('data.delivery.zone', 'Oyo (demo)');

    $token2 = addToCart(sellableVariant());
    DeliveryZone::factory()->create(['name' => 'Ibadan Central (demo)', 'state' => 'Oyo', 'city' => 'Ibadan', 'fee_kobo' => 100_000]);
    checkout($token2, ['state' => 'Oyo', 'city' => 'IBADAN'], 'oyo2-key-0123456789')->assertCreated()->assertJsonPath('data.delivery_fee.kobo', 100_000);
});

it('ignores inactive zones and applies free delivery above the threshold', function () {
    DeliveryZone::withTrashed()->forceDelete();
    ikejaZone(['is_active' => false]);
    $token = addToCart(sellableVariant());
    checkout($token)->assertUnprocessable()->assertJsonPath('code', 'delivery_unavailable');

    DeliveryZone::withTrashed()->forceDelete();
    ikejaZone(['free_threshold_kobo' => 5_000_000]);
    $big = addToCart(sellableVariant(), 2); // 50,000 naira in kobo = 5,000,000
    checkout($big, [], 'free-key-0123456789')->assertCreated()->assertJsonPath('data.delivery_fee.kobo', 0)->assertJsonPath('data.total.kobo', 5_000_000);

    $small = addToCart(sellableVariant(), 1);
    checkout($small, [], 'paid-key-0123456789')->assertCreated()->assertJsonPath('data.delivery_fee.kobo', 150_000);
});

it('requires age confirmation for age-restricted products and honours a date of birth on the account', function () {
    $token = addToCart(sellableVariant());

    checkout($token, ['age_confirmed' => false])->assertUnprocessable()->assertJsonPath('code', 'age_not_verified');
    checkout($token, ['age_confirmed' => null], 'null-key-0123456789')->assertUnprocessable()->assertJsonPath('code', 'age_not_verified');
    expect(Order::count())->toBe(0);

    $minor = User::factory()->create(['date_of_birth' => now()->subYears(16)]);
    $variant = sellableVariant();
    $this->actingAs($minor, 'sanctum')->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
    $this->actingAs($minor, 'sanctum')->withHeader('Idempotency-Key', 'minor-key-012345678')->postJson('/api/v1/checkout', checkoutPayload(['age_confirmed' => true]))
        ->assertUnprocessable()->assertJsonPath('code', 'age_not_verified');
    expect(Order::count())->toBe(0)->and($variant->refresh()->reserved)->toBe(0);
});

it('does not demand age confirmation when nothing in the cart is age-restricted', function () {
    $token = addToCart(sellableVariant([], ['is_age_restricted' => false]));

    checkout($token, ['age_confirmed' => false])->assertCreated()->assertJsonPath('data.status', 'pending_payment');
});

it('uses the configurable minimum age', function () {
    app(SettingsRepository::class)->set('commerce', 'minimum_age', 21);
    $user = User::factory()->create(['date_of_birth' => now()->subYears(19)]);
    $variant = sellableVariant();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1]);

    $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'age21-key-0123456789')->postJson('/api/v1/checkout', checkoutPayload())
        ->assertUnprocessable()->assertJsonPath('code', 'age_not_verified');
});

it('validates contact details and strips markup from free text', function () {
    $token = addToCart(sellableVariant());

    checkout($token, ['phone' => '12345', 'email' => 'nope', 'name' => '', 'address' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email', 'name', 'address']);

    checkout($token, ['delivery_instructions' => '<script>alert(1)</script>Leave with security', 'name' => '<b>Ada</b> Obi'], 'clean-key-0123456789')
        ->assertCreated()->assertJsonPath('data.delivery.instructions', 'alert(1)Leave with security')->assertJsonPath('data.customer.name', 'Ada Obi');
});

it('enforces the per-order unit limit', function () {
    app(SettingsRepository::class)->set('commerce', 'max_units_per_order', 5);
    $a = sellableVariant();
    $token = addToCart($a, 3);
    addToCart(sellableVariant(), 3, $token);

    checkout($token)->assertUnprocessable()->assertJsonPath('code', 'order_limit')->assertJsonPath('details.max_units', 5);
    expect(Order::count())->toBe(0);
});

it('lets a new checkout supersede the unpaid order and frees its stock', function () {
    $variant = sellableVariant(['on_hand' => 5]);
    $token = addToCart($variant, 4);

    $first = checkout($token, [], 'first-key-0123456789')->assertCreated()->json('data.reference');
    // The customer fixes their address: a new key, and the first order must not keep 4 units locked.
    $second = checkout($token, ['address' => '7 New Street'], 'second-key-012345678')->assertCreated()->json('data.reference');

    expect($second)->not->toBe($first);
    expect(Order::firstWhere('reference', $first)->status)->toBe(OrderStatus::Cancelled);
    expect($variant->refresh()->reserved)->toBe(4);
});

it('attaches the order to a signed-in customer and shows it only to them', function () {
    $ada = User::factory()->create();
    $bob = User::factory()->create();
    $variant = sellableVariant();
    $this->actingAs($ada, 'sanctum')->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1]);

    $ref = $this->actingAs($ada, 'sanctum')->withHeader('Idempotency-Key', 'ada-key-01234567890')->postJson('/api/v1/checkout', checkoutPayload())
        ->assertCreated()->json('data.reference');

    expect(Order::firstWhere('reference', $ref)->user_id)->toBe($ada->id);
    $this->actingAs($ada, 'sanctum')->getJson("/api/v1/me/orders/{$ref}")->assertOk()->assertJsonPath('data.reference', $ref);
    $this->actingAs($ada, 'sanctum')->getJson('/api/v1/me/orders')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($bob, 'sanctum')->getJson("/api/v1/me/orders/{$ref}")->assertNotFound();
    $this->actingAs($bob, 'sanctum')->getJson('/api/v1/me/orders')->assertOk()->assertJsonCount(0, 'data');
    app('auth')->forgetGuards();
    guest()->getJson("/api/v1/me/orders/{$ref}")->assertUnauthorized();
});

it('exposes only a minimal public status by unguessable reference', function () {
    $ref = checkout(addToCart(sellableVariant()))->assertCreated()->json('data.reference');

    $this->getJson("/api/v1/orders/{$ref}/status")->assertOk()
        ->assertExactJson(['data' => ['reference' => $ref, 'status' => 'pending_payment', 'payment_status' => 'pending']]);
    $this->getJson('/api/v1/orders/LQAAAAAAAAAA/status')->assertNotFound();
    $this->getJson('/api/v1/orders/1/status')->assertNotFound();
});

it('leaves the cart intact after checkout so a failed payment can be retried', function () {
    $token = addToCart(sellableVariant(), 2);
    checkout($token)->assertCreated();

    guest($token)->getJson('/api/v1/cart')->assertJsonPath('data.item_count', 2);
    expect(Cart::count())->toBe(1);
});

it('shows full order details only to the guest cart that placed it, or its signed-in owner', function () {
    $mine = addToCart(sellableVariant());
    $other = addToCart(sellableVariant());
    $ref = checkout($mine)->assertCreated()->json('data.reference');

    guest($mine)->getJson("/api/v1/orders/{$ref}")->assertOk()->assertJsonPath('data.reference', $ref)->assertJsonPath('data.customer.email', 'ada@example.com');
    guest($other)->getJson("/api/v1/orders/{$ref}")->assertNotFound();
    guest()->getJson("/api/v1/orders/{$ref}")->assertNotFound();
    guest($mine)->getJson('/api/v1/orders/LQAAAAAAAAAA')->assertNotFound();

    $owner = User::factory()->create();
    $variant = sellableVariant();
    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1]);
    $ownRef = $this->actingAs($owner, 'sanctum')->withHeader('Idempotency-Key', 'own-key-0123456789ab')->postJson('/api/v1/checkout', checkoutPayload())->json('data.reference');
    $this->actingAs($owner, 'sanctum')->getJson("/api/v1/orders/{$ownRef}")->assertOk();
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/v1/orders/{$ownRef}")->assertNotFound();
});

it('publishes storefront-safe commerce rules', function () {
    app(SettingsRepository::class)->set('commerce', 'minimum_age', 21);

    $this->getJson('/api/v1/settings/public')->assertOk()
        ->assertJsonPath('data.commerce.minimum_age', 21)->assertJsonPath('data.commerce.max_quantity_per_line', 12)->assertJsonPath('data.currency', 'NGN');
});
