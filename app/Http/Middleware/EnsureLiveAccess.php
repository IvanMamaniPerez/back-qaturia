<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\LiveAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLiveAccess
{
    public function __construct(private LiveAccessService $access) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->access->allows($user, $request->isMethod('GET') ? 'live.read' : 'live.ingest'), 403, 'LIVE_PERMISSION_DENIED');
        if ($request->bearerToken()) {
            $allowed = $request->isMethod('GET') ? ($user->tokenCan('live.read') || $user->tokenCan('live.ingest')) : $user->tokenCan('live.ingest');
            abort_unless($allowed, 403, 'LIVE_TOKEN_ABILITY_DENIED');
        }
        if ($request->hasHeader('X-Team-ID')) {
            abort_unless((string) $request->header('X-Team-ID') === (string) $user->team_id, 403, 'BUSINESS_MISMATCH');
        }
        abort_if(strlen($request->getContent()) > config('live.max_batch_bytes'), 413, 'BATCH_TOO_LARGE');

        return $next($request);
    }
}
