<?php

namespace Database\Seeders;

use App\Models\LiveConnector;
use Illuminate\Database\Seeder;

class LiveConnectorSeeder extends Seeder
{
    public function run(): void
    {
        LiveConnector::factory()->create();
    }
}
