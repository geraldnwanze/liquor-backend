<?php

use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

function homepagePayload(array $over = []): array
{
    return [
        'announcement_text' => 'Free delivery this weekend', 'announcement_link' => '/search',
        'hero_eyebrow' => 'New in', 'hero_title' => 'Pour something', 'hero_title_emphasis' => 'special', 'hero_text' => 'Hand-picked bottles.',
        'hero_primary_label' => 'Shop now', 'hero_primary_href' => '/search', 'hero_secondary_label' => 'Gin', 'hero_secondary_href' => 'https://example.com/gin',
        'show_best_sellers' => true, 'show_new_arrivals' => true, 'show_on_sale' => false, 'show_how_it_works' => true, 'show_promises' => true,
        ...$over,
    ];
}

it('saves the wording and exposes it publicly, with blanks meaning "use the built-in text"', function () {
    guest()->getJson('/api/v1/settings/public')->assertJsonPath('data.homepage.hero_title', null)->assertJsonPath('data.homepage.show_on_sale', true);

    asStaff(orderStaff('content-editor'))->putJson('/api/v1/admin/homepage', homepagePayload())->assertOk()->assertJsonPath('data.hero_title', 'Pour something');

    guest()->getJson('/api/v1/settings/public')
        ->assertJsonPath('data.homepage.announcement_text', 'Free delivery this weekend')
        ->assertJsonPath('data.homepage.hero_secondary_href', 'https://example.com/gin')
        ->assertJsonPath('data.homepage.show_on_sale', false);

    asStaff(orderStaff('content-editor'))->putJson('/api/v1/admin/homepage', homepagePayload(['hero_title' => '', 'announcement_text' => '', 'announcement_link' => '']))->assertOk();
    guest()->getJson('/api/v1/settings/public')->assertJsonPath('data.homepage.hero_title', null)->assertJsonPath('data.homepage.announcement_text', null);
    expect(AuditLog::where('action', 'homepage.updated')->count())->toBe(2);
});

it('refuses scripts and malformed links, and half-filled buttons', function () {
    $staff = orderStaff('content-editor');
    foreach (['javascript:alert(1)', '//evil.test', 'http://insecure.test', 'data:text/html,x', 'search'] as $bad) {
        asStaff($staff)->putJson('/api/v1/admin/homepage', homepagePayload(['hero_primary_href' => $bad]))->assertJsonValidationErrors('hero_primary_href');
    }
    asStaff($staff)->putJson('/api/v1/admin/homepage', homepagePayload(['hero_primary_href' => '']))->assertJsonValidationErrors('hero_primary_href');
    asStaff($staff)->putJson('/api/v1/admin/homepage', homepagePayload(['hero_secondary_label' => '']))->assertJsonValidationErrors('hero_secondary_label');
    asStaff($staff)->putJson('/api/v1/admin/homepage', homepagePayload(['hero_title' => str_repeat('x', 121)]))->assertJsonValidationErrors('hero_title');

    asStaff($staff)->putJson('/api/v1/admin/homepage', homepagePayload(['hero_title' => '<script>alert(1)</script>Welcome']))->assertOk();
    expect(guest()->getJson('/api/v1/settings/public')->json('data.homepage.hero_title'))->not->toContain('<');
});

it('uploads, replaces and removes the hero picture, keeping the old file only until replaced', function () {
    $staff = orderStaff('content-editor');
    $up = fn ($name) => asStaff($staff)->post('/api/v1/admin/homepage/hero-image', ['file' => UploadedFile::fake()->image($name, 1600, 800)], ['Accept' => 'application/json'])->assertOk();

    $url = $up('a.png')->json('data.hero_image_url');
    expect($url)->toBeString();
    guest()->getJson('/api/v1/settings/public')->assertJsonPath('data.homepage.hero_image_url', $url);
    $first = Storage::disk('public')->allFiles('homepage');
    expect($first)->toHaveCount(1);

    $up('b.png');
    $second = Storage::disk('public')->allFiles('homepage');
    expect($second)->toHaveCount(1)->and($second)->not->toBe($first);

    asStaff($staff)->deleteJson('/api/v1/admin/homepage/hero-image')->assertNoContent();
    expect(Storage::disk('public')->allFiles('homepage'))->toBe([]);
    guest()->getJson('/api/v1/settings/public')->assertJsonPath('data.homepage.hero_image_url', null);
});

it('refuses unsuitable hero pictures', function () {
    $staff = orderStaff('content-editor');
    foreach ([UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml'), UploadedFile::fake()->image('small.png', 300, 200), UploadedFile::fake()->image('big.png', 1600, 800)->size(6000)] as $file) {
        asStaff($staff)->post('/api/v1/admin/homepage/hero-image', ['file' => $file], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
    }
    expect(Storage::disk('public')->allFiles('homepage'))->toBe([]);
});

it('is limited to roles that manage content', function () {
    foreach (['order-manager', 'product-manager', 'customer-support'] as $role) {
        asStaff(orderStaff($role))->getJson('/api/v1/admin/homepage')->assertForbidden();
        asStaff(orderStaff($role))->putJson('/api/v1/admin/homepage', homepagePayload())->assertForbidden();
    }
    foreach (['content-editor', 'marketing-manager', 'store-manager'] as $role) {
        asStaff(orderStaff($role))->getJson('/api/v1/admin/homepage')->assertOk();
    }
    guest()->putJson('/api/v1/admin/homepage', homepagePayload())->assertUnauthorized();
});
