<?php

namespace Tests\Fixtures;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmBootstrapSeeder;

class NativeAuthFixtureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== '/tmp/qaturia-auth-e2e.sqlite') {
            throw new \RuntimeException('The native authentication fixture requires the disposable E2E database.');
        }

        $this->call(LaravelCrmBootstrapSeeder::class);
        $business = Business::create(['name' => 'Native E2E Business']);
        User::factory()->create([
            'email' => 'native-auth@example.test',
            'password' => 'native-auth-test-only',
            'crm_access' => true,
            'team_id' => $business->id,
        ]);
    }
}
