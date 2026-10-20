<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Contracts;

interface FundingProviderAdapter
{
    /**
     * Initiate a funding request with the external provider.
     *
     * @param array $transaction Includes: id, amount, currency_code, funding_method, transaction_reference, correlation_id
     * @return array             Includes: provider_reference_id, status ('initiated'|'failed'), raw_response
     */
    public function initiate(array $transaction): array;

    /**
     * Verify the provider callback's HMAC/signature and parse the payload.
     *
     * @param array  $payload   Raw callback body parsed as associative array
     * @param string $signature Signature value from the callback header (e.g., X-Signature)
     * @param string $provider  Provider identifier string (e.g., 'mock', 'mpesa')
     * @return array            Includes: valid (bool), provider_reference_id (string|null),
     *                          status ('success'|'failure'|'unknown'), amount (int|null),
     *                          currency (string|null)
     */
    public function verifyCallback(array $payload, string $signature, string $provider): array;

    /**
     * Re-query the provider for the current status of an in-flight transaction.
     * Used by orphan recovery (E27 T075) when a transaction is stuck past its timeout.
     *
     * @param string $providerReferenceId External reference returned by initiate()
     * @return array Includes: status ('completed'|'failed'|'ambiguous'), amount (int|null), currency (string|null)
     */
    public function queryStatus(string $providerReferenceId): array;
}
