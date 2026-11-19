<?php

namespace App\Console\Commands;

use App\Models\LiveSession;
use App\Services\LiveProjectionService;
use Illuminate\Console\Command;

class RebuildLiveProjections extends Command
{
    protected $signature = 'live:rebuild-projections {session : Internal LIVE session UUID}';

    protected $description = 'Rebuild LIVE projections from immutable source events and observation receipts';

    public function handle(LiveProjectionService $projections): int
    {
        $projections->rebuild(LiveSession::findOrFail($this->argument('session')));
        $this->info('LIVE projections rebuilt.');

        return self::SUCCESS;
    }
}
