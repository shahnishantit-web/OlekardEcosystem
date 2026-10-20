<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Exceptions;

final class TransferNotModifiableException extends \RuntimeException
{
    public static function forId(string $id, string $status): self
    {
        return new self("Transfer [{$id}] cannot be modified (status: {$status}).");
    }
}
