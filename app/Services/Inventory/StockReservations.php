<?php

namespace App\Services\Inventory;

use App\Enums\StockReason;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Reserved stock is held for an unpaid order. Paying turns the reservation into a sale; expiry or cancellation
 * releases it. All methods must run inside the caller's DB transaction and lock variant rows in ascending id
 * order, so concurrent orders cannot deadlock or oversell.
 */
class StockReservations
{
    /**
     * @param  list<string>  $variantIds
     * @return Collection<int, ProductVariant> locked variants (with product) keyed by id
     */
    public function lock(array $variantIds): Collection
    {
        $ids = array_values(array_unique($variantIds));
        sort($ids);

        return ProductVariant::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->with('product')->get()->keyBy('id');
    }

    /**
     * @param  Collection<int, ProductVariant>  $locked
     * @param  array<string, int>  $quantities  variant id => quantity
     */
    public function hold(Collection $locked, array $quantities): void
    {
        foreach ($quantities as $id => $quantity) {
            $variant = $locked[$id];
            if ($variant->available() < $quantity) {
                throw new InsufficientStockException("Not enough stock for {$variant->sku}.");
            }
            $variant->forceFill(['reserved' => $variant->reserved + $quantity])->save();
        }
    }

    public function release(Order $order): void
    {
        $quantities = $this->quantities($order);
        foreach ($this->lock(array_keys($quantities)) as $id => $variant) {
            $variant->forceFill(['reserved' => max(0, $variant->reserved - $quantities[$id])])->save();
        }
    }

    /** Payment confirmed: the reserved units leave the shelf. Writes a 'sale' ledger row per variant. */
    public function commit(Order $order): void
    {
        $quantities = $this->quantities($order);
        foreach ($this->lock(array_keys($quantities)) as $id => $variant) {
            $quantity = $quantities[$id];
            $variant->forceFill([
                'reserved' => max(0, $variant->reserved - $quantity),
                'on_hand' => $variant->on_hand - $quantity,
            ])->save();

            InventoryMovement::create([
                'product_variant_id' => $id,
                'delta' => -$quantity,
                'on_hand_after' => $variant->on_hand,
                'reason' => StockReason::Sale,
                'note' => "Order {$order->reference}",
            ]);
        }
    }

    /** A paid order was cancelled before it left: its units go back on the shelf, with a ledger entry per variant. */
    public function restock(Order $order): void
    {
        $quantities = $this->quantities($order);
        foreach ($this->lock(array_keys($quantities)) as $id => $variant) {
            $variant->forceFill(['on_hand' => $variant->on_hand + $quantities[$id]])->save();
            InventoryMovement::create([
                'product_variant_id' => $id,
                'delta' => $quantities[$id],
                'on_hand_after' => $variant->on_hand,
                'reason' => StockReason::OrderCancelled,
                'note' => "Order {$order->reference} cancelled",
            ]);
        }
    }

    /**
     * Fulfils an order whose reservation was already released (a payment that arrived after expiry). Succeeds only if
     * every line is still in stock; otherwise changes nothing and returns false.
     */
    public function sellDirectly(Order $order): bool
    {
        $quantities = $this->quantities($order);
        if (count($quantities) !== $order->items->count()) {
            return false; // a variant was deleted since the order was placed
        }

        $locked = $this->lock(array_keys($quantities));
        foreach ($quantities as $id => $quantity) {
            if (! isset($locked[$id]) || $locked[$id]->available() < $quantity) {
                return false;
            }
        }

        foreach ($quantities as $id => $quantity) {
            $variant = $locked[$id];
            $variant->forceFill(['on_hand' => $variant->on_hand - $quantity])->save();
            InventoryMovement::create([
                'product_variant_id' => $id,
                'delta' => -$quantity,
                'on_hand_after' => $variant->on_hand,
                'reason' => StockReason::Sale,
                'note' => "Order {$order->reference} (paid after reservation expired)",
            ]);
        }

        return true;
    }

    /** @return array<string, int> variant id => quantity (variants deleted since the order are skipped) */
    private function quantities(Order $order): array
    {
        $out = [];
        foreach ($order->items as $item) {
            if ($item->product_variant_id !== null) {
                $out[$item->product_variant_id] = ($out[$item->product_variant_id] ?? 0) + $item->quantity;
            }
        }

        return $out;
    }
}
