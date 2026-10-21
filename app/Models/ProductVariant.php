<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['product_id', 'sku', 'barcode', 'name', 'volume_ml', 'price_kobo', 'sale_price_kobo', 'cost_price_kobo', 'low_stock_threshold', 'is_active', 'sort_order'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected static function booted(): void
    {
        // Cart lines are throwaway shopping state, not records: a deleted variant simply drops out of every cart.
        static::deleting(fn (self $variant) => CartItem::where('product_variant_id', $variant->id)->delete());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Sellable only while the variant is active and its product is live (not draft, archived or discontinued). */
    public function isPurchasable(): bool
    {
        return $this->is_active && $this->product?->status === ProductStatus::Active;
    }

    /** Stock that can still be sold: on hand minus reservations held for pending orders. */
    public function available(): int
    {
        return max(0, $this->on_hand - $this->reserved);
    }

    public function isLowStock(): bool
    {
        return $this->available() > 0 && $this->available() <= $this->low_stock_threshold;
    }

    /** Effective selling price in kobo; a sale price only applies when it is genuinely lower. */
    public function effectivePriceKobo(): int
    {
        $sale = $this->sale_price_kobo;

        return $sale !== null && $sale < $this->price_kobo ? $sale : $this->price_kobo;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
