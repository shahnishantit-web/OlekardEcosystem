<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\OutboxDeadLetterServiceInterface;
use App\Modules\Platform\Domain\Exceptions\OutboxConflictException;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OutboxDeadLetterService implements OutboxDeadLetterServiceInterface
{
    public function __construct(private readonly AuditServiceInterface $audit) {}

    /**
     * Reset a dead-letter outbox event to pending and write an audit record.
     * Idempotent: if already pending, returns current state without a second audit write.
     *
     * @throws ModelNotFoundException if no outbox event with the given ID exists
     * @throws OutboxConflictException if the event is not in dead_letter status
     */
    public function replay(string $id, string $actorId): OutboxEventModel
    {
        $event = OutboxEventModel::find($id);

        if ($event === null) {
            throw new ModelNotFoundException("Outbox event [{$id}] not found.");
        }

        if ((string) $event->getAttribute('status') === 'pending') {
            return $event;
        }

        if ((string) $event->getAttribute('status') !== 'dead_letter') {
            throw new OutboxConflictException(
                "Outbox event [{$id}] cannot be replayed: status is [{$event->status}], expected dead_letter."
            );
        }

        DB::connection('platform')->transaction(function () use ($event, $actorId): void {
            $event->update([
                'status'          => 'pending',
                'attempts'        => 0,
                'next_attempt_at' => now(),
            ]);

            $this->audit->record(new AuditEventData(
                action:       'outbox.dead_letter.replay',
                actorType:    'admin',
                resourceType: 'OutboxEvent',
                correlationId: (string) ($event->getAttribute('correlation_id') ?? ''),
                outcome:      'success',
                actorId:      $actorId,
                resourceId:   (string) $event->getAttribute('id'),
                metadata:     [
                    'event_name'      => $event->getAttribute('event_name'),
                    'aggregate_id'    => $event->getAttribute('aggregate_id'),
                    'previous_status' => 'dead_letter',
                    'new_status'      => 'pending',
                ],
            ));
        });

        $event->refresh();
        return $event;
    }

    public function listDeadLetters(array $filters, int $perPage): array
    {
        $query = OutboxEventModel::query()->where('status', 'dead_letter');

        if (! empty($filters['event_name'])) {
            $query->where('event_name', $filters['event_name']);
        }

        if (! empty($filters['correlation_id'])) {
            $query->where('correlation_id', $filters['correlation_id']);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $items = $paginator->map(static fn (OutboxEventModel $row) => [
            'id'             => $row->getAttribute('id'),
            'event_name'     => $row->getAttribute('event_name'),
            'aggregate_id'   => $row->getAttribute('aggregate_id'),
            'aggregate_type' => $row->getAttribute('aggregate_type'),
            'attempts'       => $row->getAttribute('attempts'),
            'last_error'     => $row->getAttribute('last_error'),
            'correlation_id' => $row->getAttribute('correlation_id'),
            'causation_id'   => $row->getAttribute('causation_id'),
            'created_at'     => $row->getAttribute('created_at')?->toIso8601String(),
            'updated_at'     => null,
        ]);

        return [
            'data' => $items->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }
}
