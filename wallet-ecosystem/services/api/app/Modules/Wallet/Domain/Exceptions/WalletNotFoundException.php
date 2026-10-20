<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Exceptions;

use DomainException;

final class WalletNotFoundException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(string $walletId)
    {
        parent::__construct("Wallet not found: {$walletId}.");
        $this->errorCode  = 'WALLET_NOT_FOUND';
        $this->httpStatus = 404;
    }
}
