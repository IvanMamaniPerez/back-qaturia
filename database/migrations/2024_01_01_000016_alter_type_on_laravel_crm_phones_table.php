<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AlterTypeOnLaravelCrmPhonesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE '.config('laravel-crm.db_table_prefix')."phones CHANGE COLUMN type type ENUM('work','home','mobile','fax','other') NOT NULL DEFAULT 'work'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE '.config('laravel-crm.db_table_prefix')."phones CHANGE COLUMN type type ENUM('work','home','mobile','other') NOT NULL DEFAULT 'work'");
    }
}
