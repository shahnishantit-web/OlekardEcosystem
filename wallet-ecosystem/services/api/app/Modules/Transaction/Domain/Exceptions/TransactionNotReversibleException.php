<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Exceptions;

use RuntimeException;

final class TransactionNotReversibleException extends RuntimeException
{
    public static function notCompleted(string $id): self
    {
        return new self("Transaction [{$id}] is not in a completed state and cannot be reversed.");
    }

    public static function typeNotSupported(string $type): self
    {
        return new self("Transaction type [{$type}] is not eligible for reversal.");
    }

    public static function activeReversalExists(string $transactionId): self
    {
        return new self("Transaction [{$transactionId}] already has an active reversal in progress.");
    }
}
