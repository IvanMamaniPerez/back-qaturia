<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AccountPasswordService
{
    public function sendResetLink(string $email): void
    {
        DB::transaction(function () use ($email): void {
            $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->lockForUpdate()->first();
            Password::broker()->sendResetLink(['email' => $user->email ?? $email]);
        }, 3);
    }

    /** @param array{email: string, token: string, password: string, password_confirmation: string} $credentials */
    public function reset(array $credentials): string
    {
        return DB::transaction(function () use ($credentials): string {
            $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [Str::lower($credentials['email'])])->lockForUpdate()->first();
            $credentials['email'] = $user->email ?? $credentials['email'];

            return Password::broker()->reset($credentials, function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            });
        }, 3);
    }
}
