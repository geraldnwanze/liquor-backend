<?php

use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Testing\TestResponse;

function trash(string $role = 'store-manager'): TestResponse
{
    return asStaff(orderStaff($role))->getJson('/api/v1/admin/trash')->assertOk();
}

it('lists what was deleted with who deleted it, newest first', function () {
    $staff = orderStaff('store-manager');
    $old = Page::factory()->create(['title' => 'Old page']);
    $new = Brand::factory()->create(['name' => 'Gone Brand']);
    asStaff($staff)->deleteJson("/api/v1/admin/pages/{$old->id}")->assertNoContent();
    $this->travel(5)->minutes();
    asStaff($staff)->deleteJson("/api/v1/admin/brands/{$new->id}")->assertNoContent();

    $res = asStaff($staff)->getJson('/api/v1/admin/trash')->assertOk();

    expect($res->json('data.*.name'))->toBe(['Gone Brand', 'Old page (/info/'.$old->slug.')'])
        ->and($res->json('data.0.type'))->toBe('brand')
        ->and($res->json('data.0.deleted_by'))->toBe($staff->name)
        ->and($res->json('types.*.type'))->toContain('product', 'page', 'discount', 'review', 'note');
});

it('shows each role only the kinds of record it manages', function () {
    $page = Page::factory()->create();
    $brand = Brand::factory()->create();
    $page->delete();
    $brand->delete();

    expect(trash('content-editor')->json('data.*.type'))->toBe(['page'])
        ->and(trash('product-manager')->json('data.*.type'))->toBe(['brand'])
        ->and(trash('marketing-manager')->json('data.*.type'))->toBe(['page']);
    asStaff(orderStaff('customer-support'))->getJson('/api/v1/admin/trash')->assertOk()->assertJsonCount(0, 'data');
    asStaff(User::factory()->create())->getJson('/api/v1/admin/trash')->assertForbidden();
    guest()->getJson('/api/v1/admin/trash')->assertUnauthorized();
});

it('restores a record, audits it, and refuses types the role may not touch', function () {
    $page = Page::factory()->create();
    $page->delete();

    asStaff(orderStaff('product-manager'))->postJson("/api/v1/admin/trash/page/{$page->id}/restore")->assertNotFound();
    expect(Page::find($page->id))->toBeNull();

    asStaff(orderStaff('content-editor'))->postJson("/api/v1/admin/trash/page/{$page->id}/restore")->assertOk();
    expect(Page::find($page->id))->not->toBeNull()->and(AuditLog::where('action', 'trash.restored')->where('subject_id', $page->id)->count())->toBe(1);
    asStaff(orderStaff('content-editor'))->postJson("/api/v1/admin/trash/page/{$page->id}/restore")->assertNotFound();
    asStaff(orderStaff('store-manager'))->postJson('/api/v1/admin/trash/nonsense/'.$page->id.'/restore')->assertNotFound();
});

it('brings a product back with the variants, photos and reviews deleted with it', function () {
    $product = Product::factory()->draft()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    $review = Review::factory()->for($product)->create();
    $staff = orderStaff('store-manager');
    asStaff($staff)->deleteJson("/api/v1/admin/products/{$product->id}")->assertNoContent();

    asStaff($staff)->postJson("/api/v1/admin/trash/product/{$product->id}/restore")->assertOk();

    expect(Product::find($product->id))->not->toBeNull()->and(ProductVariant::find($variant->id))->not->toBeNull()->and(Review::find($review->id))->not->toBeNull();
});

it('makes a child wait for its deleted parent', function () {
    $product = Product::factory()->draft()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
    $staff = orderStaff('store-manager');
    asStaff($staff)->deleteJson("/api/v1/admin/products/{$product->id}")->assertNoContent();

    expect(trash()->json('data.*.blocked_by'))->toContain('product');
    asStaff($staff)->postJson("/api/v1/admin/trash/variant/{$variant->id}/restore")->assertConflict()->assertJsonPath('code', 'parent_deleted');

    $brand = Brand::factory()->create();
    $withBrand = Product::factory()->draft()->create(['brand_id' => $brand->id]);
    $withBrand->delete();
    $brand->delete();
    asStaff($staff)->postJson("/api/v1/admin/trash/product/{$withBrand->id}/restore")->assertConflict();
    asStaff($staff)->postJson("/api/v1/admin/trash/brand/{$brand->id}/restore")->assertOk();
    asStaff($staff)->postJson("/api/v1/admin/trash/product/{$withBrand->id}/restore")->assertOk();

    $category = Category::factory()->create();
    $inCategory = Product::factory()->draft()->create(['category_id' => $category->id]);
    $inCategory->delete();
    $category->delete();
    asStaff($staff)->postJson("/api/v1/admin/trash/product/{$inCategory->id}/restore")->assertConflict();

    $child = Category::factory()->create(['parent_id' => ($parent = Category::factory()->create())->id]);
    $child->delete();
    $parent->delete();
    asStaff($staff)->postJson("/api/v1/admin/trash/category/{$child->id}/restore")->assertConflict();
});

it('never lists customers\' personal addresses or wishlists', function () {
    $user = User::factory()->create();
    Address::factory()->for($user)->create()->delete();
    WishlistItem::create(['user_id' => $user->id, 'product_id' => Product::factory()->create()->id])->delete();

    expect(trash()->json('data'))->toBe([]);
});
