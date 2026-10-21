<?php

namespace Database\Factories;

use App\Models\DeliveryZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeliveryZone> */
class DeliveryZoneFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Demo zone',
            'state' => 'Lagos',
            'city' => fake()->unique()->city(),
            'fee_kobo' => 150_000,
            'free_threshold_kobo' => null,
            'estimated_days_min' => 1,
            'estimated_days_max' => 2,
            'is_active' => true,
        ];
    }

    public function statewide(string $state = 'Lagos'): static
    {
        return $this->state(['state' => $state, 'city' => null]);
    }
}
