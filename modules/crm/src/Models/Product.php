<?php

namespace VentureDrake\LaravelCrm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use VentureDrake\LaravelCrm\Traits\BelongsToTeams;
use VentureDrake\LaravelCrm\Traits\HasCrmFields;
use VentureDrake\LaravelCrm\Traits\SearchFilters;

class Product extends Model
{
    use BelongsToTeams;
    use HasCrmFields;
    use SearchFilters;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $searchable = [
        'name',
    ];

    protected $filterable = [
        'user_owner_id',
        'labels.id',
    ];

    protected $casts = [
        'active' => 'boolean',
        'options_version' => 'integer',
    ];

    public function getSearchable()
    {
        return $this->searchable;
    }

    public function getTable()
    {
        return config('laravel-crm.db_table_prefix').'products';
    }

    /**
     * The product-level prices.
     *
     * Variation prices share crm_product_prices and carry this product's id
     * too, so they are excluded here - otherwise one could be read back as the
     * product price. They are reached through ProductVariation::productPrices().
     */
    public function productPrices()
    {
        return $this->hasMany(ProductPrice::class)->whereNull('product_variation_id');
    }

    /**
     * The price row for the install's currency.
     *
     * Reads the currency off the cached settings map rather than re-querying
     * crm_settings, and prefers an eager-loaded productPrices — the products
     * index calls this three times for every row it renders.
     */
    public function getDefaultPrice()
    {
        $currency = app('laravel-crm.settings')->get('currency') ?? 'USD';

        if ($this->relationLoaded('productPrices')) {
            return $this->productPrices->firstWhere('currency', $currency);
        }

        return $this->productPrices()->where('currency', $currency)->first();
    }

    /**
     * The product's own price row: the install currency's, or failing that
     * whichever row it has - a product priced only in another currency has
     * no default. The edit form, its save and the show page all read this
     * row, so the variation prices they show and write share its currency.
     */
    public function getPrimaryPrice()
    {
        if ($price = $this->getDefaultPrice()) {
            return $price;
        }

        return $this->relationLoaded('productPrices')
            ? $this->productPrices->first()
            : $this->productPrices()->first();
    }

    /**
     * The unit price, in cents, for a line of this product.
     *
     * A variation's price in the document currency wins; otherwise the line
     * falls back to the product price, exactly as a line with no variation.
     */
    public function unitPriceFor(?ProductVariation $variation, ?string $currency): ?int
    {
        $currency = $currency ?: (app('laravel-crm.settings')->get('currency') ?? 'USD');
        if ($variation && ((string) $variation->product_id !== (string) $this->id || (string) $variation->team_id !== (string) $this->team_id)) {
            throw ValidationException::withMessages(['variation' => 'VALUE_NOT_IN_PRODUCT']);
        }
        $parentPrice = $this->relationLoaded('productPrices')
            ? $this->productPrices->firstWhere('currency', $currency)
            : $this->productPrices()->where('currency', $currency)->first();

        return $variation?->priceFor($currency)?->unit_price ?? $parentPrice?->unit_price;
    }

    public function files()
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function options()
    {
        return $this->hasMany(ProductOption::class)->orderBy('position')->orderBy('id');
    }

    public function productVariations()
    {
        return $this->hasMany(ProductVariation::class)->ordered();
    }

    public function activeProductVariations()
    {
        return $this->productVariations()->active();
    }

    public function productCategory()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'user_created_id');
    }

    public function updatedByUser()
    {
        return $this->belongsTo(User::class, 'user_updated_id');
    }

    public function deletedByUser()
    {
        return $this->belongsTo(User::class, 'user_deleted_id');
    }

    public function restoredByUser()
    {
        return $this->belongsTo(User::class, 'user_restored_id');
    }

    public function ownerUser()
    {
        return $this->belongsTo(User::class, 'user_owner_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }

    /**
     * Get the xero item associated with the product.
     */
    public function xeroItem()
    {
        return $this->hasOne(XeroItem::class);
    }

    public function taxRate()
    {
        return $this->belongsTo(TaxRate::class);
    }
}
