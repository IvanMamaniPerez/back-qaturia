<?php

namespace VentureDrake\LaravelCrm\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Support\BusinessContext;
use VentureDrake\LaravelCrm\Support\PortalTeam;

class ResolveBusinessContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('laravel-crm.teams')) {
            return $next($request);
        }

        $id = $request->user()?->team_id;
        $prefix = config('laravel-crm.db_table_prefix');
        $route = $request->route();
        $name = $route?->getName() ?? '';

        foreach (['quotes' => 'quote', 'invoices' => 'invoice', 'purchase-orders' => 'purchaseOrder'] as $segment => $parameter) {
            if (str_starts_with($name, 'laravel-crm.portal.'.$segment.'.')) {
                abort_unless($request->hasValidSignature(), 401);
                $table = $prefix.str_replace('-', '_', $segment);
                $id = DB::table($table)->where('external_id', $route->parameter($parameter))->value('team_id');
                abort_unless($id, 404);
            }
        }

        if ($request->is('p/chat/*')) {
            $id = DB::table($prefix.'chat_widgets')
                ->where('public_key', $route?->parameter('publicKey'))
                ->where('is_active', true)->whereNull('deleted_at')->value('team_id');
            abort_unless($id, 404);
        }

        if (str_starts_with($name, 'laravel-crm.email-tracking.') || str_starts_with($name, 'laravel-crm.sms-tracking.')) {
            $channel = str_contains($name, 'sms-tracking') ? 'sms' : 'email';
            $id = DB::table($prefix.$channel.'_campaign_recipients')
                ->where('tracking_token', $route?->parameter('token'))->value('team_id');
            abort_unless($id, 404);
        }

        if (str_starts_with($name, 'laravel-crm.portal.features.')) {
            if ($externalId = $route?->parameter('feature')) {
                $id = DB::table($prefix.'features')->where('external_id', $externalId)
                    ->where('is_public', true)->whereNull('deleted_at')->value('team_id');
            } else {
                $id = $route?->parameter('portalTeam') ?? $request->session()->get('laravel-crm.portal_team_id', $id);
                $id ??= PortalTeam::resolve();
            }
        }

        if ($code = $route?->parameter('code')) {
            if (str_contains($name, '.invitations.')) {
                $id = DB::table($prefix.'user_invitations')->where('code', $code)
                    ->whereNull('accepted_at')->whereNull('deleted_at')
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->value('team_id');
            }
        }

        if ($id !== null) {
            abort_unless(Str::isUuid($id), 404);
            abort_unless(Business::whereKey($id)->exists(), 403);
        }

        return app(BusinessContext::class)->run($id, fn () => $next($request));
    }
}
