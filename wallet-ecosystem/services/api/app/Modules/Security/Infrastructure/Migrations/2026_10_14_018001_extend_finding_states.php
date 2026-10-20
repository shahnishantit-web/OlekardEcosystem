<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection('platform')->statement('ALTER TABLE security.vulnerability_findings DROP CONSTRAINT chk_status');
        DB::connection('platform')->statement("ALTER TABLE security.vulnerability_findings ADD CONSTRAINT chk_status CHECK (status IN ('open','acknowledged','in_remediation','suppressed','resolved'))");
    }

    public function down(): void
    {
        // Refuse destructive downgrade while additive statuses exist.
        if (DB::connection('platform')->table('security.vulnerability_findings')->whereIn('status', ['acknowledged', 'in_remediation'])->exists()) {
            throw new RuntimeException('Resolve additive finding states before downgrade.');
        }
        DB::connection('platform')->statement('ALTER TABLE security.vulnerability_findings DROP CONSTRAINT chk_status');
        DB::connection('platform')->statement("ALTER TABLE security.vulnerability_findings ADD CONSTRAINT chk_status CHECK (status IN ('open','suppressed','resolved'))");
    }
};
