<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Stubs;

use App\Modules\Identity\Application\DTOs\AuthenticatedSessionDTO;
use App\Modules\Identity\Application\DTOs\ServiceTokenDTO;
use App\Modules\Identity\Application\DTOs\StepUpTokenDTO;
use App\Modules\Identity\Domain\Contracts\TokenServiceContract;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Str;

/**
 * Placeholder token service used until Phase 7 (T074) wires lcobucci/jwt.
 * Returns opaque stub tokens so Phase 6 tests can verify the authentication flow
 * without depending on real JWT infrastructure.
 */
final class StubTokenService implements TokenServiceContract
{
    public function issueServiceToken(
        string  $serviceId,
        array   $scopes,
        string  $audience,
        ?string $onBehalfOf,
        string  $correlationId,
    ): ServiceTokenDTO {
        return new ServiceTokenDTO(
            accessToken: 'stub-service-' . bin2hex(random_bytes(16)),
            tokenType:   'Bearer',
            expiresIn:   900,
            scopes:      $scopes,
        );
    }

    public function issueStepUpToken(
        string $userId,
        string $sessionId,
        string $actorType,
        string $scope,
        string $ipHash,
        string $correlationId,
    ): StepUpTokenDTO {
        return new StepUpTokenDTO(
            stepUpAccessToken: 'stub-step-up-' . bin2hex(random_bytes(16)),
            scope:             $scope,
            expiresIn:         300,
        );
    }

    public function issueTokenPair(
        User   $user,
        string $deviceId,
        string $actorType,
        string $ipHash,
        string $correlationId,
    ): AuthenticatedSessionDTO {
        return new AuthenticatedSessionDTO(
            userId:       $user->id,
            sessionId:    (string) Str::uuid(),
            accessToken:  'stub-access-' . bin2hex(random_bytes(16)),
            refreshToken: 'stub-refresh-' . bin2hex(random_bytes(32)),
            tokenType:    'Bearer',
            expiresIn:    900,
        );
    }
}
