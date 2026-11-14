<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmBootstrapSeeder;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmLeadSourcesSeeder;
use VentureDrake\LaravelCrm\Models\AddressType;
use VentureDrake\LaravelCrm\Models\ContactType;
use VentureDrake\LaravelCrm\Models\Customer;
use VentureDrake\LaravelCrm\Models\FeatureStatus;
use VentureDrake\LaravelCrm\Models\Label;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\LeadSource;
use VentureDrake\LaravelCrm\Models\LeadStatus;
use VentureDrake\LaravelCrm\Models\OrganizationType;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\PipelineStageProbability;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Models\Timezone;

test('the default seed bootstraps the CRM offline twice without demo records or duplicate UUIDs', function () {
    Http::preventStrayRequests();
    $timezone = date_default_timezone_get();
    $models = [Role::class, Permission::class, Setting::class, Label::class, LeadSource::class, Pipeline::class, PipelineStage::class, PipelineStageProbability::class];
    $snapshot = fn (): array => collect($models)->mapWithKeys(
        fn (string $model): array => [$model => $model::orderBy('id')->pluck('id')->all()]
    )->all();

    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    $first = $snapshot();
    $pivotCount = DB::table(config('permission.table_names.role_has_permissions'))->count();
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect($snapshot())->toBe($first)
        ->and(DB::table(config('permission.table_names.role_has_permissions'))->count())->toBe($pivotCount)
        ->and(date_default_timezone_get())->toBe($timezone)
        ->and(Role::count())->toBe(4)
        ->and(Permission::count())->toBe(152)
        ->and(Label::count())->toBe(3)
        ->and(LeadStatus::count())->toBe(2)
        ->and(OrganizationType::count())->toBe(4)
        ->and(AddressType::count())->toBe(6)
        ->and(ContactType::count())->toBe(2)
        ->and(FeatureStatus::count())->toBe(6)
        ->and(LeadSource::count())->toBe(8)
        ->and(Pipeline::count())->toBe(7)
        ->and(PipelineStage::count())->toBe(37)
        ->and(PipelineStageProbability::count())->toBe(12)
        ->and(Timezone::count())->toBe(count(timezone_identifiers_list()));

    foreach ($first as $ids) {
        foreach ($ids as $id) {
            expect(Str::isUuid($id))->toBeTrue();
        }
    }

    foreach (PipelineStage::with(['pipeline', 'pipelineStageProbability'])->get() as $stage) {
        expect($stage->pipeline?->id)->toBe($stage->pipeline_id)
            ->and($stage->pipelineStageProbability?->id)->toBe($stage->pipeline_stage_probability_id);
    }

    foreach ([Label::class, LeadSource::class, Pipeline::class, PipelineStage::class] as $model) {
        expect($model::whereNull('external_id')->exists())->toBeFalse();
    }

    foreach ([User::class, Customer::class, Lead::class, Person::class] as $model) {
        $this->assertDatabaseEmpty((new $model)->getTable());
    }

    Http::assertNothingSent();
});

test('default roles authorize CRM operations without granting unrelated host permissions', function (string $roleName, int $count, bool $managesRoles) {
    $hostPermission = Permission::create(['name' => 'manage host billing', 'guard_name' => 'web']);
    Permission::create(['name' => 'view crm roles', 'guard_name' => 'api']);
    $this->seed(LaravelCrmBootstrapSeeder::class);
    $role = Role::findByName($roleName, 'web');
    $user = User::factory()->create(['crm_access' => true]);
    $user->assignRole($role);

    expect($role->permissions()->count())->toBe($count)
        ->and($user->can('viewAny', Customer::class))->toBeTrue()
        ->and($user->can('create', Customer::class))->toBeTrue()
        ->and($user->can('viewAny', Role::class))->toBe($managesRoles)
        ->and($user->can('create', Role::class))->toBe($managesRoles)
        ->and($user->can($hostPermission->name))->toBeFalse()
        ->and($role->permissions()->where('guard_name', '<>', 'web')->exists())->toBeFalse();
})->with([
    'Owner' => ['Owner', 152, true],
    'Admin' => ['Admin', 152, true],
    'Manager' => ['Manager', 101, false],
    'Employee' => ['Employee', 82, false],
]);

test('reseeding preserves customized settings roles grants and soft deleted catalogs while repairing missing rows', function () {
    $this->seed(LaravelCrmBootstrapSeeder::class);
    $currency = Setting::where('name', 'currency')->firstOrFail();
    $currency->update(['value' => 'PEN']);
    $label = Label::firstOrFail();
    $label->update(['name' => 'My label']);
    $label->delete();
    $addressType = AddressType::firstOrFail();
    $missingId = $addressType->id;
    $addressType->forceDelete();
    $source = LeadSource::firstOrFail();
    $source->delete();

    $manager = Role::findByName('Manager');
    $employee = Role::findByName('Employee');
    $manager->revokePermissionTo('delete crm leads');
    $employee->revokePermissionTo('delete crm leads');
    $employee->givePermissionTo('view crm settings');
    $customRole = Role::create(['name' => 'Sales auditor', 'guard_name' => 'web', 'crm_role' => true]);
    $customRole->givePermissionTo('view crm leads');

    $this->seed(LaravelCrmBootstrapSeeder::class);

    expect($currency->fresh()->value)->toBe('PEN')
        ->and($label->fresh()->name)->toBe('My label')
        ->and($label->fresh()->trashed())->toBeTrue()
        ->and(Label::withTrashed()->count())->toBe(3)
        ->and(LeadSource::withTrashed()->count())->toBe(8)
        ->and($source->fresh()->trashed())->toBeTrue()
        ->and(AddressType::findOrFail($missingId)->id)->toBe($missingId)
        ->and($manager->fresh()->hasPermissionTo('delete crm leads'))->toBeFalse()
        ->and($employee->fresh()->hasPermissionTo('delete crm leads'))->toBeFalse()
        ->and($employee->fresh()->hasPermissionTo('view crm settings'))->toBeTrue()
        ->and($customRole->fresh()->permissions()->pluck('name')->all())->toBe(['view crm leads']);
});

test('a bootstrap failure rolls back catalogs settings and authorization data', function () {
    $this->partialMock(LaravelCrmLeadSourcesSeeder::class)
        ->shouldReceive('run')->once()->andThrow(new RuntimeException('Seed failed'));

    expect(fn () => $this->seed(LaravelCrmBootstrapSeeder::class))->toThrow(RuntimeException::class, 'Seed failed');

    foreach ([Role::class, Permission::class, Setting::class, Pipeline::class, Label::class] as $model) {
        $this->assertDatabaseEmpty((new $model)->getTable());
    }
});

test('permissions and lead source commands also work without teams and can be repeated', function () {
    config(['laravel-crm.teams' => false]);

    foreach (['laravelcrm:permissions', 'laravelcrm:lead-sources'] as $command) {
        $this->artisan($command)->assertSuccessful();
        $this->artisan($command)->assertSuccessful();
    }

    expect(Role::count())->toBe(4)
        ->and(Permission::count())->toBe(152)
        ->and(LeadSource::count())->toBe(8);
});
