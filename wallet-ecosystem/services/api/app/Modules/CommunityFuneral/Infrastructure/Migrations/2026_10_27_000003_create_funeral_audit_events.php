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
        Schema::connection('community_services')->create('funeral_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action', 80);
            $table->uuid('actor_id')->nullable();
            $table->string('resource_type', 32);
            $table->uuid('resource_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->timestampTz('occurred_at');
            $table->index(['resource_type', 'resource_id', 'occurred_at'], 'funeral_audit_resource_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('community_services')->dropIfExists('funeral_audit_events');
    }
};
