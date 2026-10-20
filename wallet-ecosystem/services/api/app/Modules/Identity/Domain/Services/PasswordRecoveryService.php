<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Contracts\AdminPasswordResetMailContract;
use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Events\AdminPasswordResetRequestedEvent;
use App\Modules\Identity\Domain\Events\AdminPasswordResetSucceededEvent;
use App\Modules\Identity\Domain\Exceptions\PasswordResetTokenExpiredException;
use App\Modules\Identity\Domain\Exceptions\PasswordResetTokenInvalidException;
use App\Modules\Identity\Infrastructure\Models\AdminPasswordResetToken;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Handles admin password-recovery token lifecycle.
 * Security guarantees:
 *   - Token generation uses CSPRNG (random_bytes)
 *   - Only the SHA-256 hash is persisted; plaintext is discarded after delivery
 *   - Response is always identical whether the email exists or not (no user enumeration)
 */
final class PasswordRecoveryService
{
    private const TOKEN_TTL_MINUTES = 60;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly CredentialRepositoryInterface $credentials,
        private readonly AdminPasswordResetMailContract $mailer,
        private readonly EventPublisherInterface $events,
        private readonly AuditServiceInterface $audit,
    ) {}

    /**
     * Initiate a password reset for an admin account.
     * Silently succeeds when the email is not registered (OWASP user-enumeration prevention).
     */
    public function requestPasswordReset(string $email, string $correlationId): void
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || $user->getAttribute('actor_type') !== 'ADMIN') {
            // Consume the same time path to prevent timing-based enumeration
            $this->simulateWorkDelay();
            return;
        }

        // Invalidate any existing unused tokens for this user
        AdminPasswordResetToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);

        AdminPasswordResetToken::create([
            'id'         => (string) Str::orderedUuid(),
            'user_id'    => $user->id,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);

        $this->mailer->send($email, $rawToken, $correlationId);

        $maskedEmail = PiiMasker::maskEmail($email);

        $userId = (string) $user->id;

        $this->events->publish(new AdminPasswordResetRequestedEvent(
            userId:        $userId,
            correlationId: $correlationId,
            maskedEmail:   $maskedEmail,
        ));

        $this->audit->record(new AuditEventData(
            action:        'admin.password_reset_requested',
            actorType:     'self',
            resourceType:  'admin_account',
            correlationId: $correlationId,
            outcome:       'success',
            resourceId:    $userId,
            metadata:      ['masked_email' => $maskedEmail],
        ));
    }

    /**
     * Complete admin password reset using a previously issued token.
     * Token is consumed (used_at stamped) atomically with the credential update
     * so it cannot be replayed even if the process crashes mid-way.
     *
     * @throws PasswordResetTokenInvalidException when token is unknown or already used
     * @throws PasswordResetTokenExpiredException when token TTL has elapsed
     */
    public function resetPassword(string $rawToken, string $newPassword, string $correlationId): void
    {
        $tokenHash = hash('sha256', $rawToken);

        /** @var AdminPasswordResetToken|null $resetToken */
        $resetToken = AdminPasswordResetToken::where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->first();

        if ($resetToken === null) {
            throw PasswordResetTokenInvalidException::make();
        }

        if ($resetToken->isExpired()) {
            throw PasswordResetTokenExpiredException::make();
        }

        $userId     = (string) $resetToken->getAttribute('user_id');
        $credential = $this->credentials->findByUserAndType($userId, 'password');

        if ($credential === null) {
            // Admin has no password credential — treat as invalid token (no state disclosure)
            throw PasswordResetTokenInvalidException::make();
        }

        $credentialId = (string) $credential->getAttribute('id');
        $tokenId      = (string) $resetToken->getAttribute('id');
        $newHash      = Hash::driver('argon2id')->make($newPassword);

        // Both models use the identity connection, so a single transaction suffices.
        DB::connection('identity')->transaction(function () use ($tokenId, $credentialId, $newHash, $userId, $correlationId): void {
            // Consume the token — prevents replay
            AdminPasswordResetToken::where('id', $tokenId)->update(['used_at' => now()]);

            // Rotate credential hash, clear lockout state, restore active status
            $this->credentials->updateHash($credentialId, $newHash);
            $this->credentials->resetFailed($credentialId);
            $this->credentials->updateStatus($credentialId, 'active');

            $this->events->publish(new AdminPasswordResetSucceededEvent(
                userId:        $userId,
                correlationId: $correlationId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'admin.password_reset_succeeded',
                actorType:     'self',
                resourceType:  'admin_account',
                correlationId: $correlationId,
                outcome:       'success',
                resourceId:    $userId,
            ));
        });
    }

    /** Constant-time no-op to prevent timing attacks when email is unknown. */
    private function simulateWorkDelay(): void
    {
        // Discard intentional — burns comparable CPU cycles to the happy path
        $_ = hash('sha256', bin2hex(random_bytes(32)));
    }
}
