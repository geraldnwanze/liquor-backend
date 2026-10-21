<?php

namespace App\Support;

use App\Models\Address;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerNote;
use App\Models\DeliveryZone;
use App\Models\Discount;
use App\Models\NewsletterCampaign;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\ReviewImage;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Model;

/** Every record type that is soft-deleted, by the short name used on the command line. */
final class SoftDeletable
{
    /** @var array<string, class-string<Model>> */
    public const TYPES = [
        'product' => Product::class,
        'variant' => ProductVariant::class,
        'image' => ProductImage::class,
        'brand' => Brand::class,
        'category' => Category::class,
        'zone' => DeliveryZone::class,
        'discount' => Discount::class,
        'page' => Page::class,
        'campaign' => NewsletterCampaign::class,
        'review' => Review::class,
        'review-image' => ReviewImage::class,
        'note' => CustomerNote::class,
        'address' => Address::class,
        'wishlist' => WishlistItem::class,
    ];
}
