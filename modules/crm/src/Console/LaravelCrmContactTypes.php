<?php

namespace VentureDrake\LaravelCrm\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmTablesSeeder;
use VentureDrake\LaravelCrm\Support\BusinessContext;

class LaravelCrmContactTypes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laravelcrm:contacttypes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate Laravel CRM Contact Types';

    /**
     * The Composer instance.
     *
     * @var \Illuminate\Foundation\Composer
     */
    protected $composer;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(Composer $composer)
    {
        parent::__construct();
        $this->composer = $composer;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        return app(BusinessContext::class)->system(
            fn () => $this->call('db:seed', ['--class' => LaravelCrmTablesSeeder::class, '--force' => true])
        );
    }
}
