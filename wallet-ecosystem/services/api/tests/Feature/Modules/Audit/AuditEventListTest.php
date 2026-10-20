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

final class AuditEventListTest extends TestCase
{
    use DatabaseTransactions;

    private AuditModuleContract $audit;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.hmac_key' => 'test-hmac-key-feature']);
        $this->audit = $this->app->make(AuditModuleContract::class);
    }

    public function test_list_returns_200_with_events(): void
    {
        $admin = $this->createAuthenticatedAdmin(['audit.read']);

        $this->audit->record(AuditEntry::make(
            category:      AuditEventCategory::Authentication,
            eventType:     'authentication.login.success',
            actorType:     AuditActorType::Customer,
            action:        'User logged in',
            outcome:       AuditOutcome::Success,
            correlationId: (string) Uuid::uuid7(),
        )->withActor($admin->id));

        $response = $this->actingAs($admin)->getJson('/v1/audit/events');

        $response->assertOk()
                 ->assertJsonStructure(['data' => [['id', 'event_category', 'event_type', 'actor_id', 'actor_type', 'action', 'outcome', 'correlation_id', 'occurred_at']]]);
    }

    public function test_filter_by_category(): void
    {
        $admin = $this->createAuthenticatedAdmin(['audit.read']);

        $this->audit->record(AuditEntry::make(
            category:      AuditEventCategory::Authentication,
            eventType:     'authentication.login.success',
            actorType:     AuditActorType::Customer,
            action:        'Login',
            outcome:       AuditOutcome::Success,
            correlationId: (string) Uuid::uuid7(),
        ));
        $this->audit->record(AuditEntry::make(
            category:      AuditEventCategory::Wallet,
            eventType:     'wallet.created',
            actorType:     AuditActorType::System,
            action:        'Wallet created',
            outcome:       AuditOutcome::Success,
            correlationId: (string) Uuid::uuid7(),
        ));

        $response = $this->actingAs($admin)->getJson('/v1/audit/events?category=authentication');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertSame('authentication', $item['event_category']);
        }
    }

    public function test_filter_by_actor_id(): void
    {
        $admin = $this->createAuthenticatedAdmin(['audit.read']);
        $userId = (string) Uuid::uuid7();

        $this->audit->record(AuditEntry::make(
            category:      AuditEventCategory::Authentication,
            eventType:     'authentication.login.success',
            actorType:     AuditActorType::Customer,
            action:        'Login',
            outcome:       AuditOutcome::Success,
            correlationId: (string) Uuid::uuid7(),
        )->withActor($userId));

        $response = $this->actingAs($admin)->getJson('/v1/audit/events?actor_id='.$userId);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertSame($userId, $data[0]['actor_id']);
    }

    public function test_filter_by_correlation_id(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['audit.read']);
        $corrId = (string) Uuid::uuid7();

        $this->audit->record(AuditEntry::make(
            category:      AuditEventCategory::Transaction,
            eventType:     'transaction.completed',
            actorType:     AuditActorType::System,
            action:        'Transfer completed',
            outcome:       AuditOutcome::Success,
            correlationId: $corrId,
        ));

        $response = $this->actingAs($admin)->getJson('/v1/audit/events?correlation_id='.$corrId);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($corrId, $data[0]['correlation_id']);
    }

    public function test_cursor_pagination_returns_distinct_pages(): void
    {
        $admin = $this->createAuthenticatedAdmin(['audit.read']);

        // Seed 5 events
        for ($i = 1; $i <= 5; $i++) {
            $this->audit->record(AuditEntry::make(
                category:      AuditEventCategory::Admin,
                eventType:     'admin.test.event',
                actorType:     AuditActorType::Admin,
                action:        'Test event '.$i,
                outcome:       AuditOutcome::Success,
                correlationId: (string) Uuid::uuid7(),
            ));
        }

        $page1 = $this->actingAs($admin)->getJson('/v1/audit/events?per_page=2');
        $page1->assertOk();
        $nextCursor = $page1->json('meta.next_cursor');
        $this->assertNotNull($nextCursor);

        $page2 = $this->actingAs($admin)->getJson('/v1/audit/events?per_page=2&cursor='.$nextCursor);
        $page2->assertOk();

        $ids1 = collect($page1->json('data'))->pluck('id')->all();
        $ids2 = collect($page2->json('data'))->pluck('id')->all();
        $this->assertEmpty(array_intersect($ids1, $ids2), 'Pages must not overlap');
    }

    public function test_sc001_all_eight_categories_are_storable_and_retrievable(): void
    {
        $admin = $this->createAuthenticatedAdmin(['audit.read']);

        $categories = [
            AuditEventCategory::Authentication,
            AuditEventCategory::Authorization,
            AuditEventCategory::Wallet,
            AuditEventCategory::Transaction,
            AuditEventCategory::Kyc,
            AuditEventCategory::Admin,
            AuditEventCategory::Config,
            AuditEventCategory::Security,
        ];

        foreach ($categories as $cat) {
            $this->audit->record(AuditEntry::make(
                category:      $cat,
                eventType:     $cat->value.'.test',
                actorType:     AuditActorType::System,
                action:        'Test event for '.$cat->value,
                outcome:       AuditOutcome::Success,
                correlationId: (string) Uuid::uuid7(),
            ));
        }

        foreach ($categories as $cat) {
            $response = $this->actingAs($admin)->getJson('/v1/audit/events?category='.$cat->value);
            $response->assertOk();
            $this->assertNotEmpty($response->json('data'), "Category {$cat->value} returned no events");
        }
    }
}
