<?php

namespace VentureDrake\LaravelCrm\Http\Controllers\Api\V2;

use App\Http\Requests\RefreshTokenRequest;
use App\Models\User;
use App\Services\ApiTokenSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use VentureDrake\LaravelCrm\Http\Requests\Api\V2\IssueTokenRequest;
use VentureDrake\LaravelCrm\Http\Requests\Api\V2\LoginRequest;

class AuthController extends ApiController
{
    #[\Dedoc\Scramble\Attributes\Response(status: 201, type: 'array{token: string, access_token: string, token_type: \'Bearer\', expires_in: int, expires_at: string, refresh_token: string, refresh_expires_at: string, user: array{id: string, name: string, email: string, team_id: string|null, business: array{id: string, name: string, legal_name: string|null, tax_id: string|null}|null}}')]
    public function issueToken(IssueTokenRequest $request, ApiTokenSessionService $sessions): JsonResponse
    {
        $user = $this->authenticate($request);
        $deviceName = $request->validated('device_name') ?? ($request->userAgent() ?: 'api-token');
        $credentials = $sessions->issue($user, $deviceName);

        return response()->json([
            ...$credentials,
            'user' => $this->transformUser($user),
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    #[\Dedoc\Scramble\Attributes\Response(status: 200, type: 'array{token: string, access_token: string, token_type: \'Bearer\', expires_in: int, expires_at: string, refresh_token: string, refresh_expires_at: string, user: array{id: string, name: string, email: string, team_id: string|null, business: array{id: string, name: string, legal_name: string|null, tax_id: string|null}|null}}')]
    public function refresh(RefreshTokenRequest $request, ApiTokenSessionService $sessions): JsonResponse
    {
        $credentials = $sessions->refresh($request->validated('refresh_token'), $request->validated('rotation_id'));
        abort_unless($credentials !== null, 401, 'The refresh token is invalid or expired.');

        return response()->json([...$credentials, 'user' => $this->transformUser($credentials['user'])])
            ->header('Cache-Control', 'no-store, private');
    }

    public function logoutTokenSession(RefreshTokenRequest $request, ApiTokenSessionService $sessions): Response
    {
        $sessions->logout($request->validated('refresh_token'));

        return response()->noContent();
    }

    public function login(LoginRequest $request): JsonResponse
    {
        abort_unless($request->hasSession(), 419, 'A stateful frontend origin and CSRF cookie are required.');

        $user = $this->authenticate($request);
        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return response()->json(['user' => $this->transformUser($user)])->header('Cache-Control', 'no-store, private');
    }

    public function logout(Request $request, ApiTokenSessionService $sessions): Response
    {
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } else {
            return $this->revokeToken($request, $sessions);
        }

        return response()->noContent();
    }

    protected function authenticate(IssueTokenRequest $request): User
    {
        $credentials = $request->validated();

        $perAccountKey = 'laravel-crm:api-token:'.Str::lower($credentials['email']);
        $perAccountLimit = (int) config('laravel-crm.api.token_attempts_per_account', 5);
        $perAccountDecay = (int) config('laravel-crm.api.token_attempts_decay_seconds', 600);

        if (RateLimiter::tooManyAttempts($perAccountKey, $perAccountLimit)) {
            $seconds = RateLimiter::availableIn($perAccountKey);

            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Try again in {$seconds} seconds."],
            ])->status(429);
        }

        $userModel = config('auth.providers.users.model');

        $user = $userModel::where('email', $credentials['email'])->first();
        $user ??= $userModel::whereRaw('LOWER(email) = ?', [Str::lower($credentials['email'])])->first();

        if (! $user
            || ! Hash::check($credentials['password'], $user->password)
            || ! (bool) ($user->crm_access ?? false)
            || (config('laravel-crm.teams') && (! $user->team_id || ! $user->business))
        ) {
            RateLimiter::hit($perAccountKey, $perAccountDecay);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($perAccountKey);

        return $user;
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->transformUser($request->user()),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function revokeToken(Request $request, ApiTokenSessionService $sessions): Response
    {
        if (! Auth::guard('web')->check()) {
            $sessions->revokeCurrent($request->user());
        }

        return response()->noContent();
    }

    /** @return array{id: string, name: string, email: string, team_id: ?string, business: ?array{id: string, name: string, legal_name: ?string, tax_id: ?string}} */
    protected function transformUser(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'team_id' => $user->team_id,
            'business' => $user->business ? [
                'id' => $user->business->id,
                'name' => $user->business->name,
                'legal_name' => $user->business->legal_name,
                'tax_id' => $user->business->tax_id,
            ] : null,
        ];
    }
}
