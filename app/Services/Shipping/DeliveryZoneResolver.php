<?php

namespace App\Services\Shipping;

use App\Models\DeliveryZone;

class DeliveryZoneResolver
{
    /** City-specific zone first, then a state-wide zone. Case-insensitive. Inactive zones never match. */
    public function forAddress(?string $state, ?string $city): ?DeliveryZone
    {
        $state = mb_strtolower(trim((string) $state));
        if ($state === '') {
            return null;
        }

        $zones = DeliveryZone::active()->whereRaw('lower(state) = ?', [$state])->get();
        $city = mb_strtolower(trim((string) $city));

        return ($city !== '' ? $zones->first(fn (DeliveryZone $z) => $z->city !== null && mb_strtolower($z->city) === $city) : null)
            ?? $zones->first(fn (DeliveryZone $z) => $z->city === null);
    }
}
