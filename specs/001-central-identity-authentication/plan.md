# Implementation Plan — E01 Central Identity & Authentication

**Feature**: `001-central-identity-authentication` | **Epic**: E01 | **Date**: 2026-08-21 | **Status**: Ready for Tasks

---

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — no stack changes; no ADRs required
- [x] No TBD value invented — biometric SDK (TBD-004), concurrent-user volume, service-token lifetime, unspecified security policy values remain TBD; step-up threshold default (10000 BWP) was explicitly authorized in the approved spec and is seeded as a runtime-configurable configuration value, not hard-coded
- [x] Authorization remains server-side — E01 provides actor context; E04 evaluates permissions
- [x] Financial integrity preserved — E01 has no financial operations; step-up is a prerequisite gate only
- [x] Security/audit requirements incorporated — 18 audit actions; HMAC-SHA256 IP; secrets in secret manager
- [x] UX/design-system requirements incorporated — Platform Foundation design system reused; all states required
- [x] QA strategy incorporated — unit, integration, API, security, concurrency, performance, mobile, E2E
- [x] Platform Foundation consumed, not recreated — uses outbox, audit, config, idempotency, correlation ID services

---

## Technical Context

| Concern | Decision |
|---|---|
| Backend | Laravel / PHP 8.4+ (modular monolith, `App\Modules\Identity\`) |
| Database | PostgreSQL; `identity` schema; `identity` named connection (ADR-002, ADR-003) |
| Queue / Cache | Redis + Laravel Horizon |
| Web Admin | Next.js + TypeScript |
| Mobile | Flutter + Riverpod |
| API | REST/OpenAPI; `/v1/` prefix; Platform Foundation envelope |
| JWT Algorithm | ES256 (ECDSA P-256); `kid` header; JWKS public-key distribution |
| Service Auth | E01-issued JWT service-identity tokens; ES256; audience-restricted |
| Credential Hash | Argon2id (PHP native; cost parameters from config) |
| OTP Hash | SHA-256 |
| Refresh Token | 256-bit opaque token; SHA-256 hash stored |
| Metrics | OTel Meter (`identity`); standard set (see Observability section) |
| Performance Tests | K6; parametric load (concurrent users TBD at run time) |
| Module namespace | `App\Modules\Identity\` |

---

## Architecture Decisions

No new ADRs required beyond what Platform Foundation established. The following decisions from Platform Foundation apply directly:

| ADR | Application to E01 |
|---|---|
| ADR-002 Database Connection Naming | E01 uses connection name `identity` |
| ADR-003 Schema-per-Module | E01 owns `identity.*` schema; no cross-schema JOINs |
| ADR-004 Idempotency Strategy | E01 idempotent endpoints use `platform.idempotency_records` via `IdempotencyServiceInterface` |
| ADR-005 Outbox Pattern | All E01 integration events via `EventPublisherInterface` + `platform.outbox_events` |

New E01-specific architecture decisions (document in `docs/adrs/` as part of implementation):

| ADR | Decision |
|---|---|
| ADR-006 (proposed) | ES256 JWT signing with JWKS key rotation for Identity tokens |
| ADR-007 (proposed) | E01 service-identity JWT issuance pattern and on-behalf-of delegation model |

---

## Module Structure

```
services/api/app/Modules/Identity/
├── Domain/
│   ├── Contracts/
│   │   ├── OtpDeliveryContract.php         # Abstract OTP delivery (SMS/email)
│   │   ├── BiometricAssertionContract.php  # Abstract biometric assertion verification (TBD-004)
│   │   └── JwksProviderContract.php        # Exposes public keys for JWKS endpoint
│   ├── Entities/
│   │   ├── IdentityUser.php
│   │   ├── CredentialRecord.php
│   │   ├── SessionRecord.php
│   │   ├── DeviceRecord.php
│   │   └── OtpChallengeRecord.php
│   ├── Services/
│   │   ├── AuthenticationService.php       # Login, OTP verification, credential validation
│   │   ├── RegistrationService.php         # Account registration flow
│   │   ├── CredentialService.php           # PIN create/change/reset/block/unblock
│   │   ├── OtpService.php                  # OTP generation, delivery, validation
│   │   ├── TokenService.php                # JWT access/step-up token issuance (ES256)
│   │   ├── RefreshTokenService.php         # Refresh token rotation + reuse detection
│   │   ├── SessionService.php              # Session lifecycle management
│   │   ├── DeviceService.php               # Device detection and verification
│   │   ├── MfaService.php                  # MFA enrollment, challenge, verification
│   │   ├── StepUpService.php               # Step-up trigger evaluation and token issuance
│   │   ├── ServiceIdentityService.php      # Service JWT issuance and validation
│   │   ├── LockoutService.php              # Progressive lockout logic
│   │   ├── JwksService.php                 # JWKS key management and rotation
│   │   └── ConfigurationSecurityService.php # Admin config read/write with validation
│   ├── Events/
│   │   └── (in-memory domain events for use within same request lifecycle)
│   ├── Exceptions/
│   │   ├── InvalidCredentialsException.php
│   │   ├── AccountLockedException.php
│   │   ├── OtpExpiredException.php
│   │   ├── OtpAttemptExceededException.php
│   │   ├── DeviceVerificationRequiredException.php
│   │   ├── StepUpRequiredException.php
│   │   ├── RefreshTokenReuseException.php
│   │   ├── PinComplexityException.php
│   │   ├── PinBlockedException.php
│   │   └── ConfigurationConflictException.php
│   └── ValueObjects/
│       ├── ActorType.php                   # USER | SERVICE | SYSTEM enum
│       ├── CredentialType.php
│       ├── SessionStatus.php
│       └── OtpChallengeType.php
├── Application/
│   ├── Commands/
│   │   ├── RegisterUserCommand.php
│   │   ├── VerifyOtpCommand.php
│   │   ├── ResendOtpCommand.php
│   │   ├── LoginWithOtpCommand.php
│   │   ├── LoginWithPinCommand.php
│   │   ├── LoginWithPasswordCommand.php
│   │   ├── LoginWithBiometricCommand.php   # TBD-004 abstraction preserved
│   │   ├── RefreshTokenCommand.php
│   │   ├── LogoutCommand.php
│   │   ├── LogoutAllCommand.php
│   │   ├── VerifyDeviceCommand.php
│   │   ├── CreatePinCommand.php
│   │   ├── ChangePinCommand.php
│   │   ├── ResetPinCommand.php
│   │   ├── UnblockPinCommand.php
│   │   ├── EnableMfaCommand.php
│   │   ├── DisableMfaCommand.php
│   │   ├── VerifyMfaCommand.php
│   │   ├── RequestStepUpCommand.php
│   │   ├── CompleteStepUpCommand.php
│   │   ├── RevokeSessionCommand.php
│   │   └── IssueServiceTokenCommand.php
│   ├── Queries/
│   │   ├── GetActiveSessionsQuery.php
│   │   └── GetUserByIdQuery.php
│   └── DTOs/
│       ├── AuthTokenDTO.php                # { access_token, refresh_token, expires_in }
│       ├── StepUpTokenDTO.php
│       ├── ServiceTokenDTO.php
│       └── SessionSummaryDTO.php
├── Infrastructure/
│   ├── Models/
│   │   ├── User.php                        # identity.users
│   │   ├── Credential.php                  # identity.credentials
│   │   ├── Session.php                     # identity.sessions
│   │   ├── Device.php                      # identity.devices
│   │   └── OtpChallenge.php                # identity.otp_challenges
│   ├── Repositories/
│   │   ├── EloquentUserRepository.php
│   │   ├── EloquentCredentialRepository.php
│   │   ├── EloquentSessionRepository.php
│   │   ├── EloquentDeviceRepository.php
│   │   └── EloquentOtpChallengeRepository.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php          # POST /register, /otp/verify, /otp/resend, /login
│   │   │   ├── BiometricController.php     # POST /login/biometric
│   │   │   ├── TokenController.php         # POST /token/refresh
│   │   │   ├── SessionController.php       # GET/DELETE /sessions, POST /logout, /logout/all
│   │   │   ├── DeviceController.php        # POST /device/verify
│   │   │   ├── PinController.php           # POST/PUT /pin, /pin/reset, /pin/unblock
│   │   │   ├── MfaController.php           # POST /mfa/enable, /mfa/disable, /mfa/verify
│   │   │   ├── StepUpController.php        # POST /step-up
│   │   │   ├── JwksController.php          # GET /.well-known/jwks.json
│   │   │   ├── ServiceTokenController.php  # POST /auth/service/token (path TBD)
│   │   │   └── AdminSessionController.php  # POST /sessions/{id}/revoke (Admin)
│   │   ├── Requests/                       # Form requests (validation only)
│   │   └── Resources/                      # API Resources (response shaping)
│   ├── Migrations/
│   │   ├── 2026_xx_xx_001_create_identity_users_table.php
│   │   ├── 2026_xx_xx_002_create_identity_credentials_table.php
│   │   ├── 2026_xx_xx_003_create_identity_sessions_table.php
│   │   ├── 2026_xx_xx_004_create_identity_devices_table.php
│   │   ├── 2026_xx_xx_005_create_identity_otp_challenges_table.php
│   │   └── 2026_xx_xx_006_seed_identity_configuration_defaults.php
│   ├── Jobs/
│   │   ├── SessionCleanupJob.php           # Purge expired/revoked sessions (>90 days)
│   │   └── OtpChallengeCleanupJob.php      # Purge expired/invalidated challenges (>24h)
│   └── Providers/
│       └── IdentityServiceProvider.php
└── Tests/
    ├── Unit/
    ├── Integration/
    ├── Feature/                            # API (Pest)
    ├── Security/
    └── Performance/                        # K6 scripts
```

---

## Database Plan

### Schema Ownership

E01 owns the `identity` PostgreSQL schema exclusively. This schema was created empty by Platform Foundation. All tables are created by E01 migrations using the `identity` named database connection.

**Named connection**: `identity` (per ADR-002)
**Connection config key**: `database.connections.identity`

### Migrations (ordered)

All migrations use `DB::connection('identity')` and fully qualify table names as `identity.{table}`.

| # | Migration | Creates |
|---|---|---|
| 001 | `create_identity_users_table` | `identity.users` |
| 002 | `create_identity_credentials_table` | `identity.credentials` |
| 003 | `create_identity_sessions_table` | `identity.sessions` |
| 004 | `create_identity_devices_table` | `identity.devices` |
| 005 | `create_identity_otp_challenges_table` | `identity.otp_challenges` |
| 006 | `seed_identity_configuration_defaults` | Rows in `platform.configuration_values` |

Migration 006 uses `DB::connection('platform')` and `insertOrIgnore` — idempotent; never overwrites existing values.

### Indexes

| Table | Index | Type | Purpose |
|---|---|---|---|
| `identity.users` | `mobile_number` | UNIQUE (partial: NOT NULL) | Duplicate registration check |
| `identity.users` | `email` | UNIQUE (partial: NOT NULL) | Duplicate registration check |
| `identity.users` | `status` | B-tree | Lockout queries |
| `identity.credentials` | `(user_id, credential_type)` | UNIQUE | One active credential per type per user |
| `identity.credentials` | `user_id` | B-tree | Credential lookup |
| `identity.sessions` | `user_id` | B-tree | Session queries per user |
| `identity.sessions` | `refresh_token_hash` | UNIQUE | Refresh token lookup |
| `identity.sessions` | `(user_id, status)` | B-tree | Active session queries |
| `identity.sessions` | `(status, updated_at)` | B-tree | Cleanup job queries |
| `identity.devices` | `(user_id, device_fingerprint)` | UNIQUE | New device detection |
| `identity.devices` | `user_id` | B-tree | Device list queries |
| `identity.otp_challenges` | `user_id` | B-tree | Challenge lookup |
| `identity.otp_challenges` | `(identifier, challenge_type, status)` | B-tree | Active challenge lookup |
| `identity.otp_challenges` | `(status, expires_at)` | B-tree | Cleanup job queries |

### Retention Implementation

Horizon background jobs (scheduled via `app/Console/Kernel.php`):

| Job | Schedule | Criteria | Connection |
|---|---|---|---|
| `SessionCleanupJob` | Daily (configurable) | `status IN ('expired','revoked','logged_out') AND updated_at < NOW() - INTERVAL '90 days'` | `identity` |
| `OtpChallengeCleanupJob` | Hourly (configurable) | `status IN ('expired','invalidated','verified') AND updated_at < NOW() - INTERVAL '24 hours'` | `identity` |

Retention intervals sourced from `platform.configuration_values` keys (seeds TBD for retention keys). Active records (`status='active'`, `status='pending'`) are NEVER deleted by cleanup jobs.

---

## API Plan

All endpoints are prefixed `/v1/` and use the Platform Foundation API response envelope (`ApiResponse`). Error codes follow the platform taxonomy plus E01-specific codes listed in `contracts/authentication-api.md`.

### Endpoint Map

| Group | Method | Path | Auth | Idempotent |
|---|---|---|---|---|
| **Registration** | POST | `/v1/auth/register` | None | Yes (`Idempotency-Key`) |
| **OTP** | POST | `/v1/auth/otp/verify` | None | Yes |
| **OTP** | POST | `/v1/auth/otp/resend` | None | No (rate-limited) |
| **Login** | POST | `/v1/auth/login` | None | No |
| **Login** | POST | `/v1/auth/login/biometric` | None | No |
| **Device** | POST | `/v1/auth/device/verify` | Partial | Yes |
| **Token** | POST | `/v1/auth/token/refresh` | Refresh token | No |
| **Session** | GET | `/v1/auth/sessions` | Bearer | No |
| **Session** | DELETE | `/v1/auth/sessions/{id}` | Bearer | Yes |
| **Session** | POST | `/v1/auth/logout` | Bearer | Yes |
| **Session** | POST | `/v1/auth/logout/all` | Bearer | Yes |
| **Admin Session** | POST | `/v1/auth/sessions/{id}/revoke` | Admin Bearer | Yes |
| **PIN** | POST | `/v1/auth/pin` | Bearer | Yes |
| **PIN** | PUT | `/v1/auth/pin` | Bearer | Yes |
| **PIN** | POST | `/v1/auth/pin/reset` | OTP | Yes |
| **PIN** | POST | `/v1/auth/pin/unblock` | OTP | Yes |
| **MFA** | POST | `/v1/auth/mfa/enable` | Bearer | Yes |
| **MFA** | POST | `/v1/auth/mfa/disable` | Bearer | Yes |
| **MFA** | POST | `/v1/auth/mfa/verify` | Partial session | No |
| **Step-Up** | POST | `/v1/auth/step-up` | Bearer | No |
| **JWKS** | GET | `/v1/.well-known/jwks.json` | None | — |
| **Service Token** | POST | TBD (service credential endpoint) | Service credential | Yes |
| **Config (Admin)** | GET | `/v1/admin/platform/configuration/security` | Admin Bearer | — |
| **Config (Admin)** | PUT | `/v1/admin/platform/configuration/security/{key}` | Admin Bearer | Yes |

### Route Registration

Routes registered in `IdentityServiceProvider::boot()` via `Route::prefix('v1')->group(...)`. Admin routes require the `admin` middleware and `platform.configuration.view` / `platform.configuration.update` permission middleware.

### Middleware Stack (per endpoint type)

- **Public (no auth)**: `cors`, `throttle:identity-public`, `correlation-id`
- **Bearer-authenticated**: `cors`, `throttle:identity-auth`, `correlation-id`, `identity.auth`
- **Admin**: `cors`, `throttle:identity-admin`, `correlation-id`, `identity.auth`, `identity.require-actor-type:admin`
- **Refresh token**: `cors`, `throttle:identity-refresh`, `correlation-id`

Throttle rates sourced from `platform.configuration_values` — not hard-coded.

---

## Event Plan

All integration events use the Platform Foundation `EventPublisherInterface` and `platform.outbox_events`. Events emitted within the same DB transaction as the state change that triggered them.

### Event Catalogue

| Event Name | Trigger | Integration Consumers | Version |
|---|---|---|---|
| `Identity.User.Registered` | Account activated on OTP verify | E02, E05, E17 | 1.0.0 |
| `Identity.User.RegistrationOtpRequested` | OTP generated for registration | E17 | 1.0.0 |
| `Identity.User.RegistrationOtpResendRequested` | OTP resend accepted | E17 | 1.0.0 |
| `Identity.Session.Created` | Successful authentication | E17, E26 | 1.0.0 |
| `Identity.Session.LoginFailed` | Authentication failure | E18, E26 | 1.0.0 |
| `Identity.Account.Locked` | Account locked after failures | E17, E26 | 1.0.0 |
| `Identity.Device.NewDeviceDetected` | Login from unrecognized device | E17, E26 | 1.0.0 |
| `Identity.Device.Verified` | New device trust established | — | 1.0.0 |
| `Identity.Session.Terminated` | User logout (current) | — | 1.0.0 |
| `Identity.Session.AllTerminated` | User logout (all sessions) | E17 | 1.0.0 |
| `Identity.Session.Expired` | Background session expiry | — | 1.0.0 |
| `Identity.Session.Revoked` | Admin revoked session | E17 | 1.0.0 |
| `Identity.Credential.PinCreated` | PIN created | — | 1.0.0 |
| `Identity.Credential.PinChanged` | PIN changed | E17 | 1.0.0 |
| `Identity.Credential.PinReset` | PIN reset via recovery | E17 | 1.0.0 |
| `Identity.Credential.PinBlocked` | PIN blocked | E17 | 1.0.0 |
| `Identity.Credential.PinUnblocked` | PIN unblocked | E17 | 1.0.0 |
| `Identity.MFA.Enabled` | MFA enabled | E17 | 1.0.0 |
| `Identity.MFA.Disabled` | MFA disabled | E17 | 1.0.0 |
| `Identity.MFA.ChallengeVerified` | MFA challenge verified | — | 1.0.0 |
| `Identity.MFA.StepUpCompleted` | Step-up completed | Calling domain | 1.0.0 |
| `Identity.MFA.StepUpRequired` | Step-up triggered | — | 1.0.0 |

### Event Payload Fields (all events)

All events include the standard Platform Foundation envelope fields (`id`, `aggregate_id`, `aggregate_type`, `actor_id`, `correlation_id`, `causation_id`, `occurred_at`, `event_name`, `event_version`, `metadata`). Payload PII is masked (mobile → `+267*****678`; email → `j*****@domain.com`).

### Consumer Idempotency

All E01 event consumers (in E02, E05, E17, E18, E26) MUST implement the Consumer Idempotency Contract from `specs/999-platform-foundation/contracts/event-envelope.md`.

---

## Audit Plan

All audit records use `AuditServiceInterface` → `platform.audit_events`. E01 does NOT create a secondary audit registry.

Audit actions must be registered in `docs/architecture/audit-action-vocabulary.md`.

| Audit Action | Severity | Actor ID Rule |
|---|---|---|
| `identity.user.registered` | INFO | Zero-UUID (unauthenticated at registration) |
| `identity.session.created` | INFO | Authenticated user UUID |
| `identity.session.login-failed` | WARN | Zero-UUID or attempted user UUID |
| `identity.account.locked` | WARN | System (zero-UUID) |
| `identity.device.new-device-detected` | WARN | Authenticated user UUID |
| `identity.session.terminated` | INFO | Authenticated user UUID |
| `identity.session.all-terminated` | INFO | Authenticated user UUID |
| `identity.session.revoked` | WARN | Admin actor UUID |
| `identity.credential.pin-created` | INFO | Authenticated user UUID |
| `identity.credential.pin-changed` | INFO | Authenticated user UUID |
| `identity.credential.pin-reset` | WARN | System/OTP-verified UUID |
| `identity.credential.pin-blocked` | WARN | System (zero-UUID) |
| `identity.credential.pin-unblocked` | INFO | System/OTP-verified UUID |
| `identity.mfa.enabled` | INFO | Authenticated user UUID |
| `identity.mfa.disabled` | WARN | Authenticated user UUID |
| `identity.mfa.step-up-completed` | INFO | Authenticated user UUID |
| `identity.mfa.step-up-failed` | WARN | Authenticated user UUID |
| `identity.service.token-issued` | INFO | Service identity (service UUID or name) |

**IP representation in all audit records**: `HMAC-SHA256(platform_secret, ip_address)`. The HMAC secret is loaded from the platform secret manager at startup; never from `platform.configuration_values`.

**PII masking**: Mobile numbers and email addresses in `before_state`/`after_state` must be masked before passing to `AuditService::record()`. Raw credential hashes and OTP values MUST NOT appear in audit payloads.

---

## Security Plan

### Credential Protection

| Credential | Storage | Algorithm |
|---|---|---|
| PIN | `identity.credentials.credential_hash` | Argon2id (memory-hard; cost from config) |
| Password | `identity.credentials.credential_hash` | Argon2id |
| OTP | `identity.otp_challenges.otp_hash` | SHA-256 |
| Refresh token | `identity.sessions.refresh_token_hash` | SHA-256 |
| Biometric | Server holds public key only (`biometric_public_key`); private key never leaves device | ECDSA assertion |

### JWT Signing (ES256)

- Private key stored as environment secret `IDENTITY_JWT_PRIVATE_KEY` (PEM format)
- `TokenService::sign()` uses `lcobucci/jwt` package (Laravel-compatible) with ES256 algorithm
- Access token claims: `iss`, `sub` (user UUID), `aud`, `iat`, `exp`, `jti`, `session_id`, `actor_type`, `roles`, `correlation_id`
- Step-up token additional claims: `step_up_verified_at`, `step_up_scope`, `aud` (restricted to specific operation/domain)
- `kid` embedded in JWT header; value = SHA-256 fingerprint of the public key

### JWKS Endpoint

- `GET /v1/.well-known/jwks.json` — public; no authentication required
- Response: JWK Set object with all currently active public keys
- Each JWK: `kty: "EC"`, `crv: "P-256"`, `use: "sig"`, `alg: "ES256"`, `kid`, `x`, `y`
- Redis cache: key `identity:jwks`, TTL configurable (default 300s)
- `Cache-Control: public, max-age=300` on HTTP response
- On key rotation: old key remains in JWKS until `NOW() + max_access_token_ttl` to allow existing tokens to be validated

### Key Rotation Procedure

1. Generate new ES256 key pair
2. Store new private key in secret manager as `IDENTITY_JWT_NEW_PRIVATE_KEY`
3. Add new public key to JWKS endpoint alongside old (`IDENTITY_JWT_OLD_PUBLIC_KEY`)
4. Deploy application — new tokens use new key
5. After old token TTL expires: remove old key from JWKS; retire `IDENTITY_JWT_OLD_PUBLIC_KEY`

### Service Token Authorization

- Service tokens carry `actor_type: "SERVICE"` and explicit `scope` claim
- E04 (authorization) validates `scope` against permitted operations before granting access
- `aud` claim is the receiving service's identifier — MUST match the validating service's own identifier
- Delegation: when `actor_type: "SERVICE"` and service acts on behalf of user, token carries additional `user_sub` (UUID of the originating user) — both service identity and user identity are audited

### Session Security

- Session records created within the same DB transaction as token issuance
- Refresh token hash stored with SHA-256; raw token returned to client once only
- Redis revocation list: `SET identity:revoked:{session_id} 1 EX {ttl}` on logout/revocation
- Token validation middleware checks Redis revocation list before business logic

### Authentication Abuse Handling

- Failed login attempts incremented in `identity.users.failed_attempt_count` (atomic SQL UPDATE)
- Lockout triggered at `auth.lockout.max_failed_attempts` (from config)
- Progressive lockout: `lockout_duration = initial_duration * 2^(episode-1)` for episodes > 1 (where episode count tracked in `lock_reason` metadata or a dedicated column)
- OTP rate limiting: resend cooldown and hourly send limit enforced at `OtpService` layer using Redis counters
- All auth failures emit `Identity.Session.LoginFailed` event for E18 risk signal

### MFA Enforcement

- Admin accounts: MFA required on every login (cannot bypass)
- Customer/Agent: MFA optional (enableable by user)
- MFA session state: `identity.sessions.status = 'mfa_pending'` until challenge verified
- TOTP verification: server-side TOTP using `BaconQrCode` + `chillerlan/php-authenticator` or equivalent
- FIDO2/WebAuthn (Admin): `web-auth/webauthn-framework` package (Laravel-compatible); assertion verified server-side against registered public key

### Step-Up Security

- Step-up evaluation: triggered by (a) `StepUpService::evaluateAmount()` against `auth.step_up_threshold` OR (b) explicit `step_up_required: true` signal in request from risk engine
- Multi-currency: if transaction currency ≠ `auth.step_up_threshold_currency`, use platform FX; if FX unavailable, ALWAYS require step-up (fail-safe)
- Step-up token `aud` is scoped to the specific operation domain; other services MUST NOT accept this token
- Step-up token TTL: configurable; exact value TBD

---

## Flutter Customer Plan (E01 Scope)

Flutter app uses Riverpod for state management. All authentication state lives in `AuthStateNotifier`.

### Auth State Machine

```
unauthenticated
    ↓ (register / login attempt)
otp_pending
    ↓ (OTP verified)
pin_pending | authenticated
    ↓ (MFA required)
mfa_pending
    ↓ (MFA verified)
authenticated
    ↓ (step-up required)
step_up_pending
    ↓ (step-up completed)
authenticated (with step-up claim)
    ↓ (logout / expiry / revocation)
unauthenticated (with reason)

locked → (recovery flow) → pin_pending
```

### Screens (E01 only)

| Screen | Stories | States Required |
|---|---|---|
| Enter mobile number | US-AUTH-001 | Default, loading, validation error, duplicate error |
| Enter email | US-AUTH-002 | Default, loading, validation error, duplicate error |
| Enter OTP | US-AUTH-003, US-AUTH-005 | Default, loading, invalid, expired, locked, resend CTA |
| OTP resend | US-AUTH-004, US-AUTH-006 | Loading, cooldown countdown, max-sends error |
| Login (mobile OTP) | US-AUTH-009 | Default, loading, error, locked |
| Login (PIN) | US-AUTH-010 | Default, loading, invalid, blocked, recovery CTA |
| Login (password) | US-AUTH-011 | Default, loading, invalid, locked |
| Login (biometric) | US-AUTH-012 | Prompt, success, fallback to PIN |
| Device verification | US-AUTH-017 | OTP challenge, success, error |
| New device alert | US-AUTH-016 | Notification banner, dismiss |
| Create PIN | US-AUTH-028 | PIN entry (6-digit), confirm, complexity error |
| Change PIN | US-AUTH-029 | Current PIN, new PIN, confirm, success, error |
| Reset PIN (recovery) | US-AUTH-030 | OTP challenge, new PIN, confirm, success |
| PIN blocked | US-AUTH-032 | Blocked state, recovery CTA |
| MFA setup | US-AUTH-034 | TOTP QR code, verify code, success |
| MFA verify | US-AUTH-036 | Code entry, error, max attempts |
| MFA disable | US-AUTH-035 | Confirm, success |
| Step-up challenge | US-AUTH-037 | Overlay/sheet, MFA entry, success, error |
| Active sessions | US-AUTH-026 | Session list, device metadata |
| Revoke session | US-AUTH-027 | Confirm, success |
| Logout | US-AUTH-022, US-AUTH-023 | Current / all sessions |

### Token Storage (Flutter)

- Access token: `flutter_secure_storage` → key `auth.access_token`
- Refresh token: `flutter_secure_storage` → key `auth.refresh_token`
- Token refresh: triggered by `401 TOKEN_EXPIRED`; implemented in `AuthInterceptor` (Dio)
- On refresh failure: clear tokens, navigate to login screen with `SessionExpiredReason`

---

## Flutter Agent Plan (E01 Scope)

Agent app uses the same Riverpod pattern and secure storage conventions as the Customer app. Agent auth is a strict subset of Customer auth.

### Agent Screens (E01 scope)

- Mobile OTP registration and login
- PIN login and management
- New device detection and verification
- Session management (view/revoke own sessions)
- No email registration (initial scope)
- No biometric (unless approved backlog items specify Agent biometric)

---

## Next.js Admin Plan (E01 Scope)

Admin flows use password + mandatory MFA. Step-up required for privileged operations.

### Admin Auth Flow

```
/admin/login → POST /v1/auth/login (password) → 
  → if mfa_pending: /admin/mfa → POST /v1/auth/mfa/verify →
  → authenticated → /admin dashboard
  → privileged action → step-up overlay → POST /v1/auth/step-up
```

### Admin E01 Pages

| Page | Route | Stories |
|---|---|---|
| Admin login | `/admin/login` | US-AUTH-011 |
| MFA verification | `/admin/mfa` | US-AUTH-036 |
| Session management | `/admin/sessions` | US-AUTH-025, US-AUTH-026 |
| Security configuration | `/admin/platform/security-config` | FR-E01-041 through FR-E01-052 |
| Security config: Authentication | `/admin/platform/security-config/authentication` | Config keys: pin, lockout |
| Security config: Session | `/admin/platform/security-config/session` | Config keys: session TTL, refresh |
| Security config: OTP | `/admin/platform/security-config/otp` | Config keys: otp.* |
| Security config: MFA | `/admin/platform/security-config/mfa` | Config keys: mfa.admin.* |
| Security config: Step-Up | `/admin/platform/security-config/step-up` | Config keys: step_up.* |
| Security config: Lockout | `/admin/platform/security-config/lockout` | Config keys: lockout.* |
| Security config: Audit Security | `/admin/platform/security-config/audit` | Config keys: security.ip_audit.* |

Admin components reuse the Platform Foundation design system (design tokens, shared components, accessibility foundation).

---

## Observability Plan

### Structured Logging

All E01 service methods emit structured logs at appropriate levels:
- Authentication events: INFO (success), WARN (failure, lockout, suspicious)
- Security events: WARN or ERROR
- Credential operations: INFO (exclude raw values)
- `correlation_id` and `request_id` present in every log entry

PII masking before logging: mobile numbers, email addresses. OTP values MUST NEVER appear in logs.

### Metrics

Emitted via PHP OpenTelemetry SDK (`OpenTelemetry\API\Metrics\MeterInterface`); meter name `identity`.

| Metric Name | Type | Labels | Notes |
|---|---|---|---|
| `identity.auth.success` | Counter | `actor_type`, `method` | Login successes |
| `identity.auth.failure` | Counter | `actor_type`, `reason` | Login failures; `reason` = enum (invalid_credential, locked, mfa_failed, etc.) |
| `identity.otp.sent` | Counter | `channel`, `challenge_type` | OTP sends; `channel` = sms | email |
| `identity.account.locked` | Counter | `actor_type` | Lockout events |
| `identity.token.refresh` | Counter | `result` | success | failure |
| `identity.step_up.triggered` | Counter | `trigger` | amount_threshold | risk_engine |
| `identity.auth.latency` | Histogram (P95) | `operation` | login, token_refresh, otp_verify; buckets: [50,100,200,300,500,750,1000,2500] ms |

Labels MUST NOT carry user IDs, mobile numbers, email addresses, tokens, OTP codes, or any PII.

### Distributed Tracing

OpenTelemetry trace spans created for each E01 service method. `correlation_id` propagated via `X-Correlation-ID` header and attached to each span as an attribute.

---

## Performance Plan

### Targets

| Operation | P95 Target | Measured At |
|---|---|---|
| Login (PIN or OTP) | ≤ 500 ms | API server boundary (excludes OTP delivery) |
| Token refresh | ≤ 200 ms | API server boundary (Redis cache warm) |
| OTP verification | ≤ 500 ms | API server boundary (excludes OTP delivery) |

### Test Harness (K6)

K6 scripts in `tests/performance/identity/`:

```
tests/performance/identity/
├── login-pin.js          # P95 ≤ 500ms
├── token-refresh.js      # P95 ≤ 200ms
├── otp-verify.js         # P95 ≤ 500ms
└── config.js             # Shared env vars + thresholds
```

**Concurrent user injection**:
```javascript
// config.js
export const options = {
  vus: __ENV.K6_VUSER_COUNT || 1,   // TBD — inject at run time
  duration: __ENV.K6_DURATION || '30s',
  thresholds: {
    http_req_duration: [`p(95)<${__ENV.LOGIN_P95_MS || 500}`],
  },
};
```

Concurrent-user volume is NOT hard-coded — injected via `K6_VUSER_COUNT` env var. Pre-release load tests use the approved target once it is defined.

### Optimization Requirements

- Database: indexes defined in migration (see Database Plan); all hot-path queries covered
- Redis: refresh token revocation cache; JWKS cache; rate limiting counters; session revocation cache
- Connection pooling: PgBouncer or Laravel persistent connections (deployment concern)
- Argon2id cost parameters: balance security vs. latency (configurable; do not over-provision)
- JWKS: serve from Redis cache on every request (avoid DB hits)

---

## Test Plan

### Unit Tests (Pest)

- All domain services (AuthenticationService, TokenService, OtpService, etc.)
- Value objects (ActorType, SessionStatus, etc.)
- PIN complexity rules
- Progressive lockout formula
- Refresh token reuse detection logic
- Step-up amount comparison (including fail-safe on missing FX)
- ES256 token signing and claim assembly
- HMAC-SHA256 IP hashing

### API / Feature Tests (Pest + Laravel HTTP test helpers)

- All 38 story endpoints: happy path, validation errors, auth errors
- All E01-specific error codes returned correctly
- Standard API envelope on all responses
- `X-Correlation-ID` and `X-Request-ID` present in all responses
- Idempotency: same request twice → same response, no duplicate side effects
- Admin-only endpoints reject non-admin actors

### Integration Tests

- Database: migrations run cleanly; indexes and constraints present
- Platform Foundation services wired: `AuditServiceInterface`, `EventPublisherInterface`, `ConfigurationServiceInterface`, `IdempotencyServiceInterface`
- Horizon jobs: `SessionCleanupJob` and `OtpChallengeCleanupJob` execute correctly
- Redis revocation list populated on logout/revocation
- JWKS endpoint serves correct JWK format

### Security Tests

- Invalid credentials: assert `INVALID_CREDENTIALS` with no identifier disclosure
- OTP after expiry: assert `OTP_EXPIRED`
- OTP replay: assert second use rejected
- Account lockout at threshold: assert `ACCOUNT_LOCKED`
- Locked account: assert authentication rejected
- Revoked token: assert `SESSION_REVOKED`
- Refresh token reuse: assert all-session revocation + `REFRESH_TOKEN_REUSE_DETECTED`
- PIN never in DB/logs/events (search audit/log payloads)
- OTP never in logs (structured log output assertion)
- Step-up token rejected as general access token (wrong audience)
- Admin login without MFA: assert rejected
- Service token with wrong `aud`: assert rejected
- HMAC-SHA256 IP in audit records (assert no raw IP)
- JWKS endpoint: assert correct JWK format; assert `Cache-Control` header

### Concurrency Tests

- Concurrent OTP verification (same challenge): exactly one succeeds
- Concurrent login requests: exactly one session created; idempotency holds
- Concurrent refresh token rotation: first succeeds; second triggers reuse detection
- Concurrent PIN change + PIN reset: conflict handled correctly
- Concurrent configuration update (stale version): `CONFIGURATION_CONFLICT` returned

### Token / JWKS Tests

- Access token: assert ES256 algorithm in header
- Access token: assert `kid` present and matches JWKS entry
- JWKS endpoint: assert public key matches the signing key
- Old key retained in JWKS during rotation window
- Step-up token: assert `aud` scoped to operation; assert rejected as general token

### Service Token Tests

- Service token issued for registered service
- Service token `aud` validated — wrong audience rejected
- On-behalf-of delegation: both service actor and user subject present in token
- Service token `jti` unique per issuance
- Audit record created for every service token issuance

### Mobile Tests (Flutter)

- Customer auth flows: OTP registration, PIN login, biometric (abstraction only — TBD-004)
- Secure token storage: tokens not accessible from non-app contexts
- Session expiry: app returns to login screen correctly
- Step-up overlay preserves in-progress operation context
- OTP input: numeric-only; auto-submit on final digit

### Web Tests (Next.js)

- Admin login with password + MFA
- MFA bypass attempt: rejected
- Step-up overlay for privileged admin operation
- Security configuration page: view and update config values
- Config validation error: displayed for invalid values

### Accessibility Tests

- WCAG AA contrast on all E01 screens (Flutter + Next.js)
- Keyboard navigation through all form fields and CTAs
- Screen-reader labels on all interactive elements
- Error messages associated with relevant form fields
- Loading states announced to screen readers

### Performance Tests (K6)

- Login P95 ≤ 500 ms
- Token refresh P95 ≤ 200 ms
- OTP verification P95 ≤ 500 ms
- Load injected via `K6_VUSER_COUNT` (value TBD)

### E2E Tests

- Scenario 1: New customer — registration → OTP verify → PIN create → PIN login
- Scenario 2: Account lockout → recovery via OTP → PIN login
- Scenario 3: Step-up MFA for high-value operation (amount trigger)
- Scenario 4: New device login → device verification → session granted
- Scenario 5: Refresh token reuse attack → all-session revocation

---

## Implementation Sequencing

The following phases MUST be executed in order. No phase may begin until its predecessor is complete and all tests for that phase pass.

| Phase | Name | Key Deliverables |
|---|---|---|
| P0 | Foundation Contracts | `OtpDeliveryContract`, `BiometricAssertionContract`, `JwksProviderContract`; `IdentityServiceProvider` scaffold; module namespace; route skeleton |
| P1 | Identity Persistence | Migrations 001–005; Eloquent models; repository interfaces and implementations; indexes; constraints |
| P2 | Configuration Seed | Migration 006 (idempotent config seed); `ConfigurationSecurityService`; seed tests |
| P3 | Registration & OTP | `RegistrationService`, `OtpService`; `OtpDeliveryContract` stub; `POST /register`, `POST /otp/verify`, `POST /otp/resend`; audit; events |
| P4 | Authentication (Login) | `AuthenticationService`; PIN/password/OTP login; `LockoutService` (progressive); `DeviceService`; login audit; events |
| P5 | Token Issuance | `TokenService` (ES256); `JwksService`; `JwksController`; `GET /v1/.well-known/jwks.json`; `kid` rotation support |
| P6 | Session & Refresh | `RefreshTokenService`; `SessionService`; `POST /token/refresh`; rotation; reuse detection; `GET /sessions`; `DELETE /sessions/{id}`; `POST /logout`; `POST /logout/all`; Redis revocation cache |
| P7 | PIN Management | `CredentialService`; `POST /pin`, `PUT /pin`, `POST /pin/reset`, `POST /pin/unblock`; complexity validation; block/unblock flow |
| P8 | MFA & Step-Up | `MfaService`; TOTP; FIDO2/WebAuthn (Admin); `StepUpService`; dual-trigger; multi-currency fail-safe; `POST /mfa/enable`, `/mfa/disable`, `/mfa/verify`, `/step-up` |
| P9 | Service Identity | `ServiceIdentityService`; service token issuance; `IdentityModuleContract::resolveServiceActor()`; service token endpoint (TBD path); on-behalf-of delegation; audience validation |
| P10 | Admin Session | `POST /sessions/{id}/revoke` (admin); admin session management |
| P11 | Config Admin API | `ConfigurationSecurityService`; `GET/PUT /admin/platform/configuration/security`; validation + security minimums; optimistic concurrency; audit |
| P12 | Flutter Customer | Auth state machine; Riverpod providers; secure storage; all Customer screens (Phases P3–P8 scope) |
| P13 | Flutter Agent | Agent subset screens |
| P14 | Next.js Admin | Admin login + MFA; session management; security configuration pages |
| P15 | Observability | OTel meter setup; all 7 metrics; latency histograms; structured logging audit |
| P16 | Performance & Security Testing | K6 scripts; all security test assertions; concurrency tests |
| P17 | E2E | Full E2E scenarios 1–5 |

---

## TBD Governance

The following items remain TBD and MUST NOT be converted to implementation values:

| Item | TBD | Impact |
|---|---|---|
| TBD-004 | Biometric SDK (Flutter; platform-specific) | `BiometricAssertionContract` abstraction holds; no SDK chosen |
| GAP-027 | Step-up threshold | **RESOLVED in spec** — Default seeded as `10000 BWP` (`auth.step_up_threshold`/`auth.step_up_threshold_currency`) per explicit speckit-specify authorization; runtime-configurable; MUST NOT be hard-coded in domain logic |
| Service token lifetime | `auth.service_token.ttl` key TBD | Configurable; not hard-coded; config key name TBD |
| Concurrent user volume | K6 load target | Injected at run time; not in test scripts |
| OTP provider credentials | Provider TBD | `OtpDeliveryContract` stub; credentials in secret manager |
| JWT access token TTL | Configurable | No hard-coded value; sourced from `platform.configuration_values` |
| Argon2id cost parameters | Configurable | No hard-coded values; sourced from `platform.configuration_values` |
| Step-up token TTL | Configurable | No hard-coded value |
| MFA failure lockout threshold | Configurable | No hard-coded value |

Any implementation that hard-codes a TBD value MUST be rejected in code review.

---

## Risks

| Risk | Mitigation |
|---|---|
| OTP provider not selected (TBD-001) | `OtpDeliveryContract` stub delivers OTPs to logs in dev/test; no production traffic until provider is wired |
| Biometric SDK not selected (TBD-004) | `BiometricAssertionContract` stub; Flutter abstraction layer; no SDK dependency in domain |
| FIDO2/WebAuthn library maturity | Use `web-auth/webauthn-framework` (maintained, WebAuthn L2 conformant); evaluate in P8 |
| Redis availability for revocation cache | Session revocation falls back to DB lookup; Redis failure does not bypass security |
| JWKS cache staleness during key rotation | Downstream services must implement `kid`-miss refresh; document in JWKS contract |
| E04 role seed not available at E01 registration | E01 references `CUSTOMER` role code; E04 must seed the role before production activation |
| Progressive lockout as denial-of-service vector | Lockout progression formula uses exponential backoff capped at a maximum; cap is configurable |
| Service token misuse (wrong audience) | `aud` claim validation mandatory at every consuming service; enforced in E04 authorization layer |
| Config update lost update (concurrent admins) | Optimistic concurrency enforced; `CONFIGURATION_CONFLICT` returned on stale version |
| Performance targets under real load | K6 tests use parametric concurrent-user volume; run with production-equivalent data volume |

---

## Dependencies

| Dependency | Required From |
|---|---|
| `identity` PostgreSQL schema (empty) | Platform Foundation ✅ |
| `platform.outbox_events` + `EventPublisherInterface` | Platform Foundation ✅ |
| `platform.audit_events` + `AuditServiceInterface` | Platform Foundation ✅ |
| `platform.configuration_values` + `ConfigurationServiceInterface` | Platform Foundation ✅ |
| `platform.idempotency_records` + `IdempotencyServiceInterface` | Platform Foundation ✅ |
| Standard API envelope + error taxonomy | Platform Foundation ✅ |
| `X-Correlation-ID` middleware | Platform Foundation ✅ |
| Redis (Laravel configured) | Platform Foundation ✅ |
| Laravel Horizon | Platform Foundation ✅ |
| Design system tokens and shared components | Platform Foundation ✅ |
| Canonical audit-action vocabulary | Platform Foundation ✅ — E01 registers its actions |
| Module boundary architecture | Platform Foundation ✅ |
| E04 role seed (`CUSTOMER` role code) | E04 (future) — E01 can proceed with stub |
| OTP provider (SMS/email gateway) | E17 / TBD provider — stub used in P3 |
| FX mechanism (for multi-currency step-up) | Platform FX service (TBD epic) — fail-safe assumed until available |
