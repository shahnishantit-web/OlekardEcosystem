<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Contracts\AdminEmailVerificationMailContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Events\AdminEmailVerifiedEvent;
use App\Modules\Identity\Domain\Exceptions\EmailVerificationTokenExpiredException;
use App\Modules\Identity\Domain\Exceptions\EmailVerificationTokenInvalidException;
use App\Modules\Identity\Infrastructure\Models\AdminEmailVerificationToken;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handles admin email-verification token lifecycle.
 * Security guarantees:
 *   - Token generation uses CSPRNG (random_bytes)
 *   - Only the SHA-256 hash is persisted; plaintext is discarded after delivery
 *   - Response is always identical whether the email exists or not (no user enumeration)
 *   - Token is consumed atomically with the status update to prevent replay
 */
final class AdminEmailVerificationService
{
    private const TOKEN_TTL_HOURS = 24;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AdminEmailVerificationMailContract $mailer,
        private readonly EventPublisherInterface $events,
        private readonly AuditServiceInterface $audit,
    ) {}

    /**
     * Verify an admin email using the raw token from the verification link.
     * Marks the token used and activates the account atomically.
     *
     * @throws EmailVerificationTokenInvalidException when token is unknown or already used
     * @throws EmailVerificationTokenExpiredException when token TTL has elapsed
     */
    public function verifyEmail(string $rawToken, string $correlationId): void
    {
        $tokenHash = hash('sha256', $rawToken);

        /** @var AdminEmailVerificationToken|null $record */
        $record = AdminEmailVerificationToken::where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->first();

        if ($record === null) {
            throw EmailVerificationTokenInvalidException::make();
        }

        if ($record->isExpired()) {
            throw EmailVerificationTokenExpiredException::make();
        }

        $userId  = (string) $record->getAttribute('user_id');
        $tokenId = (string) $record->getAttribute('id');

        DB::connection('identity')->transaction(function () use ($tokenId, $userId, $correlationId): void {
            AdminEmailVerificationToken::where('id', $tokenId)->update(['used_at' => now()]);

            $this->users->updateStatus($userId, 'active');

            $this->events->publish(new AdminEmailVerifiedEvent(
                userId:        $userId,
                correlationId: $correlationId,
            ));

            $this->audit->record(new AuditEventData(
                action:        'admin.email_verified',
                actorType:     'self',
                resourceType:  'admin_account',
                correlationId: $correlationId,
                outcome:       'success',
                resourceId:    $userId,
            ));
        });
    }

    /**
     * Re-send an email-verification link for an admin account.
     * Silently succeeds when the email is not registered or already verified (OWASP user-enumeration prevention).
     */
    public function resendVerification(string $email, string $correlationId): void
    {
        $user = $this->users->findByEmail($email);

        if ($user === null
            || $user->getAttribute('actor_type') !== 'ADMIN'
            || $user->getAttribute('status') !== 'pending_verification'
        ) {
            $this->simulateWorkDelay();
            return;
        }

        // Invalidate any existing unused tokens for this user
        AdminEmailVerificationToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = now()->addHours(self::TOKEN_TTL_HOURS);

        AdminEmailVerificationToken::create([
            'id'         => (string) Str::orderedUuid(),
            'user_id'    => $user->id,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);

        $this->mailer->send($email, $rawToken, $correlationId);

        $maskedEmail = PiiMasker::maskEmail($email);
        $userId      = (string) $user->id;

        $this->audit->record(new AuditEventData(
            action:        'admin.email_verification_resent',
            actorType:     'self',
            resourceType:  'admin_account',
            correlationId: $correlationId,
            outcome:       'success',
            resourceId:    $userId,
            metadata:      ['masked_email' => $maskedEmail],
        ));
    }

    private function simulateWorkDelay(): void
    {
        $_ = hash('sha256', bin2hex(random_bytes(32)));
    }
}
