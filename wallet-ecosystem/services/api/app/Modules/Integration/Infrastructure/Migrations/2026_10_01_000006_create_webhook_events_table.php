<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->create('integration.webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_type', 32);
            $table->string('provider_slug', 64);
            $table->string('idempotency_key', 255);
            $table->string('event_type', 100);
            $table->text('raw_payload');
            $table->string('source_ip_hash', 64)->nullable();
            $table->string('status', 16)->default('received');
            $table->integer('retry_count')->default(0);
            $table->integer('max_retry_count')->default(5);
            $table->text('last_error')->nullable();
            $table->uuid('platform_entity_id')->nullable();
            $table->string('platform_entity_type', 64)->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            'ALTER TABLE integration.webhook_events ADD CONSTRAINT uq_webhook_events_slug_idempotency UNIQUE (provider_slug, idempotency_key)'
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_webhook_events_failed ON integration.webhook_events (status, created_at) WHERE status IN ('failed', 'dead_letter')"
        );

        DB::connection('platform')->statement(
            'CREATE INDEX idx_webhook_events_provider ON integration.webhook_events (provider_slug, status, received_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('integration.webhook_events');
    }
};
