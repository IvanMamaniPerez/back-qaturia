<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use VentureDrake\LaravelCrm\Support\BusinessContext;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if ((str_starts_with($name, config('laravel-crm.db_table_prefix')) || $name === 'xero_tokens')
                && Schema::hasColumn($name, 'team_id')) {
                $tables[$name] = Schema::getForeignKeys($name);
                Schema::table($name, function (Blueprint $table) use ($name) {
                    $table->unique(['team_id', 'id'], 'biz_'.substr(md5($name), 0, 16).'_identity');
                    $table->foreign('team_id', 'biz_'.substr(md5($name), 0, 16).'_owner')
                        ->references('id')->on('businesses')->restrictOnDelete();
                });
            }
        }
        foreach ($tables as $name => $foreignKeys) {
            foreach ($foreignKeys as $foreign) {
                if (count($foreign['columns']) !== 1 || ! isset($tables[$foreign['foreign_table']])
                    || BusinessContext::shared($foreign['foreign_table'])) {
                    continue;
                }
                $column = $foreign['columns'][0];
                if ($column === 'team_id' || $foreign['foreign_columns'] !== ['id']) {
                    continue;
                }
                Schema::table($name, function (Blueprint $table) use ($name, $foreign, $column) {
                    $table->foreign(['team_id', $column], 'biz_'.substr(md5($name.$column), 0, 20).'_link')
                        ->references(['team_id', 'id'])->on($foreign['foreign_table'])->restrictOnDelete()->deferrable();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = array_filter(Schema::getTables(), fn ($table) => str_starts_with($table['name'], config('laravel-crm.db_table_prefix')) || $table['name'] === 'xero_tokens');
        foreach ($tables as $table) {
            $name = $table['name'];
            foreach (Schema::getForeignKeys($name) as $foreign) {
                if (str_starts_with($foreign['name'] ?? '', 'biz_') && str_ends_with($foreign['name'], '_link')) {
                    Schema::table($name, fn (Blueprint $table) => $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite' ? $foreign['columns'] : $foreign['name']));
                }
            }
        }
        foreach ($tables as $table) {
            $name = $table['name'];
            if (Schema::hasColumn($name, 'team_id')) {
                Schema::table($name, function (Blueprint $table) use ($name) {
                    $table->dropForeign(Schema::getConnection()->getDriverName() === 'sqlite' ? ['team_id'] : 'biz_'.substr(md5($name), 0, 16).'_owner');
                    $table->dropUnique('biz_'.substr(md5($name), 0, 16).'_identity');
                });
            }
        }
    }
};
