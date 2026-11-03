<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    /** This migration touches schemas through multiple named connections; one shared PDO transaction is unavailable. */
    public $withinTransaction = false;

    public function up(): void
    {
        $db = Schema::connection('community_services');
        if (! $db->hasColumn('community_services.event_draft_categories', 'allocation_type')) {
            $db->table('community_services.event_draft_categories', static function (Blueprint $table): void {
                $table->string('allocation_type', 20)->default('paid');
                $table->unsignedInteger('complimentary_quota')->nullable();
                $table->unsignedInteger('max_per_person')->nullable();
            });
        }
        DB::connection('platform')->statement(<<<'SQL'
            DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint
              WHERE conname = 'event_category_allocation_price_ck'
                AND conrelid = 'community_services.event_draft_categories'::regclass) THEN
              ALTER TABLE community_services.event_draft_categories
              ADD CONSTRAINT event_category_allocation_price_ck CHECK (
                (allocation_type = 'paid' AND price_minor > 0 AND complimentary_quota IS NULL AND max_per_person IS NULL)
                OR (allocation_type = 'free' AND price_minor = 0 AND complimentary_quota IS NULL
                    AND max_per_person IS NOT NULL AND max_per_person > 0)
                OR (allocation_type = 'complimentary' AND price_minor = 0
                    AND complimentary_quota IS NOT NULL AND complimentary_quota > 0
                    AND complimentary_quota <= capacity AND max_per_person IS NOT NULL AND max_per_person > 0)
              );
            END IF;
            END $$
            SQL);

        if (! $db->hasTable('community_services.event_invitations')) {
            $db->create('community_services.event_invitations', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('event_id')->index();
                $table->uuid('organisation_id')->index();
                $table->uuid('category_id')->index();
                $table->char('token_hash', 64)->unique();
                $table->text('token_encrypted');
                $table->string('idempotency_key', 128);
                $table->char('request_hash', 64);
                $table->uuid('recipient_actor_id')->index();
                $table->timestampTz('valid_from');
                $table->timestampTz('expires_at')->index();
                $table->unsignedInteger('max_uses');
                $table->unsignedInteger('uses_count')->default(0);
                $table->string('status', 16)->default('active');
                $table->uuid('created_by_actor_id');
                $table->uuid('revoked_by_actor_id')->nullable();
                $table->timestampTz('revoked_at')->nullable();
                $table->timestampsTz();
                $table->foreign('event_id')->references('id')->on('community_services.event_drafts')->restrictOnDelete();
                $table->foreign('category_id')->references('id')->on('community_services.event_draft_categories')->restrictOnDelete();
                $table->unique(['event_id', 'created_by_actor_id', 'idempotency_key'], 'event_invitation_idem_uq');
                $table->index(['event_id', 'category_id', 'status', 'expires_at'], 'event_invite_eligibility_idx');
            });
        }

        if (! $db->hasTable('community_services.event_free_registrations')) {
            $db->create('community_services.event_free_registrations', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('event_id')->index();
                $table->uuid('organisation_id')->index();
                $table->uuid('category_id')->index();
                $table->uuid('invitation_id')->nullable()->index();
                $table->uuid('attendee_actor_id')->index();
                $table->string('entitlement_source', 16);
                $table->string('idempotency_key', 128);
                $table->char('request_hash', 64);
                $table->string('status', 16)->default('registered');
                $table->timestampTz('registered_at');
                $table->timestampsTz();
                $table->unique(['event_id', 'attendee_actor_id', 'idempotency_key'], 'event_free_registration_idem_uq');
                $table->foreign('event_id')->references('id')->on('community_services.event_drafts')->restrictOnDelete();
                $table->foreign('category_id')->references('id')->on('community_services.event_draft_categories')->restrictOnDelete();
                $table->foreign('invitation_id')->references('id')->on('community_services.event_invitations')->restrictOnDelete();
                $table->index(['event_id', 'category_id', 'status', 'registered_at'], 'event_free_registration_count_idx');
            });
        }

        if (! $db->hasTable('community_services.event_invitation_audit')) {
            $db->create('community_services.event_invitation_audit', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('invitation_id')->index();
                $table->uuid('event_id')->index();
                $table->uuid('organisation_id')->index();
                $table->uuid('actor_id');
                $table->string('action', 24);
                $table->uuid('correlation_id')->nullable();
                $table->timestampTz('created_at');
                $table->foreign('invitation_id')->references('id')->on('community_services.event_invitations')->restrictOnDelete();
            });
        }

        DB::connection('platform')->statement(<<<'SQL'
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'event_invitations_event_fk' AND conrelid = 'community_services.event_invitations'::regclass) THEN
                ALTER TABLE community_services.event_invitations ADD CONSTRAINT event_invitations_event_fk
                  FOREIGN KEY (event_id) REFERENCES community_services.event_drafts(id) ON DELETE RESTRICT;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'event_invitations_category_fk' AND conrelid = 'community_services.event_invitations'::regclass) THEN
                ALTER TABLE community_services.event_invitations ADD CONSTRAINT event_invitations_category_fk
                  FOREIGN KEY (category_id) REFERENCES community_services.event_draft_categories(id) ON DELETE RESTRICT;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'event_free_registrations_event_fk' AND conrelid = 'community_services.event_free_registrations'::regclass) THEN
                ALTER TABLE community_services.event_free_registrations ADD CONSTRAINT event_free_registrations_event_fk
                  FOREIGN KEY (event_id) REFERENCES community_services.event_drafts(id) ON DELETE RESTRICT;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'event_free_registrations_category_fk' AND conrelid = 'community_services.event_free_registrations'::regclass) THEN
                ALTER TABLE community_services.event_free_registrations ADD CONSTRAINT event_free_registrations_category_fk
                  FOREIGN KEY (category_id) REFERENCES community_services.event_draft_categories(id) ON DELETE RESTRICT;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'event_free_registrations_invitation_fk' AND conrelid = 'community_services.event_free_registrations'::regclass) THEN
                ALTER TABLE community_services.event_free_registrations ADD CONSTRAINT event_free_registrations_invitation_fk
                  FOREIGN KEY (invitation_id) REFERENCES community_services.event_invitations(id) ON DELETE RESTRICT;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'event_invitation_audit_invitation_fk' AND conrelid = 'community_services.event_invitation_audit'::regclass) THEN
                ALTER TABLE community_services.event_invitation_audit ADD CONSTRAINT event_invitation_audit_invitation_fk
                  FOREIGN KEY (invitation_id) REFERENCES community_services.event_invitations(id) ON DELETE RESTRICT;
              END IF;
            END $$
            SQL);

        if (! $db->hasColumn('community_services.event_tickets', 'entitlement_source')) {
            $db->table('community_services.event_tickets', static function (Blueprint $table): void {
                $table->string('entitlement_source', 16)->default('paid');
                $table->uuid('free_registration_id')->nullable();
            });
        }
        DB::connection('platform')->statement('ALTER TABLE community_services.event_tickets ALTER COLUMN order_id DROP NOT NULL');
        DB::connection('platform')->statement('ALTER TABLE community_services.event_tickets ALTER COLUMN order_line_id DROP NOT NULL');
        DB::connection('platform')->statement('CREATE UNIQUE INDEX IF NOT EXISTS event_ticket_free_registration_uq ON community_services.event_tickets (free_registration_id) WHERE free_registration_id IS NOT NULL');
        DB::connection('platform')->statement(<<<'SQL'
            DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint
              WHERE conname = 'event_ticket_free_registration_fk'
                AND conrelid = 'community_services.event_tickets'::regclass) THEN
              ALTER TABLE community_services.event_tickets
              ADD CONSTRAINT event_ticket_free_registration_fk FOREIGN KEY (free_registration_id)
                REFERENCES community_services.event_free_registrations (id) ON DELETE RESTRICT;
            END IF;
            END $$
            SQL);
        DB::connection('platform')->statement(<<<'SQL'
            DO $$ BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_constraint
              WHERE conname = 'event_ticket_entitlement_source_ck'
                AND conrelid = 'community_services.event_tickets'::regclass) THEN
              ALTER TABLE community_services.event_tickets
              ADD CONSTRAINT event_ticket_entitlement_source_ck CHECK (
                    (entitlement_source = 'paid' AND order_id IS NOT NULL AND order_line_id IS NOT NULL AND free_registration_id IS NULL)
                    OR (entitlement_source IN ('free', 'complimentary') AND order_id IS NULL
                        AND order_line_id IS NULL AND free_registration_id IS NOT NULL)
              );
            END IF;
            END $$
            SQL);
    }

    public function down(): void
    {
        $db = Schema::connection('community_services');
        if ($db->hasColumn('community_services.event_tickets', 'free_registration_id')) {
            if (DB::connection('community_services')->table('community_services.event_tickets')
                ->whereIn('entitlement_source', ['free', 'complimentary'])->exists()) {
                throw new RuntimeException('P26 free tickets exist; migration rollback is blocked to preserve entitlements.');
            }
            DB::connection('platform')->statement('ALTER TABLE community_services.event_tickets DROP CONSTRAINT IF EXISTS event_ticket_entitlement_source_ck');
            DB::connection('platform')->statement('ALTER TABLE community_services.event_tickets DROP CONSTRAINT IF EXISTS event_ticket_free_registration_fk');
            DB::connection('platform')->statement('DROP INDEX IF EXISTS community_services.event_ticket_free_registration_uq');
            $db->table('community_services.event_tickets', static fn (Blueprint $table) => $table->dropColumn(['free_registration_id', 'entitlement_source']));
            DB::connection('platform')->statement('ALTER TABLE community_services.event_tickets ALTER COLUMN order_id SET NOT NULL');
            DB::connection('platform')->statement('ALTER TABLE community_services.event_tickets ALTER COLUMN order_line_id SET NOT NULL');
        }
        $db->dropIfExists('community_services.event_invitation_audit');
        $db->dropIfExists('community_services.event_free_registrations');
        $db->dropIfExists('community_services.event_invitations');
        DB::connection('platform')->statement('ALTER TABLE community_services.event_draft_categories DROP CONSTRAINT IF EXISTS event_category_allocation_price_ck');
        if ($db->hasColumn('community_services.event_draft_categories', 'allocation_type')) {
            $db->table('community_services.event_draft_categories', static fn (Blueprint $table) => $table->dropColumn(['allocation_type', 'complimentary_quota', 'max_per_person']));
        }
    }
};
