<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LiveEventReceiptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $envelope
 * @property CarbonImmutable $received_at
 */
class LiveEventReceipt extends Model
{
    /** @use HasFactory<LiveEventReceiptFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['envelope' => 'array', 'received_at' => 'immutable_datetime'];
    }

    public $timestamps = false;
}
