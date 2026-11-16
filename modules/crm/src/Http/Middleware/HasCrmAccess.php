<?php

namespace VentureDrake\LaravelCrm\Http\Middleware;

use Closure;

class HasCrmAccess
{
    public function handle($request, Closure $next)
    {
        if (auth()->guest()) {
            return $next($request);
        }
        $user = auth()->user();
        if (config('laravel-crm.teams')) {
            abort_unless($user->crm_access && $user->team_id && $user->business, 403, 'A business and CRM access are required.');
        } else {
            abort_unless($user->crm_access || config('laravel-crm.crm_owner') === $user->email, 403);
            if (config('laravel-crm.crm_owner') === $user->email && ! $user->hasRole('Owner')) {
                $user->assignRole('Owner');
                $user->forceFill(['crm_access' => true])->save();
            }
        }

        return $next($request);
    }
}
