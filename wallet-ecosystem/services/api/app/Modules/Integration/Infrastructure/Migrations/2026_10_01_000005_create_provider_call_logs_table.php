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
        Schema::connection('platform')->create('integration.provider_call_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('integration_id');
            $table->uuid('correlation_id')->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->jsonb('request_summary')->nullable();
            $table->integer('response_code')->nullable();
            $table->string('outcome', 16);
            $table->boolean('retry_eligible')->default(false);
            $table->integer('elapsed_ms')->nullable();
            $table->timestampTz('created_at');
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_provider_call_logs_integration ON integration.provider_call_logs (integration_id, created_at)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX idx_provider_call_logs_correlation ON integration.provider_call_logs (correlation_id)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('integration.provider_call_logs');
    }
};
