<?php

namespace VentureDrake\LaravelCrm\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use VentureDrake\LaravelCrm\Support\CrmDateTime;
use VentureDrake\LaravelCrm\Support\UtcDateTime;

class Model extends EloquentModel
{
    use HasUuids;

    /** @return mixed */
    public function setAttribute($key, $value)
    {
        if ($value !== null && $value !== '' && ($this->getCasts()[$key] ?? null) === UtcDateTime::class) {
            $value = CrmDateTime::utc($value);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * CRM instants stay in UTC in memory, persistence and JSON. Regional
     * conversion belongs to input/presentation boundaries, never global PHP state.
     * Civil dates retain their calendar day even when stored in legacy timestamps.
     *
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        $casts = parent::getCasts();
        $civilDates = ['birthday', 'issue_date', 'due_date', 'delivery_date', 'issue_at', 'expire_at', 'expected_close', 'delivery_expected', 'delivered_on'];

        foreach ($this->getDates() as $key) {
            $casts[$key] ??= 'datetime';
        }

        foreach ($casts as $key => $cast) {
            if (in_array($cast, ['datetime', 'immutable_datetime'], true) && ! in_array($key, $civilDates, true)) {
                $casts[$key] = UtcDateTime::class;
            }
        }

        return $casts;
    }

    /** @param array<string, mixed> $options */
    public function saveQuietly(array $options = [])
    {
        return static::withoutEvents(function () use ($options) {
            return $this->save($options);
        });
    }
}
