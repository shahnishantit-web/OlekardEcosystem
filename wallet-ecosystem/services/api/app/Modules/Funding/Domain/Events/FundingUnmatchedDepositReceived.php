<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class FundingUnmatchedDepositReceived implements IntegrationEventInterface
{
    public function __construct(
        public string  $unmatchedDepositId,
        public string  $bankNotificationId,
        public int     $amount,
        public string  $currencyCode,
        public ?string $rawReference,
        public string  $occurredAt,
        public string  $correlationId,
    ) {}

    public function eventName(): string { return 'Funding.UnmatchedDeposit.Received'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'unmatched_deposit_id'   => $this->unmatchedDepositId,
            'bank_notification_id'   => $this->bankNotificationId,
            'amount'                 => $this->amount,
            'currency_code'          => $this->currencyCode,
            'raw_reference'          => $this->rawReference,
            'occurred_at'            => $this->occurredAt,
            'correlation_id'         => $this->correlationId,
        ];
    }
}
