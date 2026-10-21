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
use App\Services\Payments\InvalidWebhookSignature;
use Illuminate\Support\Facades\Cache;

/**
 * DEVELOPMENT SIMULATOR ONLY. Lets the whole pay -> return -> verify -> fulfil flow be exercised locally without
 * gateway credentials. It is never offered when the app runs in production, regardless of configuration.
 * It behaves like a real gateway: the outcome is decided "at the gateway" (the cache) and only learned through verify().
 */
class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return (string) config('payments.gateways.fake.label');
    }

    public function isConfigured(): bool
    {
        return (bool) config('payments.gateways.fake.enabled') && ! app()->isProduction();
    }

    public function initialize(PaymentRequest $request): InitializedPayment
    {
        $this->guard();
        Cache::put($this->key($request->reference), ['status' => 'pending'], now()->addDay());

        return new InitializedPayment(rtrim((string) config('app.frontend_url'), '/').'/dev/pay/'.$request->reference);
    }

    /** Called by the local simulation endpoint, standing in for what the customer does on the gateway's page. */
    public function simulate(string $reference, string $outcome, ?int $amountKobo = null): void
    {
        $this->guard();
        Cache::put($this->key($reference), ['status' => $outcome, 'amount' => $amountKobo], now()->addDay());
    }

    public function verify(Payment $payment): GatewayVerification
    {
        $this->guard();
        $state = Cache::get($this->key($payment->reference));
        if (! $state) {
            return new GatewayVerification(GatewayStatus::NotFound, rawStatus: 'not_found');
        }

        return new GatewayVerification(
            status: match ($state['status']) {
                'success' => GatewayStatus::Successful,
                'failed' => GatewayStatus::Failed,
                'abandoned' => GatewayStatus::Abandoned,
                default => GatewayStatus::Pending,
            },
            reference: $payment->reference,
            amountKobo: $state['amount'] ?? $payment->amount_kobo,
            currency: $payment->currency,
            transactionId: 'fake-'.substr(sha1($payment->reference), 0, 12),
            channel: 'simulator',
            rawStatus: (string) $state['status'],
        );
    }

    public function parseWebhook(string $rawBody, array $headers): ?WebhookEvent
    {
        $this->guard();
        $signature = $headers['x-fake-signature'] ?? '';
        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $rawBody, (string) config('app.key')), $signature)) {
            throw new InvalidWebhookSignature('Fake webhook signature mismatch');
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload) || ! isset($payload['reference'])) {
            return null;
        }

        return new WebhookEvent(WebhookEvent::KIND_PAYMENT, 'charge.simulated', sha1('fake|'.$payload['reference'].'|'.($payload['nonce'] ?? '')), reference: (string) $payload['reference']);
    }

    public function refund(Payment $payment, int $amountKobo, string $reason): RefundResult
    {
        $this->guard();

        return new RefundResult('fake-refund-'.substr(sha1($payment->reference.$amountKobo.microtime()), 0, 10), 'processed');
    }

    private function guard(): void
    {
        abort_if(app()->isProduction(), 404);
    }

    private function key(string $reference): string
    {
        return 'fakepay:'.$reference;
    }
}
