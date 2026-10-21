<?php

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use App\Services\Catalog\ProductImageStore;
use App\Services\Orders\OrderLifecycle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    configureGateways();
    ikejaZone();
});

/** Buys `$variant` as `$user` (a paid order), or leaves the order unpaid. */
function purchase(User $user, ProductVariant $variant, bool $paid = true): Order
{
    $order = Order::firstWhere('reference', checkout(addToCart($variant))->assertCreated()->json('data.reference'));
    $order->forceFill(['user_id' => $user->id])->save();
    if ($paid) {
        app(OrderLifecycle::class)->markPaid($order);
    }

    return $order->refresh();
}

function review(User $as, Product $product, array $over = []): TestResponse
{
    return asStaff($as)->postJson("/api/v1/products/{$product->slug}/reviews", ['rating' => 5, 'title' => 'Superb', 'body' => 'Smooth, balanced and worth every naira.', ...$over]);
}

it('lets only a paying customer review a product', function () {
    $variant = sellableVariant();
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();
    purchase($buyer, $variant);

    review($stranger, $variant->product)->assertForbidden()->assertJsonPath('code', 'review_requires_purchase');
    review($buyer, $variant->product)->assertCreated();
    expect(Review::first())->status->toBe('pending')->order_id->not->toBeNull();
});

it('does not count an unpaid order as a purchase', function () {
    $variant = sellableVariant();
    $user = User::factory()->create();
    purchase($user, $variant, paid: false);

    review($user, $variant->product)->assertForbidden();
});

it('allows one review per customer per product', function () {
    $variant = sellableVariant();
    $user = User::factory()->create();
    purchase($user, $variant);

    review($user, $variant->product)->assertCreated();
    review($user, $variant->product, ['rating' => 1])->assertConflict()->assertJsonPath('code', 'already_reviewed');
});

it('validates rating and text, and needs sign-in', function () {
    $variant = sellableVariant();
    $user = User::factory()->create();
    purchase($user, $variant);

    review($user, $variant->product, ['rating' => 6])->assertJsonValidationErrors('rating');
    review($user, $variant->product, ['rating' => 0])->assertJsonValidationErrors('rating');
    review($user, $variant->product, ['body' => 'short'])->assertJsonValidationErrors('body');
    guest()->postJson("/api/v1/products/{$variant->product->slug}/reviews", ['rating' => 5, 'body' => 'Long enough text here.'])->assertUnauthorized();
});

it('shows only approved reviews, with a short author name and a summary', function () {
    $product = Product::factory()->create();
    Review::factory()->for($product)->for(User::factory()->state(['name' => 'Ada Obi']))->create(['rating' => 5, 'order_id' => null]);
    Review::factory()->for($product)->create(['rating' => 3]);
    Review::factory()->for($product)->pending()->create(['rating' => 1]);
    Review::factory()->for($product)->create(['rating' => 1, 'status' => Review::REJECTED]);

    $res = guest()->getJson("/api/v1/products/{$product->slug}/reviews")->assertOk();

    expect($res->json('summary.count'))->toBe(2)
        ->and($res->json('summary.average'))->toEqual(4.0)
        ->and($res->json('summary.distribution.5'))->toBe(1)
        ->and($res->json('summary.distribution.1'))->toBe(0)
        ->and(collect($res->json('data'))->pluck('author'))->toContain('Ada O.');
    expect(json_encode($res->json()))->not->toContain('@');
});

it('adds the rating to product listings and details from approved reviews only', function () {
    $product = sellableVariant()->product;
    Review::factory()->for($product)->create(['rating' => 4]);
    Review::factory()->for($product)->pending()->create(['rating' => 1]);

    guest()->getJson('/api/v1/products')->assertJsonPath('data.0.rating.count', 1);
    guest()->getJson("/api/v1/products/{$product->slug}")->assertJsonPath('data.rating.count', 1);
});

it('tells a customer whether they can review, and shows their own pending review', function () {
    $variant = sellableVariant();
    $user = User::factory()->create();
    asStaff($user)->getJson("/api/v1/me/products/{$variant->product->slug}/review")->assertJsonPath('data.can_review', false);

    purchase($user, $variant);
    asStaff($user)->getJson("/api/v1/me/products/{$variant->product->slug}/review")->assertJsonPath('data.can_review', true);

    review($user, $variant->product)->assertCreated();
    asStaff($user)->getJson("/api/v1/me/products/{$variant->product->slug}/review")
        ->assertJsonPath('data.can_review', false)->assertJsonPath('data.review.status', 'pending');
});

it('lets catalog staff approve, reject and delete reviews, and records it', function () {
    $review = Review::factory()->pending()->create();
    $staff = orderStaff('product-manager');

    asStaff($staff)->getJson('/api/v1/admin/reviews')->assertOk()->assertJsonCount(1, 'data');
    asStaff($staff)->postJson("/api/v1/admin/reviews/{$review->id}/approve")->assertOk();
    expect($review->refresh())->status->toBe('approved')->moderated_by->toBe($staff->id);

    asStaff($staff)->postJson("/api/v1/admin/reviews/{$review->id}/reject")->assertOk();
    expect($review->refresh()->status)->toBe('rejected');
    guest()->getJson("/api/v1/products/{$review->product->slug}/reviews")->assertJsonPath('summary.count', 0);

    asStaff($staff)->deleteJson("/api/v1/admin/reviews/{$review->id}")->assertNoContent();
    expect(Review::count())->toBe(0)->and(AuditLog::whereIn('action', ['review.approved', 'review.rejected', 'review.deleted'])->count())->toBe(3);
});

it('keeps moderation away from staff without catalog access and from customers', function () {
    $review = Review::factory()->pending()->create();

    asStaff(orderStaff('order-manager'))->postJson("/api/v1/admin/reviews/{$review->id}/approve")->assertForbidden();
    asStaff(User::factory()->create())->getJson('/api/v1/admin/reviews')->assertForbidden();
    guest()->postJson("/api/v1/admin/reviews/{$review->id}/approve")->assertUnauthorized();
    expect($review->refresh()->status)->toBe('pending');
});

describe('photos, replies and taking a review down', function () {
    beforeEach(fn () => Storage::fake('public'));

    function photoReview(User $as, Product $product, int $photos): TestResponse
    {
        app('auth')->forgetGuards();

        return test()->actingAs($as, 'sanctum')->post("/api/v1/products/{$product->slug}/reviews", [
            'rating' => 5, 'body' => 'Smooth, balanced and worth every naira.',
            'photos' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 600, 600), range(1, max(1, $photos))),
        ], ['Accept' => 'application/json']);
    }

    it('stores up to three re-encoded photos with a review and shows them once approved', function () {
        $variant = sellableVariant();
        $user = User::factory()->create();
        purchase($user, $variant);

        photoReview($user, $variant->product, 3)->assertCreated();
        $review = Review::first();
        expect($review->images)->toHaveCount(3);
        foreach ($review->images as $image) {
            Storage::disk('public')->assertExists($image->getRawOriginal('path'));
            expect(getimagesizefromstring(Storage::disk('public')->get($image->getRawOriginal('path')))['mime'])->toBe('image/webp');
        }

        guest()->getJson("/api/v1/products/{$variant->product->slug}/reviews")->assertJsonCount(0, 'data');
        $review->update(['status' => 'approved']);
        guest()->getJson("/api/v1/products/{$variant->product->slug}/reviews")->assertJsonCount(3, 'data.0.photos');
    });

    it('refuses a fourth photo and files that are not images', function () {
        $variant = sellableVariant();
        $user = User::factory()->create();
        purchase($user, $variant);

        photoReview($user, $variant->product, 4)->assertJsonValidationErrors('photos');
        asStaff($user)->post("/api/v1/products/{$variant->product->slug}/reviews", [
            'rating' => 5, 'body' => 'Smooth, balanced and worth every naira.', 'photos' => [UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')],
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('photos.0');
        expect(Review::count())->toBe(0);
    });

    it('lets customers delete their own review (a soft delete that keeps the photo files) and write another', function () {
        $variant = sellableVariant();
        $user = User::factory()->create();
        purchase($user, $variant);
        photoReview($user, $variant->product, 2)->assertCreated();
        $paths = Review::first()->images->map(fn ($i) => $i->getRawOriginal('path'));

        asStaff(User::factory()->create())->deleteJson("/api/v1/me/products/{$variant->product->slug}/review")->assertNoContent();
        expect(Review::count())->toBe(1);

        asStaff($user)->deleteJson("/api/v1/me/products/{$variant->product->slug}/review")->assertNoContent();
        expect(Review::count())->toBe(0)->and(Review::withTrashed()->count())->toBe(1);
        $paths->each(fn ($p) => Storage::disk('public')->assertExists($p));
        guest()->getJson("/api/v1/products/{$variant->product->slug}/reviews")->assertJsonCount(0, 'data');

        // Writing again reuses the deleted row (one review per customer per product) with a clean slate.
        review($user, $variant->product, ['rating' => 2])->assertCreated();
        $again = Review::first();
        expect(Review::withTrashed()->count())->toBe(1)->and($again->rating)->toBe(2)->and($again->status)->toBe('pending')->and($again->images)->toHaveCount(0);
    });

    it('lets staff reply publicly, change the reply and remove it', function () {
        $review = Review::factory()->create();
        $staff = orderStaff('product-manager');

        asStaff($staff)->putJson("/api/v1/admin/reviews/{$review->id}/reply", ['reply' => 'Thank you for the kind words!'])->assertOk();
        guest()->getJson("/api/v1/products/{$review->product->slug}/reviews")->assertJsonPath('data.0.reply.text', 'Thank you for the kind words!');

        asStaff($staff)->putJson("/api/v1/admin/reviews/{$review->id}/reply", ['reply' => 'x'])->assertJsonValidationErrors('reply');
        asStaff($staff)->deleteJson("/api/v1/admin/reviews/{$review->id}/reply")->assertNoContent();
        guest()->getJson("/api/v1/products/{$review->product->slug}/reviews")->assertJsonPath('data.0.reply', null);
        asStaff(orderStaff('order-manager'))->putJson("/api/v1/admin/reviews/{$review->id}/reply", ['reply' => 'Hello there'])->assertForbidden();
    });

    it('soft-deletes reviews with their photos when staff delete a review or its product, keeping the files', function () {
        $variant = sellableVariant();
        $user = User::factory()->create();
        purchase($user, $variant);
        photoReview($user, $variant->product, 2)->assertCreated();
        $review = Review::first();
        $paths = $review->images->map(fn ($i) => $i->getRawOriginal('path'));

        asStaff(orderStaff('product-manager'))->deleteJson('/api/v1/admin/reviews/'.$review->id)->assertNoContent();
        $this->assertSoftDeleted('reviews', ['id' => $review->id]);
        expect(Review::withTrashed()->find($review->id)->images()->withTrashed()->count())->toBe(2);
        $paths->each(fn ($p) => Storage::disk('public')->assertExists($p));

        // Only draft or archived products can be deleted; their reviews and photos go with them (and stay in storage).
        $product = Product::factory()->draft()->create();
        $reviewOfDeleted = Review::factory()->for($product)->create();
        $path = app(ProductImageStore::class)->putFile(UploadedFile::fake()->image('p.jpg', 600, 600), "reviews/{$reviewOfDeleted->id}");
        $reviewOfDeleted->images()->create(['path' => $path, 'sort_order' => 0]);

        asStaff(orderStaff('product-manager'))->deleteJson("/api/v1/admin/products/{$product->id}")->assertNoContent();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertSoftDeleted('reviews', ['id' => $reviewOfDeleted->id]);
        $this->assertSoftDeleted('review_images', ['review_id' => $reviewOfDeleted->id]);
        Storage::disk('public')->assertExists($path);
    });
});
