<?php

use App\Models\Address;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

const ADDRESS = ['recipient_name' => 'Ada Obi', 'phone' => '0803 123 4567', 'state' => 'Lagos', 'city' => 'Ikeja', 'address_line' => '12 Allen Avenue'];

/** Real login (not actingAs) so the token is a real, revocable Sanctum token. */
function loginAs(string $email, string $password = 'password'): string
{
    app('auth')->forgetGuards();

    return test()->withoutHeader('Authorization')->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk()->json('data.token');
}

function asToken(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

describe('saved addresses', function () {
    it('requires a signed-in customer', function () {
        $this->getJson('/api/v1/me/addresses')->assertUnauthorized();
        $this->postJson('/api/v1/me/addresses', ADDRESS)->assertUnauthorized();
    });

    it('makes the first address the default and normalises the phone', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/addresses', ADDRESS + ['label' => 'Home'])
            ->assertCreated()->assertJsonPath('data.is_default', true)->assertJsonPath('data.phone', '+2348031234567');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/addresses', ['address_line' => '5 Other Road'] + ADDRESS)
            ->assertCreated()->assertJsonPath('data.is_default', false);
    });

    it('moves the default when another address is made default, and lists the default first', function () {
        $user = User::factory()->create();
        $first = Address::factory()->for($user)->create(['is_default' => true]);
        $second = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/me/addresses/{$second->id}", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);

        expect($first->refresh()->is_default)->toBeFalse()->and($user->addresses()->where('is_default', true)->count())->toBe(1);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/addresses')->assertJsonPath('data.0.id', $second->id);
    });

    it('cannot un-default the default address directly', function () {
        $user = User::factory()->create();
        $default = Address::factory()->for($user)->create(['is_default' => true]);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/me/addresses/{$default->id}", ['is_default' => false, 'city' => 'Lekki'])->assertOk()->assertJsonPath('data.is_default', true);
        expect($default->refresh()->city)->toBe('Lekki');
    });

    it('promotes another address when the default is deleted', function () {
        $user = User::factory()->create();
        $default = Address::factory()->for($user)->create(['is_default' => true]);
        $other = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/me/addresses/{$default->id}")->assertNoContent();

        expect($other->refresh()->is_default)->toBeTrue();
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/me/addresses/{$other->id}")->assertNoContent();
        expect($user->addresses()->count())->toBe(0);
    });

    it('never exposes or changes another customer\'s addresses', function () {
        $ada = User::factory()->create();
        $bob = User::factory()->create();
        $bobs = Address::factory()->for($bob)->create(['city' => 'Bob City']);

        $this->actingAs($ada, 'sanctum')->getJson('/api/v1/me/addresses')->assertJsonCount(0, 'data');
        $this->actingAs($ada, 'sanctum')->patchJson("/api/v1/me/addresses/{$bobs->id}", ['city' => 'Hacked'])->assertNotFound();
        $this->actingAs($ada, 'sanctum')->deleteJson("/api/v1/me/addresses/{$bobs->id}")->assertNotFound();
        expect($bobs->refresh()->city)->toBe('Bob City');
    });

    it('validates input, strips markup, and caps the number of addresses', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/addresses', ['phone' => '123'] + ADDRESS)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/addresses', ['recipient_name' => ''])->assertUnprocessable();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/addresses', ['label' => '<b>Home</b>'] + ADDRESS)->assertCreated()->assertJsonPath('data.label', 'Home');

        Address::factory()->for($user)->count(9)->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/addresses', ADDRESS)->assertUnprocessable()->assertJsonPath('code', 'address_limit');
    });
});

describe('profile', function () {
    it('updates name and phone, normalising the number, but never the email', function () {
        $user = User::factory()->create(['email' => 'keep@example.com']);

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me', ['name' => 'Ada Okafor', 'phone' => '0803 123 4567', 'email' => 'hacker@example.com'])
            ->assertOk()->assertJsonPath('data.name', 'Ada Okafor')->assertJsonPath('data.phone', '+2348031234567')->assertJsonPath('data.email', 'keep@example.com');
        expect($user->refresh()->email)->toBe('keep@example.com');
    });

    it('rejects another customer\'s phone number but allows keeping your own', function () {
        User::factory()->create(['phone' => '+2348031234567']);
        $me = User::factory()->create(['phone' => '+2348099999999']);

        $this->actingAs($me, 'sanctum')->patchJson('/api/v1/me', ['phone' => '0803 123 4567'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->actingAs($me, 'sanctum')->patchJson('/api/v1/me', ['phone' => '0809 999 9999', 'name' => 'Same Phone'])->assertOk();
        $this->actingAs($me, 'sanctum')->patchJson('/api/v1/me', ['phone' => ''])->assertOk()->assertJsonPath('data.phone', null);
    });

    it('requires authentication and a sensible date of birth', function () {
        $this->patchJson('/api/v1/me', ['name' => 'X'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->patchJson('/api/v1/me', ['date_of_birth' => now()->addDay()->toDateString()])->assertUnprocessable();
    });
});

describe('password change', function () {
    it('checks the current password and the strength of the new one', function () {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'NewStr0ngPass1', 'password_confirmation' => 'NewStr0ngPass1'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'NewStr0ngPass1', 'password_confirmation' => 'Mismatch1234'])->assertUnprocessable();
    });

    it('signs out other devices but keeps the current one', function () {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $phone = loginAs('ada@example.com');
        $laptop = loginAs('ada@example.com');

        asToken($laptop)->putJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'NewStr0ngPass1', 'password_confirmation' => 'NewStr0ngPass1'])->assertOk();

        asToken($laptop)->getJson('/api/v1/me')->assertOk();
        asToken($phone)->getJson('/api/v1/me')->assertUnauthorized();
        loginAs('ada@example.com', 'NewStr0ngPass1');
        expect($user->tokens()->count())->toBe(2);
    });
});

describe('logout', function () {
    it('invalidates the token on the server', function () {
        User::factory()->create(['email' => 'ada@example.com']);
        $token = loginAs('ada@example.com');

        asToken($token)->getJson('/api/v1/me')->assertOk();
        asToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        asToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    });
});

describe('password reset', function () {
    it('answers identically for known and unknown emails, and only emails real accounts', function () {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.com'])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

        expect($known->json())->toBe($unknown->json());
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    });

    it('links to the storefront, not the API', function () {
        Notification::fake();
        config(['app.frontend_url' => 'https://shop.example.com']);
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = (new ReflectionMethod($n, 'resetUrl'))->invoke($n, $user);

            return str_starts_with($url, 'https://shop.example.com/reset-password?token=') && str_contains($url, 'email=ada%40example.com');
        });
    });

    it('resets the password with a valid token, signs out all sessions, and the token is single-use', function () {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $session = loginAs('ada@example.com');
        $token = Password::createToken($user);
        $payload = ['email' => 'ada@example.com', 'token' => $token, 'password' => 'BrandNewPass99', 'password_confirmation' => 'BrandNewPass99'];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();

        asToken($session)->getJson('/api/v1/me')->assertUnauthorized();
        loginAs('ada@example.com', 'BrandNewPass99');
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
    });

    it('rejects bad tokens, wrong emails and weak passwords without revealing which was wrong', function () {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $good = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'ada@example.com', 'token' => 'bad', 'password' => 'BrandNewPass99', 'password_confirmation' => 'BrandNewPass99'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'other@example.com', 'token' => $good, 'password' => 'BrandNewPass99', 'password_confirmation' => 'BrandNewPass99'])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
        $this->postJson('/api/v1/auth/reset-password', ['email' => 'ada@example.com', 'token' => $good, 'password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
    });
});
