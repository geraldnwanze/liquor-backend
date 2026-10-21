<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Exceptions\CommerceException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Commerce\CommerceSettings;
use App\Services\Payments\Data\PaymentRequest;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private PaymentGatewayManager $gateways, private CommerceSettings $settings) {}

    /**
     * Starts a payment for an order and returns the gateway page to send the customer to. The amount is always the
     * order's stored total: nothing monetary is accepted from the browser. Calling again within a few minutes for the
     * same gateway returns the same pending payment instead of creating another.
     */
    public function initialize(Order $order, string $gatewayName): Payment
    {
        if (! $this->gateways->isAvailable($gatewayName)) {
            throw new CommerceException('gateway_unavailable', 'That payment method is not available right now.', 422);
        }
        $gateway = $this->gateways->get($gatewayName);

        $payment = DB::transaction(function () use ($order, $gatewayName): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== OrderStatus::PendingPayment) {
                throw new CommerceException('order_not_payable', match ($locked->status) {
                    OrderStatus::Paid => 'This order has already been paid.',
                    default => 'This order can no longer be paid. Please place a new order.',
                }, 409);
            }
            if ($locked->reservation_expires_at !== null && $locked->reservation_expires_at->isPast()) {
                throw new CommerceException('order_expired', 'This order has expired and its items were released. Please place a new order.', 409);
            }

            $existing = Payment::query()
                ->where('order_id', $locked->id)->where('gateway', $gatewayName)->where('state', PaymentState::Pending)
                ->whereNotNull('authorization_url')->where('created_at', '>', now()->subMinutes(10))->latest('id')->first();
            if ($existing) {
                return $existing;
            }

            // Give the customer a full window to finish paying on the gateway's page.
            $window = now()->addMinutes($this->settings->reservationMinutes());
            if ($locked->reservation_expires_at === null || $locked->reservation_expires_at->lt($window)) {
                $locked->forceFill(['reservation_expires_at' => $window])->save();
            }

            return Payment::create([
                'order_id' => $locked->id,
                'gateway' => $gatewayName,
                'reference' => $this->newReference(),
                'amount_kobo' => $locked->total_kobo,
                'currency' => $locked->currency,
            ])->refresh();
        });

        if ($payment->authorization_url) {
            return $payment;
        }

        try {
            $initialized = $gateway->initialize(new PaymentRequest(
                reference: $payment->reference,
                amountKobo: $payment->amount_kobo,
                currency: $payment->currency,
                email: $order->email,
                name: $order->customer_name,
                phone: $order->phone,
                callbackUrl: rtrim((string) config('app.frontend_url'), '/').'/payment/return/'.$payment->reference,
                orderReference: $order->reference,
            ));
        } catch (PaymentGatewayException $e) {
            $payment->forceFill(['state' => PaymentState::Failed, 'failure_reason' => 'initialize_failed'])->save();
            PaymentAttempt::create(['payment_id' => $payment->id, 'type' => 'initialize', 'outcome' => 'error', 'http_status' => $e->httpStatus, 'detail' => mb_substr($e->getMessage(), 0, 250)]);
            report($e);

            throw new CommerceException('gateway_error', "We couldn't start the payment. Please try again, or choose another payment method.", 502);
        }

        $payment->forceFill(['authorization_url' => $initialized->authorizationUrl])->save();
        PaymentAttempt::create(['payment_id' => $payment->id, 'type' => 'initialize', 'outcome' => 'ok']);

        return $payment;
    }

    private function newReference(): string
    {
        do {
            $ref = 'LQP'.implode('', array_map(
                fn () => self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)],
                range(1, 16),
            ));
        } while (Payment::where('reference', $ref)->exists());

        return $ref;
    }
}
