<?php

use App\Models\Business;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Support\BusinessContext;

beforeEach(function () {
    config([
        'laravel-crm.teams' => true,
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
    ]);
});

test('React can log in with CSRF and session cookies read its business data and log out', function () {
    $business = Business::create(['name' => 'React Business']);
    $otherBusiness = Business::create(['name' => 'Other Business']);
    $user = User::factory()->create(['crm_access' => true, 'team_id' => $business->id]);
    $user->givePermissionTo(Permission::create(['name' => 'view crm people', 'guard_name' => 'web']));
    app(BusinessContext::class)->run($business->id, fn () => Person::create(['first_name' => 'Local']));
    app(BusinessContext::class)->run($otherBusiness->id, fn () => Person::create(['first_name' => 'Foreign']));

    // Exercise CSRF verification, which Laravel normally skips in HTTP tests.
    app()->instance('env', 'local');
    $this->withCredentials()->withHeader('Origin', 'http://localhost:5173');
    $csrf = $this->getJson('/sanctum/csrf-cookie')->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true')
        ->assertCookie('XSRF-TOKEN');
    $cookieName = config('session.cookie');
    $oldSessionId = $csrf->getCookie($cookieName)->getValue();
    $this->withCookie($cookieName, $oldSessionId)
        ->withHeader('X-XSRF-TOKEN', $csrf->getCookie('XSRF-TOKEN', false)->getValue());

    $login = $this->postJson('/crm/api/v2/auth/login', [
        'email' => $user->email, 'password' => 'password', 'remember' => true,
    ])->assertOk()->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.business.id', $business->id)
        ->assertJsonMissingPath('token')->assertJsonMissingPath('user.password');
    expect($login->getCookie($cookieName)->getValue())->not->toBe($oldSessionId);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->withCookie($cookieName, $login->getCookie($cookieName)->getValue())
        ->withHeader('X-XSRF-TOKEN', $login->getCookie('XSRF-TOKEN', false)->getValue());

    Auth::forgetGuards();
    $this->getJson('/crm/api/v2/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
    Auth::forgetGuards();
    $this->getJson('/crm/api/v2/people')->assertOk()->assertJsonCount(1, 'data')
        ->assertSee('Local')->assertDontSee('Foreign');
    Auth::forgetGuards();
    $this->withHeader('X-Team-ID', $otherBusiness->id)->getJson('/crm/api/v2/auth/me')->assertForbidden();
    $this->withoutHeader('X-Team-ID');

    Auth::forgetGuards();
    $this->deleteJson('/crm/api/v2/auth/token')->assertNoContent();
    Auth::forgetGuards();
    $logout = $this->postJson('/crm/api/v2/auth/logout')->assertNoContent();
    $this->withCookie($cookieName, $logout->getCookie($cookieName)->getValue());
    Auth::forgetGuards();
    $this->getJson('/crm/api/v2/auth/me')->assertUnauthorized();
});

test('SPA login rejects a missing CSRF token with 419', function () {
    app()->instance('env', 'local');
    $this->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/crm/api/v2/auth/login', ['email' => 'user@example.test', 'password' => 'password'])
        ->assertStatus(419);
});

test('SPA login rejects an unconfigured origin with 419', function () {
    $this->withHeader('Origin', 'https://untrusted.example')
        ->postJson('/crm/api/v2/auth/login', ['email' => 'user@example.test', 'password' => 'password'])
        ->assertStatus(419)->assertJsonPath('message', 'A stateful frontend origin and CSRF cookie are required.');
});

test('API clients can issue use and revoke a real Sanctum token', function (string $logoutMethod, string $logoutPath) {
    $business = Business::create(['name' => 'API Business']);
    $user = User::factory()->create(['crm_access' => true, 'team_id' => $business->id]);
    $otherToken = $user->createToken('other-device');

    $response = $this->postJson('/crm/api/v2/auth/token', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'react-client',
    ])->assertCreated()->assertJsonPath('user.id', $user->id)
        ->assertJsonMissingPath('user.password');
    $token = $response->json('token');
    $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'react-client']);
    $this->assertDatabaseMissing('personal_access_tokens', ['token' => $token]);

    Auth::forgetGuards();
    $this->withToken($token)->getJson('/crm/api/v2/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
    Auth::forgetGuards();
    $this->json($logoutMethod, $logoutPath)->assertNoContent();
    $this->assertDatabaseCount('personal_access_tokens', 1);
    $this->assertModelExists($otherToken->accessToken);

    Auth::forgetGuards();
    $this->getJson('/crm/api/v2/auth/me')->assertUnauthorized();
})->with([
    'token revocation' => ['DELETE', '/crm/api/v2/auth/token'],
    'logout endpoint' => ['POST', '/crm/api/v2/auth/logout'],
]);

test('authentication rejects invalid credentials or accounts without CRM access or a business with 422', function (string $path, string $condition) {
    $business = Business::create(['name' => 'Login Business']);
    $user = User::factory()->create([
        'crm_access' => $condition !== 'no-access',
        'team_id' => $condition === 'no-business' ? null : $business->id,
    ]);
    $this->withHeader('Origin', 'http://localhost:5173')->postJson($path, [
        'email' => $condition === 'unknown-email' ? 'unknown@example.test' : $user->email,
        'password' => $condition === 'wrong-password' ? 'wrong-password' : 'password',
    ])->assertUnprocessable()->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');

    $this->assertGuest('web');
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['/crm/api/v2/auth/login', '/crm/api/v2/auth/token'])
    ->with(['wrong-password', 'unknown-email', 'no-access', 'no-business']);

test('authentication validates required credentials with 422', function (string $path) {
    $this->withHeader('Origin', 'http://localhost:5173')->postJson($path)
        ->assertUnprocessable()->assertJsonValidationErrors([
            'email' => 'The email field is required.',
            'password' => 'The password field is required.',
        ]);
})->with(['/crm/api/v2/auth/login', '/crm/api/v2/auth/token']);

test('authentication validates the remember option with 422', function () {
    $this->withHeader('Origin', 'http://localhost:5173')->postJson('/crm/api/v2/auth/login', [
        'email' => 'user@example.test', 'password' => 'password', 'remember' => 'invalid',
    ])->assertUnprocessable()->assertJsonValidationErrors('remember');
});

test('failed login attempts across both endpoints are limited per account with 429', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/crm/api/v2/auth/login', [
            'email' => 'unknown@example.test', 'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/crm/api/v2/auth/token', ['email' => 'unknown@example.test', 'password' => 'wrong-password'])
        ->assertTooManyRequests()->assertJsonValidationErrors('email');
});

test('private authentication endpoints return JSON 401 to guests', function (string $method, string $path) {
    $this->call($method, $path)->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
})->with([
    ['GET', '/crm/api/v2/auth/me'],
    ['POST', '/crm/api/v2/auth/logout'],
    ['DELETE', '/crm/api/v2/auth/token'],
]);

test('CORS permits React preflight requests with credentials', function (string $origin) {
    $this->options('/crm/api/v2/auth/login', [], [
        'Origin' => $origin,
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'Content-Type,X-XSRF-TOKEN,Authorization',
    ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $origin)
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
})->with(['http://localhost:5173', 'http://127.0.0.1:5173', 'http://localhost:3000', 'http://127.0.0.1:3000']);

test('CORS does not allow an unconfigured origin', function () {
    $this->options('/crm/api/v2/auth/login', [], [
        'Origin' => 'https://untrusted.example', 'Access-Control-Request-Method' => 'POST',
    ])->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('a revoked CRM permission prevents access even with an existing token', function () {
    $business = Business::create(['name' => 'Disabled Business']);
    $user = User::factory()->create(['crm_access' => false, 'team_id' => $business->id]);
    $this->withToken($user->createToken('disabled')->plainTextToken)
        ->getJson('/crm/api/v2/auth/me')->assertForbidden();

    Auth::forgetGuards();
    $this->postJson('/crm/api/v2/auth/logout')->assertNoContent();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('API login accepts email addresses with different letter case', function (string $path, int $status) {
    $business = Business::create(['name' => 'Case Insensitive Login']);
    $user = User::factory()->create(['crm_access' => true, 'team_id' => $business->id]);
    $this->withHeader('Origin', 'http://localhost:5173')->postJson($path, [
        'email' => strtoupper($user->email), 'password' => 'password',
    ])->assertStatus($status)->assertJsonPath('user.id', $user->id);
})->with([
    'session' => ['/crm/api/v2/auth/login', 200],
    'token' => ['/crm/api/v2/auth/token', 201],
]);
