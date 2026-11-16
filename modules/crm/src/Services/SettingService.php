<?php

namespace VentureDrake\LaravelCrm\Services;

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Support\BusinessContext;

class SettingService
{
    public const GLOBAL_KEYS = [
        'app_name', 'app_env', 'app_url', 'version', 'version_latest', 'install_id',
        'country', 'currency', 'timezone', 'language', 'date_format', 'time_format',
        'tax_name', 'tax_rate', 'lead_prefix', 'deal_prefix', 'quote_prefix',
        'order_prefix', 'invoice_prefix', 'delivery_prefix', 'purchase_order_prefix',
        'dynamic_products', 'show_related_activity', 'team',
    ];

    private ?string $teamId = null;

    private bool $hasTeamOverride = false;

    private array $memo = [];

    public function forTeam(?string $teamId): static
    {
        $this->teamId = $teamId;
        $this->hasTeamOverride = true;

        return $this;
    }

    public function contextState(): array
    {
        return [$this->teamId, $this->hasTeamOverride];
    }

    public function restoreContext(array $state): void
    {
        [$this->teamId, $this->hasTeamOverride] = $state;
    }

    private function id(): ?string
    {
        return $this->hasTeamOverride ? $this->teamId : app(BusinessContext::class)->id();
    }

    public function all(): array
    {
        $key = $this->cacheKey();
        if (! isset($this->memo[$key])) {
            $this->memo[$key] = Cache::remember($key, 3600, function () {
                $query = Setting::withoutGlobalScopes()->whereNull('user_id');
                if (config('laravel-crm.teams')) {
                    $id = $this->id();
                    $query->where(function ($q) use ($id) {
                        $q->where(fn ($global) => $global->whereNull('team_id')->whereIn('name', self::GLOBAL_KEYS));
                        if ($id) {
                            $q->orWhere('team_id', $id);
                        }
                    });
                    // Tenant preferences override installation defaults deterministically.
                    $query->orderByRaw('CASE WHEN team_id IS NULL THEN 0 ELSE 1 END');
                }

                return $query->orderBy('id')->pluck('value', 'name')->all();
            });
        }
        $values = $this->memo[$key];
        if (config('laravel-crm.teams') && $this->id()) {
            $business = Business::find($this->id());
            if ($business) {
                foreach (Business::SETTINGS as $name => $column) {
                    $values[$name] = $business->getAttribute($column);
                }
            }
        }

        return $values;
    }

    public function get(string $name, $default = null)
    {
        return $this->all()[$name] ?? $default;
    }

    public function first(string $name)
    {
        if (config('laravel-crm.teams') && $name === 'team') {
            return $this->id() ? Business::find($this->id()) : null;
        }

        return Setting::whereNull('user_id')->where('name', $name)->first();
    }

    public function set($name, $value, $label = null)
    {
        $id = config('laravel-crm.teams') ? $this->id() : null;
        if (config('laravel-crm.teams') && ! $id) {
            throw ValidationException::withMessages(['business' => 'A business is required to change CRM settings.']);
        }
        if ($id && isset(Business::SETTINGS[$name])) {
            $business = Business::findOrFail($id);
            $business->setAttribute(Business::SETTINGS[$name], $value);
            $business->save();
            $this->forgetCache();

            return $business;
        }
        $setting = Setting::withoutGlobalScopes()->firstOrNew([
            'team_id' => $id, 'user_id' => null, 'name' => $name,
        ]);
        $setting->forceFill(['value' => $value, 'label' => $label, 'global' => false])->save();
        $this->forgetCache();

        return $setting;
    }

    public function getForUser($userId, string $name, $default = null)
    {
        $row = Setting::where('user_id', $userId)->where('name', $name)->first();

        return $row ? $row->value : $default;
    }

    public function setForUser($userId, string $name, $value)
    {
        if (config('laravel-crm.teams')) {
            abort_unless(User::whereKey($userId)->where('team_id', $this->id())->exists(), 403);
        }

        return Setting::updateOrCreate(['user_id' => $userId, 'name' => $name], ['value' => $value]);
    }

    public function setInstallWide(string $name, $value)
    {
        abort_if(config('laravel-crm.teams') && ! app()->runningInConsole() && ! app(BusinessContext::class)->isSystem(), 403);

        $setting = app(BusinessContext::class)->system(fn () => Setting::withoutGlobalScopes()->updateOrCreate(
            ['name' => $name, 'team_id' => null, 'user_id' => null],
            ['value' => $value, 'global' => true]
        ));
        $this->forgetCache();

        return $setting;
    }

    public function getInstallWide(string $name, $default = null)
    {
        abort_unless(app()->runningInConsole() || app(BusinessContext::class)->isSystem(), 403);

        return Setting::withoutGlobalScopes()->whereNull('team_id')->whereNull('user_id')
            ->where('name', $name)->value('value') ?? $default;
    }

    public function cacheKey(): string
    {
        $generation = Cache::get('app.crm-settings.generation', 0);

        return 'app.crm-settings.'.$generation.'.team.'.(config('laravel-crm.teams') ? ($this->id() ?? 'none') : 'disabled');
    }

    public function forgetCache(): void
    {
        Cache::add('app.crm-settings.generation', 0);
        Cache::increment('app.crm-settings.generation');
        $this->memo = [];
    }

    public function tableExists(): bool
    {
        return Schema::hasTable((new Setting)->getTable());
    }
}
