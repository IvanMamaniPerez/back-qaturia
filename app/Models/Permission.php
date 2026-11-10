<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * @property string $id
 * @property string $name
 * @property string $guard_name
 *
 * @method static static create(array<string, mixed> $attributes = [])
 */
class Permission extends SpatiePermission
{
    use HasUuids;

    /**
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeCrm(Builder $query): Builder
    {
        return $query->where('crm_permission', 1);
    }
}
