<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderLifecycle;

beforeEach(fn () => ikejaZone());

function pendingOrder(int $quantity = 2, int $onHand = 10): array
{
    $variant = sellableVariant(['on_hand' => $onHand]);
    $ref = checkout(addToCart($variant, $quantity))->assertCreated()->json('data.reference');

    return [Order::firstWhere('reference', $ref), $variant];
}

it('expires unpaid orders past their reservation and releases the stock', function () {
    [$order, $variant] = pendingOrder(3);
    [$fresh] = pendingOrder(1);
    $order->forceFill(['reservation_expires_at' => now()->subMinute()])->save();

    $this->artisan('orders:expire-pending')->expectsOutput('Expired 1 order(s).')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Expired)->and($fresh->refresh()->status)->toBe(OrderStatus::PendingPayment);
    expect($variant->refresh()->reserved)->toBe(0)->and($variant->on_hand)->toBe(10);

    $this->artisan('orders:expire-pending')->expectsOutput('Expired 0 order(s).'); // idempotent: no double release
    expect($variant->refresh()->reserved)->toBe(0);
});

it('cancelling releases stock once and cannot be repeated', function () {
    [$order, $variant] = pendingOrder(4);

    expect(app(OrderLifecycle::class)->cancel($order))->toBeTrue()->and(app(OrderLifecycle::class)->cancel($order))->toBeFalse();
    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)->and($order->cancelled_at)->not->toBeNull()->and($variant->refresh()->reserved)->toBe(0);
});

it('marking paid turns the reservation into a sale with a ledger entry, exactly once', function () {
    [$order, $variant] = pendingOrder(3, 10);

    expect(app(OrderLifecycle::class)->markPaid($order))->toBeTrue();
    expect(app(OrderLifecycle::class)->markPaid($order))->toBeFalse(); // duplicate webhook / retry

    $variant->refresh();
    expect($variant->on_hand)->toBe(7)->and($variant->reserved)->toBe(0);
    expect($order->refresh()->status)->toBe(OrderStatus::Paid)->and($order->payment_status)->toBe(PaymentStatus::Paid)->and($order->paid_at)->not->toBeNull();

    $sale = InventoryMovement::where('product_variant_id', $variant->id)->where('reason', 'sale')->get();
    expect($sale)->toHaveCount(1)->and($sale[0]->delta)->toBe(-3)->and($sale[0]->on_hand_after)->toBe(7)->and($sale[0]->note)->toBe("Order {$order->reference}");
});

it('does not let an expired, cancelled or paid order change state again', function () {
    [$expired] = pendingOrder();
    app(OrderLifecycle::class)->expire($expired);
    [$paid] = pendingOrder();
    app(OrderLifecycle::class)->markPaid($paid);

    $lifecycle = app(OrderLifecycle::class);
    expect($lifecycle->markPaid($expired))->toBeFalse()->and($lifecycle->cancel($paid))->toBeFalse()->and($lifecycle->expire($paid))->toBeFalse();
    expect($expired->refresh()->status)->toBe(OrderStatus::Expired)->and($paid->refresh()->status)->toBe(OrderStatus::Paid);
});

it('has no HTTP route that can mark an order paid', function () {
    [$order] = pendingOrder();

    foreach (['paid', 'pay', 'mark-paid', 'status'] as $suffix) {
        // 404 (no such path) or 405 (path exists, but only for GET): either way, nothing can change the order.
        expect($this->postJson("/api/v1/orders/{$order->reference}/{$suffix}")->status())->toBeIn([404, 405]);
        expect($this->patchJson("/api/v1/orders/{$order->reference}", ['payment_status' => 'paid', 'status' => 'paid'])->status())->toBeIn([404, 405]);
    }
    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('prunes only stale guest carts', function () {
    $old = Cart::create(['token' => (string) Str::uuid(), 'last_activity_at' => now()->subDays(90)]);
    $recent = Cart::create(['token' => (string) Str::uuid(), 'last_activity_at' => now()->subDays(5)]);
    $user = Cart::create(['token' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id, 'last_activity_at' => now()->subDays(400)]);

    $this->artisan('carts:prune')->assertSuccessful();

    expect(Cart::find($old->id))->toBeNull()->and(Cart::find($recent->id))->not->toBeNull()->and(Cart::find($user->id))->not->toBeNull();
});
