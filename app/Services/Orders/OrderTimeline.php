<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;

/** Writes the order's history. Every status change leaves a row here: who, what, when. */
class OrderTimeline
{
    public function record(Order $order, string $type, ?string $to = null, ?string $from = null, ?string $note = null, bool $customerVisible = false, ?User $by = null): OrderEvent
    {
        return OrderEvent::create([
            'order_id' => $order->id,
            'user_id' => $by?->id,
            'type' => $type,
            'from_value' => $from,
            'to_value' => $to,
            'note' => $note === null ? null : mb_substr($note, 0, 1000),
            'customer_visible' => $customerVisible,
        ]);
    }
}
