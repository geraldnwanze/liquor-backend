<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Administration\BusinessLogo;
use App\Services\Administration\SettingsRepository;
use App\Services\Orders\OrderLifecycle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => Storage::fake('public'));

function logoUpload(UploadedFile $file, ?User $as = null): TestResponse
{
    return asStaff($as ?? orderStaff('store-manager'))->post('/api/v1/admin/settings/logo', ['file' => $file], ['Accept' => 'application/json']);
}

it('stores the logo as a re-encoded WebP and shows it in public and admin settings', function () {
    $res = logoUpload(UploadedFile::fake()->image('logo.png', 1200, 600))->assertOk();
    $url = $res->json('data.logo_url');

    expect($url)->toBeString();
    $path = app(SettingsRepository::class)->get('business', 'logo_path');
    Storage::disk('public')->assertExists($path);
    $info = getimagesizefromstring(Storage::disk('public')->get($path));
    expect($info['mime'])->toBe('image/webp')->and(max($info[0], $info[1]))->toBe(800);

    guest()->getJson('/api/v1/settings/public')->assertJsonPath('data.business.logo_url', $url);
    asStaff(orderStaff('store-manager'))->getJson('/api/v1/admin/settings')->assertJsonPath('data.business.logo_url', $url);
    expect(AuditLog::where('action', 'settings.logo_updated')->count())->toBe(1);
});

it('removes the old file when the logo is replaced', function () {
    logoUpload(UploadedFile::fake()->image('a.png', 300, 300))->assertOk();
    $first = app(SettingsRepository::class)->get('business', 'logo_path');

    logoUpload(UploadedFile::fake()->image('b.png', 300, 300))->assertOk();
    $second = app(SettingsRepository::class)->get('business', 'logo_path');

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

it('exposes the business name only when staff set one, so the shop can keep its own default', function () {
    guest()->getJson('/api/v1/settings/public')->assertJsonPath('data.business.name', null);

    app(SettingsRepository::class)->set('business', 'name', 'Demo Cellars Ltd');
    guest()->getJson('/api/v1/settings/public')->assertJsonPath('data.business.name', 'Demo Cellars Ltd');
});

it('removes the logo and its file', function () {
    logoUpload(UploadedFile::fake()->image('a.png', 300, 300))->assertOk();
    $path = app(SettingsRepository::class)->get('business', 'logo_path');

    asStaff(orderStaff('store-manager'))->deleteJson('/api/v1/admin/settings/logo')->assertNoContent();

    Storage::disk('public')->assertMissing($path);
    guest()->getJson('/api/v1/settings/public')->assertJsonPath('data.business.logo_url', null);
    expect(AuditLog::where('action', 'settings.logo_removed')->count())->toBe(1);
});

it('refuses files that are not safe, sized or shaped like a logo', function () {
    $staff = orderStaff('store-manager');
    logoUpload(UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'), $staff)->assertJsonValidationErrors('file');
    logoUpload(UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'), $staff)->assertJsonValidationErrors('file');
    logoUpload(UploadedFile::fake()->image('tiny.png', 50, 50), $staff)->assertJsonValidationErrors('file');
    logoUpload(UploadedFile::fake()->image('big.png', 1000, 1000)->size(3000), $staff)->assertJsonValidationErrors('file');

    expect(app(SettingsRepository::class)->get('business', 'logo_path'))->toBeNull();
});

it('keeps saving the text settings from touching the logo', function () {
    $staff = orderStaff('store-manager');
    logoUpload(UploadedFile::fake()->image('a.png', 300, 300), $staff)->assertOk();
    $before = app(SettingsRepository::class)->get('business', 'logo_path');

    asStaff($staff)->putJson('/api/v1/admin/settings', [
        'business' => ['name' => 'Demo Cellars'],
        'commerce' => ['minimum_age' => 18, 'reservation_minutes' => 30, 'max_quantity_per_line' => 12, 'max_units_per_order' => 48],
    ])->assertOk()->assertJsonPath('data.business.logo_url', fn ($u) => is_string($u));

    expect(app(SettingsRepository::class)->get('business', 'logo_path'))->toBe($before);
});

it('is limited to roles that manage settings', function () {
    foreach (['order-manager', 'content-editor', 'customer-support', 'marketing-manager', 'product-manager'] as $role) {
        logoUpload(UploadedFile::fake()->image('a.png', 300, 300), orderStaff($role))->assertForbidden();
    }
    asStaff(orderStaff('order-manager'))->deleteJson('/api/v1/admin/settings/logo')->assertForbidden();
    guest()->post('/api/v1/admin/settings/logo', [], ['Accept' => 'application/json'])->assertUnauthorized();
    expect(app(SettingsRepository::class)->get('business', 'logo_path'))->toBeNull();
});

it('puts the logo on receipts', function () {
    configureGateways();
    ikejaZone();
    [$order, $token] = payableOrder();
    app(OrderLifecycle::class)->markPaid($order);

    guest($token)->getJson("/api/v1/orders/{$order->reference}/receipt")->assertOk()->assertJsonPath('data.business.logo_url', null);

    logoUpload(UploadedFile::fake()->image('a.png', 300, 300))->assertOk();
    $url = app(BusinessLogo::class)->url();
    guest($token)->getJson("/api/v1/orders/{$order->reference}/receipt")->assertOk()->assertJsonPath('data.business.logo_url', $url);
});
