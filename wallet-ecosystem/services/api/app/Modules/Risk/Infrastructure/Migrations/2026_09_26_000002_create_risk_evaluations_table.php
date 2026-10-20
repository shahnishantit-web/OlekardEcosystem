<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk.risk_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 128)->unique();
            $table->string('transaction_reference', 128)->index();
            $table->string('transaction_type', 64);
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3)->default('BWP');
            $table->uuid('customer_id')->index();
            $table->uuid('wallet_id')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->string('device_id', 128)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->uuid('beneficiary_id')->nullable();
            $table->string('kyc_tier', 20)->nullable();
            $table->decimal('overall_score', 5, 2);
            $table->string('risk_level', 20);
            $table->string('decision', 20);
            $table->integer('evaluation_ms');
            $table->timestampTz('evaluated_at');
            $table->jsonb('rules_applied');
            $table->jsonb('metadata')->nullable();

            $table->index(['customer_id', 'evaluated_at']);
            $table->index(['risk_level', 'evaluated_at']);
        });

        DB::statement('ALTER TABLE risk.risk_evaluations ADD CONSTRAINT chk_score_range CHECK (overall_score >= 0 AND overall_score <= 100)');
        DB::statement('ALTER TABLE risk.risk_evaluations ADD CONSTRAINT chk_eval_ms_positive CHECK (evaluation_ms >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('risk.risk_evaluations');
    }
};
