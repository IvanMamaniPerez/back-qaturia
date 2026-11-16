<?php

namespace VentureDrake\LaravelCrm\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;
use Spatie\Permission\PermissionRegistrar;
use VentureDrake\LaravelCrm\Database\Seeders\LaravelCrmPermissionsSeeder;

class LaravelCrmPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laravelcrm:permissions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed Laravel CRM roles and permissions';

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
    public function handle(): int
    {
        $this->info('Updating LaravelCRM Permissions...');

        $result = $this->call('db:seed', [
            '--class' => LaravelCrmPermissionsSeeder::class,
            '--force' => true,
        ]);

        if ($result !== self::SUCCESS) {
            return $result;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->info('Global CRM roles and permissions updated.');

        return self::SUCCESS;
    }
}
