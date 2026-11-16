<?php

namespace VentureDrake\LaravelCrm\Livewire\Products;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Services\ProductService;
use VentureDrake\LaravelCrm\Services\ProductVariantMatrixService;

class ProductVariantEditor extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    #[Locked]
    public string $productId;

    public int $version = 0;

    public int $page = 1;

    public string $action = 'create_options';

    public string $strategy = 'LEAVE_AS_IS';

    public array $drafts = [['name' => '', 'values' => '']];

    public string $optionId = '';

    public string $newValues = '';

    public bool $confirmDefaults = false;

    public bool $restoreArchived = false;

    public array $selection = [];

    public array $queuedSelections = [];

    public bool $selectedOnly = false;

    public array $mappings = [];

    public array $rows = [];

    public array $options = [];

    public array $optionEdits = [];

    public array $valueEdits = [];

    public array $preview = [];

    #[Locked]
    public array $previewCommand = [];

    #[Locked]
    public string $previewHash = '';

    #[Locked]
    public int $previewVersion = 0;

    #[Locked]
    public string $operationKey = '';

    public string $message = '';

    public string $legacyName = '';

    public string $currency = 'USD';

    public array $checked = [];

    public string $bulkField = 'published';

    public bool $bulkValue = false;

    public $bulkText;

    public int $total = 0;

    public array $images = [];

    public $uploadedImage;

    public function uploadImage(): void
    {
        $product = $this->product();
        $this->authorize('create', File::class);
        $this->validate(['uploadedImage' => 'required|image|mimes:jpg,jpeg,png,webp,gif,avif|max:5120']);
        $path = $this->uploadedImage->store('laravel-crm/products/'.$product->id.'/files', 'local');
        try {
            $product->files()->create([
                'file' => $path, 'disk' => 'local', 'name' => $this->uploadedImage->getClientOriginalName(),
                'mime' => $this->uploadedImage->getMimeType(), 'filesize' => $this->uploadedImage->getSize(),
                'team_id' => $product->team_id,
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        $this->uploadedImage = null;
        $this->reload();
    }

    public function mount(Product $product): void
    {
        $this->productId = $product->id;
        $this->currency = $product->getPrimaryPrice()?->currency ?: (app('laravel-crm.settings')->get('currency') ?? 'USD');
        $this->reload();
    }

    private function product(): Product
    {
        $product = Product::findOrFail($this->productId);
        $this->authorize('update', $product);

        return $product;
    }

    public function reload(): void
    {
        $product = $this->product();
        $this->images = $product->files()->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'])
            ->get()->map(fn ($f) => ['id' => $f->external_id, 'name' => $f->name ?: 'Imagen del catálogo'])->all();
        $this->version = $product->options_version;
        $this->options = app(ProductVariantMatrixService::class)->options($product);
        $this->optionEdits = [];
        $this->valueEdits = [];
        foreach ($this->options as $o) {
            $this->optionEdits[$o['id']] = $o['name'];
            foreach ($o['values'] as $v) {
                $this->valueEdits[$v['id']] = ['value' => $v['value'], 'swatch_hex' => $v['swatch_hex']];
            }
        }
        $pager = $product->productVariations()->withTrashed()->with(['productPrices', 'imageFile', 'selections.option', 'selections.value'])->paginate(25, ['*'], 'page', $this->page);
        $this->total = $pager->total();
        $this->rows = [];
        foreach ($pager as $v) {
            $explicit = $v->priceFor($this->currency)?->unit_price;
            $this->rows[$v->external_id] = [
                'name' => $v->name, 'code' => $v->code, 'barcode' => $v->barcode,
                'unit_price' => $explicit === null ? null : $explicit / 100,
                'effective_price' => $product->unitPriceFor($v, $this->currency),
                'active' => (bool) $v->active, 'published' => (bool) $v->published,
                'image_file_id' => $v->imageFile?->external_id,
                'archived' => $v->trashed(), 'legacy' => $v->configuration_status !== 'configured',
            ];
        }
    }

    public function changePage(int $delta): void
    {
        $this->page = max(1, min((int) max(1, ceil($this->total / 25)), $this->page + $delta));
        $this->checked = [];
        $this->reload();
    }

    public function updatedCurrency(): void
    {
        $this->validate(['currency' => 'required|string|size:3']);
        $this->reload();
    }

    public function removeDraft(int $index): void
    {
        unset($this->drafts[$index]);
        $this->drafts = array_values($this->drafts);
    }

    public function updatedAction(): void
    {
        $this->strategy = 'LEAVE_AS_IS';
        $this->preview = [];
        $this->previewHash = '';
    }

    public function addDraft(): void
    {
        if (count($this->drafts) + count($this->options) < 3) {
            $this->drafts[] = ['name' => '', 'values' => ''];
        }
    }

    private function command(): array
    {
        $command = ['action' => $this->action, 'strategy' => $this->strategy,
            'confirm_defaults' => $this->confirmDefaults, 'restore_archived' => $this->restoreArchived];
        if ($this->action === 'create_options') {
            $command['options'] = array_map(fn ($d) => ['name' => $d['name'], 'values' => $this->labels($d['values'])], $this->drafts);
        } elseif ($this->action === 'add_values') {
            $command['option_id'] = $this->optionId;
            $command['values'] = $this->labels($this->newValues);
        } elseif ($this->action === 'generate' && $this->selectedOnly) {
            $command['selections'] = $this->queuedSelections ?: [array_values($this->selection)];
        } elseif ($this->action === 'map_legacy') {
            $command['mappings'] = [];
            foreach ($this->mappings as $variantId => $values) {
                if (count(array_filter($values)) === count($this->options)) {
                    $command['mappings'][] = ['variant_id' => $variantId, 'values' => array_values($values)];
                }
            }
        }

        return $command;
    }

    private function labels(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $text)), fn ($v) => $v !== ''));
    }

    public function queueSelection(): void
    {
        $this->product();
        if (count($this->selection) !== count($this->options) || count(array_filter($this->selection)) !== count($this->options)) {
            $this->addError('catalog', 'Selecciona un valor de cada opción.');

            return;
        }
        if (count($this->queuedSelections) < 100) {
            $selection = array_values($this->selection);
            if (! in_array($selection, $this->queuedSelections, true)) {
                $this->queuedSelections[] = $selection;
            }
        }
    }

    public function removeSelection(int $index): void
    {
        unset($this->queuedSelections[$index]);
        $this->queuedSelections = array_values($this->queuedSelections);
    }

    public function changePreviewPage(int $delta): void
    {
        $this->attempt(function () use ($delta) {
            abort_unless($this->previewHash !== '', 409, 'PREVIEW_REQUIRED');
            $page = max(1, min(max(1, (int) ceil($this->preview['pagination']['total'] / 100)), $this->preview['pagination']['page'] + $delta));
            $preview = app(ProductVariantMatrixService::class)->preview($this->product(), $this->previewCommand, $page);
            abort_unless(hash_equals($this->previewHash, $preview['plan_hash']), 409, 'STALE_PLAN');
            $this->preview = $preview;
        });
    }

    public function previewChanges(): void
    {
        $this->attempt(function () {
            $this->previewCommand = $this->command();
            $this->preview = app(ProductVariantMatrixService::class)->preview($this->product(), $this->previewCommand);
            $this->previewHash = $this->preview['plan_hash'];
            $this->previewVersion = $this->preview['options_version'];
            $this->operationKey = (string) Str::uuid();
        });
    }

    public function applyChanges(): void
    {
        $this->attempt(function () {
            abort_unless($this->previewHash !== '', 409, 'PREVIEW_REQUIRED');
            abort_unless($this->previewCommand === $this->command(), 409, 'PREVIEW_CHANGED');
            app(ProductVariantMatrixService::class)->apply($this->product(), $this->previewCommand, $this->previewVersion, $this->previewHash, $this->operationKey);
            $this->preview = [];
            $this->previewHash = '';
            $this->mappings = [];
            $this->reload();
            $this->message = 'Cambios aplicados. Identidades y referencias conservadas.';
        });
    }

    public function createLegacyVariation(): void
    {
        $this->attempt(function () {
            $this->validate(['legacyName' => 'required|string|max:255']);
            app(ProductService::class)->createVariation($this->product(), ['name' => $this->legacyName, 'options_version' => $this->version]);
            $this->legacyName = '';
            $this->reload();
        });
    }

    public function saveVariant(string $id): void
    {
        $this->attempt(function () use ($id) {
            $this->validate(['rows.*.name' => 'required|string|max:255', 'rows.*.code' => 'nullable|string|max:255', 'rows.*.barcode' => 'nullable|string|max:255',
                'rows.*.unit_price' => 'nullable|numeric|min:0', 'rows.*.active' => 'boolean',
                'rows.*.published' => 'boolean', 'rows.*.image_file_id' => 'nullable|uuid']);
            $product = $this->product();
            $v = $product->productVariations()->where('external_id', $id)->firstOrFail();
            $patch = array_intersect_key($this->rows[$id], array_flip(['name', 'code', 'barcode', 'unit_price', 'active', 'published', 'image_file_id']));
            $patch['options_version'] = $this->version;
            app(ProductVariantMatrixService::class)->updateVariantDetails($v, $patch, $this->currency);
            $this->reload();
            $this->message = 'Variante guardada.';
        });
    }

    public function bulkSave(): void
    {
        $this->attempt(function () {
            $this->validate(['bulkField' => 'required|in:active,published,code,barcode,unit_price', 'checked' => 'required|array|min:1|max:100', 'checked.*' => 'uuid', 'bulkValue' => 'boolean']);
            $value = in_array($this->bulkField, ['active', 'published'], true) ? $this->bulkValue : $this->bulkText;
            validator(['value' => $value], ['value' => $this->bulkField === 'unit_price' ? 'nullable|numeric|min:0' : (in_array($this->bulkField, ['active', 'published'], true) ? 'boolean' : 'nullable|string|max:255')])->validate();
            app(ProductVariantMatrixService::class)->bulkUpdate($this->product(), $this->checked, [$this->bulkField => $value], $this->version, $this->currency);
            $this->checked = [];
            $this->reload();
        });
    }

    public function archive(string $id): void
    {
        $this->attempt(function () use ($id) {
            $product = $this->product();
            abort_unless($product->options_version === $this->version, 409, 'STALE_OPTIONS_VERSION');
            app(ProductVariantMatrixService::class)->archiveVariant($product->productVariations()->where('external_id', $id)->firstOrFail(), $this->version);
            $this->reload();
        });
    }

    public function restore(string $id): void
    {
        $this->attempt(function () use ($id) {
            app(ProductVariantMatrixService::class)->restoreVariant($this->product()->productVariations()->withTrashed()->where('external_id', $id)->firstOrFail(), $this->version);
            $this->reload();
        });
    }

    public function saveOption(string $optionId, ?string $valueId = null): void
    {
        $this->attempt(function () use ($optionId, $valueId) {
            $patch = $valueId ? $this->valueEdits[$valueId] : ['name' => $this->optionEdits[$optionId]];
            validator($patch, ['name' => 'sometimes|required|string|max:255', 'value' => 'sometimes|required|string|max:255',
                'swatch_hex' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']])->validate();
            $patch['options_version'] = $this->version;
            app(ProductVariantMatrixService::class)->patchOption($this->product(), $optionId, $patch, $valueId);
            $this->reload();
        });
    }

    public function moveOption(string $optionId, int $position, ?string $valueId = null): void
    {
        $this->attempt(function () use ($optionId, $position, $valueId) {
            app(ProductVariantMatrixService::class)->patchOption($this->product(), $optionId, ['position' => max(1, $position), 'options_version' => $this->version], $valueId);
            $this->reload();
        });
    }

    public function toggleValue(string $optionId, string $valueId, bool $active): void
    {
        $this->attempt(function () use ($optionId, $valueId, $active) {
            app(ProductVariantMatrixService::class)->patchOption($this->product(), $optionId, ['active' => $active, 'options_version' => $this->version], $valueId);
            $this->reload();
        });
    }

    private function attempt(callable $callback): void
    {
        $this->resetErrorBag();
        $this->message = '';
        try {
            $callback();
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }
            $this->previewHash = '';
            $this->addError('catalog', 'Otra sesión modificó el catálogo o cambió el plan. Recarga y previsualiza de nuevo.');
        }
    }

    public function render()
    {
        $this->product();

        return view('laravel-crm::livewire.products.product-variant-editor');
    }
}
