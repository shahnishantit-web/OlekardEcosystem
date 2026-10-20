<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Contracts\OtpChallengeRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpServiceContract;
use App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException;
use App\Modules\Identity\Domain\Exceptions\OtpRateLimitException;
use App\Modules\Identity\Domain\ValueObjects\OtpChallengeType;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use Illuminate\Support\Facades\Redis;

final class OtpService implements OtpServiceContract
{
    public function __construct(
        private readonly OtpChallengeRepositoryInterface $challenges,
        private readonly OtpDeliveryContract $delivery,
        private readonly ConfigurationSecurityService $config,
        private readonly IdentityMetrics $metrics,
    ) {}

    /**
     * Generate a new OTP challenge, invalidating any existing pending challenge of the same type.
     * OTP plaintext is delivered to the destination and then discarded — never stored, logged, or returned.
     */
    public function generate(
        string $identifier,
        OtpChallengeType $challengeType,
        ?string $userId,
        string $correlationId,
    ): OtpChallenge {
        $this->enforceHourlySendLimit($identifier);

        // One active challenge per type+identifier at a time
        $this->challenges->invalidateExisting($identifier, $challengeType->value);

        $plainOtp = $this->generatePlainOtp();
        $otpHash  = hash('sha256', $plainOtp);
        $ttl      = $this->config->getOtpTtl();
        $expiresAt = now()->addSeconds($ttl);

        $challenge = $this->challenges->create([
            'user_id'        => $userId,
            'challenge_type' => $challengeType->value,
            'identifier'     => $identifier,
            'otp_hash'       => $otpHash,
            'status'         => 'pending',
            'attempt_count'  => 0,
            'resend_count'   => 0,
            'expires_at'     => $expiresAt,
        ]);

        $channelType = $this->resolveChannelType($identifier);
        $this->delivery->deliver($identifier, $plainOtp, $channelType, $correlationId);
        $this->metrics->otpSent($channelType, $challengeType->value);

        // Plaintext OTP is no longer referenced after delivery
        $this->incrementHourlySendCounter($identifier);

        return $challenge;
    }

    /**
     * Verify a submitted OTP code against the stored hash.
     * T056: increments attempt count, enforces max_attempts, marks challenge consumed.
     */
    public function verify(string $challengeId, string $otpInput): OtpChallenge
    {
        $challenge = $this->challenges->findById($challengeId);

        if ($challenge === null || $challenge->status !== 'pending') {
            throw OtpInvalidOrExpiredException::challengeNotFound();
        }

        if ($challenge->expires_at < now()) {
            $challenge->update(['status' => 'expired']);
            throw OtpInvalidOrExpiredException::expired();
        }

        $this->challenges->incrementAttempt($challengeId);
        $challenge->refresh();

        $maxAttempts = $this->config->getOtpMaxAttempts();
        if ($challenge->attempt_count >= $maxAttempts) {
            $challenge->update(['status' => 'expired']);
            throw OtpInvalidOrExpiredException::maxAttemptsReached();
        }

        $inputHash = hash('sha256', $otpInput);
        if (!hash_equals($challenge->otp_hash, $inputHash)) {
            throw OtpInvalidOrExpiredException::invalidCode();
        }

        return $this->challenges->verifyAndConsume($challengeId);
    }

    /**
     * Resend OTP for an existing pending challenge, subject to cooldown and hourly limits.
     */
    public function resend(string $challengeId, string $correlationId): OtpChallenge
    {
        $challenge = $this->challenges->findById($challengeId);

        if ($challenge === null || $challenge->status !== 'pending') {
            throw OtpInvalidOrExpiredException::challengeNotFound();
        }

        if ($challenge->expires_at < now()) {
            throw OtpInvalidOrExpiredException::expired();
        }

        $this->enforceResendCooldown($challenge);
        $this->enforceHourlySendLimit($challenge->identifier);

        $plainOtp = $this->generatePlainOtp();
        $otpHash  = hash('sha256', $plainOtp);
        $ttl      = $this->config->getOtpTtl();
        $newExpiry = new \DateTimeImmutable('+'.$ttl.' seconds');

        $updated = $this->challenges->updateForResend($challengeId, $otpHash, $newExpiry);

        $channelType = $this->resolveChannelType($challenge->identifier);
        $this->delivery->deliver($challenge->identifier, $plainOtp, $channelType, $correlationId);
        $this->metrics->otpSent($channelType, $challenge->challenge_type);

        $this->incrementHourlySendCounter($challenge->identifier);

        return $updated;
    }

    public function isExpired(string $challengeId): bool
    {
        $challenge = $this->challenges->findById($challengeId);
        return $challenge === null || $challenge->expires_at?->isPast() === true;
    }

    private function generatePlainOtp(): string
    {
        $length = $this->config->getPinLength();
        $max    = (int) (10 ** $length) - 1;
        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function resolveChannelType(string $identifier): string
    {
        return str_contains($identifier, '@') ? 'email' : 'sms';
    }

    private function enforceHourlySendLimit(string $identifier): void
    {
        $key   = IdentityRedisKeys::otpRate($identifier);
        $count = (int) Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)->get($key);

        if ($count >= $this->config->getOtpMaxSendsPerHour()) {
            throw OtpRateLimitException::hourlySendLimitReached();
        }
    }

    private function incrementHourlySendCounter(string $identifier): void
    {
        $key   = IdentityRedisKeys::otpRate($identifier);
        $redis = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
        $redis->incr($key);
        $redis->expireat($key, now()->addHour()->timestamp);
    }

    private function enforceResendCooldown(OtpChallenge $challenge): void
    {
        if ($challenge->last_resent_at === null) {
            return;
        }

        $cooldown  = $this->config->getOtpResendCooldown();
        $nextAllowed = \Carbon\Carbon::parse($challenge->last_resent_at)->addSeconds($cooldown);

        if (now()->lessThan($nextAllowed)) {
            $remaining = (int) now()->diffInSeconds($nextAllowed);
            throw OtpRateLimitException::resendCooldown($remaining);
        }
    }
}
