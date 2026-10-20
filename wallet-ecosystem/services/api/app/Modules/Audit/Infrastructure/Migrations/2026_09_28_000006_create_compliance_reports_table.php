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
        Schema::connection('platform')->create('audit.compliance_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('report_type', 32);
            $table->string('status', 16)->default('pending');
            $table->uuid('requested_by_actor_id');
            $table->date('date_range_start');
            $table->date('date_range_end');
            $table->jsonb('filters')->default('{}');
            $table->string('idempotency_key', 64)->unique();
            $table->jsonb('report_data')->nullable();
            // report_file_reference omitted — file export is out of scope for E23 (T056)
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['requested_by_actor_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('audit.compliance_reports');
    }
};
