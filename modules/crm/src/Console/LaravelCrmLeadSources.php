<?php

namespace VentureDrake\LaravelCrm\Console;

use Illuminate\Console\Command;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmLeadSourcesSeeder;

class LaravelCrmLeadSources extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laravelcrm:lead-sources';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed default Laravel CRM lead sources';

    public function handle(): int
    {
        $this->info('Seeding default LaravelCRM lead sources...');

        return $this->call('db:seed', [
            '--class' => LaravelCrmLeadSourcesSeeder::class,
            '--force' => true,
        ]);
    }
}
