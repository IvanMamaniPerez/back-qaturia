<?php

namespace VentureDrake\LaravelCrm\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use VentureDrake\LaravelCrm\Support\CatalogIdentity;
use VentureDrake\LaravelCrm\Traits\BelongsToTeams;

class ProductVariation extends Model
{
    use BelongsToTeams;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'active' => 'boolean',
        'published' => 'boolean',
    ];

    public function getTable()
    {
        return config('laravel-crm.db_table_prefix').'product_variations';
    }

    protected static function booted(): void
    {
        static::saving(function ($variation) {
            if ($variation->exists && ($variation->isDirty('product_id') || $variation->isDirty('team_id'))) {
                CatalogIdentity::reject('MATRIX_IDENTITY_IMMUTABLE');
            }
        });
        static::deleting(function ($variation) {
            if ($variation->isForceDeleting()) {
                throw ValidationException::withMessages(['variation' => 'CANNOT_DELETE_HISTORICAL_VARIANT']);
            }
        });
    }

    public function selections()
    {
        return $this->hasMany(ProductVariationOptionValue::class);
    }

    public function imageFile()
    {
        return $this->belongsTo(File::class, 'image_file_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productPrices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * The price row for the install's currency.
     *
     * Mirrors Product::getDefaultPrice(), including the eager-loaded path.
     */
    public function getDefaultPrice()
    {
        return $this->priceFor(null);
    }

    /**
     * The price row for a currency, or for the install's currency when none is
     * given. Null when the variation has no price in that currency - callers
     * fall back to the product price.
     */
    public function priceFor(?string $currency)
    {
        $currency = $currency ?: (app('laravel-crm.settings')->get('currency') ?? 'USD');

        if ($this->relationLoaded('productPrices')) {
            return $this->productPrices->firstWhere('currency', $currency);
        }

        return $this->productPrices()->where('currency', $currency)->first();
    }

    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('id');
    }
}
