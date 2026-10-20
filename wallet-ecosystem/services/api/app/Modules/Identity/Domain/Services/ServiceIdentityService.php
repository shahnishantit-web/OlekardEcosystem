<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Application\Commands\IssueServiceTokenCommand;
use App\Modules\Identity\Application\DTOs\ServiceTokenDTO;
use App\Modules\Identity\Domain\Contracts\TokenServiceContract;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;

/**
 * Service Identity JWT issuance and credential verification.
 *
 * Issues ES256 service-identity JWTs (actor_type=SERVICE) for internal service-to-service calls.
 * Receiving services verify the JWT via JWKS; no E01 round-trip required for verification.
 *
 * Credential mechanism: TBD — current implementation uses a shared secret stored in the platform
 * secret manager (IDENTITY_SERVICE_SECRET env variable). This stub will be replaced when the first
 * real service-to-service integration defines the approved credential type and storage format.
 * The secret MUST NOT be stored in platform.configuration_values.
 *
 * @see contracts/service-token-api.md
 */
final class ServiceIdentityService
{
    public function __construct(
        private readonly TokenServiceContract $tokenService,
    ) {}

    /**
     * Issue a service-identity JWT for an authenticated internal service.
     *
     * @throws InvalidCredentialsException when the provided credential does not match
     */
    public function issue(IssueServiceTokenCommand $cmd, string $credential): ServiceTokenDTO
    {
        if (!$this->verifyCredential($cmd->serviceId, $credential)) {
            throw InvalidCredentialsException::make();
        }

        return $this->tokenService->issueServiceToken(
            serviceId:     $cmd->serviceId,
            scopes:        $cmd->scopes,
            audience:      $cmd->audience,
            onBehalfOf:    $cmd->onBehalfOf,
            correlationId: $cmd->correlationId,
        );
    }

    /**
     * Verify the service credential against the platform secret.
     * Mechanism TBD — reads IDENTITY_SERVICE_SECRET env variable (proxy for secret manager).
     * Uses timing-safe comparison to prevent timing attacks.
     */
    private function verifyCredential(string $serviceId, string $providedSecret): bool
    {
        $expectedSecret = (string) env('IDENTITY_SERVICE_SECRET', '');

        if ($expectedSecret === '' || $providedSecret === '') {
            return false;
        }

        return hash_equals($expectedSecret, $providedSecret);
    }
}
