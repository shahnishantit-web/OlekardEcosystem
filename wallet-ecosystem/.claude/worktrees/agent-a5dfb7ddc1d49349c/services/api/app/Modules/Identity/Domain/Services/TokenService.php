<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Application\DTOs\AuthenticatedSessionDTO;
use App\Modules\Identity\Application\DTOs\ServiceTokenDTO;
use App\Modules\Identity\Application\DTOs\StepUpTokenDTO;
use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\TokenServiceContract;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

/**
 * Issues ES256 JWT access tokens and opaque refresh tokens.
 * kid header is derived from the public key fingerprint (first 12 hex chars of SHA-256(DER)).
 * Private key MUST NOT appear in any log, response, or serialised form.
 */
final class TokenService implements TokenServiceContract
{
    public function __construct(
        private readonly JwksProviderContract $jwksProvider,
        private readonly SessionRepositoryInterface $sessions,
        private readonly ConfigurationSecurityService $config,
    ) {}

    public function issueTokenPair(
        User   $user,
        string $deviceId,
        string $actorType,
        string $ipHash,
        string $correlationId,
    ): AuthenticatedSessionDTO {
        $signingKey = $this->jwksProvider->getSigningKey();
        $ttl        = $this->resolveAccessTokenTtl($actorType);
        $sessionId  = (string) Str::uuid();
        $jti        = (string) Str::uuid();
        $now        = new \DateTimeImmutable('@' . time()); // integer-second precision for clean iat/exp arithmetic
        $expiresAt  = $now->modify('+' . $ttl . ' seconds');

        $jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($signingKey->privateKeyPem),
            InMemory::plainText($signingKey->privateKeyPem),
        );

        $token = $jwtConfig->builder()
            ->issuedBy(config('app.url', 'identity-service'))
            ->permittedFor(config('app.url', 'identity-service'))
            ->identifiedBy($jti)
            ->relatedTo($user->id)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expiresAt)
            ->withClaim('session_id', $sessionId)
            ->withClaim('actor_type', $actorType)
            ->withClaim('roles', [])
            ->withClaim('device_id', $deviceId)
            ->withClaim('correlation_id', $correlationId)
            ->withHeader('kid', $signingKey->kid)
            ->getToken($jwtConfig->signer(), $jwtConfig->signingKey());

        $accessToken = $token->toString();

        // 256-bit opaque refresh token; only the SHA-256 hash is stored
        $rawRefreshToken  = base64_encode(random_bytes(32));
        $refreshTokenHash = hash('sha256', $rawRefreshToken);
        $familyId         = (string) Str::uuid();

        $this->sessions->create([
            'id'                      => $sessionId,
            'user_id'                 => $user->id,
            'device_id'               => $deviceId,
            'refresh_token_hash'      => $refreshTokenHash,
            'refresh_token_family_id' => $familyId,
            'status'                  => 'active',
            'actor_type'              => $actorType,
            'last_activity_at'        => now(),
            'expires_at'              => now()->addSeconds($this->config->getRefreshTokenTtl()),
            'ip_hash'                 => $ipHash,
        ]);

        return new AuthenticatedSessionDTO(
            userId:       $user->id,
            sessionId:    $sessionId,
            accessToken:  $accessToken,
            refreshToken: $rawRefreshToken,
            tokenType:    'Bearer',
            expiresIn:    $ttl,
        );
    }

    /**
     * Issue a standalone access token (JWT only, no session created).
     * Used by RefreshTokenService during token rotation.
     */
    public function issueAccessToken(
        string $userId,
        string $sessionId,
        string $deviceId,
        string $actorType,
        int    $ttl,
        string $ipHash,
        string $correlationId,
    ): string {
        $signingKey = $this->jwksProvider->getSigningKey();
        $jti        = (string) Str::uuid();
        $now        = new \DateTimeImmutable('@' . time());
        $expiresAt  = $now->modify('+' . $ttl . ' seconds');

        $jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($signingKey->privateKeyPem),
            InMemory::plainText($signingKey->privateKeyPem),
        );

        return $jwtConfig->builder()
            ->issuedBy(config('app.url', 'identity-service'))
            ->permittedFor(config('app.url', 'identity-service'))
            ->identifiedBy($jti)
            ->relatedTo($userId)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expiresAt)
            ->withClaim('session_id', $sessionId)
            ->withClaim('actor_type', $actorType)
            ->withClaim('roles', [])
            ->withClaim('device_id', $deviceId)
            ->withClaim('correlation_id', $correlationId)
            ->withHeader('kid', $signingKey->kid)
            ->getToken($jwtConfig->signer(), $jwtConfig->signingKey())
            ->toString();
    }

    public function issueServiceToken(
        string  $serviceId,
        array   $scopes,
        string  $audience,
        ?string $onBehalfOf,
        string  $correlationId,
    ): ServiceTokenDTO {
        $signingKey = $this->jwksProvider->getSigningKey();
        $ttl        = $this->config->getServiceTokenTtl();
        $jti        = (string) Str::uuid();
        $now        = new \DateTimeImmutable('@' . time());
        $expiresAt  = $now->modify('+' . $ttl . ' seconds');

        $jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($signingKey->privateKeyPem),
            InMemory::plainText($signingKey->privateKeyPem),
        );

        $token = $jwtConfig->builder()
            ->issuedBy(config('app.url', 'identity-service'))
            ->permittedFor($audience)
            ->identifiedBy($jti)
            ->relatedTo($serviceId)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expiresAt)
            ->withClaim('actor_type', 'SERVICE')
            ->withClaim('service_id', $serviceId)
            ->withClaim('scopes', $scopes)
            ->withClaim('on_behalf_of', $onBehalfOf)
            ->withClaim('correlation_id', $correlationId)
            ->withHeader('kid', $signingKey->kid)
            ->getToken($jwtConfig->signer(), $jwtConfig->signingKey());

        return new ServiceTokenDTO(
            accessToken: $token->toString(),
            tokenType:   'Bearer',
            expiresIn:   $ttl,
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
        $signingKey    = $this->jwksProvider->getSigningKey();
        $ttl           = $this->config->getStepUpTokenTtl();
        $jti           = (string) Str::uuid();
        $now           = new \DateTimeImmutable('@' . time());
        $expiresAt     = $now->modify('+' . $ttl . ' seconds');
        $stepUpAudience = 'step-up:' . $scope;

        $jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($signingKey->privateKeyPem),
            InMemory::plainText($signingKey->privateKeyPem),
        );

        $token = $jwtConfig->builder()
            ->issuedBy(config('app.url', 'identity-service'))
            ->permittedFor($stepUpAudience)          // aud MUST differ from general access tokens
            ->identifiedBy($jti)
            ->relatedTo($userId)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expiresAt)
            ->withClaim('session_id', $sessionId)
            ->withClaim('actor_type', $actorType)
            ->withClaim('step_up_scope', $scope)        // purpose-scope claim
            ->withClaim('step_up_verified_at', time())  // verification timestamp (spec §10.1)
            ->withClaim('correlation_id', $correlationId)
            ->withHeader('kid', $signingKey->kid)
            ->getToken($jwtConfig->signer(), $jwtConfig->signingKey());

        return new StepUpTokenDTO(
            stepUpAccessToken: $token->toString(),
            scope:             $scope,
            expiresIn:         $ttl,
        );
    }

    private function resolveAccessTokenTtl(string $actorType): int
    {
        return match (strtoupper($actorType)) {
            'ADMIN'    => $this->config->getSessionInactivityTtlAdmin(),
            'AGENT'    => $this->config->getSessionInactivityTtlAgent(),
            default    => $this->config->getSessionInactivityTtlCustomer(),
        };
    }
}
