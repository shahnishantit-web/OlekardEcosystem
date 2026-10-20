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
        Schema::connection('platform')->create('reporting.report_jobs', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('definition_id');
            $table->string('report_type', 64);
            $table->uuid('requested_by_user_id');
            $table->uuid('correlation_id');
            $table->jsonb('filters');
            $table->timestampTz('as_of_at')->nullable();
            $table->string('status', 32)->default('requested');
            $table->boolean('pii_access')->default(false);
            $table->integer('row_count')->nullable();
            $table->jsonb('result_data')->nullable();
            $table->string('failure_reason', 512)->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->foreign('definition_id')
                ->references('id')
                ->on('reporting.report_definitions');

            $table->index(['requested_by_user_id', 'status']);
            $table->index(['report_type', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('reporting.report_jobs');
    }
};
