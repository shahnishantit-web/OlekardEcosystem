<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Services;

use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Audit\Domain\Events\AuditEventRecordedEvent;
use App\Modules\Audit\Domain\Exceptions\AuditWriteException;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Audit\Infrastructure\Models\AuditEventModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AuditService implements AuditModuleContract
{
    public function __construct(
        private readonly OutboxEventPublisher $outbox,
    ) {}

    public function record(AuditEntry $entry): string
    {
        $inTransaction = DB::connection('platform')->transactionLevel() > 0;

        $perform = function () use ($entry): string {
            try {
                $model = new AuditEventModel([
                    'event_category' => $entry->category->value,
                    'event_type'     => $entry->eventType,
                    'actor_id'       => $entry->actorId,
                    'actor_type'     => $entry->actorType->value,
                    'entity_type'    => $entry->entityType,
                    'entity_id'      => $entry->entityId,
                    'action'         => $entry->action,
                    'outcome'        => $entry->outcome->value,
                    'before_state'   => $entry->beforeState,
                    'after_state'    => $entry->afterState,
                    'metadata'       => $entry->metadata,
                    'correlation_id' => $entry->correlationId,
                    'occurred_at'    => $entry->occurredAt ?? now(),
                ]);

                $model->save();

                $this->outbox->publish(new AuditEventRecordedEvent(
                    auditEventId:  $model->id,
                    eventCategory: $model->event_category,
                    eventType:     $model->event_type,
                    actorId:       $model->actor_id,
                    occurredAtIso: $model->occurred_at->toIso8601String(),
                    correlationId: $model->correlation_id,
                ));

                Log::info('audit.event_recorded', [
                    'audit_event_id' => $model->id,
                    'event_category' => $model->event_category,
                    'event_type'     => $model->event_type,
                    'correlation_id' => $model->correlation_id,
                ]);

                Log::channel('stderr')->debug('metric', [
                    'metric'         => 'audit_events_recorded_total',
                    'event_category' => $model->event_category,
                ]);

                return $model->id;
            } catch (\Throwable $e) {
                throw new AuditWriteException('Failed to record audit event: '.$e->getMessage(), $e);
            }
        };

        if ($inTransaction) {
            return $perform();
        }

        return DB::connection('platform')->transaction($perform);
    }
}
