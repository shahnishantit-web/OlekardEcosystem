<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Audit;

use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class AuditEventDetailTest extends TestCase
{
    use DatabaseTransactions;

    private AuditModuleContract $audit;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.hmac_key' => 'test-hmac-key-detail']);
        $this->audit = $this->app->make(AuditModuleContract::class);
    }

    public function test_get_existing_event_returns_integrity_verified_true(): void
    {
        $admin = $this->createAuthenticatedAdmin(['audit.read']);

        $id = $this->audit->record(AuditEntry::make(
            category:      AuditEventCategory::Wallet,
            eventType:     'wallet.created',
            actorType:     AuditActorType::Customer,
            action:        'Wallet opened',
            outcome:       AuditOutcome::Success,
            correlationId: (string) Uuid::uuid7(),
        )->withActor($admin->id)->withEntity('wallet', (string) Uuid::uuid7()));

        $response = $this->actingAs($admin)->getJson('/v1/audit/events/'.$id);

        $response->assertOk()
                 ->assertJsonFragment(['integrity_verified' => true])
                 ->assertJsonStructure(['data' => ['id', 'event_category', 'event_type', 'before_state', 'after_state', 'metadata', 'integrity_verified']]);
    }

    public function test_get_non_existent_id_returns_404(): void
    {
        $admin = $this->createAuthenticatedAdmin(['audit.read']);

        $this->actingAs($admin)
             ->getJson('/v1/audit/events/00000000-0000-0000-0000-000000000000')
             ->assertNotFound();
    }

    public function test_actor_without_audit_read_returns_403(): void
    {
        $customer = $this->createAuthenticatedUser([]);
        $id       = 'any-uuid-value';

        $this->actingAs($customer)
             ->getJson('/v1/audit/events/'.$id)
             ->assertForbidden();
    }
}
