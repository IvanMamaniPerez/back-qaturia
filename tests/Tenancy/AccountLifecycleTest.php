<?php

use App\Models\Business;
use App\Models\User;
use App\Notifications\AccountPasswordChangedNotification;
use App\Notifications\AccountPasswordResetNotification;
use App\Notifications\AccountWelcomeNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\BusinessTestCase;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmBootstrapSeeder;
use VentureDrake\LaravelCrm\Livewire\Auth\Register;
use VentureDrake\LaravelCrm\Livewire\Auth\ResetPassword;
use VentureDrake\LaravelCrm\Support\BusinessContext;

uses(BusinessTestCase::class);

beforeEach(function () {
    config(['app.key' => str_repeat('a', 32), 'auth.public_url' => 'https://accounts.example.test']);
});

function lifecycleCredentials(array $overrides = []): array
{
    return array_replace([
        'name' => 'Account Owner', 'business_name' => 'New Business',
        'email' => 'new-owner@example.test', 'password' => 'safe-password-2026',
        'password_confirmation' => 'safe-password-2026', 'device_name' => 'android',
    ], $overrides);
}

function lifecycleUser(): User
{
    $business = Business::create(['name' => 'Existing Business']);

    return User::factory()->create(['crm_access' => true, 'team_id' => $business->id]);
}

test('registration creates an isolated business owner sends welcome and authenticates a native client', function () {
    $this->seed(LaravelCrmBootstrapSeeder::class);
    Notification::fake();
    $response = $this->withHeader('Origin', 'https://localhost')->postJson('/crm/api/v2/auth/register', lifecycleCredentials([
        'email' => ' NEW-OWNER@EXAMPLE.TEST ', 'team_id' => 'forged', 'crm_access' => false,
    ]))->assertCreated()->assertJsonPath('user.email', 'new-owner@example.test')
        ->assertJsonMissingPath('user.password')->assertCookieMissing(config('session.cookie'));
    $user = User::where('email', 'new-owner@example.test')->firstOrFail();
    expect($user->team_id)->not->toBe('forged')->and($user->crm_access)->toBeTruthy();
    expect($user->business->owner_user_id)->toBe($user->id);
    app(BusinessContext::class)->run($user->team_id, fn () => expect($user->hasRole('Owner'))->toBeTrue());
    Notification::assertSentTo($user, AccountWelcomeNotification::class);
    $this->assertDatabaseHas('api_refresh_tokens', ['user_id' => $user->id, 'device_name' => 'android']);
    Auth::forgetGuards();
    $this->withToken($response->json('token'))->getJson('/crm/api/v2/auth/me')->assertOk()->assertJsonPath('user.business.name', 'New Business');
});

test('registration rejects duplicate emails including different letter case without creating another business', function () {
    $existing = lifecycleUser();
    Notification::fake();
    $this->postJson('/crm/api/v2/auth/register', lifecycleCredentials(['email' => strtoupper($existing->email)]))
        ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Este correo ya está registrado.');
    $this->assertDatabaseCount('businesses', 1);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Notification::assertNothingSent();
});

test('registration rejects invalid fields without partial accounts', function (array $overrides, string $field) {
    Notification::fake();
    $this->postJson('/crm/api/v2/auth/register', lifecycleCredentials($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('businesses', 0);
    Notification::assertNothingSent();
})->with([
    'missing name' => [['name' => ''], 'name'],
    'missing business' => [['business_name' => ''], 'business_name'],
    'invalid email' => [['email' => 'invalid'], 'email'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'unconfirmed password' => [['password_confirmation' => 'different'], 'password'],
    'bcrypt byte limit' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password'],
    'multibyte bcrypt limit' => [['password' => str_repeat('é', 40), 'password_confirmation' => str_repeat('é', 40)], 'password'],
]);

test('registration can be disabled explicitly', function () {
    config(['auth.registration_enabled' => false]);
    $this->postJson('/crm/api/v2/auth/register', lifecycleCredentials())->assertForbidden();
    Livewire::test(Register::class)->call('register')->assertForbidden();
    $this->assertDatabaseCount('users', 0);
});

test('forgot password sends a hashed single use token with a trusted URL and throttles duplicate mail', function () {
    $user = lifecycleUser();
    Notification::fake();
    $first = $this->withHeader('Host', 'untrusted.example')->postJson('/crm/api/v2/auth/forgot-password', ['email' => strtoupper($user->email)])->assertStatus(202)->json();
    $this->postJson('/crm/api/v2/auth/forgot-password', ['email' => $user->email])->assertStatus(202)->assertExactJson($first);
    Notification::assertSentToTimes($user, AccountPasswordResetNotification::class, 1);
    $notification = Notification::sent($user, AccountPasswordResetNotification::class)->first();
    expect(Password::broker()->tokenExists($user, $notification->token))->toBeTrue();
    $this->assertDatabaseMissing('password_reset_tokens', ['token' => $notification->token]);
    $mail = $notification->toMail($user);
    expect($mail->actionUrl)->toStartWith('https://accounts.example.test/crm/password/reset/')
        ->toContain(urlencode($user->email));
    expect($notification)->toBeInstanceOf(ShouldBeEncrypted::class);
});

test('forgot password does not expose whether an account exists', function () {
    Notification::fake();
    $this->postJson('/crm/api/v2/auth/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(202)
        ->assertExactJson(['message' => 'Si el correo está registrado, recibirás las instrucciones para recuperar tu contraseña.']);
    Notification::assertNothingSent();
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

test('password reset changes the password revokes all device tokens and requires fresh login', function () {
    $user = lifecycleUser();
    $android = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'android'])->json();
    $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'desktop'])->assertCreated();
    $user->createToken('legacy');
    $token = Password::broker()->createToken($user);
    Notification::fake();
    $this->postJson('/crm/api/v2/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new-safe-password', 'password_confirmation' => 'new-safe-password'])->assertOk();
    expect(Hash::check('new-safe-password', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('api_refresh_tokens', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('password_reset_tokens', 0);
    Notification::assertSentTo($user, AccountPasswordChangedNotification::class);
    Auth::forgetGuards();
    $this->withToken($android['token'])->getJson('/crm/api/v2/auth/me')->assertUnauthorized();
    Auth::forgetGuards();
    $this->withoutHeader('Authorization')->postJson('/crm/api/v2/auth/refresh', ['refresh_token' => $android['refresh_token']])->assertUnauthorized();
    $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
    $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'new-safe-password'])->assertCreated();
});

test('a reset token cannot be reused after a successful password change', function () {
    $user = lifecycleUser();
    $token = Password::broker()->createToken($user);
    Notification::fake();
    $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-safe-password', 'password_confirmation' => 'new-safe-password'];
    $this->postJson('/crm/api/v2/auth/reset-password', $payload)->assertOk();
    $this->postJson('/crm/api/v2/auth/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors('token');
    Notification::assertSentToTimes($user, AccountPasswordChangedNotification::class, 1);
});

test('expired forged or wrong-account reset tokens never change passwords', function (string $condition) {
    $user = lifecycleUser();
    $token = Password::broker()->createToken($user);
    Notification::fake();
    if ($condition === 'expired') {
        $this->travel(61)->minutes();
    }
    $this->postJson('/crm/api/v2/auth/reset-password', [
        'email' => $condition === 'wrong-account' ? 'unknown@example.test' : $user->email,
        'token' => $condition === 'forged' ? str_repeat('a', 64) : $token,
        'password' => 'new-safe-password', 'password_confirmation' => 'new-safe-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('token');
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    Notification::assertNothingSent();
})->with(['expired', 'forged', 'wrong-account']);

test('the emailed web form uses the same password reset and revocation logic', function () {
    $user = lifecycleUser();
    $user->createToken('desktop');
    $token = Password::broker()->createToken($user);
    Notification::fake();
    Livewire::test(ResetPassword::class, ['token' => $token, 'email' => $user->email])
        ->set('password', 'web-new-password')->set('password_confirmation', 'web-new-password')
        ->call('resetPassword')->assertHasNoErrors()->assertRedirect(route('laravel-crm.login'));
    expect(Hash::check('web-new-password', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Notification::assertSentTo($user, AccountPasswordChangedNotification::class);
});

test('a browser cookie session is rejected after resetting its password', function () {
    $user = lifecycleUser();
    $login = $this->withHeader('Origin', 'http://localhost:5173')->postJson('/crm/api/v2/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    $cookie = config('session.cookie');
    $this->withCredentials()->withCookie($cookie, $login->getCookie($cookie)->getValue());
    Auth::forgetGuards();
    $this->getJson('/crm/api/v2/auth/me')->assertOk();
    $token = Password::broker()->createToken($user);
    Notification::fake();
    $this->postJson('/crm/api/v2/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new-safe-password', 'password_confirmation' => 'new-safe-password'])->assertOk();
    Auth::forgetGuards();
    $this->getJson('/crm/api/v2/auth/me')->assertUnauthorized();
});

test('recovery endpoints reject malformed input without sending mail or changing passwords', function () {
    Notification::fake();
    $this->postJson('/crm/api/v2/auth/forgot-password', ['email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/crm/api/v2/auth/reset-password')->assertUnprocessable()->assertJsonValidationErrors(['email', 'token', 'password']);
    $user = lifecycleUser();
    $token = Password::broker()->createToken($user);
    $this->postJson('/crm/api/v2/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'safe-new-password', 'password_confirmation' => 'different'])
        ->assertUnprocessable()->assertJsonPath('errors.password.0', 'Las contraseñas no coinciden.');
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    Notification::assertNothingSent();
});

test('recovery requests are rate limited without sending duplicate emails', function () {
    $user = lifecycleUser();
    Notification::fake();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/crm/api/v2/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
    }
    $this->postJson('/crm/api/v2/auth/forgot-password', ['email' => $user->email])->assertTooManyRequests();
    Notification::assertSentToTimes($user, AccountPasswordResetNotification::class, 1);
});
