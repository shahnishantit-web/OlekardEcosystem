<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Modules\Events\Domain\Permissions\EventPermissions;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        $schema = Schema::connection('community_services');
        $schema->create('community_services.event_sponsors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id')->index();
            $table->string('name', 200);
            $table->text('description');
            $table->string('logo_url', 2048)->nullable();
            $table->uuid('report_actor_id')->nullable()->index();
            $table->boolean('active')->default(true);
            $table->uuid('created_by');
            $table->timestampsTz();
            $table->unique(['organisation_id', 'name'], 'event_sponsor_org_name_uq');
        });
        $schema->create('community_services.event_sponsor_links', function (Blueprint $table): void {
            $table->uuid('event_id');
            $table->uuid('sponsor_id');
            $table->timestampTz('created_at');
            $table->primary(['event_id', 'sponsor_id']);
            $table->foreign('event_id')->references('id')->on('community_services.event_drafts')->cascadeOnDelete();
            $table->foreign('sponsor_id')->references('id')->on('community_services.event_sponsors')->restrictOnDelete();
        });
        $schema->create('community_services.event_sponsor_audit', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('sponsor_id')->index();
            $table->uuid('event_id')->nullable()->index();
            $table->uuid('actor_id');
            $table->string('action', 40);
            $table->timestampTz('created_at');
        });
        $schema->create('community_services.event_poc_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_event_id')->unique();
            $table->string('source_event_type', 100);
            $table->string('notification_type', 120);
            $table->uuid('recipient_actor_id')->index();
            $table->jsonb('variables');
            $table->string('status', 32)->default('queued');
            $table->timestampTz('simulated_at')->nullable();
            $table->timestampTz('created_at');
        });
        $schema->create('community_services.event_poc_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->index();
            $table->uuid('organisation_id')->index();
            $table->uuid('requested_by_actor_id');
            $table->uuid('approved_by_actor_id')->nullable();
            $table->string('approve_idempotency_key', 128)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 32)->default('pending_approval');
            $table->string('poc_reference', 80)->unique();
            $table->string('idempotency_key', 128);
            $table->timestampTz('requested_at');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['event_id', 'requested_by_actor_id', 'idempotency_key'], 'event_poc_settlement_idempotency_uq');
        });
        $schema->create('community_services.event_poc_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->index();
            $table->uuid('cancellation_request_id')->index();
            $table->uuid('order_id')->unique();
            $table->uuid('payment_intent_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('status', 32)->default('simulated_refunded');
            $table->string('poc_reference', 80)->unique();
            $table->timestampTz('simulated_at');
            $table->timestampsTz();
        });
        DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
            'id' => (string) \Ramsey\Uuid\Uuid::uuid7(), 'slug' => EventPermissions::PAYOUT_APPROVE,
            'display_name' => 'Approve event POC settlement', 'module' => 'events', 'is_system_permission' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $schema = Schema::connection('community_services');
        $schema->dropIfExists('community_services.event_sponsor_links');
        $schema->dropIfExists('community_services.event_sponsor_audit');
        $schema->dropIfExists('community_services.event_sponsors');
        $schema->dropIfExists('community_services.event_poc_notifications');
        $schema->dropIfExists('community_services.event_poc_settlements');
        $schema->dropIfExists('community_services.event_poc_refunds');
        $permissionId = DB::connection('identity')->table('identity.permissions')->where('slug', EventPermissions::PAYOUT_APPROVE)->value('id');
        if ($permissionId !== null && ! DB::connection('identity')->table('identity.role_permissions')->where('permission_id', $permissionId)->exists()) {
            DB::connection('identity')->table('identity.permissions')->where('id', $permissionId)->delete();
        }
    }
};
