<?php

namespace Database\Factories;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Discount> */
class DiscountFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('TEST-????##')),
            'name' => 'Test discount',
            'type' => DiscountType::Percentage,
            'value' => 10,
            'scope' => DiscountScope::Order,
            'is_active' => true,
        ];
    }

    public function percentage(int $percent, ?int $maxKobo = null): static
    {
        return $this->state(['type' => DiscountType::Percentage, 'value' => $percent, 'max_discount_kobo' => $maxKobo]);
    }

    public function fixed(int $kobo): static
    {
        return $this->state(['type' => DiscountType::Fixed, 'value' => $kobo, 'max_discount_kobo' => null]);
    }

    public function freeDelivery(): static
    {
        return $this->state(['type' => DiscountType::FreeDelivery, 'value' => 0, 'max_discount_kobo' => null]);
    }

    /** @param  iterable<Product>  $products */
    public function forProducts(iterable $products): static
    {
        return $this->state(['scope' => DiscountScope::Products])
            ->afterCreating(fn (Discount $d) => $d->products()->sync(collect($products)->pluck('id')));
    }

    /** @param  iterable<Category>  $categories */
    public function forCategories(iterable $categories): static
    {
        return $this->state(['scope' => DiscountScope::Categories])
            ->afterCreating(fn (Discount $d) => $d->categories()->sync(collect($categories)->pluck('id')));
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function between(?\DateTimeInterface $start, ?\DateTimeInterface $end): static
    {
        return $this->state(['starts_at' => $start, 'ends_at' => $end]);
    }
}
