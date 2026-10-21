<?php

namespace App\Http\Resources\Catalog;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full product detail for the storefront. Deliberately omits cost price and raw stock counts.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status->value,
            'brand' => $this->brand?->only(['name', 'slug']),
            'category' => $this->category?->only(['name', 'slug']),
            'short_description' => $this->short_description,
            'description' => $this->description,
            'country_of_origin' => $this->country_of_origin,
            'abv' => $this->abv,
            'flavor_profile' => $this->flavor_profile,
            'tasting_notes' => $this->tasting_notes,
            'serving_suggestions' => $this->serving_suggestions,
            'authenticity_note' => $this->authenticity_note,
            'is_age_restricted' => $this->is_age_restricted,
            'is_demo' => $this->is_demo,
            'rating' => $this->rating_count ? ['average' => round((float) $this->rating_avg, 1), 'count' => (int) $this->rating_count] : null,
            'images' => $this->images->map->only(['url', 'alt'])->values(),
            'variants' => $this->variants->where('is_active', true)->values()->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'name' => $v->name,
                'volume_ml' => $v->volume_ml,
                'price' => $this->money($v->price_kobo),
                'sale_price' => $v->effectivePriceKobo() < $v->price_kobo ? $this->money($v->effectivePriceKobo()) : null,
                'in_stock' => $v->available() > 0,
                'low_stock' => $v->isLowStock(),
            ]),
            'seo' => [
                'title' => $this->seo_title ?: $this->name.($this->brand ? ' | '.$this->brand->name : ''),
                'description' => $this->seo_description ?: $this->short_description,
            ],
        ];
    }

    /** @return array{kobo: int, formatted: string, currency: string} */
    private function money(int $kobo): array
    {
        return ['kobo' => $kobo, 'formatted' => Money::format($kobo), 'currency' => 'NGN'];
    }
}
