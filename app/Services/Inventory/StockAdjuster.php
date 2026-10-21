<?php

namespace App\Services\Inventory;

use App\Enums\StockReason;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StockAdjuster
{
    /**
     * Change on-hand stock by $delta and write a ledger row, atomically.
     * The variant row is locked so concurrent adjustments serialise instead of overselling.
     *
     * @throws InsufficientStockException when the change would drop stock below reserved units
     */
    public function adjust(ProductVariant $variant, int $delta, StockReason $reason, ?User $by = null, ?string $note = null): InventoryMovement
    {
        return DB::transaction(function () use ($variant, $delta, $reason, $by, $note): InventoryMovement {
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->getKey());
            $newOnHand = $locked->on_hand + $delta;

            if ($newOnHand < $locked->reserved) {
                throw new InsufficientStockException(
                    "Cannot reduce stock for {$locked->sku} below the {$locked->reserved} unit(s) reserved."
                );
            }

            $locked->forceFill(['on_hand' => $newOnHand])->save();
            $variant->setRawAttributes($locked->getAttributes(), true);

            return InventoryMovement::create([
                'product_variant_id' => $locked->getKey(),
                'user_id' => $by?->getKey(),
                'delta' => $delta,
                'on_hand_after' => $newOnHand,
                'reason' => $reason,
                'note' => $note,
            ]);
        });
    }
}
