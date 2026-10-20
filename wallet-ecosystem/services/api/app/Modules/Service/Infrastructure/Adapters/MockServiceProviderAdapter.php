<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Adapters;

use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\DTO\CallbackVerificationResult;
use App\Modules\Service\Domain\DTO\PaymentStatusResult;
use App\Modules\Service\Domain\DTO\PaymentSubmissionResult;
use App\Modules\Service\Domain\DTO\ReferenceValidationResult;
use Illuminate\Support\Str;

/**
 * Dev/test stub — always returns success. Never use in production.
 */
final class MockServiceProviderAdapter implements ServiceProviderAdapter
{
    public function validateReference(string $providerCode, string $customerReference): ReferenceValidationResult
    {
        // Treat 'INVALID' prefix or blank references as invalid for testing negative paths
        if (str_starts_with(strtoupper($customerReference), 'INVALID') || trim($customerReference) === '') {
            return new ReferenceValidationResult(
                valid: false,
                accountName: '',
                providerMessage: 'INVALID_CUSTOMER_REFERENCE',
            );
        }

        return new ReferenceValidationResult(
            valid: true,
            accountName: 'Mock Account — '.$customerReference,
        );
    }

    public function submitPayment(
        string $providerCode,
        string $transactionId,
        string $customerReference,
        int    $amount,
        string $currencyCode,
        string $idempotencyKey,
    ): PaymentSubmissionResult {
        return new PaymentSubmissionResult(
            providerReference: 'MOCK-'.strtoupper(Str::random(12)),
            status: 'completed',
        );
    }

    public function queryStatus(string $providerCode, string $providerReference): PaymentStatusResult
    {
        return new PaymentStatusResult(
            providerReference: $providerReference,
            status: 'completed',
            isTerminal: true,
        );
    }

    public function verifyCallback(string $providerCode, array $payload, array $headers): CallbackVerificationResult
    {
        return new CallbackVerificationResult(
            verified: true,
            providerReference: $payload['provider_reference'] ?? 'MOCK-'.Str::random(12),
            transactionId: $payload['transaction_id'] ?? '',
            status: $payload['status'] ?? 'completed',
            isTerminal: true,
        );
    }
}
