<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiDocumentationAccess
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local') && ! Gate::allows('viewApiDocs')) {
            return response()->json(['message' => 'Forbidden.'], 403)->header('Cache-Control', 'no-store, private');
        }

        return $next($request);
    }
}
