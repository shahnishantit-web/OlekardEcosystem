<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Services;

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Throwable;

final class WithdrawalMetricsService
{
    public function __construct(private readonly MetricsCollectorInterface $metrics) {}

    public function recordInitiated(string $channel): void
    {
        $this->emit(fn () => $this->metrics->increment('withdrawal.initiated', ['channel' => $channel]));
    }

    public function recordCompleted(string $channel): void
    {
        $this->emit(fn () => $this->metrics->increment('withdrawal.completed', ['channel' => $channel]));
    }

    public function recordFailed(string $channel, string $reason): void
    {
        $this->emit(fn () => $this->metrics->increment('withdrawal.failed', ['channel' => $channel, 'reason' => $reason]));
    }

    public function recordCancelled(string $channel): void
    {
        $this->emit(fn () => $this->metrics->increment('withdrawal.cancelled', ['channel' => $channel]));
    }

    public function recordExpired(): void
    {
        $this->emit(fn () => $this->metrics->increment('withdrawal.expired'));
    }

    public function recordCallbackDuration(string $provider, string $outcome, float $seconds): void
    {
        $this->emit(fn () => $this->metrics->histogram(
            'withdrawal.callback.duration_seconds',
            $seconds,
            ['provider' => $provider, 'outcome' => $outcome]
        ));
    }

    public function recordHoldCreated(string $channel): void
    {
        $this->emit(fn () => $this->metrics->increment('withdrawal.hold.created', ['channel' => $channel]));
    }

    public function recordHoldReleased(string $reason): void
    {
        $this->emit(fn () => $this->metrics->increment('withdrawal.hold.released', ['reason' => $reason]));
    }

    public function recordRiskCheck(string $result): void
    {
        $this->emit(fn () => $this->metrics->increment('withdrawal.risk.check', ['result' => $result]));
    }

    private function emit(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            // Metrics emission failures must never propagate.
        }
    }
}
