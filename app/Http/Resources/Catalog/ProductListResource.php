<?php

namespace App\Http\Resources\Catalog;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Lean shape for listing pages. Requires brand, category, variants and images eager loaded. @mixin Product */
class ProductListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $variants = $this->variants->where('is_active', true);
        $cheapest = $variants->sortBy(fn ($v) => $v->effectivePriceKobo())->first();
        $inStock = $variants->contains(fn ($v) => $v->available() > 0);

        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'brand' => $this->brand?->only(['name', 'slug']),
            'category' => $this->category?->only(['name', 'slug']),
            'image' => $this->images->first()?->only(['url', 'alt']),
            'status' => $this->status->value,
            'in_stock' => $inStock,
            'variant_count' => $variants->count(),
            'is_demo' => $this->is_demo,
            'rating' => $this->rating_count ? ['average' => round((float) $this->rating_avg, 1), 'count' => (int) $this->rating_count] : null,
            'price_from' => $cheapest ? $this->price($cheapest->effectivePriceKobo()) : null,
            'compare_at_price' => $cheapest && $cheapest->effectivePriceKobo() < $cheapest->price_kobo
                ? $this->price($cheapest->price_kobo) : null,
        ];
    }

    /** @return array{kobo: int, formatted: string, currency: string} */
    private function price(int $kobo): array
    {
        return ['kobo' => $kobo, 'formatted' => Money::format($kobo), 'currency' => 'NGN'];
    }
}
