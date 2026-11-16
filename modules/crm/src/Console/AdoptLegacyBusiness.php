<?php

namespace VentureDrake\LaravelCrm\Console;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmSettingsSeeder;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Support\BusinessContext;

class AdoptLegacyBusiness extends Command
{
    protected $signature = 'laravelcrm:adopt-business {owner : Existing user UUID or email} {--name=} {--dry-run}';

    protected $description = 'Adopt a single-company legacy installation without recreating UUIDs';

    public function handle(): int
    {
        $value = $this->argument('owner');
        $user = User::withoutGlobalScopes()->where(
            Str::isUuid($value) ? 'id' : 'email', $value
        )->first();
        if (! $user || $user->team_id || Business::exists()
            || User::withoutGlobalScopes()->where('crm_access', true)->whereKeyNot($user->id)->exists()) {
            $this->error('Automatic adoption requires one existing CRM owner, no businesses and no other CRM accounts. Use an explicit data mapping for a multi-account installation.');

            return self::FAILURE;
        }
        $prefix = config('laravel-crm.db_table_prefix');
        $legacy = DB::table($prefix.'settings')->whereNull('team_id')->whereNull('user_id')->pluck('value', 'name');
        $tables = collect(Schema::getTables())->pluck('name')->filter(
            fn ($name) => (str_starts_with($name, $prefix) || $name === 'xero_tokens')
                && Schema::hasColumn($name, 'team_id') && ! BusinessContext::shared($name)
        );
        foreach ($tables as $name) {
            $this->line($name.': '.DB::table($name)->whereNull('team_id')->count().' unassigned rows');
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        DB::transaction(function () use ($user, $legacy, $tables, $prefix) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA defer_foreign_keys = ON');
            }

            // Lock identity and recheck while holding the provisioning transaction.
            User::withoutGlobalScopes()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if(Business::exists(), 409);
            $business = Business::create(['name' => $this->option('name') ?: ($legacy['organization_name'] ?? config('app.name'))]);
            foreach (Business::SETTINGS as $key => $column) {
                if ($key !== 'organization_name' && isset($legacy[$key])) {
                    $business->setAttribute($column, $legacy[$key]);
                }
            }
            $business->forceFill(['owner_user_id' => $user->id])->save();
            $user->forceFill(['team_id' => $business->id, 'crm_access' => true])->save();
            foreach ($tables as $table) {
                $query = DB::table($table)->whereNull('team_id');
                if ($table === $prefix.'settings') {
                    $query->whereNotIn('name', ['app_name', 'app_env', 'app_url', 'version', 'version_latest', 'install_id'])
                        ->where('name', 'not like', 'db_%');
                }
                $query->update(['team_id' => $business->id]);
            }
            $settingId = DB::table($prefix.'settings')->where('team_id', $business->id)->where('name', 'team')->value('id');
            foreach (['addresses' => 'addressable', 'emails' => 'emailable', 'phones' => 'phoneable'] as $table => $morph) {
                DB::table($prefix.$table)->where($morph.'_type', Setting::class)
                    ->where($morph.'_id', $settingId)->update([$morph.'_type' => Business::class, $morph.'_id' => $business->id]);
            }
            app(BusinessContext::class)->run($business->id, function () use ($user) {
                $user->assignRole(Role::where('name', 'Owner')->where('guard_name', 'web')->whereNull('team_id')->firstOrFail());
            });
            // Corporate identity has one authority. Remove old key/value copies.
            DB::table($prefix.'settings')->where('team_id', $business->id)->whereIn('name', array_keys(Business::SETTINGS))->delete();
            // Recreate installation defaults without copying the legacy
            // company's preferences into businesses registered afterwards.
            app(BusinessContext::class)->system(fn () => $this->callSilent('db:seed', [
                '--class' => LaravelCrmSettingsSeeder::class,
                '--force' => true,
            ]));
            app('laravel-crm.settings')->forgetCache();
        });
        $this->info('Business adopted. Existing record UUIDs were preserved.');

        return self::SUCCESS;
    }
}
