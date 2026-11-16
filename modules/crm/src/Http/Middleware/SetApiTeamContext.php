<?php

namespace VentureDrake\LaravelCrm\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetApiTeamContext
{
    public function handle(Request $request, Closure $next)
    {
        if (config('laravel-crm.teams') && $request->hasHeader('X-Team-ID')) {
            abort_unless($request->user()?->team_id
                && (string) $request->header('X-Team-ID') === (string) $request->user()->team_id, 403);
        }

        return $next($request);
    }
}
