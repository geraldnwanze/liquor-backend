<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\WebhookEvent as WebhookRecord;
use App\Services\Payments\Data\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Turns an authenticated webhook into action. Webhooks are only a *nudge*: nothing in the body is trusted for
 * money. A payment event makes us re-ask the gateway; a refund event updates a refund we asked for.
 *
 * Returns the HTTP status to answer with: 200 = done or safe to ignore, 401 = bad signature (nothing processed),
 * 503 = we could not finish now, please retry (both gateways retry on non-200).
 */
class WebhookProcessor
{
    public function __construct(
        private PaymentGatewayManager $gateways,
        private PaymentVerifier $verifier,
        private RefundService $refunds,
    ) {}

    /** @param array<string, string> $headers lower-cased header names */
    public function handle(string $gatewayName, string $rawBody, array $headers, ?string $ip = null): int
    {
        $gateway = $this->gateways->get($gatewayName);

        try {
            $event = $gateway->parseWebhook($rawBody, $headers);
        } catch (InvalidWebhookSignature) {
            // No body in the log: it is unauthenticated and may contain anything.
            Log::warning('Rejected webhook with an invalid signature', ['gateway' => $gatewayName, 'ip' => $ip]);

            return 401;
        }

        if ($event === null) {
            return 200; // an event type we do not act on
        }

        $record = $this->claim($gatewayName, $event);
        if ($record->processed_at !== null) {
            return 200; // a retry of something already handled
        }

        try {
            [$outcome, $paymentId] = $event->kind === WebhookEvent::KIND_REFUND
                ? $this->processRefund($gatewayName, $event)
                : $this->processPayment($gatewayName, $event);
        } catch (PaymentGatewayException $e) {
            report($e);

            return 503; // the gateway will redeliver; the claim stays unprocessed so we run again
        }

        $record->forceFill(['outcome' => $outcome, 'payment_id' => $paymentId, 'processed_at' => now()])->save();

        return 200;
    }

    private function claim(string $gateway, WebhookEvent $event): WebhookRecord
    {
        try {
            return WebhookRecord::firstOrCreate(['dedupe_key' => $event->dedupeKey], ['gateway' => $gateway, 'event_type' => mb_substr($event->type, 0, 60)]);
        } catch (UniqueConstraintViolationException) {
            return WebhookRecord::where('dedupe_key', $event->dedupeKey)->firstOrFail();
        }
    }

    /** @return array{0: string, 1: int|null} */
    private function processPayment(string $gateway, WebhookEvent $event): array
    {
        $payment = $this->find($gateway, $event);
        if (! $payment) {
            return ['unknown_reference', null];
        }

        // Remember the gateway's transaction id so later calls (and refunds) can address it directly.
        if ($event->transactionId && ! $payment->gateway_transaction_id) {
            $payment->forceFill(['gateway_transaction_id' => $event->transactionId])->save();
        }

        $result = $this->verifier->verify($payment, 'webhook', strict: true);

        return [$result->state->value, $payment->id];
    }

    /** @return array{0: string, 1: int|null} */
    private function processRefund(string $gateway, WebhookEvent $event): array
    {
        $payment = $this->find($gateway, $event);
        if (! $payment) {
            return ['unknown_reference', null];
        }

        $matched = $this->refunds->applyGatewayUpdate($payment, (string) $event->refundState, $event->refundId, $event->refundAmountKobo);

        return [$matched ? 'refund_'.$event->refundState : 'unmatched_refund', $payment->id];
    }

    private function find(string $gateway, WebhookEvent $event): ?Payment
    {
        return Payment::query()->where('gateway', $gateway)
            ->when($event->reference, fn ($q) => $q->where('reference', $event->reference))
            ->when(! $event->reference && $event->transactionId, fn ($q) => $q->where('gateway_transaction_id', $event->transactionId))
            ->when(! $event->reference && ! $event->transactionId, fn ($q) => $q->whereRaw('1 = 0'))
            ->first();
    }
}
