<?php

namespace VentureDrake\LaravelCrm\Traits;

use VentureDrake\LaravelCrm\Services\ProductCatalogSnapshotService;

/**
 * The name a line item prints: the product name, plus the variation name when
 * the line has one ("Widget — Large").
 *
 * Both relations are withTrashed() on the line models, so a line keeps its
 * label after the product or variation is deleted. Eager-load
 * `product` and `productVariation` wherever lines are listed.
 */
trait HasProductLineName
{
    public static function bootHasProductLineName(): void
    {
        static::saving(function ($line) {
            $identityChanged = $line->exists && ($line->isDirty('product_id') || $line->isDirty('product_variation_id'));
            if ($identityChanged && $line->catalog_snapshot) {
                // A document edit chooses a different commercial item; a catalog edit never does.
                $line->unsetRelation('product');
                $line->unsetRelation('productVariation');
            }
            if (! $line->exists || $identityChanged || (! $line->catalog_snapshot && $line->product_id)) {
                $line->catalog_snapshot = json_encode(app(ProductCatalogSnapshotService::class)
                    ->capture($line->product, $line->productVariation), JSON_THROW_ON_ERROR);
                $line->catalog_snapshot_source = $line->exists ? 'legacy_best_effort' : 'captured_at_creation';
            }
        });
    }

    public function catalogSnapshot(): ?array
    {
        return $this->catalog_snapshot ? json_decode($this->catalog_snapshot, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function displayName(): string
    {
        $snapshot = $this->catalogSnapshot();
        $name = (string) ($snapshot['product_name'] ?? $this->product?->name ?? '');

        $variationName = $snapshot !== null ? (string) ($snapshot['variation_name'] ?? '') : ($this->product_variation_id ? (string) ($this->productVariation?->name ?? '') : '');

        if ($variationName === '') {
            return $name;
        }

        return $name === '' ? $variationName : $name.' — '.$variationName;
    }

    /**
     * The code a line prints: the variation's own code when it has one,
     * otherwise the product code.
     */
    public function displayCode(): ?string
    {
        if ($snapshot = $this->catalogSnapshot()) {
            return $snapshot['sku'];
        }
        if ($this->product_variation_id && ($code = $this->productVariation?->code)) {
            return $code;
        }

        return $this->product?->code;
    }
}
