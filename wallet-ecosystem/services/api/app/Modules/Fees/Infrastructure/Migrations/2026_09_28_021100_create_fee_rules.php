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
        Schema::connection('platform')->create('fees.fee_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('pricing_version_id')->nullable()->constrained('fees.pricing_versions')->nullOnDelete();
            $table->string('transaction_type', 20);
            $table->string('fee_type', 12);
            $table->string('currency', 3)->default('BWP');
            $table->string('subject_type', 10);
            $table->uuid('subject_id')->nullable();
            $table->decimal('rate', 10, 4)->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->bigInteger('min_fee_minor')->nullable();
            $table->bigInteger('max_fee_minor')->nullable();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to')->nullable();
            $table->string('status', 12)->default('active');
            $table->string('created_by', 36);
            $table->string('idempotency_key', 36)->unique();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE fees.fee_rules ADD CONSTRAINT fee_rules_fee_type_check
             CHECK (fee_type IN ('fixed','percentage'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE fees.fee_rules ADD CONSTRAINT fee_rules_transaction_type_check
             CHECK (transaction_type IN ('transfer','funding','withdrawal','bill_payment','qr_payment','cash_in','cash_out','settlement'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE fees.fee_rules ADD CONSTRAINT fee_rules_subject_type_check
             CHECK (subject_type IN ('global','customer','agent'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE fees.fee_rules ADD CONSTRAINT fee_rules_status_check
             CHECK (status IN ('active','expired','deactivated','superseded'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE fees.fee_rules ADD CONSTRAINT fee_rules_rate_check
             CHECK (rate IS NULL OR (rate > 0 AND rate <= 100))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE fees.fee_rules ADD CONSTRAINT fee_rules_amount_minor_check
             CHECK (amount_minor IS NULL OR amount_minor > 0)"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE fees.fee_rules ADD CONSTRAINT fee_rules_min_max_check
             CHECK (min_fee_minor IS NULL OR max_fee_minor IS NULL OR min_fee_minor <= max_fee_minor)"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE fees.fee_rules ADD CONSTRAINT fee_rules_bounds_percentage_only_check
             CHECK (fee_type = 'percentage' OR (min_fee_minor IS NULL AND max_fee_minor IS NULL))"
        );

        // Partial unique index: only one active rule per (transaction_type, currency, subject_type, subject_id)
        DB::connection('platform')->statement(
            "CREATE UNIQUE INDEX idx_fee_rules_active_unique
             ON fees.fee_rules (transaction_type, currency, subject_type, COALESCE(subject_id::text, ''))
             WHERE status = 'active'"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_fee_rules_global_lookup
             ON fees.fee_rules (transaction_type, currency, subject_type, status, valid_from, valid_to)
             WHERE subject_type = 'global' AND status = 'active'"
        );

        DB::connection('platform')->statement(
            "CREATE INDEX idx_fee_rules_subject_lookup
             ON fees.fee_rules (subject_id, transaction_type, currency, status)
             WHERE subject_id IS NOT NULL AND status = 'active'"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('fees.fee_rules');
    }
};
