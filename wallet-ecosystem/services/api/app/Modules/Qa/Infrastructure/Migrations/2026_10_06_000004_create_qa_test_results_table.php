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
        Schema::connection('platform')->create('qa.test_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('test_run_id');
            $table->uuid('test_case_id');
            $table->string('outcome', 20);
            $table->integer('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestampTz('created_at')->default(DB::raw('now()'));

            $table->foreign('test_run_id')
                  ->references('id')
                  ->on('qa.test_runs')
                  ->cascadeOnDelete();

            $table->foreign('test_case_id')
                  ->references('id')
                  ->on('qa.test_cases');

            $table->unique(['test_run_id', 'test_case_id'], 'uq_qa_test_results_run_case');
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_qa_test_results_run_id ON qa.test_results (test_run_id)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX idx_qa_test_results_outcome ON qa.test_results (test_run_id, outcome)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('qa.test_results');
    }
};
