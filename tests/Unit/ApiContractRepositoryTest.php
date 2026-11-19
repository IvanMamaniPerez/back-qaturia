<?php

use App\Support\ApiContracts\ContractRepository;

function apiContractFixture(): array
{
    return [
        'openapi' => '3.1.2', 'info' => ['title' => 'Test', 'version' => '1'],
        'servers' => [['url' => '/crm/api/v2']],
        'security' => [['bearer' => []]],
        'paths' => ['/products' => ['parameters' => [['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']]], 'get' => [
            'operationId' => 'products.index', 'tags' => ['products'], 'summary' => 'List products',
            'responses' => [200 => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Product']]]]],
        ]]],
        'components' => [
            'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
            'schemas' => [
                'Product' => ['type' => 'object', 'properties' => ['value' => ['$ref' => '#/components/schemas/Price'], 'parent' => ['$ref' => '#/components/schemas/Product']]],
                'Price' => ['type' => 'number'], 'Unused' => ['type' => 'string'],
            ],
        ],
    ];
}

test('retrieval preserves inherited metadata and resolves shared cyclic references once', function () {
    $repository = new ContractRepository;

    $bundle = $repository->operation(apiContractFixture(), 'products.index');

    expect($bundle['contract']['operation']['security'])->toBe([['bearer' => []]]);
    expect($bundle['contract']['operation']['parameters'][0]['name'])->toBe('page');
    expect($bundle['components']['schemas'])->toHaveKeys(['Product', 'Price'])->not->toHaveKey('Unused');
    expect($bundle['components']['securitySchemes'])->toHaveKey('bearer');
    expect($repository->schema(apiContractFixture(), 'Product')['components']['schemas'])->toHaveCount(2);
});

test('a transitive schema change changes the operation revision and triggers compatibility review', function () {
    $repository = new ContractRepository;
    $before = apiContractFixture();
    $after = $before;
    $after['components']['schemas']['Price']['type'] = 'string';

    $changes = $repository->changes($before, $after);

    expect($changes['operations']['modified'])->toBe(['products.index']);
    expect($changes['schemas']['modified'])->toBe(['Price']);
    expect($changes['requires_review'])->toBeTrue();
});

test('removed operations and security changes require review while additive operations do not', function () {
    $repository = new ContractRepository;
    $before = apiContractFixture();
    $after = $before;
    $after['paths']['/products']['get']['security'] = [];

    expect($repository->changes($before, $after)['requires_review'])->toBeTrue();
    $after = $before;
    $after['paths']['/products']['post'] = ['operationId' => 'products.store', 'responses' => [201 => ['description' => 'Created']]];
    expect($repository->changes($before, $after)['requires_review'])->toBeFalse();
    expect($repository->changes($after, $before)['operations']['removed'])->toBe(['products.store']);
});

test('validation rejects unresolved external references and duplicate operation identifiers', function (string $defect) {
    $repository = new ContractRepository;
    $spec = apiContractFixture();
    if ($defect === 'duplicate') {
        $spec['paths']['/duplicate']['post'] = $spec['paths']['/products']['get'];
    } else {
        $spec['components']['schemas']['Price'] = ['$ref' => $defect];
    }

    expect(fn () => $repository->validate($spec))->toThrow(RuntimeException::class);
})->with(['#/components/schemas/Missing', 'https://example.test/remote.json', 'duplicate']);

test('canonical encoding preserves empty JSON objects and ignores object key ordering', function () {
    $repository = new ContractRepository;
    $first = $repository->decode('{"b":{},"a":{"type":"string"}}');
    $second = $repository->decode('{"a":{"type":"string"},"b":{}}');

    expect($repository->hash($first))->toBe($repository->hash($second));
    expect($repository->json($first))->toContain('"b": {}');
});

test('retrieval rejects unknown operation and schema names', function (string $kind) {
    $repository = new ContractRepository;

    expect(fn () => $repository->{$kind}(apiContractFixture(), 'missing'))->toThrow(RuntimeException::class);
})->with(['operation', 'schema']);

test('duplicate operation parameters are rejected before client generation', function () {
    $repository = new ContractRepository;
    $spec = apiContractFixture();
    $parameter = ['in' => 'query', 'name' => 'page', 'schema' => ['type' => 'integer']];
    $spec['paths']['/products']['get']['parameters'] = [$parameter, $parameter];

    expect(fn () => $repository->validate($spec))->toThrow(RuntimeException::class, 'Duplicate parameter');
});

test('diagnostic comparison detects added removed and modified errors even when counts match', function () {
    $repository = new ContractRepository;
    $original = ['context' => ['key' => 'route:products'], 'details' => [['Located at', '/products']], 'code' => 'SE001', 'message' => 'Unknown'];
    $modified = [...$original, 'message' => 'Different error'];
    $added = [...$original, 'context' => ['key' => 'route:stock']];

    $changes = $repository->diagnosticChanges([$original], [$modified, $added]);

    expect($changes['added'])->toBe([$added]);
    expect($changes['modified'])->toBe([['before' => [$original], 'after' => [$modified]]]);
    expect($repository->diagnosticChanges([$original], [])['removed'])->toBe([$original]);
    expect($repository->diagnosticChanges([$original, $added], [$added, $original]))->toBe(['added' => [], 'removed' => [], 'modified' => []]);
});
