<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Domain\Events\SessionAllTerminatedEvent;
use App\Modules\Identity\Domain\Events\SessionRevokedEvent;
use App\Modules\Identity\Domain\Events\SessionTerminatedEvent;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use App\Modules\Identity\Infrastructure\Models\Session;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Manages session lifecycle: validation, revocation, and listing.
 * Redis `identity:revoked:{session_id}` is checked for fast revocation on every authenticated request.
 */
final class SessionService
{
    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly ConfigurationSecurityService $config,
        private readonly EventPublisherInterface $events,
        private readonly AuditServiceInterface $audit,
    ) {}

    /**
     * Find and validate a session: must exist and have status=active.
     * Also checks the Redis revocation cache for fast denial.
     */
    public function validateSession(string $sessionId): Session
    {
        $redis = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);

        // Fast revocation cache check
        if ($redis->exists(IdentityRedisKeys::revokedSession($sessionId))) {
            throw InvalidCredentialsException::make();
        }

        $session = $this->sessions->findById($sessionId);

        if ($session === null || $session->status !== 'active') {
            throw InvalidCredentialsException::make();
        }

        return $session;
    }

    /**
     * Mark a single session as logged_out and cache its ID in Redis.
     */
    public function revokeSession(
        string $sessionId,
        string $userId,
        string $actorType,
        string $correlationId,
        string $ipHash,
        string $reason = 'logout',
    ): void {
        $ttl = $this->config->getRefreshTokenTtl();

        DB::connection('platform')->transaction(function () use ($sessionId, $userId, $actorType, $correlationId, $ipHash, $reason, $ttl): void {
            $this->sessions->updateStatus($sessionId, 'logged_out');

            $this->events->publish(new SessionTerminatedEvent(
                sessionId:     $sessionId,
                actorId:       $userId,
                correlationId: $correlationId,
                userId:        $userId,
                reason:        $reason,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.session.terminated',
                actorType:     $actorType,
                resourceType:  'identity.session',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $userId,
                resourceId:    $sessionId,
                afterState:    ['reason' => $reason],
                ipAddress:     $ipHash,
            ));
        });

        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)
            ->setex(IdentityRedisKeys::revokedSession($sessionId), $ttl, '1');
    }

    /**
     * Revoke all active sessions for a user.
     */
    public function revokeAllForUser(
        string $userId,
        string $actorType,
        string $correlationId,
        string $ipHash,
    ): void {
        $activeSessions = $this->sessions->listActiveForUser($userId);
        $ttl            = $this->config->getRefreshTokenTtl();

        DB::connection('platform')->transaction(function () use ($userId, $actorType, $correlationId, $ipHash, $activeSessions): void {
            $this->sessions->revokeAllForUser($userId);

            $this->events->publish(new SessionAllTerminatedEvent(
                userId:        $userId,
                actorId:       $userId,
                correlationId: $correlationId,
                reason:        'logout_all',
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.session.all-terminated',
                actorType:     $actorType,
                resourceType:  'identity.user',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $userId,
                resourceId:    $userId,
                afterState:    ['reason' => 'logout_all', 'session_count' => count($activeSessions)],
                ipAddress:     $ipHash,
            ));
        });

        $redis = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
        foreach ($activeSessions as $session) {
            $redis->setex(IdentityRedisKeys::revokedSession((string) $session->id), $ttl, '1');
        }
    }

    /**
     * List active sessions for a user.
     * ip_hash is excluded from responses (Session model hides it).
     */
    public function listActiveSessions(string $userId): array
    {
        return $this->sessions->listActiveForUser($userId);
    }

    /**
     * Admin-initiated forced revocation of any user's session.
     * Sets status=revoked (not logged_out), caches in Redis, and audits with admin actor.
     */
    public function adminRevokeSession(
        string $sessionId,
        string $targetUserId,
        string $targetActorType,
        string $adminActorId,
        string $adminActorType,
        string $correlationId,
        string $ipHash,
    ): void {
        $ttl = $this->config->getRefreshTokenTtl();

        DB::connection('platform')->transaction(function () use ($sessionId, $targetUserId, $targetActorType, $adminActorId, $adminActorType, $correlationId, $ipHash): void {
            $this->sessions->updateStatus($sessionId, 'revoked');

            $this->events->publish(new SessionRevokedEvent(
                sessionId:       $sessionId,
                actorId:         $adminActorId,
                correlationId:   $correlationId,
                targetUserId:    $targetUserId,
                targetActorType: $targetActorType,
                reason:          'admin_revoked',
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.session.revoked',
                actorType:     $adminActorType,
                resourceType:  'identity.session',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $adminActorId,
                resourceId:    $sessionId,
                afterState:    ['reason' => 'admin_revoked', 'target_user_id' => $targetUserId],
                ipAddress:     $ipHash,
            ));
        });

        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)
            ->setex(IdentityRedisKeys::revokedSession($sessionId), $ttl, '1');
    }

    /**
     * Touch last_activity_at for an active session.
     */
    public function touchActivity(string $sessionId): void
    {
        $this->sessions->updateLastActivity($sessionId);
    }
}
