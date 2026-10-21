<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Order confirmation. Contains the order reference and totals only: never payment or card details. */
class OrderPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing('items');
        $mail = (new MailMessage)
            ->subject("Order {$order->reference} confirmed")
            ->greeting("Thank you, {$order->customer_name}!")
            ->line("We've received your payment of ".Money::format($order->total_kobo).' for order '.$order->reference.'.');

        foreach ($order->items as $item) {
            $mail->line("{$item->quantity} × {$item->product_name} ({$item->variant_name})");
        }

        return $mail
            ->line("Delivering to {$order->delivery_address}, {$order->delivery_city}, {$order->delivery_state}.")
            ->action('View your order', rtrim((string) config('app.frontend_url'), '/').'/orders/'.$order->reference);
    }
}
