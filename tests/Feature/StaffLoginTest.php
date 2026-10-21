<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;

function staffLogin(string $email, string $password): TestResponse
{
    return guest()->postJson('/api/v1/auth/staff/login', ['email' => $email, 'password' => $password]);
}

it('signs staff in at the staff portal with a short-lived token', function () {
    $staff = orderStaff('order-manager');
    $staff->forceFill(['password' => 'Staff-Pass-12345'])->save();

    $res = staffLogin($staff->email, 'Staff-Pass-12345')->assertOk()->assertJsonPath('data.user.is_staff', true);

    expect(now()->addHours(9)->isAfter($res->json('data.expires_at')))->toBeTrue();
    asStaff($staff)->getJson('/api/v1/admin/orders/summary')->assertOk();
});

it('gives customers and strangers the same answer as a wrong password at the staff portal', function () {
    $customer = User::factory()->create(['password' => 'Customer-Pass-12345']);
    $staff = orderStaff('order-manager');
    $staff->forceFill(['password' => 'Staff-Pass-12345'])->save();

    $customerReply = staffLogin($customer->email, 'Customer-Pass-12345')->assertUnprocessable()->json();
    $wrongPassword = staffLogin($staff->email, 'nope-nope-nope')->assertUnprocessable()->json();
    $nobody = staffLogin('nobody@example.com', 'whatever-123')->assertUnprocessable()->json();

    expect($customerReply)->toBe($wrongPassword)->toBe($nobody);
});

it('refuses staff accounts at the customer sign-in and points them to the staff portal', function () {
    $staff = orderStaff('customer-support');
    $staff->forceFill(['password' => 'Staff-Pass-12345'])->save();

    guest()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'Staff-Pass-12345'])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Staff accounts sign in at the staff portal, not here.');
    // A wrong password gets the generic message, not the portal hint, so the hint does not reveal who is staff.
    guest()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'wrong-wrong-12'])
        ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
});

it('refuses deactivated staff and applies the sign-in rate limit', function () {
    $staff = orderStaff('order-manager');
    $staff->forceFill(['password' => 'Staff-Pass-12345', 'is_active' => false])->save();
    staffLogin($staff->email, 'Staff-Pass-12345')->assertUnprocessable();

    foreach (range(1, 10) as $_) {
        staffLogin($staff->email, 'bad-password-1');
    }
    staffLogin($staff->email, 'bad-password-1')->assertStatus(429);
});

it('lets customers keep signing in as before', function () {
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'password']);

    guest()->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])->assertOk();
});
