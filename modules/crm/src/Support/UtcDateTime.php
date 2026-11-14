<?php

namespace VentureDrake\LaravelCrm\Support;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @implements CastsAttributes<Carbon, DateTimeInterface|string|int|null>
 */
class UtcDateTime implements CastsAttributes, SerializesCastableAttributes
{
    public bool $withoutObjectCaching = true;

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return CrmDateTime::utc($value);
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return CrmDateTime::utc($value)?->format($model->getDateFormat());
    }

    /** @param array<string, mixed> $attributes */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return CrmDateTime::utc($value)?->toJSON();
    }
}
