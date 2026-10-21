<?php

namespace Database\Seeders;

use App\Models\DeliveryZone;
use Illuminate\Database\Seeder;

/**
 * DEMO DATA ONLY. Areas, fees, thresholds and timings are placeholders to exercise the checkout.
 * The business must replace them with real delivery zones in the admin before launch.
 */
class DemoDeliverySeeder extends Seeder
{
    public function run(): void
    {
        $zones = [
            ['Lagos (demo)', 'Lagos', null, 150_000, 5_000_000, 1, 2],
            ['Abuja (demo)', 'FCT', null, 250_000, 8_000_000, 2, 4],
            ['Ibadan (demo)', 'Oyo', 'Ibadan', 200_000, null, 2, 3],
        ];

        foreach ($zones as [$name, $state, $city, $fee, $free, $min, $max]) {
            DeliveryZone::updateOrCreate(
                ['state' => $state, 'city' => $city],
                ['name' => $name, 'fee_kobo' => $fee, 'free_threshold_kobo' => $free, 'estimated_days_min' => $min, 'estimated_days_max' => $max, 'is_active' => true],
            );
        }
    }
}
