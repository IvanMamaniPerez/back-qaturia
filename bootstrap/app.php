<?php

use App\Http\Middleware\EnsureFrontendSession;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use VentureDrake\LaravelCrm\Http\Middleware\ForceJsonResponse;
use VentureDrake\LaravelCrm\Http\Middleware\ResolveBusinessContext;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(replace: [EnsureFrontendRequestsAreStateful::class => EnsureFrontendSession::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ForceJsonResponse::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureFrontendSession::class);

        $tenant = ResolveBusinessContext::class;
        $middleware->appendToGroup('web', $tenant);
        $middleware->appendToGroup('web', AuthenticateSession::class);
        $middleware->appendToGroup('api', $tenant);
        $middleware->prependToPriorityList(SubstituteBindings::class, $tenant);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', trim(config('laravel-crm.route_prefix', 'crm'), '/').'/api/*')
                || $request->routeIs('api-contracts.*')
                || $request->expectsJson(),
        );
    })->create();
