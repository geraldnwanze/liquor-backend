<?php

use App\Enums\ProductStatus;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\CartItem;
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
use App\Models\User;
use App\Models\WishlistItem;
use App\Support\SoftDeletable;
use App\Support\UniqueSlug;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

it('gives every deletable record type a deleted_at column and the SoftDeletes trait', function () {
    foreach (SoftDeletable::TYPES as $type => $model) {
        expect(in_array(SoftDeletes::class, class_uses_recursive($model), true))->toBeTrue("{$type} should use SoftDeletes")
            ->and(Schema::hasColumn((new $model)->getTable(), 'deleted_at'))->toBeTrue("{$type} should have deleted_at");
    }
});

it('never hard-deletes a business record from application code', function () {
    $offenders = collect(File::allFiles(app_path()))->filter(fn ($f) => str_contains($f->getContents(), 'forceDelete'))->map->getRelativePathname()->all();

    expect($offenders)->toBe([]);
});

/** One deletable record per endpoint, as a closure returning [model class, record, staff role, delete URL]. */
dataset('delete endpoints', [
    'brand' => [fn () => [Brand::class, Brand::factory()->create(), 'product-manager', fn ($r) => "/api/v1/admin/brands/{$r->id}"]],
    'category' => [fn () => [Category::class, Category::factory()->create(), 'product-manager', fn ($r) => "/api/v1/admin/categories/{$r->id}"]],
    'delivery zone' => [fn () => [DeliveryZone::class, DeliveryZone::factory()->create(), 'order-manager', fn ($r) => "/api/v1/admin/delivery-zones/{$r->id}"]],
    'discount' => [fn () => [Discount::class, Discount::factory()->create(), 'marketing-manager', fn ($r) => "/api/v1/admin/discounts/{$r->id}"]],
    'page' => [fn () => [Page::class, Page::factory()->create(), 'content-editor', fn ($r) => "/api/v1/admin/pages/{$r->id}"]],
    'campaign' => [fn () => [NewsletterCampaign::class, NewsletterCampaign::factory()->create(), 'marketing-manager', fn ($r) => "/api/v1/admin/newsletter/campaigns/{$r->id}"]],
    'product' => [fn () => [Product::class, Product::factory()->draft()->create(), 'product-manager', fn ($r) => "/api/v1/admin/products/{$r->id}"]],
    'variant' => [fn () => [ProductVariant::class, ProductVariant::factory()->create(['on_hand' => 0, 'reserved' => 0]), 'product-manager', fn ($r) => "/api/v1/admin/variants/{$r->id}"]],
    'image' => [fn () => [ProductImage::class, ProductImage::factory()->create(), 'product-manager', fn ($r) => "/api/v1/admin/images/{$r->id}"]],
    'review' => [fn () => [Review::class, Review::factory()->create(), 'product-manager', fn ($r) => "/api/v1/admin/reviews/{$r->id}"]],
]);

it('only hides the record when staff delete it', function (Closure $setup) {
    [$model, $record, $role, $url] = $setup();

    asStaff(orderStaff($role))->deleteJson($url($record))->assertSuccessful();

    $this->assertSoftDeleted($record->getTable(), ['id' => $record->id]);
    expect($model::find($record->id))->toBeNull()->and($model::withTrashed()->find($record->id))->not->toBeNull();
})->with('delete endpoints');

it('soft-deletes a customer\'s saved address and a support note', function () {
    $customer = User::factory()->create();
    $address = Address::factory()->for($customer)->create();
    asStaff($customer)->deleteJson("/api/v1/me/addresses/{$address->id}")->assertSuccessful();
    $this->assertSoftDeleted('addresses', ['id' => $address->id]);

    $note = CustomerNote::create(['customer_id' => $customer->id, 'author_id' => $customer->id, 'body' => 'Prefers evening delivery']);
    asStaff(orderStaff('customer-support'))->deleteJson("/api/v1/admin/customers/{$customer->id}/notes/{$note->id}")->assertSuccessful();
    $this->assertSoftDeleted('customer_notes', ['id' => $note->id]);
});

it('soft-deletes a wishlist item and brings it back when the product is saved again', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    asStaff($user)->putJson("/api/v1/me/wishlist/{$product->slug}")->assertCreated();
    asStaff($user)->deleteJson("/api/v1/me/wishlist/{$product->slug}")->assertNoContent();
    $this->assertSoftDeleted('wishlist_items', ['user_id' => $user->id, 'product_id' => $product->id]);
    asStaff($user)->getJson('/api/v1/me/wishlist/slugs')->assertExactJson(['data' => []]);

    asStaff($user)->putJson("/api/v1/me/wishlist/{$product->slug}")->assertCreated();
    expect(WishlistItem::withTrashed()->count())->toBe(1)->and(WishlistItem::count())->toBe(1);
});

it('takes a product\'s variants, photos, reviews and wishlist entries with it, and restores them together', function () {
    $product = Product::factory()->draft()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    $image = ProductImage::factory()->create(['product_id' => $product->id]);
    $review = Review::factory()->for($product)->create();
    $saved = WishlistItem::create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id]);
    $earlier = ProductVariant::factory()->create(['product_id' => $product->id, 'deleted_at' => now()->subDay()]);

    asStaff(orderStaff('product-manager'))->deleteJson("/api/v1/admin/products/{$product->id}")->assertNoContent();

    foreach ([[$product, 'products'], [$variant, 'product_variants'], [$image, 'product_images'], [$review, 'reviews'], [$saved, 'wishlist_items']] as [$record, $table]) {
        $this->assertSoftDeleted($table, ['id' => $record->id]);
    }

    Product::withTrashed()->find($product->id)->restore();

    expect(Product::find($product->id))->not->toBeNull()
        ->and(ProductVariant::find($variant->id))->not->toBeNull()
        ->and(ProductImage::find($image->id))->not->toBeNull()
        ->and(Review::find($review->id))->not->toBeNull()
        ->and(WishlistItem::find($saved->id))->not->toBeNull()
        ->and(ProductVariant::find($earlier->id))->toBeNull(); // deleted a day before, so it stays deleted
});

it('drops a deleted variant from carts, and a deleted product from the shop', function () {
    $variant = sellableVariant(['on_hand' => 0]);
    $token = addToCart(sellableVariant(['on_hand' => 9]));
    CartItem::create(['cart_id' => Cart::firstWhere('token', $token)->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'price_when_added_kobo' => 1]);
    expect(CartItem::where('product_variant_id', $variant->id)->count())->toBe(1);

    asStaff(orderStaff('product-manager'))->deleteJson("/api/v1/admin/variants/{$variant->id}")->assertNoContent();
    expect(CartItem::where('product_variant_id', $variant->id)->count())->toBe(0);
    guest($token)->getJson('/api/v1/cart')->assertOk();

    $product = Product::factory()->create(['status' => ProductStatus::Draft]);
    asStaff(orderStaff('product-manager'))->deleteJson("/api/v1/admin/products/{$product->id}")->assertNoContent();
    guest()->getJson("/api/v1/products/{$product->slug}")->assertNotFound();
});

it('keeps a deleted record\'s slug, SKU and code taken so restoring can never clash', function () {
    $product = Product::factory()->draft()->create(['slug' => 'old-rum']);
    $variant = ProductVariant::factory()->create(['sku' => 'RUM-1', 'on_hand' => 0, 'reserved' => 0]);
    $discount = Discount::factory()->create(['code' => 'OLDCODE']);
    $staff = orderStaff('marketing-manager');
    asStaff(orderStaff('product-manager'))->deleteJson("/api/v1/admin/products/{$product->id}")->assertNoContent();
    asStaff(orderStaff('product-manager'))->deleteJson("/api/v1/admin/variants/{$variant->id}")->assertNoContent();
    asStaff($staff)->deleteJson("/api/v1/admin/discounts/{$discount->id}")->assertNoContent();

    expect(UniqueSlug::make(Product::class, 'Old Rum'))->toBe('old-rum-2');
    asStaff(orderStaff('product-manager'))->postJson("/api/v1/admin/products/{$product->id}/variants", [])->assertNotFound();
    expect(Discount::withTrashed()->where('code', 'OLDCODE')->count())->toBe(1);
    asStaff($staff)->postJson('/api/v1/admin/discounts', ['code' => 'OLDCODE', 'name' => 'Again', 'type' => 'percentage', 'percent' => 10, 'scope' => 'order', 'is_active' => true])->assertJsonValidationErrors('code');
});

it('keeps deleting audited', function () {
    $page = Page::factory()->create();

    asStaff(orderStaff('content-editor'))->deleteJson("/api/v1/admin/pages/{$page->id}")->assertNoContent();

    expect(AuditLog::where('action', 'page.deleted')->where('subject_id', $page->id)->count())->toBe(1);
});

it('lists and restores deleted records from the command line', function () {
    $page = Page::factory()->create(['title' => 'Delivery info']);
    $page->delete();

    $this->artisan('trash:list', ['type' => 'page'])->expectsOutputToContain('Delivery info')->assertSuccessful();
    $this->artisan('trash:restore', ['type' => 'page', 'id' => $page->id])->expectsOutput('Restored.')->assertSuccessful();
    expect(Page::find($page->id))->not->toBeNull();

    $this->artisan('trash:restore', ['type' => 'page', 'id' => $page->id])->assertFailed();
    $this->artisan('trash:list', ['type' => 'nonsense'])->assertFailed();
    $this->artisan('trash:list')->expectsOutput('Nothing has been deleted.')->assertSuccessful();
});

it('leaves staff and credentials alone: tokens are really revoked, and nobody is deleted', function () {
    $staff = orderStaff('order-manager');
    $staff->forceFill(['password' => 'Staff-Pass-12345'])->save();
    $token = guest()->postJson('/api/v1/auth/staff/login', ['email' => $staff->email, 'password' => 'Staff-Pass-12345'])->json('data.token');

    guest()->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($staff->tokens()->count())->toBe(0)->and(Schema::hasColumn('users', 'deleted_at'))->toBeFalse();
});

it('refuses to recreate a deleted delivery zone for the same place instead of failing on the database', function () {
    $zone = DeliveryZone::factory()->create(['state' => 'Oyo', 'city' => 'Ibadan']);
    $staff = orderStaff('order-manager');
    asStaff($staff)->deleteJson("/api/v1/admin/delivery-zones/{$zone->id}")->assertNoContent();

    asStaff($staff)->postJson('/api/v1/admin/delivery-zones', ['name' => 'Ibadan again', 'state' => 'Oyo', 'city' => 'Ibadan', 'fee_kobo' => 100_000])
        ->assertUnprocessable()->assertJsonValidationErrors('city');
});
