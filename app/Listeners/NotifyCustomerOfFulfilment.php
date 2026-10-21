<?php

namespace App\Listeners;

use App\Enums\OrderFulfilment;
use App\Events\FulfilmentChanged;
use App\Notifications\OrderFulfilmentNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class NotifyCustomerOfFulfilment implements ShouldQueue
{
    public function handle(FulfilmentChanged $event): void
    {
        // Only the moments a customer cares about: leaving, arriving, a failed attempt.
        if (! in_array($event->to, [OrderFulfilment::OutForDelivery, OrderFulfilment::Delivered, OrderFulfilment::DeliveryFailed], true)) {
            return;
        }

        Notification::route('mail', [$event->order->email => $event->order->customer_name])->notify(new OrderFulfilmentNotification($event->order, $event->to));
    }
}
