<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmPermissionsSeeder;
use VentureDrake\LaravelCrm\Http\Resources\Api\V2\OrderProductResource;
use VentureDrake\LaravelCrm\Livewire\Products\ProductCreate;
use VentureDrake\LaravelCrm\Livewire\Products\ProductEdit;
use VentureDrake\LaravelCrm\Livewire\Products\ProductVariantEditor;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Services\CatalogAvailabilityService;
use VentureDrake\LaravelCrm\Services\ProductService;
use VentureDrake\LaravelCrm\Services\ProductVariantMatrixService;

function matrixCommand(string $strategy = 'CREATE'): array
{
    return ['action' => 'create_options', 'strategy' => $strategy, 'options' => [
        ['name' => 'Color', 'values' => ['Negro', 'Blanco']], ['name' => 'Talla', 'values' => ['S', 'M']],
    ]];
}

function matrixApply(Product $product, array $command, ?string $key = null): array
{
    $service = app(ProductVariantMatrixService::class);
    $preview = $service->preview($product, $command);

    return $service->apply($product, $command, $preview['options_version'], $preview['plan_hash'], $key ?? (string) Str::uuid());
}

function matrixLogin(): User
{
    config(['laravel-crm.teams' => false, 'permission.teams' => false]);
    test()->seed(LaravelCrmPermissionsSeeder::class);
    $user = User::factory()->create(['crm_access' => true]);
    $user->assignRole('Owner');
    test()->actingAs($user);
    Sanctum::actingAs($user);

    return $user;
}

test('simple products do not acquire fictional variants and four combinations preserve prices and idempotency', function () {
    $product = Product::create(['name' => 'Shirt']);
    expect($product->productVariations()->count())->toBe(0);
    $product->productPrices()->create(['currency' => 'PEN', 'unit_price' => 12.50]);
    $service = app(ProductVariantMatrixService::class);
    $command = matrixCommand();
    $preview = $service->preview($product, $command);
    expect($preview['counts']['created'])->toBe(4)->and($product->options()->count())->toBe(0);
    $result = $service->apply($product, $command, 0, $preview['plan_hash'], 'same-request');
    $again = $service->apply($product, $command, 0, $preview['plan_hash'], 'same-request');
    expect($again)->toBe($result)->and($product->productVariations()->count())->toBe(4)
        ->and($product->productVariations()->distinct()->count('combination_key'))->toBe(4);
    $v = $product->productVariations()->first();
    expect($v->published)->toBeFalse()->and($product->unitPriceFor($v, 'PEN'))->toBe(1250)
        ->and($product->unitPriceFor($v, 'USD'))->toBeNull()->and($v->productPrices()->count())->toBe(0);
    app(ProductService::class)->updateVariation($v, ['unit_price' => 15.25], 'PEN');
    expect($v->priceFor('PEN')->unit_price)->toBe(1525);
    expect(fn () => $service->apply($product, ['action' => 'generate'], 0, $preview['plan_hash'], 'same-request'))
        ->toThrow(HttpException::class, 'IDEMPOTENCY_CONFLICT');
});

test('selected combinations create only requested identities', function () {
    $p = Product::create(['name' => 'Selected']);
    matrixApply($p, matrixCommand('LEAVE_AS_IS'));
    $options = app(ProductVariantMatrixService::class)->options($p);
    matrixApply($p, ['action' => 'generate', 'selections' => [
        [$options[0]['values'][0]['id'], $options[1]['values'][0]['id']],
        [$options[0]['values'][1]['id'], $options[1]['values'][1]['id']],
    ]]);
    expect($p->productVariations()->count())->toBe(2);
    matrixApply($p, ['action' => 'generate']);
    expect($p->productVariations()->count())->toBe(4);
});

test('adding options requires consent and preserves historical identities for both strategies', function (string $strategy, int $count) {
    $p = Product::create(['name' => 'Shirt']);
    matrixApply($p, matrixCommand());
    $ids = $p->productVariations()->pluck('external_id', 'id')->all();
    $v = $p->productVariations()->first();
    $order = Order::create([]);
    $line = $order->orderProducts()->create(['product_id' => $p->id, 'product_variation_id' => $v->id]);
    $command = ['action' => 'create_options', 'strategy' => $strategy,
        'options' => [['name' => 'Material', 'values' => ['Algodón', 'Poliéster']]]];
    $preview = app(ProductVariantMatrixService::class)->preview($p, $command);
    expect($preview['conflicts'])->toContain('DEFAULT_ASSIGNMENT_CONFIRMATION_REQUIRED');
    expect(fn () => matrixApply($p, $command))->toThrow(ValidationException::class);
    $command['confirm_defaults'] = true;
    matrixApply($p, $command);
    expect($p->productVariations()->count())->toBe($count)
        ->and($p->productVariations()->whereIn('id', array_keys($ids))->pluck('external_id', 'id')->all())->toBe($ids)
        ->and($v->fresh()->selections()->count())->toBe(3)
        ->and($line->fresh()->product_variation_id)->toBe($v->id);
})->with([['LEAVE_AS_IS', 4], ['CREATE', 8]]);

test('adding values never removes rows and rename and reorder preserve combination identity and snapshots', function () {
    $p = Product::create(['name' => 'Shirt']);
    matrixApply($p, matrixCommand());
    $v = $p->productVariations()->first();
    $id = $v->id;
    $key = $v->combination_key;
    $line = Order::create([])->orderProducts()->create(['product_id' => $p->id, 'product_variation_id' => $id]);
    $before = $line->displayName();
    $color = $p->options()->first();
    $value = $color->values()->first();
    $service = app(ProductVariantMatrixService::class);
    $service->patchOption($p, $color->external_id, ['value' => 'Grafito', 'options_version' => $p->fresh()->options_version], $value->external_id);
    $service->patchOption($p, $color->external_id, ['position' => 2, 'options_version' => $p->fresh()->options_version]);
    expect($v->fresh()->id)->toBe($id)->and($v->fresh()->combination_key)->toBe($key)
        ->and($line->fresh()->displayName())->toBe($before)->and($line->fresh()->catalog_snapshot_source)->toBe('captured_at_creation');
    matrixApply($p, ['action' => 'add_values', 'option_id' => $color->external_id, 'values' => ['Rojo'], 'strategy' => 'MANAGE_NON_DESTRUCTIVE']);
    expect($p->productVariations()->count())->toBe(6);
    matrixApply($p, ['action' => 'add_values', 'option_id' => $color->external_id, 'values' => ['Azul'], 'strategy' => 'LEAVE_AS_IS']);
    expect($p->productVariations()->count())->toBe(6);
});

test('legacy mapping never parses text and keeps IDs prices and referenced documents', function () {
    $p = Product::create(['name' => 'Legacy']);
    $v = app(ProductService::class)->createVariation($p, ['name' => 'Negro M', 'code' => 'SKU', 'unit_price' => 19.99], 'PEN');
    $other = app(ProductService::class)->createVariation($p, ['name' => 'Another']);
    $line = Order::create([])->orderProducts()->create(['product_id' => $p->id, 'product_variation_id' => $v->id]);
    // Emulate a pre-feature document whose original wording cannot be recovered.
    DB::table('crm_order_products')->where('id', $line->id)->update(['catalog_snapshot' => null, 'catalog_snapshot_source' => null]);
    matrixApply($p, matrixCommand('LEAVE_AS_IS'));
    expect($v->fresh()->configuration_status)->toBe('legacy_unmapped');
    expect(fn () => matrixApply($p, ['action' => 'generate']))->toThrow(ValidationException::class, 'LEGACY_MAPPING_REQUIRED');
    $o = app(ProductVariantMatrixService::class)->options($p);
    $selection = [$o[0]['values'][0]['id'], $o[1]['values'][1]['id']];
    expect(fn () => matrixApply($p, ['action' => 'map_legacy', 'mappings' => [
        ['variant_id' => $v->external_id, 'values' => $selection], ['variant_id' => $other->external_id, 'values' => $selection],
    ]]))->toThrow(ValidationException::class, 'DUPLICATE_VARIANT_COMBINATION');
    matrixApply($p, ['action' => 'map_legacy', 'mappings' => [['variant_id' => $v->external_id, 'values' => $selection]]]);
    expect($v->fresh()->configuration_status)->toBe('configured')->and($v->fresh()->code)->toBe('SKU')
        ->and($v->fresh()->priceFor('PEN')->unit_price)->toBe(1999)
        ->and($line->fresh()->product_variation_id)->toBe($v->id)
        ->and($line->fresh()->catalog_snapshot_source)->toBe('legacy_best_effort');
});

test('archiving and explicit restoration preserve combination identity and references', function () {
    $p = Product::create(['name' => 'Shirt']);
    matrixApply($p, matrixCommand());
    $v = $p->productVariations()->first();
    $line = Order::create([])->orderProducts()->create(['product_id' => $p->id, 'product_variation_id' => $v->id]);
    app(ProductVariantMatrixService::class)->archiveVariant($v);
    matrixApply($p, ['action' => 'generate']);
    expect($p->productVariations()->count())->toBe(3)->and($p->productVariations()->withTrashed()->count())->toBe(4);
    matrixApply($p, ['action' => 'generate', 'restore_archived' => true]);
    expect($p->productVariations()->count())->toBe(4)->and($v->fresh()->published)->toBeTrue()
        ->and($line->fresh()->product_variation_id)->toBe($v->id);
    expect(fn () => $v->fresh()->forceDelete())->toThrow(ValidationException::class);
});

test('stale plans do not mutate and invalid selections are rejected', function () {
    $p = Product::create(['name' => 'Shirt']);
    matrixApply($p, matrixCommand());
    $s = app(ProductVariantMatrixService::class);
    $plan = $s->preview($p, ['action' => 'generate']);
    $v = $p->productVariations()->first();
    $s->updateVariantDetails($v, ['code' => 'CHANGED']);
    expect(fn () => $s->apply($p, ['action' => 'generate'], $plan['options_version'], $plan['plan_hash'], 'stale'))
        ->toThrow(HttpException::class, 'STALE_OPTIONS_VERSION');
    expect(fn () => matrixApply($p, ['action' => 'generate', 'selections' => [[(string) Str::uuid()]]]))
        ->toThrow(ValidationException::class, 'VALUE_NOT_IN_OPTION');
    expect(fn () => app(ProductService::class)->createVariation($p, ['name' => 'bypass']))
        ->toThrow(ValidationException::class, 'STRUCTURED_SELECTION_REQUIRED');
    expect($p->productVariations()->count())->toBe(4);
});

test('option and variant limits and Unicode normalized duplicates are rejected', function () {
    $p = Product::create(['name' => 'Limits']);
    matrixApply($p, matrixCommand());
    expect(fn () => matrixApply($p, ['action' => 'create_options', 'options' => [
        ['name' => 'Material', 'values' => ['A']], ['name' => 'Size', 'values' => ['B']],
    ]]))->toThrow(ValidationException::class, 'OPTION_LIMIT_EXCEEDED');
    $p2 = Product::create(['name' => 'Too many']);
    expect(fn () => matrixApply($p2, ['action' => 'create_options', 'strategy' => 'CREATE', 'options' => [
        ['name' => 'A', 'values' => array_map('strval', range(1, 46))], ['name' => 'B', 'values' => array_map('strval', range(1, 46))],
    ]]))->toThrow(ValidationException::class, 'VARIANT_LIMIT_EXCEEDED');
    expect($p2->options()->count())->toBe(0);
    expect(fn () => matrixApply($p2, ['action' => 'create_options', 'options' => [
        ['name' => ' Color ', 'values' => ['Café', "CAFE\u{0301}"]],
    ]]))->toThrow(ValidationException::class, 'DUPLICATE_OPTION_VALUE');
    expect(fn () => matrixApply($p2, ['action' => 'create_options', 'options' => [
        ['name' => ' Color ', 'values' => ['A']], ['name' => 'COLOR', 'values' => ['B']],
    ]]))->toThrow(ValidationException::class, 'DUPLICATE_OPTION_NAME');
});

test('partial sync preserves omitted identities and rejects invalid IDs atomically', function () {
    $p = Product::create(['name' => 'Legacy']);
    $s = app(ProductService::class);
    $v = $s->createVariation($p, ['name' => 'One']);
    $other = $s->createVariation($p, ['name' => 'Two']);
    $s->syncVariations($p, [['id' => $v->id, 'code' => 'NEW']]);
    expect($p->productVariations()->count())->toBe(2)->and($other->fresh()->deleted_at)->toBeNull()
        ->and($v->fresh()->name)->toBe('One');
    expect(fn () => $s->syncVariations($p, [['id' => $v->id, 'code' => 'ROLLBACK'], ['id' => (string) Str::uuid()]]))
        ->toThrow(ValidationException::class);
    expect($v->fresh()->code)->toBe('NEW');
});

test('V2 preview apply resolution pagination and old PATCH endpoints share the engine', function () {
    matrixLogin();
    $p = Product::create(['name' => 'API']);
    $url = '/crm/api/v2/products/'.$p->external_id;
    $preview = $this->postJson($url.'/options/preview', ['command' => matrixCommand()])->assertOk()->json();
    $this->postJson($url.'/options/apply', ['command' => matrixCommand(), 'options_version' => 0,
        'plan_hash' => $preview['plan_hash'], 'operation_key' => 'api'])->assertOk();
    $options = $this->getJson($url.'/options')->assertOk()->json('data');
    expect($options[0]['id'])->not->toBe($p->options()->first()->id);
    $v = $p->productVariations()->first();
    $this->patchJson($url.'/variations/'.$v->external_id, ['code' => 'API-SKU'])->assertOk()->assertJsonPath('data.code', 'API-SKU');
    $this->patchJson($url, ['name' => 'Updated'])->assertOk();
    expect($p->productVariations()->count())->toBe(4);
    $this->getJson($url.'/variations?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4);
    $this->postJson($url.'/variations/resolve', ['value_ids' => [$options[0]['values'][0]['id'], $options[1]['values'][0]['id']]])
        ->assertOk()->assertJsonPath('exists', true)->assertJsonPath('availability', 'unknown');
    $this->postJson($url.'/variations/resolve', ['value_ids' => [(string) Str::uuid()]])->assertOk()->assertJsonPath('exists', false);
    $this->postJson($url.'/variations/'.$v->external_id.'/archive')->assertNoContent();
    $this->postJson($url.'/variations/'.$v->external_id.'/restore')->assertOk()->assertJsonPath('data.id', $v->external_id);
});

test('Livewire editor previews applies saves and archives and parent save preserves variants', function () {
    matrixLogin();
    $p = Product::create(['name' => 'Livewire']);
    $editor = Livewire::test(ProductVariantEditor::class, ['product' => $p])
        ->set('drafts', [['name' => 'Color', 'values' => 'Negro, Blanco'], ['name' => 'Talla', 'values' => 'S, M']])
        ->set('strategy', 'CREATE')->call('previewChanges')->assertHasNoErrors()->assertSet('preview.counts.created', 4)
        ->call('applyChanges')->assertHasNoErrors();
    $v = $p->productVariations()->first();
    $editor->set('rows.'.$v->external_id.'.code', 'LW')->call('saveVariant', $v->external_id)->assertHasNoErrors();
    expect($v->fresh()->code)->toBe('LW');
    Livewire::test(ProductEdit::class, ['product' => $p])->set('name', 'Edited')->call('save')->assertHasNoErrors();
    expect($p->productVariations()->count())->toBe(4);
    $editor->call('archive', $v->external_id)->assertHasErrors('catalog');
    $editor->call('reload')->call('archive', $v->external_id)->assertHasNoErrors();
    expect($v->fresh()->trashed())->toBeTrue();
});

test('Livewire creation generates a matrix only after preview and leaves new variants unpublished', function () {
    matrixLogin();
    Livewire::test(ProductCreate::class)->set('name', 'Created with options')->set('withOptions', true)
        ->set('optionDrafts', [['name' => 'Color', 'values' => 'Negro, Blanco']])
        ->call('previewNewVariants')->assertHasNoErrors()->assertSet('creationPreview.counts.created', 2)
        ->call('save')->assertHasNoErrors();
    $p = Product::where('name', 'Created with options')->firstOrFail();
    expect($p->productVariations()->count())->toBe(2)->and($p->productVariations()->where('published', true)->count())->toBe(0);
});

test('images are restricted to catalog files and value disabling unpublishes without deleting', function () {
    $p = Product::create(['name' => 'Media']);
    matrixApply($p, matrixCommand());
    $v = $p->productVariations()->first();
    $f = $p->files()->create(['file' => 'catalog.png', 'mime' => 'image/png']);
    $s = app(ProductVariantMatrixService::class);
    $s->updateVariantDetails($v, ['image_file_id' => $f->external_id, 'published' => true]);
    expect($v->fresh()->image_file_id)->toBe($f->id)->and($f->fresh()->file)->toBe('catalog.png');
    $otherProduct = Product::create(['name' => 'Other']);
    $other = $otherProduct->files()->create(['file' => 'other.png', 'mime' => 'image/png']);
    expect(fn () => $s->updateVariantDetails($v, ['image_file_id' => $other->external_id]))->toThrow(ValidationException::class, 'INVALID_VARIANT_IMAGE');
    $o = $p->options()->first();
    $value = $o->values()->first();
    $s->patchOption($p, $o->external_id, ['active' => false, 'options_version' => $p->fresh()->options_version], $value->external_id);
    expect($p->productVariations()->count())->toBe(4);
    expect(fn () => $s->patchOption($p, $o->external_id, ['active' => false, 'options_version' => $p->fresh()->options_version]))
        ->toThrow(ValidationException::class, 'CANNOT_REMOVE_USED_OPTION');
});

test('adding a value to a sparse matrix only adds combinations containing that value', function () {
    $p = Product::create(['name' => 'Sparse']);
    matrixApply($p, matrixCommand('LEAVE_AS_IS'));
    $o = app(ProductVariantMatrixService::class)->options($p);
    matrixApply($p, ['action' => 'generate', 'selections' => [[$o[0]['values'][0]['id'], $o[1]['values'][0]['id']]]]);
    matrixApply($p, ['action' => 'add_values', 'option_id' => $o[0]['id'], 'values' => ['Rojo'], 'strategy' => 'MANAGE_NON_DESTRUCTIVE']);
    expect($p->productVariations()->count())->toBe(3);
});

test('bulk edits are explicit atomic and stale versions are rejected', function () {
    $p = Product::create(['name' => 'Batch']);
    matrixApply($p, matrixCommand());
    $s = app(ProductVariantMatrixService::class);
    $ids = $p->productVariations()->pluck('external_id')->all();
    $version = $p->fresh()->options_version;
    expect(fn () => $s->bulkUpdate($p, [$ids[0], (string) Str::uuid()], ['code' => 'WRONG'], $version))
        ->toThrow(ValidationException::class);
    expect($p->productVariations()->whereNotNull('code')->count())->toBe(0);
    $s->bulkUpdate($p, $ids, ['published' => true], $version);
    expect($p->productVariations()->where('published', true)->count())->toBe(4);
    expect(fn () => $s->bulkUpdate($p, $ids, ['published' => false], $version))
        ->toThrow(HttpException::class);
});

test('100 plus variants use bounded eager loaded API and paginated Livewire forms without N plus one', function () {
    matrixLogin();
    $p = Product::create(['name' => 'Large catalogue']);
    matrixApply($p, ['action' => 'create_options', 'strategy' => 'CREATE', 'options' => [
        ['name' => 'Size', 'values' => array_map('strval', range(1, 120))],
    ]]);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $url = '/crm/api/v2/products/'.$p->external_id;
    $this->getJson($url.'/variations?per_page=25')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 120);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries)->toBeLessThan(40);
    $this->getJson($url)->assertOk()->assertJsonCount(100, 'data.variations')->assertJsonPath('data.variations_count', 120)
        ->assertJsonPath('data.variations_truncated', true)->assertJsonCount(1, 'data.variations.0.selected_options');
    Livewire::test(ProductVariantEditor::class, ['product' => $p])->assertCount('rows', 25)
        ->call('changePage', 1)->assertSet('page', 2)->assertCount('rows', 25);
});

test('all commercial line types keep references prices and frozen labels on variant archival and option rename', function () {
    $p = Product::create(['name' => 'Documents']);
    matrixApply($p, matrixCommand());
    $v = $p->productVariations()->first();
    $lines = [
        Deal::create(['title' => 'D'])->dealProducts()->create(['product_id' => $p->id, 'product_variation_id' => $v->id, 'price' => 1.25, 'external_id' => (string) Str::uuid()]),
        Quote::create(['title' => 'Q'])->quoteProducts()->create(['product_id' => $p->id, 'product_variation_id' => $v->id, 'price' => 1.25, 'external_id' => (string) Str::uuid()]),
        Order::create([])->orderProducts()->create(['product_id' => $p->id, 'product_variation_id' => $v->id, 'price' => 1.25, 'external_id' => (string) Str::uuid()]),
        Invoice::create([])->invoiceLines()->create(['product_id' => $p->id, 'product_variation_id' => $v->id, 'price' => 1.25, 'external_id' => (string) Str::uuid()]),
        PurchaseOrder::create([])->purchaseOrderLines()->create(['product_id' => $p->id, 'product_variation_id' => $v->id, 'price' => 1.25, 'external_id' => (string) Str::uuid()]),
    ];
    $name = $lines[0]->displayName();
    $delivery = Delivery::create([])->deliveryProducts()->create(['order_product_id' => $lines[2]->id]);
    $o = $p->options()->first();
    $value = $o->values()->first();
    $s = app(ProductVariantMatrixService::class);
    $s->patchOption($p, $o->external_id, ['value' => 'Grafito', 'options_version' => $p->fresh()->options_version], $value->external_id);
    $s->archiveVariant($v);
    foreach ($lines as $line) {
        expect($line->fresh()->displayName())->toBe($name)->and($line->fresh()->product_variation_id)->toBe($v->id)->and($line->fresh()->price)->toBe(125);
    }
    expect($delivery->fresh()->displayName())->toBe($name);
});

test('forged livewire preview fields cannot apply a different command and changed creation previews fail atomically', function () {
    matrixLogin();
    $p = Product::create(['name' => 'Forged preview']);
    Livewire::test(ProductVariantEditor::class, ['product' => $p])->set('drafts', [['name' => 'Color', 'values' => 'A']])
        ->call('previewChanges')->set('drafts.0.values', 'B')->call('applyChanges')->assertHasErrors('catalog');
    expect($p->options()->count())->toBe(0);
    Livewire::test(ProductCreate::class)->set('name', 'Stale create')->set('withOptions', true)
        ->set('optionDrafts', [['name' => 'Color', 'values' => 'A']])->call('previewNewVariants')
        ->set('optionDrafts.0.values', 'A, B')->call('save')->assertHasErrors('catalog');
    expect(Product::where('name', 'Stale create')->exists())->toBeFalse();
});

test('inventory integration distinguishes parent and variant without manufacturing quantities or moving balances', function () {
    $p = Product::create(['name' => 'Inventory boundary']);
    $p->productPrices()->create(['currency' => 'PEN', 'unit_price' => 7.50, 'cost_per_unit' => 3.25]);
    $priceBefore = $p->productPrices()->first()->getRawOriginal();
    $availability = app(CatalogAvailabilityService::class);
    expect($availability->forIdentity($p, null)['requires_variant_selection'])->toBeFalse();
    matrixApply($p, matrixCommand());
    $v = $p->productVariations()->first();
    expect($availability->forIdentity($p, null)['requires_variant_selection'])->toBeTrue()
        ->and($availability->forIdentity($p, $v)['available_quantity'])->toBeNull()
        ->and($availability->forIdentity($p, $v)['parent_stock_transfer_allowed'])->toBeFalse()
        ->and($p->productPrices()->first()->getRawOriginal())->toBe($priceBefore)
        ->and($v->productPrices()->count())->toBe(0);
});

test('reusing labels after rename assigns new identities without reinterpreting existing option or value IDs', function () {
    $p = Product::create(['name' => 'Stable identity']);
    matrixApply($p, ['action' => 'create_options', 'strategy' => 'CREATE', 'options' => [['name' => 'Color', 'values' => ['Negro']]]]);
    $s = app(ProductVariantMatrixService::class);
    $option = $p->options()->first();
    $value = $option->values()->first();
    $variant = $p->productVariations()->first();
    $key = $variant->combination_key;
    $s->patchOption($p, $option->external_id, ['value' => 'Grafito', 'options_version' => $p->fresh()->options_version], $value->external_id);
    matrixApply($p, ['action' => 'add_values', 'option_id' => $option->external_id, 'values' => ['Negro'], 'strategy' => 'MANAGE_NON_DESTRUCTIVE']);
    expect($option->values()->count())->toBe(2)->and($variant->fresh()->combination_key)->toBe($key)
        ->and($option->values()->where('value', 'Negro')->value('external_id'))->not->toBe($value->external_id);
    $s->patchOption($p, $option->external_id, ['name' => 'Tono', 'options_version' => $p->fresh()->options_version]);
    matrixApply($p, ['action' => 'create_options', 'options' => [['name' => 'Color', 'values' => ['Sin especificar']]], 'confirm_defaults' => true]);
    expect($p->options()->count())->toBe(2)->and($p->options()->where('name', 'Color')->value('external_id'))->not->toBe($option->external_id);
});

test('document API uses frozen snapshot names while catalogue resources reflect current labels', function () {
    $p = Product::create(['name' => 'Original product', 'code' => 'PARENT']);
    matrixApply($p, matrixCommand());
    $v = $p->productVariations()->first();
    $line = Order::create([])->orderProducts()->create(['product_id' => $p->id, 'product_variation_id' => $v->id]);
    $before = $line->displayName();
    $s = app(ProductVariantMatrixService::class);
    $o = $p->options()->first();
    $value = $o->values()->first();
    $s->patchOption($p, $o->external_id, ['value' => 'Grafito', 'options_version' => $p->fresh()->options_version], $value->external_id);
    $response = (new OrderProductResource($line->fresh()->load(['product', 'productVariation'])))->resolve(request());
    expect($response['display_name'])->toBe($before)->and($response['variation']['name'])->toBe($line->catalogSnapshot()['variation_name'])
        ->and($response['variation']['name'])->not->toBe($v->fresh()->name)
        ->and($response['catalog_snapshot_source'])->toBe('captured_at_creation');
});

test('Livewire queues multiple concrete combinations and paginates a preview without changing its hash', function () {
    matrixLogin();
    $p = Product::create(['name' => 'Queue']);
    matrixApply($p, matrixCommand('LEAVE_AS_IS'));
    $o = app(ProductVariantMatrixService::class)->options($p);
    Livewire::test(ProductVariantEditor::class, ['product' => $p])->set('action', 'generate')->set('selectedOnly', true)
        ->set('selection', [$o[0]['id'] => $o[0]['values'][0]['id'], $o[1]['id'] => $o[1]['values'][0]['id']])->call('queueSelection')
        ->set('selection', [$o[0]['id'] => $o[0]['values'][1]['id'], $o[1]['id'] => $o[1]['values'][1]['id']])->call('queueSelection')
        ->call('previewChanges')->assertSet('preview.counts.created', 2)->call('applyChanges')->assertHasNoErrors();
    expect($p->productVariations()->count())->toBe(2);
    $big = Product::create(['name' => 'Paged preview']);
    $editor = Livewire::test(ProductVariantEditor::class, ['product' => $big])->set('strategy', 'CREATE')
        ->set('drafts', [['name' => 'Number', 'values' => implode(',', range(1, 120))]])->call('previewChanges')->assertCount('preview.details', 100);
    $hash = $editor->get('previewHash');
    $editor->call('changePreviewPage', 1)->assertCount('preview.details', 20)->assertSet('previewHash', $hash)->assertHasNoErrors();
});

test('Livewire uploads an optional private catalog photograph and associates it without altering the source', function () {
    matrixLogin();
    Storage::fake('local');
    $p = Product::create(['name' => 'Photo catalogue']);
    matrixApply($p, matrixCommand());
    $upload = UploadedFile::fake()->createWithContent('catalog.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRusAAAAASUVORK5CYII='));
    Livewire::test(ProductVariantEditor::class, ['product' => $p])->set('uploadedImage', $upload)->call('uploadImage')->assertHasNoErrors();
    $file = $p->files()->firstOrFail();
    expect($file->disk)->toBe('local')->and($file->mime)->toBe('image/png')->and($file->fileable_id)->toBe($p->id);
    Storage::disk('local')->assertExists($file->file);
    $before = Storage::disk('local')->get($file->file);
    app(ProductVariantMatrixService::class)->updateVariantDetails($p->productVariations()->first(), ['image_file_id' => $file->external_id]);
    expect(Storage::disk('local')->get($file->file))->toBe($before);
});

test('legacy backoffice still adds and renames manual variants until options are introduced', function () {
    matrixLogin();
    $p = Product::create(['name' => 'Manual legacy']);
    $editor = Livewire::test(ProductVariantEditor::class, ['product' => $p])->set('legacyName', 'Legacy name')
        ->call('createLegacyVariation')->assertHasNoErrors();
    $v = $p->productVariations()->firstOrFail();
    $editor->set('rows.'.$v->external_id.'.name', 'Renamed legacy')->call('saveVariant', $v->external_id)->assertHasNoErrors();
    expect($v->fresh()->name)->toBe('Renamed legacy');
    matrixApply($p, matrixCommand('LEAVE_AS_IS'));
    $editor->call('reload')->set('legacyName', 'Bypass')->call('createLegacyVariation')->assertHasErrors('catalog');
    expect($p->productVariations()->count())->toBe(1);
});

test('legacy V2 variation CRUD retains partial writes major unit pricing and nested ownership', function () {
    matrixLogin();
    $p = Product::create(['name' => 'Legacy V2']);
    $other = Product::create(['name' => 'Other V2']);
    $url = '/crm/api/v2/products/'.$p->external_id.'/variations';
    $created = $this->postJson($url, ['name' => 'Legacy M', 'code' => 'SKU-LEGACY', 'unit_price' => 12.75, 'currency' => 'PEN'])
        ->assertCreated()->assertJsonPath('data.code', 'SKU-LEGACY')->assertJsonPath('data.prices.0.unit_price', 12.75)->json('data.id');
    $this->patchJson($url.'/'.$created, ['barcode' => '123456'])->assertOk()->assertJsonPath('data.code', 'SKU-LEGACY')
        ->assertJsonPath('data.prices.0.unit_price', 12.75);
    $this->getJson('/crm/api/v2/products/'.$other->external_id.'/variations/'.$created)->assertNotFound();
    $v = $p->productVariations()->firstOrFail();
    expect($v->priceFor('PEN')->unit_price)->toBe(1275);
    $this->deleteJson($url.'/'.$created)->assertNoContent();
    expect($v->fresh()->trashed())->toBeTrue()->and($v->fresh()->priceFor('PEN')->unit_price)->toBe(1275);
});
