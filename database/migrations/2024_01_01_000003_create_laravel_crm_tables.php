<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLaravelCrmTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('laravel-crm.db_table_prefix').'settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('value');
            $table->timestamps();
        });
        Schema::create(config('laravel-crm.db_table_prefix').'organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->uuid('user_created_id')->nullable();
            $table->foreign('user_created_id')->references('id')->on('users');
            $table->uuid('user_updated_id')->nullable();
            $table->foreign('user_updated_id')->references('id')->on('users');
            $table->uuid('user_deleted_id')->nullable();
            $table->foreign('user_deleted_id')->references('id')->on('users');
            $table->uuid('user_restored_id')->nullable();
            $table->foreign('user_restored_id')->references('id')->on('users');
            $table->foreign('user_owner_id')->references('id')->on('users');
            $table->uuid('user_owner_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create(config('laravel-crm.db_table_prefix').'people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->string('title')->nullable();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('maiden_name')->nullable();
            $table->date('birthday')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->text('description')->nullable();
            $table->uuid('organization_id')->nullable();
            $table->foreign('organization_id')->references('id')->on(config('laravel-crm.db_table_prefix').'organizations');
            $table->uuid('user_created_id')->nullable();
            $table->foreign('user_created_id')->references('id')->on('users');
            $table->uuid('user_updated_id')->nullable();
            $table->foreign('user_updated_id')->references('id')->on('users');
            $table->uuid('user_deleted_id')->nullable();
            $table->foreign('user_deleted_id')->references('id')->on('users');
            $table->uuid('user_restored_id')->nullable();
            $table->foreign('user_restored_id')->references('id')->on('users');
            $table->foreign('user_owner_id')->references('id')->on('users');
            $table->uuid('user_owner_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create(config('laravel-crm.db_table_prefix').'emails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->string('address');
            $table->boolean('primary')->default(false);
            $table->enum('type', ['work', 'home', 'other'])->default('work')->nullable();
            $table->uuidMorphs('emailable');
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
        Schema::create(config('laravel-crm.db_table_prefix').'phones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->string('number');
            $table->boolean('primary')->default(false);
            $table->enum('type', ['work', 'home', 'mobile', 'fax', 'other'])->default('work')->nullable();
            $table->uuidMorphs('phoneable');
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
        Schema::create(config('laravel-crm.db_table_prefix').'addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->string('address')->nullable();
            $table->string('line1')->nullable();
            $table->string('line2')->nullable();
            $table->string('line3')->nullable();
            $table->string('code')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->uuidMorphs('addressable');
            $table->boolean('primary')->default(false);
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
        Schema::create(config('laravel-crm.db_table_prefix').'lead_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->smallInteger('order')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create(config('laravel-crm.db_table_prefix').'lead_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create(config('laravel-crm.db_table_prefix').'leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->uuid('person_id')->index()->nullable();
            $table->foreign('person_id')->references('id')->on(config('laravel-crm.db_table_prefix').'people');
            $table->uuid('organization_id')->index()->nullable();
            $table->foreign('organization_id')->references('id')->on(config('laravel-crm.db_table_prefix').'organizations');
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('amount')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->uuid('lead_status_id')->index()->nullable();
            $table->foreign('lead_status_id')->references('id')->on(config('laravel-crm.db_table_prefix').'lead_statuses');
            $table->uuid('lead_source_id')->index()->nullable();
            $table->foreign('lead_source_id')->references('id')->on(config('laravel-crm.db_table_prefix').'lead_sources');
            $table->boolean('qualified')->default(false);
            $table->datetime('expected_close')->nullable();
            $table->datetime('converted_at')->nullable();
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
        Schema::create(config('laravel-crm.db_table_prefix').'deals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->uuid('lead_id')->index()->nullable();
            $table->foreign('lead_id')->references('id')->on(config('laravel-crm.db_table_prefix').'leads');
            $table->uuid('person_id')->index()->nullable();
            $table->foreign('person_id')->references('id')->on(config('laravel-crm.db_table_prefix').'people');
            $table->uuid('organization_id')->index()->nullable();
            $table->foreign('organization_id')->references('id')->on(config('laravel-crm.db_table_prefix').'organizations');
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('amount')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->boolean('qualified')->default(false);
            $table->datetime('expected_close')->nullable();
            $table->datetime('closed_at')->nullable();
            $table->enum('closed_status', ['won', 'lost'])->nullable();
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
        Schema::create(config('laravel-crm.db_table_prefix').'fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->string('name');
            $table->string('key');
            $table->string('model');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create(config('laravel-crm.db_table_prefix').'field_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->uuid('field_id')->index();
            $table->foreign('field_id')->references('id')->on(config('laravel-crm.db_table_prefix').'fields');
            $table->uuidMorphs('field_valueable');
            $table->text('value');
            $table->timestamps();
        });
        Schema::create(config('laravel-crm.db_table_prefix').'labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->string('name');
            $table->string('hex');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create(config('laravel-crm.db_table_prefix').'labelables', function (Blueprint $table) {
            $table->uuid('label_id');
            $table->uuid(config('laravel-crm.db_table_prefix').'labelable_id');
            $table->string(config('laravel-crm.db_table_prefix').'labelable_type');
        });
        Schema::create('crm_teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('crm_team_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('crm_team_id');
            $table->foreign('crm_team_id')->references('id')->on('crm_teams');
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users');
            $table->timestamps();
            $table->unique(['crm_team_id', 'user_id']);
        });
        Schema::create('crm_team_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('crm_team_id');
            $table->foreign('crm_team_id')->references('id')->on('crm_teams');
            $table->string('email');
            $table->timestamps();
            $table->unique(['crm_team_id', 'email']);
        });
        Schema::create(config('laravel-crm.db_table_prefix').'activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->string('log_name')->default('default');
            $table->string('description')->nullable();
            $table->nullableUuidMorphs('causeable');
            $table->nullableUuidMorphs('timelineable');
            $table->nullableUuidMorphs('recordable');
            $table->string('event')->nullable();
            $table->json('properties')->nullable();
            $table->json('modified')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('url')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
        Schema::create(config('laravel-crm.db_table_prefix').'notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_id');
            $table->text('content');
            $table->uuidMorphs('noteable');
            $table->boolean('pinned')->default(false);
            $table->uuid('user_created_id')->nullable();
            $table->foreign('user_created_id')->references('id')->on('users');
            $table->uuid('user_updated_id')->nullable();
            $table->foreign('user_updated_id')->references('id')->on('users');
            $table->uuid('user_deleted_id')->nullable();
            $table->foreign('user_deleted_id')->references('id')->on('users');
            $table->uuid('user_restored_id')->nullable();
            $table->foreign('user_restored_id')->references('id')->on('users');
            $table->softDeletes();
            $table->timestamps();
        });
        if (! Schema::hasColumn('users', 'crm_access')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('crm_access')->default(false);
            });
        }
        if (! Schema::hasColumn('users', 'last_online_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_online_at')->nullable();
            });
        }
        if (! Schema::hasColumn('users', 'current_crm_team_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->uuid('current_crm_team_id')->nullable()->index();
                $table->foreign('current_crm_team_id')->references('id')->on('crm_teams');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'settings');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'leads');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'lead_statuses');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'lead_sources');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'people');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'organizations');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'emails');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'phones');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'addresses');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'fields');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'field_values');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'deals');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'labels');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'labelables');
        Schema::dropIfExists('crm_teams');
        Schema::dropIfExists('crm_team_user');
        Schema::dropIfExists('crm_team_invitations');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'activities');
        Schema::dropIfExists(config('laravel-crm.db_table_prefix').'notes');
    }
}
