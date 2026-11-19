<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LiveSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $projection
 * @property CarbonImmutable|null $first_observed_at
 * @property CarbonImmutable|null $last_observed_at
 */
class LiveSession extends Model
{
    /** @use HasFactory<LiveSessionFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['projection' => 'array', 'first_observed_at' => 'immutable_datetime', 'last_observed_at' => 'immutable_datetime'];
    }

    /** @return HasMany<LiveConnector, $this> */
    public function connectors(): HasMany
    {
        return $this->hasMany(LiveConnector::class);
    }

    /** @return HasMany<LiveEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(LiveEvent::class);
    }
}
