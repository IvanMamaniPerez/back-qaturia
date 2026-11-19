<?php

namespace Database\Seeders;

use App\Models\LiveConnector;
use App\Models\LiveSession;
use App\Models\User;
use App\Services\LiveIngestionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LiveEventSeeder extends Seeder
{
    public function run(): void
    {
        $connector = LiveConnector::factory()->create();
        $session = LiveSession::findOrFail($connector->live_session_id);
        $user = User::withoutGlobalScopes()->findOrFail($connector->user_id);
        app(LiveIngestionService::class)->ingest($user, $session, [[
            'eventId' => (string) Str::uuid(), 'eventType' => 'live.comment.received.v1', 'schemaVersion' => 1,
            'idempotencyKey' => (string) Str::uuid(), 'platform' => 'tiktok', 'connectorKind' => 'desktop',
            'connectorInstanceId' => $connector->connector_instance_id, 'connectionEpoch' => (string) Str::uuid(),
            'sequence' => 0, 'clientSessionId' => $session->client_session_id, 'sourceRoomId' => $session->source_room_id,
            'sourceEventType' => 'CHAT', 'sourceMessageId' => (string) Str::uuid(), 'sourceKind' => 'platform',
            'observedAt' => now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
            'payload' => ['text' => 'Sample LIVE comment', 'authorId' => 'sample-viewer', 'authorName' => 'Sample'],
        ]]);
    }
}
