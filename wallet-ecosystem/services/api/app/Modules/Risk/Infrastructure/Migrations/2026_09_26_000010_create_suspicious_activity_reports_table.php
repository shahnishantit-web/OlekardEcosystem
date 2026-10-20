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
        Schema::create('risk.suspicious_activity_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->bigIncrements('report_number')->unique();
            $table->string('status', 30)->default('draft');
            $table->string('subject_type', 30);
            $table->uuid('subject_id')->index();
            $table->uuid('alert_id');
            $table->uuid('decision_id');
            $table->text('narrative');
            $table->jsonb('suspected_offences');
            $table->jsonb('transaction_references');
            $table->decimal('amount_involved', 19, 4);
            $table->char('currency', 3)->default('BWP');
            $table->date('reporting_period_start');
            $table->date('reporting_period_end');
            $table->uuid('filer_id');
            $table->uuid('approver_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->string('fia_reference', 100)->nullable();
            $table->timestampTz('fia_acknowledged_at')->nullable();
            $table->integer('submission_attempts')->default(0);
            $table->text('last_submission_error')->nullable();
            $table->timestampsTz();

            $table->foreign('alert_id')->references('id')->on('risk.risk_alerts');
            $table->foreign('decision_id')->references('id')->on('risk.risk_decisions');
        });

        DB::statement('ALTER TABLE risk.suspicious_activity_reports ADD CONSTRAINT chk_sar_period CHECK (reporting_period_end >= reporting_period_start)');
        DB::statement('ALTER TABLE risk.suspicious_activity_reports ADD CONSTRAINT chk_sar_amount_positive CHECK (amount_involved > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('risk.suspicious_activity_reports');
    }
};
