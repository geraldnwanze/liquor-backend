<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Enums\GatewayStatus;
use App\Models\Payment;
use App\Services\Payments\Data\GatewayVerification;
use App\Services\Payments\Data\InitializedPayment;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Data\RefundResult;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\Gateways\Concerns\CallsGatewayApi;
use App\Services\Payments\InvalidWebhookSignature;
use App\Services\Payments\PaymentGatewayException;

/**
 * Paystack. Implemented against the official reference (paystack.com/docs/api):
 *  - amounts are in the currency subunit (kobo), sent as a string;
 *  - POST /transaction/initialize -> data.authorization_url;
 *  - GET /transaction/verify/:reference -> data.status success|failed|abandoned, data.amount, data.requested_amount;
 *  - webhooks carry x-paystack-signature = HMAC-SHA512(raw body, secret key); events charge.success, refund.*;
 *  - POST /refund is asynchronous (data.status "pending"), final state arrives via refund.* events.
 */
class PaystackGateway implements PaymentGateway
{
    use CallsGatewayApi;

    public function name(): string
    {
        return 'paystack';
    }

    public function label(): string
    {
        return (string) config('payments.gateways.paystack.label', 'Paystack');
    }

    public function isConfigured(): bool
    {
        return filled($this->secretKey());
    }

    protected function secretKey(): ?string
    {
        return config('payments.gateways.paystack.secret_key');
    }

    protected function baseUrl(): string
    {
        return (string) config('payments.gateways.paystack.base_url');
    }

    public function initialize(PaymentRequest $request): InitializedPayment
    {
        $response = $this->send('POST', '/transaction/initialize', [
            'email' => $request->email,
            'amount' => (string) $request->amountKobo,
            'currency' => $request->currency,
            'reference' => $request->reference,
            'callback_url' => $request->callbackUrl,
            // Paystack expects metadata as a stringified JSON object. Order reference only: no personal data.
            'metadata' => json_encode(['order_reference' => $request->orderReference], JSON_THROW_ON_ERROR),
        ]);

        $url = $response->json('data.authorization_url');
        if (! $response->successful() || $response->json('status') !== true || ! is_string($url) || $url === '') {
            throw new PaymentGatewayException('Paystack initialize failed: '.$this->messageOf($response), $response->status());
        }

        return new InitializedPayment($url);
    }

    public function verify(Payment $payment): GatewayVerification
    {
        $response = $this->send('GET', '/transaction/verify/'.rawurlencode($payment->reference), idempotentRead: true);

        if ($response->serverError()) {
            throw new PaymentGatewayException('Paystack verify failed: '.$this->messageOf($response), $response->status());
        }
        if (! $response->successful()) {
            // An unknown reference is a 4xx with "not found" in the message: the customer never reached the payment page.
            if (stripos($this->messageOf($response), 'not found') !== false) {
                return new GatewayVerification(GatewayStatus::NotFound, rawStatus: 'not_found', message: $this->messageOf($response));
            }
            throw new PaymentGatewayException('Paystack verify rejected: '.$this->messageOf($response), $response->status());
        }

        $data = (array) $response->json('data', []);
        $raw = (string) ($data['status'] ?? '');

        // `amount` can include fees when the merchant passes them on; `requested_amount` is what we asked for.
        $amount = $data['requested_amount'] ?? $data['amount'] ?? null;

        return new GatewayVerification(
            status: match ($raw) {
                'success' => GatewayStatus::Successful,
                'failed' => GatewayStatus::Failed,
                'abandoned' => GatewayStatus::Abandoned,
                default => GatewayStatus::Pending, // ongoing, pending, processing, queued, ...
            },
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            amountKobo: is_numeric($amount) ? (int) $amount : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            transactionId: isset($data['id']) ? (string) $data['id'] : null,
            channel: isset($data['channel']) ? (string) $data['channel'] : null,
            rawStatus: $raw,
            message: isset($data['gateway_response']) ? mb_substr((string) $data['gateway_response'], 0, 180) : null,
        );
    }

    public function parseWebhook(string $rawBody, array $headers): ?WebhookEvent
    {
        $secret = $this->secretKey();
        $signature = $headers['x-paystack-signature'] ?? '';

        // Fail closed: with no secret configured nothing can be authenticated, so nothing is accepted.
        if (! filled($secret) || $signature === '' || ! hash_equals(hash_hmac('sha512', $rawBody, (string) $secret), $signature)) {
            throw new InvalidWebhookSignature('Paystack webhook signature mismatch');
        }

        $payload = json_decode($rawBody, true);
        $event = is_array($payload) ? ($payload['event'] ?? null) : null;
        $data = is_array($payload) ? ($payload['data'] ?? []) : [];
        if (! is_string($event) || ! is_array($data)) {
            return null;
        }

        if ($event === 'charge.success') {
            $reference = (string) ($data['reference'] ?? '');

            return $reference === '' ? null : new WebhookEvent(
                WebhookEvent::KIND_PAYMENT, $event,
                sha1("paystack|{$event}|{$reference}|".($data['id'] ?? '')),
                reference: $reference,
                transactionId: isset($data['id']) ? (string) $data['id'] : null,
            );
        }

        if (str_starts_with($event, 'refund.')) {
            $refundReference = (string) ($data['refund_reference'] ?? '');

            return new WebhookEvent(
                WebhookEvent::KIND_REFUND, $event,
                sha1("paystack|{$event}|{$refundReference}|".($data['transaction_reference'] ?? '')),
                reference: isset($data['transaction_reference']) ? (string) $data['transaction_reference'] : null,
                refundId: $refundReference ?: null,
                refundState: match ($event) {
                    'refund.processed' => 'processed',
                    'refund.failed' => 'failed',
                    default => 'pending', // refund.pending, refund.processing
                },
                // Documented as a string in subunits, e.g. "5000".
                refundAmountKobo: isset($data['amount']) && is_numeric($data['amount']) ? (int) $data['amount'] : null,
            );
        }

        return null;
    }

    public function refund(Payment $payment, int $amountKobo, string $reason): RefundResult
    {
        $response = $this->send('POST', '/refund', [
            'transaction' => $payment->reference,
            'amount' => $amountKobo,
            'currency' => $payment->currency,
            'merchant_note' => mb_substr($reason, 0, 190),
        ]);

        if (! $response->successful() || $response->json('status') !== true) {
            throw new PaymentGatewayException('Paystack refund failed: '.$this->messageOf($response), $response->status());
        }

        $state = (string) $response->json('data.status', 'pending');
        if ($state === 'failed') {
            throw new PaymentGatewayException('Paystack refund failed');
        }

        return new RefundResult((string) $response->json('data.id', ''), $state === 'processed' ? 'processed' : 'pending');
    }
}
