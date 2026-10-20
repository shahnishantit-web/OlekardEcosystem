<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Exceptions;

use App\Modules\Wallet\Domain\Enums\WalletLimitType;
use DomainException;

final class TransactionLimitExceededException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(
        public readonly WalletLimitType $limitType,
        public readonly int $requested,
        public readonly int $limit,
    ) {
        parent::__construct("Transaction exceeds {$limitType->value} limit: requested={$requested}, limit={$limit}.");
        $this->errorCode  = 'TRANSACTION_LIMIT_EXCEEDED';
        $this->httpStatus = 422;
    }
}
