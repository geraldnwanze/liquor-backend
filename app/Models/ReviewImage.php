<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable(['review_id', 'path', 'sort_order'])]
class ReviewImage extends Model
{
    use HasUuids, SoftDeletes;

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** @return Attribute<string, never> */
    protected function url(): Attribute
    {
        return Attribute::get(fn (mixed $value, array $attributes) => Storage::disk(config('filesystems.product_images_disk'))->url($attributes['path']));
    }
}
