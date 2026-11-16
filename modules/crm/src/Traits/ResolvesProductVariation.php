<?php

namespace VentureDrake\LaravelCrm\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductVariation;
use VentureDrake\LaravelCrm\Support\CatalogIdentity;

/**
 * Turns a submitted variation id into one that is safe to store on a line.
 *
 * The id arrives from a Livewire public property or an API payload, so it is
 * only kept when it names a variation of that same product in the current
 * team (the BelongsToTeams scope applies). A deleted variation is only kept
 * when the line already points at it - an existing line keeps its label, but
 * a new line cannot pick a deleted variation.
 */
trait ResolvesProductVariation
{
    protected function variationIdFor(?string $productId, ?string $variationId, ?string $currentId = null): ?string
    {
        if (($productId && ! Str::isUuid($productId))
            || ($variationId && ! Str::isUuid($variationId))) {
            CatalogIdentity::reject('INVALID_PRODUCT_VARIATION');
        }
        if ($variationId && ! $productId) {
            CatalogIdentity::reject('INVALID_PRODUCT_VARIATION');
        }
        if (! $variationId) {
            if ($productId && Product::whereKey($productId)->whereHas('options')->exists()) {
                CatalogIdentity::reject('STRUCTURED_SELECTION_REQUIRED');
            }

            return null;
        }

        $query = ProductVariation::query()
            ->where('product_id', $productId)
            ->whereKey($variationId);

        if ($currentId && (string) $currentId === (string) $variationId) {
            $query->withTrashed();
        }

        if (! $currentId || (string) $currentId !== (string) $variationId) {
            $query->where('active', true)->where('published', true);
        }
        $variation = $query->first();
        if (! $variation) {
            CatalogIdentity::reject('INVALID_PRODUCT_VARIATION');
        }
        if ((! $currentId || (string) $currentId !== (string) $variationId)
            && $variation->product->options()->exists()
            && ($variation->configuration_status !== 'configured' || ! $variation->published)) {
            CatalogIdentity::reject('VARIANT_NOT_SELLABLE');
        }

        return $variation->id;
    }

    /**
     * The variation on the line a new line is converted from (a deal line on
     * a quote, a quote line on an order, an order line on an invoice or
     * purchase order). Passed as $currentId so a conversion carries a variation
     * across even after it has been deleted from the product.
     *
     * @param  class-string<Model>  $lineClass
     */
    protected function sourceVariationId(string $lineClass, ?string $lineId): ?string
    {
        return $lineId ? $lineClass::query()->whereKey($lineId)->value('product_variation_id') : null;
    }
}
