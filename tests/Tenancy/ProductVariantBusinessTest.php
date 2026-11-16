<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\BusinessTestCase;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmBootstrapSeeder;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Services\BusinessRegistrationService;
use VentureDrake\LaravelCrm\Services\ProductVariantMatrixService;
use VentureDrake\LaravelCrm\Support\BusinessContext;
use VentureDrake\LaravelCrm\Traits\ResolvesProductVariation;

uses(BusinessTestCase::class);

test('matrix commands routes media and sales reject other businesses and products without leaking IDs', function () {
    $this->seed(LaravelCrmBootstrapSeeder::class);
    $register = fn ($name) => app(BusinessRegistrationService::class)->register([
        'name' => $name, 'email' => strtolower($name).'@matrix.test', 'password' => 'safe-test-password', 'business_name' => $name,
    ]);
    $a = $register('MatrixAlpha');
    $b = $register('MatrixBeta');
    $context = app(BusinessContext::class);
    $matrix = app(ProductVariantMatrixService::class);
    $make = function ($name) use ($matrix) {
        $p = Product::create(['name' => $name]);
        $command = ['action' => 'create_options', 'strategy' => 'CREATE', 'options' => [['name' => 'Color', 'values' => ['Negro']]]];
        $preview = $matrix->preview($p, $command);
        $matrix->apply($p, $command, 0, $preview['plan_hash'], 'setup');

        return $p;
    };
    $pa = $context->run($a->team_id, fn () => $make('A'));
    $pb = $context->run($b->team_id, fn () => $make('B'));
    $foreignValue = $context->run($b->team_id, fn () => $pb->options()->first()->values()->first());
    $foreignVariant = $context->run($b->team_id, fn () => $pb->productVariations()->first());
    $foreignImage = $context->run($b->team_id, fn () => $pb->files()->create(['file' => 'private.png', 'mime' => 'image/png']));
    $context->run($a->team_id, function () use ($pa, $pb, $matrix, $foreignValue, $foreignVariant, $foreignImage) {
        expect(fn () => $matrix->preview($pb, ['action' => 'generate']))->toThrow(ModelNotFoundException::class);
        expect(fn () => $matrix->preview($pa, ['action' => 'generate', 'selections' => [[$foreignValue->external_id]]]))
            ->toThrow(ValidationException::class, 'VALUE_NOT_IN_OPTION');
        expect(fn () => $matrix->updateVariantDetails($pa->productVariations()->first(), ['image_file_id' => $foreignImage->external_id]))
            ->toThrow(ValidationException::class, 'INVALID_VARIANT_IMAGE');
        $helper = new class
        {
            use ResolvesProductVariation { variationIdFor as public; }
        };
        expect(fn () => $helper->variationIdFor($pa->id, $foreignVariant->id))->toThrow(ValidationException::class);
        expect(fn () => $helper->variationIdFor($pa->id, null))->toThrow(ValidationException::class, 'STRUCTURED_SELECTION_REQUIRED');
        // Composite database FKs protect raw writes independently of tenant scopes.
        $selection = $pa->productVariations()->first()->selections()->first();
        expect(fn () => DB::transaction(fn () => DB::table('crm_product_variation_option_values')->where('id', $selection->id)->update([
            'product_option_value_id' => $foreignValue->id,
        ])))->toThrow(QueryException::class);
        if (DB::getDriverName() === 'pgsql') {
            expect(fn () => DB::transaction(fn () => DB::table('crm_product_options')->insert([
                'id' => (string) Str::uuid(), 'external_id' => (string) Str::uuid(), 'team_id' => null,
                'product_id' => $pa->id, 'name' => 'Null owner bypass', 'normalized_name' => 'null owner bypass',
            ])))->toThrow(QueryException::class, 'MATRIX_BUSINESS_MISMATCH');
        }
    });
    Sanctum::actingAs($a);
    $this->getJson('/crm/api/v2/products/'.$pb->external_id.'/options')->assertNotFound();
    $this->postJson('/crm/api/v2/products/'.$pa->external_id.'/options/preview', [
        'command' => ['action' => 'generate', 'selections' => [[$foreignValue->external_id]]],
    ])->assertUnprocessable()->assertJsonPath('errors.catalog.0', 'VALUE_NOT_IN_OPTION');
    $this->patchJson('/crm/api/v2/products/'.$pa->external_id.'/variations/'.$foreignVariant->external_id, ['code' => 'FORGED'])->assertNotFound();
});
