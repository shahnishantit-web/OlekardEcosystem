<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

/**
 * Typed value object representing the configuration for one API operation type.
 * All values are read from storage; never hard-coded in adapter subclasses.
 */
final readonly class ApiOperationConfig
{
    /**
     * @param array<int, array{source_field: string, target_field: string, transform_type: string, required: bool}> $requestFieldMappings
     * @param array<int, array{source_field: string, target_field: string, transform_type: string, required: bool}> $responseFieldMappings
     * @param array<string, string> $secretKeyReferences  role => secret_manager_key_name
     * @param array<string, string> $nonSecretHeaders
     * @param array<string>         $successStatusValues
     * @param array<string>         $pendingStatusValues
     * @param array<string>         $failureStatusValues
     */
    public function __construct(
        public string  $id,
        public string  $accountId,
        public ?string $configVersionId,
        public string  $operationType,
        public string  $baseUrl,
        public string  $endpointPath,
        public string  $httpMethod,
        public string  $contentType,
        public array   $nonSecretHeaders,
        public string  $authProfile,
        public array   $secretKeyReferences,
        public array   $requestFieldMappings,
        public array   $responseFieldMappings,
        public ?string $providerTxnRefMapping,
        public ?string $idempotencyRefMapping,
        public string  $amountFormat,
        public string  $currencyFormat,
        public array   $successStatusValues,
        public array   $pendingStatusValues,
        public array   $failureStatusValues,
        public int     $timeoutMs,
        public int     $maxRetries,
        public int     $retryBackoffMs,
        public ?string $callbackVerificationProfile,
        public ?string $apiVersion,
    ) {
    }

    public function fullUrl(): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($this->endpointPath, '/');
    }

    public function isStatusSuccess(string $providerStatus): bool
    {
        return in_array($providerStatus, $this->successStatusValues, true);
    }

    public function isStatusPending(string $providerStatus): bool
    {
        return in_array($providerStatus, $this->pendingStatusValues, true);
    }

    public function isStatusFailure(string $providerStatus): bool
    {
        return in_array($providerStatus, $this->failureStatusValues, true);
    }

    /**
     * FR-010: Status not in any known bucket → unresolved, never default success.
     */
    public function resolveProviderStatus(string $providerStatus): string
    {
        if ($this->isStatusSuccess($providerStatus)) {
            return 'success';
        }

        if ($this->isStatusFailure($providerStatus)) {
            return 'failure';
        }

        if ($this->isStatusPending($providerStatus)) {
            return 'pending';
        }

        return 'unresolved';
    }
}
