<?php

namespace VentureDrake\LaravelCrm\Http\Controllers\Api\V2;

use Illuminate\Http\Request;
use VentureDrake\LaravelCrm\Http\Resources\Api\V2\ProductVariationResource;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Services\ProductVariantMatrixService;

class ProductOptionController extends ApiController
{
    public function __construct(private ProductVariantMatrixService $matrix) {}

    public function index(Product $product)
    {
        $this->authorize('view', $product);

        return ['data' => $this->matrix->options($product), 'options_version' => $product->options_version];
    }

    public function preview(Request $request, Product $product)
    {
        $this->authorize('update', $product);
        $data = $request->validate(['command' => 'required|array', 'page' => 'sometimes|integer|min:1']);

        return $this->matrix->preview($product, $data['command'], $data['page'] ?? 1);
    }

    public function legacyPreview(Request $request, Product $product)
    {
        $this->authorize('update', $product);
        if ($request->has('command')) {
            return $this->preview($request, $product);
        }

        return $this->matrix->previewLegacyMapping($product, max(1, (int) $request->input('page', 1)));
    }

    public function apply(Request $request, Product $product)
    {
        $this->authorize('update', $product);
        $data = $request->validate([
            'command' => 'required|array', 'options_version' => 'required|integer|min:0',
            'plan_hash' => 'required|string|size:64', 'operation_key' => 'required|string|max:100',
        ]);

        return $this->matrix->apply($product, $data['command'], $data['options_version'], $data['plan_hash'], $data['operation_key']);
    }

    public function update(Request $request, Product $product, string $option, ?string $value = null)
    {
        $this->authorize('update', $product);
        $data = $request->validate([
            'options_version' => 'required|integer|min:0', 'name' => 'sometimes|required|string|max:255',
            'value' => 'sometimes|required|string|max:255', 'position' => 'sometimes|integer|min:1',
            'active' => 'sometimes|boolean', 'swatch_hex' => $value === null ? ['prohibited'] : ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $this->matrix->patchOption($product, $option, $data, $value);

        return $this->index($product->refresh());
    }

    public function archive(Request $request, Product $product, string $variation)
    {
        $this->authorize('update', $product);
        $variant = $product->productVariations()->withTrashed()->where('external_id', $variation)->firstOrFail();
        $data = $request->validate(['options_version' => 'sometimes|integer|min:0']);
        $this->matrix->archiveVariant($variant, $data['options_version'] ?? null);

        return response()->noContent();
    }

    public function restore(Request $request, Product $product, string $variation)
    {
        $this->authorize('update', $product);
        $variant = $product->productVariations()->withTrashed()->where('external_id', $variation)->firstOrFail();

        $data = $request->validate(['options_version' => 'sometimes|integer|min:0']);

        return new ProductVariationResource($this->matrix->restoreVariant($variant, $data['options_version'] ?? null)->load(['productPrices', 'selections.option', 'selections.value', 'product.productPrices', 'imageFile']));
    }

    public function bulk(Request $request, Product $product)
    {
        $this->authorize('update', $product);
        $data = $request->validate([
            'options_version' => 'required|integer|min:0', 'variant_ids' => 'required|array|min:1|max:100',
            'currency' => 'sometimes|nullable|string|size:3',
            'variant_ids.*' => 'required|uuid', 'patch' => 'required|array:code,barcode,active,published,unit_price,image_file_id',
            'patch.code' => 'sometimes|nullable|string|max:255', 'patch.barcode' => 'sometimes|nullable|string|max:255',
            'patch.active' => 'sometimes|boolean', 'patch.published' => 'sometimes|boolean',
            'patch.unit_price' => 'sometimes|nullable|numeric|min:0', 'patch.image_file_id' => 'sometimes|nullable|uuid',
        ]);
        $this->matrix->bulkUpdate($product, $data['variant_ids'], $data['patch'], $data['options_version'], $data['currency'] ?? null);

        return ['options_version' => $product->fresh()->options_version];
    }

    /** Resolve only persisted combinations. Availability remains unknown without an inventory provider. */
    public function resolve(Request $request, Product $product)
    {
        $this->authorize('view', $product);
        $data = $request->validate(['value_ids' => 'required|array|min:1|max:3', 'value_ids.*' => 'required|uuid']);
        $query = $product->productVariations()->where('configuration_status', 'configured');
        foreach ($data['value_ids'] as $id) {
            $query->whereHas('selections.value', fn ($q) => $q->where('external_id', $id));
        }
        $query->has('selections', '=', count($data['value_ids']));
        $variant = $query->with(['productPrices', 'selections.option', 'selections.value', 'product.productPrices', 'imageFile'])->first();

        return ['exists' => $variant !== null, 'availability' => 'unknown', 'variant' => $variant ? new ProductVariationResource($variant) : null];
    }
}
