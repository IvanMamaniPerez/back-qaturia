<?php

namespace Tests;

class PostgresTestCase extends TestCase
{
    /** @var array<string, bool> */
    private static array $migrated = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.default') !== 'pgsql'
            || ! in_array(config('database.connections.pgsql.database'), ['qaturia_variants_test', 'qaturia_live_test'], true)) {
            throw new \RuntimeException('PostgreSQL tests require qaturia_variants_test or qaturia_live_test.');
        }
        $database = config('database.connections.pgsql.database');
        if (! (self::$migrated[$database] ?? false)) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            self::$migrated[$database] = true;
        }
    }
}
