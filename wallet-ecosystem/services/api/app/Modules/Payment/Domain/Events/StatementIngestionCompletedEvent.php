<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class StatementIngestionCompletedEvent implements IntegrationEventInterface
{
    public function __construct(
        private string $statementId,
        private string $providerCode,
        private string $accountId,
        private string $environment,
        private string $currencyCode,
        private string $periodStart,
        private string $periodEnd,
        private int    $totalLines,
        private string $sourceHash,
        private string $ingestedAt,
    ) {}

    public function eventName(): string
    {
        return 'payment.statement.ingestion_completed';
    }

    public function occurredAt(): string
    {
        return $this->ingestedAt;
    }

    public function correlationId(): string
    {
        return $this->statementId;
    }

    public function aggregateType(): string
    {
        return 'provider_statement';
    }

    public function aggregateId(): string
    {
        return $this->statementId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event'          => $this->eventName(),
            'statement_id'   => $this->statementId,
            'provider_code'  => $this->providerCode,
            'account_id'     => $this->accountId,
            'environment'    => $this->environment,
            'currency_code'  => $this->currencyCode,
            'period_start'   => $this->periodStart,
            'period_end'     => $this->periodEnd,
            'total_lines'    => $this->totalLines,
            'source_hash'    => $this->sourceHash,
            'ingested_at'    => $this->ingestedAt,
        ];
    }
}
