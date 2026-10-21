<?php

namespace App\Notifications;

use App\Models\NewsletterCampaign;
use App\Models\NewsletterDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Throwable;

/** One newsletter email. Every copy carries a working unsubscribe link, and the one-click header mail apps look for. */
class NewsletterCampaignNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public NewsletterCampaign $campaign, public string $unsubscribeUrl, public bool $isTest = false, public ?string $deliveryId = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** The queue gave up on this email: keep the reason (short, no addresses or credentials) for the delivery log. */
    public function failed(Throwable $e): void
    {
        if ($this->deliveryId) {
            NewsletterDelivery::whereKey($this->deliveryId)->update(['status' => NewsletterDelivery::FAILED, 'error' => Str::limit(class_basename($e).': '.preg_replace('/\S+@\S+/', '[email]', $e->getMessage()), 290, '')]);
        }
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(($this->isTest ? '[Test] ' : '').$this->campaign->subject);

        foreach (preg_split('/\R{2,}/', trim($this->campaign->body)) ?: [] as $paragraph) {
            $mail->line($paragraph);
        }

        return $mail
            ->line('You are receiving this because you subscribed on '.config('app.name').'. Unsubscribe any time: '.$this->unsubscribeUrl)
            ->withSymfonyMessage(fn ($message) => $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>'));
    }
}
