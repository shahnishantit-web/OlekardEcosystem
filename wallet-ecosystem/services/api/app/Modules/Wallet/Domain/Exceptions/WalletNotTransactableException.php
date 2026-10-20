<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Exceptions;

use App\Modules\Wallet\Domain\Enums\WalletStatus;
use DomainException;

class WalletNotTransactableException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(public readonly WalletStatus $status)
    {
        parent::__construct("Wallet is not transactable in status: {$status->value}.");
        $this->errorCode  = 'WALLET_NOT_TRANSACTABLE';
        $this->httpStatus = 422;
    }
}
