<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Exceptions;

use DomainException;

final class WalletAlreadyExistsException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(string $ownerId)
    {
        parent::__construct("A wallet already exists for user {$ownerId}.");
        $this->errorCode  = 'WALLET_ALREADY_EXISTS';
        $this->httpStatus = 409;
    }
}
