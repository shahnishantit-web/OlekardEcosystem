<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Audit;

use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class AuditImmutabilityFeatureTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.hmac_key' => 'test-hmac-key-immut-feature']);
    }

    public function test_no_patch_route_exists_for_audit_events(): void
    {
        $admin = $this->createAuthenticatedAdmin(['audit.read']);
        $this->actingAs($admin)
             ->patchJson('/v1/audit/events/some-uuid', [])
             ->assertStatus(405);
    }

    public function test_no_delete_route_exists_for_audit_events(): void
    {
        $admin = $this->createAuthenticatedAdmin(['audit.read']);
        $this->actingAs($admin)
             ->deleteJson('/v1/audit/events/some-uuid')
             ->assertStatus(405);
    }

    public function test_raw_sql_update_is_blocked_by_db_trigger(): void
    {
        $audit = $this->app->make(AuditModuleContract::class);
        $id    = $audit->record(AuditEntry::make(
            category:      AuditEventCategory::Security,
            eventType:     'security.test',
            actorType:     AuditActorType::System,
            action:        'Trigger test',
            outcome:       AuditOutcome::Denied,
            correlationId: (string) Uuid::uuid7(),
        ));

        $this->expectException(\Throwable::class);
        DB::connection('platform')->statement(
            'UPDATE audit.audit_events SET action = ? WHERE id = ?',
            ['hacked', $id]
        );
    }

    public function test_get_event_returns_integrity_verified_true(): void
    {
        $admin = $this->createAuthenticatedAdmin(['audit.read']);
        $audit = $this->app->make(AuditModuleContract::class);
        $id    = $audit->record(AuditEntry::make(
            category:      AuditEventCategory::Wallet,
            eventType:     'wallet.created',
            actorType:     AuditActorType::Customer,
            action:        'Wallet created',
            outcome:       AuditOutcome::Success,
            correlationId: (string) Uuid::uuid7(),
        ));

        $this->actingAs($admin)
             ->getJson('/v1/audit/events/'.$id)
             ->assertOk()
             ->assertJsonFragment(['integrity_verified' => true]);
    }
}
