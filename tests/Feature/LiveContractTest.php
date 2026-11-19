<?php

use App\Support\ApiContracts\ContractRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

// Count changes are justified by this exact allowlist, preserving all 77 CRM operations.
test('official artifacts publish eight LIVE operations independently and retain the complete CRM surface', function () {
    $repository = new ContractRepository;
    $snapshot = $repository->snapshot(base_path('contracts'));
    $operations = $repository->operations($snapshot['spec']);
    $live = array_filter(array_keys($operations), fn ($id) => str_starts_with($id, 'live.v1.'));
    sort($live);
    expect($live)->toBe([
        'live.v1.connectors.destroy.delete', 'live.v1.connectors.store.post', 'live.v1.coverage.index.get',
        'live.v1.events.index.get', 'live.v1.events.ingest.post', 'live.v1.sessions.index.get',
        'live.v1.sessions.show.get', 'live.v1.sessions.store.post',
    ]);
    expect(array_filter(array_keys($operations), fn ($id) => str_starts_with($id, 'crm.v2.')))->toHaveCount(77);
    $operation = $repository->operation($snapshot['spec'], 'live.v1.events.ingest.post')['contract'];
    expect($operation['path'])->toBe('/api/v1/live/ingest/batches');
    expect($operation['operation']['servers'])->toBe([['url' => '/']]);
    expect($operation['operation']['tags'])->toBe(['LIVE']);
    $schema = $snapshot['spec']['components']['schemas']['LiveCommentReceivedV1Envelope'];
    expect($schema['properties']['schemaVersion']['enum'])->toBe([1]);
    expect($schema['additionalProperties'])->toBeFalse();
    expect($snapshot['version']['diagnostics_count'])->toBe(44);
});

test('unapproved private routes in the new LIVE domain block official publication', function () {
    $directory = sys_get_temp_dir().'/qat04-private-'.bin2hex(random_bytes(8));
    Route::get('api/v1/live/internal/debug', fn () => ['private' => true])->name('live.private.debug');
    try {
        $this->artisan('api:contracts', ['action' => 'generate', '--directory' => $directory])
            ->expectsOutputToContain('unapproved_candidates')->assertFailed();
        expect(is_file($directory.'/openapi.json'))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});
