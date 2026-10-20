<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Risk\Application\Commands\CreateRiskAlertCommand;
use App\Modules\Risk\Application\Commands\InvestigateAlertCommand;
use App\Modules\Risk\Application\Commands\ResolveAlertCommand;
use App\Modules\Risk\Domain\Enums\AlertStatus;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Enums\RiskLevel;
use App\Modules\Risk\Domain\Models\RiskAlert;
use App\Modules\Risk\Domain\Models\RiskAlertEvent;
use App\Modules\Risk\Domain\Models\RiskDecision;
use App\Modules\Risk\Domain\Models\RiskEvaluation;
use App\Modules\Risk\Domain\Models\RiskEvent;
use App\Modules\Risk\Domain\Models\SuspiciousActivityReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AlertService
{
    private const SLA_HOURS = [
        'critical' => 4,
        'high'     => 24,
        'medium'   => 72,
        'low'      => 168,
    ];

    public function __construct(
        private readonly AuditServiceInterface       $audit,
        private readonly ConfigurationServiceInterface $config,
    ) {}

    /**
     * Create a manual-review alert for an evaluation and link it via RiskAlertEvent.
     *
     * @throws \RuntimeException when an open alert already exists for this evaluation
     */
    public function sendForManualReview(RiskEvaluation $evaluation, string $actorId): RiskAlert
    {
        $existing = RiskAlert::where('correlation_id', $evaluation->idempotency_key)
            ->whereIn('status', [AlertStatus::Open->value, AlertStatus::UnderInvestigation->value])
            ->first();

        if ($existing !== null) {
            throw new \RuntimeException('Alert already open for this evaluation.');
        }

        $severity = $evaluation->risk_level ?? RiskLevel::High;
        $level    = $severity instanceof RiskLevel ? $severity->value : (string) $severity;

        $slaHours = (int) $this->config->getOrDefault(
            "risk.sla_hours.{$level}",
            self::SLA_HOURS[$level] ?? 24
        );

        $systemId = '00000000-0000-0000-0000-000000000000';

        $alert = RiskAlert::create([
            'id'             => (string) Str::orderedUuid(),
            'alert_type'     => AlertType::ThresholdBreach->value,
            'status'         => AlertStatus::Open->value,
            'severity'       => $level,
            'subject_type'   => 'customer',
            'subject_id'     => $evaluation->customer_id,
            'title'          => mb_substr('Manual Review: '.$evaluation->transaction_reference, 0, 255),
            'description'    => "Transaction flagged for manual review. Score: {$evaluation->overall_score}. Decision: {$evaluation->decision->value}.",
            'correlation_id' => $evaluation->idempotency_key,
            'sla_due_at'     => now()->addHours($slaHours),
            'created_by'     => $actorId ?: $systemId,
            'updated_by'     => $actorId ?: $systemId,
            'version'        => 1,
        ]);

        // Create a RiskEvent linking the evaluation, then link to the alert
        $event = RiskEvent::create([
            'id'                    => (string) Str::orderedUuid(),
            'event_type'            => 'threshold_breach',
            'severity'              => $level,
            'subject_type'          => 'customer',
            'subject_id'            => $evaluation->customer_id,
            'transaction_reference' => $evaluation->transaction_reference,
            'evaluation_id'         => $evaluation->id,
            'correlation_id'        => $evaluation->idempotency_key,
            'context'               => ['overall_score' => $evaluation->overall_score],
            'detected_at'           => now(),
            'created_at'            => now(),
        ]);

        RiskAlertEvent::create([
            'alert_id'  => $alert->id,
            'event_id'  => $event->id,
            'linked_at' => now(),
        ]);

        $this->audit->record(new AuditEventData(
            action:        'risk.evaluation.manual_review_requested',
            actorType:     'user',
            resourceType:  'risk_alert',
            correlationId: $evaluation->idempotency_key,
            outcome:       'success',
            metadata:      [
                'evaluation_id' => $evaluation->id,
                'alert_id'      => $alert->id,
                'actor_id'      => $actorId,
            ],
        ));

        return $alert;
    }

    /**
     * Create a high-severity alert for repeated authentication failures.
     */
    public function createAuthFailureAlert(string $subjectType, string $subjectId, int $failureCount): RiskAlert
    {
        $systemId = '00000000-0000-0000-0000-000000000000';

        $alert = RiskAlert::create([
            'id'             => (string) Str::orderedUuid(),
            'alert_type'     => AlertType::AuthFailure->value,
            'status'         => AlertStatus::Open->value,
            'severity'       => RiskLevel::High->value,
            'subject_type'   => $subjectType,
            'subject_id'     => $subjectId,
            'title'          => "Repeated authentication failures ({$failureCount})",
            'description'    => "Subject {$subjectId} has failed authentication {$failureCount} times.",
            'correlation_id' => null,
            'sla_due_at'     => now()->addHours($this->computeSlaHours(RiskLevel::High->value)),
            'created_by'     => $systemId,
            'updated_by'     => $systemId,
            'version'        => 1,
        ]);

        $this->audit->record(new AuditEventData(
            action:        'risk.alert.created',
            actorType:     'system',
            resourceType:  'risk_alert',
            correlationId: $subjectId,
            outcome:       'success',
            metadata:      [
                'alert_id'      => $alert->id,
                'subject_type'  => $subjectType,
                'subject_id'    => $subjectId,
                'failure_count' => $failureCount,
                'alert_type'    => AlertType::AuthFailure->value,
            ],
        ));

        return $alert;
    }

    /**
     * Create a caller-supplied alert; idempotent by (alert_type, subject_id, correlation_id).
     *
     * @throws \RuntimeException on correlation conflict
     */
    public function createAlert(CreateRiskAlertCommand $cmd): RiskAlert
    {
        if ($cmd->correlationId !== null) {
            $existing = RiskAlert::where('correlation_id', $cmd->correlationId)
                ->where('alert_type', $cmd->alertType)
                ->where('subject_id', $cmd->subjectId)
                ->first();

            if ($existing !== null) {
                throw new \RuntimeException('Alert already exists for this correlation_id.');
            }
        }

        $slaHours = $this->computeSlaHours($cmd->severity);

        $alert = RiskAlert::create([
            'id'             => (string) Str::orderedUuid(),
            'alert_type'     => $cmd->alertType,
            'status'         => AlertStatus::Open->value,
            'severity'       => $cmd->severity,
            'subject_type'   => $cmd->subjectType,
            'subject_id'     => $cmd->subjectId,
            'title'          => mb_substr($cmd->title, 0, 255),
            'description'    => $cmd->description,
            'correlation_id' => $cmd->correlationId,
            'assigned_to'    => $cmd->assignedTo,
            'sla_due_at'     => now()->addHours($slaHours),
            'created_by'     => $cmd->actorId,
            'updated_by'     => $cmd->actorId,
            'version'        => 1,
        ]);

        foreach ($cmd->eventIds as $eventId) {
            RiskAlertEvent::create([
                'alert_id'  => $alert->id,
                'event_id'  => $eventId,
                'linked_at' => now(),
            ]);
        }

        $this->audit->record(new AuditEventData(
            action:        'risk.alert.created',
            actorType:     'user',
            resourceType:  'risk_alert',
            correlationId: $cmd->correlationId ?? $alert->id,
            outcome:       'success',
            metadata:      ['alert_id' => $alert->id, 'alert_type' => $cmd->alertType],
        ));

        return $alert;
    }

    /**
     * Transition alert to under_investigation with optimistic locking.
     *
     * @throws \RuntimeException on version mismatch or terminal status
     */
    public function investigateAlert(InvestigateAlertCommand $cmd): RiskAlert
    {
        return DB::connection('risk')->transaction(function () use ($cmd): RiskAlert {
            $alert = RiskAlert::lockForUpdate()->find($cmd->alertId);

            if ($alert === null) {
                throw new \RuntimeException('Alert not found.');
            }

            if ($alert->version !== $cmd->version) {
                throw new \RuntimeException('Version mismatch — alert was modified concurrently.', 409);
            }

            if ($alert->status->isTerminal()) {
                throw new \RuntimeException('Alert is already in a terminal status.', 422);
            }

            $alert->update([
                'status'      => AlertStatus::UnderInvestigation->value,
                'assigned_to' => $cmd->assignedTo,
                'updated_by'  => $cmd->actorId,
            ]);

            $this->audit->record(new AuditEventData(
                action:        'risk.alert.assigned',
                actorType:     'user',
                resourceType:  'risk_alert',
                correlationId: $cmd->alertId,
                outcome:       'success',
                metadata:      ['alert_id' => $cmd->alertId, 'assigned_to' => $cmd->assignedTo, 'actor' => $cmd->actorId],
            ));

            return $alert->fresh();
        });
    }

    /**
     * Resolve an alert with a formal decision. Creates SAR draft when decision=file_sar.
     *
     * @throws \RuntimeException on version mismatch, terminal status, or missing checker_id
     */
    public function resolveAlert(ResolveAlertCommand $cmd): RiskAlert
    {
        return DB::connection('risk')->transaction(function () use ($cmd): RiskAlert {
            $alert = RiskAlert::lockForUpdate()->find($cmd->alertId);

            if ($alert === null) {
                throw new \RuntimeException('Alert not found.');
            }

            if ($alert->version !== $cmd->version) {
                throw new \RuntimeException('Version mismatch — alert was modified concurrently.', 409);
            }

            if ($alert->status->isTerminal()) {
                throw new \RuntimeException('Alert is already resolved.', 422);
            }

            if (in_array($cmd->decision, ['freeze_account', 'file_sar'], true) && $cmd->checkerId === null) {
                throw new \RuntimeException("checker_id is required for decision={$cmd->decision}.", 422);
            }

            // Map decision to terminal status
            $terminalStatus = match ($cmd->decision) {
                'clear', 'allow'     => AlertStatus::ResolvedFalsePositive->value,
                'block', 'freeze_account', 'file_sar' => AlertStatus::ResolvedTruePositive->value,
                default              => AlertStatus::Closed->value,
            };

            $decision = RiskDecision::create([
                'id'         => (string) Str::orderedUuid(),
                'alert_id'   => $alert->id,
                'decision'   => $cmd->decision,
                'rationale'  => $cmd->rationale,
                'decided_by' => $cmd->decidedBy,
                'decided_at' => now(),
                'checker_id' => $cmd->checkerId,
                'created_at' => now(),
            ]);

            $alert->update([
                'status'           => $terminalStatus,
                'resolved_at'      => now(),
                'resolution_notes' => $cmd->rationale,
                'updated_by'       => $cmd->decidedBy,
            ]);

            if ($cmd->decision === 'file_sar') {
                SuspiciousActivityReport::create([
                    'id'                       => (string) Str::orderedUuid(),
                    'status'                   => 'draft',
                    'subject_type'             => $alert->subject_type,
                    'subject_id'               => $alert->subject_id,
                    'alert_id'                 => $alert->id,
                    'decision_id'              => $decision->id,
                    'narrative'                => $cmd->sarNarrative ?? $cmd->rationale,
                    'suspected_offences'       => json_encode($cmd->sarSuspectedOffences ?? []),
                    'transaction_references'   => json_encode($cmd->sarTransactionReferences ?? []),
                    'amount_involved'          => $cmd->sarAmountInvolved ?? '0.0000',
                    'currency'                 => $cmd->sarCurrency ?? 'BWP',
                    'reporting_period_start'   => $cmd->sarPeriodStart ?? now()->subDays(30)->toDateString(),
                    'reporting_period_end'     => $cmd->sarPeriodEnd ?? now()->toDateString(),
                    'filer_id'                 => $cmd->decidedBy,
                ]);
            }

            $this->audit->record(new AuditEventData(
                action:        'risk.alert.resolved',
                actorType:     'user',
                resourceType:  'risk_alert',
                correlationId: (string) $alert->id,
                outcome:       'success',
                metadata:      [
                    'alert_id'   => $alert->id,
                    'decision'   => $cmd->decision,
                    'actor'      => $cmd->decidedBy,
                    'checker_id' => $cmd->checkerId,
                ],
            ));

            return $alert->fresh();
        });
    }

    /**
     * Compute SLA deadline in hours for a given risk level.
     */
    public function computeSlaHours(string $riskLevel): int
    {
        return (int) $this->config->getOrDefault(
            "risk.sla_hours.{$riskLevel}",
            self::SLA_HOURS[$riskLevel] ?? 24
        );
    }
}
