<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use VentureDrake\LaravelCrm\Support\BusinessContext;
use VentureDrake\LaravelCrm\Traits\GuardsBusinessRelations;
use VentureDrake\LaravelCrm\Traits\HasCrmAccess;
use VentureDrake\LaravelCrm\Traits\HasCrmTeams;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Business|null $currentTeam
 * @property Business|null $business
 * @property string|null $team_id
 * @property bool $crm_access
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use GuardsBusinessRelations;
    use HasApiTokens, HasCrmAccess, HasCrmTeams, HasUuids;
    use HasRoles {
        removeRole as private removeAssignedRole;
        assignRole as private assignGlobalRole;
        syncRoles as private syncGlobalRoles;
    }

    protected static function booted(): void
    {
        // Authentication must resolve an identity before it can resolve tenancy.
        static::addGlobalScope('business', function (Builder $query): void {
            $context = app(BusinessContext::class);
            if (config('laravel-crm.teams') && ! $context->isSystem() && $context->id()) {
                $query->where('users.team_id', $context->id());
            }
        });
        static::saving(function (User $user): void {
            if (! config('laravel-crm.teams') || app(BusinessContext::class)->isSystem()) {
                return;
            }
            if (! $user->exists && ! $user->team_id && app(BusinessContext::class)->id()) {
                $user->team_id = app(BusinessContext::class)->id();
            }
            if ($user->exists && $user->getOriginal('team_id') && $user->isDirty('team_id')) {
                throw ValidationException::withMessages(['team_id' => 'A user cannot be transferred to another business.']);
            }
            if ($user->exists && $user->isDirty('crm_access') && ! $user->crm_access
                && Business::where('owner_user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['crm_access' => 'The business owner must retain CRM access.']);
            }
        });
        static::deleting(function (User $user): void {
            if (Business::where('owner_user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['user' => 'Transfer ownership before deleting its owner.']);
            }
        });
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'team_id');
    }

    /** @return BelongsTo<Business, $this> */
    public function currentTeam(): BelongsTo
    {
        return $this->business();
    }

    /** @return Collection<int, Business> */
    public function allTeams(): Collection
    {
        return $this->business ? collect([$this->business]) : collect();
    }

    /** @return HasMany<Business, $this> */
    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Business::class, 'owner_user_id');
    }

    public function switchTeam(mixed $team): bool
    {
        return $team instanceof Business && (string) $team->id === (string) $this->team_id;
    }

    public function removeRole(mixed $role): static
    {
        $name = is_string($role) ? $role : $role->name;
        if (config('laravel-crm.teams') && $name === 'Owner' && Business::where('owner_user_id', $this->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'Transfer ownership before removing the owner role.']);
        }

        return $this->removeAssignedRole($role);
    }

    public function assignRole(mixed ...$roles): static
    {
        if (config('laravel-crm.teams')) {
            if (! $this->team_id || (string) $this->team_id !== app(BusinessContext::class)->id()) {
                throw ValidationException::withMessages(['role' => 'Role assignments require the user business context.']);
            }
            foreach (collect($roles)->flatten() as $role) {
                if ($role instanceof Model && $role->getAttribute('team_id') !== null) {
                    throw ValidationException::withMessages(['role' => 'Only global roles may be assigned.']);
                }
            }
        }

        return $this->assignGlobalRole(...$roles);
    }

    public function syncRoles(mixed ...$roles): static
    {
        if (config('laravel-crm.teams') && Business::where('owner_user_id', $this->id)->exists()) {
            $names = collect($roles)->flatten()->map(fn ($role) => $role instanceof Model ? $role->getAttribute('name') : $role);
            if (! $names->contains('Owner')) {
                throw ValidationException::withMessages(['role' => 'Transfer ownership before removing the owner role.']);
            }
        }

        return $this->syncGlobalRoles(...$roles);
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
