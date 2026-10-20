<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Exceptions;

use DomainException;

final class WalletHasBalanceException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct()
    {
        parent::__construct('Cannot close wallet: one or more pockets have a non-zero balance or active holds.');
        $this->errorCode  = 'WALLET_HAS_BALANCE';
        $this->httpStatus = 422;
    }
}
