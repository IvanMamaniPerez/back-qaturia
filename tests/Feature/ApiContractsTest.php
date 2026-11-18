<?php

use App\Models\User;
use App\Support\ApiContracts\ContractRepository;
use Dedoc\Scramble\Scramble;
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
