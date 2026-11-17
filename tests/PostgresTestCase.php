<?php

namespace Tests;

class PostgresTestCase extends TestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'qaturia_variants_test') {
            throw new \RuntimeException('PostgreSQL matrix tests require qaturia_variants_test.');
        }
        if (! self::$migrated) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            self::$migrated = true;
        }
    }
}
