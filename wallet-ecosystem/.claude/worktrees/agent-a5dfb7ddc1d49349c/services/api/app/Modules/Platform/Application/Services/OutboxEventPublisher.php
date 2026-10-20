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

        $maxAttempts = (int) (app(ConfigurationService::class)->getOrDefault(
            "events.max_attempts.{$event->eventName()}",
            app(ConfigurationService::class)->getOrDefault('events.max_attempts.default', 5),
        ) ?? 5);

        DB::connection('platform')->table('platform.outbox_events')->insert([
            'id'             => (string) Uuid::uuid7(),
            'event_name'     => $event->eventName(),
            'event_version'  => $event->eventVersion(),
            'payload'        => json_encode($event->payload()),
            'metadata'       => json_encode($event->metadata()),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id'   => $event->aggregateId(),
            'correlation_id' => $event->correlationId(),
            'causation_id'   => $event->causationId(),
            'status'         => 'pending',
            'attempts'       => 0,
            'max_attempts'   => $maxAttempts,
            'created_at'     => now(),
        ]);
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

        $rows = array_map(function (IntegrationEventInterface $event): array {
            $maxAttempts = (int) (app(ConfigurationService::class)->getOrDefault(
                "events.max_attempts.{$event->eventName()}",
                app(ConfigurationService::class)->getOrDefault('events.max_attempts.default', 5),
            ) ?? 5);

            return [
                'id'             => (string) Uuid::uuid7(),
                'event_name'     => $event->eventName(),
                'event_version'  => $event->eventVersion(),
                'payload'        => json_encode($event->payload()),
                'metadata'       => json_encode($event->metadata()),
                'aggregate_type' => $event->aggregateType(),
                'aggregate_id'   => $event->aggregateId(),
                'correlation_id' => $event->correlationId(),
                'causation_id'   => $event->causationId(),
                'status'         => 'pending',
                'attempts'       => 0,
                'max_attempts'   => $maxAttempts,
                'created_at'     => now(),
            ];
        }, $events);

        DB::connection('platform')->table('platform.outbox_events')->insert($rows);
    }
}
