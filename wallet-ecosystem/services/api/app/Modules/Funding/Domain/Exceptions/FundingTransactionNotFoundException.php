<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Exceptions;

final class FundingTransactionNotFoundException extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self("FundingTransaction [{$id}] not found.");
    }
}
