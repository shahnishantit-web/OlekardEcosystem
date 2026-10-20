<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class WithdrawalCompleted implements IntegrationEventInterface
{
    public function __construct(
        public string $withdrawalTransactionId,
        public string $walletId,
        public string $currencyCode,
        public int    $grossAmount,
        public int    $feeAmount,
        public int    $netPayoutAmount,
        public string $channel,
        public string $ledgerJournalId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Withdrawal.Transaction.Completed'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'withdrawal_transaction_id' => $this->withdrawalTransactionId,
            'wallet_id'                 => $this->walletId,
            'currency_code'             => $this->currencyCode,
            'gross_amount'              => $this->grossAmount,
            'fee_amount'                => $this->feeAmount,
            'net_payout_amount'         => $this->netPayoutAmount,
            'channel'                   => $this->channel,
            'ledger_journal_id'         => $this->ledgerJournalId,
            'occurred_at'               => $this->occurredAt,
            'correlation_id'            => $this->correlationId,
        ];
    }
}
