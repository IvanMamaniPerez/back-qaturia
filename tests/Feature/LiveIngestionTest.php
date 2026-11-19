<?php

use App\Models\Business;
use App\Models\LiveConnector;
use App\Models\LiveEvent;
use App\Models\LiveSession;
use App\Models\User;
use App\Services\ApiTokenSessionService;
use App\Services\LiveProjectionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function liveContext(): array
{
    $connector = LiveConnector::factory()->create();
    $session = LiveSession::findOrFail($connector->live_session_id);
    $user = User::findOrFail($connector->user_id);

    return [$user, $session, $connector];
}

function liveEnvelope(LiveSession $session, LiveConnector $connector, string $type = 'live.comment.received.v1', string $source = 'CHAT', array $payload = ['text' => 'Hola', 'authorId' => 'viewer', 'authorName' => 'Viewer'], int $sequence = 1): array
{
    return [
        'eventId' => (string) Str::uuid(), 'eventType' => $type, 'schemaVersion' => 1,
        'idempotencyKey' => (string) Str::uuid(), 'platform' => 'tiktok', 'connectorKind' => $connector->connector_kind,
        'connectorInstanceId' => $connector->connector_instance_id, 'connectionEpoch' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'sequence' => $sequence, 'clientSessionId' => $session->client_session_id, 'sourceRoomId' => $session->source_room_id,
        'sourceEventType' => $source, 'sourceMessageId' => (string) Str::uuid(), 'sourceKind' => 'platform',
        'observedAt' => '2026-11-18T12:00:00.000Z', 'payload' => $payload,
    ];
}

function liveBatch(LiveSession $session, array $events): array
{
    return ['batchId' => (string) Str::uuid(), 'contractVersion' => 1, 'liveSessionId' => $session->id, 'events' => $events];
}

function liveToken(User $user): string
{
    return app(ApiTokenSessionService::class)->issue($user, 'LIVE test')['access_token'];
}

test('LIVE requires authentication and refuses a revoked bearer token with 401', function () {
    [$user, $session, $connector] = liveContext();
    $batch = liveBatch($session, [liveEnvelope($session, $connector)]);
    $this->postJson('/api/v1/live/ingest/batches', $batch)->assertUnauthorized();
    $token = liveToken($user);
    $user->tokens()->delete();
    $this->withToken($token)->postJson('/api/v1/live/ingest/batches', $batch)->assertUnauthorized();
    $this->assertDatabaseCount('live_events', 0);
});

test('LIVE reuses password fingerprint session revocation with 401', function () {
    [$user, $session, $connector] = liveContext();
    $token = liveToken($user);
    $user->forceFill(['password' => 'another-password'])->save();
    $this->withToken($token)->postJson('/api/v1/live/ingest/batches', liveBatch($session, [liveEnvelope($session, $connector)]))->assertUnauthorized();
    $this->assertDatabaseCount('live_events', 0);
});

test('LIVE hides foreign sessions from ingestion history and registration with 404', function () {
    [$user] = liveContext();
    [, $foreign, $connector] = liveContext();
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($foreign, [liveEnvelope($foreign, $connector)]))->assertNotFound();
    $this->getJson('/api/v1/live/sessions/'.$foreign->id)->assertNotFound();
    $this->getJson('/api/v1/live/sessions/'.$foreign->id.'/events')->assertNotFound();
    $this->postJson('/api/v1/live/sessions/'.$foreign->id.'/connectors', ['connectorInstanceId' => (string) Str::uuid(), 'connectorKind' => 'desktop'])->assertNotFound();
    $this->assertDatabaseCount('live_events', 0);
});

test('LIVE requires explicit ingestion permission for a business member with 403', function () {
    [$owner, $session, $connector] = liveContext();
    $member = User::factory()->create(['team_id' => $owner->team_id, 'crm_access' => true]);
    $this->withToken(liveToken($member))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [liveEnvelope($session, $connector)]))->assertForbidden();
    $this->assertDatabaseCount('live_events', 0);
});

test('LIVE registers a server device and rejects foreign devices regardless of trust flags', function () {
    [$owner, $session] = liveContext();
    [, , $foreign] = liveContext();
    DB::table('devices')->where('id', $foreign->device_id)->update(['is_trusted' => true]);
    $this->withToken(liveToken($owner))->postJson('/api/v1/live/sessions/'.$session->id.'/connectors', [
        'connectorInstanceId' => (string) Str::uuid(), 'connectorKind' => 'desktop', 'deviceId' => $foreign->device_id,
    ])->assertForbidden();
    $instance = (string) Str::uuid();
    $response = $this->postJson('/api/v1/live/sessions/'.$session->id.'/connectors', ['connectorInstanceId' => $instance, 'connectorKind' => 'android'])->assertCreated();
    $this->assertDatabaseHas('devices', ['id' => $response->json('data.deviceId'), 'user_id' => $owner->id, 'is_trusted' => false]);
    $this->assertDatabaseHas('live_connectors', ['connector_instance_id' => $instance, 'business_id' => $owner->team_id]);
});

test('LIVE rejects unknown or revoked instances and wrong rooms individually', function (string $condition, string $code) {
    [$user, $session, $connector] = liveContext();
    $event = liveEnvelope($session, $connector);
    if ($condition === 'unknown') {
        $event['connectorInstanceId'] = (string) Str::uuid();
    }
    if ($condition === 'revoked') {
        $connector->update(['revoked_at' => now()]);
    }
    if ($condition === 'room') {
        $event['sourceRoomId'] = 'foreign-room';
    }
    if ($condition === 'session') {
        $event['clientSessionId'] = (string) Str::uuid();
    }
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$event]))
        ->assertOk()->assertJsonPath('results.0.status', 'rejected')->assertJsonPath('results.0.code', $code);
    $this->assertDatabaseCount('live_events', 0);
})->with([['unknown', 'CONNECTOR_NOT_AUTHORIZED'], ['revoked', 'CONNECTOR_NOT_AUTHORIZED'], ['room', 'SESSION_MISMATCH'], ['session', 'SESSION_MISMATCH']]);

test('LIVE commits valid siblings and rejects malformed unsupported and experimental events', function () {
    [$user, $session, $connector] = liveContext();
    $valid = liveEnvelope($session, $connector);
    $invalid = liveEnvelope($session, $connector, sequence: 2);
    $invalid['payload']['text'] = [];
    $unsupported = liveEnvelope($session, $connector, sequence: 3);
    $unsupported['eventType'] = 'live.product.match.v1';
    $version = liveEnvelope($session, $connector, sequence: 4);
    $version['schemaVersion'] = 2;
    $caption = liveEnvelope($session, $connector, 'live.caption.observed.v1', 'CAPTION_MESSAGE', ['segments' => [['text' => 'Hola', 'startMs' => 0, 'endMs' => 100]]], 5);
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$valid, $invalid, $unsupported, $version, $caption, 'bad']))
        ->assertOk()->assertJsonPath('results.0.status', 'accepted')->assertJsonPath('results.1.code', 'INVALID_EVENT')
        ->assertJsonPath('results.2.code', 'UNSUPPORTED_EVENT_TYPE')->assertJsonPath('results.3.code', 'UNSUPPORTED_SCHEMA_VERSION')
        ->assertJsonPath('results.4.code', 'FEATURE_DISABLED')->assertJsonPath('results.5.code', 'INVALID_EVENT');
    $this->assertDatabaseCount('live_events', 1);
    $this->assertDatabaseCount('live_event_receipts', 1);
});

test('LIVE rejects malformed batches before any write with 422', function (array $changes) {
    [$user, $session, $connector] = liveContext();
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', array_replace(liveBatch($session, [liveEnvelope($session, $connector)]), $changes))->assertUnprocessable();
    $this->assertDatabaseCount('live_events', 0);
})->with([[['events' => []]], [['events' => 'bad']], [['contractVersion' => 2]], [['batchId' => 'bad']], [['businessId' => 'forged']], [['team_id' => 'forged']]]);

test('LIVE safely retries an acknowledged or timed-out batch and detects identity conflicts', function () {
    [$user, $session, $connector] = liveContext();
    $event = liveEnvelope($session, $connector);
    $batch = liveBatch($session, [$event]);
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', $batch)->assertOk()->assertJsonPath('results.0.status', 'accepted');
    $this->postJson('/api/v1/live/ingest/batches', $batch)->assertOk()->assertJsonPath('results.0.status', 'duplicate');
    $event['payload']['text'] = 'Changed';
    $this->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$event]))->assertOk()->assertJsonPath('results.0.code', 'IDEMPOTENCY_CONFLICT');
    $this->assertDatabaseCount('live_events', 1);
    expect(LiveEvent::first()->payload['text'])->toBe('Hola');
});

test('LIVE canonicalizes a stable message from two authorized devices with separate durable receipts', function () {
    [$user, $session, $one] = liveContext();
    $two = LiveConnector::factory()->create(['live_session_id' => $session->id, 'business_id' => $user->team_id, 'user_id' => $user->id]);
    $a = liveEnvelope($session, $one);
    $b = liveEnvelope($session, $two);
    $b['sourceMessageId'] = $a['sourceMessageId'];
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$a, $b]))
        ->assertOk()->assertJsonPath('results.0.status', 'accepted')->assertJsonPath('results.1.status', 'duplicate');
    $this->assertDatabaseCount('live_events', 1);
    $this->assertDatabaseCount('live_event_receipts', 2);
});

test('LIVE preserves similar legitimate messages without source identity', function () {
    [$user, $session, $connector] = liveContext();
    $a = liveEnvelope($session, $connector);
    $b = liveEnvelope($session, $connector, sequence: 2);
    unset($a['sourceMessageId'], $b['sourceMessageId']);
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$a, $b]))
        ->assertOk()->assertJsonPath('results.1.status', 'accepted');
    $this->assertDatabaseCount('live_events', 2);
    expect(LiveEvent::pluck('identity_quality')->unique()->all())->toBe(['transport_only']);
});

test('LIVE SOCIAL and derived follow share actions count once while unknown social stays separate', function (string $action) {
    [$user, $session, $connector] = liveContext();
    $a = liveEnvelope($session, $connector, 'live.social.action.v1', strtoupper($action), ['action' => $action]);
    $b = liveEnvelope($session, $connector, 'live.social.action.v1', 'SOCIAL', ['action' => $action], 2);
    $b['sourceMessageId'] = $a['sourceMessageId'];
    $c = liveEnvelope($session, $connector, 'live.social.action.v1', 'SOCIAL', ['action' => 'unknown'], 3);
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$a, $b, $c]))->assertOk()->assertJsonPath('results.1.status', 'duplicate');
    $this->getJson('/api/v1/live/sessions/'.$session->id)->assertOk()->assertJsonPath('data.'.$action.'Count', 1)->assertJsonPath('data.unknownSocialCount', 1);
})->with(['follow', 'share']);

test('LIVE samples distinguish cumulative likes audience and observation order without summing totals', function () {
    [$user, $session, $connector] = liveContext();
    $a = liveEnvelope($session, $connector, 'live.likes.sampled.v1', 'LIKE', ['total' => 100, 'increment' => 5, 'quality' => 'reported']);
    $b = liveEnvelope($session, $connector, 'live.likes.sampled.v1', 'LIKE', ['total' => 100, 'quality' => 'reported'], 2);
    $b['observedAt'] = '2026-11-18T12:00:01Z';
    $c = liveEnvelope($session, $connector, 'live.audience.sampled.v1', 'ROOM_USER', ['concurrent' => 50, 'cumulative' => 300, 'quality' => 'reported'], 3);
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$b, $a, $c]))->assertOk();
    $this->getJson('/api/v1/live/sessions/'.$session->id)->assertOk()->assertJsonPath('data.likesTotal', 100)->assertJsonPath('data.audienceConcurrent', 50)->assertJsonPath('data.audienceCumulative', 300);
    $this->getJson('/api/v1/live/sessions/'.$session->id.'/events?eventType=live.likes.sampled.v1')->assertOk()->assertJsonCount(2, 'data');
});

test('LIVE keeps NOTICE SYSTEM and all moderation sources out of spontaneous comments', function (string $type, string $source, array $payload) {
    [$user, $session, $connector] = liveContext();
    $event = liveEnvelope($session, $connector, $type, $source, $payload);
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$event]))->assertOk()->assertJsonPath('results.0.status', 'accepted');
    $this->getJson('/api/v1/live/sessions/'.$session->id.'/events?eventType=live.comment.received.v1')->assertOk()->assertJsonCount(0, 'data');
    expect($session->fresh()->status)->toBe('initialized');
})->with([
    ['live.system.notice.v1', 'NOTICE', ['text' => 'Platform notice']], ['live.system.notice.v1', 'SYSTEM', ['text' => 'Platform system']],
    ...array_map(fn ($source) => ['live.moderation.observed.v1', $source, ['text' => 'Observed warning']], ['IM_DELETE', 'UNAUTHORIZED_MEMBER', 'MSG_DETECT', 'ROOM_VERIFY', 'ACCESS_CONTROL', 'ACCESS_RECALL', 'PERCEPTION']),
]);

test('LIVE IM_DELETE hides a comment projection without mutating its source in either arrival order', function (bool $deleteFirst) {
    [$user, $session, $connector] = liveContext();
    $comment = liveEnvelope($session, $connector);
    $delete = liveEnvelope($session, $connector, 'live.moderation.observed.v1', 'IM_DELETE', ['targetMessageId' => $comment['sourceMessageId']], 2);
    $delete['observedAt'] = '2026-11-18T12:00:01Z';
    $events = $deleteFirst ? [$delete, $comment] : [$comment, $delete];
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, $events))->assertOk();
    $this->getJson('/api/v1/live/sessions/'.$session->id.'/events?eventType=live.comment.received.v1')->assertOk()->assertJsonPath('data.0.commentDeleted', true)->assertJsonPath('data.0.payload.text', 'Hola');
    expect(fn () => LiveEvent::where('event_type', 'live.comment.received.v1')->first()->update(['payload' => []]))->toThrow(LogicException::class);
})->with([false, true]);

test('LIVE disconnect and reconnection preserve the session and title history without a confirmed end', function () {
    [$user, $session, $connector] = liveContext();
    $a = liveEnvelope($session, $connector, 'live.connector.status.v1', 'DISCONNECTED', ['state' => 'disconnected']);
    $b = liveEnvelope($session, $connector, 'live.connector.status.v1', 'CONNECTED', ['state' => 'tracking'], 2);
    $b['observedAt'] = '2026-11-18T12:01:00Z';
    $b['connectionEpoch'] = (string) Str::uuid();
    $c = liveEnvelope($session, $connector, 'live.room.metadata.v1', 'ROOM_USER', ['title' => 'New title'], 3);
    $c['observedAt'] = '2026-11-18T12:01:01Z';
    $c['connectionEpoch'] = $b['connectionEpoch'];
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$a]))->assertOk();
    $this->getJson('/api/v1/live/sessions/'.$session->id)->assertOk()->assertJsonPath('data.status', 'reconnecting');
    $this->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$b, $c]))->assertOk();
    $this->getJson('/api/v1/live/sessions/'.$session->id)->assertOk()->assertJsonPath('data.status', 'tracking')->assertJsonPath('data.title', 'New title');
    $this->assertDatabaseCount('live_sessions', 1);
    $this->assertDatabaseCount('live_coverage_intervals', 2);
});

test('LIVE confirms an end once across CONTROL_MESSAGE STREAM_END and later disconnects', function () {
    [$user, $session, $connector] = liveContext();
    $a = liveEnvelope($session, $connector, 'live.stream.control.v1', 'CONTROL_MESSAGE', ['action' => 'end_confirmed']);
    $b = liveEnvelope($session, $connector, 'live.ended.v1', 'STREAM_END', ['kind' => 'confirmed'], 2);
    $c = liveEnvelope($session, $connector, 'live.connector.status.v1', 'DISCONNECTED', ['state' => 'disconnected'], 3);
    $c['observedAt'] = '2026-11-18T12:02:00Z';
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$a, $b, $c]))->assertOk();
    $this->getJson('/api/v1/live/sessions/'.$session->id)->assertOk()->assertJsonPath('data.status', 'ended_confirmed');
    $this->assertDatabaseCount('live_coverage_intervals', 1);
});

test('LIVE preserves administrative suspension manual stop and unverified end', function (string $action, string $status) {
    [$user, $session, $connector] = liveContext();
    $a = liveEnvelope($session, $connector, 'live.stream.control.v1', 'CONTROL_MESSAGE', ['action' => $action]);
    $b = liveEnvelope($session, $connector, 'live.connector.status.v1', 'CONNECTED', ['state' => 'connected'], 2);
    $b['observedAt'] = '2026-11-18T12:02:00Z';
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$a, $b]))->assertOk();
    $this->getJson('/api/v1/live/sessions/'.$session->id)->assertOk()->assertJsonPath('data.status', $status);
})->with([['suspend', 'suspended'], ['stop', 'stopped'], ['end_unverified', 'ended_unverified']]);

test('LIVE accepts gated caption segments and refuses binary diagnostics', function () {
    config(['live.captions_enabled' => true]);
    [$user, $session, $connector] = liveContext();
    $a = liveEnvelope($session, $connector, 'live.caption.observed.v1', 'CAPTION_MESSAGE', ['segments' => [['text' => 'Hola', 'startMs' => 0, 'endMs' => 100]]]);
    $b = liveEnvelope($session, $connector, 'live.connector.status.v1', 'RAW_DATA', ['state' => 'tracking', 'binary' => 'raw'], 2);
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$a, $b]))->assertOk()->assertJsonPath('results.0.status', 'accepted')->assertJsonPath('results.1.code', 'INVALID_EVENT');
    $this->assertDatabaseCount('live_events', 1);
});

test('LIVE rolls back the whole persistence transaction and returns no accepted ACK on database failure', function () {
    [$user, $session, $connector] = liveContext();
    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'insert into "live_event_receipts"')) {
            throw new QueryException('sqlite', 'injected storage failure', [], new RuntimeException('Storage unavailable'));
        }
    });
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [liveEnvelope($session, $connector)]))
        ->assertServiceUnavailable()->assertJsonPath('code', 'PERSISTENCE_UNAVAILABLE')->assertJsonMissingPath('results');
    $this->assertDatabaseCount('live_events', 0);
    $this->assertDatabaseCount('live_event_receipts', 0);
});

test('LIVE initializes session authorizations idempotently and revokes connector access', function () {
    $business = Business::factory()->create();
    $user = $business->owner;
    $input = ['clientSessionId' => (string) Str::uuid(), 'platform' => 'tiktok', 'sourceRoomId' => 'room-123'];
    $response = $this->withToken(liveToken($user))->postJson('/api/v1/live/sessions', $input)->assertCreated();
    $id = $response->json('data.id');
    $this->postJson('/api/v1/live/sessions', $input)->assertOk()->assertJsonPath('data.id', $id);
    $this->postJson('/api/v1/live/sessions', array_replace($input, ['sourceRoomId' => 'another']))->assertConflict();
    $connector = $this->postJson('/api/v1/live/sessions/'.$id.'/connectors', ['connectorInstanceId' => (string) Str::uuid(), 'connectorKind' => 'desktop'])->assertCreated()->json('data.id');
    $this->deleteJson('/api/v1/live/sessions/'.$id.'/connectors/'.$connector)->assertOk();
    expect(LiveConnector::findOrFail($connector)->revoked_at)->not->toBeNull();
    $this->getJson('/api/v1/live/sessions')->assertOk()->assertJsonCount(1, 'data');
});

test('LIVE rejects forged server fields timezone-free timestamps and empty sample payloads', function (string $field, mixed $value) {
    [$user, $session, $connector] = liveContext();
    $event = liveEnvelope($session, $connector);
    $event[$field] = $value;
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$event]))->assertOk()->assertJsonPath('results.0.code', 'INVALID_EVENT');
    $this->assertDatabaseCount('live_events', 0);
})->with([['businessId', 'fake'], ['team_id', 'fake'], ['receivedAt', '2026-11-18T00:00:00Z'], ['observedAt', '2026-11-18 12:00:00'], ['occurredAt', '2026-11-18T12:00:00Z']]);

test('LIVE stores instants in UTC and rebuilds projections from retained events', function () {
    [$user, $session, $connector] = liveContext();
    $event = liveEnvelope($session, $connector, 'live.social.action.v1', 'FOLLOW', ['action' => 'follow']);
    $event['observedAt'] = '2026-11-18T07:00:00-05:00';
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$event]))->assertOk();
    $this->getJson('/api/v1/live/sessions/'.$session->id.'/events')->assertOk()->assertJsonPath('data.0.observedAt', '2026-11-18T12:00:00.000000Z');
    $session->refresh();
    $expected = $session->projection;
    app(LiveProjectionService::class)->rebuild($session);
    expect($session->fresh()->projection)->toBe($expected);
});

test('LIVE shares normalized JSON fixtures and persists all twelve variants including caption opt-in', function () {
    config(['live.captions_enabled' => true]);
    $batch = json_decode(file_get_contents(base_path('tests/Fixtures/live-v1.batch.json')), true, 512, JSON_THROW_ON_ERROR);
    $session = LiveSession::factory()->create(['id' => $batch['liveSessionId'], 'client_session_id' => $batch['events'][0]['clientSessionId'], 'source_room_id' => $batch['events'][0]['sourceRoomId']]);
    LiveConnector::factory()->create(['live_session_id' => $session->id, 'business_id' => $session->business_id, 'user_id' => $session->created_by, 'connector_instance_id' => $batch['events'][0]['connectorInstanceId']]);
    $user = User::findOrFail($session->created_by);
    $response = $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', $batch)->assertOk()->assertJsonCount(12, 'results');
    expect(array_unique(array_column($response->json('results'), 'status')))->toBe(['accepted']);
    $this->assertDatabaseCount('live_events', 12);
    $this->getJson('/api/v1/live/sessions/'.$session->id.'/coverage')->assertOk()->assertJsonPath('data.0.startedAt', '2026-11-18T12:00:01.123456Z')->assertJsonPath('data.0.endReason', 'confirmed');
    $this->getJson('/api/v1/live/sessions/'.$session->id.'/events?per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 12);
});

test('LIVE enforces body size and account request limits before persistence', function () {
    [$user, $session, $connector] = liveContext();
    config(['live.max_batch_bytes' => 100]);
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [liveEnvelope($session, $connector)]))->assertStatus(413);
    $this->assertDatabaseCount('live_events', 0);
    config(['live.max_batch_bytes' => 1048576, 'live.requests_per_minute' => 2]);
    $this->getJson('/api/v1/live/sessions')->assertOk();
    $this->getJson('/api/v1/live/sessions')->assertTooManyRequests();
});

test('LIVE rejects conflicting transport identities before canonical message deduplication', function (string $identity) {
    [$user, $session, $connector] = liveContext();
    $a = liveEnvelope($session, $connector);
    $b = liveEnvelope($session, $connector, sequence: 2);
    $b['sourceMessageId'] = $a['sourceMessageId'];
    $b[$identity] = $a[$identity];
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$a, $b]))->assertOk()->assertJsonPath('results.1.code', 'IDEMPOTENCY_CONFLICT');
    $this->assertDatabaseCount('live_events', 1);
})->with(['eventId', 'idempotencyKey', 'sequence']);

test('LIVE keeps another active observer tracking when one instance disconnects', function () {
    [$user, $session, $one] = liveContext();
    $two = LiveConnector::factory()->create(['live_session_id' => $session->id, 'business_id' => $session->business_id, 'user_id' => $user->id]);
    $a = liveEnvelope($session, $one, 'live.connector.status.v1', 'CONNECTED', ['state' => 'tracking']);
    $b = liveEnvelope($session, $two, 'live.connector.status.v1', 'CONNECTED', ['state' => 'tracking']);
    $c = liveEnvelope($session, $one, 'live.connector.status.v1', 'DISCONNECTED', ['state' => 'disconnected'], 2);
    $c['observedAt'] = '2026-11-18T12:01:00Z';
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$a, $b, $c]))->assertOk();
    $this->getJson('/api/v1/live/sessions/'.$session->id)->assertOk()->assertJsonPath('data.status', 'tracking');
});

test('LIVE closes experimental diagnostics by default and requires counters and ordered caption segments', function (string $type, string $source, array $payload, string $code) {
    config(['live.captions_enabled' => true]);
    [$user, $session, $connector] = liveContext();
    $event = liveEnvelope($session, $connector, $type, $source, $payload);
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$event]))->assertOk()->assertJsonPath('results.0.code', $code);
    $this->assertDatabaseCount('live_events', 0);
})->with([
    ['live.connector.status.v1', 'VIEWER_PICKS_UPDATE', ['state' => 'tracking'], 'FEATURE_DISABLED'],
    ['live.likes.sampled.v1', 'LIKE', ['quality' => 'unknown'], 'INVALID_EVENT'],
    ['live.audience.sampled.v1', 'ROOM_USER', ['quality' => 'unknown'], 'INVALID_EVENT'],
    ['live.caption.observed.v1', 'CAPTION_MESSAGE', ['segments' => [['text' => 'Caption', 'startMs' => 10, 'endMs' => 5]]], 'INVALID_EVENT'],
    ['live.ended.v1', 'DISCONNECTED', ['kind' => 'confirmed'], 'INVALID_EVENT'],
]);

test('LIVE results serialize an error map object consistently with the HTTP contract', function () {
    [$user, $session, $connector] = liveContext();
    $response = $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [liveEnvelope($session, $connector)]))->assertOk();
    expect(json_decode($response->getContent())->results[0]->errors)->toBeInstanceOf(stdClass::class);
});

test('LIVE administrative holds and reconstruction remain deterministic with late delivery', function () {
    [$user, $session, $connector] = liveContext();
    $connected = liveEnvelope($session, $connector, 'live.connector.status.v1', 'CONNECTED', ['state' => 'tracking']);
    $connected['observedAt'] = '2026-11-18T12:02:00Z';
    $suspended = liveEnvelope($session, $connector, 'live.stream.control.v1', 'CONTROL_MESSAGE', ['action' => 'suspend'], 2);
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$connected, $suspended]))->assertOk();
    expect($session->fresh()->status)->toBe('suspended');
    app(LiveProjectionService::class)->rebuild($session);
    expect($session->fresh()->status)->toBe('suspended');
});

test('LIVE honors restricted Sanctum token abilities even for a business owner', function () {
    [$user, $session, $connector] = liveContext();
    $token = $user->createToken('restricted', ['live.read'])->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/live/sessions')->assertOk();
    $this->postJson('/api/v1/live/ingest/batches', liveBatch($session, [liveEnvelope($session, $connector)]))->assertForbidden();
    $this->assertDatabaseCount('live_events', 0);
});

test('LIVE preserves a nonempty zero source ID as stable identity', function () {
    [$user, $session, $connector] = liveContext();
    $a = liveEnvelope($session, $connector);
    $b = liveEnvelope($session, $connector, sequence: 2);
    $a['sourceMessageId'] = $b['sourceMessageId'] = '0';
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$a, $b]))
        ->assertOk()->assertJsonPath('results.1.status', 'duplicate');
    expect(LiveEvent::first()->identity_quality)->toBe('source_message');
});

test('LIVE preserves distinct moderation observations sharing a source reference', function () {
    [$user, $session, $connector] = liveContext();
    $a = liveEnvelope($session, $connector, 'live.moderation.observed.v1', 'IM_DELETE', ['text' => 'Observed']);
    $b = liveEnvelope($session, $connector, 'live.moderation.observed.v1', 'ROOM_VERIFY', ['text' => 'Observed'], 2);
    $b['sourceMessageId'] = $a['sourceMessageId'];
    $this->withToken(liveToken($user))->postJson('/api/v1/live/ingest/batches', liveBatch($session, [$a, $b]))
        ->assertOk()->assertJsonPath('results.1.status', 'accepted');
    $this->assertDatabaseCount('live_events', 2);
});
