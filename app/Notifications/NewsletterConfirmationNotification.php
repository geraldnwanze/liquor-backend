<?php

namespace App\Notifications;

use App\Models\NewsletterSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Asks the address owner to confirm. Nothing is sent to them until they do. */
class NewsletterConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public NewsletterSubscriber $subscriber) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = rtrim((string) config('app.frontend_url'), '/').'/newsletter';

        return (new MailMessage)
            ->subject('Confirm your subscription')
            ->line('Someone, hopefully you, asked to receive news and offers from '.config('app.name').' at this address.')
            ->action('Confirm subscription', $base.'/confirm?token='.$this->subscriber->token)
            ->line('If this was not you, ignore this email and nothing will be sent. You can also opt out at any time: '.$base.'/unsubscribe?token='.$this->subscriber->token);
    }
}
