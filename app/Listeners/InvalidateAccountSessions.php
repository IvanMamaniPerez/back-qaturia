<?php

namespace App\Listeners;

use App\Models\ApiRefreshToken;
use App\Models\User;
use App\Notifications\AccountPasswordChangedNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;

class InvalidateAccountSessions
{
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;
        if (! $user instanceof User) {
            return;
        }
        DB::transaction(function () use ($user): void {
            User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            ApiRefreshToken::where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
            $user->notify((new AccountPasswordChangedNotification)->afterCommit());
        }, 3);
    }
}
