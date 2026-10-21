<?php

use App\Models\AuditLog;
use App\Models\DeliveryZone;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

function zoneStaff(string $role): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('slug', $role)->first());

    return $user;
}

it('lists only active zones publicly, without internal ids', function () {
    ikejaZone(['free_threshold_kobo' => 5_000_000, 'estimated_days_min' => 1, 'estimated_days_max' => 3]);
    DeliveryZone::factory()->create(['city' => 'Hidden', 'is_active' => false]);

    $res = $this->getJson('/api/v1/delivery/zones')->assertOk()->assertJsonCount(1, 'data');
    $res->assertJsonPath('data.0.city', 'Ikeja')->assertJsonPath('data.0.fee.formatted', '₦1,500')
        ->assertJsonPath('data.0.free_threshold.kobo', 5_000_000)->assertJsonPath('data.0.estimate', '1-3 days');
    expect($res->json('data.0'))->not->toHaveKey('id');
});

it('restricts zone management to staff with the shipping permission', function () {
    $payload = ['name' => 'Abuja (demo)', 'state' => 'FCT', 'city' => 'Garki', 'fee_kobo' => 200_000];

    $this->postJson('/api/v1/admin/delivery-zones', $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/admin/delivery-zones', $payload)->assertForbidden();
    $this->actingAs(zoneStaff('content-editor'), 'sanctum')->postJson('/api/v1/admin/delivery-zones', $payload)->assertForbidden();
    $this->actingAs(zoneStaff('order-manager'), 'sanctum')->postJson('/api/v1/admin/delivery-zones', $payload)->assertCreated();
});

it('creates, updates and deletes zones with audit entries and fresh public data', function () {
    $this->actingAs(zoneStaff('store-manager'), 'sanctum');
    $this->getJson('/api/v1/delivery/zones')->assertJsonCount(0, 'data');

    $id = $this->postJson('/api/v1/admin/delivery-zones', ['name' => 'Abuja (demo)', 'state' => 'FCT', 'city' => 'Garki', 'fee_kobo' => 200_000, 'estimated_days_min' => 2, 'estimated_days_max' => 4])
        ->assertCreated()->json('data.id');
    $this->getJson('/api/v1/delivery/zones')->assertJsonCount(1, 'data'); // cache invalidated on create

    $this->patchJson("/api/v1/admin/delivery-zones/{$id}", ['fee_kobo' => 250_000])->assertOk();
    $this->getJson('/api/v1/delivery/zones')->assertJsonPath('data.0.fee.kobo', 250_000);

    $this->patchJson("/api/v1/admin/delivery-zones/{$id}", ['is_active' => false])->assertOk();
    $this->getJson('/api/v1/delivery/zones')->assertJsonCount(0, 'data');

    $this->deleteJson("/api/v1/admin/delivery-zones/{$id}")->assertNoContent();
    expect(AuditLog::whereIn('action', ['delivery_zone.created', 'delivery_zone.updated', 'delivery_zone.deleted'])->count())->toBe(4);
});

it('rejects duplicate state and city combinations, including state-wide duplicates', function () {
    $this->actingAs(zoneStaff('store-manager'), 'sanctum');
    $base = ['name' => 'Z', 'fee_kobo' => 100_000];

    $this->postJson('/api/v1/admin/delivery-zones', $base + ['state' => 'Lagos', 'city' => 'Ikeja'])->assertCreated();
    $this->postJson('/api/v1/admin/delivery-zones', $base + ['state' => 'lagos', 'city' => 'IKEJA'])->assertUnprocessable()->assertJsonValidationErrors('city');
    $this->postJson('/api/v1/admin/delivery-zones', $base + ['state' => 'Lagos'])->assertCreated();
    $this->postJson('/api/v1/admin/delivery-zones', $base + ['state' => 'Lagos', 'city' => null])->assertUnprocessable()->assertJsonValidationErrors('city');
});

it('validates fees and estimate ranges', function () {
    $this->actingAs(zoneStaff('store-manager'), 'sanctum');

    $this->postJson('/api/v1/admin/delivery-zones', ['name' => 'Z', 'state' => 'Lagos', 'fee_kobo' => -5])->assertUnprocessable()->assertJsonValidationErrors('fee_kobo');
    $this->postJson('/api/v1/admin/delivery-zones', ['name' => 'Z', 'state' => 'Lagos', 'fee_kobo' => 1, 'estimated_days_min' => 5, 'estimated_days_max' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors('estimated_days_max');
});
