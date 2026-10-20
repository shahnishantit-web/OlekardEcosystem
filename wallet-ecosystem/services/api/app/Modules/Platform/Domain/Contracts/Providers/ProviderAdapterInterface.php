<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts\Providers;

use App\Modules\Platform\Domain\Exceptions\ProviderException;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderResponse;

interface ProviderAdapterInterface
{
    /**
     * Execute an outbound provider call.
     *
     * Implementors MUST:
     *   1. Return a ProviderResponse — never null. On provider error, the response
     *      carries a ProviderError with isTransient correctly set.
     *   2. Map all provider-specific error codes to ProviderErrorCode enum values
     *      before returning.
     *   3. Emit an audit event via AuditServiceInterface for every call (success
     *      and failure) per FR-E1000-020. The audit event MUST NOT contain
     *      credentials, raw OTP values, or PII beyond the correlation/entity ID.
     *   4. Emit metrics per FR-E1000-021. Metric labels MUST be low-cardinality
     *      and MUST NOT carry user ID, account number, raw IP, or credentials.
     *   5. Never surface provider credentials in the returned ProviderResponse,
     *      any log entry, outbox event, or audit event.
     *   6. Provider-specific timeout configuration (connect/read) is sourced from
     *      platform.configuration_values via ConfigurationServiceInterface; the
     *      adapter MUST NOT hard-code timeout values. Absence of configuration is
     *      treated as a configuration error, not a silent default.
     *      (Ownership of timeout/retry values is deferred to downstream epics —
     *      E1000's role is to mandate the obligation via this PHPDoc.)
     *
     * @throws ProviderException if the provider returns an unrecoverable error
     *                           that cannot be normalized into a ProviderResponse.
     */
    public function send(ProviderRequest $request): ProviderResponse;

    /**
     * Return the static capability declarations for this adapter instance.
     *
     * MUST NOT query the provider API at runtime. Capabilities are declared
     * statically and reflect what the platform's integration supports.
     */
    public function getCapabilities(): ProviderCapabilitySet;

    /**
     * Return the configuration key identifier for this adapter.
     *
     * Example return values: 'mno_primary', 'kyc_vendor', 'otp_gateway_fallback'
     * MUST NOT return a provider brand name, credential, or secret name.
     * MUST match the configuration key used to bind this adapter in the service container.
     */
    public function getProviderIdentifier(): string;
}
