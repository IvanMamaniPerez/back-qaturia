<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property array{token: string, access_token: string, token_type: string, expires_in: int, expires_at: string, refresh_token: string, refresh_expires_at: string}|null $rotation_result
 */
class ApiRefreshToken extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'password_fingerprint', 'rotation_key_hash', 'rotation_result'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime', 'rotation_result' => 'encrypted:array'];
    }
}
