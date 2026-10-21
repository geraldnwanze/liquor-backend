<?php

namespace App\Models;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\RedemptionState;
use Database\Factories\DiscountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'name', 'type', 'value', 'max_discount_kobo', 'min_order_kobo', 'scope', 'starts_at', 'ends_at',
    'usage_limit', 'per_customer_limit', 'is_active', 'created_by',
])]
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'scope' => DiscountScope::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public static function normaliseCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'discount_product');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'discount_category');
    }

    /** @return HasMany<DiscountRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    /** Uses that count against the limits: paid orders plus orders still awaiting payment. */
    public function activeUseCount(): int
    {
        return $this->redemptions()->whereIn('state', [RedemptionState::Reserved, RedemptionState::Redeemed])->count();
    }

    /** What staff see: disabled | scheduled | expired | active. */
    public function status(): string
    {
        if (! $this->is_active) {
            return 'disabled';
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'scheduled';
        }
        if ($this->ends_at && $this->ends_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }
}
