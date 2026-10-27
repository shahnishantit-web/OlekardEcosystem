<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Adapters;

use App\Modules\Payment\Application\Services\CredentialRotationService;
use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
use App\Modules\Payment\Domain\ValueObjects\ApiOperationConfig;
use App\Modules\Payment\Infrastructure\Models\ApiOperationConfigModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderResponse;
use Illuminate\Support\Facades\Log;

/**
 * T099: Abstract base for bank payment adapters.
 * Subclasses provide: getBaseUrl(), getAuthHeaders(), getSignatureAlgorithm(), getCallbackSecretReference().
 * NEVER logs credential values.
 */
abstract class AbstractBankPaymentAdapter implements PaymentAdapterInterface
{
    public function __construct(
        protected readonly AuditServiceInterface       $audit,
        protected readonly ConfigurationServiceInterface $config,
        protected readonly ?CredentialRotationService  $credentialRotationService = null,
    ) {
    }

    /**
     * Base URL for the bank's API.
     */
    abstract protected function getBaseUrl(): string;

    /**
     * Authorization headers (API key, Bearer token, etc.) — values from secrets manager only.
     *
     * @return array<string, string>
     */
    abstract protected function getAuthHeaders(): array;

    /**
     * HMAC algorithm used for webhook signature verification (e.g., 'sha256', 'sha512').
     */
    abstract protected function getSignatureAlgorithm(): string;

    /**
     * Reference key in secrets manager for the webhook callback secret.
     * This is a reference, NOT the actual secret value.
     */
    abstract protected function getCallbackSecretReference(): string;

    /**
     * T099: Base send() implementation for bank adapters.
     * Emits audit event and logs per FR-E1000-020; NEVER logs credentials.
     */
    public function send(ProviderRequest $request): ProviderResponse
    {
        $startedAt = microtime(true);

        try {
            $response = $this->executeRequest($request);

            $this->audit->record(new AuditEventData(
                action:        'provider.request.sent',
                actorId:       'system',
                actorType:     'system',
                resourceType:  'provider_request',
                resourceId:    $request->correlationId ?? '',
                correlationId: $request->correlationId ?? '',
                outcome:       'success',
                afterState:    [
                    'provider'      => $this->getProviderIdentifier(),
                    'operation'     => $request->operationType,
                    'duration_ms'   => (int) ((microtime(true) - $startedAt) * 1000),
                ],
            ));

            return $response;
        } catch (\Throwable $e) {
            $this->audit->record(new AuditEventData(
                action:        'provider.request.failed',
                actorId:       'system',
                actorType:     'system',
                resourceType:  'provider_request',
                resourceId:    $request->correlationId ?? '',
                correlationId: $request->correlationId ?? '',
                outcome:       'failure',
                afterState:    [
                    'provider'    => $this->getProviderIdentifier(),
                    'error_class' => get_class($e),
                    // NEVER log message — may contain credentials or sensitive data
                ],
            ));

            throw $e;
        }
    }

    /**
     * T044b: Load operation configuration from storage for the given account + operation + version.
     * Replaces hard-coded URL/method/mapping constants in adapter dispatch flow (FR-008 compliance).
     */
    protected function loadOperationConfig(
        string $accountId,
        string $operation,
        ?string $configVersionId = null,
    ): ApiOperationConfig {
        $query = ApiOperationConfigModel::query()
            ->where('account_id', $accountId)
            ->where('operation_type', $operation);

        if ($configVersionId !== null) {
            $query->where('config_version_id', $configVersionId);
        } else {
            $query->whereNull('config_version_id');
        }

        $model = $query->first();

        if ($model === null) {
            throw new \RuntimeException(
                sprintf('No operation config found for account %s, operation %s', $accountId, $operation)
            );
        }

        // FR-014: secret_key_references contains key names only — never raw values
        return new ApiOperationConfig(
            id:                          $model->id,
            accountId:                   $model->account_id,
            configVersionId:             $model->config_version_id,
            operationType:               $model->operation_type,
            baseUrl:                     $model->base_url,
            endpointPath:                $model->endpoint_path,
            httpMethod:                  $model->http_method,
            contentType:                 $model->content_type,
            nonSecretHeaders:            $model->non_secret_headers,
            authProfile:                 $model->auth_profile,
            secretKeyReferences:         $model->secret_key_references,
            requestFieldMappings:        $model->request_field_mappings,
            responseFieldMappings:       $model->response_field_mappings,
            providerTxnRefMapping:       $model->provider_txn_ref_mapping,
            idempotencyRefMapping:       $model->idempotency_ref_mapping,
            amountFormat:                $model->amount_format,
            currencyFormat:              $model->currency_format,
            successStatusValues:         $model->success_status_values,
            pendingStatusValues:         $model->pending_status_values,
            failureStatusValues:         $model->failure_status_values,
            timeoutMs:                   $model->timeout_ms,
            maxRetries:                  $model->max_retries,
            retryBackoffMs:              $model->retry_backoff_ms,
            callbackVerificationProfile: $model->callback_verification_profile,
            apiVersion:                  $model->api_version,
        );
    }

    /**
     * FR-024: Resolve the correct credential key for an in-flight attempt, using time-aware
     * credential rotation log lookup when a configVersionId is provided.
     */
    protected function resolveCredentialKeyForRole(
        string $accountId,
        string $secretRole,
        ?string $configVersionId,
    ): string {
        if ($this->credentialRotationService !== null) {
            return $this->credentialRotationService->resolveCurrentKeyForRole(
                $accountId,
                $secretRole,
                $configVersionId,
            );
        }

        $config = $this->loadOperationConfig($accountId, 'initiate', $configVersionId);

        return $config->secretKeyReferences[$secretRole]
            ?? throw new \RuntimeException("Secret role '{$secretRole}' not found in operation config for account {$accountId}");
    }

    /**
     * Subclasses override this to execute the actual HTTP request.
     */
    protected function executeRequest(ProviderRequest $request): ProviderResponse
    {
        throw new \LogicException(static::class.' must override executeRequest() to make actual HTTP calls.');
    }
}
