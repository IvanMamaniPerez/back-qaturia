<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /** @var literal-string $p Deployment-controlled schema prefix. */
        $p = config('laravel-crm.db_table_prefix');
        if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $p)) {
            throw new RuntimeException('Invalid CRM table prefix.');
        }
        Schema::table($p.'products', fn (Blueprint $t) => $t->unsignedBigInteger('options_version')->default(0));
        Schema::table($p.'product_variations', function (Blueprint $t) use ($p) {
            $t->string('combination_key', 64)->nullable();
            $t->text('combination_signature')->nullable();
            $t->string('configuration_status')->default('legacy_unmapped');
            $t->boolean('published')->default(true);
            $t->uuid('image_file_id')->nullable();
            $t->unique(['product_id', 'combination_key'], 'matrix_combination_unique');
            $t->unique(['team_id', 'product_id', 'combination_key'], 'matrix_team_combination_unique');
            $t->unique(['product_id', 'id'], 'matrix_variation_product_unique');
            $t->unique(['team_id', 'product_id', 'id'], 'matrix_variation_owner_unique');
            $t->foreign('product_id', 'matrix_variation_product_fk')->references('id')->on($p.'products')->restrictOnDelete();
            $t->foreign(['team_id', 'product_id'], 'matrix_variation_team_fk')->references(['team_id', 'id'])->on($p.'products')->restrictOnDelete();
            $t->foreign('image_file_id', 'matrix_image_fk')->references('id')->on($p.'files')->restrictOnDelete();
            $t->foreign(['team_id', 'image_file_id'], 'matrix_image_team_fk')->references(['team_id', 'id'])->on($p.'files')->restrictOnDelete();
        });
        Schema::create($p.'product_options', function (Blueprint $t) use ($p) {
            $t->uuid('id')->primary();
            $t->uuid('external_id')->unique();
            $t->uuid('team_id')->nullable();
            $t->uuid('product_id');
            $t->string('name');
            $t->string('normalized_name');
            $t->unsignedInteger('position')->default(1);
            $t->boolean('active')->default(true);
            $t->uuid('user_created_id')->nullable();
            $t->uuid('user_updated_id')->nullable();
            $t->timestamps();
            $t->unique(['product_id', 'normalized_name'], 'matrix_option_name_unique');
            $t->unique(['team_id', 'product_id', 'normalized_name'], 'matrix_option_team_name_unique');
            $t->unique(['product_id', 'id'], 'matrix_option_product_unique');
            $t->unique(['team_id', 'product_id', 'id'], 'matrix_option_owner_unique');
            $t->foreign('team_id')->references('id')->on('businesses')->restrictOnDelete();
            $t->foreign('product_id')->references('id')->on($p.'products')->restrictOnDelete();
            $t->foreign(['team_id', 'product_id'])->references(['team_id', 'id'])->on($p.'products')->restrictOnDelete();
        });
        Schema::create($p.'product_option_values', function (Blueprint $t) use ($p) {
            $t->uuid('id')->primary();
            $t->uuid('external_id')->unique();
            $t->uuid('team_id')->nullable();
            $t->uuid('product_id');
            $t->uuid('product_option_id');
            $t->string('value');
            $t->string('normalized_value');
            $t->unsignedInteger('position')->default(1);
            $t->boolean('active')->default(true);
            $t->string('swatch_hex', 7)->nullable();
            $t->uuid('user_created_id')->nullable();
            $t->uuid('user_updated_id')->nullable();
            $t->timestamps();
            $t->unique(['product_option_id', 'normalized_value'], 'matrix_value_name_unique');
            $t->unique(['team_id', 'product_option_id', 'normalized_value'], 'matrix_value_team_name_unique');
            $t->unique(['product_id', 'product_option_id', 'id'], 'matrix_value_product_unique');
            $t->unique(['team_id', 'product_id', 'product_option_id', 'id'], 'matrix_value_owner_unique');
            $t->foreign('product_option_id')->references('id')->on($p.'product_options')->restrictOnDelete();
            $t->foreign(['product_id', 'product_option_id'], 'matrix_value_product_fk')->references(['product_id', 'id'])->on($p.'product_options')->restrictOnDelete();
            $t->foreign(['team_id', 'product_id', 'product_option_id'], 'matrix_value_team_fk')->references(['team_id', 'product_id', 'id'])->on($p.'product_options')->restrictOnDelete();
        });
        Schema::create($p.'product_variation_option_values', function (Blueprint $t) use ($p) {
            $t->uuid('id')->primary();
            $t->uuid('team_id')->nullable();
            $t->uuid('product_id');
            $t->uuid('product_variation_id');
            $t->uuid('product_option_id');
            $t->uuid('product_option_value_id');
            $t->unique(['product_variation_id', 'product_option_id'], 'matrix_one_value_per_option');
            $t->foreign(['product_id', 'product_variation_id'], 'matrix_selection_variant_fk')->references(['product_id', 'id'])->on($p.'product_variations')->restrictOnDelete();
            $t->foreign(['team_id', 'product_id', 'product_variation_id'], 'matrix_selection_variant_team_fk')->references(['team_id', 'product_id', 'id'])->on($p.'product_variations')->restrictOnDelete();
            $t->foreign(['product_id', 'product_option_id', 'product_option_value_id'], 'matrix_selection_value_fk')->references(['product_id', 'product_option_id', 'id'])->on($p.'product_option_values')->restrictOnDelete();
            $t->foreign(['team_id', 'product_id', 'product_option_id', 'product_option_value_id'], 'matrix_selection_value_team_fk')->references(['team_id', 'product_id', 'product_option_id', 'id'])->on($p.'product_option_values')->restrictOnDelete();
        });
        Schema::create($p.'product_matrix_operations', function (Blueprint $t) use ($p) {
            $t->uuid('id')->primary();
            $t->uuid('team_id')->nullable();
            $t->uuid('product_id');
            $t->string('operation_key', 100);
            $t->string('request_hash', 64);
            $t->unsignedBigInteger('previous_version');
            $t->uuid('actor_id')->nullable();
            $t->json('request');
            $t->json('result');
            $t->timestamps();
            $t->unique(['product_id', 'operation_key'], 'matrix_operation_unique');
            $t->foreign('product_id')->references('id')->on($p.'products')->restrictOnDelete();
            $t->foreign(['team_id', 'product_id'])->references(['team_id', 'id'])->on($p.'products')->restrictOnDelete();
        });
        foreach (['deal_products', 'quote_products', 'order_products', 'invoice_lines', 'purchase_order_lines'] as $table) {
            Schema::table($p.$table, function (Blueprint $t) {
                $t->text('catalog_snapshot')->nullable();
                $t->string('catalog_snapshot_source')->nullable();
            });
        }
        if (DB::getDriverName() === 'pgsql') {
            // Protect regular case/spacing/NFC duplicates even when a raw writer supplies a forged normalized field.
            DB::unprepared("CREATE UNIQUE INDEX matrix_option_label_unique ON {$p}product_options (product_id, lower(btrim(regexp_replace(normalize(name, NFC), '\\s+', ' ', 'g'))));
                CREATE UNIQUE INDEX matrix_value_label_unique ON {$p}product_option_values (product_option_id, lower(btrim(regexp_replace(normalize(value, NFC), '\\s+', ' ', 'g'))));
                ALTER TABLE {$p}product_variations ADD CONSTRAINT matrix_configuration_status CHECK (configuration_status IN ('legacy_unmapped', 'configured'));");
            DB::unprepared("CREATE OR REPLACE FUNCTION matrix_assert_owner() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE owner_id uuid;
            BEGIN
                SELECT team_id INTO owner_id FROM {$p}products WHERE id = NEW.product_id FOR UPDATE;
                IF NEW.team_id IS DISTINCT FROM owner_id THEN
                    RAISE EXCEPTION 'MATRIX_BUSINESS_MISMATCH' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND (NEW.product_id IS DISTINCT FROM OLD.product_id OR NEW.team_id IS DISTINCT FROM OLD.team_id) THEN
                    RAISE EXCEPTION 'MATRIX_IDENTITY_IMMUTABLE' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END \$\$;
            CREATE OR REPLACE FUNCTION matrix_assert_configuration() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE pid uuid;
            BEGIN
                pid := COALESCE(NEW.product_id, OLD.product_id);
                IF EXISTS (
                    SELECT 1 FROM {$p}product_variations v WHERE v.product_id = pid AND v.configuration_status = 'configured'
                    AND (v.combination_key IS NULL OR v.combination_signature IS NULL
                        OR (SELECT count(*) FROM {$p}product_options o WHERE o.product_id = pid) = 0
                        OR (SELECT count(*) FROM {$p}product_variation_option_values s WHERE s.product_variation_id = v.id)
                            <> (SELECT count(*) FROM {$p}product_options o WHERE o.product_id = pid))
                ) THEN
                    RAISE EXCEPTION 'EXACTLY_ONE_VALUE_PER_OPTION_REQUIRED' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END \$\$;");
            foreach (['product_options', 'product_option_values', 'product_variation_option_values', 'product_matrix_operations', 'product_variations'] as $suffix) {
                DB::unprepared("CREATE TRIGGER matrix_owner BEFORE INSERT OR UPDATE ON {$p}{$suffix} FOR EACH ROW EXECUTE FUNCTION matrix_assert_owner();");
            }
            foreach (['product_options', 'product_variation_option_values', 'product_variations'] as $suffix) {
                DB::unprepared("CREATE CONSTRAINT TRIGGER matrix_complete AFTER INSERT OR UPDATE OR DELETE ON {$p}{$suffix} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION matrix_assert_configuration();");
            }
            // Parent row locks also protect raw/legacy inserts, including archived rows.
            DB::unprepared("CREATE OR REPLACE FUNCTION matrix_catalog_limit() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                PERFORM id FROM {$p}products WHERE id = NEW.product_id FOR UPDATE;
                IF TG_TABLE_NAME = '{$p}product_options' THEN
                    IF (SELECT count(*) FROM {$p}product_options WHERE product_id = NEW.product_id) >= 3 THEN
                        RAISE EXCEPTION 'OPTION_LIMIT_EXCEEDED' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF (SELECT count(*) FROM {$p}product_variations WHERE product_id = NEW.product_id) >= 2048 THEN
                        RAISE EXCEPTION 'VARIANT_LIMIT_EXCEEDED' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END \$\$;
            CREATE TRIGGER matrix_option_limit BEFORE INSERT ON {$p}product_options FOR EACH ROW EXECUTE FUNCTION matrix_catalog_limit();
            CREATE TRIGGER matrix_variant_limit BEFORE INSERT ON {$p}product_variations FOR EACH ROW EXECUTE FUNCTION matrix_catalog_limit();");
        }
    }

    public function down(): void
    {
        // An operational rollback must retain identities and snapshots. Roll code back instead.
        throw new RuntimeException('Matrix rollback is non-destructive: retain schema/data and roll back application code.');
    }
};
