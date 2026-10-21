<?php

namespace App\Services\Orders;

use App\Enums\OrderFulfilment;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Events\FulfilmentChanged;
use App\Exceptions\CommerceException;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Administration\AuditLogger;
use App\Services\Payments\RefundService;
use Illuminate\Support\Facades\DB;

/**
 * What staff can do to an order. Every change is rule-checked on the server (never trust the screen), recorded on
 * the order's timeline with who did it, and written to the audit log. Payment status is deliberately NOT here:
 * an order becomes paid only through a verified gateway payment, so staff cannot mark one paid by hand.
 */
class OrderManagement
{
    public function __construct(
        private OrderTimeline $timeline,
        private OrderLifecycle $lifecycle,
        private RefundService $refunds,
        private AuditLogger $audit,
    ) {}

    public function moveFulfilment(Order $order, OrderFulfilment $to, User $by, ?string $note = null): Order
    {
        DB::transaction(function () use ($order, $to, $by, $note): void {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== OrderStatus::Paid) {
                throw new CommerceException('order_not_fulfillable', 'Only a paid order can be prepared or delivered.', 409);
            }
            $from = $locked->fulfilment_status;
            if (! $from->canMoveTo($to)) {
                throw new CommerceException('invalid_fulfilment_transition', "An order that is \"{$from->label()}\" cannot move to \"{$to->label()}\".", 422, [
                    'from' => $from->value, 'allowed' => array_map(fn (OrderFulfilment $s) => $s->value, $from->allowedNext()),
                ]);
            }

            $locked->forceFill(['fulfilment_status' => $to])->save();

            $shipment = $locked->shipment;
            if ($shipment && $to === OrderFulfilment::OutForDelivery) {
                $shipment->forceFill(['dispatched_at' => now(), 'delivered_at' => null])->save();
            }
            if ($shipment && $to === OrderFulfilment::Delivered) {
                $shipment->forceFill(['delivered_at' => now()])->save();
            }

            // The customer sees where their order is, not the staff note that came with the change.
            $this->timeline->record($locked, 'fulfilment', $to->value, $from->value, $note, customerVisible: true, by: $by);
            $this->audit->record('order.fulfilment_changed', $locked, ['from' => $from->value, 'to' => $to->value]);
            FulfilmentChanged::dispatch($locked, $to);
        });

        return $order->refresh();
    }

    /** @param array{carrier?: ?string, tracking_reference?: ?string, carrier_phone?: ?string, notes?: ?string, estimated_delivery_at?: ?string} $data */
    public function saveShipment(Order $order, array $data, User $by): Shipment
    {
        if ($order->status !== OrderStatus::Paid) {
            throw new CommerceException('order_not_fulfillable', 'Delivery details can only be added to a paid order.', 409);
        }

        $shipment = DB::transaction(function () use ($order, $data, $by): Shipment {
            $shipment = Shipment::firstOrNew(['order_id' => $order->id]);
            $shipment->forceFill(['order_id' => $order->id])->fill($data)->save();
            $this->timeline->record($order, 'shipment', note: 'Delivery details updated', by: $by);
            $this->audit->record('order.shipment_saved', $order, ['carrier' => $shipment->carrier]);

            return $shipment;
        });

        return $shipment->refresh();
    }

    public function addNote(Order $order, string $body, User $by): OrderEvent
    {
        $event = $this->timeline->record($order, 'note', note: $body, by: $by); // never customer-visible
        $this->audit->record('order.note_added', $order);

        return $event;
    }

    /**
     * Cancellation rules:
     *  - unpaid order: cancel it and release the reserved stock;
     *  - paid order that has NOT left yet (awaiting preparation / processing): refund the customer in full first
     *    (needs the refund permission), then cancel and put the stock back;
     *  - dispatched, delivered or already cancelled: refused. After delivery, refund instead.
     */
    public function cancel(Order $order, string $reason, User $by): Order
    {
        $order->refresh();

        if ($order->status === OrderStatus::PendingPayment) {
            if (! $this->lifecycle->cancel($order)) {
                throw new CommerceException('order_not_cancellable', 'This order can no longer be cancelled.', 409);
            }
            $this->timeline->record($order, 'note', note: "Cancelled by staff: {$reason}", by: $by);
            $this->audit->record('order.cancelled', $order, ['reason' => $reason, 'was' => 'pending_payment']);

            return $order->refresh();
        }

        if ($order->status !== OrderStatus::Paid) {
            throw new CommerceException('order_not_cancellable', 'This order is already cancelled or expired.', 409);
        }
        if (! $order->fulfilment_status->isCancellable()) {
            throw new CommerceException('order_not_cancellable', 'This order has already left for delivery. It can no longer be cancelled; refund it instead.', 409);
        }
        if (! $by->hasPermission('orders.refund')) {
            throw new CommerceException('refund_permission_required', 'Cancelling a paid order refunds the customer, which needs the refund permission.', 403);
        }

        // Refund first (idempotent per order, so a retry after a partial failure simply continues).
        $payment = $order->payments()->where('state', PaymentState::Succeeded)->latest('id')->first();
        if ($payment && $payment->refundableKobo() > 0) {
            $this->refunds->request($payment, null, "Order {$order->reference} cancelled: {$reason}", $by, 'cancel-'.$order->reference);
        }

        if (! $this->lifecycle->cancelPaid($order, $by, $reason)) {
            throw new CommerceException('order_not_cancellable', 'This order changed while it was being cancelled. Please check it and try again.', 409);
        }
        $this->audit->record('order.cancelled', $order, ['reason' => $reason, 'was' => 'paid', 'refunded' => $payment !== null]);

        return $order->refresh();
    }
}
