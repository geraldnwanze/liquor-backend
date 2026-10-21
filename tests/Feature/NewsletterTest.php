<?php

use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Notifications\NewsletterConfirmationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => Notification::fake());

function subscribe(string $email = 'Ada@Example.com', array $over = []): TestResponse
{
    return guest()->postJson('/api/v1/newsletter', ['email' => $email, 'consent' => true, ...$over]);
}

it('asks for confirmation and records the consent wording', function () {
    subscribe()->assertStatus(202);

    $s = NewsletterSubscriber::first();
    expect($s->email)->toBe('ada@example.com')->and($s->confirmed_at)->toBeNull()->and($s->consent_text)->toContain('unsubscribe');
    Notification::assertSentTo($s, NewsletterConfirmationNotification::class);
});

it('refuses to sign anyone up without explicit consent or a valid email', function () {
    subscribe(over: ['consent' => false])->assertJsonValidationErrors('consent');
    guest()->postJson('/api/v1/newsletter', ['email' => 'ada@example.com'])->assertJsonValidationErrors('consent');
    subscribe('not-an-email')->assertJsonValidationErrors('email');
    expect(NewsletterSubscriber::count())->toBe(0);
    Notification::assertNothingSent();
});

it('confirms through the emailed link only, then counts as subscribed', function () {
    subscribe();
    $s = NewsletterSubscriber::first();

    guest()->postJson('/api/v1/newsletter/confirm', ['token' => 'wrong'])->assertUnprocessable()->assertJsonPath('code', 'invalid_newsletter_token');
    expect($s->refresh()->isActive())->toBeFalse();

    guest()->postJson('/api/v1/newsletter/confirm', ['token' => $s->token])->assertOk();
    expect($s->refresh()->isActive())->toBeTrue();
});

it('answers the same for an already-subscribed address and does not email them again', function () {
    NewsletterSubscriber::factory()->confirmed()->create(['email' => 'ada@example.com']);

    subscribe()->assertStatus(202)->assertJsonPath('message', 'Almost done: check your inbox and confirm your email address.');
    Notification::assertNothingSent();
});

it('does not resend confirmation more than once an hour', function () {
    subscribe();
    subscribe();
    Notification::assertSentTimes(NewsletterConfirmationNotification::class, 1);

    $this->travel(2)->hours();
    subscribe();
    Notification::assertSentTimes(NewsletterConfirmationNotification::class, 2);
});

it('unsubscribes, and lets someone sign up again later', function () {
    $s = NewsletterSubscriber::factory()->confirmed()->create(['email' => 'ada@example.com']);

    guest()->postJson('/api/v1/newsletter/unsubscribe', ['token' => $s->token])->assertOk();
    expect($s->refresh()->isActive())->toBeFalse();

    subscribe()->assertStatus(202);
    expect($s->refresh())->confirmed_at->toBeNull()->unsubscribed_at->toBeNull();
    Notification::assertSentTo($s, NewsletterConfirmationNotification::class);
});

it('rate-limits sign-ups from one address', function () {
    foreach (range(1, 5) as $i) {
        subscribe("p{$i}@example.com")->assertStatus(202);
    }
    subscribe('p6@example.com')->assertStatus(429);
});

it('shows marketing staff the active list and keeps it from others', function () {
    NewsletterSubscriber::factory()->confirmed()->create(['email' => 'in@example.com']);
    NewsletterSubscriber::factory()->create(['email' => 'waiting@example.com']);
    NewsletterSubscriber::factory()->confirmed()->create(['email' => 'out@example.com', 'unsubscribed_at' => now()]);

    $res = asStaff(orderStaff('marketing-manager'))->getJson('/api/v1/admin/newsletter')->assertOk();
    expect($res->json('data.*.email'))->toBe(['in@example.com'])
        ->and($res->json('summary'))->toBe(['active' => 1, 'awaiting_confirmation' => 1, 'unsubscribed' => 1]);

    asStaff(orderStaff('order-manager'))->getJson('/api/v1/admin/newsletter')->assertForbidden();
    asStaff(User::factory()->create())->getJson('/api/v1/admin/newsletter')->assertForbidden();
});
