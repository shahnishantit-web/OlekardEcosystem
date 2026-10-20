<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Stubs;

use App\Modules\Identity\Domain\Contracts\BiometricAssertionContract;
use App\Modules\Identity\Domain\Contracts\BiometricEnrollmentResult;
use App\Modules\Identity\Domain\Contracts\BiometricVerificationResult;

/**
 * TBD-004 stub: accepts any well-formed assertion/enrollment payload in tests.
 * Replace with the platform-selected biometric SDK once TBD-004 is resolved.
 * The abstraction boundary (BiometricAssertionContract) MUST be preserved.
 */
final class StubBiometricAssertionProvider implements BiometricAssertionContract
{
    public function verify(
        string $challenge,
        string $assertion,
        string $registeredPublicKey,
    ): BiometricVerificationResult {
        return new BiometricVerificationResult(verified: true, failureReason: null);
    }

    public function validateEnrollment(string $enrollmentPayload): BiometricEnrollmentResult
    {
        return new BiometricEnrollmentResult(
            devicePublicKey:  'stub-public-key',
            deviceIdentifier: 'stub-device-identifier',
        );
    }
}
