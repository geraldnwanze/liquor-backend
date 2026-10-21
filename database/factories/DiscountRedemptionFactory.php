<?php

namespace Database\Factories;

use App\Enums\RedemptionState;
use App\Models\DiscountRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiscountRedemption> */
class DiscountRedemptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'amount_kobo' => 0,
            'delivery_waived_kobo' => 0,
            'state' => RedemptionState::Redeemed,
        ];
    }
}
