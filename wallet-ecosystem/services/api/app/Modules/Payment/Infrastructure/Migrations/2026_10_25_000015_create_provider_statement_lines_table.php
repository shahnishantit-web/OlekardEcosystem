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
        Schema::connection('payment')->create('provider_statement_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('statement_id');
            $table->string('line_type', 30);
            $table->string('provider_reference_id', 200);
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('direction', 10);
            $table->timestampTz('settled_at');
            $table->string('match_status', 30)->default('pending');
            $table->string('matched_entity_type', 50)->nullable();
            $table->uuid('matched_entity_id')->nullable();
            $table->uuid('clearing_record_id')->nullable();
            $table->uuid('exception_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            'CREATE INDEX provider_statement_lines_statement_id_idx
             ON payment.provider_statement_lines (statement_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX provider_statement_lines_provider_reference_idx
             ON payment.provider_statement_lines (provider_reference_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX provider_statement_lines_match_status_idx
             ON payment.provider_statement_lines (match_status)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX provider_statement_lines_clearing_record_id_idx
             ON payment.provider_statement_lines (clearing_record_id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX provider_statement_lines_matched_entity_id_idx
             ON payment.provider_statement_lines (matched_entity_id)'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.provider_statement_lines
             ADD CONSTRAINT provider_statement_lines_statement_fk
             FOREIGN KEY (statement_id) REFERENCES payment.provider_statements (id)"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.provider_statement_lines
             ADD CONSTRAINT provider_statement_lines_line_type_check
             CHECK (line_type IN ('capture','fee','refund','reversal','chargeback','internal_transfer','adjustment'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.provider_statement_lines
             ADD CONSTRAINT provider_statement_lines_direction_check
             CHECK (direction IN ('credit','debit'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.provider_statement_lines
             ADD CONSTRAINT provider_statement_lines_match_status_check
             CHECK (match_status IN ('pending','matched','amount_mismatch','no_platform_record','internal_transfer','exception_raised'))"
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.provider_statement_lines_statement_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.provider_statement_lines_provider_reference_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.provider_statement_lines_match_status_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.provider_statement_lines_clearing_record_id_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.provider_statement_lines_matched_entity_id_idx'
        );
        Schema::connection('payment')->dropIfExists('provider_statement_lines');
    }
};
