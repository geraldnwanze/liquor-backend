<?php

namespace App\Services\Payments\Data;

final readonly class PaymentRequest
{
    public function __construct(
        public string $reference,
        public int $amountKobo,
        public string $currency,
        public string $email,
        public string $name,
        public ?string $phone,
        public string $callbackUrl,
        public string $orderReference,
    ) {}
}
