<?php

namespace App\Http\Resources\Catalog;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'is_featured' => $this->is_featured,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent?->only(['name', 'slug'])),
            'children' => self::collection($this->whenLoaded('children', fn () => $this->children->where('is_active', true)->values())),
            'seo' => ['title' => $this->seo_title ?: $this->name, 'description' => $this->seo_description ?: $this->description],
        ];
    }
}
