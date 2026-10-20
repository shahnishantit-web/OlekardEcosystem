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
        $s = Schema::connection('platform');
        $s->table('integration.api_clients', function (Blueprint $t): void {
            $t->uuid('integration_id')->nullable();
            $t->jsonb('ip_whitelist')->default('[]');
            $t->timestampTz('revoked_at')->nullable();
            $t->foreign('integration_id')->references('id')->on('integration.integrations');
        });
        $s->table('integration.webhook_events', fn (Blueprint $t) => $t->integer('processing_generation')->default(0));
        $s->table('ops.provider_health_checks', function (Blueprint $t): void {
            $t->uuid('integration_id')->nullable();
            $t->index(['integration_id', 'checked_at']);
        });
        $s->create('integration.admin_retry_intents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('webhook_event_id');
            $t->timestampTz('created_at');
            $t->timestampTz('dispatched_at')->nullable();
            $t->index('dispatched_at');
            $t->foreign('webhook_event_id')->references('id')->on('integration.webhook_events');
        });
        $s->create('integration.admin_actions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('actor_id');
            $t->string('action');
            $t->uuid('resource_id');
            $t->string('correlation_id', 128);
            $t->jsonb('metadata');
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        $s = Schema::connection('platform');
        $s->dropIfExists('integration.admin_retry_intents');
        $s->dropIfExists('integration.admin_actions');
        $s->table('ops.provider_health_checks', function (Blueprint $t): void {
            $t->dropIndex(['integration_id', 'checked_at']);
            $t->dropColumn('integration_id');
        });
        $s->table('integration.webhook_events', fn (Blueprint $t) => $t->dropColumn('processing_generation'));
        $s->table('integration.api_clients', function (Blueprint $t): void {
            $t->dropForeign(['integration_id']);
            $t->dropColumn(['integration_id', 'ip_whitelist', 'revoked_at']);
        });
    }
};
