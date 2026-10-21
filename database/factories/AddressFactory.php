<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Address> */
class AddressFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Home',
            'recipient_name' => fake()->name(),
            'phone' => '+2348031234567',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'address_line' => fake()->streetAddress(),
            'is_default' => false,
        ];
    }
}
