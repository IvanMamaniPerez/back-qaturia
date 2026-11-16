<?php

namespace VentureDrake\LaravelCrm\Http\Resources\Api\V2;

use Illuminate\Http\Resources\Json\JsonResource;
use VentureDrake\LaravelCrm\Services\CatalogAvailabilityService;

class ProductVariationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->external_id,
            'external_id' => $this->external_id,
            'published' => (bool) $this->published,
            'archived' => $this->deleted_at !== null,
            'configuration_status' => $this->configuration_status,
            'combination_key' => $this->combination_key,
            'availability' => 'unknown',
            'inventory' => $this->whenLoaded('product', fn () => app(CatalogAvailabilityService::class)->forIdentity($this->product, $this->resource)),
            'image_file_id' => $this->whenLoaded('imageFile', fn () => $this->imageFile?->external_id),
            'selected_options' => $this->whenLoaded('selections', fn () => $this->selections->map(fn ($s) => [
                'option_id' => $s->option->external_id, 'name' => $s->option->name,
                'value_id' => $s->value->external_id, 'value' => $s->value->value,
            ])->values()),
            'effective_price' => $this->whenLoaded('product', function () use ($request) {
                $currency = $request->query('currency') ?: (app('laravel-crm.settings')->get('currency') ?? 'USD');
                $amount = $this->product->unitPriceFor($this->resource, $currency);

                return ['currency' => $currency, 'unit_price' => $amount === null ? null : $amount / 100,
                    'source' => $this->priceFor($currency)?->unit_price !== null ? 'explicit' : ($amount !== null ? 'parent_inherited' : 'not_available')];
            }),
            'name' => $this->name,
            'code' => $this->code,
            'barcode' => $this->barcode,
            'active' => (bool) $this->active,
            'order' => $this->order !== null ? (int) $this->order : null,
            'prices' => $this->whenLoaded('productPrices', fn () => $this->productPrices->map(fn ($price) => [
                'currency' => $price->currency,
                'unit_price' => $price->unit_price !== null ? $price->unit_price / 100 : null,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
