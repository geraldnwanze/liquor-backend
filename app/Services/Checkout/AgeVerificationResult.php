<?php

namespace App\Services\Checkout;

final readonly class AgeVerificationResult
{
    public function __construct(public bool $passed, public string $method, public ?string $reason = null) {}
}
