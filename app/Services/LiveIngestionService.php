<?php

namespace App\Services;

use App\Models\LiveConnector;
use App\Models\LiveEvent;
use App\Models\LiveEventReceipt;
use App\Models\LiveSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LiveIngestionService
{
    public function __construct(private LiveEventSchema $schema, private LiveProjectionService $projections) {}

    /**
     * @param  list<mixed>  $events
     * @return list<array{eventId: string|null, status: string, code: string|null, canonicalEventId: string|null, errors: array<string, list<string>>}>
     */
    public function ingest(User $user, LiveSession $session, array $events): array
    {
        return DB::transaction(function () use ($user, $session, $events): array {
            $session = LiveSession::where('business_id', $user->team_id)->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $results = [];
            foreach ($events as $input) {
                $results[] = $this->receive($user, $session, $input);
            }

            return $results;
        }, 3);
    }

    /**
     * @return array{eventId: string|null, status: string, code: string|null, canonicalEventId: string|null, errors: array<string, list<string>>}
     */
    private function receive(User $user, LiveSession $session, mixed $input): array
    {
        $eventId = is_array($input) && is_string($input['eventId'] ?? null) ? $input['eventId'] : null;
        $result = ['eventId' => $eventId, 'status' => 'rejected', 'code' => null, 'canonicalEventId' => null, 'errors' => []];
        if (! is_array($input) || array_is_list($input)) {
            return [...$result, 'code' => 'INVALID_EVENT'];
        }
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > config('live.max_event_bytes')) {
            return [...$result, 'code' => 'EVENT_TOO_LARGE'];
        }
        if (($input['schemaVersion'] ?? null) !== 1) {
            return [...$result, 'code' => 'UNSUPPORTED_SCHEMA_VERSION'];
        }
        if (! is_string($input['eventType'] ?? null) || ! isset(LiveEventSchema::SOURCES[$input['eventType']])) {
            return [...$result, 'code' => 'UNSUPPORTED_EVENT_TYPE'];
        }
        try {
            $event = $this->schema->validate($input);
        } catch (ValidationException $exception) {
            return [...$result, 'code' => 'INVALID_EVENT', 'errors' => $exception->errors()];
        }
        if (($event['eventType'] === 'live.caption.observed.v1' && ! config('live.captions_enabled'))
            || ($event['sourceEventType'] === 'VIEWER_PICKS_UPDATE' && ! config('live.viewer_picks_enabled'))) {
            return [...$result, 'code' => 'FEATURE_DISABLED'];
        }
        if ($event['clientSessionId'] !== $session->client_session_id || $event['sourceRoomId'] !== $session->source_room_id || $event['platform'] !== $session->platform) {
            return [...$result, 'code' => 'SESSION_MISMATCH'];
        }
        $connector = LiveConnector::where('business_id', $user->team_id)->where('live_session_id', $session->id)
            ->where('connector_instance_id', $event['connectorInstanceId'])->where('user_id', $user->id)
            ->where('connector_kind', $event['connectorKind'])->whereNull('revoked_at')->lockForUpdate()->first();
        if (! $connector || ! DB::table('devices')->where('id', $connector->device_id)->where('user_id', $user->id)->exists()) {
            return [...$result, 'code' => 'CONNECTOR_NOT_AUTHORIZED'];
        }
        $fingerprint = $this->schema->fingerprint($event);
        $idempotencyHash = hash('sha256', $event['idempotencyKey']);
        $receipts = LiveEventReceipt::where('business_id', $user->team_id)->where(function ($query) use ($event, $connector, $idempotencyHash): void {
            $query->where('event_id', $event['eventId'])->orWhere(function ($query) use ($event, $connector, $idempotencyHash): void {
                $query->where('live_connector_id', $connector->id)->where(function ($query) use ($event, $idempotencyHash): void {
                    $query->where('idempotency_hash', $idempotencyHash)->orWhere(function ($query) use ($event): void {
                        $query->where('connection_epoch', $event['connectionEpoch'])->where('sequence', $event['sequence']);
                    });
                });
            });
        })->get();
        if ($receipts->isNotEmpty()) {
            if ($receipts->contains(fn (LiveEventReceipt $receipt): bool => $receipt->fingerprint !== $fingerprint || $receipt->live_connector_id !== $connector->id)) {
                return [...$result, 'code' => 'IDEMPOTENCY_CONFLICT'];
            }

            return [...$result, 'status' => 'duplicate', 'canonicalEventId' => $receipts->first()->live_event_id];
        }
        $sourceId = $event['sourceMessageId'] ?? null;
        $identity = $sourceId !== null && $sourceId !== '' && $event['sourceKind'] !== 'connector' && $event['eventType'] !== 'live.connector.status.v1' ? 'source_message' : 'transport_only';
        $namespace = match ($event['eventType']) {
            'live.social.action.v1', 'live.stream.control.v1' => $event['eventType'].':'.$event['payload']['action'],
            'live.moderation.observed.v1' => $event['eventType'].':'.$event['sourceEventType'],
            'live.ended.v1' => $event['eventType'].':'.$event['payload']['kind'],
            default => $event['eventType'],
        };
        $canonical = $identity === 'source_message'
            ? hash('sha256', $namespace."\0".$sourceId)
            : hash('sha256', $connector->id."\0".$event['connectionEpoch']."\0".$event['sequence']);
        $stored = LiveEvent::where('live_session_id', $session->id)->where('canonical_key', $canonical)->first();
        $duplicate = $stored !== null;
        $receivedAt = CarbonImmutable::now('UTC');
        if (! $stored) {
            $stored = LiveEvent::create([
                'business_id' => $user->team_id, 'live_session_id' => $session->id, 'live_connector_id' => $connector->id,
                'event_id' => $event['eventId'], 'event_type' => $event['eventType'], 'schema_version' => $event['schemaVersion'],
                'source_event_type' => $event['sourceEventType'], 'source_message_id' => $sourceId, 'source_kind' => $event['sourceKind'],
                'target_message_id' => $event['eventType'] === 'live.moderation.observed.v1' ? ($event['payload']['targetMessageId'] ?? null) : null,
                'occurred_at_source' => $event['occurredAtSource'] ?? null,
                'occurred_at' => isset($event['occurredAt']) ? CarbonImmutable::parse($event['occurredAt'])->utc() : null,
                'observed_at' => CarbonImmutable::parse($event['observedAt'])->utc(), 'received_at' => $receivedAt,
                'canonical_key' => $canonical, 'identity_quality' => $identity, 'payload' => $event['payload'], 'processing_status' => 'projected',
            ]);
            $this->projections->apply($session, $stored);
        }
        LiveEventReceipt::create([
            'business_id' => $user->team_id, 'live_connector_id' => $connector->id, 'live_event_id' => $stored->id,
            'event_id' => $event['eventId'], 'idempotency_hash' => $idempotencyHash, 'connection_epoch' => $event['connectionEpoch'],
            'sequence' => $event['sequence'], 'fingerprint' => $fingerprint, 'envelope' => $event, 'received_at' => $receivedAt,
        ]);
        $this->projections->observeCoverage($session, $connector->id, $event);

        return [...$result, 'status' => $duplicate ? 'duplicate' : 'accepted', 'canonicalEventId' => $stored->id];
    }
}
