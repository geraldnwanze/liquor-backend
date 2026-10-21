<?php

namespace App\Services\Payments\Data;

/** An authenticated, normalised webhook. Contains identifiers only: money is always re-verified via the API. */
final readonly class WebhookEvent
{
    public const KIND_PAYMENT = 'payment';

    public const KIND_REFUND = 'refund';

    public function __construct(
        public string $kind,
        public string $type,
        public string $dedupeKey,
        /** Our payment reference (Paystack reference / Flutterwave tx_ref), when the payload carries it. */
        public ?string $reference = null,
        public ?string $transactionId = null,
        public ?string $refundId = null,
        /** For refunds: pending | processed | failed. */
        public ?string $refundState = null,
        public ?int $refundAmountKobo = null,
    ) {}
}
