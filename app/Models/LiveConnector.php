<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LiveConnectorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable|null $state_observed_at
 * @property CarbonImmutable|null $revoked_at
 */
class LiveConnector extends Model
{
    /** @use HasFactory<LiveConnectorFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['observed_state' => 'initialized'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revoked_at' => 'immutable_datetime', 'state_observed_at' => 'immutable_datetime'];
    }
}
