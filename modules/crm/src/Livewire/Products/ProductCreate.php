<?php

namespace VentureDrake\LaravelCrm\Livewire\Products;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Modelable;
use Livewire\Component;
use VentureDrake\LaravelCrm\Livewire\Products\Traits\HasProductCommon;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrm\Services\ProductVariantMatrixService;

class ProductCreate extends Component
{
    use AuthorizesRequests, HasProductCommon;

    public $layout = 'full';

    #[Modelable]
    public bool $showCreateProduct = false;

    public function mount()
    {
        $this->mountCommon();

        if ($taxRate = TaxRate::where('default', 1)->first()) {
            $this->tax_rate_id = $taxRate->id;
            $this->tax_rate = $taxRate->rate;
        }

        $this->currency = app('laravel-crm.settings')->get('currency', 'USD');
        $this->user_owner_id = auth()->user()->id;
    }

    public function createProduct()
    {
        $this->save(false);

        $this->dispatch('product-created');

        $this->showCreateProduct = false;
    }

    public function save($redirect = true)
    {
        $this->authorize('create', Product::class);

        $this->validate();

        // Create a request object to pass to services
        $request = \VentureDrake\LaravelCrm\Http\Helpers\PublicProperties\asRequest($this);

        $product = DB::transaction(function () use ($request) {
            $matrix = app(ProductVariantMatrixService::class);
            if ($this->withOptions) {
                $preview = $matrix->previewNew($this->creationCommand());
                if (! hash_equals($preview['plan_hash'], $this->creationPreviewHash)) {
                    throw ValidationException::withMessages(['catalog' => 'Previsualiza las variantes antes de guardar.']);
                }
                $request->variations = [];
            }
            $product = $this->productService->create($request);
            if ($this->withOptions) {
                $plan = $matrix->preview($product, $this->creationCommand());
                $matrix->apply($product, $this->creationCommand(), $plan['options_version'], $plan['plan_hash'], (string) Str::uuid());
            }

            return $product;
        });

        $this->saveCustomFields($product);

        if ($redirect) {
            $this->success(
                ucfirst(trans('laravel-crm::lang.product_created')),
                redirectTo: route('laravel-crm.products.index')
            );
        } else {
            $this->success(
                ucfirst(trans('laravel-crm::lang.product_created'))
            );
        }
    }

    public function render()
    {
        return view('laravel-crm::livewire.products.product-create');
    }
}
