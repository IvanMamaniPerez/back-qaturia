<?php

namespace VentureDrake\LaravelCrm\Database\Seeders;

use Illuminate\Database\Seeder;
use VentureDrake\LaravelCrm\Models\LeadSource;

class LaravelCrmLeadSourcesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Website', 'Referral', 'Cold Call', 'Email Campaign', 'Social Media', 'Trade Show', 'Partner', 'Organic Search'] as $name) {
            LeadSource::withTrashed()->firstOrCreate(['name' => $name, 'team_id' => null]);
        }
    }
}
