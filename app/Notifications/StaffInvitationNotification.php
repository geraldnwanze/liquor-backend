<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a new team member how to set their password. The link is a normal single-use password-reset link (so it
 * expires, and setting a password signs out any other session); it never contains a password.
 */
class StaffInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $roleNames */
    public function __construct(public string $token, public array $roleNames, public string $invitedBy) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);
        $url = rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject("You've been invited to the ".config('app.name').' team')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->invitedBy} added you to the ".config('app.name').' admin as: '.implode(', ', $this->roleNames).'.')
            ->line('Set a password to sign in.')
            ->action('Set your password', $url)
            ->line("This link works once and expires in {$minutes} minutes. If it has expired, ask for a new invitation.")
            ->line("If you weren't expecting this, you can ignore this email.");
    }
}
