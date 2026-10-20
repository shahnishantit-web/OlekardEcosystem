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
        Schema::connection('platform')->create('ops.health_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('service_name', 100);
            $table->string('overall_status', 20);
            $table->jsonb('components')->default('[]');
            $table->timestampTz('checked_at')->default(DB::raw('now()'));
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_health_snapshots_service_time ON ops.health_snapshots (service_name, checked_at DESC)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('ops.health_snapshots');
    }
};
