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
        Schema::connection('platform')->create('qa.test_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('category', 60);
            $table->uuid('triggered_by');
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 255)->unique();
            $table->uuid('correlation_id');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at')->default(DB::raw('now()'));
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_qa_test_runs_category_status ON qa.test_runs (category, status)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX idx_qa_test_runs_triggered_by ON qa.test_runs (triggered_by)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX idx_qa_test_runs_created_at ON qa.test_runs (created_at DESC)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('qa.test_runs');
    }
};
