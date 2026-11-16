<?php

namespace VentureDrake\LaravelCrm\Relations;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use VentureDrake\LaravelCrm\Support\BusinessContext;
use VentureDrake\LaravelCrm\Support\BusinessIntegrity;

trait GuardsBusinessPivot
{
    protected function formatAttachRecord($key, $value, $attributes, $hasTimestamps)
    {
        $record = parent::formatAttachRecord($key, $value, $attributes, $hasTimestamps);
        if (Schema::hasColumn($this->table, 'id')) {
            $record['id'] ??= (string) Str::uuid();
        }

        return $record;
    }

    public function sync($ids, $detaching = true)
    {
        $this->validateBusinessIds($ids);

        return parent::sync($ids, $detaching);
    }

    public function attach($ids, array $attributes = [], $touch = true)
    {
        $this->validateBusinessIds($ids);

        return parent::attach($ids, $attributes, $touch);
    }

    private function validateBusinessIds($ids): void
    {
        $context = app(BusinessContext::class);
        if (! config('laravel-crm.teams') || $context->isSystem()
            || ! app(BusinessIntegrity::class)->hasTeam($this->related->getTable())) {
            return;
        }
        $records = $this->formatRecordsList($this->parseIds($ids));
        $keys = array_keys($records);
        $query = $this->related->newQuery()->whereKey($keys);
        if ($this->related instanceof User) {
            $query->where('team_id', $context->id());
        }
        if (count($keys) !== $query->count()) {
            throw ValidationException::withMessages(['relations' => 'One or more related records belong to another business.']);
        }
    }
}
