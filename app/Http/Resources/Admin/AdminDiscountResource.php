<?php

namespace App\Http\Resources\Admin;

use App\Enums\DiscountType;
use App\Models\Discount;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Discount */
class AdminDiscountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'percent' => $this->type === DiscountType::Percentage ? $this->value : null,
            'amount_kobo' => $this->type === DiscountType::Fixed ? $this->value : null,
            'max_discount_kobo' => $this->max_discount_kobo,
            'min_order_kobo' => $this->min_order_kobo,
            'scope' => $this->scope->value,
            'products' => $this->whenLoaded('products', fn () => $this->products->map->only(['id', 'name', 'slug'])->values()),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map->only(['id', 'name', 'slug'])->values()),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'usage_limit' => $this->usage_limit,
            'per_customer_limit' => $this->per_customer_limit,
            'is_active' => $this->is_active,
            'status' => $this->status(),
            // Reserved (awaiting payment) and redeemed (paid) uses; released uses are not counted.
            'uses' => $this->uses_count ?? $this->activeUseCount(),
            'given' => $this->whenNotNull($this->given_kobo === null ? null : Money::toArray((int) $this->given_kobo)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
