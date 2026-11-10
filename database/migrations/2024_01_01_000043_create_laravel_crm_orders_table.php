<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLaravelCrmOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('laravel-crm.db_table_prefix').'orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->uuid('team_id')->index()->nullable();
            $table->uuid('lead_id')->index()->nullable();
            $table->foreign('lead_id')->references('id')->on(config('laravel-crm.db_table_prefix').'leads');
            $table->uuid('deal_id')->index()->nullable();
            $table->foreign('deal_id')->references('id')->on(config('laravel-crm.db_table_prefix').'deals');
            $table->uuid('quote_id')->index()->nullable();
            $table->foreign('quote_id')->references('id')->on(config('laravel-crm.db_table_prefix').'quotes');
            $table->uuid('person_id')->index()->nullable();
            $table->foreign('person_id')->references('id')->on(config('laravel-crm.db_table_prefix').'people');
            $table->uuid('organization_id')->index()->nullable();
            $table->foreign('organization_id')->references('id')->on(config('laravel-crm.db_table_prefix').'organizations');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->integer('subtotal')->nullable();
            $table->integer('discount')->nullable();
            $table->integer('tax')->nullable();
            $table->integer('adjustments')->nullable();
            $table->integer('total')->nullable();
            $table->text('terms')->nullable();
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
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'orders');
    }
}
