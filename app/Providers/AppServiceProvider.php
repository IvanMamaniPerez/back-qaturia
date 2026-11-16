<?php

namespace App\Providers;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use VentureDrake\LaravelCrm\Support\BusinessContext;
use VentureDrake\LaravelCrm\Support\BusinessIntegrity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(BusinessContext::class);
        $this->app->scoped(BusinessIntegrity::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Gate::before(function ($user, string $ability, array $arguments = []) {
            if (! config('laravel-crm.teams')) {
                return null;
            }
            $target = $arguments[0] ?? null;
            $read = in_array($ability, ['view', 'viewAny'], true);
            if (in_array(is_object($target) ? get_class($target) : $target, [Role::class, Permission::class], true)) {
                return $read ? null : false;
            }
            if ($target instanceof Model) {
                if ($target instanceof Business) {
                    return (string) $target->id === (string) $user->team_id ? null : false;
                }
                if (array_key_exists('team_id', $target->getAttributes())) {
                    if ($read && $target->getAttribute('team_id') === null && BusinessContext::shared($target->getTable())) {
                        return null;
                    }
                    if (! $user->team_id || (string) $target->getAttribute('team_id') !== (string) $user->team_id) {
                        return false;
                    }
                }
            }

            return null;
        });

        Queue::createPayloadUsing(fn () => [
            'business_id' => app(BusinessContext::class)->id(),
        ]);
        Queue::before(function ($event) {
            app(BusinessContext::class)->beginJob($event->job->payload()['business_id'] ?? null);
        });
        $clear = fn () => app(BusinessContext::class)->endJob();
        Queue::after($clear);
        Queue::exceptionOccurred($clear);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
