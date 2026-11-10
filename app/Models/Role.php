<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use VentureDrake\LaravelCrm\Models\Role as CrmRole;

/**
 * @property string $id
 * @property string $name
 * @property string $guard_name
 *
 * @method static static create(array<string, mixed> $attributes = [])
 */
class Role extends CrmRole
{
    use HasUuids;
}
