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
        Schema::connection('payment')->create('provider_statements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_code', 50);
            $table->string('account_id', 100);
            $table->string('environment', 20);
            $table->char('currency_code', 3);
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->char('source_hash', 64);
            $table->string('status', 30)->default('pending_match');
            $table->integer('total_lines')->default(0);
            $table->integer('matched_lines')->default(0);
            $table->integer('exception_lines')->default(0);
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('ingested_at');
            $table->timestampTz('matching_completed_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            'CREATE UNIQUE INDEX provider_statements_source_hash_idx
             ON payment.provider_statements (source_hash)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX provider_statements_provider_period_idx
             ON payment.provider_statements (provider_code, period_start, period_end)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX provider_statements_status_idx
             ON payment.provider_statements (status)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX provider_statements_created_at_idx
             ON payment.provider_statements (created_at)'
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.provider_statements
             ADD CONSTRAINT provider_statements_status_check
             CHECK (status IN ('pending_match','matching','matched','partially_matched','exception_raised','closed'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.provider_statements
             ADD CONSTRAINT provider_statements_environment_check
             CHECK (environment IN ('production','sandbox'))"
        );
    }

    public function down(): void
    {
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.provider_statements_source_hash_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.provider_statements_provider_period_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.provider_statements_status_idx'
        );
        DB::connection('payment')->statement(
            'DROP INDEX IF EXISTS payment.provider_statements_created_at_idx'
        );
        Schema::connection('payment')->dropIfExists('provider_statements');
    }
};
