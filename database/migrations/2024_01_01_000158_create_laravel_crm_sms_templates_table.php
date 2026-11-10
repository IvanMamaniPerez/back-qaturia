<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLaravelCrmSmsTemplatesTable extends Migration
{
    public function up()
    {
        Schema::create(config('laravel-crm.db_table_prefix').'sms_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->uuid('team_id')->index()->nullable();
            $table->string('name');
            $table->text('body');
            $table->boolean('is_system')->default(false);
            $table->uuid('user_created_id')->nullable();
            $table->uuid('user_updated_id')->nullable();
            $table->uuid('user_deleted_id')->nullable();
            $table->uuid('user_restored_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'sms_templates');
    }
}
