<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a customer what happened to their deletion request. Sent to the address they had, before it is erased. */
class AccountDeletionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $outcome, public ?string $note = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->outcome === 'completed') {
            return (new MailMessage)->subject('Your account has been deleted')
                ->line('As you asked, we have deleted your account and removed your personal details from it: your name, email, phone number, saved addresses, wishlist and reviews.')
                ->line('Records of past orders (including the delivery name and address on them) are kept for as long as tax and accounting rules require, and are not used for anything else.')
                ->line('You are no longer subscribed to our emails. This is the last message you will get from us about this account.');
        }

        return (new MailMessage)->subject('About your account deletion request')
            ->line('We were not able to delete your account yet.')
            ->line($this->note ?: 'Please contact us if you would like to know more.')
            ->line('You can ask again at any time from your account page.');
    }
}
