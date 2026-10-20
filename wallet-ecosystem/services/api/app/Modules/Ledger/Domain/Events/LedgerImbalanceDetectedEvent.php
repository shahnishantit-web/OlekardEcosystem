<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class LedgerImbalanceDetectedEvent extends BaseIntegrationEvent
{
    public function __construct(
        string $commandRunId,
        private readonly string $currencyCode,
        private readonly int $debitTotal,
        private readonly int $creditTotal,
        private readonly int $discrepancy,
        private readonly string $detectedAt,
        string $correlationId,
    ) {
        parent::__construct($commandRunId, null, $correlationId);
    }

    public function eventName(): string
    {
        return 'Ledger.Imbalance.Detected';
    }

    public function aggregateType(): string
    {
        return 'LedgerBalance';
    }

    public function payload(): array
    {
        return [
            'currency_code' => $this->currencyCode,
            'debit_total'   => $this->debitTotal,
            'credit_total'  => $this->creditTotal,
            'discrepancy'   => $this->discrepancy,
            'detected_at'   => $this->detectedAt,
        ];
    }
}
