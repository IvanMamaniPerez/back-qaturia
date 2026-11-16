<?php

namespace VentureDrake\LaravelCrm\Models;

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Support\CatalogIdentity;
use VentureDrake\LaravelCrm\Traits\BelongsToTeams;

class ProductOption extends Model
{
    use BelongsToTeams;

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean'];

    public function getTable()
    {
        return config('laravel-crm.db_table_prefix').'product_options';
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function values()
    {
        return $this->hasMany(ProductOptionValue::class)->orderBy('position')->orderBy('id');
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->external_id ??= (string) Str::uuid();
            $model->user_created_id = auth()->id();
        });
        static::saving(function ($model) {
            $model->normalized_name = CatalogIdentity::normalize($model->name);
            $model->user_updated_id = auth()->id();
        });
    }
}
