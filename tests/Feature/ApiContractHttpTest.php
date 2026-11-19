<?php

use App\Models\User;
use App\Support\ApiContracts\ContractRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    $this->httpContractDirectory = null;
});

afterEach(function () {
    if ($this->httpContractDirectory !== null) {
        File::deleteDirectory($this->httpContractDirectory);
    }
});

test('local clients discover query links and search without knowing a business route', function () {
    app()->instance('env', 'local');

    $discovery = $this->getJson('/.well-known/api-contracts')->assertOk()
        ->assertJsonPath('operation_count', 85)
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('links.query', route('api-contracts.query'));
    expect($discovery->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    $search = $this->getJson($discovery->json('links.search').'&q=products%20index&limit=1')
        ->assertOk()->assertJsonCount(1, 'operations')->assertJsonPath('total', 2)
        ->assertJsonPath('operations.0.operationId', 'crm.v2.products.index.get');
    $this->getJson($discovery->json('links.query').'?q=products%20index&limit=1')->assertOk()->assertJsonPath('total', 2);
    $this->getJson($discovery->json('links.operation').'&operationId='.$search->json('operations.0.operationId'))
        ->assertOk()->assertJsonPath('contract.method', 'GET')
        ->assertJsonStructure(['components' => ['schemas' => ['ProductResource', 'ProductVariationResource'], 'securitySchemes' => ['http', 'frontendSession']]])
        ->assertHeader('X-Contract-Revision', $discovery->json('contract_sha256'));
});

test('production rejects guests and unauthorized users even without an Accept header', function () {
    app()->instance('env', 'production');
    Gate::define('viewApiDocs', fn (User $user): bool => false);

    $this->get('/.well-known/api-contracts')->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    $this->getJson(route('api-contracts.query', ['q' => 'products']))->assertForbidden();
    $this->actingAs(User::factory()->make())->getJson('/.well-known/api-contracts')->assertForbidden();
});

test('production allows only a session user authorized by viewApiDocs', function () {
    app()->instance('env', 'production');
    $reader = User::factory()->create();
    Gate::define('viewApiDocs', fn (User $user): bool => $user->is($reader));

    $this->getJson('/.well-known/api-contracts')->assertForbidden();
    $this->actingAs($reader)->getJson('/.well-known/api-contracts')->assertOk();
    $this->actingAs(User::factory()->create())->getJson('/.well-known/api-contracts')->assertForbidden();
});

test('the configured documentation reader allowlist fails closed by default in production', function () {
    app()->instance('env', 'production');
    $reader = User::factory()->create();
    $this->actingAs($reader)->getJson('/.well-known/api-contracts')->assertForbidden();

    config(['api-contracts.readers' => [$reader->id]]);
    $this->getJson('/.well-known/api-contracts')->assertOk();
    $this->actingAs(User::factory()->create())->getJson('/.well-known/api-contracts')->assertForbidden();
});

test('HTTP queries retrieve schemas and compare available contract revisions', function () {
    app()->instance('env', 'local');
    $repository = new ContractRepository;
    $version = $repository->verify(base_path('contracts'));

    $this->getJson(route('api-contracts.query', ['action' => 'schema', 'schemaName' => 'ProductResource']))
        ->assertOk()->assertJsonPath('contract.$ref', '#/components/schemas/ProductResource')
        ->assertJsonStructure(['components' => ['schemas' => ['ProductVariationResource']]]);
    $this->getJson(route('api-contracts.query', ['action' => 'changes', 'revision' => $version['contract_sha256']]))
        ->assertOk()->assertJsonPath('requires_review', false)->assertJsonPath('operations.modified', []);
});

test('unknown operation schema and revision names return 404', function (array $query) {
    app()->instance('env', 'local');

    $this->getJson(route('api-contracts.query', $query))->assertNotFound()->assertJsonStructure(['message']);
})->with([
    [['action' => 'operation', 'operationId' => 'missing.operation']],
    [['action' => 'schema', 'schemaName' => 'MissingSchema']],
    [['action' => 'changes', 'revision' => str_repeat('0', 64)]],
]);

test('HTTP input cannot select paths write files or execute generation', function (array $query, string $field) {
    app()->instance('env', 'local');

    $this->getJson(route('api-contracts.query', $query))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['action' => 'generate'], 'action'],
    [['action' => 'sync'], 'action'],
    [['limit' => 101], 'limit'],
    [['max_bytes' => 1048577], 'max_bytes'],
    [['action' => 'operation'], 'operationId'],
    [['action' => 'schema'], 'schemaName'],
    [['action' => 'changes', 'revision' => '../../.env'], 'revision'],
    [['directory' => '/tmp/other-contracts'], 'directory'],
    [['output' => '/tmp/contract-output.json'], 'output'],
    [['q' => str_repeat('x', 201)], 'q'],
]);

test('oversized responses return 413 without truncating the contract and can be retried', function () {
    app()->instance('env', 'local');
    $query = ['action' => 'operation', 'operationId' => 'crm.v2.products.index.get'];

    $this->getJson(route('api-contracts.query', [...$query, 'max_bytes' => 1024]))
        ->assertStatus(413)->assertJsonMissingPath('contract')->assertJsonStructure(['required_bytes', 'max_bytes']);
    $this->getJson(route('api-contracts.query', [...$query, 'max_bytes' => 1048576]))
        ->assertOk()->assertJsonStructure(['contract', 'components']);
});

test('unavailable artifacts fail closed without returning server paths', function () {
    app()->instance('env', 'local');
    config(['api-contracts.directory' => '/tmp/nonexistent-contracts-'.bin2hex(random_bytes(8))]);
    Exceptions::fake();

    $this->getJson('/.well-known/api-contracts')->assertServiceUnavailable()
        ->assertExactJson(['message' => 'Contract artifacts are unavailable or inconsistent.']);
    Exceptions::assertReported(RuntimeException::class);
});

test('HTTP retrieval rejects mismatched artifacts and verifies historical snapshots', function () {
    app()->instance('env', 'local');
    $this->httpContractDirectory = sys_get_temp_dir().'/qaturia-http-contracts-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->httpContractDirectory);
    foreach (['openapi.json', 'operations-index.json', 'contract-version.json', 'diagnostics.json'] as $file) {
        File::copy(base_path('contracts/'.$file), $this->httpContractDirectory.'/'.$file);
    }
    config(['api-contracts.directory' => $this->httpContractDirectory]);
    $repository = new ContractRepository;
    $previous = $repository->read($this->httpContractDirectory.'/openapi.json');
    $previous['components']['schemas']['ProductResource']['properties']['code']['description'] = 'Previous description';
    $revision = $repository->hash($previous);
    $snapshot = $this->httpContractDirectory.'/revisions/'.$revision;
    File::ensureDirectoryExists($snapshot);
    File::put($snapshot.'/openapi.json', $repository->json($previous));

    $this->getJson(route('api-contracts.query', ['action' => 'changes', 'revision' => $revision]))
        ->assertOk()->assertJsonPath('requires_review', true)->assertJsonPath('schemas.modified', ['ProductResource']);

    Exceptions::fake();
    File::put($snapshot.'/openapi.json', $repository->json(['modified' => true]));
    $this->getJson(route('api-contracts.query', ['action' => 'changes', 'revision' => $revision]))->assertServiceUnavailable();
    $manifest = $repository->read($this->httpContractDirectory.'/contract-version.json');
    $manifest['contract_sha256'] = str_repeat('0', 64);
    File::put($this->httpContractDirectory.'/contract-version.json', $repository->json($manifest));
    $this->getJson('/.well-known/api-contracts')->assertServiceUnavailable();
    Exceptions::assertReported(RuntimeException::class);
});

test('HTTP queries are throttled with the same rate budget for discovery and retrieval', function () {
    app()->instance('env', 'local');
    RateLimiter::for('api-contracts', fn () => Limit::perMinute(1)->by('contract-http-test'));

    $this->getJson('/.well-known/api-contracts')->assertOk();
    $this->getJson(route('api-contracts.query', ['q' => 'products']))->assertTooManyRequests();
});

test('documentation queries reuse the configured frontend origins without making other origins trusted', function () {
    app()->instance('env', 'local');

    $this->withHeader('Origin', 'http://localhost:5173')->getJson('/.well-known/api-contracts')
        ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
    $this->withHeader('Origin', 'http://localhost:5173')->getJson(route('api-contracts.query', ['q' => 'products']))
        ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    $this->withHeader('Origin', 'https://untrusted.example')->getJson('/.well-known/api-contracts')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('discovery provides exact protected JSON downloads for Orval and its metadata', function (string $name) {
    app()->instance('env', 'local');
    $descriptor = $this->getJson('/.well-known/api-contracts')->assertOk();
    $artifact = $descriptor->json('artifacts')[$name];
    $expected = File::get(base_path('contracts/'.$name));

    $download = $this->get($artifact['url'])->assertOk()->assertDownload($name)
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Contract-Revision', $descriptor->json('contract_sha256'))
        ->assertHeader('X-Artifact-SHA256', $artifact['sha256'])
        ->assertContent($expected);
    expect(hash('sha256', $download->getContent()))->toBe($artifact['sha256']);
    expect(strlen($download->getContent()))->toBe($artifact['bytes']);
    expect($download->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
})->with(ContractRepository::ARTIFACTS);

test('production artifact downloads require the documentation reader session', function () {
    app()->instance('env', 'production');
    $url = route('api-contracts.query', ['action' => 'download', 'artifact' => 'openapi.json']);

    $this->get($url)->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    $reader = User::factory()->create();
    $this->actingAs($reader)->get($url)->assertForbidden();
    config(['api-contracts.readers' => [$reader->id]]);
    $this->get($url)->assertOk()->assertDownload('openapi.json');
});

test('download selection rejects arbitrary files and invalid version pins', function (array $query, string $field) {
    app()->instance('env', 'local');

    $this->getJson(route('api-contracts.query', ['action' => 'download', ...$query]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [[], 'artifact'],
    [['artifact' => '../.env'], 'artifact'],
    [['artifact' => '/etc/passwd'], 'artifact'],
    [['artifact' => 'missing.json'], 'artifact'],
    [['artifact' => 'openapi.json', 'sha256' => 'invalid'], 'sha256'],
    [['artifact' => 'openapi.json', 'revision' => '../revision'], 'revision'],
]);

test('downloads fail with 409 if a pinned contract or file has changed', function (string $pin) {
    app()->instance('env', 'local');

    $this->getJson(route('api-contracts.query', ['action' => 'download', 'artifact' => 'openapi.json', $pin => str_repeat('0', 64)]))
        ->assertConflict()->assertJsonMissingPath('paths')
        ->assertExactJson(['message' => 'The contract artifact changed; refresh discovery and retry the complete download.']);
})->with(['revision', 'sha256']);

test('file downloads have their own size limit and preserve the complete OpenAPI bytes', function () {
    app()->instance('env', 'local');
    $url = route('api-contracts.query', ['action' => 'download', 'artifact' => 'openapi.json', 'max_bytes' => 1024]);

    $this->get($url)->assertOk()->assertDownload('openapi.json')
        ->assertContent(File::get(base_path('contracts/openapi.json')));
    config(['api-contracts.http.max_download_bytes' => 1024]);
    $this->getJson($url)->assertStatus(413)->assertJsonMissingPath('paths');
});

test('manifest checksum pins detect metadata changes even when OpenAPI has the same revision', function () {
    app()->instance('env', 'local');
    $this->httpContractDirectory = sys_get_temp_dir().'/qaturia-download-contracts-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->httpContractDirectory);
    foreach (ContractRepository::ARTIFACTS as $name) {
        File::copy(base_path('contracts/'.$name), $this->httpContractDirectory.'/'.$name);
    }
    config(['api-contracts.directory' => $this->httpContractDirectory]);
    $descriptor = $this->getJson('/.well-known/api-contracts')->assertOk();
    $oldUrl = $descriptor->json('artifacts')['contract-version.json']['url'];
    $repository = new ContractRepository;
    $version = $repository->read($this->httpContractDirectory.'/contract-version.json');
    $version['code_revision'] = str_repeat('a', 40);
    File::put($this->httpContractDirectory.'/contract-version.json', $repository->json($version));

    $this->getJson($oldUrl)->assertConflict();
    $updated = $this->getJson('/.well-known/api-contracts')->assertOk();
    $this->get($updated->json('artifacts')['contract-version.json']['url'])->assertOk()->assertDownload('contract-version.json');

    Exceptions::fake();
    File::append($this->httpContractDirectory.'/openapi.json', "\n");
    $this->getJson($updated->json('artifacts')['openapi.json']['url'])->assertServiceUnavailable()
        ->assertExactJson(['message' => 'Contract artifacts are unavailable or inconsistent.']);
    Exceptions::assertReported(RuntimeException::class);
});
