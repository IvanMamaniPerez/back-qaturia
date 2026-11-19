<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\LiveSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LiveSession> */
class LiveSessionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'created_by' => fn (array $attributes): string => Business::whereKey($attributes['business_id'])->firstOrFail()->owner_user_id,
            'client_session_id' => (string) Str::uuid(), 'platform' => 'tiktok', 'source_room_id' => fake()->numerify('################'),
            'status' => 'initialized', 'initial_title' => 'Fixture LIVE', 'title' => 'Fixture LIVE',
        ];
    }
}
