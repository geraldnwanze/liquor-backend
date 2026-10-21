<?php

namespace App\Notifications;

use App\Enums\OrderFulfilment;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Delivery updates by email. Carrier name and tracking reference only: never the carrier's phone or staff notes. */
class OrderFulfilmentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order, public OrderFulfilment $to) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing('shipment');
        $mail = (new MailMessage)->greeting("Hello {$order->customer_name},");

        match ($this->to) {
            OrderFulfilment::OutForDelivery => $mail->subject("Order {$order->reference} is on its way")
                ->line("Your order {$order->reference} is out for delivery to {$order->delivery_address}, {$order->delivery_city}."),
            OrderFulfilment::Delivered => $mail->subject("Order {$order->reference} was delivered")
                ->line("Your order {$order->reference} has been delivered. Thank you for shopping with us. Please drink responsibly."),
            default => $mail->subject("We couldn't deliver order {$order->reference}")
                ->line("We tried to deliver order {$order->reference} but couldn't complete the delivery. We'll contact you to arrange another attempt."),
        };

        if ($order->shipment?->carrier) {
            $mail->line('Delivered by: '.$order->shipment->carrier.($order->shipment->tracking_reference ? " (tracking: {$order->shipment->tracking_reference})" : ''));
        }

        return $mail->action('Track your order', rtrim((string) config('app.frontend_url'), '/').'/orders/'.$order->reference);
    }
}
