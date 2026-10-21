<?php

namespace App\Services\Payments;

use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Exceptions\CommerceException;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Administration\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Refund foundations: staff request a (full or partial) refund of a settled payment; the gateway returns the money
 * (often asynchronously, confirmed later by webhook). Pending and processed refunds both count against the payment,
 * so the same money can never be refunded twice, and a repeated request with the same key changes nothing.
 */
class RefundService
{
    public function __construct(private PaymentGatewayManager $gateways, private AuditLogger $audit) {}

    public function request(Payment $payment, ?int $amountKobo, string $reason, User $by, string $idempotencyKey): Refund
    {
        /** @var array{0: Refund, 1: bool} $result [refund, whether the gateway still has to be asked] */
        $result = DB::transaction(function () use ($payment, $amountKobo, $reason, $by, $idempotencyKey): array {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($existing = Refund::where('payment_id', $locked->id)->where('idempotency_key', $idempotencyKey)->first()) {
                // The same admin action again. A refund the gateway accepted is simply returned; one it REJECTED is
                // retried (the money was never sent back), otherwise a failed attempt could never be completed.
                if ($existing->state !== 'failed') {
                    return [$existing, false];
                }
                $existing->forceFill(['state' => 'pending', 'failure_reason' => null, 'gateway_refund_id' => null])->save();

                return [$existing, true];
            }

            // "review" payments hold money we received but could not fulfil: refunding them is the point.
            if (! in_array($locked->state, [PaymentState::Succeeded, PaymentState::Review], true)) {
                throw new CommerceException('payment_not_refundable', 'Only a payment that was received can be refunded.', 409);
            }

            $remaining = $locked->amount_kobo - $this->committedKobo($locked);
            $amount = $amountKobo ?? $remaining;
            if ($amount <= 0 || $amount > $remaining) {
                throw new CommerceException('refund_amount_invalid', 'The refund must be more than zero and no more than what is left to refund.', 422, ['refundable_kobo' => $remaining]);
            }
            $min = (int) config("payments.gateways.{$locked->gateway}.min_refund_kobo", 0);
            if ($amount < $min && $amount !== $remaining) {
                throw new CommerceException('refund_amount_invalid', 'This gateway does not accept refunds below the minimum amount.', 422, ['min_kobo' => $min]);
            }

            return [Refund::create([
                'payment_id' => $locked->id, 'order_id' => $locked->order_id, 'requested_by' => $by->id,
                'idempotency_key' => $idempotencyKey, 'amount_kobo' => $amount, 'reason' => mb_substr($reason, 0, 255),
            ])->refresh(), true];
        });

        [$refund, $askGateway] = $result;
        if (! $askGateway) {
            return $refund;
        }

        try {
            $outcome = $this->gateways->get($payment->gateway)->refund($payment, $refund->amount_kobo, $reason);
        } catch (PaymentGatewayException $e) {
            $refund->forceFill(['state' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 180)])->save();
            $this->sync($payment);
            report($e);

            throw new CommerceException('refund_failed', 'The payment gateway did not accept the refund. Nothing was refunded.', 502);
        }

        $refund->forceFill(['gateway_refund_id' => $outcome->gatewayRefundId ?: null, 'state' => $outcome->state])->save();
        $this->sync($payment);
        $this->audit->record('payment.refund_requested', $payment, ['refund_id' => $refund->id, 'amount_kobo' => $refund->amount_kobo, 'state' => $refund->state]);

        return $refund;
    }

    /**
     * A refund update from the gateway. Matched by the gateway's refund id when we have it; otherwise (Paystack's
     * webhook id differs from the id its create call returns) by the oldest in-flight refund with the same amount.
     */
    public function applyGatewayUpdate(Payment $payment, string $state, ?string $gatewayRefundId, ?int $amountKobo): bool
    {
        return DB::transaction(function () use ($payment, $state, $gatewayRefundId, $amountKobo): bool {
            Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $inFlight = Refund::query()->where('payment_id', $payment->id)->where('state', 'pending')
                ->when($amountKobo !== null, fn ($q) => $q->where('amount_kobo', $amountKobo))->orderBy('id');

            // Exact id match first; otherwise the oldest in-flight refund of the same amount.
            $refund = ($gatewayRefundId ? (clone $inFlight)->where('gateway_refund_id', $gatewayRefundId)->first() : null) ?? $inFlight->first();

            if (! $refund || ! in_array($state, ['pending', 'processed', 'failed'], true)) {
                return false;
            }
            $refund->forceFill(['state' => $state, 'gateway_refund_id' => $refund->gateway_refund_id ?? $gatewayRefundId])->save();
            $this->sync($payment);

            return true;
        });
    }

    /** Recomputes the refunded total on the payment and the order's payment status. */
    private function sync(Payment $payment): void
    {
        $payment->refresh();
        $committed = $this->committedKobo($payment);
        $payment->forceFill(['refunded_kobo' => $committed])->save();

        if ($payment->state === PaymentState::Succeeded) {
            $payment->order->forceFill(['payment_status' => match (true) {
                $committed >= $payment->amount_kobo => PaymentStatus::Refunded,
                $committed > 0 => PaymentStatus::PartiallyRefunded,
                default => PaymentStatus::Paid,
            }])->save();
        }
    }

    /** Money already promised back: refunds that are in flight or done. */
    private function committedKobo(Payment $payment): int
    {
        return (int) Refund::where('payment_id', $payment->id)->whereIn('state', ['pending', 'processed'])->sum('amount_kobo');
    }
}
