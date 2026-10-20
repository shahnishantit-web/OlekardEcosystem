<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Exceptions;

final class TransactionNotModifiableException extends \RuntimeException
{
    public function __construct(string $id, string $currentStatus, string $attemptedAction = 'modified')
    {
        parent::__construct(
            "FundingTransaction [{$id}] in status [{$currentStatus}] cannot be [{$attemptedAction}]."
        );
    }

    public static function notPending(string $id, string $currentStatus): self
    {
        return new self($id, $currentStatus, 'confirmed');
    }

    public static function notCancellable(string $id, string $currentStatus): self
    {
        return new self($id, $currentStatus, 'cancelled');
    }
}
