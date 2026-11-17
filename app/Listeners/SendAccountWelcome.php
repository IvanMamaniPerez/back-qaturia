<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\AccountWelcomeNotification;
use Illuminate\Auth\Events\Registered;

class SendAccountWelcome
{
    public function handle(Registered $event): void
    {
        if ($event->user instanceof User) {
            $event->user->notify(new AccountWelcomeNotification);
        }
    }
}
