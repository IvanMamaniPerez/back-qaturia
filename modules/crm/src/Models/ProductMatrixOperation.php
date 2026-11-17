<?php

namespace VentureDrake\LaravelCrm\Models;

use VentureDrake\LaravelCrm\Traits\BelongsToTeams;

class ProductMatrixOperation extends Model
{
    use BelongsToTeams;

    protected $guarded = ['id'];

    protected $casts = ['request' => 'array', 'result' => 'array'];

    public function getTable()
    {
        return config('laravel-crm.db_table_prefix').'product_matrix_operations';
    }
}
