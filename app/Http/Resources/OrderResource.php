<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'fulfilment' => ['status' => $this->fulfilment_status->value, 'label' => $this->fulfilment_status->label()],
            'currency' => $this->currency,
            'subtotal' => Money::toArray($this->subtotal_kobo),
            'discount' => Money::toArray($this->discount_kobo),
            'discount_code' => $this->discount_code,
            'delivery_fee' => Money::toArray($this->delivery_fee_kobo),
            'total' => Money::toArray($this->total_kobo),
            'items' => $this->items->map(fn (OrderItem $i) => [
                'sku' => $i->sku,
                'product_name' => $i->product_name,
                'variant_name' => $i->variant_name,
                'quantity' => $i->quantity,
                'unit_price' => Money::toArray($i->unit_price_kobo),
                'line_total' => Money::toArray($i->line_total_kobo),
            ])->values(),
            'customer' => ['name' => $this->customer_name, 'email' => $this->email, 'phone' => $this->phone],
            'delivery' => [
                'zone' => $this->delivery_zone_name,
                'state' => $this->delivery_state,
                'city' => $this->delivery_city,
                'address' => $this->delivery_address,
                'instructions' => $this->delivery_instructions,
            ],
            // Carrier name and tracking reference only. The carrier's phone and staff notes stay internal.
            'shipment' => $this->whenLoaded('shipment', fn () => $this->shipment ? [
                'carrier' => $this->shipment->carrier,
                'tracking_reference' => $this->shipment->tracking_reference,
                'estimated_delivery_at' => $this->shipment->estimated_delivery_at?->toIso8601String(),
            ] : null),
            'timeline' => $this->whenLoaded('events', fn () => $this->events->where('customer_visible', true)->values()->map(fn ($e) => [
                'type' => $e->type, 'label' => $e->customerLabel(), 'at' => $e->created_at->toIso8601String(),
            ])),
            'reservation_expires_at' => $this->when($this->isPendingPayment(), $this->reservation_expires_at?->toIso8601String()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
