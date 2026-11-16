<?php

namespace VentureDrake\LaravelCrm\Http\Resources\Api\V2;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->external_id,
            'product_id' => $this->whenLoaded('product', fn () => $this->product?->external_id),
            'display_name' => $this->displayName(),
            'display_code' => $this->displayCode(),
            'catalog_snapshot' => $this->catalogSnapshot(),
            'catalog_snapshot_source' => $this->catalog_snapshot_source,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->external_id,
                'name' => $this->catalogSnapshot()['product_name'] ?? $this->product->name,
                'code' => $this->catalogSnapshot() ? ($this->catalogSnapshot()['product_code'] ?? null) : $this->product->code,
            ] : null),
            'product_variation_id' => $this->whenLoaded('productVariation', fn () => $this->productVariation?->external_id),
            'variation' => $this->whenLoaded('productVariation', fn () => $this->productVariation ? [
                'id' => $this->productVariation->external_id,
                'name' => $this->catalogSnapshot() ? $this->catalogSnapshot()['variation_name'] : $this->productVariation->name,
                'code' => $this->catalogSnapshot() ? ($this->catalogSnapshot()['variation_code'] ?? null) : $this->productVariation->code,
            ] : null),
            'quantity' => $this->quantity !== null ? (float) $this->quantity : null,
            'unit_price' => $this->price !== null ? $this->price / 100 : null,
            'amount' => $this->amount !== null ? $this->amount / 100 : null,
            'tax_rate' => $this->tax_rate !== null ? (float) $this->tax_rate : null,
            'tax_amount' => $this->tax_amount !== null ? $this->tax_amount / 100 : null,
            'currency' => $this->currency,
            'comments' => $this->comments,
            'order' => $this->order !== null ? (int) $this->order : null,
        ];
    }
}
