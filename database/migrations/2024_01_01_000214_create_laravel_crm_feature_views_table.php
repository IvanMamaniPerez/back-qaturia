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
        Schema::create(config('laravel-crm.db_table_prefix').'feature_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('feature_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('team_id')->nullable()->index();
            $table->string('ip_hash', 64)->nullable()->index();
            $table->timestamp('viewed_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'feature_views');
    }
};
