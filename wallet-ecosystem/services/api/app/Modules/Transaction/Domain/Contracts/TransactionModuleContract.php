<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Contracts;

interface TransactionModuleContract
{
    /**
     * Generate a platform-unique transaction reference string
     * (format defined by E11; guaranteed to be stable and sortable).
     */
    public function generateReference(): string;

    /**
     * Returns the current lifecycle status of the given transaction reference.
     * Returns null if the reference is not found.
     */
    public function status(string $transactionReference): ?string;

    /**
     * List transactions for compliance review (E23 dependency).
     * Returns a page array with keys: data (array of transaction items), next_cursor (?string).
     * Each item: transaction_id, transaction_type, amount_minor, currency_code, outcome, occurred_at, user_id.
     * Accepted filters: user_id, from, to, transaction_type, outcome.
     */
    public function listForCompliance(array $filters, ?string $cursor, int $perPage): array;
}
