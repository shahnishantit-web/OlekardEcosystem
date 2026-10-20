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
        Schema::connection('platform')->create('platform.configuration_audit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('configuration_value_id')->notNull();
            $table->string('action', 50)->notNull();
            $table->uuid('actor_id')->nullable();
            $table->string('correlation_id', 255)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('occurred_at')->notNull()->useCurrent();

            // FK within the same schema is permitted (no cross-schema FK)
            $table->foreign('configuration_value_id')
                ->references('id')
                ->on('platform.configuration_values');

            $table->index('configuration_value_id', 'configuration_audit_value_id_idx');
            $table->index('occurred_at', 'configuration_audit_occurred_at_idx');
        });

        // Enforce immutability at DB layer — DBA must grant back if needed for migration rollback
        DB::connection('platform')->statement(
            'REVOKE UPDATE, DELETE ON platform.configuration_audit FROM wallet_app'
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'GRANT UPDATE, DELETE ON platform.configuration_audit TO wallet_app'
        );

        Schema::connection('platform')->dropIfExists('platform.configuration_audit');
    }
};
