<?php

namespace App\Http\Resources;

use App\Models\LiveConnector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LiveConnector */
class LiveConnectorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'liveSessionId' => $this->live_session_id, 'deviceId' => $this->device_id,
            'connectorInstanceId' => $this->connector_instance_id, 'connectorKind' => $this->connector_kind,
            'observedState' => $this->observed_state, 'stateObservedAt' => $this->state_observed_at?->toISOString(),
            'revokedAt' => $this->revoked_at?->toISOString(),
        ];
    }
}
