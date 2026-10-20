<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk.risk_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->bigIncrements('alert_number')->unique();
            $table->string('alert_type', 100);
            $table->string('status', 50)->default('open');
            $table->string('severity', 20);
            $table->string('subject_type', 30);
            $table->uuid('subject_id')->index();
            $table->string('title', 255);
            $table->text('description');
            $table->uuid('assigned_to')->nullable();
            $table->string('correlation_id', 128)->nullable()->index();
            $table->timestampTz('sla_due_at');
            $table->timestampTz('escalated_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->uuid('created_by');
            $table->uuid('updated_by');
            $table->integer('version')->default(1);
            $table->timestampsTz();

            $table->index(['status', 'severity', 'sla_due_at']);
            $table->index(['subject_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk.risk_alerts');
    }
};
