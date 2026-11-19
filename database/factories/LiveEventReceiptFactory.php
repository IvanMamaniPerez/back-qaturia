<?php

namespace Database\Factories;

use App\Models\LiveConnector;
use App\Models\LiveEvent;
use App\Models\LiveEventReceipt;
use App\Models\LiveSession;
use App\Services\LiveEventSchema;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LiveEventReceipt> */
class LiveEventReceiptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'live_event_id' => LiveEvent::factory(),
            'live_connector_id' => fn (array $attributes): string => LiveEvent::whereKey($attributes['live_event_id'])->firstOrFail()->live_connector_id,
            'business_id' => fn (array $attributes): string => LiveEvent::whereKey($attributes['live_event_id'])->firstOrFail()->business_id,
            'event_id' => fn (array $attributes): string => LiveEvent::whereKey($attributes['live_event_id'])->firstOrFail()->event_id,
            'connection_epoch' => (string) Str::uuid(), 'sequence' => 0,
            'envelope' => function (array $attributes): array {
                $event = LiveEvent::whereKey($attributes['live_event_id'])->firstOrFail();
                $connector = LiveConnector::whereKey($attributes['live_connector_id'])->firstOrFail();
                $session = LiveSession::whereKey($event->live_session_id)->firstOrFail();

                return [
                    'eventId' => $event->event_id, 'eventType' => $event->event_type, 'schemaVersion' => 1,
                    'idempotencyKey' => 'factory:'.$event->event_id, 'platform' => $session->platform,
                    'connectorKind' => $connector->connector_kind, 'connectorInstanceId' => $connector->connector_instance_id,
                    'connectionEpoch' => $attributes['connection_epoch'], 'sequence' => $attributes['sequence'],
                    'clientSessionId' => $session->client_session_id, 'sourceRoomId' => $session->source_room_id,
                    'sourceEventType' => $event->source_event_type, 'sourceMessageId' => $event->source_message_id,
                    'sourceKind' => $event->source_kind, 'observedAt' => $event->observed_at->format('Y-m-d\TH:i:s.u\Z'),
                    'payload' => $event->payload,
                ];
            },
            'idempotency_hash' => fn (array $attributes): string => hash('sha256', $attributes['envelope']['idempotencyKey']),
            'fingerprint' => fn (array $attributes): string => app(LiveEventSchema::class)->fingerprint($attributes['envelope']),
            'received_at' => now(),
        ];
    }
}
