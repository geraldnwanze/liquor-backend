<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;

function searchable(array $product): Product
{
    $p = Product::factory()->create($product);
    ProductVariant::factory()->for($p)->create();

    return $p;
}

function searchNames(string $q, array $extra = []): array
{
    return guest()->getJson('/api/v1/products?'.http_build_query(['q' => $q] + $extra))->assertOk()->json('data.*.name');
}

it('finds products by name, brand, category and description', function () {
    $spirits = Category::factory()->create(['name' => 'Spirits']);
    $whiskey = Category::factory()->create(['name' => 'Whiskey', 'parent_id' => $spirits->id]);
    searchable(['name' => 'Glen Reserve', 'brand_id' => Brand::factory()->create(['name' => 'Highland Co'])->id, 'category_id' => $whiskey->id, 'description' => 'A smoky single malt from the Speyside.', 'tasting_notes' => 'Vanilla and oak', 'country_of_origin' => 'Scotland']);
    searchable(['name' => 'Plain Vodka', 'category_id' => Category::factory()->create(['name' => 'Vodka'])->id]);

    expect(searchNames('glen'))->toBe(['Glen Reserve'])
        ->and(searchNames('highland'))->toBe(['Glen Reserve'])     // brand
        ->and(searchNames('whiskey'))->toBe(['Glen Reserve'])      // category
        ->and(searchNames('spirits'))->toBe(['Glen Reserve'])      // parent category
        ->and(searchNames('smoky'))->toBe(['Glen Reserve'])        // description
        ->and(searchNames('vanilla'))->toBe(['Glen Reserve'])      // tasting notes
        ->and(searchNames('scotland'))->toBe(['Glen Reserve'])     // country
        ->and(searchNames('nonexistentword'))->toBe([]);
});

it('needs every word to match somewhere, in any field', function () {
    searchable(['name' => 'Glen Reserve', 'brand_id' => Brand::factory()->create(['name' => 'Highland Co'])->id, 'description' => 'Smoky single malt']);
    searchable(['name' => 'Glen Light', 'description' => 'Fresh and mild']);

    expect(searchNames('glen smoky'))->toBe(['Glen Reserve'])
        ->and(searchNames('highland smoky malt'))->toBe(['Glen Reserve'])
        ->and(searchNames('glen smoky vodka'))->toBe([]);
});

it('puts name matches before brand or category matches before description matches', function () {
    // Created best match first, so plain "newest first" would give the opposite order.
    searchable(['name' => 'Zzz Dark Rum']);
    searchable(['name' => 'Bbb Brand Match', 'brand_id' => Brand::factory()->create(['name' => 'Rum House'])->id]);
    searchable(['name' => 'Aaa Description Only', 'description' => 'Aged in rum barrels']);

    expect(searchNames('rum'))->toBe(['Zzz Dark Rum', 'Bbb Brand Match', 'Aaa Description Only']);
    // An explicit sort wins over relevance.
    expect(searchNames('rum', ['sort' => 'name']))->toBe(['Aaa Description Only', 'Bbb Brand Match', 'Zzz Dark Rum']);
});

it('treats wildcard characters in a search as plain text and ignores unpublished products', function () {
    searchable(['name' => 'Fifty Percent Blend']);
    searchable(['name' => 'Hidden Gem', 'status' => 'draft']);

    expect(searchNames('%'))->toBe([])->and(searchNames('_'))->toBe([])->and(searchNames('gem'))->toBe([]);
});
