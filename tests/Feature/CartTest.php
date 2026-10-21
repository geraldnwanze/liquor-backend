<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Administration\SettingsRepository;
use App\Services\Commerce\CommerceSettings;

it('creates a guest cart on first add and returns a token', function () {
    $variant = sellableVariant();

    $response = $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])
        ->assertCreated()
        ->assertJsonPath('data.item_count', 2)
        ->assertJsonPath('data.subtotal.formatted', '₦50,000')
        ->assertJsonPath('data.items.0.unit_price.kobo', 2_500_000);

    $token = $response->json('data.token');
    expect($token)->toBeString()->and($response->headers->get('X-Cart-Token'))->toBe($token);

    guest($token)->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.item_count', 2);
});

it('returns an empty cart when there is no token or the token is junk', function () {
    guest()->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items', [])->assertJsonPath('data.token', null);
    guest('not-a-uuid')->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items', []);
    guest((string) Str::uuid())->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.item_count', 0);
});

it('uses server prices only and flags a price change since the item was added', function () {
    $variant = sellableVariant();
    $token = addToCart($variant);

    guest()->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 1, 'price_kobo' => 1])->assertCreated();
    guest($token)->getJson('/api/v1/cart')->assertJsonPath('data.items.0.price_changed_from', null);

    $variant->update(['price_kobo' => 3_000_000]);

    guest($token)->getJson('/api/v1/cart')
        ->assertJsonPath('data.items.0.unit_price.kobo', 3_000_000)
        ->assertJsonPath('data.items.0.price_changed_from.kobo', 2_500_000)
        ->assertJsonPath('data.subtotal.kobo', 3_000_000);
});

it('applies a sale price only when it is lower', function () {
    $variant = sellableVariant(['sale_price_kobo' => 2_000_000]);
    $token = addToCart($variant);

    guest($token)->getJson('/api/v1/cart')->assertJsonPath('data.items.0.unit_price.kobo', 2_000_000);
});

it('rejects quantities beyond stock and out-of-stock variants', function () {
    $this->postJson('/api/v1/cart/items', ['variant_id' => sellableVariant(['on_hand' => 3])->id, 'quantity' => 4])
        ->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock')->assertJsonPath('details.available', 3);

    $this->postJson('/api/v1/cart/items', ['variant_id' => sellableVariant(['on_hand' => 0])->id, 'quantity' => 1])
        ->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock')->assertJsonPath('details.available', 0);

    // Reserved stock for other customers' unpaid orders is not available either.
    $this->postJson('/api/v1/cart/items', ['variant_id' => sellableVariant(['on_hand' => 5, 'reserved' => 5])->id, 'quantity' => 1])
        ->assertUnprocessable();
});

it('rejects unpublished, archived, discontinued and inactive items', function () {
    foreach ([['status' => 'draft'], ['status' => 'archived'], ['status' => 'discontinued']] as $product) {
        $this->postJson('/api/v1/cart/items', ['variant_id' => sellableVariant([], $product)->id, 'quantity' => 1])
            ->assertUnprocessable()->assertJsonPath('code', 'variant_unavailable');
    }
    $this->postJson('/api/v1/cart/items', ['variant_id' => sellableVariant(['is_active' => false])->id, 'quantity' => 1])
        ->assertUnprocessable()->assertJsonPath('code', 'variant_unavailable');
});

it('validates input and enforces the per-line limit across repeated adds', function () {
    $variant = sellableVariant(['on_hand' => 100]);
    $this->postJson('/api/v1/cart/items', ['variant_id' => 99999, 'quantity' => 1])->assertUnprocessable();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 0])->assertUnprocessable();
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 1.5])->assertUnprocessable();

    $token = addToCart($variant, 10);
    guest($token)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 3])
        ->assertUnprocessable()->assertJsonPath('code', 'quantity_limit')->assertJsonPath('details.max', 12);
});

it('adds to an existing line instead of duplicating it', function () {
    $variant = sellableVariant();
    $token = addToCart($variant, 2);
    guest($token)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 3])
        ->assertCreated()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 5);
});

it('updates and removes lines, re-checking stock on update', function () {
    $variant = sellableVariant(['on_hand' => 4]);
    $token = addToCart($variant, 1);
    $itemId = guest($token)->getJson('/api/v1/cart')->json('data.items.0.id');

    guest($token)->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 4])->assertOk()->assertJsonPath('data.item_count', 4);
    guest($token)->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 5])->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock');
    guest($token)->deleteJson("/api/v1/cart/items/{$itemId}")->assertOk()->assertJsonPath('data.items', []);
});

it('never lets one cart touch another cart\'s items', function () {
    $mine = addToCart(sellableVariant());
    $theirs = addToCart(sellableVariant());
    $theirItem = guest($theirs)->getJson('/api/v1/cart')->json('data.items.0.id');

    guest($mine)->patchJson("/api/v1/cart/items/{$theirItem}", ['quantity' => 2])->assertNotFound();
    guest($mine)->deleteJson("/api/v1/cart/items/{$theirItem}")->assertNotFound();
    guest()->deleteJson("/api/v1/cart/items/{$theirItem}")->assertNotFound(); // no cart at all
    expect(CartItem::count())->toBe(2);
});

it('surfaces items that became unavailable instead of silently dropping them', function () {
    $gone = sellableVariant(['on_hand' => 10], ['name' => 'Gone Gin']);
    $short = sellableVariant(['on_hand' => 10]);
    $fine = sellableVariant();
    $token = addToCart($gone);
    addToCart($short, 5, $token);
    addToCart($fine, 1, $token);

    $gone->product->update(['status' => 'archived']);
    $short->forceFill(['on_hand' => 2])->save();

    $cart = guest($token)->getJson('/api/v1/cart')->assertOk();
    $cart->assertJsonCount(3, 'data.items')->assertJsonPath('data.has_issues', true)
        ->assertJsonPath('data.items.0.issues', ['unavailable'])
        ->assertJsonPath('data.items.1.issues', ['insufficient_stock'])
        ->assertJsonPath('data.items.1.max_quantity', 2)
        ->assertJsonPath('data.items.2.issues', []);
    // Unavailable lines are not charged for; the rest are still priced at the requested quantity.
    $cart->assertJsonPath('data.subtotal.kobo', 2_500_000 * 5 + 2_500_000);
});

it('estimates delivery for a state and city, and says nothing when we do not deliver there', function () {
    ikejaZone(['free_threshold_kobo' => 10_000_000, 'estimated_days_min' => 1, 'estimated_days_max' => 2]);
    $token = addToCart(sellableVariant());

    guest($token)->getJson('/api/v1/cart?state=lagos&city=IKEJA')
        ->assertJsonPath('data.delivery.fee.kobo', 150_000)->assertJsonPath('data.delivery.estimate', '1-2 days')->assertJsonPath('data.delivery.is_estimate', true);
    guest($token)->getJson('/api/v1/cart?state=Kano&city=Kano')->assertJsonPath('data.delivery', null);
    guest($token)->getJson('/api/v1/cart')->assertJsonPath('data.delivery', null);
});

it('keeps a signed-in customer\'s cart across sessions and hides the guest token', function () {
    $user = User::factory()->create();
    $variant = sellableVariant();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])
        ->assertCreated()->assertJsonPath('data.token', null);

    $this->actingAs(User::find($user->id), 'sanctum')->getJson('/api/v1/cart')->assertJsonPath('data.item_count', 2);
    expect(Cart::where('user_id', $user->id)->count())->toBe(1);
});

it('merges the guest cart into the customer cart on login', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $shared = sellableVariant(['on_hand' => 50]);
    $guestOnly = sellableVariant();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/cart/items', ['variant_id' => $shared->id, 'quantity' => 2])->assertCreated();
    app('auth')->forgetGuards();

    $guestToken = addToCart($shared, 3);
    addToCart($guestOnly, 1, $guestToken);

    guest($guestToken)->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])->assertOk();

    $cart = Cart::where('user_id', $user->id)->first();
    expect($cart->items->pluck('quantity', 'product_variant_id')->all())->toBe([$shared->id => 5, $guestOnly->id => 1]);
    expect(Cart::where('token', $guestToken)->exists())->toBeFalse();
});

it('caps merged quantities at the per-line limit and also merges on registration', function () {
    $variant = sellableVariant(['on_hand' => 100]);
    $guestToken = addToCart($variant, 10);

    guest($guestToken)->postJson('/api/v1/auth/register', [
        'name' => 'New Customer', 'email' => 'new@example.com', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd',
    ])->assertCreated();

    $user = User::firstWhere('email', 'new@example.com');
    expect(Cart::firstWhere('user_id', $user->id)->items->first()->quantity)->toBe(10);

    $second = addToCart($variant, 8);
    guest($second)->postJson('/api/v1/auth/login', ['email' => 'new@example.com', 'password' => 'Str0ngPassw0rd'])->assertOk();
    expect(Cart::firstWhere('user_id', $user->id)->items->first()->quantity)->toBe(app(CommerceSettings::class)->maxQuantityPerLine());
});

it('reads commerce limits from settings, overriding config', function () {
    app(SettingsRepository::class)->set('commerce', 'max_quantity_per_line', 2);
    $variant = sellableVariant();

    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 3])->assertUnprocessable()->assertJsonPath('code', 'quantity_limit');
    expect(ProductVariant::count())->toBe(1);
});

it('keeps a guest order reachable after the guest signs in', function () {
    [$order, $token] = payableOrder();
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'password']);

    $bearer = guest($token)->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])->assertOk()->json('data.token');

    $order->refresh();
    expect($order->user_id)->not->toBeNull()->and($order->cart_id)->not->toBeNull();

    guest()->withToken($bearer)->getJson('/api/v1/me/orders/'.$order->reference)->assertOk();
});

it('treats email case and spacing the same at register and login', function () {
    guest()->postJson('/api/v1/auth/register', [
        'name' => 'Case Test', 'email' => '  Mixed@Example.COM ', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd',
    ])->assertCreated();

    expect(User::where('email', 'mixed@example.com')->exists())->toBeTrue();
    guest()->postJson('/api/v1/auth/login', ['email' => 'MIXED@example.com', 'password' => 'Str0ngPassw0rd'])->assertOk();
    guest()->postJson('/api/v1/auth/register', [
        'name' => 'Dup', 'email' => 'MIXED@example.com', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd',
    ])->assertUnprocessable();
});
