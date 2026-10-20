<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Exceptions;

use RuntimeException;

final class FeeRuleConflictException extends RuntimeException
{
    public function __construct(string $transactionType, string $currency, string $subjectType)
    {
        parent::__construct(
            "An active fee rule already exists for transaction_type={$transactionType}, currency={$currency}, subject_type={$subjectType}."
        );
    }
}
