<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\OrderLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orders:expire-pending')]
#[Description('Expire unpaid orders whose stock reservation has run out and release their stock')]
class ExpirePendingOrders extends Command
{
    public function handle(OrderLifecycle $lifecycle): int
    {
        $expired = 0;

        Order::query()
            ->where('status', OrderStatus::PendingPayment)
            ->where('reservation_expires_at', '<=', now())
            ->chunkById(100, function ($orders) use ($lifecycle, &$expired): void {
                foreach ($orders as $order) {
                    $expired += $lifecycle->expire($order) ? 1 : 0;
                }
            });

        $this->info("Expired {$expired} order(s).");

        return self::SUCCESS;
    }
}
