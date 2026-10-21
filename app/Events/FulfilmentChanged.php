<?php

namespace App\Events;

use App\Enums\OrderFulfilment;
use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class FulfilmentChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Order $order, public OrderFulfilment $to) {}
}
