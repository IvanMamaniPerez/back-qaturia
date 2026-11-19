<?php

use App\Models\User;
use App\Support\ApiContracts\ContractRepository;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->contractDirectory = sys_get_temp_dir().'/qaturia-contract-test-'.bin2hex(random_bytes(8));
});

afterEach(function () {
    File::deleteDirectory($this->contractDirectory);
});

test('Scramble generates approved module routes and selectively retrieves their complete dependencies', function () {
    $this->artisan('api:contracts', ['action' => 'generate', '--allow-partial' => true, '--directory' => $this->contractDirectory])->assertSuccessful();
    $repository = new ContractRepository;
    $version = $repository->verify($this->contractDirectory);
    $spec = $repository->read($this->contractDirectory.'/openapi.json');
    $index = $repository->index($spec);
    $operation = $repository->operation($spec, 'crm.v2.products.index.get');

    expect($version['status'])->toBe('draft');
    expect($version['source_sha256'])->toBe($repository->sourceHash());
    expect($index['operations'])->toHaveCount(77);
    expect($spec['paths'])->toHaveKey('/auth/token')->toHaveKey('/products/{product}/variations/{productVariation}');
    expect($spec['paths']['/products/{product}'])->toHaveKey('patch')->toHaveKey('put');
    expect($spec['paths'])->not->toHaveKey('/crm/users')->not->toHaveKey('/docs/api');
    expect($operation['components']['schemas'])->toHaveKey('ProductResource')->toHaveKey('ProductVariationResource');
    expect($operation['components']['securitySchemes'])->toHaveKey('http')->toHaveKey('frontendSession');
    expect($spec['paths']['/auth/token']['post']['security'])->toBe([]);
    expect($spec['paths']['/auth/me']['get']['responses'])->toHaveKey(401)->toHaveKey(403);
    expect($spec['paths']['/products/{product}/options/apply']['post']['responses'])->toHaveKey(409)->toHaveKey(422);
    expect($spec['components']['schemas']['ProductResource']['properties']['tax_rate']['type'])->toBe(['number', 'null']);
    expect($spec['components']['schemas']['StoreProductRequest']['required'])->toContain('name');
    expect($repository->search($index, 'products index', 2)['operations'])->toHaveCount(2);
    expect($repository->search($index, '/crm/api/v2/products', 1)['total'])->toBeGreaterThan(0);
    $this->getJson('/crm/api/v2/products')->assertUnauthorized();
    $this->postJson('/crm/api/v2/auth/token', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    $user = User::factory()->create(['crm_access' => true]);
    $tokenResponse = $this->postJson('/crm/api/v2/auth/token', ['email' => $user->email, 'password' => 'password'])
        ->assertCreated()->assertJsonPath('user.id', $user->id)->assertJsonPath('token_type', 'Bearer');
    $tokenSchema = $spec['paths']['/auth/token']['post']['responses'][201]['content']['application/json']['schema'];
    $tokenResponse->assertJsonStructure($tokenSchema['required']);
    expect($tokenResponse->json('expires_in'))->toBeInt();
    expect($tokenSchema['properties']['expires_in']['type'])->toBe('integer');
    $this->artisan('api:contracts', ['action' => 'operation', 'value' => 'crm.v2.products.index.get', '--directory' => $this->contractDirectory, '--output' => $this->contractDirectory.'/one.json'])->assertSuccessful();
    expect($repository->read($this->contractDirectory.'/one.json')['contract']['method'])->toBe('GET');
    $this->artisan('api:contracts', ['action' => 'operation', 'value' => 'crm.v2.products.index.get', '--directory' => $this->contractDirectory, '--max-bytes' => 1])->assertFailed();
    $this->artisan('api:contracts', ['action' => 'check', '--directory' => $this->contractDirectory])->assertSuccessful();
    $this->artisan('api:contracts', ['action' => 'changes', 'value' => $version['contract_sha256'], '--directory' => $this->contractDirectory, '--fail-on-breaking' => true])->assertSuccessful();
    $this->artisan('api:contracts', ['action' => 'sync', '--directory' => $this->contractDirectory, '--destination' => $this->contractDirectory.'/frontend'])->assertFailed();
    $this->artisan('api:contracts', ['action' => 'sync', '--directory' => $this->contractDirectory, '--destination' => $this->contractDirectory.'/frontend', '--allow-partial' => true])->assertSuccessful();
    expect($repository->verify($this->contractDirectory.'/frontend')['contract_sha256'])->toBe($version['contract_sha256']);
    $this->artisan('api:contracts', ['action' => 'sync', '--directory' => $this->contractDirectory, '--destination' => $this->contractDirectory.'/frontend', '--allow-partial' => true])->assertFailed();

    $index['contract_sha256'] = str_repeat('0', 64);
    File::put($this->contractDirectory.'/operations-index.json', $repository->json($index));
    $this->artisan('api:contracts', ['action' => 'check', '--directory' => $this->contractDirectory])->assertFailed();
});

test('documentation HTTP endpoints are absent outside local development', function () {
    $this->get('/docs/api')->assertNotFound();
    $this->get('/docs/api.json')->assertNotFound();
});

test('a new unapproved private API route does not enter the contract', function () {
    $route = Route::get('crm/api/v2/internal/debug', fn () => ['secret' => 'private'])->name('laravel-crm.api.v2.internal.debug');
    $resolver = Scramble::getGeneratorConfig('default')->routeResolver();

    expect($resolver($route))->toBeFalse();
});

test('generation is idempotent and distinguishes internal source changes from OpenAPI changes', function () {
    $repository = new ContractRepository;
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory])->assertSuccessful();
    $before = $repository->snapshot($this->contractDirectory);
    $version = $before['version'];
    $version['source_sha256'] = str_repeat('0', 64);
    File::put($this->contractDirectory.'/contract-version.json', $repository->json($version));
    $this->artisan('api:contracts', ['action' => 'check', '--directory' => $this->contractDirectory])->assertFailed();

    $reportPath = $this->contractDirectory.'/report.json';
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory, '--output' => $reportPath])->assertSuccessful();
    expect($repository->read($reportPath)['source_changed'])->toBeTrue();
    expect($repository->read($reportPath)['message'])->toBe('API contract unchanged');
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory, '--output' => $reportPath])->assertSuccessful();
    expect($repository->read($reportPath)['source_changed'])->toBeFalse();
    expect($repository->read($reportPath)['producer_metadata_changed'])->toBeFalse();

    expect($repository->snapshot($this->contractDirectory)['files'])->toBe($before['files']);
    expect(is_dir($this->contractDirectory.'/revisions'))->toBeFalse();

    $version = $before['version'];
    $version['code_revision'] = str_repeat('0', 40);
    File::put($this->contractDirectory.'/contract-version.json', $repository->json($version));
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory, '--output' => $reportPath])->assertSuccessful();
    expect($repository->read($reportPath)['metadata_only'])->toBeTrue();
    expect($repository->read($reportPath)['contract_changed'])->toBeFalse();
    expect($repository->snapshot($this->contractDirectory)['files'])->toBe($before['files']);
});

test('new or modified diagnostics cannot be accepted using allow partial and leave published files untouched', function (string $kind) {
    $repository = new ContractRepository;
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory])->assertSuccessful();
    $before = $repository->snapshot($this->contractDirectory);
    $baseline = $this->contractDirectory.'/baseline';
    File::ensureDirectoryExists($baseline);
    foreach ($before['files'] as $name => $contents) {
        File::put($baseline.'/'.$name, $contents);
    }
    $version = $before['version'];
    $diagnostics = $repository->decode($before['files']['diagnostics.json'])['diagnostics'];
    if ($kind === 'added') {
        $diagnostics = [];
    } else {
        $diagnostics[0]['message'] = 'Previously accepted diagnostic message';
    }
    $version['diagnostics_count'] = count($diagnostics);
    $version['diagnostics_sha256'] = $repository->hash($diagnostics);
    File::put($baseline.'/contract-version.json', $repository->json($version));
    File::put($baseline.'/diagnostics.json', $repository->json(['contract_sha256' => $version['contract_sha256'], 'diagnostics' => $diagnostics]));

    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory, '--baseline' => $baseline, '--allow-partial' => true])
        ->expectsOutputToContain('"'.$kind.'": [')->assertFailed();

    expect($repository->snapshot($this->contractDirectory)['files'])->toBe($before['files']);
})->with(['added', 'modified']);

test('unclassified API routes block publication while explicitly private routes remain excluded', function () {
    Route::get('crm/api/v2/internal/new', fn () => ['private' => true])->name('contract.private');

    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory])
        ->expectsOutputToContain('unapproved_candidates')->assertFailed();
    expect(is_file($this->contractDirectory.'/openapi.json'))->toBeFalse();
    config(['api-contracts.excluded_routes' => ['contract.private']]);
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory])->assertSuccessful();
    expect((new ContractRepository)->read($this->contractDirectory.'/openapi.json')['paths'])->not->toHaveKey('/internal/new');
});

test('approved additions request and resource changes and removals are reflected in artifacts and review reports', function () {
    $repository = new ContractRepository;
    $name = 'laravel-crm.api.v2.contract-fixture.store';
    $route = Route::post('crm/api/v2/contract-fixture', [ContractFixtureController::class, 'original'])->name($name);
    config(['api-contracts.routes' => [...config('api-contracts.routes'), $name]]);
    $id = 'crm.v2.contract-fixture.store.post';
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory])
        ->expectsOutputToContain('"'.$id.'"')->assertSuccessful();
    $before = $repository->snapshot($this->contractDirectory);
    expect(array_column($before['index']['operations'], 'operationId'))->toContain($id);
    expect($before['spec']['paths'])->toHaveKey('/contract-fixture');

    $route->uses(ContractFixtureController::class.'@changed');
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory, '--fail-on-breaking' => true])
        ->expectsOutputToContain('"modified": [')->assertFailed();
    expect($repository->snapshot($this->contractDirectory)['files'])->toBe($before['files']);
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory])->assertSuccessful();
    $after = $repository->snapshot($this->contractDirectory);
    $changes = $repository->changes($before['spec'], $after['spec']);
    expect($changes['operations']['modified'])->toContain($id);
    expect($changes['requires_review'])->toBeTrue();
    expect($after['spec']['components']['schemas']['ContractChangedRequest']['properties'])->toHaveKey('quantity');
    expect($after['spec']['components']['schemas']['ContractChangedResource']['properties'])->toHaveKey('available');

    $routes = new RouteCollection;
    foreach (app('router')->getRoutes() as $registered) {
        if ($registered !== $route) {
            $routes->add($registered);
        }
    }
    app('router')->setRoutes($routes);
    config(['api-contracts.routes' => array_values(array_diff(config('api-contracts.routes'), [$name]))]);
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory, '--fail-on-breaking' => true])
        ->expectsOutputToContain('"removed": [')->assertFailed();
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory])->assertSuccessful();
    $removed = $repository->snapshot($this->contractDirectory);
    expect($removed['spec']['paths'])->not->toHaveKey('/contract-fixture');
    expect(array_column($removed['index']['operations'], 'operationId'))->not->toContain($id);
    expect($repository->changes($after['spec'], $removed['spec'])['operations']['removed'])->toContain($id);
});

test('a failed staged validation or publication preserves the complete previous set', function () {
    $repository = new ContractRepository;
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory])->assertSuccessful();
    $before = $repository->snapshot($this->contractDirectory);
    $this->mock(Generator::class)->shouldReceive('generate')->once()->andThrow(new RuntimeException('Simulated inference failure'));
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory])->assertFailed();
    expect($repository->snapshot($this->contractDirectory)['files'])->toBe($before['files']);
    $documents = array_map(fn (string $json): array => $repository->decode($json), $before['files']);
    $documents['operations-index.json']['contract_sha256'] = str_repeat('0', 64);

    expect(fn () => $repository->publish($this->contractDirectory, $documents))->toThrow(RuntimeException::class);
    expect($repository->snapshot($this->contractDirectory)['files'])->toBe($before['files']);

    $spec = $before['spec'];
    $spec['info']['description'] = 'Changed contract for write failure';
    $documents['openapi.json'] = $spec;
    $documents['operations-index.json'] = $repository->index($spec);
    $documents['contract-version.json']['contract_sha256'] = $repository->hash($spec);
    $documents['diagnostics.json']['contract_sha256'] = $repository->hash($spec);
    $filesystem = new Filesystem;
    $failed = false;
    File::partialMock()->shouldReceive('replace')->andReturnUsing(function (string $path, string $contents) use ($filesystem, &$failed): void {
        if (! $failed && str_ends_with($path, '/operations-index.json')) {
            $failed = true;
            throw new RuntimeException('Simulated publication failure');
        }
        $filesystem->replace($path, $contents);
    });

    expect(fn () => $repository->publish($this->contractDirectory, $documents))->toThrow(RuntimeException::class, 'Simulated publication failure');
    expect($repository->snapshot($this->contractDirectory)['files'])->toBe($before['files']);
});

test('reproduction detects a self consistent artifact that does not match the actual source', function () {
    $repository = new ContractRepository;
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory])->assertSuccessful();
    $snapshot = $repository->snapshot($this->contractDirectory);
    $documents = array_map(fn (string $json): array => $repository->decode($json), $snapshot['files']);
    $documents['openapi.json']['paths']['/products']['get']['summary'] = 'Outdated documented behavior';
    $documents['operations-index.json'] = $repository->index($documents['openapi.json']);
    $documents['contract-version.json']['contract_sha256'] = $repository->hash($documents['openapi.json']);
    $documents['diagnostics.json']['contract_sha256'] = $repository->hash($documents['openapi.json']);
    $repository->publish($this->contractDirectory, $documents);
    $candidate = $this->contractDirectory.'/candidate';

    $this->artisan('api:contracts', ['action' => 'check', '--directory' => $this->contractDirectory])->assertSuccessful();
    $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $candidate])->assertSuccessful();

    expect($repository->snapshot($candidate)['version']['contract_sha256'])->toBe($snapshot['version']['contract_sha256']);
    foreach (['openapi.json', 'operations-index.json', 'diagnostics.json'] as $name) {
        expect(File::get($candidate.'/'.$name))->not->toBe(File::get($this->contractDirectory.'/'.$name));
    }
});

test('the writer lock prevents concurrent generation before altering any artifact', function () {
    File::ensureDirectoryExists($this->contractDirectory);
    $lock = fopen($this->contractDirectory.'/.generate.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $this->contractDirectory])->assertFailed();
        expect(is_file($this->contractDirectory.'/openapi.json'))->toBeFalse();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

class ContractOriginalRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string']];
    }
}

class ContractChangedRequest extends FormRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string'], 'quantity' => ['required', 'integer', 'min:1']];
    }
}

/** @mixin User */
class ContractOriginalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['name' => 'Fixture'];
    }
}

/** @mixin User */
class ContractChangedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['name' => 'Fixture', 'available' => true];
    }
}

class ContractFixtureController
{
    public function original(ContractOriginalRequest $request): ContractOriginalResource
    {
        return new ContractOriginalResource([]);
    }

    public function changed(ContractChangedRequest $request): ContractChangedResource
    {
        return new ContractChangedResource([]);
    }
}
