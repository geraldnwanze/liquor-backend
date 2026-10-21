<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterDelivery;
use App\Models\NewsletterSubscriber;
use App\Notifications\NewsletterCampaignNotification;
use App\Services\Administration\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/** Newsletter campaigns: write a draft, send yourself a test, then send to everyone currently subscribed. */
class NewsletterCampaignController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => NewsletterCampaign::latest('id')->limit(100)->get()->map(fn ($c) => $this->present($c)),
            'active_subscribers' => $this->active()->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $campaign = NewsletterCampaign::create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->audit->record('newsletter_campaign.created', $campaign, ['subject' => $campaign->subject]);

        return response()->json(['data' => $this->present($campaign)], 201);
    }

    public function update(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        if ($blocked = $this->lockedResponse($campaign)) {
            return $blocked;
        }
        $campaign->update($this->validated($request));
        $this->audit->record('newsletter_campaign.updated', $campaign);

        return response()->json(['data' => $this->present($campaign)]);
    }

    public function destroy(NewsletterCampaign $campaign): JsonResponse
    {
        if ($blocked = $this->lockedResponse($campaign)) {
            return $blocked;
        }
        $this->audit->record('newsletter_campaign.deleted', $campaign, ['subject' => $campaign->subject]);
        $campaign->delete();

        return response()->json(null, 204);
    }

    /** Sends the draft to the signed-in staff member only, so they can see what customers will get. */
    public function test(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        Notification::route('mail', $request->user()->email)->notify(new NewsletterCampaignNotification($campaign, $this->unsubscribeUrl('test'), isTest: true));

        return response()->json(['message' => 'A test email is on its way to '.$request->user()->email.'.']);
    }

    /**
     * Sends to every confirmed, still-subscribed address. The caller must repeat how many people they expect, so a
     * list that changed while the page was open can't be mailed by surprise. Sending is claimed atomically: pressing
     * the button twice cannot mail anyone twice.
     */
    public function send(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        $expected = $request->validate(['expected_recipients' => ['required', 'integer', 'min:0']])['expected_recipients'];
        $count = $this->active()->count();
        if ($count === 0) {
            return response()->json(['message' => 'There is nobody subscribed yet.', 'code' => 'no_recipients'], 422);
        }
        if ($expected !== $count) {
            return response()->json(['message' => "The list now has {$count} subscribers, not {$expected}. Check and try again.", 'code' => 'recipients_changed'], 409);
        }

        $claimed = NewsletterCampaign::whereKey($campaign->id)->where('status', NewsletterCampaign::DRAFT)
            ->update(['status' => NewsletterCampaign::SENT, 'sent_at' => now(), 'sent_by' => $request->user()->id, 'recipient_count' => $count]);
        if (! $claimed) {
            return response()->json(['message' => 'This campaign has already been sent.', 'code' => 'campaign_sent'], 409);
        }

        $campaign->refresh();
        $this->active()->each(fn (NewsletterSubscriber $s) => $this->deliver($campaign, $s));
        $this->audit->record('newsletter_campaign.sent', $campaign, ['recipients' => $count]);

        return response()->json(['data' => $this->present($campaign->refresh())]);
    }

    /** Failed emails only (never the ones that went), for when the mail service was down. */
    public function retryFailed(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        $failed = $campaign->deliveries()->where('status', NewsletterDelivery::FAILED)->get();
        foreach ($failed as $delivery) {
            $subscriber = NewsletterSubscriber::find($delivery->subscriber_id);
            if ($subscriber?->isActive()) {
                $delivery->update(['status' => NewsletterDelivery::QUEUED, 'error' => null]);
                $subscriber->notify(new NewsletterCampaignNotification($campaign, $this->unsubscribeUrl($subscriber->token), deliveryId: $delivery->id));
            }
        }
        $this->audit->record('newsletter_campaign.retried', $campaign, ['count' => $failed->count()]);

        return response()->json(['data' => $this->present($campaign->refresh())]);
    }

    public function deliveries(Request $request, NewsletterCampaign $campaign): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:queued,sent,failed']])['status'] ?? null;
        $rows = $campaign->deliveries()->when($status, fn ($q) => $q->where('status', $status))->orderBy('email')->limit(200)->get(['email', 'status', 'error', 'sent_at']);

        return response()->json(['data' => $rows, 'summary' => $this->counts($campaign)]);
    }

    private function deliver(NewsletterCampaign $campaign, NewsletterSubscriber $subscriber): void
    {
        $delivery = NewsletterDelivery::firstOrCreate(['campaign_id' => $campaign->id, 'subscriber_id' => $subscriber->id], ['email' => $subscriber->email]);
        $subscriber->notify(new NewsletterCampaignNotification($campaign, $this->unsubscribeUrl($subscriber->token), deliveryId: $delivery->id));
    }

    /** @return array{queued: int, sent: int, failed: int} */
    private function counts(NewsletterCampaign $campaign): array
    {
        $by = $campaign->deliveries()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ['queued' => (int) ($by[NewsletterDelivery::QUEUED] ?? 0), 'sent' => (int) ($by[NewsletterDelivery::SENT] ?? 0), 'failed' => (int) ($by[NewsletterDelivery::FAILED] ?? 0)];
    }

    /** @return Builder<NewsletterSubscriber> */
    private function active()
    {
        return NewsletterSubscriber::whereNotNull('confirmed_at')->whereNull('unsubscribed_at');
    }

    /** @return array{subject: string, body: string} */
    private function validated(Request $request): array
    {
        return $request->validate(['subject' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'min:10', 'max:10000']]);
    }

    private function lockedResponse(NewsletterCampaign $campaign): ?JsonResponse
    {
        return $campaign->status === NewsletterCampaign::DRAFT
            ? null
            : response()->json(['message' => 'A sent campaign can no longer be changed.', 'code' => 'campaign_sent'], 409);
    }

    private function unsubscribeUrl(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/newsletter/unsubscribe?token='.$token;
    }

    /** @return array<string, mixed> */
    private function present(NewsletterCampaign $c): array
    {
        return ['id' => $c->id, 'subject' => $c->subject, 'body' => $c->body, 'status' => $c->status, 'sent_at' => $c->sent_at?->toIso8601String(), 'recipient_count' => $c->recipient_count, 'delivery' => $c->status === NewsletterCampaign::SENT ? $this->counts($c) : null];
    }
}
