<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Platform\Services;

use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class OutboxEventPublisherCausationTest extends TestCase
{
    public function test_causation_id_populated_when_event_implements_method(): void
    {
        $causationId = '01932b00-0000-7000-8000-aabbccddeeff';

        $event = new class ($causationId) implements IntegrationEventInterface {
            public function __construct(private readonly string $causationId) {}
            public function eventName(): string { return 'test.event'; }
            public function occurredAt(): string { return '2026-10-24T10:00:00Z'; }
            public function correlationId(): string { return '01932b00-0000-7000-8000-111111111111'; }
            public function toArray(): array { return ['event' => 'test.event']; }
            public function causationId(): ?string { return $this->causationId; }
        };

        $inserted = $this->captureInsert($event);

        $this->assertSame($causationId, $inserted['causation_id']);
    }

    public function test_causation_id_null_when_event_lacks_method(): void
    {
        $event = new class implements IntegrationEventInterface {
            public function eventName(): string { return 'test.event'; }
            public function occurredAt(): string { return '2026-10-24T10:00:00Z'; }
            public function correlationId(): string { return '01932b00-0000-7000-8000-111111111111'; }
            public function toArray(): array { return ['event' => 'test.event']; }
        };

        $inserted = $this->captureInsert($event);

        $this->assertNull($inserted['causation_id']);
    }

    private function captureInsert(IntegrationEventInterface $event): array
    {
        $inserted = [];

        $configService = $this->createMock(ConfigurationServiceInterface::class);
        $configService->method('getOrDefault')->willReturn(5);
        $this->app->instance(ConfigurationServiceInterface::class, $configService);

        DB::shouldReceive('connection->transactionLevel')->andReturn(1);
        DB::shouldReceive('connection->table->insert')
            ->once()
            ->andReturnUsing(function (array $row) use (&$inserted): bool {
                $inserted = $row;
                return true;
            });

        $publisher = new OutboxEventPublisher();
        $publisher->publishOnConnection($event, 'platform');

        return $inserted;
    }
}
