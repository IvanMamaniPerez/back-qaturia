<?php

namespace VentureDrake\LaravelCrm\Http\Middleware;

use Closure;

class Settings
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}
