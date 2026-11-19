<?php

namespace Database\Factories;

use App\Models\LiveConnector;
use App\Models\LiveEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LiveEvent> */
class LiveEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'live_connector_id' => LiveConnector::factory(),
            'business_id' => fn (array $attributes): string => LiveConnector::whereKey($attributes['live_connector_id'])->firstOrFail()->business_id,
            'live_session_id' => fn (array $attributes): string => LiveConnector::whereKey($attributes['live_connector_id'])->firstOrFail()->live_session_id,
            'event_id' => (string) Str::uuid(), 'event_type' => 'live.comment.received.v1', 'schema_version' => 1,
            'source_event_type' => 'CHAT', 'source_message_id' => (string) Str::uuid(), 'source_kind' => 'platform',
            'observed_at' => now(), 'received_at' => now(), 'canonical_key' => hash('sha256', (string) Str::uuid()),
            'identity_quality' => 'source_message', 'processing_status' => 'projected',
            'payload' => ['text' => 'Fixture comment', 'authorId' => 'viewer-1', 'authorName' => 'Viewer'],
        ];
    }
}
