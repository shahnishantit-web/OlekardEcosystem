<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Infrastructure\Models\OtpChallenge;

interface OtpChallengeRepositoryInterface
{
    public function create(array $attributes): OtpChallenge;

    public function findActive(string $identifier, string $challengeType): ?OtpChallenge;

    public function invalidateExisting(string $identifier, string $challengeType): void;

    public function verifyAndConsume(string $challengeId): OtpChallenge;

    public function incrementAttempt(string $challengeId): void;

    public function findById(string $challengeId): ?OtpChallenge;

    public function updateForResend(string $challengeId, string $otpHash, \DateTimeImmutable $expiresAt): OtpChallenge;
}
