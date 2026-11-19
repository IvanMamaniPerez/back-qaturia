<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class LivePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['live.ingest', 'live.read'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web'], ['crm_permission' => false]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
