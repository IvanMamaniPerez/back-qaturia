<?php

namespace VentureDrake\LaravelCrm\Support;

use App\Models\Business;
use Closure;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

/**
 * One authority for tenancy in HTTP, Livewire, console and queue workers.
 * System mode is an explicit infrastructure operation, never a request header.
 */
class BusinessContext
{
    private ?string $businessId = null;

    private bool $pinned = false;

    private bool $system = false;

    private array $jobs = [];

    public const SHARED_CATALOGS = [
        'labels', 'address_types', 'contact_types', 'organization_types', 'industries',
        'lead_sources', 'lead_statuses', 'pipeline_stage_probabilities', 'pipelines',
        'pipeline_stages', 'tax_rates', 'field_groups', 'fields', 'field_models',
        'field_options', 'feature_statuses', 'email_templates', 'sms_templates',
    ];

    public function id(): ?string
    {
        if ($this->pinned) {
            return $this->businessId;
        }

        return Auth::hasUser() ? Auth::user()->team_id : null;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function business(): ?Business
    {
        return $this->id() ? Business::find($this->id()) : null;
    }

    public function set(?string $id): void
    {
        $this->pinned = true;
        $this->businessId = $id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($id);

        if (Auth::hasUser()) {
            Auth::user()->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function beginJob(?string $id): void
    {
        $this->jobs[] = [
            $this->businessId, $this->pinned, $this->system,
            app(PermissionRegistrar::class)->getPermissionsTeamId(),
            app('laravel-crm.settings')->contextState(),
        ];
        $this->system = false;
        $this->set($id);
        app('laravel-crm.settings')->forTeam($id);
    }

    public function endJob(): void
    {
        $previous = array_pop($this->jobs);
        if ($previous === null) {
            $this->set(null);
            app('laravel-crm.settings')->forTeam(null);

            return;
        }
        [$this->businessId, $this->pinned, $this->system, $permissionsId, $settingsState] = $previous;
        app(PermissionRegistrar::class)->setPermissionsTeamId($permissionsId);
        app('laravel-crm.settings')->restoreContext($settingsState);
        if (Auth::hasUser()) {
            Auth::user()->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function run(?string $id, Closure $callback): mixed
    {
        $previous = [$this->businessId, $this->pinned, $this->system];
        $permissionsId = app(PermissionRegistrar::class)->getPermissionsTeamId();
        $settings = app('laravel-crm.settings');
        $settingsState = $settings->contextState();
        $this->system = false;
        $this->set($id);
        $settings->forTeam($id);

        try {
            return $callback();
        } finally {
            [$this->businessId, $this->pinned, $this->system] = $previous;
            $settings->restoreContext($settingsState);
            app(PermissionRegistrar::class)->setPermissionsTeamId($permissionsId);
            if (Auth::hasUser()) {
                Auth::user()->unsetRelation('roles')->unsetRelation('permissions');
            }
        }
    }

    public function system(Closure $callback): mixed
    {
        $previous = $this->system;
        $this->system = true;
        try {
            return $callback();
        } finally {
            $this->system = $previous;
        }
    }

    public static function shared(string $table): bool
    {
        return in_array($table, array_map(
            fn ($name) => config('laravel-crm.db_table_prefix').$name,
            self::SHARED_CATALOGS
        ), true);
    }
}
