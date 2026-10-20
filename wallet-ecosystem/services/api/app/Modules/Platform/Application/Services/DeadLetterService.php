<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\DeadLetterRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\DeadLetterServiceInterface;
use App\Modules\Platform\Domain\Exceptions\DeadLetterConflictException;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Models\DeadLetterItemModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class DeadLetterService implements DeadLetterServiceInterface
{
    public function __construct(
        private readonly DeadLetterRepositoryInterface $repository,
        private readonly AuditServiceInterface $auditService,
    ) {}

    public function capture(
        string $operationType,
        string $sourceId,
        array $payload,
        string $failureReason,
        int $retryCount,
        array $retryHistory,
        string $correlationId,
        ?Model $sourceModel = null,
    ): DeadLetterItemModel {
        $item = new DeadLetterItemModel([
            'id'             => (string) Uuid::uuid7(),
            'operation_type' => $operationType,
            'source_id'      => $sourceId,
            'payload'        => $payload,
            'failure_reason' => $failureReason,
            'retry_count'    => $retryCount,
            'retry_history'  => $retryHistory,
            'status'         => 'pending',
            'correlation_id' => $correlationId,
            'created_at'     => now(),
            'expires_at'     => now()->addDays(config('reliability.dead_letter_ttl_days', 30)),
        ]);

        DB::connection('platform')->transaction(function () use ($item, $sourceModel): void {
            $this->repository->save($item);

            if ($sourceModel !== null) {
                $sourceModel->dead_lettered_at = now();
                $sourceModel->save();
            }
        });

        return $item;
    }

    public function findById(string $id): ?DeadLetterItemModel
    {
        return $this->repository->findById($id);
    }

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    public function replay(
        string $id,
        string $actorId,
        ?string $note,
        string $correlationId,
    ): DeadLetterItemModel {
        return DB::connection('platform')->transaction(function () use ($id, $actorId, $note, $correlationId): DeadLetterItemModel {
            // lockForUpdate() prevents two concurrent replay calls from both passing the
            // status guard — required by FR-016 "exactly one replay MUST succeed".
            $item = DeadLetterItemModel::where('id', $id)->lockForUpdate()->first();

            if ($item === null || $item->status !== 'pending') {
                throw new DeadLetterConflictException(
                    "Dead-letter item {$id} is not in pending status and cannot be replayed."
                );
            }

            $item->status      = 'replayed';
            $item->replayed_at = now();
            $item->replayed_by = $actorId;
            $this->repository->save($item);

            $this->auditService->record(new AuditEventData(
                action:        'platform.dead_letter.item_replayed',
                actorType:     'ADMIN',
                resourceType:  'dead_letter_item',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $id,
                afterState:    ['status' => 'replayed', 'note' => $note],
            ));

            return $item;
        });
    }

    public function dismiss(
        string $id,
        string $actorId,
        string $reason,
        string $correlationId,
    ): DeadLetterItemModel {
        return DB::connection('platform')->transaction(function () use ($id, $actorId, $reason, $correlationId): DeadLetterItemModel {
            // lockForUpdate() mirrors the replay() guard — prevents concurrent dismiss races (FR-016).
            $item = DeadLetterItemModel::where('id', $id)->lockForUpdate()->first();

            if ($item === null || $item->status !== 'pending') {
                throw new DeadLetterConflictException(
                    "Dead-letter item {$id} is not in pending status and cannot be dismissed."
                );
            }

            $item->status           = 'dismissed';
            $item->dismissed_at     = now();
            $item->dismissed_by     = $actorId;
            $item->dismissal_reason = $reason;
            $this->repository->save($item);

            $this->auditService->record(new AuditEventData(
                action:        'platform.dead_letter.item_dismissed',
                actorType:     'ADMIN',
                resourceType:  'dead_letter_item',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $id,
                afterState:    ['status' => 'dismissed', 'reason' => $reason],
            ));

            return $item;
        });
    }

    public function stats(): array
    {
        $counts = $this->repository->countByStatus();

        return [
            'pending'   => $counts['pending']   ?? 0,
            'replayed'  => $counts['replayed']  ?? 0,
            'dismissed' => $counts['dismissed'] ?? 0,
            'total'     => array_sum($counts),
        ];
    }
}
