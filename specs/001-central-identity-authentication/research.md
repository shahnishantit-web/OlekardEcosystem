# Research — E01 Central Identity & Authentication

**Feature**: `001-central-identity-authentication` | **Date**: 2026-08-21 | **Status**: Complete

All technical unknowns from the spec have been resolved here. Business TBDs (biometric SDK TBD-004, concurrent-user volume, service-token lifetime, unspecified security policy values) are preserved as TBD and are NOT resolved in this document. GAP-027 step-up threshold is RESOLVED in the spec (default `10000 BWP`, runtime-configurable via `auth.step_up_threshold`/`auth.step_up_threshold_currency`); this document does not re-resolve it.

---

## Decision 1 — JWT Signing Algorithm

**Decision**: ES256 (ECDSA P-256)

**Rationale**: Approved in spec clarifications (Session 2026-08-20). Asymmetric signing allows downstream services (E04, E09, E23) to verify tokens locally via cached JWKS public keys without calling E01 at validation time. ES256 produces smaller signatures than RS256 and is preferred in modern fintech stacks. Supports future service extraction without introducing synchronous E01 dependencies.

**Key architecture points**:
- E01 is the sole signing authority
- JWT header carries `kid` for key rotation
- JWKS endpoint at `GET /v1/.well-known/jwks.json`
- Key rotation: overlapping old/new public keys in JWKS until old tokens expire
- Private keys in platform secret manager (environment secrets); never in `platform.configuration_values`

**Alternatives considered**: RS256 (larger keys, otherwise equivalent); HS256 (symmetric, not compatible with service extraction goal — rejected).

---

## Decision 2 — Service-to-Service Authentication

**Decision**: JWT service-identity tokens issued by E01

**Rationale**: Approved in spec clarifications (Session 2026-08-20). Consistent with the bearer-token model used for user identities. Auditable via `jti`. Survives service extraction since downstream services verify via JWKS without calling E01.

**Key architecture points**:
- Actor types: `USER`, `SERVICE`, `SYSTEM`
- Service tokens: ES256, audience-restricted, time-bounded (`exp`), unique `jti`
- On-behalf-of delegation: service token carries both service actor identity and originating user identity
- Authorization: capability/scope-based; valid service JWT does not grant unrestricted access
- Service token provisioning endpoint: exact path TBD — defined in contracts phase
- Service token lifetime: configurable; not hard-coded; exact value TBD

**Alternatives considered**: Internal API key (not JWT-compatible, no JWKS path); mTLS (complex in modular monolith); token introspection only (creates E01 runtime dependency).

---

## Decision 3 — Password/PIN Hashing

**Decision**: Argon2id for PINs and passwords (Laravel's `Hash::make()` default with Argon2id driver)

**Rationale**: Argon2id is the OWASP-recommended algorithm for password/PIN hashing. Memory-hard, resistant to GPU and ASIC attacks. Laravel ships Argon2id support natively via PHP 7.2+ `password_hash()`.

**Key architecture points**:
- `identity.credentials.credential_hash`: stores the Argon2id hash string (includes algorithm, cost, salt)
- Algorithm version embedded in the hash string — enables future migration without schema changes
- Cost parameters sourced from `platform.configuration_values` (memory, time, threads)
- Raw PIN/password MUST NOT appear in logs, responses, events, or audit records

**Alternatives considered**: bcrypt (weaker against GPU attacks); scrypt (limited Laravel support).

---

## Decision 4 — OTP Storage

**Decision**: SHA-256 hash of the OTP stored in `identity.otp_challenges.otp_hash`

**Rationale**: OTPs are single-use, short-TTL (5 minutes default), and low-value targets compared to passwords. SHA-256 is sufficient for OTP storage since the OTP space is large enough given retry limits (5 attempts). Argon2id would be over-engineered here and adds latency to OTP verification on every attempt.

**Key architecture points**:
- OTP value generated server-side using PHP `random_int()` (CSPRNG)
- OTP delivered to user via `OtpDeliveryContract` (provider TBD)
- OTP never stored in logs, events, or responses
- OTP hash cleared/invalidated after verification or expiry

---

## Decision 5 — Refresh Token Storage

**Decision**: Opaque random token (256-bit entropy) stored as SHA-256 hash in `identity.sessions.refresh_token_hash`

**Rationale**: Refresh tokens are long-lived (30-day default) and high-value. Storing only the hash prevents token theft from database access. The opaque format is standard for refresh tokens (RFC 6749 recommendation for opaque bearer tokens).

**Key architecture points**:
- Token generated using PHP `random_bytes(32)` + base64url encoding
- `identity.sessions.refresh_token_hash = hash('sha256', $rawToken)`
- Revoked refresh token hashes cached in Redis for fast rejection (TTL aligned with token TTL)
- Rotation: every refresh invalidates previous token; presenting rotated token triggers all-session revocation

---

## Decision 6 — Device Fingerprinting

**Decision**: Platform-generated stable device identifier, stored as SHA-256 hash

**Rationale**: Raw device identifiers (UDID, advertising ID, etc.) are PII-adjacent. Hashing at ingestion prevents PII storage while maintaining device identity for new-device detection.

**Key architecture points**:
- Client sends device fingerprint in request header (content TBD by mobile platform conventions)
- Server computes `hash('sha256', $deviceFingerprint)` before storing in `identity.devices.device_fingerprint`
- Comparison is always hash-to-hash; raw fingerprint never persisted
- TBD-004: exact fingerprint composition (biometric SDK dependency) — uses abstraction layer

---

## Decision 7 — Laravel Module Structure

**Decision**: Standard modular monolith convention from Platform Foundation (`App\Modules\Identity\`)

**Rationale**: Matches the extraction-ready modular architecture established in Platform Foundation. Consistent with ADR-003 (schema-per-module) and module contract patterns from `module-contracts.md`.

**Module layout**:
```
services/api/app/Modules/Identity/
├── Domain/
│   ├── Contracts/          # OtpDeliveryContract, BiometricAssertionContract, JwksProviderContract
│   ├── Entities/           # Domain entities (non-Eloquent)
│   ├── Services/           # AuthenticationService, TokenService, OtpService, etc.
│   ├── Events/             # In-memory domain events
│   ├── Exceptions/
│   └── ValueObjects/
├── Application/
│   ├── Commands/           # RegisterUser, LoginWithPin, RefreshToken, etc.
│   ├── Queries/            # GetActiveSessions, GetUserByToken, etc.
│   └── DTOs/
├── Infrastructure/
│   ├── Models/             # Eloquent: User, Credential, Session, Device, OtpChallenge
│   ├── Repositories/       # IdentityUserRepository, SessionRepository, etc.
│   ├── Http/
│   │   ├── Controllers/    # AuthController, TokenController, SessionController, etc.
│   │   ├── Requests/       # Thin form-request validation only
│   │   └── Resources/      # API response resources
│   ├── Migrations/         # identity.* table migrations
│   ├── Jobs/               # SessionCleanupJob, OtpCleanupJob
│   └── Providers/          # IdentityServiceProvider
└── Tests/
    ├── Unit/
    ├── Integration/
    ├── Feature/
    ├── Security/
    └── Performance/
```

---

## Decision 8 — JWKS Key Rotation Mechanism

**Decision**: JSON Web Key Set with overlapping keys; rotation via environment secret replacement + JWKS endpoint cache invalidation

**Key architecture points**:
- Active private key stored as environment secret `IDENTITY_JWT_PRIVATE_KEY` (PEM or JWK format)
- Retired (still-valid) public key stored as environment secret `IDENTITY_JWT_OLD_PUBLIC_KEY` (optional, present during rotation window)
- JWKS endpoint serves all current public keys identified by `kid`
- `kid` value = SHA-256 prefix of the public key DER encoding (stable across application restarts)
- JWKS response cached in Redis (TTL: configurable, e.g., 300s); `Cache-Control: public, max-age=300` on the HTTP response
- On key rotation: update secrets, deploy, old key remains in JWKS until all tokens signed with it expire
- Downstream services must implement JWKS refresh (at cache TTL or on `kid` miss)

---

## Decision 9 — Flutter Secure Storage

**Decision**: `flutter_secure_storage` package backed by Android Keystore / iOS Keychain

**Rationale**: Platform-recommended secure storage on both Android (Keystore) and iOS (Keychain). Tokens stored here are inaccessible from outside the app (no root access required for read on modern Android with key attestation).

**Key architecture points**:
- Access token: stored in flutter_secure_storage; wiped on logout/expiry
- Refresh token: stored in flutter_secure_storage
- Biometric private key (TBD-004): stored in secure enclave / Keystore via platform-specific SDK
- Biometric data: NEVER leaves device, NEVER sent to server
- Background token refresh: checked on app resume; refresh triggered before expiry

---

## Decision 10 — Metrics Backend

**Decision**: Laravel Telescope for local dev; production metrics via PHP OpenTelemetry SDK → OTLP exporter

**Rationale**: OpenTelemetry is the platform-standard for metrics, traces, and logs. The approved standard metric set (auth counters, OTP send rate, lockout rate, token refresh rate, step-up trigger rate, latency histograms) maps to OTel metrics primitives (Counter, Histogram). Backend collector is a deployment decision (e.g., Prometheus, Datadog) — not hardcoded.

**Key architecture points**:
- Each metric emitted via `OpenTelemetry\API\Metrics\MeterInterface`
- Meter name: `identity`
- Metric names: `identity.auth.success`, `identity.auth.failure`, `identity.otp.sent`, `identity.account.locked`, `identity.token.refresh`, `identity.step_up.triggered`, `identity.auth.latency`
- Labels MUST NOT carry PII, credentials, OTP codes, or secrets
- Latency histogram buckets: [50, 100, 200, 300, 500, 750, 1000, 2500] ms

---

## Decision 11 — Performance Test Tooling

**Decision**: K6 for load/performance tests; Pest for API + integration tests

**Rationale**: K6 is script-based (JavaScript), container-friendly, and supports parametric load profiles (concurrent user volume injected at runtime via env vars). Integrates with CI. Pest already used in Platform Foundation.

**Key architecture points**:
- K6 scripts in `tests/performance/identity/`
- Concurrent user target injected via `K6_VUSER_COUNT` env var (TBD — not hardcoded)
- P95 assertions baked into K6 threshold config: `{ http_req_duration: ['p(95)<500'] }` for login
- `TOKEN_REFRESH_P95_MS=200` and `OTP_VERIFY_P95_MS=500` as K6 env var thresholds

---

## Decision 12 — Configuration Seed Migration

**Decision**: Laravel migration class with `INSERT ... ON CONFLICT (key) DO NOTHING` for all 25 identity configuration keys

**Rationale**: Idempotent by design — safe to run on fresh installs and on existing production databases. Matches BR-017 requirement.

**Key architecture points**:
- Migration class: `2026_xx_xx_000001_seed_identity_configuration_defaults`
- Each key inserted using `DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([...])`
- Zero overwrite of existing values; full idempotency guaranteed at SQL level
- Migration runs within Platform schema connection (`platform`), not `identity` connection
