<?php

namespace App\Services;

use App\Models\ApiRefreshToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenSessionService
{
    /** @return array{token: string, access_token: string, token_type: string, expires_in: int, expires_at: string, refresh_token: string, refresh_expires_at: string, user: User} */
    public function issue(User $user, string $deviceName, ?string $familyId = null, ?CarbonInterface $refreshExpiresAt = null): array
    {
        return DB::transaction(function () use ($user, $deviceName, $familyId, $refreshExpiresAt): array {
            $minutes = max(1, (int) config('sanctum.access_token_minutes', 15));
            $expiresAt = now()->addMinutes($minutes);
            $refreshExpiresAt ??= now()->addDays(max(1, (int) config('sanctum.refresh_token_days', 30)));
            $token = $user->createToken($deviceName, ['*'], $expiresAt);
            $refreshToken = Str::random(80);
            ApiRefreshToken::create([
                'user_id' => $user->id,
                'access_token_id' => $token->accessToken->getKey(),
                'family_id' => $familyId ?? (string) Str::uuid(),
                'token_hash' => hash('sha256', $refreshToken),
                'password_fingerprint' => hash('sha256', $user->getAuthPassword()),
                'device_name' => $deviceName,
                'expires_at' => $refreshExpiresAt,
            ]);

            return [
                'token' => $token->plainTextToken,
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in' => $minutes * 60,
                'expires_at' => $expiresAt->toIso8601String(),
                'refresh_token' => $refreshToken,
                'refresh_expires_at' => $refreshExpiresAt->toIso8601String(),
                'user' => $user,
            ];
        });
    }

    /** @return array{token: string, access_token: string, token_type: string, expires_in: int, expires_at: string, refresh_token: string, refresh_expires_at: string, user: User}|null */
    public function refresh(string $plainTextToken, ?string $rotationId = null): ?array
    {
        return DB::transaction(function () use ($plainTextToken, $rotationId): ?array {
            $hash = hash('sha256', $plainTextToken);
            $identity = ApiRefreshToken::where('token_hash', $hash)->first();
            if (! $identity) {
                return null;
            }

            $user = User::withoutGlobalScopes()->whereKey($identity->user_id)->lockForUpdate()->first();
            $refresh = ApiRefreshToken::whereKey($identity->id)->lockForUpdate()->first();
            if (! $user || ! $refresh) {
                return null;
            }

            if ($refresh->expires_at->isPast() || ! $this->userCanRefresh($user, $refresh)) {
                $this->revokeFamily($refresh->family_id);

                return null;
            }

            if ($refresh->used_at) {
                if ($rotationId && $refresh->rotation_key_hash
                    && hash_equals($refresh->rotation_key_hash, hash('sha256', $rotationId))
                    && $refresh->used_at->greaterThan(now()->subSeconds(30))
                    && $refresh->rotation_result
                ) {
                    return [...$refresh->rotation_result, 'user' => $user];
                }
                $this->revokeFamily($refresh->family_id);

                return null;
            }

            $refresh->update(['used_at' => now()]);
            $result = $this->issue($user, $refresh->device_name, $refresh->family_id, $refresh->expires_at);
            if ($rotationId) {
                $refresh->update([
                    'rotation_key_hash' => hash('sha256', $rotationId),
                    'rotation_result' => array_diff_key($result, ['user' => true]),
                ]);
            }
            PersonalAccessToken::whereKey($refresh->access_token_id)->delete();

            return $result;
        }, 3);
    }

    public function logout(string $plainTextToken): void
    {
        DB::transaction(function () use ($plainTextToken): void {
            $refresh = ApiRefreshToken::where('token_hash', hash('sha256', $plainTextToken))->first();
            if ($refresh) {
                User::withoutGlobalScopes()->whereKey($refresh->user_id)->lockForUpdate()->first();
                $this->revokeFamily($refresh->family_id);
            }
        }, 3);
    }

    public function revokeCurrent(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->first();
            $tokenId = $user->currentAccessToken()->getKey();
            $refresh = ApiRefreshToken::where('access_token_id', $tokenId)->first();
            if ($refresh) {
                $this->revokeFamily($refresh->family_id);
            } else {
                PersonalAccessToken::whereKey($tokenId)->delete();
            }
        }, 3);
    }

    public function isValid(User $user): bool
    {
        $refresh = ApiRefreshToken::where('access_token_id', $user->currentAccessToken()->getKey())->first();
        if (! $refresh) {
            return true;
        }
        if ($this->userCanRefresh($user, $refresh)) {
            return true;
        }

        $this->logoutByFamily($refresh->family_id, $user);

        return false;
    }

    private function userCanRefresh(User $user, ApiRefreshToken $refresh): bool
    {
        return (bool) $user->crm_access
            && (! config('laravel-crm.teams') || ($user->team_id && $user->business))
            && hash_equals($refresh->password_fingerprint, hash('sha256', $user->getAuthPassword()));
    }

    private function logoutByFamily(string $familyId, User $user): void
    {
        DB::transaction(function () use ($familyId, $user): void {
            User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->first();
            $this->revokeFamily($familyId);
        }, 3);
    }

    private function revokeFamily(string $familyId): void
    {
        PersonalAccessToken::whereIn('id', ApiRefreshToken::where('family_id', $familyId)->select('access_token_id'))->delete();
        ApiRefreshToken::where('family_id', $familyId)->delete();
    }
}
