<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-????-####')),
            'name' => '700 ml',
            'volume_ml' => 700,
            'price_kobo' => 2_500_000,
            'cost_price_kobo' => 1_800_000,
            'on_hand' => 10,
            'reserved' => 0,
            'low_stock_threshold' => 3,
            'is_active' => true,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(['on_hand' => 0]);
    }
}
