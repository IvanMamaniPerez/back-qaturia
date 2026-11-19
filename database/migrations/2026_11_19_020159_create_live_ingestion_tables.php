<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('client_session_id');
            $table->string('platform', 16);
            $table->string('source_room_id', 128);
            $table->string('creator', 255)->nullable();
            $table->string('initial_title')->nullable();
            $table->string('initial_creator')->nullable();
            $table->string('status', 32)->default('initialized');
            $table->string('title')->nullable();
            $table->jsonb('projection')->nullable();
            $table->timestampTz('first_observed_at', 6)->nullable();
            $table->timestampTz('last_observed_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['business_id', 'client_session_id']);
            $table->unique(['business_id', 'id']);
            $table->foreign(['business_id', 'created_by'])->references(['team_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['business_id', 'created_at', 'id']);
        });
        Schema::create('live_connectors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('live_session_id');
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained('devices')->restrictOnDelete();
            $table->uuid('connector_instance_id');
            $table->string('connector_kind', 32);
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->string('observed_state', 32)->default('initialized');
            $table->timestampTz('state_observed_at', 6)->nullable();
            $table->string('state_order', 80)->nullable();
            $table->timestampsTz(6);
            $table->foreign(['business_id', 'live_session_id'])->references(['business_id', 'id'])->on('live_sessions')->restrictOnDelete();
            $table->foreign(['business_id', 'user_id'])->references(['team_id', 'id'])->on('users')->restrictOnDelete();
            $table->unique(['live_session_id', 'connector_instance_id']);
            $table->unique(['business_id', 'live_session_id', 'id'], 'live_connectors_tenant_session_unique');
        });
        Schema::create('live_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('business_id');
            $table->uuid('live_session_id');
            $table->uuid('live_connector_id');
            $table->uuid('event_id');
            $table->string('event_type', 64);
            $table->unsignedSmallInteger('schema_version');
            $table->string('source_event_type', 40);
            $table->string('source_message_id', 128)->nullable();
            $table->string('target_message_id', 128)->nullable();
            $table->string('source_kind', 24);
            $table->string('occurred_at_source', 24)->nullable();
            $table->timestampTz('occurred_at', 6)->nullable();
            $table->timestampTz('observed_at', 6);
            $table->timestampTz('received_at', 6);
            $table->char('canonical_key', 64);
            $table->string('identity_quality', 32);
            $table->string('processing_status', 24)->default('projected');
            $table->jsonb('payload');
            $table->foreign(['business_id', 'live_session_id', 'live_connector_id'], 'live_events_connector_foreign')->references(['business_id', 'live_session_id', 'id'])->on('live_connectors')->restrictOnDelete();
            $table->unique(['live_session_id', 'canonical_key']);
            $table->unique(['business_id', 'event_id']);
            $table->index(['live_session_id', 'event_type', 'observed_at', 'id'], 'live_events_history_index');
            $table->index(['live_session_id', 'source_message_id']);
            $table->index(['live_session_id', 'target_message_id']);
            $table->index(['live_session_id', 'observed_at', 'id']);
        });
        Schema::create('live_event_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('live_connector_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('live_event_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->char('idempotency_hash', 64);
            $table->uuid('connection_epoch');
            $table->unsignedBigInteger('sequence');
            $table->char('fingerprint', 64);
            $table->jsonb('envelope');
            $table->timestampTz('received_at', 6);
            $table->unique(['business_id', 'event_id']);
            $table->unique(['live_connector_id', 'idempotency_hash']);
            $table->unique(['live_connector_id', 'connection_epoch', 'sequence'], 'live_receipts_transport_unique');
        });
        Schema::create('live_comment_states', function (Blueprint $table): void {
            $table->foreignUuid('live_event_id')->primary()->constrained()->restrictOnDelete();
            $table->timestampTz('deleted_at', 6)->nullable();
        });
        Schema::create('live_coverage_intervals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('live_session_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('live_connector_id')->constrained()->restrictOnDelete();
            $table->uuid('connection_epoch');
            $table->timestampTz('started_at', 6);
            $table->timestampTz('last_observed_at', 6);
            $table->timestampTz('ended_at', 6)->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->unique(['live_connector_id', 'connection_epoch']);
            $table->index(['live_session_id', 'started_at', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['live_coverage_intervals', 'live_comment_states', 'live_event_receipts', 'live_events', 'live_connectors', 'live_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
