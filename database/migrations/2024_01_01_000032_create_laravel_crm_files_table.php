<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLaravelCrmFilesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('laravel-crm.db_table_prefix').'files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->uuid('team_id')->index()->nullable();
            $table->uuidMorphs('fileable');
            $table->string('file');
            $table->string('name')->nullable();
            $table->string('title')->nullable();
            $table->string('format')->nullable();
            $table->string('filesize')->nullable();
            $table->string('disk')->default('local');
            $table->uuid('user_created_id')->nullable();
            $table->foreign('user_created_id')->references('id')->on('users');
            $table->uuid('user_updated_id')->nullable();
            $table->foreign('user_updated_id')->references('id')->on('users');
            $table->uuid('user_deleted_id')->nullable();
            $table->foreign('user_deleted_id')->references('id')->on('users');
            $table->uuid('user_restored_id')->nullable();
            $table->foreign('user_restored_id')->references('id')->on('users');
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
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'files');
    }
}
