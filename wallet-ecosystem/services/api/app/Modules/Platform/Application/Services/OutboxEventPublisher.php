<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class OutboxEventPublisher implements \App\Modules\Platform\Domain\Contracts\ConnectionAwareEventPublisherInterface
{
    public function publish(IntegrationEventInterface $event): void
    {
        $this->publishOnConnection($event, 'platform');
    }

    public function publishOnConnection(IntegrationEventInterface $event, string $connection): void
    {
        // Domain state change + Outbox INSERT MUST be in the same transaction (FR-050).
        if (DB::connection($connection)->transactionLevel() < 1) {
            throw new \LogicException(
                'OutboxEventPublisher::publish() must be called within an active database transaction.'
            );
        }

        DB::connection($connection)->table('platform.outbox_events')->insert(
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
        $id = (string) Uuid::uuid7();

        $maxAttempts = (int) (app(ConfigurationServiceInterface::class)->getOrDefault(
            "events.max_attempts.{$event->eventName()}",
            app(ConfigurationServiceInterface::class)->getOrDefault('events.max_attempts.default', 5),
        ) ?? 5);

        return [
            'id'             => $id,
            'event_name'     => $event->eventName(),
            'event_version'  => '1',
            // Inject eventId so listeners can look up the outbox row for version checking (P11 FR-010).
            'payload'        => json_encode(array_merge($event->toArray(), ['eventId' => $id])),
            'metadata'       => json_encode([
                'event_name'     => $event->eventName(),
                'correlation_id' => $event->correlationId(),
                'occurred_at'    => $event->occurredAt(),
            ]),
            'aggregate_type' => method_exists($event, 'aggregateType') ? $event->aggregateType() : $event->eventName(),
            'aggregate_id'   => method_exists($event, 'aggregateId')   ? $event->aggregateId()   : $event->correlationId(),
            'correlation_id' => $event->correlationId(),
            'causation_id'   => method_exists($event, 'causationId') ? $event->causationId() : null,
            'status'         => 'pending',
            'attempts'       => 0,
            'max_attempts'   => $maxAttempts,
            'created_at'     => now(),
        ];
    }
}
