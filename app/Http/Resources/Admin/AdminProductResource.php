<?php

namespace App\Http\Resources\Admin;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class AdminProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status->value,
            'brand' => $this->whenLoaded('brand', fn () => $this->brand?->only(['id', 'name', 'slug'])),
            'category' => $this->whenLoaded('category', fn () => $this->category?->only(['id', 'name', 'slug'])),
            'short_description' => $this->short_description,
            'description' => $this->description,
            'country_of_origin' => $this->country_of_origin,
            'abv' => $this->abv,
            'flavor_profile' => $this->flavor_profile,
            'tasting_notes' => $this->tasting_notes,
            'serving_suggestions' => $this->serving_suggestions,
            'authenticity_note' => $this->authenticity_note,
            'is_featured' => $this->is_featured,
            'is_age_restricted' => $this->is_age_restricted,
            'is_demo' => $this->is_demo,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'variants' => AdminVariantResource::collection($this->whenLoaded('variants')),
            'images' => $this->whenLoaded('images', fn () => $this->images->map->only(['id', 'url', 'alt', 'sort_order'])),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
