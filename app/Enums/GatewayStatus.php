<?php

namespace App\Enums;

/** What a gateway told us about a transaction, normalised across gateways. */
enum GatewayStatus: string
{
    case Successful = 'successful';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case Pending = 'pending';
    /** The gateway has no record of this reference (the customer never reached the payment page). */
    case NotFound = 'not_found';
}
