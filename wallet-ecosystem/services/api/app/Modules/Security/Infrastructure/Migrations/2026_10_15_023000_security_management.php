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
        Schema::connection('platform')->table('security.vulnerability_findings', function (Blueprint $t): void {
            $t->decimal('cvss_score', 3, 1)->nullable();
            $t->text('remediation_steps')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('verified_at')->nullable();
        });
        DB::connection('platform')->statement('ALTER TABLE security.vulnerability_findings DROP CONSTRAINT chk_status');
        DB::connection('platform')->statement("ALTER TABLE security.vulnerability_findings ADD CONSTRAINT chk_status CHECK (status IN ('open','acknowledged','in_remediation','suppressed','resolved','verified'))");
        DB::connection('platform')->statement('ALTER TABLE security.vulnerability_findings ADD CONSTRAINT chk_cvss CHECK (cvss_score IS NULL OR (cvss_score>=0 AND cvss_score<=10))');
    }

    public function down(): void
    {
        if (DB::connection('platform')->table('security.vulnerability_findings')->where('status', 'verified')->exists()) {
            throw new RuntimeException('Verified findings prevent downgrade.');
        }DB::connection('platform')->statement('ALTER TABLE security.vulnerability_findings DROP CONSTRAINT chk_cvss');
        DB::connection('platform')->statement('ALTER TABLE security.vulnerability_findings DROP CONSTRAINT chk_status');
        DB::connection('platform')->statement("ALTER TABLE security.vulnerability_findings ADD CONSTRAINT chk_status CHECK (status IN ('open','acknowledged','in_remediation','suppressed','resolved'))");
        Schema::connection('platform')->table('security.vulnerability_findings', fn (Blueprint $t) => $t->dropColumn(['cvss_score', 'remediation_steps', 'version', 'verified_at']));
    }
};
