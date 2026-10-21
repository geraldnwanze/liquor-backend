<?php

namespace Database\Seeders;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\Category;
use App\Models\Discount;
use Illuminate\Database\Seeder;

/** Local/testing discount codes only (DatabaseSeeder skips this in production). The codes are listed here. */
class DemoDiscountSeeder extends Seeder
{
    public function run(): void
    {
        Discount::updateOrCreate(['code' => 'WELCOME10'], [
            'name' => 'Demo: 10% off, up to ₦5,000, from ₦10,000', 'type' => DiscountType::Percentage, 'value' => 10,
            'max_discount_kobo' => 500_000, 'min_order_kobo' => 1_000_000, 'scope' => DiscountScope::Order,
            'per_customer_limit' => 1, 'is_active' => true,
        ]);

        Discount::updateOrCreate(['code' => 'FREESHIP'], [
            'name' => 'Demo: free delivery', 'type' => DiscountType::FreeDelivery, 'value' => 0,
            'scope' => DiscountScope::Order, 'is_active' => true,
        ]);

        $spirits = Discount::updateOrCreate(['code' => 'SPIRITS1000'], [
            'name' => 'Demo: ₦1,000 off spirits', 'type' => DiscountType::Fixed, 'value' => 100_000,
            'scope' => DiscountScope::Categories, 'is_active' => true,
        ]);
        $spirits->categories()->sync(Category::query()->where('slug', 'spirits')->pluck('id'));
    }
}
