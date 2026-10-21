<?php

namespace App\Models;

use App\Models\Concerns\CascadesSoftDeletes;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['product_id', 'user_id', 'order_id', 'rating', 'title', 'body', 'reply', 'replied_at', 'replied_by', 'status', 'moderated_by', 'moderated_at'])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use CascadesSoftDeletes, HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> Deleted and restored together with the review. */
    protected array $cascadesSoftDeletes = ['images'];

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rating' => 'integer', 'moderated_at' => 'datetime', 'replied_at' => 'datetime'];
    }

    /** @param Builder<Review> $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', self::APPROVED);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<ReviewImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ReviewImage::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
