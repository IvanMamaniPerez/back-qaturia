<?php

namespace VentureDrake\LaravelCrm\Models;

use VentureDrake\LaravelCrm\Traits\BelongsToTeams;

class ProductVariationOptionValue extends Model
{
    use BelongsToTeams;

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean'];

    public function getTable()
    {
        return config('laravel-crm.db_table_prefix').'product_variation_option_values';
    }

    public $timestamps = false;

    public function option()
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    public function value()
    {
        return $this->belongsTo(ProductOptionValue::class, 'product_option_value_id');
    }
}
