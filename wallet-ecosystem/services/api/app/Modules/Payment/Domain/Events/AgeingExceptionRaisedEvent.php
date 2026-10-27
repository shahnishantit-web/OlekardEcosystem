<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class AgeingExceptionRaisedEvent implements IntegrationEventInterface
{
    public function __construct(
        private string $clearingRecordId,
        private string $providerCode,
        private string $currencyCode,
        private int    $thresholdDays,
        private string $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'payment.reconciliation.ageing_exception_raised';
    }

    public function occurredAt(): string
    {
        return $this->occurredAt;
    }

    public function correlationId(): string
    {
        return $this->clearingRecordId;
    }

    public function aggregateType(): string
    {
        return 'clearing_record';
    }

    public function aggregateId(): string
    {
        return $this->clearingRecordId;
    }

    public function idempotencyKey(): string
    {
        return "recon:ageing:{$this->clearingRecordId}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event'              => $this->eventName(),
            'clearing_record_id' => $this->clearingRecordId,
            'provider_code'      => $this->providerCode,
            'currency_code'      => $this->currencyCode,
            'threshold_days'     => $this->thresholdDays,
            'occurred_at'        => $this->occurredAt,
        ];
    }
}
