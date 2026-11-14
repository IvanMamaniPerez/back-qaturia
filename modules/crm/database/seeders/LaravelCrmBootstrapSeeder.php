<?php

namespace VentureDrake\LaravelCrm\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class LaravelCrmBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        try {
            DB::transaction(function (): void {
                $this->call([
                    LaravelCrmSettingsSeeder::class,
                    LaravelCrmTablesSeeder::class,
                    LaravelCrmLeadSourcesSeeder::class,
                ]);
            });
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            app('laravel-crm.settings')->forgetCache();
            app('laravel-crm.system-check')->forgetCache();
        }
    }
}
