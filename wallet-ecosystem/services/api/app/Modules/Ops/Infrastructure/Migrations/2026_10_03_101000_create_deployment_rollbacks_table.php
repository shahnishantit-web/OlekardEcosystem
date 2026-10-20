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
        Schema::connection('platform')->create('ops.deployment_rollbacks', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('idempotency_key', 100)->unique();
            $table->string('from_version', 100);
            $table->string('to_version', 100);
            $table->string('status', 30)->default('in_progress');
            $table->string('initiated_by')->nullable();
            $table->string('reason')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_deployment_rollbacks_status ON ops.deployment_rollbacks (status, created_at DESC)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('ops.deployment_rollbacks');
    }
};
