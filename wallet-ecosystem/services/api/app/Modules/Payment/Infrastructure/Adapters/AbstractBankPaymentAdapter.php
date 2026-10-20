<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Adapters;

use App\Modules\Payment\Domain\Contracts\PaymentAdapterInterface;
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
     * Subclasses override this to execute the actual HTTP request.
     */
    protected function executeRequest(ProviderRequest $request): ProviderResponse
    {
        throw new \LogicException(static::class.' must override executeRequest() to make actual HTTP calls.');
    }
}
