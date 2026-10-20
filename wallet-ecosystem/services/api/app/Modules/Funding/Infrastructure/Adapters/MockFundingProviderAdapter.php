<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Adapters;

use App\Modules\Funding\Domain\Contracts\FundingProviderAdapter;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;

/**
 * MockFundingProviderAdapter — E08 only, for development and test.
 *
 * Simulates a payment provider:
 * - initiate(): always succeeds (returns MOCK-{id}); configurable failure rate via funding.mock.success_rate
 * - verifyCallback(): validates X-Mock-Signature HMAC-SHA256 with config key funding.mock.hmac_secret
 *
 * Real provider adapters live in E1000 (TBD-E08-005).
 */
final class MockFundingProviderAdapter implements FundingProviderAdapter
{
    private const DEFAULT_HMAC_SECRET = 'mock-secret-change-in-production';

    public function __construct(
        private readonly ConfigurationServiceInterface $config,
    ) {}

    public function initiate(array $transaction): array
    {
        $successRate = (float) ($this->config->get('funding.mock.success_rate') ?? 1.0);
        $succeeds    = (mt_rand(1, 100) / 100.0) <= $successRate;

        if (! $succeeds) {
            return [
                'provider_reference_id' => null,
                'status'                => 'failed',
                'raw_response'          => ['error' => 'mock_provider_declined'],
            ];
        }

        return [
            'provider_reference_id' => 'MOCK-' . $transaction['id'],
            'status'                => 'initiated',
            'raw_response'          => ['mock' => true],
        ];
    }

    public function verifyCallback(array $payload, string $signature, string $provider): array
    {
        $secret        = $this->config->get('funding.mock.hmac_secret') ?? self::DEFAULT_HMAC_SECRET;
        $expectedHmac  = hash_hmac('sha256', json_encode($payload), $secret);
        $signatureValid = hash_equals($expectedHmac, $signature);

        if (! $signatureValid) {
            return ['valid' => false, 'provider_reference_id' => null, 'status' => 'unknown', 'amount' => null, 'currency' => null];
        }

        $status = match ($payload['status'] ?? 'unknown') {
            'success' => 'success',
            'failure' => 'failure',
            default   => 'unknown',
        };

        return [
            'valid'                 => true,
            'provider_reference_id' => $payload['provider_transaction_id'] ?? null,
            'status'                => $status,
            'amount'                => isset($payload['amount']) ? (int) $payload['amount'] : null,
            'currency'              => $payload['currency'] ?? null,
        ];
    }

    public function queryStatus(string $providerReferenceId): array
    {
        // Mock always returns ambiguous — real adapters call provider status API.
        return ['status' => 'ambiguous', 'amount' => null, 'currency' => null];
    }
}
