<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The full order as staff see it. Requires items, payments, refunds, shipment, events.user and user loaded. @mixin Order */
class AdminOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $paid = $this->status === OrderStatus::Paid;

        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'fulfilment_status' => $this->fulfilment_status->value,
            'fulfilment_label' => $this->fulfilment_status->label(),
            // What staff may do next. The server re-checks every one of these when the action is attempted.
            'next_fulfilment' => $paid ? array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], $this->fulfilment_status->allowedNext()) : [],
            'can_cancel' => $this->status === OrderStatus::PendingPayment || ($paid && $this->fulfilment_status->isCancellable()),
            'subtotal' => Money::toArray($this->subtotal_kobo),
            'discount' => Money::toArray($this->discount_kobo),
            'discount_code' => $this->discount_code,
            'delivery_fee' => Money::toArray($this->delivery_fee_kobo),
            'total' => Money::toArray($this->total_kobo),
            'customer' => [
                'name' => $this->customer_name, 'email' => $this->email, 'phone' => $this->phone,
                'has_account' => $this->user_id !== null,
            ],
            'delivery' => [
                'zone' => $this->delivery_zone_name, 'state' => $this->delivery_state, 'city' => $this->delivery_city,
                'address' => $this->delivery_address, 'instructions' => $this->delivery_instructions,
            ],
            'age_confirmed_at' => $this->age_confirmed_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'sku' => $i->sku, 'product_name' => $i->product_name, 'variant_name' => $i->variant_name, 'quantity' => $i->quantity,
                'unit_price' => Money::toArray($i->unit_price_kobo), 'line_total' => Money::toArray($i->line_total_kobo),
            ])->values()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'reference' => $p->reference, 'gateway' => $p->gateway, 'state' => $p->state->value, 'review_reason' => $p->review_reason,
                'amount' => Money::toArray($p->amount_kobo), 'refunded' => Money::toArray($p->refunded_kobo),
                'channel' => $p->channel, 'paid_at' => $p->paid_at?->toIso8601String(), 'created_at' => $p->created_at->toIso8601String(),
            ])->values()),
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->map(fn ($r) => [
                'amount' => Money::toArray($r->amount_kobo), 'state' => $r->state, 'reason' => $r->reason, 'created_at' => $r->created_at->toIso8601String(),
            ])->values()),
            'shipment' => $this->whenLoaded('shipment', fn () => $this->shipment ? [
                'carrier' => $this->shipment->carrier, 'tracking_reference' => $this->shipment->tracking_reference,
                'carrier_phone' => $this->shipment->carrier_phone, 'notes' => $this->shipment->notes,
                'estimated_delivery_at' => $this->shipment->estimated_delivery_at?->toIso8601String(),
                'dispatched_at' => $this->shipment->dispatched_at?->toIso8601String(), 'delivered_at' => $this->shipment->delivered_at?->toIso8601String(),
            ] : null),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'type' => $e->type, 'from' => $e->from_value, 'to' => $e->to_value, 'note' => $e->note,
                'customer_visible' => $e->customer_visible, 'by' => $e->user?->name, 'at' => $e->created_at->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
