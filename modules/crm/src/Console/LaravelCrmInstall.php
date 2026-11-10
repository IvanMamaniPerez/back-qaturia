<?php

namespace VentureDrake\LaravelCrm\Console;

use Illuminate\Console\Command;

class LaravelCrmInstall extends Command
{
    protected $signature = 'laravelcrm:install
                           {--force : Run without confirmation, for automated environments}';

    protected $description = 'Bootstrap the embedded Laravel CRM without overwriting host files';

    public function handle(): int
    {
        $this->info('Laravel CRM is embedded in this application; host configuration and migrations will not be published.');

        return $this->call('laravelcrm:update', [
            '--force' => (bool) $this->option('force'),
        ]);
    }
}
