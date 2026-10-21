<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Models\DeliveryZone;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DeliveryZoneController extends Controller
{
    /** Where we deliver, for the checkout state/city pickers. Fully configured by staff. */
    public function index(): JsonResponse
    {
        $zones = Cache::remember('delivery.zones', 300, fn () => DeliveryZone::active()->orderBy('state')->orderBy('sort_order')->get()
            ->map(fn (DeliveryZone $z) => [
                'name' => $z->name,
                'state' => $z->state,
                'city' => $z->city,
                'fee' => Money::toArray($z->fee_kobo),
                'free_threshold' => $z->free_threshold_kobo === null ? null : Money::toArray($z->free_threshold_kobo),
                'estimate' => CartResource::estimate($z->estimated_days_min, $z->estimated_days_max),
                'notes' => $z->notes,
            ])->all());

        return response()->json(['data' => $zones]);
    }
}
