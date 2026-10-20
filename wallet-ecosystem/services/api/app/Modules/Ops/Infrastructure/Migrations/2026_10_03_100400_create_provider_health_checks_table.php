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
        Schema::connection('platform')->create('ops.provider_health_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('provider_type', 50);
            $table->string('status', 20)->default('healthy');
            $table->integer('response_time_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('checked_at')->default(DB::raw('now()'));
            $table->timestampTz('created_at')->default(DB::raw('now()'));
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_provider_health_checks_type_at ON ops.provider_health_checks (provider_type, checked_at DESC)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('ops.provider_health_checks');
    }
};
