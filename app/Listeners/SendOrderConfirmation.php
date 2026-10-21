<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Notifications\OrderPaidNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class SendOrderConfirmation implements ShouldQueue
{
    public function handle(OrderPaid $event): void
    {
        Notification::route('mail', [$event->order->email => $event->order->customer_name])->notify(new OrderPaidNotification($event->order));
    }
}
