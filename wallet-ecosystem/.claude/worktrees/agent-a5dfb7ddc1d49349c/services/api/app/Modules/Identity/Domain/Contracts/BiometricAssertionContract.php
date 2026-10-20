<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\Exceptions\BiometricEnrollmentException;
use App\Modules\Identity\Domain\Exceptions\BiometricVerificationException;

/**
 * TBD-004: Biometric SDK abstraction boundary.
 * Do NOT choose a concrete SDK implementation here.
 * The concrete provider is bound in IdentityServiceProvider.
 */
interface BiometricAssertionContract
{
    /**
     * @param string $challenge         Server-issued nonce for this session
     * @param string $assertion         Base64-encoded signed assertion from the client
     * @param string $registeredPublicKey PEM or JWK public key from identity.credentials
     * @throws BiometricVerificationException on cryptographic failure
     */
    public function verify(
        string $challenge,
        string $assertion,
        string $registeredPublicKey,
    ): BiometricVerificationResult;

    /**
     * @param string $enrollmentPayload Device-specific enrollment data from the client
     * @throws BiometricEnrollmentException on invalid payload
     */
    public function validateEnrollment(string $enrollmentPayload): BiometricEnrollmentResult;
}
