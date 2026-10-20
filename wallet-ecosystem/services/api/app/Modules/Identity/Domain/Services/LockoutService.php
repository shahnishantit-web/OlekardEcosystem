<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Events\AccountLockedEvent;
use App\Modules\Identity\Domain\Exceptions\AccountLockedException;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Support\Facades\DB;

/**
 * Manages progressive account lockout.
 * Progressive duration = initial_duration × 2^(episode_count − 1).
 * Threshold values sourced from ConfigurationSecurityService — never hard-coded.
 */
final class LockoutService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly ConfigurationSecurityService $config,
        private readonly EventPublisherInterface $events,
        private readonly AuditServiceInterface $audit,
        private readonly IdentityMetrics $metrics,
    ) {}

    /**
     * Check if the account is currently locked.
     * Throws AccountLockedException if locked_at is set and the lockout duration has not elapsed.
     */
    public function assertNotLocked(User $user, string $correlationId, string $ipHash): void
    {
        if ($user->locked_at === null) {
            return;
        }

        $duration  = $this->computeLockoutDuration($user->lockout_episode_count ?? 1);
        $lockedAt  = \Carbon\Carbon::parse($user->locked_at);
        $lockedUntil = $lockedAt->copy()->addSeconds($duration);

        if (now()->lessThanOrEqualTo($lockedUntil)) {
            $this->metrics->authFailure($user->actor_type, 'account_locked');
            throw new AccountLockedException(
                \DateTimeImmutable::createFromMutable($lockedUntil->toDateTime()),
            );
        }

        // Lockout period has elapsed — clear the lock (including locked_at so this branch only runs once)
        $this->users->updateStatus($user->id, 'active', ['locked_at' => null]);
        $this->users->resetFailedAttempts($user->id);
    }

    /**
     * Record a failed authentication attempt.
     * If the attempt count reaches the threshold, impose a lockout.
     */
    public function recordFailure(User $user, string $correlationId, string $ipHash, string $maskedIdentifier): void
    {
        $this->users->incrementFailedAttempts($user->id);
        $user->refresh();

        $maxAttempts = $this->config->getLockoutMaxFailedAttempts();

        if ($user->failed_attempt_count < $maxAttempts) {
            return;
        }

        // Compute episode count optimistically before the transaction so it can be
        // used in the throw after the transaction commits.
        $newEpisode = ($user->lockout_episode_count ?? 0) + 1;
        $duration   = $this->computeLockoutDuration($newEpisode);
        $lockedUntil = now()->addSeconds($duration)->toIso8601String();

        // Lockout state change + outbox event must be atomic (FR-050).
        // AccountLockedException is thrown AFTER the transaction so the commit is not rolled back.
        DB::connection('platform')->transaction(function () use ($user, $newEpisode, $correlationId, $ipHash, $maskedIdentifier, $lockedUntil): void {
            $this->users->incrementLockoutEpisode($user->id);
            $this->users->updateLockedAt($user->id, now());

            $this->events->publish(new AccountLockedEvent(
                userId:              $user->id,
                actorId:             null,
                correlationId:       $correlationId,
                maskedIdentifier:    $maskedIdentifier,
                lockoutEpisodeCount: $newEpisode,
                lockedUntil:         $lockedUntil,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.account.locked',
                actorType:     $user->actor_type,
                resourceType:  'identity.user',
                correlationId: $correlationId,
                outcome:       'success',
                resourceId:    $user->id,
                afterState:    [
                    'lockout_episode_count' => $newEpisode,
                    'masked_identifier'     => $maskedIdentifier,
                    'locked_until'          => $lockedUntil,
                ],
                ipAddress:     $ipHash,
            ));
        });

        $this->metrics->accountLocked($user->actor_type);

        throw new AccountLockedException(
            new \DateTimeImmutable('+' . $duration . ' seconds'),
        );
    }

    /**
     * Reset failed attempt count after a successful authentication.
     */
    public function recordSuccess(User $user): void
    {
        $this->users->resetFailedAttempts($user->id);
    }

    /**
     * Progressive lockout duration: initial_duration × 2^(episode - 1).
     * e.g. episode 1 = 900s, episode 2 = 1800s, episode 3 = 3600s.
     */
    private function computeLockoutDuration(int $episode): int
    {
        $initial = $this->config->getLockoutInitialDuration();

        if (!$this->config->isLockoutProgressiveEnabled() || $episode <= 1) {
            return $initial;
        }

        return $initial * (2 ** ($episode - 1));
    }
}
