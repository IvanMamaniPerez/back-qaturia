<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_id', 32)->nullable();
            $table->text('fiscal_address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('website_url')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_name')->nullable();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // Nullable only for external portal identities and legacy accounts
            // awaiting explicit adoption. CRM access requires a business.
            $table->foreignUuid('team_id')->nullable()->constrained('businesses')->restrictOnDelete();
            $table->unique(['team_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropUnique(['team_id', 'id']);
            $table->dropColumn('team_id');
        });
        Schema::dropIfExists('businesses');
    }
};
