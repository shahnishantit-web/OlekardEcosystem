<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Exceptions;

use App\Modules\Wallet\Domain\Enums\WalletStatus;
use DomainException;

final class InvalidWalletTransitionException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(
        public readonly WalletStatus $fromStatus,
        public readonly WalletStatus $toStatus,
    ) {
        parent::__construct("Invalid wallet transition: {$fromStatus->value} → {$toStatus->value}.");
        $this->errorCode  = 'INVALID_WALLET_TRANSITION';
        $this->httpStatus = 422;
    }
}
