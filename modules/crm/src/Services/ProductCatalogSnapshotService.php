<?php

namespace VentureDrake\LaravelCrm\Services;

use Illuminate\Support\Facades\DB;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductVariation;

class ProductCatalogSnapshotService
{
    public function capture(?Product $product, ?ProductVariation $variation): array
    {
        return [
            'product_name' => $product?->name ?? '', 'variation_name' => $variation?->name,
            'sku' => $variation?->code ?: $product?->code,
            'product_code' => $product?->code, 'variation_code' => $variation?->code,
            'options' => $variation ? $variation->loadMissing(['selections.option', 'selections.value'])->selections->map(fn ($s) => [
                'option_id' => $s->option->external_id, 'name' => $s->option->name,
                'value_id' => $s->value->external_id, 'value' => $s->value->value,
            ])->all() : [],
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /** Unknown historical wording is explicitly labelled, never claimed as original. */
    public function backfill(Product $product): void
    {
        $variants = $product->productVariations()->withTrashed()->with(['selections.option', 'selections.value'])->get()->keyBy('id');
        foreach (['deal_products', 'quote_products', 'order_products', 'invoice_lines', 'purchase_order_lines'] as $table) {
            $name = config('laravel-crm.db_table_prefix').$table;
            DB::table($name)->where('product_id', $product->id)->whereNull('catalog_snapshot')->orderBy('id')->chunkById(100, function ($rows) use ($product, $variants, $name) {
                foreach ($rows as $row) {
                    DB::table($name)->where('id', $row->id)->whereNull('catalog_snapshot')->update([
                        'catalog_snapshot' => json_encode($this->capture($product, $variants->get($row->product_variation_id)), JSON_THROW_ON_ERROR),
                        'catalog_snapshot_source' => 'legacy_best_effort',
                    ]);
                }
            });
        }
    }
}
