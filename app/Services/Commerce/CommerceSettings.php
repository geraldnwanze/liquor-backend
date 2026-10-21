<?php

namespace App\Services\Commerce;

use App\Services\Administration\SettingsRepository;

/** Commerce rules: DB settings (editable by staff) override config/commerce.php defaults. */
class CommerceSettings
{
    public function __construct(private SettingsRepository $settings) {}

    public function minimumAge(): int
    {
        return (int) $this->settings->get('commerce', 'minimum_age', config('commerce.minimum_age'));
    }

    public function reservationMinutes(): int
    {
        return max(1, (int) $this->settings->get('commerce', 'reservation_minutes', config('commerce.reservation_minutes')));
    }

    public function maxQuantityPerLine(): int
    {
        return max(1, (int) $this->settings->get('commerce', 'max_quantity_per_line', config('commerce.max_quantity_per_line')));
    }

    public function maxUnitsPerOrder(): int
    {
        return max(1, (int) $this->settings->get('commerce', 'max_units_per_order', config('commerce.max_units_per_order')));
    }
}
