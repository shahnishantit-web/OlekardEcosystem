<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('platform')->create('platform.dead_letter_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operation_type', 100)->notNullable();
            $table->uuid('source_id')->notNullable();
            $table->jsonb('payload')->notNullable();
            $table->text('failure_reason')->notNullable();
            $table->integer('retry_count')->default(0)->notNullable();
            $table->jsonb('retry_history')->default('[]')->notNullable();
            $table->string('status', 20)->default('pending')->notNullable();
            $table->timestampTz('replayed_at')->nullable();
            $table->uuid('replayed_by')->nullable();
            $table->jsonb('replay_outcome')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->uuid('dismissed_by')->nullable();
            $table->text('dismissal_reason')->nullable();
            $table->string('correlation_id', 100)->nullable();
            $table->timestampTz('created_at')->notNullable();
            $table->timestampTz('expires_at')->notNullable();
        });

        Schema::connection('platform')->getConnection()->statement(
            'CREATE INDEX platform_dead_letter_status_created_at_idx ON platform.dead_letter_items (status, created_at)'
        );
        Schema::connection('platform')->getConnection()->statement(
            'CREATE INDEX platform_dead_letter_operation_type_status_idx ON platform.dead_letter_items (operation_type, status)'
        );
        Schema::connection('platform')->getConnection()->statement(
            'CREATE INDEX platform_dead_letter_source_id_idx ON platform.dead_letter_items (source_id)'
        );
        Schema::connection('platform')->getConnection()->statement(
            'CREATE INDEX platform_dead_letter_expires_at_idx ON platform.dead_letter_items (expires_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('platform.dead_letter_items');
    }
};
