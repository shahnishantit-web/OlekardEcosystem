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
        Schema::connection('platform')->create('platform.provider_external_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('platform_entity_id')->notNull();
            $table->string('platform_entity_type', 100)->notNull();
            $table->string('provider_key', 100)->notNull();
            $table->string('provider_reference', 500)->nullable();
            $table->timestampTz('reference_confirmed_at')->nullable();
            $table->string('operation_type', 100)->notNull();
            $table->string('reconciliation_state', 20)->notNull()->default('pending');
            $table->text('reconciliation_reason')->nullable();
            $table->string('reconciliation_owner', 255)->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->timestampTz('created_at')->notNull()->useCurrent();

            // Platform entity lookup (E20 reconciliation)
            $table->index(['platform_entity_id', 'platform_entity_type'], 'per_entity_idx');

            // Provider key + reference lookup
            $table->index(['provider_key', 'provider_reference'], 'per_provider_ref_idx');
        });

        // Provider reference uniqueness: excludes NULLs by PostgreSQL default partial index
        DB::connection('platform')->statement(
            'CREATE UNIQUE INDEX per_key_ref_unique
             ON platform.provider_external_references (provider_key, provider_reference)
             WHERE provider_reference IS NOT NULL'
        );

        // Speculative record idempotency (FR-E1000-026): prevents duplicate PENDING records for the
        // same logical operation. createSpeculative() returns the existing record on conflict.
        DB::connection('platform')->statement(
            "CREATE UNIQUE INDEX per_speculative_unique
             ON platform.provider_external_references (platform_entity_id, platform_entity_type, provider_key, operation_type)
             WHERE reconciliation_state = 'PENDING'"
        );

        // Pending record timeout detection
        DB::connection('platform')->statement(
            "CREATE INDEX per_pending_created_idx
             ON platform.provider_external_references (reconciliation_state, created_at)
             WHERE reconciliation_state = 'PENDING'"
        );

        // Exception review queue
        DB::connection('platform')->statement(
            "CREATE INDEX per_exception_idx
             ON platform.provider_external_references (reconciliation_state)
             WHERE reconciliation_state = 'EXCEPTION'"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('platform.provider_external_references');
    }
};
