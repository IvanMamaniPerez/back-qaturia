<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class LiveEventReceiptSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(LiveEventSeeder::class);
    }
}
