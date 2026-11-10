<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('laravel-crm.db_table_prefix').'feature_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->uuid('team_id')->index()->nullable();
            $table->uuid('feature_id')->index();
            $table->uuid('parent_id')->nullable()->index();
            $table->text('body');
            $table->boolean('is_admin_reply')->default(false);
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
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'feature_comments');
    }
};
