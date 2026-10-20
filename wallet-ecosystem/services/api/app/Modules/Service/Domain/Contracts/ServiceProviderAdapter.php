<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Contracts;

use App\Modules\Service\Domain\DTO\ReferenceValidationResult;
use App\Modules\Service\Domain\DTO\PaymentSubmissionResult;
use App\Modules\Service\Domain\DTO\PaymentStatusResult;
use App\Modules\Service\Domain\DTO\CallbackVerificationResult;

interface ServiceProviderAdapter
{
    /**
     * Validate a customer account/meter reference with the provider.
     * Returns the account name on success, or throws InvalidCustomerReferenceException.
     */
    public function validateReference(string $providerCode, string $customerReference): ReferenceValidationResult;

    /**
     * Submit a payment instruction to the provider.
     * Returns a submission result including the provider reference and initial status.
     */
    public function submitPayment(
        string $providerCode,
        string $transactionId,
        string $customerReference,
        int    $amount,
        string $currencyCode,
        string $idempotencyKey,
    ): PaymentSubmissionResult;

    /**
     * Query the current status of a previously submitted payment.
     */
    public function queryStatus(string $providerCode, string $providerReference): PaymentStatusResult;

    /**
     * Verify and parse an inbound provider callback payload.
     * Returns a structured result or throws on HMAC/signature failure.
     */
    public function verifyCallback(string $providerCode, array $payload, array $headers): CallbackVerificationResult;
}
