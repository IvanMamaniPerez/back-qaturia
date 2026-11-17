<?php

namespace VentureDrake\LaravelCrm\Services;

use Dcblogdev\Xero\Facades\Xero;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductVariation;
use VentureDrake\LaravelCrm\Repositories\ProductRepository;
use VentureDrake\LaravelCrm\Support\CatalogIdentity;
use VentureDrake\LaravelCrm\Support\XeroIntegration;

class ProductService
{
    /**
     * @var ProductRepository
     */
    private $productRepository;

    /**
     * LeadService constructor.
     */
    public function __construct(ProductRepository $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    public function create($request)
    {
        return DB::transaction(function () use ($request) {
            $product = Product::create([
                'name' => $request->name,
                'code' => $request->code ?? null,
                'barcode' => $request->barcode ?? null,
                'purchase_account' => $request->purchase_account ?? null,
                'sales_account' => $request->sales_account ?? null,
                'product_category_id' => $request->product_category,
                'unit' => $request->unit ?? null,
                'tax_rate_id' => $request->tax_rate_id ?? null,
                'tax_rate' => $request->tax_rate ?? null,
                'description' => $request->description ?? null,
                'user_owner_id' => $request->user_owner_id,
            ]);

            $productPrice = $product->productPrices()->create([
                'unit_price' => $request->unit_price,
                'currency' => $request->currency,
            ]);

            if (is_array($request->variations ?? null)) {
                $this->syncVariations($product, $request->variations, $request->currency);
            }

            if (XeroIntegration::connected()) {
                $xeroProduct = Xero::post('Items', [
                    'Code' => $product->code,
                    'Name' => $product->name,
                    'Description' => $product->description,
                    'PurchaseDetails' => [
                        'AccountCode' => $product->purchase_account ?? 310,
                    ],
                    'SalesDetails' => [
                        'UnitPrice' => $productPrice->unit_price ? $productPrice->unit_price / 100 : null,
                        'AccountCode' => $product->sales_account ?? 200,
                    ],
                ]);

                $item = $xeroProduct['body']['Items'][0];

                $product->xeroItem()->updateOrCreate([
                    'item_id' => $item['ItemID'],
                ], [
                    'code' => $item['Code'],
                    'name' => $item['Name'],
                    'inventory_tracked' => $item['IsTrackedAsInventory'],
                    'is_sold' => $item['IsSold'],
                    'is_purchased' => $item['IsPurchased'],
                    'purchase_price' => (isset($item['PurchaseDetails']['UnitPrice'])) ? $item['PurchaseDetails']['UnitPrice'] : null,
                    'sell_price' => (isset($item['SalesDetails']['UnitPrice'])) ? $item['SalesDetails']['UnitPrice'] : null,
                    'purchase_description' => $item['PurchaseDescription'] ?? null,
                ]);
            }

            return $product;
        });
    }

    public function update(Product $product, $request)
    {
        return DB::transaction(function () use ($product, $request) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            app(ProductCatalogSnapshotService::class)->backfill($product);

            $product->update([
                'name' => $request->name,
                'code' => $request->code ?? null,
                'barcode' => $request->barcode ?? null,
                'purchase_account' => $request->purchase_account ?? null,
                'sales_account' => $request->sales_account ?? null,
                'product_category_id' => $request->product_category,
                'unit' => $request->unit ?? null,
                'tax_rate_id' => $request->tax_rate_id ?? null,
                'tax_rate' => $request->tax_rate ?? null,
                'description' => $request->description ?? null,
                'user_owner_id' => $request->user_owner_id,
            ]);

            // The same row ProductEdit loads: a product priced only in another
            // currency keeps that price rather than gaining a second, empty one.
            // The Xero sync below reads it too, as there may be no default price.
            $productPrice = $product->getPrimaryPrice();

            if ($productPrice) {
                $productPrice->update([
                    'unit_price' => $request->unit_price,
                ]);
            } else {
                $productPrice = $product->productPrices()->create([
                    'unit_price' => $request->unit_price,
                    'currency' => $request->currency,
                ]);
            }

            // In the currency of the product price just saved, not the form's
            // currency select: the product price ignores that select on edit, and
            // the form reloads variation prices in the product price's currency.
            if (is_array($request->variations ?? null)) {
                $this->syncVariations($product, $request->variations, $productPrice->currency);
            }

            if (XeroIntegration::connected()) {
                $xeroProduct = Xero::post('Items', [
                    'ItemID' => $product->xeroItem->item_id ?? null,
                    'Code' => $product->code,
                    'Name' => $product->name,
                    'Description' => $product->description,
                    'PurchaseDetails' => [
                        'AccountCode' => $product->purchase_account ?? 310,
                    ],
                    'SalesDetails' => [
                        'UnitPrice' => $productPrice->unit_price ? $productPrice->unit_price / 100 : null,
                        'AccountCode' => $product->sales_account ?? 200,
                    ],
                ]);

                $item = $xeroProduct['body']['Items'][0];

                $product->xeroItem()->updateOrCreate([
                    'item_id' => $item['ItemID'],
                ], [
                    'code' => $item['Code'],
                    'name' => $item['Name'],
                    'inventory_tracked' => $item['IsTrackedAsInventory'],
                    'is_sold' => $item['IsSold'],
                    'is_purchased' => $item['IsPurchased'],
                    'purchase_price' => (isset($item['PurchaseDetails']['UnitPrice'])) ? $item['PurchaseDetails']['UnitPrice'] : null,
                    'sell_price' => (isset($item['SalesDetails']['UnitPrice'])) ? $item['SalesDetails']['UnitPrice'] : null,
                    'purchase_description' => $item['PurchaseDescription'] ?? null,
                ]);
            }

            $product->increment('options_version');

            return $product;
        });
    }

    /**
     * Make the product's variations match the rows submitted by the product
     * form without deleting omitted identities. Explicit archive is required.
     * Invalid IDs fail instead of becoming accidental new variations.
     *
     * Each row carries a single price, in the product's currency.
     */
    public function syncVariations(Product $product, array $rows, ?string $currency = null): void
    {
        DB::transaction(function () use ($product, $rows, $currency) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            foreach (array_values($rows) as $index => $row) {
                $data = array_intersect_key($row, array_flip(['name', 'code', 'barcode', 'active', 'published', 'unit_price', 'image_file_id']));
                $data['order'] = $index + 1;
                if (! empty($row['id'])) {
                    if (! Str::isUuid($row['id'])) {
                        CatalogIdentity::reject('VALUE_NOT_IN_PRODUCT');
                    }
                    $variation = $product->productVariations()->whereKey($row['id'])->first();
                    if (! $variation) {
                        CatalogIdentity::reject('VALUE_NOT_IN_PRODUCT');
                    }
                    $this->updateVariation($variation, $data, $currency);
                } else {
                    $this->createVariation($product, $data, $currency);
                }
            }
        });
    }

    /**
     * Create a variation. `unit_price` (in the major unit, e.g. dollars) is
     * optional; without it the variation sells at the product price.
     */
    public function createVariation(Product $product, array $data, ?string $currency = null): ProductVariation
    {
        return DB::transaction(function () use ($product, $data, $currency) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if (isset($data['options_version'])) {
                abort_unless((int) $data['options_version'] === $product->options_version, 409, 'STALE_OPTIONS_VERSION');
            }
            if ($product->options()->exists()) {
                CatalogIdentity::reject('STRUCTURED_SELECTION_REQUIRED_USE_MATRIX');
            }
            if ($product->productVariations()->withTrashed()->count() >= ProductVariantMatrixService::MAX_VARIANTS) {
                CatalogIdentity::reject('VARIANT_LIMIT_EXCEEDED');
            }
            $variation = $product->productVariations()->create($this->variationAttributes($data) + [
                'team_id' => $product->team_id,
                'order' => ($product->productVariations()->max('order') ?? 0) + 1,
            ]);
            if (array_key_exists('unit_price', $data)) {
                $this->setVariationPrice($variation, $data['unit_price'], $currency);
            }
            if (array_key_exists('image_file_id', $data)) {
                $variation = app(ProductVariantMatrixService::class)->updateVariantDetails($variation, ['image_file_id' => $data['image_file_id']]);
            }
            $product->increment('options_version');

            return $variation;
        });
    }

    /**
     * Update a variation. Only the keys present in $data change; a present
     * but blank `unit_price` removes the variation's price in that currency.
     */
    public function updateVariation(ProductVariation $variation, array $data, ?string $currency = null): ProductVariation
    {
        return app(ProductVariantMatrixService::class)->updateVariantDetails($variation, $data, $currency);
    }

    /**
     * Soft-delete a variation. Its prices stay, so a restored variation comes
     * back priced, and existing lines still resolve it (withTrashed).
     */
    public function deleteVariation(ProductVariation $variation): void
    {
        app(ProductVariantMatrixService::class)->archiveVariant($variation);
    }

    /**
     * Set or clear a variation's price in one currency. The currency defaults
     * to the product's own price currency, then the install currency.
     */
    public function setVariationPrice(ProductVariation $variation, $unitPrice, ?string $currency = null): void
    {
        $currency = $currency
            ?: $variation->product?->getPrimaryPrice()?->currency
            ?: (app('laravel-crm.settings')->get('currency') ?? 'USD');

        if ($unitPrice === null || $unitPrice === '') {
            $variation->productPrices()->where('currency', $currency)->get()->each->delete();

            return;
        }

        $variation->productPrices()->updateOrCreate([
            'currency' => $currency,
            'product_id' => $variation->product_id,
        ], [
            'unit_price' => $unitPrice,
        ]);
    }

    protected function variationAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip(['name', 'code', 'barcode', 'active', 'published', 'order']));
    }
}
