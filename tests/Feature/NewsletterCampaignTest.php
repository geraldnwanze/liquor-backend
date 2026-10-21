<?php

use App\Models\NewsletterCampaign;
use App\Models\NewsletterDelivery;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Notifications\NewsletterCampaignNotification;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

function marketer(): User
{
    return orderStaff('marketing-manager');
}

function campaignPayload(array $over = []): array
{
    return ['subject' => 'Weekend offers', 'body' => "Hello!\n\nTen percent off all gin this weekend.", ...$over];
}

it('creates, edits and deletes drafts, and locks a campaign once sent', function () {
    $m = marketer();
    $id = asStaff($m)->postJson('/api/v1/admin/newsletter/campaigns', campaignPayload())->assertCreated()->json('data.id');

    asStaff($m)->putJson("/api/v1/admin/newsletter/campaigns/{$id}", campaignPayload(['subject' => 'Changed']))->assertOk();
    expect(NewsletterCampaign::find($id)->subject)->toBe('Changed');
    asStaff($m)->postJson('/api/v1/admin/newsletter/campaigns', ['subject' => '', 'body' => 'short'])->assertJsonValidationErrors(['subject', 'body']);

    NewsletterSubscriber::factory()->confirmed()->create();
    asStaff($m)->postJson("/api/v1/admin/newsletter/campaigns/{$id}/send", ['expected_recipients' => 1])->assertOk();
    asStaff($m)->putJson("/api/v1/admin/newsletter/campaigns/{$id}", campaignPayload())->assertConflict();
    asStaff($m)->deleteJson("/api/v1/admin/newsletter/campaigns/{$id}")->assertConflict();

    $draft = NewsletterCampaign::factory()->create();
    asStaff($m)->deleteJson("/api/v1/admin/newsletter/campaigns/{$draft->id}")->assertNoContent();
});

it('sends only to confirmed, still-subscribed people, each with their own unsubscribe link', function () {
    $in = NewsletterSubscriber::factory()->confirmed()->create();
    NewsletterSubscriber::factory()->create(); // never confirmed
    NewsletterSubscriber::factory()->confirmed()->create(['unsubscribed_at' => now()]);
    $campaign = NewsletterCampaign::factory()->create();

    asStaff(marketer())->postJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/send", ['expected_recipients' => 1])->assertOk()->assertJsonPath('data.status', 'sent');

    Notification::assertSentTimes(NewsletterCampaignNotification::class, 1);
    Notification::assertSentTo($in, NewsletterCampaignNotification::class, fn ($n) => str_contains($n->unsubscribeUrl, $in->token) && ! $n->isTest);
    expect($campaign->refresh())->recipient_count->toBe(1)->sent_at->not->toBeNull();
});

it('will not send twice, to nobody, or to a list that changed under the sender', function () {
    $m = marketer();
    $campaign = NewsletterCampaign::factory()->create();
    asStaff($m)->postJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/send", ['expected_recipients' => 0])->assertUnprocessable()->assertJsonPath('code', 'no_recipients');

    NewsletterSubscriber::factory()->confirmed()->count(2)->create();
    asStaff($m)->postJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/send", ['expected_recipients' => 1])->assertConflict()->assertJsonPath('code', 'recipients_changed');
    asStaff($m)->postJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/send")->assertJsonValidationErrors('expected_recipients');
    Notification::assertNothingSent();

    asStaff($m)->postJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/send", ['expected_recipients' => 2])->assertOk();
    asStaff($m)->postJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/send", ['expected_recipients' => 2])->assertConflict()->assertJsonPath('code', 'campaign_sent');
    Notification::assertSentTimes(NewsletterCampaignNotification::class, 2);
});

it('sends a test copy to the signed-in staff member only', function () {
    NewsletterSubscriber::factory()->confirmed()->count(3)->create();
    $m = marketer();
    $campaign = NewsletterCampaign::factory()->create();

    asStaff($m)->postJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/test")->assertOk();

    Notification::assertSentOnDemand(NewsletterCampaignNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $m->email && $n->isTest);
    Notification::assertSentTimes(NewsletterCampaignNotification::class, 1);
    expect($campaign->refresh()->status)->toBe('draft');
});

it('renders the body as paragraphs with the unsubscribe line and header', function () {
    $campaign = NewsletterCampaign::factory()->create(['body' => "First paragraph.\n\nSecond paragraph."]);
    $mail = (new NewsletterCampaignNotification($campaign, 'https://shop.test/newsletter/unsubscribe?token=abc'))->toMail(new stdClass);

    expect($mail->introLines)->toContain('First paragraph.', 'Second paragraph.')
        ->and(collect($mail->introLines)->last())->toContain('https://shop.test/newsletter/unsubscribe?token=abc');
});

it('is limited to marketing staff', function () {
    $campaign = NewsletterCampaign::factory()->create();
    foreach ([orderStaff('order-manager'), User::factory()->create()] as $who) {
        asStaff($who)->getJson('/api/v1/admin/newsletter/campaigns')->assertForbidden();
        asStaff($who)->postJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/send", ['expected_recipients' => 0])->assertForbidden();
    }
    guest()->getJson('/api/v1/admin/newsletter/campaigns')->assertUnauthorized();
});

describe('the delivery log', function () {
    beforeEach(fn () => Notification::swap(new ChannelManager(app()))); // real sending, through the array mailer

    it('records one delivery per person and marks each sent once the mail service accepts it', function () {
        $a = NewsletterSubscriber::factory()->confirmed()->create(['email' => 'a@example.com']);
        NewsletterSubscriber::factory()->confirmed()->create(['email' => 'b@example.com']);
        $campaign = NewsletterCampaign::factory()->create();

        $res = asStaff(marketer())->postJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/send", ['expected_recipients' => 2])->assertOk();

        expect(NewsletterDelivery::pluck('status')->unique()->all())->toBe(['sent'])
            ->and(NewsletterDelivery::whereNotNull('sent_at')->count())->toBe(2)
            ->and($res->json('data.delivery'))->toBe(['queued' => 0, 'sent' => 2, 'failed' => 0])
            ->and(NewsletterDelivery::where('subscriber_id', $a->id)->value('email'))->toBe('a@example.com');
    });

    it('keeps the reason when an email fails, without leaking the address', function () {
        $sub = NewsletterSubscriber::factory()->confirmed()->create(['email' => 'down@example.com']);
        $campaign = NewsletterCampaign::factory()->create();
        $delivery = NewsletterDelivery::create(['campaign_id' => $campaign->id, 'subscriber_id' => $sub->id, 'email' => $sub->email]);

        (new NewsletterCampaignNotification($campaign, 'https://x.test/u', deliveryId: $delivery->id))->failed(new RuntimeException('Cannot reach down@example.com on smtp'));

        $delivery->refresh();
        expect($delivery->status)->toBe('failed')->and($delivery->error)->toContain('RuntimeException')->not->toContain('down@example.com')->and(strlen($delivery->error))->toBeLessThan(300);
    });

    it('lists deliveries by status and shows who can see them', function () {
        $campaign = NewsletterCampaign::factory()->create(['status' => 'sent']);
        foreach ([['a@x.test', 'sent'], ['b@x.test', 'failed'], ['c@x.test', 'queued']] as [$email, $status]) {
            NewsletterDelivery::create(['campaign_id' => $campaign->id, 'email' => $email, 'status' => $status]);
        }

        $failed = asStaff(marketer())->getJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/deliveries?status=failed")->assertOk();
        expect($failed->json('data.*.email'))->toBe(['b@x.test'])->and($failed->json('summary'))->toBe(['queued' => 1, 'sent' => 1, 'failed' => 1]);
        asStaff(orderStaff('order-manager'))->getJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/deliveries")->assertForbidden();
    });

    it('retries only the failed emails, and only for people still subscribed', function () {
        Notification::fake();
        $campaign = NewsletterCampaign::factory()->create(['status' => 'sent']);
        $still = NewsletterSubscriber::factory()->confirmed()->create();
        $left = NewsletterSubscriber::factory()->confirmed()->create(['unsubscribed_at' => now()]);
        $done = NewsletterSubscriber::factory()->confirmed()->create();
        $mk = fn ($s, $status) => NewsletterDelivery::create(['campaign_id' => $campaign->id, 'subscriber_id' => $s->id, 'email' => $s->email, 'status' => $status, 'error' => $status === 'failed' ? 'boom' : null]);
        $retryable = $mk($still, 'failed');
        $mk($left, 'failed');
        $mk($done, 'sent');

        asStaff(marketer())->postJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/retry-failed")->assertOk();

        Notification::assertSentTimes(NewsletterCampaignNotification::class, 1);
        Notification::assertSentTo($still, NewsletterCampaignNotification::class, fn ($n) => $n->deliveryId === $retryable->id);
        expect($retryable->refresh())->status->toBe('queued')->error->toBeNull();
    });

    it('keeps test sends out of the log', function () {
        $campaign = NewsletterCampaign::factory()->create();

        asStaff(marketer())->postJson("/api/v1/admin/newsletter/campaigns/{$campaign->id}/test")->assertOk();

        expect(NewsletterDelivery::count())->toBe(0);
    });
});
