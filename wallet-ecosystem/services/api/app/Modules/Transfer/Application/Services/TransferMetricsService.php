<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Services;

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;

final class TransferMetricsService
{
    public function __construct(private readonly MetricsCollectorInterface $metrics) {}

    public function transferInitiated(string $currencyCode): void
    {
        $this->metrics->increment('transfer.initiated', ['currency' => $currencyCode]);
    }

    public function transferCompleted(string $currencyCode, int $feeAmount): void
    {
        $this->metrics->increment('transfer.completed', ['currency' => $currencyCode]);
        $this->metrics->histogram('transfer.fee.amount', $feeAmount, ['currency' => $currencyCode]);
    }

    public function transferFailed(string $currencyCode, string $failureReason): void
    {
        $this->metrics->increment('transfer.failed', ['currency' => $currencyCode, 'reason' => $failureReason]);
    }

    public function transferCancelled(string $currencyCode): void
    {
        $this->metrics->increment('transfer.cancelled', ['currency' => $currencyCode]);
    }

    public function transferExpired(int $count): void
    {
        $this->metrics->increment('transfer.expired', ['batch_size' => (string) $count]);
    }

    public function confirmationDuration(float $durationMs, string $currencyCode): void
    {
        $this->metrics->histogram('transfer.confirmation.duration_ms', $durationMs, ['currency' => $currencyCode]);
    }

    public function journalPostDuration(float $durationMs): void
    {
        $this->metrics->histogram('transfer.journal.post.duration_ms', $durationMs);
    }

    public function recipientLookup(bool $found): void
    {
        $this->metrics->increment('transfer.recipient_lookup', ['found' => $found ? 'true' : 'false']);
    }
}
