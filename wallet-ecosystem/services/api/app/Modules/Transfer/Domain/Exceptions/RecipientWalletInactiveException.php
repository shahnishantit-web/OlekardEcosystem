<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Exceptions;

final class RecipientWalletInactiveException extends \RuntimeException
{
    public function __construct(string $walletId)
    {
        parent::__construct("Recipient wallet [{$walletId}] is not active.");
    }
}
