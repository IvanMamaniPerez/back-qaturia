<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property string $id
 * @property string $name
 * @property string $guard_name
 *
 * @method static static create(array<string, mixed> $attributes = [])
 */
class Role extends SpatieRole
{
    use HasUuids;

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeCrm(Builder $query): Builder
    {
        return $query->where('crm_role', 1);
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeCrmNotOwner(Builder $query): Builder
    {
        return $query->where('crm_role', 1)->where('name', '<>', 'Owner');
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('crm_role', 1)->when(config('laravel-crm.teams'), function ($query) {
            $currentTeamId = auth()->user()?->currentTeam?->id;

            $query->where(function ($query) use ($currentTeamId) {
                $query->whereNull('team_id')
                    ->orWhere('team_id', $currentTeamId);
            });
        });
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeAssignableBy(Builder $query, ?User $user = null): Builder
    {
        $user = func_num_args() > 1 ? $user : auth()->user();

        return $this->scopeAssignable($query)->unless(
            (bool) $user?->hasRole('Owner'),
            fn ($query) => $query->where('name', '<>', 'Owner')
        );
    }
}
