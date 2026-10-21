<?php

use App\Enums\StockReason;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\StockAdjuster;

it('adjusts stock and records who, why and when', function () {
    $variant = ProductVariant::factory()->create(['on_hand' => 10]);
    $admin = User::factory()->create();

    $movement = app(StockAdjuster::class)->adjust($variant, 5, StockReason::Received, $admin, 'PO-1');

    expect($variant->on_hand)->toBe(15)
        ->and($movement->delta)->toBe(5)
        ->and($movement->on_hand_after)->toBe(15)
        ->and($movement->user_id)->toBe($admin->id)
        ->and($movement->reason)->toBe(StockReason::Received)
        ->and($movement->created_at)->not->toBeNull();
    $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'on_hand' => 15]);
});

it('refuses to reduce stock below reserved units and leaves no ledger row', function () {
    $variant = ProductVariant::factory()->create(['on_hand' => 10, 'reserved' => 8]);

    expect(fn () => app(StockAdjuster::class)->adjust($variant, -5, StockReason::Damaged))
        ->toThrow(InsufficientStockException::class);

    $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'on_hand' => 10]);
    $this->assertDatabaseCount('inventory_movements', 0);
});

it('works from a stale model instance by locking and re-reading the row', function () {
    $stale = ProductVariant::factory()->create(['on_hand' => 10]);
    ProductVariant::whereKey($stale->id)->update(['on_hand' => 20]);

    app(StockAdjuster::class)->adjust($stale, -5, StockReason::Correction);

    $this->assertDatabaseHas('product_variants', ['id' => $stale->id, 'on_hand' => 15]);
});
