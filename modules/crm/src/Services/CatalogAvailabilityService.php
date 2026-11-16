<?php

namespace VentureDrake\LaravelCrm\Services;

use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductVariation;
use VentureDrake\LaravelCrm\Support\CatalogIdentity;

/** Future inventory adapter: quantities must be backed by an actual ledger/provider. */
class CatalogAvailabilityService
{
    public function forIdentity(Product $product, ?ProductVariation $variation): array
    {
        if ($variation && ((string) $variation->product_id !== (string) $product->id
            || (string) $variation->team_id !== (string) $product->team_id)) {
            CatalogIdentity::reject('VALUE_NOT_IN_PRODUCT');
        }

        return [
            'status' => 'unknown', 'managed' => false, 'available_quantity' => null,
            'requires_variant_selection' => $variation === null && $product->options()->exists(),
            // Product stock, if added by an integration, must be physically classified.
            'parent_stock_transfer_allowed' => false,
        ];
    }
}
