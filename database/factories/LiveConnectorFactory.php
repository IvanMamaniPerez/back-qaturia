<?php

namespace Database\Factories;

use App\Models\LiveConnector;
use App\Models\LiveSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @extends Factory<LiveConnector> */
class LiveConnectorFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'live_session_id' => LiveSession::factory(),
            'business_id' => fn (array $attributes): string => LiveSession::whereKey($attributes['live_session_id'])->firstOrFail()->business_id,
            'user_id' => fn (array $attributes): string => LiveSession::whereKey($attributes['live_session_id'])->firstOrFail()->created_by,
            'device_id' => function (array $attributes): string {
                $id = (string) Str::uuid();
                DB::table('devices')->insert(['id' => $id, 'user_id' => $attributes['user_id'], 'created_at' => now(), 'updated_at' => now()]);

                return $id;
            },
            'connector_instance_id' => (string) Str::uuid(), 'connector_kind' => 'desktop',
        ];
    }
}
