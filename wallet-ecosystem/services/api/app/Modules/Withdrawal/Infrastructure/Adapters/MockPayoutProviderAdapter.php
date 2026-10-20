<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Infrastructure\Adapters;

use App\Modules\Withdrawal\Domain\Contracts\PayoutProviderAdapter;

final class MockPayoutProviderAdapter implements PayoutProviderAdapter
{
    public function initiate(array $transaction): array
    {
        return [
            'status'               => 'initiated',
            'provider_reference_id' => 'mock-payout-' . $transaction['id'],
            'raw_response'         => [],
        ];
    }

    public function verifyCallback(array $payload, string $signature, string $provider): array
    {
        $valid = ($signature === 'mock-valid-sig');

        return [
            'valid'                 => $valid,
            'provider_reference_id' => $payload['provider_reference_id'] ?? null,
            'status'               => $payload['status'] ?? 'unknown',
            'amount'               => isset($payload['amount']) ? (int) $payload['amount'] : null,
            'currency'             => $payload['currency'] ?? null,
        ];
    }

    public function queryStatus(string $providerReferenceId): array
    {
        // Mock always returns ambiguous — real adapters call provider status API.
        return ['status' => 'ambiguous', 'amount' => null, 'currency' => null];
    }
}
