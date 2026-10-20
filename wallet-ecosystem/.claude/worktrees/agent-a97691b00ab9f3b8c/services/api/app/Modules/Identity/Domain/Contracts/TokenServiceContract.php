<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Application\DTOs\AuthenticatedSessionDTO;
use App\Modules\Identity\Application\DTOs\ServiceTokenDTO;
use App\Modules\Identity\Application\DTOs\StepUpTokenDTO;
use App\Modules\Identity\Infrastructure\Models\User;

interface TokenServiceContract
{
    /**
     * Issue an access token and refresh token for a newly authenticated user.
     * Creates a session record and returns the full token pair.
     */
    public function issueTokenPair(
        User   $user,
        string $deviceId,
        string $actorType,
        string $ipHash,
        string $correlationId,
    ): AuthenticatedSessionDTO;

    /**
     * Issue a service-identity JWT for an authenticated internal service.
     * actor_type = SERVICE; aud = audience; jti unique per issuance.
     * TTL read from ConfigurationSecurityService (auth.service_token.ttl; TBD).
     */
    public function issueServiceToken(
        string  $serviceId,
        array   $scopes,
        string  $audience,
        ?string $onBehalfOf,
        string  $correlationId,
    ): ServiceTokenDTO;

    /**
     * Issue a purpose-scoped step-up JWT.
     * aud = "step-up:{scope}" — MUST NOT be accepted by general-access endpoints.
     * TTL is short and read from ConfigurationSecurityService (auth.step_up.token_ttl).
     */
    public function issueStepUpToken(
        string $userId,
        string $sessionId,
        string $actorType,
        string $scope,
        string $ipHash,
        string $correlationId,
    ): StepUpTokenDTO;
}
