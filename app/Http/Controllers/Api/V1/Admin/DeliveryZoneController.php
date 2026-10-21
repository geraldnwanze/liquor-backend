<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeliveryZoneRequest;
use App\Models\DeliveryZone;
use App\Services\Administration\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryZoneController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(DeliveryZone::query()->orderBy('state')->orderBy('sort_order')->get());
    }

    public function store(DeliveryZoneRequest $request): JsonResponse
    {
        $zone = DeliveryZone::create($request->validated() + ['city' => null])->refresh();
        $this->audit->record('delivery_zone.created', $zone, ['name' => $zone->name]);

        return (new JsonResource($zone))->response()->setStatusCode(201);
    }

    public function update(DeliveryZoneRequest $request, DeliveryZone $zone): JsonResource
    {
        $zone->fill($request->validated())->save();
        $this->audit->record('delivery_zone.updated', $zone, ['changed' => array_keys($zone->getChanges())]);

        return new JsonResource($zone);
    }

    public function destroy(DeliveryZone $zone): JsonResponse
    {
        // Past orders keep their own snapshot of the zone name, so deleting is safe.
        $this->audit->record('delivery_zone.deleted', $zone, ['name' => $zone->name]);
        $zone->delete();

        return response()->json(null, 204);
    }
}
