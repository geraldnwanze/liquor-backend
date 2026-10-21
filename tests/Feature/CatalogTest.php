<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;

function productWithVariant(array $product = [], array $variant = []): Product
{
    $p = Product::factory()->create($product);
    ProductVariant::factory()->for($p)->create($variant);

    return $p;
}

it('lists only published products with formatted naira prices', function () {
    productWithVariant(['name' => 'Visible Rum', 'slug' => 'visible-rum']);
    productWithVariant(['name' => 'Hidden Draft', 'slug' => 'hidden-draft', 'status' => 'draft']);
    productWithVariant(['name' => 'Old Gin', 'slug' => 'old-gin', 'status' => 'archived']);

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'visible-rum')
        ->assertJsonPath('data.0.price_from.formatted', '₦25,000')
        ->assertJsonPath('data.0.price_from.currency', 'NGN')
        ->assertJsonPath('data.0.variant_count', 1)
        ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'total']]);
});

it('never exposes cost price or raw stock counts', function () {
    $p = productWithVariant(['slug' => 'x']);

    $json = $this->getJson('/api/v1/products/'.$p->slug)->assertOk()->json();

    expect(json_encode($json))->not->toContain('cost_price')->not->toContain('on_hand')->not->toContain('reserved');
});

it('returns 404 for draft products', function () {
    $p = productWithVariant(['status' => 'draft']);

    $this->getJson('/api/v1/products/'.$p->slug)->assertNotFound();
});

it('shows a sale price only when it is lower than the regular price', function () {
    $real = productWithVariant(['slug' => 'real'], ['sale_price_kobo' => 2_000_000]);
    $fake = productWithVariant(['slug' => 'fake'], ['sale_price_kobo' => 3_000_000]);

    $this->getJson('/api/v1/products/real')->assertJsonPath('data.variants.0.sale_price.formatted', '₦20,000');
    $this->getJson('/api/v1/products/fake')->assertJsonPath('data.variants.0.sale_price', null);
});

it('reports out-of-stock variants and honours reservations', function () {
    productWithVariant(['slug' => 'gone'], ['on_hand' => 5, 'reserved' => 5]);

    $this->getJson('/api/v1/products/gone')->assertJsonPath('data.variants.0.in_stock', false);
    $this->getJson('/api/v1/products?in_stock=1')->assertJsonCount(0, 'data');
});

it('filters by category including children, brand, price and search', function () {
    $whiskey = Category::factory()->create(['slug' => 'whiskey']);
    $scotch = Category::factory()->create(['slug' => 'scotch', 'parent_id' => $whiskey->id]);
    $brand = Brand::factory()->create(['slug' => 'glen', 'name' => 'Glen Demo']);
    productWithVariant(['slug' => 'a', 'name' => 'Single Malt', 'category_id' => $scotch->id, 'brand_id' => $brand->id]);
    productWithVariant(['slug' => 'b', 'name' => 'Cheap Vodka'], ['price_kobo' => 500_000]);

    $this->getJson('/api/v1/products?category=whiskey')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products?brand=glen')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products?q=vodka')->assertJsonPath('data.0.slug', 'b');
    $this->getJson('/api/v1/products?max_price=10000')->assertJsonPath('data.0.slug', 'b');
});

it('sorts by price', function () {
    productWithVariant(['slug' => 'pricey'], ['price_kobo' => 9_000_000]);
    productWithVariant(['slug' => 'cheap'], ['price_kobo' => 1_000_000]);

    $this->getJson('/api/v1/products?sort=price_asc')->assertJsonPath('data.0.slug', 'cheap');
    $this->getJson('/api/v1/products?sort=price_desc')->assertJsonPath('data.0.slug', 'pricey');
});

it('rejects invalid query parameters', function () {
    $this->getJson('/api/v1/products?sort=bogus&per_page=500')->assertUnprocessable();
});

it('lists the category tree and returns 404 for unknown or inactive categories', function () {
    $parent = Category::factory()->create(['slug' => 'spirits']);
    Category::factory()->create(['slug' => 'rum', 'parent_id' => $parent->id]);
    Category::factory()->create(['slug' => 'secret', 'is_active' => false]);

    $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.children.0.slug', 'rum');
    $this->getJson('/api/v1/categories/secret')->assertNotFound();
});

it('includes the primary image and does not N+1', function () {
    $p = productWithVariant(['slug' => 'pic']);
    ProductImage::factory()->for($p)->create(['url' => 'https://img.test/a.jpg']);
    productWithVariant();
    productWithVariant();

    DB::enableQueryLog();
    $this->getJson('/api/v1/products')->assertJsonFragment(['url' => 'https://img.test/a.jpg']);

    expect(count(DB::getQueryLog()))->toBeLessThan(10);
});

it('exposes the parent on a category so the storefront can validate nested URLs', function () {
    $parent = Category::factory()->create(['slug' => 'spirits', 'name' => 'Spirits']);
    Category::factory()->create(['slug' => 'whiskey', 'parent_id' => $parent->id]);

    $this->getJson('/api/v1/categories/whiskey')->assertOk()->assertJsonPath('data.parent.slug', 'spirits');
    $this->getJson('/api/v1/categories/spirits')->assertOk()->assertJsonPath('data.children.0.slug', 'whiskey');
});

it('serves the category list from a persistent cache store', function () {
    config(['cache.default' => 'database']);
    $parent = Category::factory()->create(['slug' => 'spirits']);
    Category::factory()->create(['slug' => 'rum', 'parent_id' => $parent->id]);

    $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.children.0.slug', 'rum');
    // Second call is a cache hit: this is where an Eloquent collection used to fail to unserialize.
    $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.children.0.slug', 'rum');
});
