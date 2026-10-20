<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Withdrawal;

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Withdrawal\Application\Services\WithdrawalMetricsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WithdrawalMetricsServiceTest extends TestCase
{
    private MetricsCollectorInterface&MockObject $collectorMock;
    private WithdrawalMetricsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collectorMock = $this->createMock(MetricsCollectorInterface::class);
        $this->service       = new WithdrawalMetricsService($this->collectorMock);
    }

    /** @test */
    public function record_initiated_delegates_to_collector(): void
    {
        $this->collectorMock->expects($this->once())
            ->method('increment')
            ->with('withdrawal.initiated', ['channel' => 'mobile_money']);

        $this->service->recordInitiated('mobile_money');
    }

    /** @test */
    public function record_completed_delegates_to_collector(): void
    {
        $this->collectorMock->expects($this->once())
            ->method('increment')
            ->with('withdrawal.completed', ['channel' => 'bank_transfer']);

        $this->service->recordCompleted('bank_transfer');
    }

    /** @test */
    public function record_failed_delegates_to_collector_with_reason(): void
    {
        $this->collectorMock->expects($this->once())
            ->method('increment')
            ->with('withdrawal.failed', ['channel' => 'mobile_money', 'reason' => 'provider_declined']);

        $this->service->recordFailed('mobile_money', 'provider_declined');
    }

    /** @test */
    public function record_expired_delegates_to_collector_without_args(): void
    {
        $this->collectorMock->expects($this->once())
            ->method('increment')
            ->with('withdrawal.expired');

        $this->service->recordExpired();
    }

    /** @test */
    public function record_callback_duration_uses_histogram(): void
    {
        $this->collectorMock->expects($this->once())
            ->method('histogram')
            ->with(
                'withdrawal.callback.duration_seconds',
                0.042,
                ['provider' => 'mock', 'outcome' => 'success']
            );

        $this->service->recordCallbackDuration('mock', 'success', 0.042);
    }

    /** @test */
    public function record_risk_check_delegates_to_collector(): void
    {
        $this->collectorMock->expects($this->once())
            ->method('increment')
            ->with('withdrawal.risk.check', ['result' => 'approved']);

        $this->service->recordRiskCheck('approved');
    }

    /** @test */
    public function emit_swallows_exception_from_collector(): void
    {
        $this->collectorMock->method('increment')
            ->willThrowException(new \RuntimeException('Metrics down'));

        // Must not propagate
        $this->service->recordInitiated('mobile_money');
        $this->service->recordCompleted('mobile_money');

        $this->assertTrue(true);
    }

    /** @test */
    public function emit_swallows_throwable_from_collector(): void
    {
        $this->collectorMock->method('histogram')
            ->willThrowException(new \Error('Fatal metrics error'));

        $this->service->recordCallbackDuration('mock', 'success', 0.1);

        $this->assertTrue(true);
    }
}
