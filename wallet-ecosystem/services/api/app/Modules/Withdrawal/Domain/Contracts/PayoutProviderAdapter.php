<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Contracts;

interface PayoutProviderAdapter
{
    public function initiate(array $transaction): array;

    public function verifyCallback(array $payload, string $signature, string $provider): array;

    /**
     * Re-query the provider for the current status of an in-flight payout.
     * Used by orphan recovery (E27 T075) when a transaction is stuck past its timeout.
     *
     * @param string $providerReferenceId External reference returned by initiate()
     * @return array Includes: status ('completed'|'failed'|'ambiguous'), amount (int|null), currency (string|null)
     */
    public function queryStatus(string $providerReferenceId): array;
}
