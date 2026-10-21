<?php

use App\Models\Order;
use App\Services\Orders\OrderLifecycle;

beforeEach(function () {
    configureGateways();
    ikejaZone();
});

function sale($variant, int $quantity, bool $paid = true, ?string $paidAt = null): void
{
    $order = Order::firstWhere('reference', checkout(addToCart($variant, $quantity))->assertCreated()->json('data.reference'));
    if ($paid) {
        app(OrderLifecycle::class)->markPaid($order);
        $paidAt && $order->forceFill(['paid_at' => $paidAt])->save();
    }
}

it('lists products by paid units sold, most first, ignoring unpaid and old sales', function () {
    [$a, $b, $c, $d] = [sellableVariant(['on_hand' => 99]), sellableVariant(['on_hand' => 99]), sellableVariant(['on_hand' => 99]), sellableVariant(['on_hand' => 99])];
    sale($a, 2);
    sale($b, 5);
    sale($c, 10, paid: false);
    sale($d, 10, paidAt: now()->subDays(120)->toDateTimeString());
    sale($e = sellableVariant(['on_hand' => 99]), 10);
    Order::latest('id')->first()->forceFill(['status' => 'cancelled'])->save(); // paid, then cancelled

    $slugs = guest()->getJson('/api/v1/products/best-sellers')->assertOk()->json('data.*.slug');

    expect($slugs)->toBe([$b->product->slug, $a->product->slug]);
});

it('is empty until something has sold, and honours the limit', function () {
    sellableVariant();
    guest()->getJson('/api/v1/products/best-sellers')->assertOk()->assertJsonCount(0, 'data');

    foreach (range(1, 3) as $_) {
        sale(sellableVariant(['on_hand' => 9]), 1);
    }
    guest()->getJson('/api/v1/products/best-sellers?limit=2')->assertJsonCount(2, 'data');
});
