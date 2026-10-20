<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Reconciliation\Domain\Contracts\ReconciliationMetricsInterface;
use App\Modules\Reconciliation\Domain\Enums\ExceptionStatus;
use App\Modules\Reconciliation\Domain\Enums\ExceptionType;
use App\Modules\Reconciliation\Domain\Enums\ItemStatus;
use App\Modules\Reconciliation\Domain\Models\ReconciliationException;
use App\Modules\Reconciliation\Domain\Models\ReconciliationItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ReconciliationExceptionService
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
        private readonly ReconciliationMetricsInterface $metrics,
    ) {}

    public function createForItem(
        ReconciliationItem $item,
        ExceptionType $exceptionType,
        string $openedBy,
    ): ReconciliationException {
        return DB::connection('reconciliation')->transaction(function () use ($item, $exceptionType, $openedBy): ReconciliationException {
            $exceptionId = (string) Str::orderedUuid();

            $exception = ReconciliationException::on('reconciliation')->create([
                'id'             => $exceptionId,
                'item_id'        => $item->id,
                'batch_id'       => $item->batch_id,
                'exception_type' => $exceptionType->value,
                'status'         => ExceptionStatus::Open->value,
                'opened_by'      => $openedBy,
            ]);

            $item->status = ItemStatus::Exception;
            $item->save();

            $this->metrics->incrementExceptionCreated($exceptionType->value);

            $this->audit->record(new AuditEventData(
                action:        'reconciliation.exception.created',
                actorType:     'system',
                resourceType:  'reconciliation_exception',
                correlationId: $exceptionId,
                outcome:       'success',
                actorId:       $openedBy,
                resourceId:    $exceptionId,
                afterState:    ['exception_type' => $exceptionType->value, 'item_id' => $item->id],
            ));

            return $exception;
        });
    }

    public function listExceptions(
        ?string $status = null,
        ?string $batchId = null,
        ?string $assignedTo = null,
        int $page = 1,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = ReconciliationException::on('reconciliation')
            ->with('item')
            ->orderBy('created_at', 'desc');

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($batchId !== null) {
            $query->where('batch_id', $batchId);
        }

        if ($assignedTo !== null) {
            $query->where('assigned_to', $assignedTo);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function getException(string $exceptionId): ReconciliationException
    {
        $exception = ReconciliationException::on('reconciliation')
            ->with(['item', 'batch'])
            ->find($exceptionId);

        if ($exception === null) {
            throw new NotFoundHttpException("Reconciliation exception {$exceptionId} not found.");
        }

        return $exception;
    }

    public function resolveException(
        string $exceptionId,
        string $resolutionType,
        string $resolution,
        string $resolvedBy,
    ): ReconciliationException {
        $exception = $this->getException($exceptionId);

        if (! in_array($exception->status->value, [ExceptionStatus::Open->value, ExceptionStatus::Investigating->value], true)) {
            throw new UnprocessableEntityHttpException("Exception {$exceptionId} is already {$exception->status->value}.");
        }

        return DB::connection('reconciliation')->transaction(function () use (
            $exception, $resolutionType, $resolution, $resolvedBy
        ): ReconciliationException {
            $newStatus = $resolutionType === 'written_off'
                ? ExceptionStatus::WrittenOff->value
                : ExceptionStatus::Resolved->value;

            $exception->update([
                'status'          => $newStatus,
                'resolution_type' => $resolutionType,
                'resolution'      => $resolution,
                'resolved_by'     => $resolvedBy,
                'resolved_at'     => now(),
            ]);

            $this->metrics->incrementExceptionResolved($resolutionType);

            $this->audit->record(new AuditEventData(
                action:        'reconciliation.exception.resolved',
                actorType:     'admin',
                resourceType:  'reconciliation_exception',
                correlationId: (string) $exception->id,
                outcome:       'success',
                actorId:       $resolvedBy,
                resourceId:    (string) $exception->id,
                afterState:    ['status' => $newStatus, 'resolution_type' => $resolutionType],
            ));

            return $exception->fresh();
        });
    }
}
