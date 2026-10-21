<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

function staff(string $role): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('slug', $role)->first());

    return $user;
}

it('blocks anonymous users, customers and wrongly-roled staff from admin routes', function () {
    $this->getJson('/api/v1/admin/products')->assertUnauthorized();

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/admin/products')->assertForbidden();
    $this->actingAs(staff('content-editor'), 'sanctum')->postJson('/api/v1/admin/products', ['name' => 'X'])->assertForbidden();
    // Product managers can manage the catalog and stock; order managers can do neither.
    $this->actingAs(staff('order-manager'), 'sanctum')->getJson('/api/v1/admin/inventory/low-stock')->assertForbidden();
});

it('creates a draft product with an auto-generated unique slug and audits it', function () {
    $admin = staff('product-manager');
    $this->actingAs($admin, 'sanctum');

    $this->postJson('/api/v1/admin/products', ['name' => 'Single Malt <b>12</b>'])
        ->assertCreated()->assertJsonPath('data.slug', 'single-malt-12')->assertJsonPath('data.status', 'draft');
    $this->postJson('/api/v1/admin/products', ['name' => 'Single Malt 12'])
        ->assertCreated()->assertJsonPath('data.slug', 'single-malt-12-2');

    $log = AuditLog::where('action', 'product.created')->first();
    expect($log->user_id)->toBe($admin->id)->and($log->subject_type)->toBe((new Product)->getMorphClass());
});

it('strips HTML from descriptive text and validates fields', function () {
    $this->actingAs(staff('product-manager'), 'sanctum');

    $this->postJson('/api/v1/admin/products', ['name' => 'A', 'tasting_notes' => '<script>alert(1)</script>Smooth', 'abv' => 40.5])
        ->assertCreated()->assertJsonPath('data.tasting_notes', 'alert(1)Smooth');
    $this->postJson('/api/v1/admin/products', ['name' => 'B', 'abv' => 140, 'brand_id' => 999, 'status' => 'bogus'])
        ->assertUnprocessable()->assertJsonValidationErrors(['abv', 'brand_id', 'status']);
});

it('only publishes products that have an active variant', function () {
    $this->actingAs(staff('product-manager'), 'sanctum');
    $product = Product::factory()->draft()->create();

    $this->patchJson("/api/v1/admin/products/{$product->id}", ['status' => 'active'])
        ->assertUnprocessable()->assertJsonPath('code', 'product_not_publishable');

    ProductVariant::factory()->for($product)->create();
    $this->patchJson("/api/v1/admin/products/{$product->id}", ['status' => 'active'])->assertOk();
    $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
});

it('hides cost price from staff without the cost permission and ignores their writes', function () {
    $product = Product::factory()->create();

    $this->actingAs(staff('product-manager'), 'sanctum');
    $res = $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
        'sku' => 'SKU-1', 'name' => '700 ml', 'price_kobo' => 1_000_000, 'cost_price_kobo' => 700_000,
    ])->assertCreated();
    expect($res->json('data'))->not->toHaveKey('cost_price_kobo');
    $this->assertDatabaseHas('product_variants', ['sku' => 'SKU-1', 'cost_price_kobo' => null]);

    $this->actingAs(staff('store-manager'), 'sanctum');
    $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
        'sku' => 'SKU-2', 'name' => '1 L', 'price_kobo' => 1_000_000, 'cost_price_kobo' => 700_000,
    ])->assertCreated()->assertJsonPath('data.cost_price_kobo', 700_000);
});

it('validates variant pricing and unique SKUs', function () {
    $this->actingAs(staff('product-manager'), 'sanctum');
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->create(['sku' => 'TAKEN']);

    $this->postJson("/api/v1/admin/products/{$product->id}/variants", ['sku' => 'TAKEN', 'name' => 'x', 'price_kobo' => 100])
        ->assertUnprocessable()->assertJsonValidationErrors('sku');
    $this->postJson("/api/v1/admin/products/{$product->id}/variants", ['sku' => 'N', 'name' => 'x', 'price_kobo' => 100, 'sale_price_kobo' => 100])
        ->assertUnprocessable()->assertJsonValidationErrors('sale_price_kobo');
    $this->postJson("/api/v1/admin/products/{$product->id}/variants", ['sku' => 'N', 'name' => 'x', 'price_kobo' => 100.5])
        ->assertUnprocessable()->assertJsonValidationErrors('price_kobo');
});

it('records initial stock in the ledger and blocks setting stock through variant updates', function () {
    $admin = staff('product-manager');
    $this->actingAs($admin, 'sanctum');
    $product = Product::factory()->create();

    $id = $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
        'sku' => 'S1', 'name' => '700 ml', 'price_kobo' => 500_000, 'initial_stock' => 12,
    ])->assertCreated()->assertJsonPath('data.on_hand', 12)->json('data.id');

    $this->assertDatabaseHas('inventory_movements', ['product_variant_id' => $id, 'delta' => 12, 'reason' => 'initial', 'user_id' => $admin->id]);

    $this->patchJson("/api/v1/admin/variants/{$id}", ['on_hand' => 999, 'name' => 'Renamed'])->assertOk();
    $this->assertDatabaseHas('product_variants', ['id' => $id, 'on_hand' => 12, 'name' => 'Renamed']);
    $this->patchJson("/api/v1/admin/variants/{$id}", ['initial_stock' => 5])->assertUnprocessable();
});

it('adjusts stock with a mandatory reason and refuses to go below reserved', function () {
    $admin = staff('product-manager');
    $this->actingAs($admin, 'sanctum');
    $variant = ProductVariant::factory()->create(['on_hand' => 10, 'reserved' => 6]);

    $this->postJson("/api/v1/admin/variants/{$variant->id}/stock", ['delta' => 5, 'reason' => 'received', 'note' => 'PO-9'])
        ->assertOk()->assertJsonPath('data.variant.on_hand', 15)->assertJsonPath('data.movement.on_hand_after', 15);
    $this->postJson("/api/v1/admin/variants/{$variant->id}/stock", ['delta' => -10, 'reason' => 'damaged'])
        ->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock');
    $this->postJson("/api/v1/admin/variants/{$variant->id}/stock", ['delta' => 0, 'reason' => 'received'])->assertUnprocessable();
    $this->postJson("/api/v1/admin/variants/{$variant->id}/stock", ['delta' => 1, 'reason' => 'sale'])->assertUnprocessable();
    $this->postJson("/api/v1/admin/variants/{$variant->id}/stock", ['delta' => 1])->assertUnprocessable();

    $this->getJson("/api/v1/admin/variants/{$variant->id}/movements")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.by.id', $admin->id);
    expect(AuditLog::where('action', 'stock.adjusted')->count())->toBe(1);
});

it('lists low-stock variants', function () {
    $this->actingAs(staff('product-manager'), 'sanctum');
    ProductVariant::factory()->create(['sku' => 'LOW', 'on_hand' => 2, 'low_stock_threshold' => 3]);
    ProductVariant::factory()->create(['sku' => 'FINE', 'on_hand' => 50, 'low_stock_threshold' => 3]);

    $this->getJson('/api/v1/admin/inventory/low-stock')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'LOW');
});

it('manages images, accepting only https URLs', function () {
    $this->actingAs(staff('product-manager'), 'sanctum');
    $product = Product::factory()->create();

    $this->postJson("/api/v1/admin/products/{$product->id}/images", ['url' => 'http://insecure.test/a.jpg'])->assertUnprocessable();
    $this->postJson("/api/v1/admin/products/{$product->id}/images", ['url' => 'javascript:alert(1)'])->assertUnprocessable();
    $id = $this->postJson("/api/v1/admin/products/{$product->id}/images", ['url' => 'https://cdn.test/a.jpg', 'alt' => 'Front'])
        ->assertCreated()->json('data.id');
    $this->deleteJson("/api/v1/admin/images/{$id}")->assertNoContent();
    $this->assertSoftDeleted('product_images', ['id' => $id]);
});

it('archives, protects live products from deletion, and deletes drafts', function () {
    $this->actingAs(staff('product-manager'), 'sanctum');
    $live = Product::factory()->create();

    $this->deleteJson("/api/v1/admin/products/{$live->id}")->assertConflict()->assertJsonPath('code', 'product_not_deletable');
    $this->postJson("/api/v1/admin/products/{$live->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
    $this->getJson('/api/v1/products/'.$live->slug)->assertNotFound();
    $this->deleteJson("/api/v1/admin/products/{$live->id}")->assertNoContent();
    expect(AuditLog::whereIn('action', ['product.archived', 'product.deleted'])->count())->toBe(2);
});

it('duplicates a product as a draft with new SKUs and zero stock', function () {
    $this->actingAs(staff('product-manager'), 'sanctum');
    $product = Product::factory()->create(['name' => 'Rum', 'slug' => 'rum', 'is_featured' => true]);
    ProductVariant::factory()->for($product)->create(['sku' => 'RUM-1', 'on_hand' => 30]);

    $this->postJson("/api/v1/admin/products/{$product->id}/duplicate")
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.slug', 'rum-copy')
        ->assertJsonPath('data.is_featured', false)->assertJsonPath('data.variants.0.sku', 'RUM-1-COPY')
        ->assertJsonPath('data.variants.0.on_hand', 0);
});

it('manages categories, preventing cycles and deletion of non-empty ones', function () {
    $this->actingAs(staff('product-manager'), 'sanctum');

    $parentId = $this->postJson('/api/v1/admin/categories', ['name' => 'Spirits'])->assertCreated()->json('data.id');
    $childId = $this->postJson('/api/v1/admin/categories', ['name' => 'Rum', 'parent_id' => $parentId])->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/admin/categories/{$parentId}", ['parent_id' => $childId])
        ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    $this->patchJson("/api/v1/admin/categories/{$parentId}", ['parent_id' => $parentId])->assertUnprocessable();
    $this->deleteJson("/api/v1/admin/categories/{$parentId}")->assertConflict()->assertJsonPath('code', 'category_not_empty');

    Product::factory()->create(['category_id' => $childId]);
    $this->deleteJson("/api/v1/admin/categories/{$childId}")->assertConflict();
    $leaf = Category::factory()->create();
    $this->deleteJson("/api/v1/admin/categories/{$leaf->id}")->assertNoContent();
});

it('manages brands and refuses to delete brands that have products', function () {
    $this->actingAs(staff('product-manager'), 'sanctum');

    $id = $this->postJson('/api/v1/admin/brands', ['name' => 'Demo Brand', 'logo_url' => 'https://cdn.test/l.png'])
        ->assertCreated()->assertJsonPath('data.slug', 'demo-brand')->json('data.id');
    $this->patchJson("/api/v1/admin/brands/{$id}", ['is_featured' => true])->assertOk();
    Product::factory()->create(['brand_id' => $id]);
    $this->deleteJson("/api/v1/admin/brands/{$id}")->assertConflict();
    $this->postJson('/api/v1/admin/brands', ['name' => 'Bad', 'logo_url' => 'http://x.test/l.png'])->assertUnprocessable();
    $empty = Brand::factory()->create();
    $this->deleteJson("/api/v1/admin/brands/{$empty->id}")->assertNoContent();
});

it('invalidates the public category cache when admins change categories', function () {
    $this->actingAs(staff('product-manager'), 'sanctum');

    $this->getJson('/api/v1/categories')->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/admin/categories', ['name' => 'Gin'])->assertCreated();
    $this->getJson('/api/v1/categories')->assertJsonCount(1, 'data');

    $id = Category::first()->id;
    $this->patchJson("/api/v1/admin/categories/{$id}", ['is_active' => false]);
    $this->getJson('/api/v1/categories')->assertJsonCount(0, 'data');
});

it('reflects price edits on the public API immediately', function () {
    $this->actingAs(staff('product-manager'), 'sanctum');
    $product = Product::factory()->create(['slug' => 'live']);
    $variant = ProductVariant::factory()->for($product)->create(['price_kobo' => 1_000_000]);

    $this->getJson('/api/v1/products/live')->assertJsonPath('data.variants.0.price.formatted', '₦10,000');
    $this->patchJson("/api/v1/admin/variants/{$variant->id}", ['price_kobo' => 1_250_000])->assertOk();
    $this->getJson('/api/v1/products/live')->assertJsonPath('data.variants.0.price.formatted', '₦12,500');
});
