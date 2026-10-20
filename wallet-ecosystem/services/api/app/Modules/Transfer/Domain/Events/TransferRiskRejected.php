<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class TransferRiskRejected implements IntegrationEventInterface
{
    public function __construct(
        public string $transferId,
        public string $senderWalletId,
        public string $currencyCode,
        public int    $grossAmount,
        public string $riskReason,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string    { return 'Transfer.RiskRejected'; }
    public function occurredAt(): string   { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'transfer_id'      => $this->transferId,
            'sender_wallet_id' => $this->senderWalletId,
            'currency_code'    => $this->currencyCode,
            'gross_amount'     => $this->grossAmount,
            'risk_reason'      => $this->riskReason,
            'occurred_at'      => $this->occurredAt,
            'correlation_id'   => $this->correlationId,
        ];
    }
}
