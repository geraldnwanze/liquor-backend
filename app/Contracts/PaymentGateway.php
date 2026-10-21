<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Services\Payments\Data\GatewayVerification;
use App\Services\Payments\Data\InitializedPayment;
use App\Services\Payments\Data\PaymentRequest;
use App\Services\Payments\Data\RefundResult;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\InvalidWebhookSignature;
use App\Services\Payments\PaymentGatewayException;

/**
 * One implementation per payment provider. Everything gateway-specific (URLs, field names, units, signature
 * schemes) lives behind this interface so checkout, orders and reconciliation never see it.
 */
interface PaymentGateway
{
    /** Stable identifier used in URLs, the database and settings: "paystack", "flutterwave". */
    public function name(): string;

    public function label(): string;

    /** True when credentials are present. A gateway without them is never offered at checkout. */
    public function isConfigured(): bool;

    /** @throws PaymentGatewayException when the gateway cannot be reached or refuses the request */
    public function initialize(PaymentRequest $request): InitializedPayment;

    /**
     * Asks the gateway (server to server, with the secret key) what happened. This is the only source of truth for
     * whether money arrived: redirect parameters and webhook bodies are never trusted on their own.
     *
     * @throws PaymentGatewayException on network or server errors (so callers can retry later)
     */
    public function verify(Payment $payment): GatewayVerification;

    /**
     * Authenticates a webhook delivery with the gateway's official mechanism and normalises it.
     * Returns null for event types we do not act on.
     *
     * @param  array<string, string>  $headers  lower-cased header names
     *
     * @throws InvalidWebhookSignature when authentication fails (nothing may be processed)
     */
    public function parseWebhook(string $rawBody, array $headers): ?WebhookEvent;

    /** @throws PaymentGatewayException */
    public function refund(Payment $payment, int $amountKobo, string $reason): RefundResult;
}
