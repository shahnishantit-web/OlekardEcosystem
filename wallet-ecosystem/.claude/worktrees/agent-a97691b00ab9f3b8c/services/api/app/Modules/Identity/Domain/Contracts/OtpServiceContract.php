<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\ValueObjects\OtpChallengeType;
use App\Modules\Identity\Infrastructure\Models\OtpChallenge;

/**
 * Contract for OTP generation, verification, and resend operations.
 * Cross-module consumers (e.g. E02 ContactChangeService) depend on this
 * interface rather than the concrete OtpService so tests can mock it.
 */
interface OtpServiceContract
{
    public function generate(
        string $identifier,
        OtpChallengeType $challengeType,
        ?string $userId,
        string $correlationId,
    ): OtpChallenge;

    public function verify(string $challengeId, string $otpInput): OtpChallenge;

    public function resend(string $challengeId, string $correlationId): OtpChallenge;

    /**
     * Returns true when the challenge does not exist or its expires_at is in the past.
     * Used by cross-module consumers (e.g. E02 cleanup job) that must not read E01 models directly.
     */
    public function isExpired(string $challengeId): bool;
}
