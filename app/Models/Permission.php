<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use VentureDrake\LaravelCrm\Models\Permission as CrmPermission;

/**
 * @property string $id
 * @property string $name
 * @property string $guard_name
 *
 * @method static static create(array<string, mixed> $attributes = [])
 */
class Permission extends CrmPermission
{
    use HasUuids;
}
