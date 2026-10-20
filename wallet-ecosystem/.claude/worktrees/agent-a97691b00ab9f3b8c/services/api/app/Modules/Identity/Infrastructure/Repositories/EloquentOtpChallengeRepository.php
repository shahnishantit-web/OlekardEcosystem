<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Contracts\OtpChallengeRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\OtpExpiredException;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;

final class EloquentOtpChallengeRepository implements OtpChallengeRepositoryInterface
{
    public function create(array $attributes): OtpChallenge
    {
        return OtpChallenge::create($attributes);
    }

    public function findActive(string $identifier, string $challengeType): ?OtpChallenge
    {
        return OtpChallenge::where('identifier', $identifier)
            ->where('challenge_type', $challengeType)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function invalidateExisting(string $identifier, string $challengeType): void
    {
        OtpChallenge::where('identifier', $identifier)
            ->where('challenge_type', $challengeType)
            ->where('status', 'pending')
            ->update(['status' => 'invalidated']);
    }

    public function verifyAndConsume(string $challengeId): OtpChallenge
    {
        $challenge = OtpChallenge::where('id', $challengeId)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $challenge->update(['status' => 'verified']);

        return $challenge->fresh();
    }

    public function incrementAttempt(string $challengeId): void
    {
        OtpChallenge::where('id', $challengeId)->increment('attempt_count');
    }

    public function findById(string $challengeId): ?OtpChallenge
    {
        return OtpChallenge::find($challengeId);
    }

    public function updateForResend(string $challengeId, string $otpHash, \DateTimeImmutable $expiresAt): OtpChallenge
    {
        $challenge = OtpChallenge::findOrFail($challengeId);
        $challenge->update([
            'otp_hash'       => $otpHash,
            'expires_at'     => $expiresAt->format('Y-m-d H:i:s'),
            'last_resent_at' => now(),
            'resend_count'   => $challenge->resend_count + 1,
        ]);

        return $challenge->fresh();
    }
}
