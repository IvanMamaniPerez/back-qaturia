<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\PostgresTestCase;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Services\ProductVariantMatrixService;

uses(PostgresTestCase::class);

function pgMatrixApply(Product $p, array $command): array
{
    $s = app(ProductVariantMatrixService::class);
    $plan = $s->preview($p, $command);

    return $s->apply($p, $command, $plan['options_version'], $plan['plan_hash'], (string) Str::uuid());
}

function pgMatrixWorkers(Product $p, array $inputs): array
{
    DB::beginTransaction();
    Product::whereKey($p->id)->lockForUpdate()->firstOrFail();
    $workers = [];
    try {
        foreach ($inputs as $input) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/matrix-worker.php')], base_path(), [
                'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            ], json_encode(['product' => $p->external_id] + $input, JSON_THROW_ON_ERROR), 40);
            $process->start();
            $process->waitUntil(fn ($type, $output) => str_contains($output, 'ready'));
            $workers[] = $process;
        }
        // Both workers have reached the locked parent; neither can complete a write yet.
        foreach ($workers as $worker) {
            expect($worker->isRunning())->toBeTrue();
        }
    } finally {
        DB::rollBack();
    }
    $results = [];
    foreach ($workers as $worker) {
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        $results[] = json_decode(substr($worker->getOutput(), strpos($worker->getOutput(), "\n") + 1), true, 512, JSON_THROW_ON_ERROR);
    }

    return $results;
}

test('two committed simultaneous applies share an idempotent result and distinct keys detect a stale plan', function () {
    $p = Product::create(['name' => 'Concurrent generation']);
    $s = app(ProductVariantMatrixService::class);
    $command = ['action' => 'create_options', 'strategy' => 'CREATE', 'options' => [
        ['name' => 'Color', 'values' => ['Negro', 'Blanco']], ['name' => 'Talla', 'values' => ['S', 'M']],
    ]];
    $plan = $s->preview($p, $command);
    $input = ['mode' => 'apply', 'command' => $command, 'version' => 0, 'hash' => $plan['plan_hash'], 'key' => 'race'];
    $results = pgMatrixWorkers($p, [$input, $input]);
    expect(array_column($results, 'status'))->toBe([200, 200])->and($p->productVariations()->count())->toBe(4)
        ->and($results[0]['result'])->toBe($results[1]['result']);
    $generate = ['action' => 'generate'];
    $plan = $s->preview($p, $generate);
    $input = ['mode' => 'apply', 'command' => $generate, 'version' => $plan['options_version'], 'hash' => $plan['plan_hash']];
    $results = pgMatrixWorkers($p, [$input + ['key' => 'one'], $input + ['key' => 'two']]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe([200, 409])->and($p->productVariations()->count())->toBe(4);
});

test('option reorder racing with generation is serialized and cannot change variant identity', function () {
    $p = Product::create(['name' => 'Order race']);
    pgMatrixApply($p, ['action' => 'create_options', 'strategy' => 'CREATE', 'options' => [
        ['name' => 'Color', 'values' => ['Negro']], ['name' => 'Talla', 'values' => ['S']],
    ]]);
    $before = $p->productVariations()->pluck('combination_key', 'id')->all();
    $command = ['action' => 'generate'];
    $plan = app(ProductVariantMatrixService::class)->preview($p, $command);
    $results = pgMatrixWorkers($p, [
        ['mode' => 'reorder', 'option' => $p->options()->first()->external_id, 'version' => $plan['options_version']],
        ['mode' => 'apply', 'command' => $command, 'version' => $plan['options_version'], 'hash' => $plan['plan_hash'], 'key' => 'reorder-generate'],
    ]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe([200, 409])->and($p->productVariations()->pluck('combination_key', 'id')->all())->toBe($before);
});

test('archived rows count toward 2048 and simultaneous last-slot inserts cannot exceed the limit', function () {
    $p = Product::create(['name' => 'Limit race']);
    $rows = [];
    for ($i = 0; $i < 2047; $i++) {
        $rows[] = ['id' => (string) Str::uuid(), 'external_id' => (string) Str::uuid(), 'product_id' => $p->id, 'name' => 'Legacy '.$i, 'deleted_at' => now()];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('crm_product_variations')->insert($chunk);
    }
    $results = pgMatrixWorkers($p, [['mode' => 'insert'], ['mode' => 'insert']]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe([200, 422])->and($p->productVariations()->withTrashed()->count())->toBe(2048);
    expect(fn () => DB::transaction(fn () => DB::table('crm_product_variations')->insert([
        'id' => (string) Str::uuid(), 'external_id' => (string) Str::uuid(), 'product_id' => $p->id, 'name' => 'Over limit',
    ])))->toThrow(QueryException::class, 'VARIANT_LIMIT_EXCEEDED');
});

test('PostgreSQL enforces complete selections uniqueness and matching option product and value even on raw writes', function () {
    $p = Product::create(['name' => 'Constraints']);
    pgMatrixApply($p, ['action' => 'create_options', 'strategy' => 'CREATE', 'options' => [
        ['name' => 'Color', 'values' => ['A', 'B']], ['name' => 'Size', 'values' => ['S']],
    ]]);
    $v = $p->productVariations()->first();
    expect(fn () => DB::transaction(fn () => DB::table('crm_product_variation_option_values')->where('product_variation_id', $v->id)->delete()))
        ->toThrow(PDOException::class, 'EXACTLY_ONE_VALUE_PER_OPTION_REQUIRED');
    expect($v->selections()->count())->toBe(2);
    expect(fn () => DB::transaction(fn () => DB::table('crm_product_variations')->insert([
        'id' => (string) Str::uuid(), 'external_id' => (string) Str::uuid(), 'product_id' => $p->id,
        'name' => 'Duplicate', 'combination_key' => $v->combination_key,
    ])))->toThrow(QueryException::class);
    $options = $p->options()->with('values')->get();
    $selection = $v->selections()->where('product_option_id', $options[0]->id)->first();
    expect(fn () => DB::transaction(fn () => DB::table('crm_product_variation_option_values')->where('id', $selection->id)->update([
        'product_option_value_id' => $options[1]->values->first()->id,
    ])))->toThrow(QueryException::class);
    $other = Product::create(['name' => 'Other constraint product']);
    pgMatrixApply($other, ['action' => 'create_options', 'options' => [['name' => 'Foreign', 'values' => ['F']]]]);
    expect(fn () => DB::transaction(fn () => DB::table('crm_product_variation_option_values')->where('id', $selection->id)->update([
        'product_option_id' => $other->options()->first()->id, 'product_option_value_id' => $other->options()->first()->values()->first()->id,
    ])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('crm_product_options')->insert([
        'id' => (string) Str::uuid(), 'external_id' => (string) Str::uuid(), 'product_id' => $p->id, 'name' => 'COLOR', 'normalized_name' => 'color',
    ])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('crm_product_options')->insert([
        'id' => (string) Str::uuid(), 'external_id' => (string) Str::uuid(), 'product_id' => $p->id,
        'name' => '  CoLoR  ', 'normalized_name' => 'forged-normalized-field',
    ])))->toThrow(QueryException::class);
    $constraints = DB::select("SELECT conname FROM pg_constraint WHERE conrelid='crm_product_variation_option_values'::regclass");
    expect(count($constraints))->toBeGreaterThanOrEqual(6);
});
