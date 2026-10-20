<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class TestEventCommand extends Command
{
    protected $signature = 'platform:test-event';

    protected $description = 'Publish a test integration event for quickstart validation (local/testing only)';

    public function handle(OutboxEventPublisher $publisher): int
    {
        $correlationId = (string) \Ramsey\Uuid\Uuid::uuid7();

        $event = new class($correlationId) extends BaseIntegrationEvent {
            public function __construct(string $correlationId)
            {
                parent::__construct(
                    aggregateId: (string) \Ramsey\Uuid\Uuid::uuid7(),
                    actorId: null,
                    correlationId: $correlationId,
                );
            }

            public function eventName(): string
            {
                return 'Platform.Foundation.TestEventTriggered';
            }

            public function aggregateType(): string
            {
                return 'Platform';
            }

            public function payload(): array
            {
                return ['triggered_by' => 'platform:test-event'];
            }
        };

        $eventId = null;

        DB::connection('platform')->transaction(function () use ($publisher, $event, &$eventId) {
            $publisher->publish($event);
            $eventId = $event->eventId();
        });

        $this->info("Test event published: {$eventId}");

        return self::SUCCESS;
    }
}
