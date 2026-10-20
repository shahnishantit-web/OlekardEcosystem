<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Enums\ReconciliationExceptionType;
use App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * T142: Reconciliation exception lifecycle.
 *
 * NO mark-as-paid path. Financial state changes only via verified provider evidence.
 * All resolution actions are audited.
 */
final class ReconciliationExceptionService
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
    ) {
    }

    /**
     * Create a new reconciliation exception.
     *
     * @param array<string, mixed> $data
     */
    public function create(
        ReconciliationExceptionType $type,
        array $data,
    ): ReconciliationExceptionModel {
        return DB::connection('payment')->transaction(function () use ($type, $data): ReconciliationExceptionModel {
            $exception = new ReconciliationExceptionModel();
            $exception->id              = (string) Uuid::uuid7();
            $exception->exception_type  = $type;
            $exception->payment_intent_id = $data['payment_intent_id'] ?? null;
            $exception->payment_attempt_id = $data['payment_attempt_id'] ?? null;
            $exception->provider_code   = $data['provider_code'] ?? null;
            $exception->provider_reference_id = $data['provider_reference_id'] ?? null;
            $exception->detected_amount_minor = isset($data['detected_amount_minor'])
                ? (int) $data['detected_amount_minor']
                : null;
            $exception->expected_amount_minor = isset($data['expected_amount_minor'])
                ? (int) $data['expected_amount_minor']
                : null;
            $exception->currency_code   = $data['currency_code'] ?? null;
            $exception->description     = $data['description'] ?? $type->value;
            $exception->status          = 'open';
            $exception->save();

            Log::info('ReconciliationException created', [
                'id'   => $exception->id,
                'type' => $type->value,
            ]);

            return $exception;
        });
    }

    /**
     * Resolve a reconciliation exception.
     *
     * This does NOT perform any financial state change — it records the human decision
     * that the exception has been investigated and the outcome is understood.
     */
    public function resolve(
        string $exceptionId,
        string $resolvedBy,
        string $reason,
        string $notes = '',
    ): void {
        DB::connection('payment')->transaction(function () use (
            $exceptionId, $resolvedBy, $reason, $notes
        ): void {
            /** @var ReconciliationExceptionModel|null $exception */
            $exception = ReconciliationExceptionModel::query()->find($exceptionId);

            if ($exception === null) {
                throw new \RuntimeException("ReconciliationException not found: {$exceptionId}");
            }

            if ($exception->status === 'resolved') {
                return; // Idempotent
            }

            $exception->status              = 'resolved';
            $exception->resolved_by         = $resolvedBy;
            $exception->resolved_at         = now();
            $exception->resolution_reason   = $reason;
            $exception->investigation_notes = $notes;
            $exception->save();

            $this->audit->record(new AuditEventData(
                action:        'reconciliation.exception.resolved',
                actorId:       $resolvedBy,
                actorType:     'user',
                resourceType:  'reconciliation_exception',
                resourceId:    $exceptionId,
                correlationId: $exceptionId,
                outcome:       'success',
                afterState:    [
                    'exception_id'   => $exceptionId,
                    'resolution'     => $reason,
                    'resolved_by'    => $resolvedBy,
                ],
            ));
        });
    }

    /**
     * Escalate an exception to a higher support tier.
     */
    public function escalate(string $exceptionId, string $actorId): void
    {
        DB::connection('payment')->transaction(function () use ($exceptionId, $actorId): void {
            /** @var ReconciliationExceptionModel|null $exception */
            $exception = ReconciliationExceptionModel::query()->find($exceptionId);

            if ($exception === null) {
                throw new \RuntimeException("ReconciliationException not found: {$exceptionId}");
            }

            $exception->status = 'escalated';
            $exception->investigation_notes = ($exception->investigation_notes ?? '')
                ."\n[Escalated by {$actorId} at ".now()->toIso8601String().']';
            $exception->save();

            $this->audit->record(new AuditEventData(
                action:        'reconciliation.exception.escalated',
                actorId:       $actorId,
                actorType:     'user',
                resourceType:  'reconciliation_exception',
                resourceId:    $exceptionId,
                correlationId: $exceptionId,
                outcome:       'success',
                afterState:    ['exception_id' => $exceptionId, 'escalated_by' => $actorId],
            ));
        });
    }
}
