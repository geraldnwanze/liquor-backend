<?php

/*
 | Defaults for commerce rules. Each can be overridden at runtime from the `settings` table (group "commerce"),
 | so staff can change them without a deploy. See App\Services\Commerce\CommerceSettings.
 | These are placeholders, not legal advice: minimum age, limits and delivery rules must be confirmed with
 | qualified local counsel before launch.
 */
return [
    'minimum_age' => 18,
    'reservation_minutes' => 30,
    'max_quantity_per_line' => 12,
    'max_units_per_order' => 36,
    'guest_cart_retention_days' => 60,
];
