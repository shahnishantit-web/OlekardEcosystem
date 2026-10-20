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
        Schema::connection('platform')->create('audit.suspicious_activity_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('report_number', 32)->unique();
            $table->uuid('subject_user_id');
            $table->string('reason_code', 64);
            $table->text('reason_narrative');
            $table->jsonb('flagged_transaction_ids')->default('[]');
            $table->jsonb('subject_identity_snapshot')->nullable();
            $table->jsonb('aml_alert_history')->default('[]');
            $table->jsonb('risk_score_timeline')->default('[]');
            $table->uuid('created_by_actor_id');
            $table->string('idempotency_key', 64)->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('subject_user_id');
            $table->index(['created_by_actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('audit.suspicious_activity_reports');
    }
};
