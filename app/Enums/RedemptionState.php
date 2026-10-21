<?php

namespace App\Enums;

enum RedemptionState: string
{
    /** The order is placed and awaiting payment: the use is held so limits cannot be beaten by parallel checkouts. */
    case Reserved = 'reserved';
    case Redeemed = 'redeemed';
    /** The order was cancelled or expired: the use is given back. */
    case Released = 'released';
}
