<?php

use App\Models\AuditLog;
use App\Models\CustomerNote;
use App\Models\Order;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function customerWithPassword(string $email = 'ada@example.com'): User
{
    return User::factory()->create(['email' => $email, 'password' => 'Customer-Pass-12345']);
}

function customerLogin(string $email = 'ada@example.com', string $password = 'Customer-Pass-12345'): TestResponse
{
    return guest()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

it('suspends a customer: signed out everywhere, unable to sign in, history kept', function () {
    $customer = customerWithPassword();
    $token = customerLogin()->assertOk()->json('data.token');
    $support = orderStaff('customer-support');

    $res = asStaff($support)->postJson("/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => 'Chargeback abuse'])->assertOk();

    $res->assertJsonPath('data.is_active', false)->assertJsonPath('data.suspension_reason', 'Chargeback abuse');
    expect($customer->refresh())->is_active->toBeFalse()->suspended_at->not->toBeNull()->and($customer->tokens()->count())->toBe(0);
    guest()->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    expect(CustomerNote::where('customer_id', $customer->id)->value('body'))->toBe('Account suspended: Chargeback abuse')
        ->and(AuditLog::where('action', 'customer.suspended')->where('subject_id', $customer->id)->count())->toBe(1);
});

it('tells a suspended customer why only after the right password', function () {
    $customer = customerWithPassword();
    asStaff(orderStaff('customer-support'))->postJson("/api/v1/admin/customers/{$customer->id}/suspend")->assertOk();

    customerLogin()->assertUnprocessable()->assertJsonPath('errors.email.0', 'This account has been suspended. Please contact us for help.');
    customerLogin(password: 'wrong-password-1')->assertUnprocessable()->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    customerLogin('nobody@example.com')->assertUnprocessable()->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
});

it('reactivates a customer, who can then sign in again', function () {
    $customer = customerWithPassword();
    $support = orderStaff('customer-support');
    asStaff($support)->postJson("/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => 'Review'])->assertOk();

    asStaff($support)->postJson("/api/v1/admin/customers/{$customer->id}/reactivate")->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.suspension_reason', null);

    customerLogin()->assertOk();
    expect($customer->refresh())->suspended_at->toBeNull()
        ->and(AuditLog::where('action', 'customer.reactivated')->count())->toBe(1)
        ->and(CustomerNote::where('customer_id', $customer->id)->pluck('body')->all())->toContain('Account reactivated.');
});

it('does nothing twice: suspending or reactivating again changes and records nothing new', function () {
    $customer = customerWithPassword();
    $support = orderStaff('customer-support');

    asStaff($support)->postJson("/api/v1/admin/customers/{$customer->id}/reactivate")->assertOk();
    asStaff($support)->postJson("/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => 'First'])->assertOk();
    asStaff($support)->postJson("/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => 'Second'])->assertOk()->assertJsonPath('data.suspension_reason', 'First');

    expect(AuditLog::whereIn('action', ['customer.suspended', 'customer.reactivated'])->count())->toBe(1)->and(CustomerNote::count())->toBe(1);
});

it('cleans and limits the reason', function () {
    $customer = customerWithPassword();
    $support = orderStaff('customer-support');

    asStaff($support)->postJson("/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => str_repeat('x', 301)])->assertJsonValidationErrors('reason');
    asStaff($support)->postJson("/api/v1/admin/customers/{$customer->id}/suspend", ['reason' => '<script>alert(1)</script>Fraud'])->assertOk();

    expect($customer->refresh()->suspension_reason)->not->toContain('<');
});

it('can only be done by people who manage customers, and only to customers', function () {
    $customer = customerWithPassword();
    foreach (['order-manager', 'content-editor', 'marketing-manager'] as $role) {
        asStaff(orderStaff($role))->postJson("/api/v1/admin/customers/{$customer->id}/suspend")->assertForbidden();
    }
    asStaff($customer)->postJson("/api/v1/admin/customers/{$customer->id}/suspend")->assertForbidden();
    guest()->postJson("/api/v1/admin/customers/{$customer->id}/suspend")->assertUnauthorized();

    $staff = orderStaff('order-manager');
    asStaff(orderStaff('customer-support'))->postJson("/api/v1/admin/customers/{$staff->id}/suspend")->assertNotFound();
    expect($customer->refresh()->is_active)->toBeTrue()->and($staff->refresh()->is_active)->toBeTrue();
});

it('shows suspension in the customer list and filters by it', function () {
    $active = customerWithPassword('active@example.com');
    $suspended = customerWithPassword('suspended@example.com');
    $support = orderStaff('customer-support');
    asStaff($support)->postJson("/api/v1/admin/customers/{$suspended->id}/suspend")->assertOk();

    $only = asStaff($support)->getJson('/api/v1/admin/customers?status=suspended')->assertOk();
    expect($only->json('data.*.email'))->toBe(['suspended@example.com'])->and($only->json('data.0.is_active'))->toBeFalse()->and($only->json('data.0.suspended_at'))->not->toBeNull();
    expect(asStaff($support)->getJson('/api/v1/admin/customers?status=active')->json('data.*.email'))->toBe(['active@example.com']);
    asStaff($support)->getJson("/api/v1/admin/customers/{$suspended->id}")->assertJsonPath('data.suspension_reason', null)->assertJsonPath('data.is_active', false);
});

it('rejects an inactive account on every signed-in route even if a token survived', function () {
    $customer = customerWithPassword();
    $token = customerLogin()->json('data.token');
    $customer->forceFill(['is_active' => false])->save(); // a token left over from before, e.g. a race

    guest()->withToken($token)->getJson('/api/v1/me/orders')->assertUnauthorized()->assertJsonPath('code', 'account_inactive');
    expect($customer->tokens()->count())->toBe(0);
});

it('keeps deactivated staff out of the staff portal with the generic answer', function () {
    $staff = orderStaff('order-manager');
    $staff->forceFill(['password' => 'Staff-Pass-12345', 'is_active' => false])->save();

    guest()->postJson('/api/v1/auth/staff/login', ['email' => $staff->email, 'password' => 'Staff-Pass-12345'])->assertUnprocessable()->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
});

it('stops a suspended customer buying as a guest with their email, but not other shoppers or deactivated staff emails', function () {
    configureGateways();
    ikejaZone();
    $customer = customerWithPassword('ada@example.com');
    asStaff(orderStaff('customer-support'))->postJson("/api/v1/admin/customers/{$customer->id}/suspend")->assertOk();

    checkout(addToCart(sellableVariant()), ['email' => 'Ada@Example.com'])->assertForbidden()->assertJsonPath('code', 'account_suspended');
    expect(Order::count())->toBe(0);

    checkout(addToCart(sellableVariant()), ['email' => 'someone.else@example.com'], 'idem-key-9999999999')->assertCreated();

    $staff = orderStaff('order-manager');
    $staff->forceFill(['is_active' => false])->save();
    checkout(addToCart(sellableVariant()), ['email' => $staff->email], 'idem-key-8888888888')->assertCreated();

    asStaff(orderStaff('customer-support'))->postJson("/api/v1/admin/customers/{$customer->id}/reactivate")->assertOk();
    checkout(addToCart(sellableVariant()), ['email' => 'ada@example.com'], 'idem-key-7777777777')->assertCreated();
});
