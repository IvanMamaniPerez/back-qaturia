<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ApiTokenSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenSessionIsValid
{
    public function __construct(private ApiTokenSessionService $sessions) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && ! auth('web')->check()) {
            abort_unless($this->sessions->isValid($user), 401, 'The token session is no longer valid.');
        }

        return $next($request);
    }
}
