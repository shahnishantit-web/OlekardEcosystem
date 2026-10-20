<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'risk';

    public function up(): void
    {
        // Relax constraint to allow 0.0000 (unknown amount at draft creation time)
        DB::connection('risk')->statement(
            'ALTER TABLE risk.suspicious_activity_reports DROP CONSTRAINT IF EXISTS chk_sar_amount_positive'
        );
        DB::connection('risk')->statement(
            'ALTER TABLE risk.suspicious_activity_reports ADD CONSTRAINT chk_sar_amount_non_negative CHECK (amount_involved >= 0)'
        );
    }

    public function down(): void
    {
        DB::connection('risk')->statement(
            'ALTER TABLE risk.suspicious_activity_reports DROP CONSTRAINT IF EXISTS chk_sar_amount_non_negative'
        );
        DB::connection('risk')->statement(
            'ALTER TABLE risk.suspicious_activity_reports ADD CONSTRAINT chk_sar_amount_positive CHECK (amount_involved > 0)'
        );
    }
};
