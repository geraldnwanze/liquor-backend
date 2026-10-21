<?php

namespace App\Services\Catalog;

use App\Models\Review;
use Illuminate\Http\UploadedFile;

/** Photos attached to a review: re-encoded like product photos. They stay in storage when the review is soft-deleted. */
class ReviewPhotos
{
    public const MAX = 3;

    public function __construct(private ProductImageStore $store) {}

    /** @param array<int, UploadedFile> $files */
    public function attach(Review $review, array $files): void
    {
        foreach (array_slice($files, 0, self::MAX) as $i => $file) {
            $review->images()->create(['path' => $this->store->putFile($file, "reviews/{$review->id}"), 'sort_order' => $i]);
        }
    }
}
