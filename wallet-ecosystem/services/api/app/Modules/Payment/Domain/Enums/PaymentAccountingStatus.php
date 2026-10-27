<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PaymentAccountingStatus: string
{
    case PendingLedger = 'pending_ledger';
    case LedgerPosted  = 'ledger_posted';
    case LedgerFailed  = 'ledger_failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::LedgerPosted,
            self::LedgerFailed => true,
            default            => false,
        };
    }

    /** @return array<self> */
    private static function transitions(): array
    {
        return [
            self::PendingLedger->value => [self::LedgerPosted, self::LedgerFailed],
            self::LedgerPosted->value  => [],
            self::LedgerFailed->value  => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value] ?? [], strict: true);
    }
}
