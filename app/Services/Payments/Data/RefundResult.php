<?php

namespace App\Services\Payments\Data;

final readonly class RefundResult
{
    public function __construct(
        public string $gatewayRefundId,
        /** pending | processed (failed refunds throw instead) */
        public string $state,
    ) {}
}
