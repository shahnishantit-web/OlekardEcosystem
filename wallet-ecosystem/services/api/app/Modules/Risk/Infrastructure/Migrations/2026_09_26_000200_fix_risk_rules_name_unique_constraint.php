<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'risk';

    public function up(): void
    {
        // Drop the global unique constraint on name — it prevents historical (inactive) records
        // from sharing the same name when a rule is reconfigured (deactivate old + create new).
        DB::connection('risk')->statement(
            'ALTER TABLE risk.risk_rules DROP CONSTRAINT IF EXISTS risk_risk_rules_name_unique'
        );

        // Partial unique index: only one ACTIVE rule per (name, subject_id) combination.
        DB::connection('risk')->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_risk_rules_active_name
             ON risk.risk_rules (name)
             WHERE is_active = true'
        );
    }

    public function down(): void
    {
        DB::connection('risk')->statement(
            'DROP INDEX IF EXISTS risk.uq_risk_rules_active_name'
        );
        DB::connection('risk')->statement(
            'ALTER TABLE risk.risk_rules ADD CONSTRAINT risk_risk_rules_name_unique UNIQUE (name)'
        );
    }
};
