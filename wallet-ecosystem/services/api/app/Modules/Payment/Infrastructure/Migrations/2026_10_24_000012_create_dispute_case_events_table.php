<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        Schema::connection('payment')->create('dispute_case_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('dispute_case_id');
            $table->string('event_type', 64);
            $table->uuid('actor_id')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->string('correlation_id', 128);
            // No updated_at — append-only; records are NEVER modified after insert
        });

        DB::connection('payment')->statement(
            'CREATE INDEX dispute_case_events_case_idx
             ON payment.dispute_case_events (dispute_case_id, occurred_at)'
        );

        DB::connection('payment')->statement(
            'ALTER TABLE payment.dispute_case_events
             ADD CONSTRAINT dispute_case_events_case_fk
             FOREIGN KEY (dispute_case_id) REFERENCES payment.payment_dispute_cases (id)'
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.dispute_case_events_case_idx'
        );

        Schema::connection('payment')->dropIfExists('dispute_case_events');
    }
};
