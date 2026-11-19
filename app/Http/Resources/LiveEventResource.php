<?php

namespace App\Http\Resources;

use App\Models\LiveEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LiveEvent */
class LiveEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'eventId' => $this->event_id, 'eventType' => $this->event_type,
            'liveSessionId' => $this->live_session_id, 'connectorId' => $this->live_connector_id, 'schemaVersion' => $this->schema_version,
            'sourceEventType' => $this->source_event_type, 'sourceMessageId' => $this->source_message_id,
            'sourceKind' => $this->source_kind, 'identityQuality' => $this->identity_quality,
            'occurredAt' => $this->occurred_at?->toISOString(), 'occurredAtSource' => $this->occurred_at_source,
            'observedAt' => $this->observed_at->toISOString(), 'receivedAt' => $this->received_at->toISOString(),
            'processingStatus' => $this->processing_status,
            'payload' => $this->payload,
            'commentDeleted' => $this->getAttribute('comment_deleted_at') !== null,
        ];
    }
}
