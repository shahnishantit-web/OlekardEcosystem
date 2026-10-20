<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Exceptions;

use App\Modules\Wallet\Domain\Enums\WalletStatus;

final class WalletFrozenException extends WalletNotTransactableException
{
    public function __construct()
    {
        parent::__construct(WalletStatus::Frozen);
        $this->errorCode  = 'WALLET_FROZEN';
    }
}
