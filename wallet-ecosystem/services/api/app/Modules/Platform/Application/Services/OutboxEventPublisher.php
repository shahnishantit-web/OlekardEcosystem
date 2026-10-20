<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class OutboxEventPublisher implements EventPublisherInterface
{
    public function publish(IntegrationEventInterface $event): void
    {
        // Domain state change + Outbox INSERT MUST be in the same transaction (FR-050).
        if (DB::connection('platform')->transactionLevel() < 1) {
            throw new \LogicException(
                'OutboxEventPublisher::publish() must be called within an active database transaction.'
            );
        }

        DB::connection('platform')->table('platform.outbox_events')->insert(
            $this->buildRow($event)
        );
    }

    public function publishAll(array $events): void
    {
        if (DB::connection('platform')->transactionLevel() < 1) {
            throw new \LogicException(
                'OutboxEventPublisher::publishAll() must be called within an active database transaction.'
            );
        }

        if (empty($events)) {
            return;
        }

        DB::connection('platform')->table('platform.outbox_events')->insert(
            array_map(fn (IntegrationEventInterface $e) => $this->buildRow($e), $events)
        );
    }

    private function buildRow(IntegrationEventInterface $event): array
    {
        $maxAttempts = (int) (app(ConfigurationService::class)->getOrDefault(
            "events.max_attempts.{$event->eventName()}",
            app(ConfigurationService::class)->getOrDefault('events.max_attempts.default', 5),
        ) ?? 5);

        return [
            'id'             => (string) Uuid::uuid7(),
            'event_name'     => $event->eventName(),
            'event_version'  => '1',
            'payload'        => json_encode($event->toArray()),
            'metadata'       => json_encode([
                'event_name'     => $event->eventName(),
                'correlation_id' => $event->correlationId(),
                'occurred_at'    => $event->occurredAt(),
            ]),
            'aggregate_type' => method_exists($event, 'aggregateType') ? $event->aggregateType() : $event->eventName(),
            'aggregate_id'   => method_exists($event, 'aggregateId')   ? $event->aggregateId()   : $event->correlationId(),
            'correlation_id' => $event->correlationId(),
            'causation_id'   => null,
            'status'         => 'pending',
            'attempts'       => 0,
            'max_attempts'   => $maxAttempts,
            'created_at'     => now(),
        ];
    }
}
