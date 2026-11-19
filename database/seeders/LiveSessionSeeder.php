<?php

namespace Database\Seeders;

use App\Models\LiveSession;
use Illuminate\Database\Seeder;

class LiveSessionSeeder extends Seeder
{
    public function run(): void
    {
        LiveSession::factory()->create();
    }
}
