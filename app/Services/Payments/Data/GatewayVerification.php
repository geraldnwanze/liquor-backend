<?php

namespace App\Services\Payments\Data;

use App\Enums\GatewayStatus;

/** A gateway's answer about one transaction, with amounts normalised to integer kobo. */
final readonly class GatewayVerification
{
    public function __construct(
        public GatewayStatus $status,
        public ?string $reference = null,
        public ?int $amountKobo = null,
        public ?string $currency = null,
        public ?string $transactionId = null,
        public ?string $channel = null,
        public ?string $rawStatus = null,
        public ?string $message = null,
    ) {}
}
