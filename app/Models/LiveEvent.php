<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LiveEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $observed_at
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $occurred_at
 */
class LiveEvent extends Model
{
    /** @use HasFactory<LiveEventFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    public const UPDATED_AT = null;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('LIVE source events are immutable.'));
        static::deleting(fn () => throw new \LogicException('LIVE source events are immutable.'));
    }
}
