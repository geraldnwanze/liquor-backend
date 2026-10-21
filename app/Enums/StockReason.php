<?php

namespace App\Enums;

enum StockReason: string
{
    case Received = 'received';
    case Correction = 'correction';
    case Damaged = 'damaged';
    case Returned = 'returned';
    case Sale = 'sale';
    case Initial = 'initial';
    /** A paid order cancelled before it left: the units go back on the shelf. */
    case OrderCancelled = 'order_cancelled';
}
