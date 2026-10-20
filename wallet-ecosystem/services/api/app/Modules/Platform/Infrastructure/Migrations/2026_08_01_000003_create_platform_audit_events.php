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
        Schema::connection('platform')->create('platform.audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 50)->notNull();
            $table->string('actor_name_masked', 255)->nullable();
            $table->string('action', 100)->notNull();
            $table->string('event_type', 100)->notNull();
            $table->string('resource_type', 100)->notNull();
            $table->string('resource_id', 255)->nullable();
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->string('outcome', 20)->notNull()->default('success');
            $table->jsonb('payload')->notNull()->default('{}');
            $table->jsonb('metadata')->notNull()->default('{}');
            $table->string('correlation_id', 36)->notNull();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at')->notNull()->useCurrent();

            $table->index(['actor_id', 'occurred_at'], 'audit_events_actor_occurred_at_idx');
            $table->index(['resource_type', 'resource_id'], 'audit_events_resource_idx');
            $table->index('action', 'audit_events_action_idx');
            $table->index('event_type', 'audit_events_event_type_idx');
        });

        // Immutability enforcement: app role can only INSERT + SELECT, never UPDATE or DELETE.
        // The SQL role name depends on the deployment environment; documented here as a required
        // post-migration DBA step. The test environment uses a superuser so no REVOKE is needed.
        // DB::statement("REVOKE UPDATE, DELETE ON platform.audit_events FROM wallet_app_role");
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('platform.audit_events');
    }
};
