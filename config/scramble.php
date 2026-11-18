<?php

use App\Http\Middleware\EnsureApiDocumentationAccess;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [
    'api_path' => trim(config('laravel-crm.route_prefix', 'crm'), '/').'/api/v2',
    'info' => [
        'version' => '2.0.0',
        'description' => 'Qaturia CRM API v2. Send Accept: application/json. Protected operations accept Sanctum bearer tokens or a stateful frontend session. Session writes require the CSRF cookie and X-XSRF-TOKEN header. Tenant and permissions are resolved by the authenticated user; X-Team-ID can only confirm the current team. Monetary inputs use major currency units; inspect each resource for serialized amounts. See docs/API_CONTRACTS.md for restrictions and known inference limitations.',
    ],
    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
    'middleware' => ['web', EnsureApiDocumentationAccess::class],
];
