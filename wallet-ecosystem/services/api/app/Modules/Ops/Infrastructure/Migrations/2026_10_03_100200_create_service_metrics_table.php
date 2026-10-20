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
        Schema::connection('platform')->create('ops.service_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('service_name', 100);
            $table->string('metric_name', 100);
            $table->decimal('value', 18, 4);
            $table->string('unit', 50);
            $table->string('granularity', 10)->default('raw');
            $table->timestampTz('recorded_at');
            $table->timestampTz('created_at')->default(DB::raw('now()'));
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_service_metrics_lookup ON ops.service_metrics (service_name, metric_name, granularity, recorded_at DESC)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX idx_service_metrics_time ON ops.service_metrics (recorded_at DESC)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('ops.service_metrics');
    }
};
