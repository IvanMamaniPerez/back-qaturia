<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLaravelCrmCustomersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable(config('laravel-crm.db_table_prefix').'customers')) {
            return;
        }

        Schema::create(config('laravel-crm.db_table_prefix').'customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->uuid('team_id')->index()->nullable();
            $table->uuidMorphs('customerable');
            $table->uuid('user_created_id')->nullable();
            $table->foreign('user_created_id')->references('id')->on('users');
            $table->uuid('user_updated_id')->nullable();
            $table->foreign('user_updated_id')->references('id')->on('users');
            $table->uuid('user_deleted_id')->nullable();
            $table->foreign('user_deleted_id')->references('id')->on('users');
            $table->uuid('user_restored_id')->nullable();
            $table->foreign('user_restored_id')->references('id')->on('users');
            $table->uuid('user_owner_id')->nullable();
            $table->foreign('user_owner_id')->references('id')->on('users');
            $table->uuid('user_assigned_id')->nullable();
            $table->foreign('user_assigned_id')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'customers');
    }
}
