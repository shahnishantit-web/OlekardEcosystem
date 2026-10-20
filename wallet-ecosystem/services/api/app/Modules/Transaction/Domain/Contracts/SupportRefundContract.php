<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Contracts;

interface SupportRefundContract
{
    /** Authoritative original under row lock. Must be called within platform transaction. */
    public function original(string $id): ?array;

    /** Persist the immutable linked refund transaction, returning its UUID. */
    public function record(array $original, int $amount, string $journalId, string $disputeId, string $correlationId): string;
}
