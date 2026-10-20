<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Exceptions;

final class CommissionExpenseAccountNotConfiguredException extends \DomainException
{
    public readonly string $errorCode;

    public function __construct(string $currencyCode)
    {
        parent::__construct(
            "Commission expense ledger account is not configured for currency [{$currencyCode}]. " .
            "Set 'ledger.commission_expense_account.{$currencyCode}' in platform configuration."
        );
        $this->errorCode = 'COMMISSION_EXPENSE_ACCOUNT_NOT_CONFIGURED';
    }
}
