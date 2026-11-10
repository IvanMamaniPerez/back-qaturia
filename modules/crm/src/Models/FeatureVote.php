<?php

namespace VentureDrake\LaravelCrm\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class FeatureVote extends Pivot
{
    use HasUuids;

    protected $guarded = ['id'];

    public function getTable()
    {
        return config('laravel-crm.db_table_prefix').'feature_votes';
    }

    public function feature()
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }
}
