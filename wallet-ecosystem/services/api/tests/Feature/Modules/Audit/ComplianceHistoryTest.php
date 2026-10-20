<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Audit;

use App\Modules\Audit\Application\Services\ComplianceStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ComplianceHistoryTest extends TestCase
{
    use DatabaseTransactions;

    private ComplianceStatusService $statusService;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.hmac_key' => 'test-hmac-compliance-history']);
        $this->statusService = $this->app->make(ComplianceStatusService::class);
    }

    public function test_sc004_completeness_three_distinct_changes(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['compliance.read']);
        $userId = (string) Uuid::uuid7();

        $now = CarbonImmutable::now();

        // Change 1: KYC tier change
        $this->statusService->applyKycChange(
            userId:        $userId,
            newTier:       1,
            auditEventId:  (string) Uuid::uuid7(),
            correlationId: (string) Uuid::uuid7(),
            occurredAt:    $now->subMinutes(10),
        );

        // Change 2: AML risk change
        $this->statusService->applyAmlChange(
            userId:        $userId,
            newRiskLevel:  'medium',
            auditEventId:  (string) Uuid::uuid7(),
            correlationId: (string) Uuid::uuid7(),
            occurredAt:    $now->subMinutes(5),
        );

        // Change 3: Restriction change
        $this->statusService->applyRestrictionChange(
            userId:        $userId,
            restrictions:  [['type' => 'withdrawal_blocked']],
            auditEventId:  (string) Uuid::uuid7(),
            correlationId: (string) Uuid::uuid7(),
            occurredAt:    $now,
        );

        $response = $this->actingAs($admin)->getJson('/v1/compliance/history/'.$userId);
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(3, $data, 'SC-004: must return exactly 3 history entries');

        $fieldNames = collect($data)->pluck('field_changed')->all();
        $this->assertContains('kyc_tier', $fieldNames);
        $this->assertContains('aml_risk_level', $fieldNames);
        $this->assertContains('restrictions', $fieldNames);
    }

    public function test_sc004_cursor_pagination_split(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['compliance.read']);
        $userId = (string) Uuid::uuid7();

        $now = CarbonImmutable::now();

        for ($i = 0; $i < 3; $i++) {
            $this->statusService->applyKycChange(
                userId:        $userId,
                newTier:       $i + 1,
                auditEventId:  (string) Uuid::uuid7(),
                correlationId: (string) Uuid::uuid7(),
                occurredAt:    $now->subMinutes(10 - $i),
            );
        }

        $page1 = $this->actingAs($admin)->getJson('/v1/compliance/history/'.$userId.'?per_page=2');
        $page1->assertOk();
        $this->assertCount(2, $page1->json('data'));

        $cursor = $page1->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $page2 = $this->actingAs($admin)->getJson('/v1/compliance/history/'.$userId.'?per_page=2&cursor='.$cursor);
        $page2->assertOk();
        $this->assertCount(1, $page2->json('data'));
    }

    public function test_history_entries_are_in_descending_changed_at_order(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['compliance.read']);
        $userId = (string) Uuid::uuid7();
        $now    = CarbonImmutable::now();

        $this->statusService->applyKycChange(
            userId:        $userId,
            newTier:       1,
            auditEventId:  (string) Uuid::uuid7(),
            correlationId: (string) Uuid::uuid7(),
            occurredAt:    $now->subMinutes(20),
        );
        $this->statusService->applyAmlChange(
            userId:        $userId,
            newRiskLevel:  'high',
            auditEventId:  (string) Uuid::uuid7(),
            correlationId: (string) Uuid::uuid7(),
            occurredAt:    $now->subMinutes(5),
        );

        $response = $this->actingAs($admin)->getJson('/v1/compliance/history/'.$userId);
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        // Most recent first (descending)
        $this->assertSame('aml_risk_level', $data[0]['field_changed']);
        $this->assertSame('kyc_tier', $data[1]['field_changed']);
    }
}
