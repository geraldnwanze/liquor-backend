<?php

namespace App\Services\Payments;

use App\Enums\GatewayStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Administration\AuditLogger;
use App\Services\Orders\OrderLifecycle;
use App\Services\Payments\Data\GatewayVerification;
use Illuminate\Support\Facades\DB;

/**
 * The single place a payment is judged. Callers (customer return page, webhooks, reconciliation) only supply a
 * trigger; the gateway's own answer, fetched server to server, decides the outcome. It is safe to call repeatedly
 * and concurrently: a settled payment is never changed again, and settlement happens under a row lock.
 */
class PaymentVerifier
{
    public function __construct(
        private PaymentGatewayManager $gateways,
        private OrderLifecycle $orders,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  bool  $strict  rethrow gateway errors (webhooks must answer "retry me later" rather than pretend success)
     *
     * @throws PaymentGatewayException when $strict and the gateway cannot be asked
     */
    public function verify(Payment $payment, string $source, bool $strict = false): Payment
    {
        $payment->refresh();
        if ($payment->state->isSettled()) {
            return $payment;
        }

        try {
            $result = $this->gateways->get($payment->gateway)->verify($payment);
        } catch (PaymentGatewayException $e) {
            $this->attempt($payment, $source, 'error', $e->httpStatus, null, $e->getMessage());
            if ($strict) {
                throw $e;
            }

            return $payment;
        }

        return $this->apply($payment, $result, $source);
    }

    public function apply(Payment $payment, GatewayVerification $result, string $source): Payment
    {
        $outcome = null;

        $settled = DB::transaction(function () use ($payment, $result, &$outcome): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->state->isSettled()) {
                return $locked; // another request got here first
            }

            $locked->last_verified_at = now();
            $locked->gateway_status = $result->rawStatus;

            switch ($result->status) {
                case GatewayStatus::Successful:
                    $outcome = $this->settleSuccess($locked, $result);
                    break;
                case GatewayStatus::Failed:
                    $locked->state = PaymentState::Failed;
                    $locked->failure_reason = $result->message;
                    $outcome = 'ok';
                    break;
                case GatewayStatus::Abandoned:
                    $locked->state = PaymentState::Abandoned;
                    $outcome = 'ok';
                    break;
                default: // pending / not found: nothing to decide yet
                    $outcome = 'ok';
            }

            $locked->save();

            return $locked->refresh();
        });

        $this->attempt($settled, $source, $outcome ?? 'ok', null, $result->rawStatus, $settled->review_reason ?? $result->message);

        if ($outcome === 'paid') {
            OrderPaid::dispatch($settled->order);
        }

        return $settled;
    }

    /** @return string 'paid' | 'review' | 'mismatch' (the payment row is updated in place) */
    private function settleSuccess(Payment $payment, GatewayVerification $result): string
    {
        $payment->gateway_transaction_id = $result->transactionId ?? $payment->gateway_transaction_id;
        $payment->channel = $result->channel;
        $payment->paid_at = now();

        $order = Order::query()->lockForUpdate()->with('items')->findOrFail($payment->order_id);

        // 1. Independently validate what the gateway says against what we stored. Any difference is a hard stop.
        $problem = $this->mismatch($payment, $order, $result);
        if ($problem !== null) {
            return $this->flag($payment, $problem, 'mismatch');
        }

        // 2. The money is real and matches. Fulfil the order if it can still be fulfilled.
        if ($order->status === OrderStatus::PendingPayment && $this->orders->markPaid($order)) {
            $payment->state = PaymentState::Succeeded;

            return 'paid';
        }
        if ($order->status === OrderStatus::Paid) {
            // Another payment already paid this order: this one is a duplicate to refund.
            return $this->flag($payment, 'duplicate_payment', 'review');
        }
        if (in_array($order->status, [OrderStatus::Expired, OrderStatus::Cancelled], true) && $this->orders->markPaidLate($order)) {
            $payment->state = PaymentState::Succeeded;

            return 'paid';
        }

        return $this->flag($payment, 'late_payment_unfulfillable', 'review');
    }

    /** Reference, currency and amount must all equal what we stored. Anything else is never auto-fulfilled. */
    private function mismatch(Payment $payment, Order $order, GatewayVerification $result): ?string
    {
        return match (true) {
            $result->reference !== null && $result->reference !== $payment->reference => 'reference_mismatch',
            $result->currency === null || strtoupper($result->currency) !== strtoupper($payment->currency) => 'currency_mismatch',
            $payment->currency !== $order->currency => 'currency_mismatch',
            $result->amountKobo === null || $result->amountKobo !== $payment->amount_kobo => 'amount_mismatch',
            $payment->amount_kobo !== $order->total_kobo => 'order_total_mismatch',
            default => null,
        };
    }

    /** Money has been received but the order must not be fulfilled automatically: park it for staff. */
    private function flag(Payment $payment, string $reason, string $outcome): string
    {
        $payment->state = PaymentState::Review;
        $payment->review_reason = $reason;
        $this->audit->record('payment.needs_review', $payment, ['reason' => $reason, 'order_id' => $payment->order_id]);

        return $outcome;
    }

    private function attempt(Payment $payment, string $type, string $outcome, ?int $http, ?string $gatewayStatus, ?string $detail): void
    {
        PaymentAttempt::create([
            'payment_id' => $payment->id,
            'type' => $type,
            'outcome' => $outcome === 'paid' ? 'ok' : $outcome,
            'http_status' => $http,
            'gateway_status' => $gatewayStatus,
            'detail' => $detail === null ? null : mb_substr($detail, 0, 250),
        ]);
    }
}
