<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Exceptions;

use RuntimeException;

final class WalletReferenceCollisionException extends RuntimeException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(string $message = 'Wallet reference generation failed due to repeated collisions.')
    {
        parent::__construct($message);
        $this->errorCode  = 'WALLET_REFERENCE_COLLISION';
        $this->httpStatus = 500;
    }
}
