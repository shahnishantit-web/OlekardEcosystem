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
        Schema::connection('platform')->create('reporting.report_exports', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('job_id');
            $table->string('format', 16);
            $table->string('status', 16)->default('pending');
            $table->string('storage_path', 512)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('download_token', 256)->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('file_retention_expires_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->uuid('created_by_user_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('job_id')
                ->references('id')
                ->on('reporting.report_jobs')
                ->cascadeOnDelete();
        });

        DB::connection('platform')->statement(
            'CREATE INDEX report_exports_job_id_idx ON reporting.report_exports (job_id)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX report_exports_retention_idx ON reporting.report_exports (file_retention_expires_at) WHERE purged_at IS NULL'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX report_exports_token_idx ON reporting.report_exports (download_token) WHERE download_token IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('reporting.report_exports');
    }
};
