<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Services\ProductService;
use VentureDrake\LaravelCrm\Services\ProductVariantMatrixService;

// Separate PHP process: no inherited PDO connection, no access to another database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'qaturia_variants_test', 'laravel-crm.teams' => false]);
DB::purge('pgsql');
if (! $app->environment('testing')) {
    throw new RuntimeException('Testing environment required.');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$product = Product::where('external_id', $input['product'])->firstOrFail();
echo "ready\n";
flush();
try {
    if ($input['mode'] === 'apply') {
        $result = app(ProductVariantMatrixService::class)->apply(
            $product, $input['command'], $input['version'], $input['hash'], $input['key']
        );
        echo json_encode(['status' => 200, 'result' => $result], JSON_THROW_ON_ERROR);
    } elseif ($input['mode'] === 'reorder') {
        app(ProductVariantMatrixService::class)->patchOption($product, $input['option'], ['position' => 2, 'options_version' => $input['version']]);
        echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
    } elseif ($input['mode'] === 'insert') {
        app(ProductService::class)->createVariation($product, ['name' => 'Concurrent']);
        echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
    }
} catch (HttpException $e) {
    echo json_encode(['status' => $e->getStatusCode(), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (ValidationException $e) {
    echo json_encode(['status' => 422, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
