<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['laravel-crm.teams' => true, 'app.key' => str_repeat('a', 32)]);
});

function nativeAuthUser(): User
{
    $business = Business::create(['name' => 'Native Business']);

    return User::factory()->create(['crm_access' => true, 'team_id' => $business->id]);
}

test('Android and desktop authenticate and rotate real tokens without cookies or CSRF', function (string $origin, string $device) {
    $user = nativeAuthUser();
    app()->instance('env', 'local');
    $this->withHeader('Origin', $origin);
    $issued = $this->postJson('/crm/api/v2/auth/token', [
        'email' => $user->email, 'password' => 'password', 'device_name' => $device,
    ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('token_type', 'Bearer')->assertJsonPath('expires_in', 900)
        ->assertCookieMissing(config('session.cookie'))->json();
    $this->assertDatabaseHas('api_refresh_tokens', [
        'token_hash' => hash('sha256', $issued['refresh_token']), 'device_name' => $device,
    ]);
    $this->assertDatabaseMissing('api_refresh_tokens', ['token_hash' => $issued['refresh_token']]);

    Auth::forgetGuards();
    $this->withToken($issued['token'])->getJson('/crm/api/v2/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
    $this->travel(16)->minutes();
    Auth::forgetGuards();
    $this->getJson('/crm/api/v2/auth/me')->assertUnauthorized();
    $this->withoutHeader('Authorization');
    Auth::forgetGuards();
    $rotated = $this->postJson('/crm/api/v2/auth/refresh', ['refresh_token' => $issued['refresh_token']])
        ->assertOk()->assertJsonPath('user.id', $user->id)->json();
    expect($rotated['token'])->not->toBe($issued['token']);
    expect($rotated['refresh_token'])->not->toBe($issued['refresh_token']);
    expect($rotated['refresh_expires_at'])->toBe($issued['refresh_expires_at']);

    Auth::forgetGuards();
    $this->withToken($rotated['token'])->getJson('/crm/api/v2/auth/me')->assertOk();
    Auth::forgetGuards();
    $this->withToken($issued['token'])->getJson('/crm/api/v2/auth/me')->assertUnauthorized();
})->with([
    'Android WebView' => ['https://localhost', 'android'],
    'Android HTTP WebView' => ['http://localhost', 'android'],
    'Electron packaged' => ['null', 'desktop'],
    'Electron development' => ['http://localhost:5173', 'desktop'],
]);

test('reusing a rotated refresh token revokes that device family while another device remains authenticated', function () {
    $user = nativeAuthUser();
    $android = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'android'])->json();
    $desktop = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'desktop'])->json();
    $rotated = $this->postJson('/crm/api/v2/auth/refresh', ['refresh_token' => $android['refresh_token']])->assertOk()->json();
    $this->postJson('/crm/api/v2/auth/refresh', ['refresh_token' => $android['refresh_token']])->assertUnauthorized();
    Auth::forgetGuards();
    $this->withToken($rotated['token'])->getJson('/crm/api/v2/auth/me')->assertUnauthorized();
    Auth::forgetGuards();
    $this->withToken($desktop['token'])->getJson('/crm/api/v2/auth/me')->assertOk();
});

test('logout revokes access and refresh tokens for only the current device', function () {
    $user = nativeAuthUser();
    $android = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'android'])->json();
    $desktop = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'desktop'])->json();
    Auth::forgetGuards();
    $this->withToken($android['token'])->postJson('/crm/api/v2/auth/logout')->assertNoContent();
    Auth::forgetGuards();
    $this->withoutHeader('Authorization')->postJson('/crm/api/v2/auth/refresh', ['refresh_token' => $android['refresh_token']])->assertUnauthorized();
    Auth::forgetGuards();
    $this->withToken($desktop['token'])->getJson('/crm/api/v2/auth/me')->assertOk();
});

test('logout with refresh credentials works after access expiry and is idempotent', function () {
    $user = nativeAuthUser();
    $issued = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password'])->json();
    $rotated = $this->postJson('/crm/api/v2/auth/refresh', ['refresh_token' => $issued['refresh_token']])->assertOk()->json();
    $this->travel(16)->minutes();
    $this->postJson('/crm/api/v2/auth/token/logout', ['refresh_token' => $issued['refresh_token']])->assertNoContent();
    $this->postJson('/crm/api/v2/auth/token/logout', ['refresh_token' => $issued['refresh_token']])->assertNoContent();
    $this->postJson('/crm/api/v2/auth/refresh', ['refresh_token' => $rotated['refresh_token']])->assertUnauthorized();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('api_refresh_tokens', 0);
});

test('expired refresh tokens cannot renew and remove the device credentials', function () {
    $user = nativeAuthUser();
    $issued = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password'])->json();
    $this->travel(31)->days();
    $this->postJson('/crm/api/v2/auth/refresh', ['refresh_token' => $issued['refresh_token']])->assertUnauthorized();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('api_refresh_tokens', 0);
});

test('refresh tokens cannot be used as Bearer access tokens', function () {
    $user = nativeAuthUser();
    $issued = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password'])->json();
    $this->withToken($issued['refresh_token'])->getJson('/crm/api/v2/auth/me')->assertUnauthorized();
});

test('credential or access changes invalidate refresh tokens', function (string $change) {
    $user = nativeAuthUser();
    $issued = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password'])->json();
    $user->forceFill($change === 'password' ? ['password' => 'changed-password'] : ['crm_access' => false])->save();
    $this->postJson('/crm/api/v2/auth/refresh', ['refresh_token' => $issued['refresh_token']])->assertUnauthorized();
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['password', 'access']);

test('a password change invalidates an unexpired access token immediately', function () {
    $user = nativeAuthUser();
    $issued = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password'])->json();
    $user->forceFill(['password' => 'changed-password'])->save();
    Auth::forgetGuards();
    $this->withToken($issued['token'])->getJson('/crm/api/v2/auth/me')->assertUnauthorized();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('unknown refresh credentials return 401 and malformed credentials return 422', function () {
    $this->postJson('/crm/api/v2/auth/refresh', ['refresh_token' => Str::random(80)])->assertUnauthorized();
    $this->postJson('/crm/api/v2/auth/refresh', ['refresh_token' => 'malformed'])->assertUnprocessable()->assertJsonValidationErrors('refresh_token');
    $this->postJson('/crm/api/v2/auth/refresh')->assertUnprocessable()->assertJsonValidationErrors('refresh_token');
});

test('a lost refresh response can be retried with its persisted rotation ID without revoking the session', function () {
    $user = nativeAuthUser();
    $issued = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password'])->json();
    $payload = ['refresh_token' => $issued['refresh_token'], 'rotation_id' => (string) Str::uuid()];
    $rotated = $this->postJson('/crm/api/v2/auth/refresh', $payload)->assertOk()->json();
    $this->travel(10)->seconds();
    $this->postJson('/crm/api/v2/auth/refresh', $payload)->assertOk()
        ->assertJsonPath('token', $rotated['token'])->assertJsonPath('refresh_token', $rotated['refresh_token']);
    Auth::forgetGuards();
    $this->withToken($rotated['token'])->getJson('/crm/api/v2/auth/me')->assertOk();
});

test('retry grace expires and a replay with another rotation ID invalidates the device', function (string $condition) {
    $user = nativeAuthUser();
    $issued = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password'])->json();
    $payload = ['refresh_token' => $issued['refresh_token'], 'rotation_id' => (string) Str::uuid()];
    $rotated = $this->postJson('/crm/api/v2/auth/refresh', $payload)->assertOk()->json();
    if ($condition === 'expired-grace') {
        $this->travel(31)->seconds();
    } else {
        $payload['rotation_id'] = (string) Str::uuid();
    }
    $this->postJson('/crm/api/v2/auth/refresh', $payload)->assertUnauthorized();
    Auth::forgetGuards();
    $this->withToken($rotated['token'])->getJson('/crm/api/v2/auth/me')->assertUnauthorized();
})->with(['expired-grace', 'different-request']);
