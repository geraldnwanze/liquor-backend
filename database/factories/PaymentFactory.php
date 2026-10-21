<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Pass `order_id`: orders are created through checkout (there is no Order factory).
            'gateway' => 'paystack',
            'reference' => 'LQP'.Str::upper(Str::random(16)),
            'amount_kobo' => 2_000_000,
            'currency' => 'NGN',
        ];
    }
}
