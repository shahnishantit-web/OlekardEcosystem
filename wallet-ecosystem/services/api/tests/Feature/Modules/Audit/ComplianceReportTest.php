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

final class ComplianceReportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'audit.hmac_key'                    => 'test-hmac-report',
            'audit.report.async_threshold_days' => 1,
        ]);
    }

    public function test_post_generates_report_for_small_range(): void
    {
        $admin = $this->createAuthenticatedAdmin(['compliance.report']);
        $key   = (string) Uuid::uuid7();

        $start    = microtime(true);
        $response = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/compliance/reports', [
                'report_type'      => 'compliance_summary',
                'date_range_start' => now()->toDateString(),
                'date_range_end'   => now()->toDateString(),
            ]);
        $elapsed = microtime(true) - $start;

        $response->assertOk()
                 ->assertJsonPath('data.status', 'completed');

        // SC-005 sync-path SLA: must complete within 60s
        $this->assertLessThan(60.0, $elapsed, 'SC-005: sync report generation must complete <60s');
    }

    public function test_idempotent_repeat_returns_same_report_id(): void
    {
        $admin = $this->createAuthenticatedAdmin(['compliance.report']);
        $key   = (string) Uuid::uuid7();

        $response1 = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/compliance/reports', [
                'report_type'      => 'compliance_summary',
                'date_range_start' => now()->toDateString(),
                'date_range_end'   => now()->toDateString(),
            ]);

        $response2 = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/compliance/reports', [
                'report_type'      => 'compliance_summary',
                'date_range_start' => now()->toDateString(),
                'date_range_end'   => now()->toDateString(),
            ]);

        $response1->assertOk();
        $response2->assertOk();
        $this->assertSame($response1->json('data.id'), $response2->json('data.id'));
    }

    public function test_missing_compliance_report_permission_returns_403(): void
    {
        $customer = $this->createAuthenticatedUser([]);
        $key      = (string) Uuid::uuid7();

        $this->actingAs($customer)
             ->withHeaders(['Idempotency-Key' => $key])
             ->postJson('/v1/compliance/reports', [
                 'report_type'      => 'compliance_summary',
                 'date_range_start' => now()->toDateString(),
                 'date_range_end'   => now()->toDateString(),
             ])
             ->assertForbidden();
    }
}
