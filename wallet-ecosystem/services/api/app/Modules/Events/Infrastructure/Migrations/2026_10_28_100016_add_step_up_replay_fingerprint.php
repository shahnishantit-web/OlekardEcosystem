<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'community_services';

    public function up(): void
    {
        Schema::connection('community_services')->table('community_services.event_ticket_security_audit', static function (Blueprint $table): void {
            $table->char('step_up_token_fingerprint', 64)->nullable();
            $table->unique(['ticket_id', 'step_up_token_fingerprint'], 'event_ticket_step_up_fingerprint_uq');
        });
    }

    public function down(): void
    {
        Schema::connection('community_services')->table('community_services.event_ticket_security_audit', static function (Blueprint $table): void {
            $table->dropUnique('event_ticket_step_up_fingerprint_uq');
            $table->dropColumn('step_up_token_fingerprint');
        });
    }
};
