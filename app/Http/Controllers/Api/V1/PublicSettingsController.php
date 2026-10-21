<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Administration\BusinessLogo;
use App\Services\Administration\HomepageContent;
use App\Services\Administration\SettingsRepository;
use App\Services\Commerce\CommerceSettings;
use Illuminate\Http\JsonResponse;

class PublicSettingsController extends Controller
{
    /** Storefront-safe commerce rules (no secrets). Lets the UI show real limits instead of hardcoding them. */
    public function show(CommerceSettings $settings, SettingsRepository $store, BusinessLogo $logo, HomepageContent $homepage): JsonResponse
    {
        return response()->json(['data' => [
            'currency' => 'NGN',
            'business' => ['name' => $store->get('business', 'name') ?: null, 'logo_url' => $logo->url()],
            'homepage' => $homepage->values(),
            'commerce' => [
                'minimum_age' => $settings->minimumAge(),
                'max_quantity_per_line' => $settings->maxQuantityPerLine(),
                'max_units_per_order' => $settings->maxUnitsPerOrder(),
                'reservation_minutes' => $settings->reservationMinutes(),
            ],
        ]]);
    }
}
