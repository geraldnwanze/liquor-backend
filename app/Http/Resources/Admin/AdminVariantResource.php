<?php

namespace App\Http\Resources\Admin;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class AdminVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'volume_ml' => $this->volume_ml,
            'price_kobo' => $this->price_kobo,
            'sale_price_kobo' => $this->sale_price_kobo,
            'cost_price_kobo' => $this->when($request->user()?->hasPermission('catalog.view-cost'), $this->cost_price_kobo),
            'on_hand' => $this->on_hand,
            'reserved' => $this->reserved,
            'available' => $this->available(),
            'low_stock_threshold' => $this->low_stock_threshold,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
