<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->create('platform.outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable();
            $table->string('aggregate_id', 36)->notNull();
            $table->string('aggregate_type', 100)->notNull();
            $table->string('correlation_id', 36)->notNull();
            $table->uuid('causation_id')->nullable();
            $table->string('event_name', 255)->notNull();
            $table->string('event_version', 20)->notNull()->default('1.0.0');
            $table->jsonb('payload')->notNull();
            $table->jsonb('metadata')->notNull()->default('{}');
            $table->string('status', 20)->notNull()->default('pending');
            $table->smallInteger('attempts')->notNull()->default(0);
            $table->smallInteger('max_attempts')->notNull()->default(5);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('created_at')->notNull()->useCurrent();
            $table->timestamp('delivered_at')->nullable();

            $table->index(['status', 'next_attempt_at'], 'outbox_events_dispatch_idx');
            $table->index(['aggregate_id', 'event_name'], 'outbox_events_aggregate_idx');
            $table->index(['status', 'processing_started_at'], 'outbox_events_stuck_idx');
            $table->index('correlation_id', 'outbox_events_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('platform.outbox_events');
    }
};
