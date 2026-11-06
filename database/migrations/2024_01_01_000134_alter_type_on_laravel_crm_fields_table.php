<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AlterTypeOnLaravelCrmFieldsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->replacePostgresConstraint([
                'text',
                'textarea',
                'select',
                'select_multiple',
                'checkbox',
                'checkbox_multiple',
                'radio',
                'date',
            ]);

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE '.config('laravel-crm.db_table_prefix')."fields CHANGE COLUMN type type ENUM('text','textarea','select','select_multiple','checkbox','checkbox_multiple', 'radio','date') NOT NULL DEFAULT 'text'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->replacePostgresConstraint([
                'text',
                'textarea',
                'select',
                'select_multiple',
                'checkbox',
                'radio',
                'date',
            ]);

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE '.config('laravel-crm.db_table_prefix')."fields CHANGE COLUMN type type ENUM('text','textarea','select','select_multiple','checkbox', 'radio','date') NOT NULL DEFAULT 'text'");
    }

    /**
     * @param  list<string>  $values
     */
    private function replacePostgresConstraint(array $values): void
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $tableName = config('laravel-crm.db_table_prefix').'fields';
        $constraintName = $tableName.'_type_check';
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedConstraint = $grammar->wrap($constraintName);
        $wrappedColumn = $grammar->wrap('type');
        $allowedValues = implode(', ', array_map(
            static fn (string $value): string => $connection->getPdo()->quote($value),
            $values,
        ));

        DB::statement("ALTER TABLE {$wrappedTable} DROP CONSTRAINT IF EXISTS {$wrappedConstraint}");
        DB::statement("ALTER TABLE {$wrappedTable} ADD CONSTRAINT {$wrappedConstraint} CHECK ({$wrappedColumn} IN ({$allowedValues}))");
    }
}
