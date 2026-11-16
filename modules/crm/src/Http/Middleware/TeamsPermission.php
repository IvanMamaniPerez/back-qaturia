<?php

namespace VentureDrake\LaravelCrm\Http\Middleware;

use Closure;

class TeamsPermission
{
    public function handle($request, Closure $next)
    {
        // ResolveBusinessContext owns setup and cleanup, including anonymous
        // signed documents. Do not overwrite it with a staff session here.
        return $next($request);
    }
}
