<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['app.frontend_url' => 'https://shop.test']);
});

function invitePayload(array $over = []): array
{
    return array_merge(['name' => 'Tunde Bakare', 'email' => 'Tunde@Example.test', 'roles' => ['order-manager']], $over);
}

describe('who can manage staff', function () {
    it('is for super administrators only, not even store managers', function () {
        $someone = orderStaff('order-manager');

        $this->getJson('/api/v1/admin/staff')->assertUnauthorized();
        asStaff(User::factory()->create())->getJson('/api/v1/admin/staff')->assertForbidden();
        foreach (['store-manager', 'order-manager', 'customer-support', 'marketing-manager', 'product-manager'] as $role) {
            asStaff(orderStaff($role))->getJson('/api/v1/admin/staff')->assertForbidden();
            asStaff(orderStaff($role))->postJson('/api/v1/admin/staff', invitePayload())->assertForbidden();
            asStaff(orderStaff($role))->patchJson("/api/v1/admin/staff/{$someone->id}", ['roles' => ['super-admin']])->assertForbidden();
        }
        asStaff(orderStaff('super-admin'))->getJson('/api/v1/admin/staff')->assertOk();
        expect($someone->refresh()->hasRole('super-admin'))->toBeFalse();
    });
});

describe('listing', function () {
    it('lists staff with their roles and leaves customers out', function () {
        $admin = orderStaff('super-admin');
        $manager = orderStaff('store-manager');
        User::factory()->create(['name' => 'A Customer']);

        $res = asStaff($admin)->getJson('/api/v1/admin/staff')->assertOk();

        expect(collect($res->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect([$admin->id, $manager->id])->sort()->values()->all());
        expect($res->json('data.0'))->toHaveKeys(['name', 'email', 'is_active', 'roles']);
        expect($res->getContent())->not->toContain('password');
    });

    it('lists the roles with what each can do', function () {
        $res = asStaff(orderStaff('super-admin'))->getJson('/api/v1/admin/roles')->assertOk();
        $byRole = collect($res->json('data'))->keyBy('slug');

        expect($byRole['super-admin']['permissions'])->toBe(['*'])
            ->and($byRole['order-manager']['permissions'])->toContain('orders.manage')->not->toContain('settings.manage')
            ->and($byRole)->not->toHaveKey('nonexistent');
    });
});

describe('inviting staff', function () {
    it('creates the account with the chosen roles and emails a single-use link, never a password', function () {
        Notification::fake();
        $admin = orderStaff('super-admin');

        $res = asStaff($admin)->postJson('/api/v1/admin/staff', invitePayload(['roles' => ['order-manager', 'customer-support']]))->assertCreated()
            ->assertJsonPath('data.email', 'tunde@example.test')->assertJsonPath('data.is_active', true);

        $user = User::firstWhere('email', 'tunde@example.test');
        expect($user->roles->pluck('slug')->sort()->values()->all())->toBe(['customer-support', 'order-manager'])->and($res->getContent())->not->toContain('password');
        Notification::assertSentTo($user, StaffInvitationNotification::class, function ($n) use ($user) {
            $mail = $n->toMail($user);

            return str_starts_with($mail->actionUrl, 'https://shop.test/reset-password?token=') && str_contains($mail->actionUrl, urlencode($user->email))
                && ! str_contains(json_encode($mail->toArray()), 'password":');
        });
        expect(AuditLog::where('action', 'staff.invited')->count())->toBe(1);
    });

    it('lets the invited person set a password from the link and then sign in with their roles', function () {
        Notification::fake();
        asStaff(orderStaff('super-admin'))->postJson('/api/v1/admin/staff', invitePayload())->assertCreated();
        $user = User::firstWhere('email', 'tunde@example.test');
        $token = null;
        Notification::assertSentTo($user, StaffInvitationNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        // Nobody can sign in before the link is used.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'anything-at-all-1'])->assertUnprocessable();

        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'Brand-New-Pass-42', 'password_confirmation' => 'Brand-New-Pass-42'])->assertOk();
        $login = $this->postJson('/api/v1/auth/staff/login', ['email' => $user->email, 'password' => 'Brand-New-Pass-42'])->assertOk();

        expect($login->json('data.user.roles'))->toBe(['order-manager'])->and($login->json('data.user.permissions'))->toContain('orders.manage');
        // The link is single use.
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'Another-Pass-4242', 'password_confirmation' => 'Another-Pass-4242'])->assertUnprocessable();
    });

    it('refuses bad invitations', function (array $over, string $field) {
        User::factory()->create(['email' => 'taken@example.test']);

        asStaff(orderStaff('super-admin'))->postJson('/api/v1/admin/staff', invitePayload($over))->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'no roles' => [['roles' => []], 'roles'],
        'missing roles' => [['roles' => null], 'roles'],
        'an unknown role' => [['roles' => ['wizard']], 'roles.0'],
        'a bad email' => [['email' => 'nope'], 'email'],
        'an email that already has an account' => [['email' => 'Taken@Example.test'], 'email'],
        'no name' => [['name' => ''], 'name'],
    ]);

    it('strips markup from the name', function () {
        Notification::fake();

        asStaff(orderStaff('super-admin'))->postJson('/api/v1/admin/staff', invitePayload(['name' => '<b>Tunde</b> Bakare<script>x</script>']))->assertCreated()->assertJsonPath('data.name', 'Tunde Bakarex');
    });
});

describe('changing roles', function () {
    it('replaces someone\'s roles, takes effect straight away, and audits it', function () {
        $admin = orderStaff('super-admin');
        $person = orderStaff('customer-support');

        asStaff($person)->getJson('/api/v1/admin/orders')->assertOk();
        asStaff($person)->getJson('/api/v1/admin/products')->assertForbidden();

        asStaff($admin)->patchJson("/api/v1/admin/staff/{$person->id}", ['roles' => ['product-manager']])->assertOk()->assertJsonPath('data.roles.0.slug', 'product-manager');

        // A fresh model, as a real request would load: the permission list is memoised per instance.
        asStaff(User::find($person->id))->getJson('/api/v1/admin/products')->assertOk();
        asStaff(User::find($person->id))->getJson('/api/v1/admin/orders')->assertForbidden();
        $log = AuditLog::where('action', 'staff.roles_changed')->first();
        expect($log->metadata)->toBe(['from' => ['customer-support'], 'to' => ['product-manager']]);
    });

    it('will not leave someone with no roles, or take the email field', function () {
        $person = orderStaff('customer-support');
        $admin = orderStaff('super-admin');

        asStaff($admin)->patchJson("/api/v1/admin/staff/{$person->id}", ['roles' => []])->assertUnprocessable()->assertJsonValidationErrors('roles');
        asStaff($admin)->patchJson("/api/v1/admin/staff/{$person->id}", ['email' => 'new@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        expect($person->refresh()->roles)->toHaveCount(1);
    });

    it('does not let a super administrator change their own roles', function () {
        $admin = orderStaff('super-admin');
        orderStaff('super-admin'); // a second one exists, so only the self rule applies

        asStaff($admin)->patchJson("/api/v1/admin/staff/{$admin->id}", ['roles' => ['order-manager']])->assertStatus(409)->assertJsonPath('code', 'cannot_modify_self');
        expect($admin->refresh()->hasRole('super-admin'))->toBeTrue();
    });

    it('does not open customers or unknown people as staff', function () {
        $customer = User::factory()->create();
        $admin = orderStaff('super-admin');

        asStaff($admin)->patchJson("/api/v1/admin/staff/{$customer->id}", ['roles' => ['super-admin']])->assertNotFound();
        asStaff($admin)->postJson("/api/v1/admin/staff/{$customer->id}/deactivate")->assertNotFound();
        expect($customer->refresh()->roles)->toHaveCount(0);
    });
});

describe('deactivating', function () {
    it('signs the person out everywhere at once and stops them signing in', function () {
        $admin = orderStaff('super-admin');
        $person = orderStaff('order-manager');
        $person->forceFill(['password' => 'Some-Password-123'])->save();
        $token = $person->createToken('api')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/admin/orders')->assertOk();

        asStaff($admin)->postJson("/api/v1/admin/staff/{$person->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        expect($person->tokens()->count())->toBe(0)->and(AuditLog::where('action', 'staff.deactivated')->count())->toBe(1);
        $this->postJson('/api/v1/auth/login', ['email' => $person->email, 'password' => 'Some-Password-123'])->assertUnprocessable();
    });

    it('rejects a token that somehow survives, because the account is inactive', function () {
        $person = orderStaff('order-manager');
        $token = $person->createToken('api')->plainTextToken;
        $person->forceFill(['is_active' => false])->save();

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/admin/orders')->assertUnauthorized();
    });

    it('will not deactivate yourself', function () {
        $admin = orderStaff('super-admin');
        orderStaff('super-admin');

        asStaff($admin)->postJson("/api/v1/admin/staff/{$admin->id}/deactivate")->assertStatus(409)->assertJsonPath('code', 'cannot_modify_self');
        expect($admin->refresh()->is_active)->toBeTrue();
    });

    it('can bring someone back, and resend an invitation only to active people', function () {
        Notification::fake();
        $admin = orderStaff('super-admin');
        $person = orderStaff('order-manager');

        asStaff($admin)->postJson("/api/v1/admin/staff/{$person->id}/deactivate")->assertOk();
        asStaff($admin)->postJson("/api/v1/admin/staff/{$person->id}/resend-invite")->assertStatus(409)->assertJsonPath('code', 'staff_inactive');
        Notification::assertNothingSent();

        asStaff($admin)->postJson("/api/v1/admin/staff/{$person->id}/reactivate")->assertOk()->assertJsonPath('data.is_active', true);
        asStaff($admin)->postJson("/api/v1/admin/staff/{$person->id}/resend-invite")->assertOk();
        Notification::assertSentTo($person, StaffInvitationNotification::class);
        expect(AuditLog::where('action', 'staff.reactivated')->count())->toBe(1)->and(AuditLog::where('action', 'staff.invite_resent')->count())->toBe(1);
    });
});

describe('the last super administrator', function () {
    /** A delegated manager: can manage staff but is not a super administrator, so the last-one guard is what protects the owner. */
    function delegatedStaffManager(): User
    {
        $user = orderStaff('customer-support');
        $role = Role::create(['slug' => 'staff-admin', 'name' => 'Staff Admin']);
        $role->permissions()->attach(Permission::firstOrCreate(['slug' => 'staff.manage']));
        $user->roles()->attach($role);

        return User::find($user->id);
    }

    it('can never be demoted or deactivated', function () {
        $owner = orderStaff('super-admin');
        $manager = delegatedStaffManager();

        asStaff($manager)->patchJson("/api/v1/admin/staff/{$owner->id}", ['roles' => ['store-manager']])->assertStatus(409)->assertJsonPath('code', 'last_super_admin');
        asStaff($manager)->postJson("/api/v1/admin/staff/{$owner->id}/deactivate")->assertStatus(409)->assertJsonPath('code', 'last_super_admin');

        expect($owner->refresh()->hasRole('super-admin'))->toBeTrue()->and($owner->is_active)->toBeTrue();
    });

    it('ignores an inactive super administrator when counting', function () {
        $owner = orderStaff('super-admin');
        $dormant = orderStaff('super-admin');
        $dormant->forceFill(['is_active' => false])->save();

        asStaff(delegatedStaffManager())->postJson("/api/v1/admin/staff/{$owner->id}/deactivate")->assertStatus(409)->assertJsonPath('code', 'last_super_admin');
    });

    it('can be changed once another active super administrator exists', function () {
        $owner = orderStaff('super-admin');
        orderStaff('super-admin');

        asStaff(delegatedStaffManager())->postJson("/api/v1/admin/staff/{$owner->id}/deactivate")->assertOk();
    });
});
