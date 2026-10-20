<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('risk');
        $schema->create('risk.alert_notes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('alert_id');
            $t->uuid('actor_id');
            $t->text('note');
            $t->timestampTz('created_at');
            $t->foreign('alert_id')->references('id')->on('risk.risk_alerts');
            $t->index(['alert_id', 'created_at']);
        });
        $schema->create('risk.alert_links', function (Blueprint $t): void {
            $t->uuid('alert_id');
            $t->uuid('related_alert_id');
            $t->uuid('actor_id');
            $t->timestampTz('created_at');
            $t->primary(['alert_id', 'related_alert_id']);
            $t->foreign('alert_id')->references('id')->on('risk.risk_alerts');
            $t->foreign('related_alert_id')->references('id')->on('risk.risk_alerts');
        });
        $schema->create('risk.alert_freeze_requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('alert_id');
            $t->uuid('maker_id');
            $t->text('reason');
            $t->uuid('checker_id')->nullable();
            $t->string('status')->default('pending');
            $t->uuid('hold_id')->nullable();
            $t->timestampsTz();
            $t->foreign('alert_id')->references('id')->on('risk.risk_alerts');
            $t->index(['alert_id', 'status']);
        });
        $schema->create('risk.investigation_actions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('alert_id');
            $t->uuid('actor_id');
            $t->string('action');
            $t->string('correlation_id', 128);
            $t->jsonb('metadata');
            $t->timestampTz('created_at');
            $t->foreign('alert_id')->references('id')->on('risk.risk_alerts');
            $t->index(['alert_id', 'created_at']);
        });
        $schema->create('risk.investigation_outbox', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('event_name');
            $t->jsonb('payload');
            $t->timestampTz('created_at');
            $t->timestampTz('delivered_at')->nullable();
            $t->index('delivered_at');
        });
        DB::connection('risk')->statement('ALTER TABLE risk.alert_links ADD CONSTRAINT chk_not_self CHECK (alert_id <> related_alert_id)');
        DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
            'id' => (string) Uuid::uuid7(), 'slug' => 'security.alerts.read', 'display_name' => 'View risk alert investigations',
            'module' => 'security', 'is_system_permission' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (['investigation_outbox', 'investigation_actions', 'alert_freeze_requests', 'alert_links', 'alert_notes'] as $t) {
            Schema::connection('risk')->dropIfExists('risk.'.$t);
        }
    }
};
