<?php

use App\Models\LiveConnector;
use App\Models\LiveEventReceipt;
use App\Models\LiveSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class);

function livePgWorkers(LiveSession $session, array $inputs): array
{
    DB::beginTransaction();
    LiveSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
    $workers = [];
    try {
        foreach ($inputs as $input) {
            $worker = new Process([PHP_BINARY, base_path('tests/Support/live-worker.php')], base_path(), [
                'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            ], json_encode(['session' => $session->id] + $input, JSON_THROW_ON_ERROR), 40);
            $worker->start();
            $worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready'));
            $workers[] = $worker;
        }
        foreach ($workers as $worker) {
            expect($worker->isRunning())->toBeTrue($worker->getErrorOutput());
        }
    } finally {
        DB::rollBack();
    }

    return array_map(function (Process $worker): array {
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());

        return json_decode(explode("\n", $worker->getOutput(), 2)[1], true, 512, JSON_THROW_ON_ERROR);
    }, $workers);
}

function livePgEvent(LiveSession $session, LiveConnector $connector): array
{
    return [
        'eventId' => (string) Str::uuid(), 'eventType' => 'live.social.action.v1', 'schemaVersion' => 1,
        'idempotencyKey' => (string) Str::uuid(), 'platform' => 'tiktok', 'connectorKind' => 'desktop',
        'connectorInstanceId' => $connector->connector_instance_id, 'connectionEpoch' => (string) Str::uuid(), 'sequence' => 1,
        'clientSessionId' => $session->client_session_id, 'sourceRoomId' => $session->source_room_id, 'sourceEventType' => 'FOLLOW',
        'sourceMessageId' => 'stable-social-message', 'sourceKind' => 'platform',
        'observedAt' => '2026-11-18T12:00:00.123456Z', 'payload' => ['action' => 'follow'],
    ];
}

test('PostgreSQL commits one canonical event and one projection under simultaneous identical retries', function () {
    $connector = LiveConnector::factory()->create();
    $session = LiveSession::findOrFail($connector->live_session_id);
    $input = ['user' => $connector->user_id, 'events' => [livePgEvent($session, $connector)]];
    $results = livePgWorkers($session, [$input, $input]);
    $statuses = [$results[0][0]['status'], $results[1][0]['status']];
    sort($statuses);
    expect($statuses)->toBe(['accepted', 'duplicate']);
    expect($session->events()->count())->toBe(1);
    expect(LiveEventReceipt::where('live_connector_id', $connector->id)->count())->toBe(1);
    expect($session->fresh()->projection['followCount'])->toBe(1);
    expect($session->events()->first()->observed_at->format('u'))->toBe('123456');
});

test('PostgreSQL canonicalizes simultaneous devices and enforces durable unique identities and tenant references', function () {
    $one = LiveConnector::factory()->create();
    $session = LiveSession::findOrFail($one->live_session_id);
    $two = LiveConnector::factory()->create(['live_session_id' => $session->id, 'business_id' => $one->business_id, 'user_id' => $one->user_id]);
    $results = livePgWorkers($session, [
        ['user' => $one->user_id, 'events' => [livePgEvent($session, $one)]],
        ['user' => $two->user_id, 'events' => [livePgEvent($session, $two)]],
    ]);
    $statuses = [$results[0][0]['status'], $results[1][0]['status']];
    sort($statuses);
    expect($statuses)->toBe(['accepted', 'duplicate']);
    expect($session->events()->count())->toBe(1);
    expect(LiveEventReceipt::whereIn('live_connector_id', [$one->id, $two->id])->count())->toBe(2);
    $receipt = LiveEventReceipt::where('live_connector_id', $one->id)->first()->getRawOriginal();
    $receipt['id'] = (string) Str::uuid();
    expect(fn () => DB::transaction(fn () => DB::table('live_event_receipts')->insert($receipt)))->toThrow(QueryException::class);
    $event = $session->events()->first()->getRawOriginal();
    $foreign = LiveConnector::factory()->create();
    $event['id'] = (string) Str::uuid();
    $event['event_id'] = (string) Str::uuid();
    $event['canonical_key'] = hash('sha256', 'foreign');
    $event['live_connector_id'] = $foreign->id;
    expect(fn () => DB::transaction(fn () => DB::table('live_events')->insert($event)))->toThrow(QueryException::class);
});
