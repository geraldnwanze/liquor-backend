<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Models\Concerns\CascadesSoftDeletes;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'brand_id', 'category_id', 'name', 'slug', 'status', 'short_description', 'description', 'country_of_origin',
    'abv', 'flavor_profile', 'tasting_notes', 'serving_suggestions', 'authenticity_note', 'is_featured',
    'is_age_restricted', 'is_demo', 'seo_title', 'seo_description',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use CascadesSoftDeletes, HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> Deleted and restored together with the product. */
    protected array $cascadesSoftDeletes = ['variants', 'images', 'reviews', 'wishlistItems'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'abv' => 'decimal:1',
            'flavor_profile' => 'array',
            'is_featured' => 'boolean',
            'is_age_restricted' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    /** @param Builder<Product> $query */
    public function scopePublished(Builder $query): void
    {
        $query->whereIn('status', [ProductStatus::Active, ProductStatus::Discontinued]);
    }

    /** Adds `rating_count` and `rating_avg` from approved reviews only. @param Builder<Product> $query */
    public function scopeWithRating(Builder $query): void
    {
        $approved = fn ($q) => $q->where('status', Review::APPROVED);
        $query->withCount(['reviews as rating_count' => $approved])->withAvg(['reviews as rating_avg' => $approved], 'rating');
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    /** @return HasMany<WishlistItem, $this> */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }
}
