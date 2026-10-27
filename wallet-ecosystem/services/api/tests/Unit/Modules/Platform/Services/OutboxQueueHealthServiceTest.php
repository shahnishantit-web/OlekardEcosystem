<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Platform\Services;

use App\Modules\Platform\Application\Services\OutboxQueueHealthService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OutboxQueueHealthServiceTest extends TestCase
{
    public function test_returns_metrics_with_correct_shape(): void
    {
        DB::shouldReceive('connection->select')
            ->once()
            ->andReturn([$this->fakeRow('payment.confirmed', 2, 1, 0, now()->subMinutes(5)->toIso8601String())]);

        $service = new OutboxQueueHealthService();
        $result  = $service->getHealthMetrics();

        $this->assertCount(1, $result);
        $row = $result[0];
        $this->assertArrayHasKey('event_name', $row);
        $this->assertArrayHasKey('pending_count', $row);
        $this->assertArrayHasKey('dead_letter_count', $row);
        $this->assertArrayHasKey('stuck_count', $row);
        $this->assertArrayHasKey('oldest_pending_age_seconds', $row);
        $this->assertSame('payment.confirmed', $row['event_name']);
        $this->assertSame(2, $row['pending_count']);
        $this->assertSame(1, $row['dead_letter_count']);
    }

    public function test_filters_by_event_name(): void
    {
        DB::shouldReceive('connection->select')
            ->once()
            ->withArgs(static function (string $sql, array $bindings): bool {
                return str_contains($sql, 'WHERE event_name = ?') && $bindings === ['payment.failed'];
            })
            ->andReturn([]);

        $service = new OutboxQueueHealthService();
        $result  = $service->getHealthMetrics('payment.failed');

        $this->assertSame([], $result);
    }

    public function test_stuck_count_reflects_stale_processing_rows(): void
    {
        DB::shouldReceive('connection->select')
            ->once()
            ->andReturn([$this->fakeRow('payment.confirmed', 0, 0, 2, null)]);

        $service = new OutboxQueueHealthService();
        $result  = $service->getHealthMetrics();

        $this->assertSame(2, $result[0]['stuck_count']);
    }

    public function test_returns_empty_when_no_events(): void
    {
        DB::shouldReceive('connection->select')->once()->andReturn([]);

        $service = new OutboxQueueHealthService();
        $result  = $service->getHealthMetrics();

        $this->assertSame([], $result);
    }

    private function fakeRow(
        string  $eventName,
        int     $pendingCount,
        int     $deadLetterCount,
        int     $stuckCount,
        ?string $oldestPendingAt,
    ): object {
        return (object) [
            'event_name'       => $eventName,
            'pending_count'    => $pendingCount,
            'dead_letter_count' => $deadLetterCount,
            'stuck_count'      => $stuckCount,
            'oldest_pending_at' => $oldestPendingAt,
        ];
    }
}
