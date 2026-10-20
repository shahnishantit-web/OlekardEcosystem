<?php

declare(strict_types=1);

namespace Tests\Unit\Risk\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Risk\Application\Commands\CreateRiskAlertCommand;
use App\Modules\Risk\Application\Commands\InvestigateAlertCommand;
use App\Modules\Risk\Application\Commands\ResolveAlertCommand;
use App\Modules\Risk\Application\Services\AlertService;
use App\Modules\Risk\Domain\Enums\AlertStatus;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Models\RiskAlert;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T102: AlertService unit tests — lifecycle transitions, optimistic locking, SLA.
 */
final class AlertServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AlertService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $audit  = $this->createStub(AuditServiceInterface::class);
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('getOrDefault')->willReturnArgument(1);

        $this->service = new AlertService($audit, $config);
    }

    // ── createAlert ───────────────────────────────────────────────────────────

    /** New alert is persisted with open status */
    public function test_create_alert_persists_open_alert(): void
    {
        $cmd = $this->makeCreateCmd();

        $alert = $this->service->createAlert($cmd);

        $this->assertNotNull($alert->id);
        $this->assertSame(AlertStatus::Open, $alert->status);
        $this->assertDatabaseHas('risk.risk_alerts', ['id' => $alert->id, 'status' => 'open'], 'risk');
    }

    /** SLA deadline uses severity default when config returns the default */
    public function test_create_alert_sla_deadline_set(): void
    {
        $cmd = $this->makeCreateCmd(['severity' => 'critical']);

        $alert = $this->service->createAlert($cmd);

        $this->assertNotNull($alert->sla_due_at);
        // critical SLA default = 4 hours; expect within 5 hours from now
        $this->assertLessThanOrEqual(5, now()->diffInHours($alert->sla_due_at));
    }

    /** Duplicate correlation_id + alert_type + subject_id throws RuntimeException */
    public function test_create_alert_duplicate_correlation_throws(): void
    {
        $correlationId = Str::uuid()->toString();
        $subjectId     = Str::uuid()->toString();

        $cmd = $this->makeCreateCmd(['correlationId' => $correlationId, 'subjectId' => $subjectId]);

        $this->service->createAlert($cmd);

        $this->expectException(\RuntimeException::class);
        $this->service->createAlert($cmd);
    }

    /** No duplicate check when correlationId is null */
    public function test_create_alert_no_correlation_allows_duplicates(): void
    {
        $subjectId = Str::uuid()->toString();

        $cmd = $this->makeCreateCmd(['correlationId' => null, 'subjectId' => $subjectId]);

        $alert1 = $this->service->createAlert($cmd);
        $alert2 = $this->service->createAlert($cmd);

        $this->assertNotSame($alert1->id, $alert2->id);
    }

    // ── investigateAlert ──────────────────────────────────────────────────────

    /** Valid version transitions alert to under_investigation */
    public function test_investigate_transitions_to_under_investigation(): void
    {
        $alert = $this->createOpenAlert();

        $cmd = new InvestigateAlertCommand(
            alertId:    $alert->id,
            assignedTo: Str::uuid()->toString(),
            version:    $alert->version,
            actorId:    Str::uuid()->toString(),
        );

        $updated = $this->service->investigateAlert($cmd);

        $this->assertSame(AlertStatus::UnderInvestigation, $updated->status);
    }

    /** Stale version throws RuntimeException with code 409 */
    public function test_investigate_stale_version_throws_409(): void
    {
        $alert = $this->createOpenAlert();

        $cmd = new InvestigateAlertCommand(
            alertId:    $alert->id,
            assignedTo: Str::uuid()->toString(),
            version:    $alert->version + 99,  // wrong version
            actorId:    Str::uuid()->toString(),
        );

        try {
            $this->service->investigateAlert($cmd);
            $this->fail('Expected RuntimeException not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
    }

    /** Investigating an already-terminal alert throws RuntimeException with code 422 */
    public function test_investigate_terminal_alert_throws_422(): void
    {
        $alert = $this->createResolvedAlert();

        $cmd = new InvestigateAlertCommand(
            alertId:    $alert->id,
            assignedTo: Str::uuid()->toString(),
            version:    $alert->version,
            actorId:    Str::uuid()->toString(),
        );

        try {
            $this->service->investigateAlert($cmd);
            $this->fail('Expected RuntimeException not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
        }
    }

    // ── resolveAlert ──────────────────────────────────────────────────────────

    /** Resolving with decision=clear sets resolved_false_positive status */
    public function test_resolve_clear_sets_false_positive(): void
    {
        $alert = $this->createOpenAlert();

        $cmd = $this->makeResolveCmd($alert, 'clear');

        $resolved = $this->service->resolveAlert($cmd);

        $this->assertSame(AlertStatus::ResolvedFalsePositive, $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
    }

    /** Resolving with decision=block sets resolved_true_positive status */
    public function test_resolve_block_sets_true_positive(): void
    {
        $alert = $this->createOpenAlert();

        $cmd = $this->makeResolveCmd($alert, 'block');

        $resolved = $this->service->resolveAlert($cmd);

        $this->assertSame(AlertStatus::ResolvedTruePositive, $resolved->status);
    }

    /** Resolving already-terminal alert throws RuntimeException code 422 */
    public function test_resolve_terminal_alert_throws_422(): void
    {
        $alert = $this->createResolvedAlert();

        $cmd = $this->makeResolveCmd($alert, 'clear');

        try {
            $this->service->resolveAlert($cmd);
            $this->fail('Expected RuntimeException not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
        }
    }

    /** freeze_account decision without checker_id throws RuntimeException code 422 */
    public function test_resolve_freeze_account_requires_checker_id(): void
    {
        $alert = $this->createOpenAlert();

        $cmd = new ResolveAlertCommand(
            alertId:    $alert->id,
            decision:   'freeze_account',
            rationale:  'Suspicious activity confirmed by analyst.',
            version:    $alert->version,
            decidedBy:  Str::uuid()->toString(),
            checkerId:  null,   // missing!
        );

        try {
            $this->service->resolveAlert($cmd);
            $this->fail('Expected RuntimeException not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('checker_id', $e->getMessage());
        }
    }

    /** file_sar decision without checker_id throws RuntimeException code 422 */
    public function test_resolve_file_sar_requires_checker_id(): void
    {
        $alert = $this->createOpenAlert();

        $cmd = new ResolveAlertCommand(
            alertId:   $alert->id,
            decision:  'file_sar',
            rationale: 'Confirmed money laundering pattern.',
            version:   $alert->version,
            decidedBy: Str::uuid()->toString(),
            checkerId: null,
        );

        try {
            $this->service->resolveAlert($cmd);
            $this->fail('Expected RuntimeException not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
        }
    }

    /** file_sar decision with checker_id creates SuspiciousActivityReport draft */
    public function test_resolve_file_sar_creates_sar_draft(): void
    {
        $alert = $this->createOpenAlert();

        $cmd = new ResolveAlertCommand(
            alertId:    $alert->id,
            decision:   'file_sar',
            rationale:  'Confirmed money laundering pattern over 30 days.',
            version:    $alert->version,
            decidedBy:  Str::uuid()->toString(),
            checkerId:  Str::uuid()->toString(),
        );

        $this->service->resolveAlert($cmd);

        $this->assertDatabaseHas('risk.suspicious_activity_reports', [
            'alert_id' => $alert->id,
            'status'   => 'draft',
        ], 'risk');
    }

    // ── SLA computation ───────────────────────────────────────────────────────

    /** computeSlaHours returns known defaults when config uses fallback */
    public function test_sla_hours_defaults(): void
    {
        $this->assertSame(4,   $this->service->computeSlaHours('critical'));
        $this->assertSame(24,  $this->service->computeSlaHours('high'));
        $this->assertSame(72,  $this->service->computeSlaHours('medium'));
        $this->assertSame(168, $this->service->computeSlaHours('low'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCreateCmd(array $overrides = []): CreateRiskAlertCommand
    {
        return new CreateRiskAlertCommand(
            alertType:     $overrides['alertType']     ?? AlertType::Pattern->value,
            severity:      $overrides['severity']      ?? 'medium',
            subjectType:   $overrides['subjectType']   ?? 'customer',
            subjectId:     $overrides['subjectId']     ?? Str::uuid()->toString(),
            title:         $overrides['title']         ?? 'Test Alert',
            description:   $overrides['description']   ?? 'Test description.',
            actorId:       $overrides['actorId']       ?? Str::uuid()->toString(),
            correlationId: $overrides['correlationId'] ?? Str::uuid()->toString(),
            assignedTo:    $overrides['assignedTo']    ?? null,
            eventIds:      $overrides['eventIds']      ?? [],
        );
    }

    private function createOpenAlert(): RiskAlert
    {
        return RiskAlert::create([
            'id'           => (string) Str::orderedUuid(),
            'alert_type'   => AlertType::Pattern->value,
            'status'       => AlertStatus::Open->value,
            'severity'     => 'medium',
            'subject_type' => 'customer',
            'subject_id'   => Str::uuid()->toString(),
            'title'        => 'Test Alert',
            'description'  => 'Test description.',
            'created_by'   => Str::uuid()->toString(),
            'updated_by'   => Str::uuid()->toString(),
            'version'      => 1,
            'sla_due_at'   => now()->addHours(72),
        ]);
    }

    private function createResolvedAlert(): RiskAlert
    {
        return RiskAlert::create([
            'id'           => (string) Str::orderedUuid(),
            'alert_type'   => AlertType::Pattern->value,
            'status'       => AlertStatus::ResolvedFalsePositive->value,
            'severity'     => 'low',
            'subject_type' => 'customer',
            'subject_id'   => Str::uuid()->toString(),
            'title'        => 'Resolved Alert',
            'description'  => 'Already resolved.',
            'created_by'   => Str::uuid()->toString(),
            'updated_by'   => Str::uuid()->toString(),
            'version'      => 1,
            'resolved_at'  => now()->subMinute(),
            'sla_due_at'   => now()->addHours(72),
        ]);
    }

    private function makeResolveCmd(RiskAlert $alert, string $decision): ResolveAlertCommand
    {
        return new ResolveAlertCommand(
            alertId:   $alert->id,
            decision:  $decision,
            rationale: 'Investigated thoroughly and determined outcome.',
            version:   $alert->version,
            decidedBy: Str::uuid()->toString(),
        );
    }
}
