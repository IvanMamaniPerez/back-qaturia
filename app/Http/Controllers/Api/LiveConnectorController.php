<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLiveConnectorRequest;
use App\Http\Resources\LiveConnectorResource;
use App\Models\LiveConnector;
use App\Models\LiveSession;
use App\Services\LiveAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LiveConnectorController extends Controller
{
    public function __construct(private LiveAccessService $access) {}

    /** Explicitly authorize this user and instance for this session. A missing deviceId registers a server-owned device; trust flags confer no permission. */
    public function store(StoreLiveConnectorRequest $request, string $session): LiveConnectorResource
    {
        $session = $this->access->session($request->user(), $session);
        $data = $request->validated();
        $connector = DB::transaction(function () use ($request, $session, $data): LiveConnector {
            LiveSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $existing = $session->connectors()->where('connector_instance_id', $data['connectorInstanceId'])->first();
            if ($existing) {
                abort_unless($existing->user_id === $request->user()->id && $existing->connector_kind === $data['connectorKind']
                    && (! isset($data['deviceId']) || $existing->device_id === $data['deviceId']) && ! $existing->revoked_at, 409, 'CONNECTOR_IDENTITY_CONFLICT');

                return $existing;
            }
            $deviceId = $data['deviceId'] ?? (string) Str::uuid();
            if (isset($data['deviceId'])) {
                abort_unless(DB::table('devices')->where('id', $deviceId)->where('user_id', $request->user()->id)->exists(), 403, 'DEVICE_NOT_AUTHORIZED');
            } else {
                DB::table('devices')->insert(['id' => $deviceId, 'user_id' => $request->user()->id,
                    'platform' => $data['connectorKind'], 'created_at' => now(), 'updated_at' => now()]);
            }

            return LiveConnector::create([
                'business_id' => $request->user()->team_id, 'live_session_id' => $session->id,
                'user_id' => $request->user()->id, 'device_id' => $deviceId,
                'connector_instance_id' => $data['connectorInstanceId'], 'connector_kind' => $data['connectorKind'],
            ]);
        }, 3);

        return new LiveConnectorResource($connector);
    }

    public function destroy(Request $request, string $session, string $connector): LiveConnectorResource
    {
        $session = $this->access->session($request->user(), $session);
        $grant = DB::transaction(function () use ($request, $session, $connector): LiveConnector {
            LiveSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $grant = $session->connectors()->findOrFail($connector);
            abort_unless($grant->user_id === $request->user()->id || (string) $request->user()->business->owner_user_id === (string) $request->user()->id, 403);
            $grant->update(['revoked_at' => $grant->revoked_at ?? now()]);

            return $grant;
        });

        return new LiveConnectorResource($grant);
    }
}
