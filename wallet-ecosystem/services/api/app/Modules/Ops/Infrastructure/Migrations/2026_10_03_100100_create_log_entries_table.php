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
        Schema::connection('platform')->create('ops.log_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('service_name', 100);
            $table->uuid('correlation_id');
            $table->string('severity', 20);
            $table->text('message');
            $table->jsonb('context')->default('{}');
            $table->timestampTz('created_at')->default(DB::raw('now()'));
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_log_entries_correlation_id ON ops.log_entries (correlation_id)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX idx_log_entries_severity_time ON ops.log_entries (severity, created_at DESC)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX idx_log_entries_service_time ON ops.log_entries (service_name, created_at DESC)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX idx_log_entries_context_gin ON ops.log_entries USING GIN (context)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('ops.log_entries');
    }
};
