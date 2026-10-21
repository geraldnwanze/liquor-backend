<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Notifications\NewsletterConfirmationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Double opt-in newsletter list. The consent wording shown at sign-up is stored with the timestamp. */
class NewsletterController extends Controller
{
    public const CONSENT_TEXT = 'I agree to receive news and offers by email and understand I can unsubscribe at any time.';

    /** Always answers the same way, so it cannot be used to find out who is already subscribed. */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'consent' => ['accepted'],
        ]);
        $email = mb_strtolower(trim($data['email']));

        $subscriber = NewsletterSubscriber::firstOrNew(['email' => $email]);
        $resendable = $subscriber->confirmation_sent_at === null || $subscriber->confirmation_sent_at->lt(now()->subHour());
        $needsConfirmation = ! $subscriber->exists || ! $subscriber->isActive();

        if ($needsConfirmation && $resendable) {
            if ($subscriber->unsubscribed_at) {
                $subscriber->confirmed_at = null;
                $subscriber->unsubscribed_at = null;
            }
            $subscriber->fill(['token' => $subscriber->token ?: Str::random(48), 'consented_at' => now(), 'consent_text' => self::CONSENT_TEXT, 'confirmation_sent_at' => now()])->save();
            $subscriber->notify(new NewsletterConfirmationNotification($subscriber));
        }

        return response()->json(['message' => 'Almost done: check your inbox and confirm your email address.'], 202);
    }

    public function confirm(Request $request): JsonResponse
    {
        $subscriber = $this->byToken($request);
        if (! $subscriber) {
            return response()->json(['message' => 'This link is not valid.', 'code' => 'invalid_newsletter_token'], 422);
        }
        $subscriber->update(['confirmed_at' => $subscriber->confirmed_at ?? now(), 'unsubscribed_at' => null]);

        return response()->json(['message' => "You're subscribed. Thank you!"]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $subscriber = $this->byToken($request);
        if (! $subscriber) {
            return response()->json(['message' => 'This link is not valid.', 'code' => 'invalid_newsletter_token'], 422);
        }
        $subscriber->update(['unsubscribed_at' => $subscriber->unsubscribed_at ?? now()]);

        return response()->json(['message' => "You've been unsubscribed and won't hear from us again."]);
    }

    private function byToken(Request $request): ?NewsletterSubscriber
    {
        $token = $request->validate(['token' => ['required', 'string', 'max:64']])['token'];

        return NewsletterSubscriber::where('token', $token)->first();
    }
}
