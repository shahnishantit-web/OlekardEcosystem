<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Contracts;

/**
 * FundingProviderAdapter — abstract interface for payment provider integrations.
 *
 * Each payment provider (mobile money operator, card processor, bank) implements
 * this interface. E08 defines the interface and provides MockFundingProviderAdapter.
 * Real adapters are in specs/1000-provider-adapter-foundation/ (TBD-E08-005).
 *
 * Bound in FundingServiceProvider keyed by funding method:
 *   App::bind('funding.adapter.mobile_money', MockFundingProviderAdapter::class);
 *   App::bind('funding.adapter.bank_transfer', MockFundingProviderAdapter::class);
 *   App::bind('funding.adapter.card',          MockFundingProviderAdapter::class);
 *
 * Resolved by FundingConfirmationService:
 *   $adapter = App::make("funding.adapter.{$tx->funding_method->value}");
 */
interface FundingProviderAdapter
{
    /**
     * Initiates a payment request with the provider.
     *
     * Called synchronously after the customer confirms the transaction.
     * The FundingTransaction is already in 'processing' status at call time.
     *
     * @param array $transaction Snapshot of the FundingTransaction:
     *   'id'               => string  (UUID v7)
     *   'amount'           => int     (gross amount, minor units)
     *   'net_amount'       => int     (net amount after fee, minor units)
     *   'currency_code'    => string
     *   'customer_id'      => string
     *   'idempotency_key'  => string  (use as provider-side idempotency reference)
     *
     * @return ProviderInitiateResult with:
     *   'success'              => bool
     *   'provider_reference_id'=> string  (provider's transaction ID; empty on failure)
     *   'failure_reason'       => string|null
     *
     * MUST be idempotent: if called twice with the same idempotency_key, the provider
     * returns the same provider_reference_id without double-charging.
     *
     * On connectivity error: throw \RuntimeException; the caller enqueues a retry job.
     * On provider rejection (e.g., invalid credentials): return success=false.
     */
    public function initiate(array $transaction): array;

    /**
     * Verifies the authenticity and integrity of an incoming provider callback.
     *
     * Called before any state mutation on a provider callback request.
     *
     * @param array  $payload    Raw parsed request body
     * @param string $signature  Value of the provider's signature header (e.g., X-Provider-Sig)
     * @param string $provider   Provider identifier string (e.g., 'mpesa', 'mock')
     *
     * @return array with:
     *   'valid'                 => bool
     *   'provider_reference_id' => string|null   (extracted from payload on success)
     *   'amount'                => int|null       (amount in minor units; from payload)
     *   'currency_code'         => string|null
     *   'status'                => string|null    ('success'|'failure')
     *   'failure_reason'        => string|null
     *
     * Returns ['valid' => false, ...nulls] on invalid signature. MUST NOT throw.
     */
    public function verifyCallback(array $payload, string $signature, string $provider): array;
}
