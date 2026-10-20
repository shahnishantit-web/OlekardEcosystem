<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Risk;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Risk\Domain\Contracts\FiaReportingInterface;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\AlertStatus;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Models\RiskAlert;
use App\Modules\Risk\Domain\Models\RiskDecision;
use App\Modules\Risk\Domain\Models\SuspiciousActivityReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T122: SAR workflow — draft → approve → submit; guard violations.
 */
final class SarWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private string $filerId;
    private string $approverId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(RiskMetricsInterface::class, $this->createStub(RiskMetricsInterface::class));

        $this->filerId    = Str::uuid()->toString();
        $this->approverId = Str::uuid()->toString();
    }

    // ── resolve alert file_sar → SAR draft created ─────────────────────────────

    /** Resolving an alert with decision=file_sar creates a SAR in draft status */
    public function test_resolve_file_sar_creates_sar_draft(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.manage_alerts']);
        $alert = $this->seedOpenAlert();

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/resolve-risk-alert', [
                'alert_id'   => $alert->id,
                'decision'   => 'file_sar',
                'rationale'  => 'Confirmed structured transactions consistent with money laundering.',
                'version'    => $alert->version,
                'checker_id' => Str::uuid()->toString(),
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('risk.suspicious_activity_reports', [
            'alert_id' => $alert->id,
            'status'   => 'draft',
        ], 'risk');
    }

    // ── List & Show ───────────────────────────────────────────────────────────

    /** List SARs returns paginated results */
    public function test_list_sars_returns_paginated(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.file_sar']);

        $this->seedSar();
        $this->seedSar();

        $response = $this->actingAs($admin)
            ->getJson('/v1/risk/sars');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    /** Show SAR returns full details */
    public function test_show_sar_returns_details(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.file_sar']);
        $sar   = $this->seedSar();

        $response = $this->actingAs($admin)
            ->getJson("/v1/risk/sars/{$sar->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $sar->id)
            ->assertJsonStructure(['id', 'status', 'narrative', 'suspected_offences', 'transaction_references']);
    }

    /** Show unknown SAR returns 404 */
    public function test_show_unknown_sar_returns_404(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.file_sar']);

        $this->actingAs($admin)
            ->getJson('/v1/risk/sars/'.Str::uuid())
            ->assertStatus(404);
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    /** Approve SAR transitions to approved */
    public function test_approve_sar(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.file_sar']);
        $sar   = $this->seedSar();

        $response = $this->actingAs($admin)
            ->postJson("/v1/risk/sars/{$sar->id}/approve", [
                'approver_id' => $this->approverId,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('risk.suspicious_activity_reports', [
            'id'     => $sar->id,
            'status' => 'approved',
        ], 'risk');
    }

    /** Approver cannot be the same as the filer (maker-checker) */
    public function test_approve_same_as_filer_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.file_sar']);
        $sar   = $this->seedSar();

        $this->actingAs($admin)
            ->postJson("/v1/risk/sars/{$sar->id}/approve", [
                'approver_id' => $this->filerId,   // same as filer
            ])
            ->assertStatus(422);
    }

    /** Double approve returns 409 */
    public function test_double_approve_returns_409(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.file_sar']);
        $sar   = $this->seedSar();

        $this->actingAs($admin)
            ->postJson("/v1/risk/sars/{$sar->id}/approve", ['approver_id' => $this->approverId])
            ->assertStatus(200);

        $this->actingAs($admin)
            ->postJson("/v1/risk/sars/{$sar->id}/approve", ['approver_id' => Str::uuid()->toString()])
            ->assertStatus(409);
    }

    // ── Submit ────────────────────────────────────────────────────────────────

    /** Submit without prior approval returns 422 */
    public function test_submit_without_approval_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.file_sar']);
        $sar   = $this->seedSar();

        $this->actingAs($admin)
            ->postJson("/v1/risk/sars/{$sar->id}/submit", [])
            ->assertStatus(422);
    }

    /** Submit approved SAR calls FIA adapter; on success sets status=submitted */
    public function test_submit_approved_sar_success(): void
    {
        $fiaRef  = 'FIA-REF-'.Str::random(8);
        $fakeFia = $this->createMock(FiaReportingInterface::class);
        $fakeFia->expects($this->once())
            ->method('submit')
            ->willReturn($fiaRef);

        $this->instance(FiaReportingInterface::class, $fakeFia);

        $admin = $this->createAuthenticatedAdmin(['risk.file_sar']);
        $sar   = $this->seedSar(['status' => 'approved', 'approver_id' => $this->approverId, 'approved_at' => now()]);

        $response = $this->actingAs($admin)
            ->postJson("/v1/risk/sars/{$sar->id}/submit", []);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('fia_reference', $fiaRef);

        $this->assertDatabaseHas('risk.suspicious_activity_reports', [
            'id'            => $sar->id,
            'status'        => 'submitted',
            'fia_reference' => $fiaRef,
        ], 'risk');
    }

    /** Submit returns 502 on FIA adapter failure */
    public function test_submit_fia_failure_returns_502(): void
    {
        $fakeFia = $this->createMock(FiaReportingInterface::class);
        $fakeFia->expects($this->once())
            ->method('submit')
            ->willThrowException(new \RuntimeException('FIA portal timeout'));

        $this->instance(FiaReportingInterface::class, $fakeFia);

        $admin = $this->createAuthenticatedAdmin(['risk.file_sar']);
        $sar   = $this->seedSar(['status' => 'approved', 'approver_id' => $this->approverId, 'approved_at' => now()]);

        $response = $this->actingAs($admin)
            ->postJson("/v1/risk/sars/{$sar->id}/submit", []);

        $response->assertStatus(502);

        $this->assertDatabaseHas('risk.suspicious_activity_reports', [
            'id'     => $sar->id,
            'status' => 'submission_failed',
        ], 'risk');
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    /** Unauthenticated returns 401 */
    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/v1/risk/sars')->assertStatus(401);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedOpenAlert(): RiskAlert
    {
        return RiskAlert::create([
            'id'           => (string) Str::orderedUuid(),
            'alert_type'   => AlertType::Pattern->value,
            'status'       => AlertStatus::Open->value,
            'severity'     => 'high',
            'subject_type' => 'customer',
            'subject_id'   => Str::uuid()->toString(),
            'title'        => 'SAR Workflow Test Alert',
            'description'  => 'Created for SAR workflow test.',
            'created_by'   => $this->filerId,
            'updated_by'   => $this->filerId,
            'version'      => 1,
            'sla_due_at'   => now()->addHours(24),
        ]);
    }

    private function seedSar(array $overrides = []): SuspiciousActivityReport
    {
        $alert    = $this->seedOpenAlert();
        $decision = RiskDecision::create([
            'id'         => (string) Str::orderedUuid(),
            'alert_id'   => $alert->id,
            'decision'   => 'file_sar',
            'rationale'  => 'Suspicious activity confirmed.',
            'decided_by' => $this->filerId,
            'decided_at' => now(),
        ]);

        return SuspiciousActivityReport::create(array_merge([
            'id'                     => (string) Str::orderedUuid(),
            'status'                 => 'draft',
            'subject_type'           => $alert->subject_type,
            'subject_id'             => $alert->subject_id,
            'alert_id'               => $alert->id,
            'decision_id'            => $decision->id,
            'narrative'              => 'Structured transactions over 30 days suggest layering.',
            'suspected_offences'     => json_encode(['money_laundering']),
            'transaction_references' => json_encode(['TXN-001', 'TXN-002']),
            'amount_involved'        => '25000.0000',
            'currency'               => 'BWP',
            'reporting_period_start' => now()->subDays(30)->toDateString(),
            'reporting_period_end'   => now()->toDateString(),
            'filer_id'               => $this->filerId,
            'submission_attempts'    => 0,
        ], $overrides));
    }
}
