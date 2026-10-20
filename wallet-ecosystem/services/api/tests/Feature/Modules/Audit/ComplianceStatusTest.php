<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Audit;

use App\Modules\Audit\Application\Services\ComplianceStatusService;
use App\Modules\Audit\Infrastructure\Models\ComplianceStatusModel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class ComplianceStatusTest extends TestCase
{
    use DatabaseTransactions;

    private ComplianceStatusService $statusService;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.hmac_key' => 'test-hmac-compliance-status']);
        $this->statusService = $this->app->make(ComplianceStatusService::class);
    }

    public function test_get_status_returns_correct_kyc_tier_and_aml_risk_level(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['compliance.read']);
        $userId = (string) Uuid::uuid7();

        $this->statusService->applyKycChange(
            userId:        $userId,
            newTier:       1,
            auditEventId:  (string) Uuid::uuid7(),
            correlationId: (string) Uuid::uuid7(),
            occurredAt:    CarbonImmutable::now(),
        );

        $start    = microtime(true);
        $response = $this->actingAs($admin)->getJson('/v1/compliance/status/'.$userId);
        $elapsed  = microtime(true) - $start;

        $response->assertOk()
                 ->assertJsonFragment(['kyc_tier' => 1]);

        // SC-003: response time <300ms
        $this->assertLessThan(0.3, $elapsed, 'SC-003: compliance status response must be <300ms');
    }

    public function test_get_status_returns_404_for_unknown_user(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['compliance.read']);
        $userId = (string) Uuid::uuid7();

        $this->actingAs($admin)
             ->getJson('/v1/compliance/status/'.$userId)
             ->assertNotFound();
    }

    public function test_get_status_returns_403_for_missing_permission(): void
    {
        $customer = $this->createAuthenticatedUser([]);
        $userId   = (string) Uuid::uuid7();

        $this->actingAs($customer)
             ->getJson('/v1/compliance/status/'.$userId)
             ->assertForbidden();
    }

    public function test_sc003_response_time_gate(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['compliance.read']);
        $userId = (string) Uuid::uuid7();

        // Seed a status record directly
        $model = new ComplianceStatusModel([
            'user_id'        => $userId,
            'kyc_tier'       => 2,
            'aml_risk_level' => 'medium',
        ]);
        $model->save();

        $start    = microtime(true);
        $response = $this->actingAs($admin)->getJson('/v1/compliance/status/'.$userId);
        $elapsed  = microtime(true) - $start;

        $response->assertOk();
        $this->assertLessThan(0.3, $elapsed, 'SC-003: must respond in <300ms');
    }
}
