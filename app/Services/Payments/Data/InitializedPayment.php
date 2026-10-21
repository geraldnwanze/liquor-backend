<?php

namespace App\Services\Payments\Data;

final readonly class InitializedPayment
{
    public function __construct(public string $authorizationUrl) {}
}
