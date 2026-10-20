<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk.risk_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 100);
            $table->string('severity', 20);
            $table->string('subject_type', 30);
            $table->uuid('subject_id')->index();
            $table->string('transaction_reference', 128)->nullable();
            $table->uuid('evaluation_id')->nullable();
            $table->string('correlation_id', 128)->index();
            $table->jsonb('context');
            $table->timestampTz('detected_at');
            $table->timestampTz('created_at');

            $table->index(['subject_id', 'detected_at']);
            $table->index(['event_type', 'detected_at']);
            $table->index(['severity', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk.risk_events');
    }
};
