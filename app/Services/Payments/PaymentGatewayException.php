<?php

namespace App\Services\Payments;

use RuntimeException;

/** A gateway call failed. The message is for logs and staff; customers get a generic one. */
class PaymentGatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
