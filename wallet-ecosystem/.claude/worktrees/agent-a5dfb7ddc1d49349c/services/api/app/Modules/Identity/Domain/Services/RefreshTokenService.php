<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Application\DTOs\AuthenticatedSessionDTO;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Events\SessionAllTerminatedEvent;
use App\Modules\Identity\Domain\Events\SessionTerminatedEvent;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Exceptions\RefreshTokenReuseException;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use App\Modules\Identity\Infrastructure\Models\Session;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Manages refresh token generation, rotation, and reuse detection.
 * Rotation: each refresh produces a new access token and a new refresh token; the old token hash is invalidated.
 * Reuse detection (BR-008): presenting an already-rotated token revokes the entire session family.
 */
final class RefreshTokenService
{
    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly UserRepositoryInterface $users,
        private readonly TokenService $tokenService,
        private readonly ConfigurationSecurityService $config,
        private readonly EventPublisherInterface $events,
        private readonly AuditServiceInterface $audit,
        private readonly IdentityMetrics $metrics,
    ) {}

    /**
     * Generate a new 256-bit opaque refresh token (base64url, no padding).
     */
    public function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Rotate an existing refresh token.
     * - Finds the session by SHA-256 hash of the raw token.
     * - Detects reuse: if session is not active, revokes the whole family.
     * - If active, issues new access+refresh tokens and invalidates the old token hash.
     */
    public function rotate(
        string $rawRefreshToken,
        string $correlationId,
        string $ipHash,
    ): AuthenticatedSessionDTO {
        $hash    = hash('sha256', $rawRefreshToken);
        $session = $this->sessions->findByRefreshHash($hash);

        if ($session === null) {
            $this->metrics->tokenRefresh('failure');
            throw InvalidCredentialsException::make();
        }

        if ($session->status !== 'active') {
            // Reuse detected — revoke entire family
            if ($this->config->isRefreshTokenReuseDetectionEnabled()) {
                $this->revokeFamily($session, $correlationId, $ipHash);
            }
            $this->metrics->tokenRefresh('failure');
            throw RefreshTokenReuseException::make();
        }

        $user = $this->users->findById($session->user_id);

        if ($user === null) {
            $this->metrics->tokenRefresh('failure');
            throw InvalidCredentialsException::make();
        }

        $ttl          = $this->config->getRefreshTokenTtl();
        $newRawToken  = $this->generateToken();
        $newHash      = hash('sha256', $newRawToken);
        $newSessionId = (string) Str::uuid();

        $ttlAccess = match (strtoupper($session->actor_type)) {
            'ADMIN'  => $this->config->getSessionInactivityTtlAdmin(),
            'AGENT'  => $this->config->getSessionInactivityTtlAgent(),
            default  => $this->config->getSessionInactivityTtlCustomer(),
        };

        // Revoke old session, create new one, and write audit — all atomic (FR-050).
        DB::connection('platform')->transaction(function () use ($session, $newSessionId, $newHash, $ttl, $ttlAccess, $correlationId, $ipHash): void {
            $this->sessions->updateStatus($session->id, 'revoked');

            $this->sessions->create([
                'id'                      => $newSessionId,
                'user_id'                 => $session->user_id,
                'device_id'               => $session->device_id,
                'refresh_token_hash'      => $newHash,
                'refresh_token_family_id' => $session->refresh_token_family_id,
                'status'                  => 'active',
                'actor_type'              => $session->actor_type,
                'last_activity_at'        => now(),
                'expires_at'              => now()->addSeconds($ttl),
                'ip_hash'                 => $ipHash,
            ]);

            // identity.session.token-rotated: justified extension (FR-E01-020) — tracks rotation for security monitoring; not in Section 16 18-action table.
            $this->audit->record(new AuditEventData(
                action:        'identity.session.token-rotated',
                actorType:     $session->actor_type,
                resourceType:  'identity.session',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $session->user_id,
                resourceId:    $newSessionId,
                afterState:    ['old_session_id' => $session->id, 'new_session_id' => $newSessionId],
                ipAddress:     $ipHash,
            ));
        });

        // Cache old hash as revoked after DB commits (Redis is not transactional).
        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)
            ->setex(IdentityRedisKeys::revokedRefreshToken($hash), $ttl, '1');

        $newAccessToken = $this->tokenService->issueAccessToken(
            userId:        $session->user_id,
            sessionId:     $newSessionId,
            deviceId:      (string) $session->device_id,
            actorType:     $session->actor_type,
            ttl:           $ttlAccess,
            ipHash:        $ipHash,
            correlationId: $correlationId,
        );

        $this->metrics->tokenRefresh('success');

        return new AuthenticatedSessionDTO(
            userId:       $session->user_id,
            sessionId:    $newSessionId,
            accessToken:  $newAccessToken,
            refreshToken: $newRawToken,
            tokenType:    'Bearer',
            expiresIn:    $ttlAccess,
        );
    }

    private function revokeFamily(Session $session, string $correlationId, string $ipHash): void
    {
        $ttl      = $this->config->getRefreshTokenTtl();
        $familySessions = $this->sessions->findByFamilyId($session->refresh_token_family_id);

        DB::connection('platform')->transaction(function () use ($session, $correlationId, $ipHash): void {
            $this->sessions->revokeFamily($session->refresh_token_family_id);

            $this->events->publish(new SessionAllTerminatedEvent(
                userId:        $session->user_id,
                actorId:       $session->user_id,
                correlationId: $correlationId,
                reason:        'refresh_token_reuse',
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.session.all-terminated',
                actorType:     $session->actor_type,
                resourceType:  'identity.session',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $session->user_id,
                resourceId:    $session->user_id,
                afterState:    ['reason' => 'refresh_token_reuse', 'family_id' => $session->refresh_token_family_id],
                ipAddress:     $ipHash,
            ));
        });

        $redis = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
        foreach ($familySessions as $s) {
            $redis->setex(IdentityRedisKeys::revokedSession((string) $s->id), $ttl, '1');
        }
    }
}
