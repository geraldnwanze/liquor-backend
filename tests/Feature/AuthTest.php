<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

const VALID_REGISTRATION = [
    'name' => 'Ada Obi',
    'email' => 'ada@example.com',
    'phone' => '0803 123 4567',
    'password' => 'Str0ngPassw0rd',
    'password_confirmation' => 'Str0ngPassw0rd',
];

it('registers a customer, normalising the phone number', function () {
    $this->postJson('/api/v1/auth/register', VALID_REGISTRATION)
        ->assertCreated()
        ->assertJsonPath('data.user.phone', '+2348031234567')
        ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['id', 'email']]]);
});

it('rejects weak passwords and invalid phones', function () {
    $this->postJson('/api/v1/auth/register', [...VALID_REGISTRATION, 'password' => 'short', 'password_confirmation' => 'short', 'phone' => '123'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password', 'phone']);
});

it('logs in, returns the profile, and logs out', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $token = $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertOk()->json('data.token');

    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'ada@example.com');
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
});

it('refuses wrong credentials and deactivated accounts', function () {
    User::factory()->create(['email' => 'a@example.com']);
    User::factory()->create(['email' => 'b@example.com', 'is_active' => false]);

    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'nope'])->assertUnprocessable();
    $this->postJson('/api/v1/auth/login', ['email' => 'b@example.com', 'password' => 'password'])->assertUnprocessable();
});

it('requires authentication for the profile endpoint', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('enforces permissions server-side', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Route::middleware(['auth:sanctum', 'permission:catalog.manage'])->get('/api/v1/_probe', fn () => ['ok' => true]);

    $customer = User::factory()->create();
    $editor = User::factory()->create();
    $editor->roles()->attach(Role::where('slug', 'content-editor')->first());
    $productManager = User::factory()->create();
    $productManager->roles()->attach(Role::where('slug', 'product-manager')->first());
    $super = User::factory()->create();
    $super->roles()->attach(Role::where('slug', 'super-admin')->first());

    $this->actingAs($customer, 'sanctum')->getJson('/api/v1/_probe')->assertForbidden();
    $this->actingAs($editor, 'sanctum')->getJson('/api/v1/_probe')->assertForbidden();
    $this->actingAs($productManager, 'sanctum')->getJson('/api/v1/_probe')->assertOk();
    $this->actingAs($super, 'sanctum')->getJson('/api/v1/_probe')->assertOk();
});
