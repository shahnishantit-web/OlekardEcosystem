<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'notification';

    public function up(): void
    {
        $schema = Schema::connection('notification');
        $schema->table('notification.notification_templates', function (Blueprint $t): void {
            $t->string('language', 35)->nullable();
            $t->unsignedInteger('revision')->default(1);
        });
        $schema->table('notification.notifications', function (Blueprint $t): void {
            $t->jsonb('rule_channels')->nullable();
        });
        $schema->create('notification.admin_rules', function (Blueprint $t): void {
            $t->uuid('template_id');
            $t->string('channel', 20);
            $t->boolean('enabled');
            $t->integer('revision')->default(1);
            $t->timestampsTz();
            $t->primary(['template_id', 'channel']);
            $t->foreign('template_id')->references('id')->on('notification.notification_templates');
        });
        $schema->create('notification.admin_actions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('actor_id');
            $t->string('action');
            $t->uuid('resource_id');
            $t->string('correlation_id', 128);
            $t->jsonb('metadata');
            $t->timestampTz('created_at');
            $t->index(['resource_id', 'created_at']);
        });
        $schema->create('notification.admin_outbox', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('event_name');
            $t->jsonb('payload');
            $t->timestampTz('created_at');
            $t->timestampTz('delivered_at')->nullable();
            $t->index('delivered_at');
        });
        foreach (['notifications.templates.read', 'notifications.templates.write', 'notifications.log.read', 'notifications.retry', 'notifications.rules.read', 'notifications.rules.write'] as $slug) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore(['id' => (string) Uuid::uuid7(), 'slug' => $slug, 'display_name' => ucwords(str_replace('.', ' ', $slug)), 'module' => 'notification', 'is_system_permission' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('notification');
        foreach (['admin_outbox', 'admin_actions', 'admin_rules'] as $name) {
            $schema->dropIfExists('notification.'.$name);
        }
        $schema->table('notification.notifications', fn (Blueprint $t) => $t->dropColumn('rule_channels'));
        $schema->table('notification.notification_templates', fn (Blueprint $t) => $t->dropColumn(['language', 'revision']));
    }
};
