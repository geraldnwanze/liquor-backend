<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Inventory\StockReservations;
use App\Services\Promotions\DiscountRedemptions;
use Illuminate\Support\Facades\DB;

/**
 * The only place an order's status changes. Every transition re-reads the order under a row lock and is a no-op
 * unless the order is still pending payment, so duplicate calls (retries, webhooks, the expiry job) are safe.
 *
 * markPaid() is internal: it must only be called after a payment has been verified against the gateway. No HTTP
 * route exposes it, so staff cannot mark an order paid by hand.
 */
class OrderLifecycle
{
    public function __construct(private StockReservations $stock, private OrderTimeline $timeline, private DiscountRedemptions $redemptions) {}

    public function markPaid(Order $order): bool
    {
        return $this->transition($order, function (Order $locked): void {
            $this->stock->commit($locked);
            $this->redemptions->markRedeemed($locked);
            $locked->forceFill(['status' => OrderStatus::Paid, 'payment_status' => PaymentStatus::Paid, 'paid_at' => now()])->save();
            $this->clearPurchasedFromCart($locked);
            $this->timeline->record($locked, 'paid', 'paid', 'pending_payment', customerVisible: true);
        });
    }

    /**
     * A verified payment arrived after the reservation lapsed (order expired or was superseded). If every item is
     * still in stock the order is fulfilled; otherwise nothing changes and the caller must flag the payment for refund.
     */
    public function markPaidLate(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = Order::query()->lockForUpdate()->with('items')->find($order->id);
            if (! $locked || ! in_array($locked->status, [OrderStatus::Expired, OrderStatus::Cancelled], true)) {
                return false;
            }
            if (! $this->stock->sellDirectly($locked)) {
                return false;
            }

            $previous = $locked->status->value;
            $this->redemptions->markRedeemed($locked);
            $locked->forceFill(['status' => OrderStatus::Paid, 'payment_status' => PaymentStatus::Paid, 'paid_at' => now(), 'cancelled_at' => null])->save();
            $this->clearPurchasedFromCart($locked);
            $this->timeline->record($locked, 'paid', 'paid', $previous, 'Paid after the reservation had expired', customerVisible: true);
            $order->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Cancels a PAID order that has not left yet and puts its stock back. Returns false (changing nothing) once it is
     * dispatched or delivered. The caller is responsible for refunding the customer first.
     */
    public function cancelPaid(Order $order, ?User $by, ?string $reason): bool
    {
        return DB::transaction(function () use ($order, $by, $reason): bool {
            $locked = Order::query()->lockForUpdate()->with('items')->find($order->id);
            if (! $locked || $locked->status !== OrderStatus::Paid || ! $locked->fulfilment_status->isCancellable()) {
                return false;
            }

            $this->stock->restock($locked);
            $this->redemptions->release($locked);
            $locked->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();
            $this->timeline->record($locked, 'cancelled', 'cancelled', 'paid', $reason, customerVisible: true, by: $by);
            $order->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /** The customer has bought these lines; take them out of the cart so they are not bought twice. */
    private function clearPurchasedFromCart(Order $order): void
    {
        if ($order->cart_id) {
            CartItem::query()->where('cart_id', $order->cart_id)->whereIn('product_variant_id', $order->items->pluck('product_variant_id')->filter())->delete();
        }
    }

    public function cancel(Order $order): bool
    {
        return $this->transition($order, function (Order $locked): void {
            $this->stock->release($locked);
            $this->redemptions->release($locked);
            $locked->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();
            $this->timeline->record($locked, 'cancelled', 'cancelled', 'pending_payment', customerVisible: true);
        });
    }

    public function expire(Order $order): bool
    {
        return $this->transition($order, function (Order $locked): void {
            $this->stock->release($locked);
            $this->redemptions->release($locked);
            $locked->forceFill(['status' => OrderStatus::Expired, 'cancelled_at' => now()])->save();
            $this->timeline->record($locked, 'expired', 'expired', 'pending_payment', customerVisible: true);
        });
    }

    /** @param callable(Order): void $apply */
    private function transition(Order $order, callable $apply): bool
    {
        return DB::transaction(function () use ($order, $apply): bool {
            $locked = Order::query()->lockForUpdate()->with('items')->find($order->id);
            if (! $locked || ! $locked->isPendingPayment()) {
                return false;
            }
            $apply($locked);
            $order->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
