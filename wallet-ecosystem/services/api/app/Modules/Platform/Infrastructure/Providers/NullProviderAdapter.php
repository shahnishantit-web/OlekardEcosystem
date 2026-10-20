<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Providers;

use App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderHealthInterface;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderError;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderHealthStatus;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderResponse;

/**
 * Configurable in-code test double implementing ProviderAdapterInterface.
 *
 * MUST NOT be registered as the live container binding in production.
 * For local development and testing only.
 */
final class NullProviderAdapter implements ProviderAdapterInterface, ProviderHealthInterface
{
    public function __construct(
        private readonly ProviderResponse $responseToReturn,
        private readonly ProviderCapabilitySet $capabilities,
        private readonly string $providerIdentifier = 'null',
    ) {}

    public static function alwaysSucceed(ProviderCapabilitySet $caps): self
    {
        return new self(
            responseToReturn: ProviderResponse::success(
                providerRef: 'null-ref',
                rawCode: '200',
                correlationId: 'null-correlation',
            ),
            capabilities: $caps,
        );
    }

    public static function alwaysFail(ProviderError $error, ProviderCapabilitySet $caps): self
    {
        return new self(
            responseToReturn: ProviderResponse::failure($error, 'PROVIDER_ERROR', 'null-correlation'),
            capabilities: $caps,
        );
    }

    public function send(ProviderRequest $request): ProviderResponse
    {
        return $this->responseToReturn;
    }

    public function getCapabilities(): ProviderCapabilitySet
    {
        return $this->capabilities;
    }

    public function getProviderIdentifier(): string
    {
        return $this->providerIdentifier;
    }

    public function getHealthStatus(): ProviderHealthStatus
    {
        return ProviderHealthStatus::up(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }
}
