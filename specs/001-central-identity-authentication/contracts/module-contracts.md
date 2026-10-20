# Module Contracts — E01 Central Identity & Authentication

**Feature**: `001-central-identity-authentication`

PHP interface contracts that define how other modules interact with E01 (via `IdentityModuleContract`) and how E01 interacts with injectable providers (via `OtpDeliveryContract`, `BiometricAssertionContract`, `JwksProviderContract`).

All interfaces belong to the `App\Modules\Identity\Domain\Contracts\` namespace.

---

## IdentityModuleContract

The public surface through which other modules (E04, E09, etc.) consume E01 functionality. All cross-module calls go through this interface; direct queries to `identity.*` tables from other modules are prohibited.

```php
namespace App\Modules\Identity\Domain\Contracts;

interface IdentityModuleContract
{
    /**
     * Resolve the authenticated actor from a Bearer JWT.
     * Returns null if the token is absent, expired, or invalid.
     * Does NOT perform RBAC; returns identity claims only.
     */
    public function resolveActorFromToken(string $bearerToken): ?AuthenticatedActor;

    /**
     * Resolve actor from a service-identity JWT.
     * Returns null for invalid/expired service tokens.
     */
    public function resolveServiceActor(string $bearerToken): ?ServiceActor;

    /**
     * Check whether a step-up token grants the requested scope.
     * Does NOT issue step-up; scope check only.
     */
    public function verifyStepUpScope(string $stepUpToken, string $requiredScope): bool;

    /**
     * Revoke a specific session by ID.
     * Used by admin flows in other modules (e.g., E04 admin tooling).
     */
    public function revokeSession(string $sessionId, string $revokedByActorId): void;
}
```

### AuthenticatedActor DTO

```php
namespace App\Modules\Identity\Domain\Contracts;

readonly class AuthenticatedActor
{
    public function __construct(
        public string $userId,
        public string $sessionId,
        public string $actorType,    // USER | SERVICE | SYSTEM
        public array  $roles,
        public ?string $deviceId,
    ) {}
}
```

### ServiceActor DTO

```php
namespace App\Modules\Identity\Domain\Contracts;

readonly class ServiceActor
{
    public function __construct(
        public string  $serviceId,
        public string  $actorType,     // SERVICE
        public array   $scopes,
        public ?string $onBehalfOf,    // user UUID or null
    ) {}
}
```

---

## OtpDeliveryContract

Abstracts OTP delivery so the Identity domain is not coupled to any specific SMS/email provider. The provider is resolved via the service container based on the `auth.otp.provider` configuration value.

```php
namespace App\Modules\Identity\Domain\Contracts;

interface OtpDeliveryContract
{
    /**
     * Deliver an OTP to the specified destination.
     *
     * @param string $destination  E.164 mobile or email address
     * @param string $otp          Plaintext OTP (provider hashes/logs as needed)
     * @param string $channelType  'sms' | 'email'
     * @param string $correlationId  For tracing delivery failures to the originating request
     * @throws OtpDeliveryException on provider failure
     */
    public function deliver(
        string $destination,
        string $otp,
        string $channelType,
        string $correlationId,
    ): OtpDeliveryResult;
}
```

### OtpDeliveryResult DTO

```php
namespace App\Modules\Identity\Domain\Contracts;

readonly class OtpDeliveryResult
{
    public function __construct(
        public bool   $delivered,
        public string $providerReference,  // Provider's message ID for tracing
        public ?string $failureReason,
    ) {}
}
```

**OTP value must not appear in**:
- Application logs at any level
- Audit records
- Error messages returned to clients
- `OtpDeliveryResult` fields other than what the provider requires internally

---

## BiometricAssertionContract

Abstracts biometric assertion verification so TBD-004 (biometric SDK choice) does not leak into the Identity domain. The concrete implementation is bound in `IdentityServiceProvider` and swapped when TBD-004 is resolved.

```php
namespace App\Modules\Identity\Domain\Contracts;

interface BiometricAssertionContract
{
    /**
     * Verify a biometric assertion from the client against the registered public key.
     *
     * @param string $challenge        The server-issued challenge (nonce) for this session
     * @param string $assertion        Base64-encoded signed assertion from the client
     * @param string $registeredPublicKey  PEM or JWK public key from identity.credentials
     * @throws BiometricVerificationException on cryptographic failure
     */
    public function verify(
        string $challenge,
        string $assertion,
        string $registeredPublicKey,
    ): BiometricVerificationResult;

    /**
     * Validate a biometric enrollment payload and extract the device public key.
     *
     * @param string $enrollmentPayload  Device-specific enrollment data from the client
     * @throws BiometricEnrollmentException on invalid payload
     */
    public function validateEnrollment(string $enrollmentPayload): BiometricEnrollmentResult;
}
```

### BiometricVerificationResult DTO

```php
namespace App\Modules\Identity\Domain\Contracts;

readonly class BiometricVerificationResult
{
    public function __construct(
        public bool   $verified,
        public ?string $failureReason,
    ) {}
}
```

### BiometricEnrollmentResult DTO

```php
namespace App\Modules\Identity\Domain\Contracts;

readonly class BiometricEnrollmentResult
{
    public function __construct(
        public string $devicePublicKey,    // PEM or JWK format; stored in identity.credentials
        public string $deviceIdentifier,   // Device-stable identifier extracted from enrollment
    ) {}
}
```

**Biometric data constraints**:
- Raw biometric templates MUST NOT be passed to this interface.
- Only cryptographic assertions/signatures are passed.
- Biometric data never transits the network; the device handles it internally.
- The `registeredPublicKey` stored in `identity.credentials.biometric_public_key` is the device public key only.

---

## JwksProviderContract

Abstracts JWKS key material so the signing implementation is swappable (e.g., local environment vs. KMS-backed in production).

```php
namespace App\Modules\Identity\Domain\Contracts;

interface JwksProviderContract
{
    /**
     * Return the current signing private key for JWT issuance.
     * Implementation sources the key from the secret manager.
     */
    public function getSigningKey(): JwkSigningKey;

    /**
     * Return all current verification keys (for JWKS endpoint publication).
     * Includes retired keys during rotation window.
     */
    public function getVerificationKeys(): array; // JwkPublicKey[]

    /**
     * Return the current key ID (kid) used for new token issuance.
     */
    public function getCurrentKid(): string;
}
```

### JwkSigningKey DTO

```php
namespace App\Modules\Identity\Domain\Contracts;

readonly class JwkSigningKey
{
    public function __construct(
        public string $kid,
        public string $privateKeyPem,   // Never logged, never serialized to any response
        public string $algorithm,       // 'ES256'
    ) {}
}
```

### JwkPublicKey DTO

```php
namespace App\Modules\Identity\Domain\Contracts;

readonly class JwkPublicKey
{
    public function __construct(
        public string $kid,
        public string $kty,    // 'EC'
        public string $crv,    // 'P-256'
        public string $use,    // 'sig'
        public string $alg,    // 'ES256'
        public string $x,      // base64url x-coordinate
        public string $y,      // base64url y-coordinate
    ) {}
}
```

**JwksProviderContract implementations**:
- `LocalJwksProvider`: Reads `IDENTITY_JWT_PRIVATE_KEY` and optionally `IDENTITY_JWT_OLD_PUBLIC_KEY` from environment.
- Future: `KmsJwksProvider` — when production secret management provider is selected (TBD).

---

## Exception Types

```php
namespace App\Modules\Identity\Domain\Exceptions;

// Thrown by OtpDeliveryContract on provider failure
class OtpDeliveryException extends \RuntimeException {}

// Thrown by BiometricAssertionContract on cryptographic failure
class BiometricVerificationException extends \RuntimeException {}

// Thrown by BiometricAssertionContract on invalid enrollment payload
class BiometricEnrollmentException extends \RuntimeException {}
```

All exceptions must NOT include the OTP value, PIN, raw credential, or biometric data in their message or context.
