<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * DEMO DATA ONLY. Brands, names, prices and stock are fictional placeholders and
 * must be replaced with verified business data before launch.
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Two-level tree so URLs like /spirits/whiskey exist: [parent => children]. Champagne is its own root.
        $tree = [
            'Spirits' => ['Whiskey', 'Rum', 'Tequila', 'Gin', 'Vodka', 'Cognac'],
            'Wine' => ['Red Wine', 'White Wine'],
            'Champagne' => [],
        ];

        $categories = collect();
        $order = 0;
        foreach ($tree as $parentName => $children) {
            $parent = Category::updateOrCreate(
                ['slug' => Str::slug($parentName)],
                ['name' => $parentName, 'parent_id' => null, 'sort_order' => $order++, 'is_featured' => true],
            );
            $categories[$parentName] = $parent;
            foreach ($children as $i => $childName) {
                $categories[$childName] = Category::updateOrCreate(
                    ['slug' => Str::slug($childName)],
                    ['name' => $childName, 'parent_id' => $parent->id, 'sort_order' => $i],
                );
            }
        }

        $brand = Brand::updateOrCreate(['slug' => 'demo-house'], ['name' => 'Demo House', 'description' => 'Placeholder brand for demo data.']);

        // [name, category, volumes => [label, ml, price in NGN]]
        $items = [
            ['Demo Single Malt', 'Whiskey', [['350 ml', 350, 18_000], ['700 ml', 700, 32_000], ['1 litre', 1000, 44_000]]],
            ['Demo Blended Scotch', 'Whiskey', [['700 ml', 700, 21_000]]],
            ['Demo Red Reserve', 'Red Wine', [['750 ml', 750, 12_500]]],
            ['Demo Dark Rum', 'Rum', [['700 ml', 700, 15_000]]],
            ['Demo Blanco Tequila', 'Tequila', [['700 ml', 700, 24_000]]],
            ['Demo London Dry Gin', 'Gin', [['700 ml', 700, 17_500]]],
            ['Demo Premium Vodka', 'Vodka', [['700 ml', 700, 14_000]]],
            ['Demo White Reserve', 'White Wine', [['750 ml', 750, 11_500]]],
            ['Demo Brut Champagne', 'Champagne', [['750 ml', 750, 58_000]]],
        ];

        foreach ($items as [$name, $category, $volumes]) {
            $product = Product::updateOrCreate(['slug' => Str::slug($name)], [
                'name' => $name,
                'brand_id' => $brand->id,
                'category_id' => $categories[$category]->id,
                'status' => ProductStatus::Active,
                'short_description' => 'Demo product. Specifications to be verified by the business.',
                'is_demo' => true,
            ]);

            foreach ($volumes as $order => [$label, $ml, $naira]) {
                ProductVariant::updateOrCreate(['sku' => 'DEMO-'.strtoupper(Str::slug($name)).'-'.$ml], [
                    'product_id' => $product->id,
                    'name' => $label,
                    'volume_ml' => $ml,
                    'price_kobo' => $naira * 100,
                    'sort_order' => $order,
                ])->forceFill(['on_hand' => 25])->save();
            }
        }
    }
}
