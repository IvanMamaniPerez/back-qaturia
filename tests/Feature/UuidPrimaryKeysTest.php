<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmPipelineTablesSeeder;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmTablesSeeder;
use VentureDrake\LaravelCrm\Models\Label;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\Setting;

test('application and crm models generate uuid primary keys', function () {
    $user = User::factory()->create();
    $label = Label::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'UUID label',
        'hex' => '2563eb',
    ]);
    $setting = Setting::create([
        'name' => 'uuid_test_setting',
        'value' => 'enabled',
    ]);

    expect(Str::isUuid($user->id))->toBeTrue()
        ->and(Str::isUuid($label->id))->toBeTrue()
        ->and(Str::isUuid($setting->id))->toBeTrue();
});

test('spatie roles permissions and polymorphic assignments use uuid keys', function () {
    $user = User::factory()->create();
    $permission = Permission::create([
        'name' => 'uuid permission',
        'guard_name' => 'web',
    ]);
    $role = Role::create([
        'name' => 'uuid role',
        'guard_name' => 'web',
    ]);

    $role->givePermissionTo($permission);
    $user->assignRole($role);

    expect(config('permission.models.permission'))->toBe(Permission::class)
        ->and(config('permission.models.role'))->toBe(Role::class)
        ->and(Str::isUuid($permission->id))->toBeTrue()
        ->and(Str::isUuid($role->id))->toBeTrue()
        ->and($user->hasRole($role))->toBeTrue()
        ->and($user->can($permission->name))->toBeTrue();

    $this->assertDatabaseHas(config('permission.table_names.role_has_permissions'), [
        'permission_id' => $permission->id,
        'role_id' => $role->id,
    ]);
    $this->assertDatabaseHas(config('permission.table_names.model_has_roles'), [
        'role_id' => $role->id,
        config('permission.column_names.model_morph_key') => $user->id,
        'model_type' => $user->getMorphClass(),
    ]);
});

test('crm seeders preserve relationships with deterministic uuid keys', function () {
    $this->seed(LaravelCrmTablesSeeder::class);
    $this->seed(LaravelCrmPipelineTablesSeeder::class);

    $pipeline = Pipeline::query()->firstOrFail();
    $stage = PipelineStage::query()->where('pipeline_id', $pipeline->id)->firstOrFail();

    expect(Str::isUuid($pipeline->id))->toBeTrue()
        ->and(Str::isUuid($stage->id))->toBeTrue()
        ->and($stage->pipeline_id)->toBe($pipeline->id);
});
