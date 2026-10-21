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
use App\Support\Money;

/**
 * Flutterwave v3 (developer.flutterwave.com). Implemented against the official docs:
 *  - amounts are in MAJOR units (naira), unlike Paystack's kobo;
 *  - POST /payments {tx_ref, amount, currency, redirect_url, customer} -> data.link;
 *  - GET /transactions/{id}/verify, or /transactions/verify_by_reference?tx_ref= -> data.status "successful", data.amount;
 *  - webhooks carry the merchant's secret hash verbatim in the `verif-hash` header; payment events are
 *    `charge.completed` with data.id / data.tx_ref; refund notifications have no `event` key and carry TransactionId;
 *  - POST /transactions/{id}/refund {amount, comments}.
 */
class FlutterwaveGateway implements PaymentGateway
{
    use CallsGatewayApi;

    public function name(): string
    {
        return 'flutterwave';
    }

    public function label(): string
    {
        return (string) config('payments.gateways.flutterwave.label', 'Flutterwave');
    }

    public function isConfigured(): bool
    {
        return filled($this->secretKey());
    }

    protected function secretKey(): ?string
    {
        return config('payments.gateways.flutterwave.secret_key');
    }

    protected function baseUrl(): string
    {
        return (string) config('payments.gateways.flutterwave.base_url');
    }

    public function initialize(PaymentRequest $request): InitializedPayment
    {
        $customer = array_filter(['email' => $request->email, 'name' => $request->name, 'phonenumber' => $request->phone]);

        $response = $this->send('POST', '/payments', [
            'tx_ref' => $request->reference,
            'amount' => Money::toNairaString($request->amountKobo),
            'currency' => $request->currency,
            'redirect_url' => $request->callbackUrl,
            'customer' => $customer,
            'meta' => ['order_reference' => $request->orderReference],
        ]);

        $link = $response->json('data.link');
        if (! $response->successful() || $response->json('status') !== 'success' || ! is_string($link) || $link === '') {
            throw new PaymentGatewayException('Flutterwave initialize failed: '.$this->messageOf($response), $response->status());
        }

        return new InitializedPayment($link);
    }

    public function verify(Payment $payment): GatewayVerification
    {
        $response = $payment->gateway_transaction_id
            ? $this->send('GET', '/transactions/'.rawurlencode($payment->gateway_transaction_id).'/verify', idempotentRead: true)
            : $this->send('GET', '/transactions/verify_by_reference', ['tx_ref' => $payment->reference], idempotentRead: true);

        if ($response->serverError()) {
            throw new PaymentGatewayException('Flutterwave verify failed: '.$this->messageOf($response), $response->status());
        }
        if (! $response->successful() || $response->json('status') !== 'success') {
            if (preg_match('/no transaction|not found/i', $this->messageOf($response)) === 1 || $response->status() === 404) {
                return new GatewayVerification(GatewayStatus::NotFound, rawStatus: 'not_found', message: $this->messageOf($response));
            }
            throw new PaymentGatewayException('Flutterwave verify rejected: '.$this->messageOf($response), $response->status());
        }

        $data = (array) $response->json('data', []);
        $raw = (string) ($data['status'] ?? '');

        return new GatewayVerification(
            status: match ($raw) {
                'successful' => GatewayStatus::Successful,
                'failed' => GatewayStatus::Failed,
                default => GatewayStatus::Pending,
            },
            reference: isset($data['tx_ref']) ? (string) $data['tx_ref'] : null,
            amountKobo: isset($data['amount']) && is_numeric($data['amount']) ? Money::fromNaira($data['amount']) : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            transactionId: isset($data['id']) ? (string) $data['id'] : null,
            channel: isset($data['payment_type']) ? (string) $data['payment_type'] : null,
            rawStatus: $raw,
            message: isset($data['processor_response']) ? mb_substr((string) $data['processor_response'], 0, 180) : null,
        );
    }

    public function parseWebhook(string $rawBody, array $headers): ?WebhookEvent
    {
        $secretHash = config('payments.gateways.flutterwave.secret_hash');
        $signature = $headers['verif-hash'] ?? '';

        // Constant-time comparison; fail closed when no secret hash is configured.
        if (! filled($secretHash) || $signature === '' || ! hash_equals((string) $secretHash, $signature)) {
            throw new InvalidWebhookSignature('Flutterwave webhook verif-hash mismatch');
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return null;
        }

        if (($payload['event'] ?? null) === 'charge.completed' && is_array($payload['data'] ?? null)) {
            $data = $payload['data'];
            $reference = (string) ($data['tx_ref'] ?? '');

            return $reference === '' ? null : new WebhookEvent(
                WebhookEvent::KIND_PAYMENT, 'charge.completed',
                sha1('flutterwave|charge.completed|'.($data['id'] ?? '').'|'.($data['status'] ?? '')),
                reference: $reference,
                transactionId: isset($data['id']) ? (string) $data['id'] : null,
            );
        }

        // Refund notifications are flat objects: {id, AmountRefunded, status, TransactionId, FlwRef, ...}.
        if (isset($payload['AmountRefunded'], $payload['TransactionId'], $payload['id'])) {
            $status = (string) ($payload['status'] ?? '');

            return new WebhookEvent(
                WebhookEvent::KIND_REFUND, 'refund',
                sha1('flutterwave|refund|'.$payload['id'].'|'.$status),
                transactionId: (string) $payload['TransactionId'],
                refundId: (string) $payload['id'],
                refundState: $this->refundState($status),
                refundAmountKobo: is_numeric($payload['AmountRefunded']) ? Money::fromNaira($payload['AmountRefunded']) : null,
            );
        }

        return null;
    }

    public function refund(Payment $payment, int $amountKobo, string $reason): RefundResult
    {
        if (! $payment->gateway_transaction_id) {
            throw new PaymentGatewayException('Flutterwave refunds need the transaction id, which is recorded when a payment is verified');
        }

        $response = $this->send('POST', '/transactions/'.rawurlencode($payment->gateway_transaction_id).'/refund', [
            'amount' => Money::toNairaString($amountKobo),
            'comments' => mb_substr($reason, 0, 190),
        ]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            throw new PaymentGatewayException('Flutterwave refund failed: '.$this->messageOf($response), $response->status());
        }

        $state = $this->refundState((string) $response->json('data.status', ''));
        if ($state === 'failed') {
            throw new PaymentGatewayException('Flutterwave refund failed');
        }

        return new RefundResult((string) $response->json('data.id', ''), $state);
    }

    /**
     * Flutterwave statuses: "completed" = initiated and pending disbursement; "completed-bank-transfer|momo|mpgs|
     * offline|preauth" = money returned; "pending-*" and "processing" = in flight.
     */
    private function refundState(string $status): string
    {
        return match (true) {
            $status === 'completed' => 'pending',
            str_starts_with($status, 'completed-') => 'processed',
            str_starts_with($status, 'pending'), $status === 'processing' => 'pending',
            default => 'failed',
        };
    }
}
