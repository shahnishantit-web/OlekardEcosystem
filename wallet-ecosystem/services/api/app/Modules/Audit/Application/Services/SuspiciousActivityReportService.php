<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Services;

use App\Modules\Audit\Domain\Events\SARGeneratedEvent;
use App\Modules\Audit\Domain\Exceptions\SARNotFoundException;
use App\Modules\Audit\Infrastructure\Models\AuditEventModel;
use App\Modules\Audit\Infrastructure\Models\ComplianceHistoryModel;
use App\Modules\Audit\Infrastructure\Models\SuspiciousActivityReportModel;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use Illuminate\Support\Facades\Log;

final class SuspiciousActivityReportService
{
    public function __construct(
        private readonly IdentityModuleContract $identity,
        private readonly OutboxEventPublisher   $outbox,
    ) {}

    public function generateSAR(
        string  $subjectUserId,
        string  $reasonCode,
        string  $reasonNarrative,
        array   $flaggedTransactionIds,
        string  $idempotencyKey,
        string  $actorId,
        string  $correlationId,
    ): SuspiciousActivityReportModel {
        Log::info('sar.generation_requested', [
            'subject_user_id' => $subjectUserId,
            'reason_code'     => $reasonCode,
            'actor_id'        => $actorId,
            'correlation_id'  => $correlationId,
        ]);

        $existing = SuspiciousActivityReportModel::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        // (1) Subject identity snapshot via IdentityModuleContract (Constitution Principle 7)
        $sarStatus = 'draft';
        try {
            $identitySnapshot = $this->identity->getIdentitySnapshot($subjectUserId);
        } catch (\Throwable $e) {
            // E01 getIdentitySnapshot() not yet implemented — SAR is incomplete until it ships.
            Log::warning('sar.identity_snapshot_unavailable', [
                'user_id'        => $subjectUserId,
                'error'          => $e->getMessage(),
                'correlation_id' => $correlationId,
            ]);
            $identitySnapshot = [
                'user_id' => $subjectUserId,
                'status'  => 'snapshot_unavailable',
                'reason'  => 'E01 getIdentitySnapshot() not yet implemented',
            ];
            $sarStatus = 'incomplete';
        }

        // (2) AML alert history from audit_events (security events for this user)
        $amlAlertHistory = AuditEventModel::where('event_category', 'security')
            ->where('entity_type', 'user')
            ->where('entity_id', $subjectUserId)
            ->orderBy('occurred_at')
            ->get(['id', 'event_type', 'action', 'outcome', 'occurred_at', 'metadata'])
            ->map(fn ($row) => [
                'event_type'  => $row->event_type,
                'action'      => $row->action,
                'outcome'     => $row->outcome,
                'occurred_at' => $row->occurred_at->toIso8601String(),
            ])
            ->values()
            ->all();

        if (empty($amlAlertHistory)) {
            $reasonNarrative .= ' Note: no AML alert events found for this subject in the audit log.';
        }

        // (3) Risk score timeline from compliance_history
        $riskScoreTimeline = ComplianceHistoryModel::where('user_id', $subjectUserId)
            ->where('field_changed', 'aml_risk_level')
            ->orderBy('changed_at')
            ->get(['id', 'changed_at', 'before_value', 'after_value', 'source_system'])
            ->map(fn ($row) => [
                'changed_at'   => $row->changed_at->toIso8601String(),
                'before_value' => $row->before_value,
                'after_value'  => $row->after_value,
                'source_system' => $row->source_system,
            ])
            ->values()
            ->all();

        $sar = new SuspiciousActivityReportModel([
            'subject_user_id'           => $subjectUserId,
            'reason_code'               => $reasonCode,
            'reason_narrative'          => $reasonNarrative,
            'status'                    => $sarStatus,
            'flagged_transaction_ids'   => $flaggedTransactionIds,
            'subject_identity_snapshot' => $identitySnapshot,
            'aml_alert_history'         => $amlAlertHistory,
            'risk_score_timeline'       => $riskScoreTimeline,
            'created_by_actor_id'       => $actorId,
            'idempotency_key'           => $idempotencyKey,
        ]);
        $sar->save();

        $this->outbox->publish(new SARGeneratedEvent(
            sarId:            $sar->id,
            reportNumber:     $sar->report_number,
            subjectUserId:    $subjectUserId,
            createdByActorId: $actorId,
            occurredAtIso:    now()->toIso8601String(),
            correlationId:    $correlationId,
        ));

        Log::channel('stderr')->debug('metric', [
            'metric' => 'sar_generated_total',
        ]);

        return $sar;
    }

    public function getSAR(string $sarId): SuspiciousActivityReportModel
    {
        $sar = SuspiciousActivityReportModel::find($sarId);
        if ($sar === null) {
            throw new SARNotFoundException($sarId);
        }
        return $sar;
    }
}
