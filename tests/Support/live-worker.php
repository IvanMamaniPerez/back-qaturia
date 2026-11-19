<?php

use App\Models\LiveSession;
use App\Models\User;
use App\Services\LiveIngestionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => 'qaturia_live_test', 'laravel-crm.teams' => false]);
DB::purge('pgsql');
if (! $app->environment('testing')) {
    throw new RuntimeException('Testing environment required.');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$session = LiveSession::findOrFail($input['session']);
$user = User::findOrFail($input['user']);
echo "ready\n";
flush();
echo json_encode(app(LiveIngestionService::class)->ingest($user, $session, $input['events']), JSON_THROW_ON_ERROR);
