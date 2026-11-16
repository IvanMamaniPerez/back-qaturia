<?php

namespace Tests;

class BusinessTestCase extends TestCase
{
    public function createApplication()
    {
        $_ENV['LARAVEL_CRM_TEAMS'] = $_SERVER['LARAVEL_CRM_TEAMS'] = 'true';
        putenv('LARAVEL_CRM_TEAMS=true');

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.default');
        $database = config('database.connections.'.$connection.'.database');
        if (! app()->environment('testing') || ! (
            ($connection === 'sqlite' && $database === ':memory:')
            || preg_match('/test|validation/i', (string) $database)
        )) {
            throw new \RuntimeException('Tenancy tests require an isolated testing database.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_ENV['LARAVEL_CRM_TEAMS'] = $_SERVER['LARAVEL_CRM_TEAMS'] = 'false';
        putenv('LARAVEL_CRM_TEAMS=false');
    }
}
