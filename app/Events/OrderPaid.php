<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Raised once, after the transaction that marked an order paid has committed. */
class OrderPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Order $order) {}
}
