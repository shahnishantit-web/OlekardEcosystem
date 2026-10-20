# Data Model — E01 Central Identity & Authentication

**Feature**: `001-central-identity-authentication` | **Date**: 2026-08-21 | **Schema**: `identity`

E01 owns the `identity` PostgreSQL schema exclusively. All tables are created by E01 migrations on the `identity` named database connection (ADR-002, ADR-003). No cross-schema JOINs are permitted. Cross-module references use value copies (UUIDs), not foreign keys.

---

## Entity Relationship Overview

```
identity.users (1)
    ├── (N) identity.credentials     [credential_type UNIQUE per user]
    ├── (N) identity.sessions        [one per authenticated session]
    ├── (N) identity.devices         [device_fingerprint UNIQUE per user]
    └── (N) identity.otp_challenges  [user_id NULL for pre-registration]
```

No foreign keys reference tables outside the `identity` schema.

---

## Table: `identity.users`

Core identity record. One record per registered account.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK, NOT NULL, DEFAULT `gen_random_uuid()` | UUID v7 |
| `mobile_number` | `varchar(20)` | UNIQUE (partial: WHERE NOT NULL), NULL | Normalised E.164 format; NULL if email-only registration |
| `email` | `varchar(255)` | UNIQUE (partial: WHERE NOT NULL), NULL | Lowercase normalised; NULL if mobile-only registration |
| `status` | `varchar(20)` | NOT NULL | `pending_verification` \| `active` \| `locked` \| `suspended` \| `deactivated` |
| `default_role_id` | `uuid` | NULL | Value-copy of E04 seeded role UUID; no FK constraint |
| `actor_type` | `varchar(20)` | NOT NULL | `CUSTOMER` \| `AGENT` \| `ADMIN` \| `ENTERPRISE` |
| `lock_reason` | `varchar(100)` | NULL | Populated on lockout |
| `locked_at` | `timestamptz` | NULL | When account was locked |
| `lockout_episode_count` | `integer` | NOT NULL DEFAULT `0` | Number of lockout episodes for progressive lockout calculation |
| `failed_attempt_count` | `integer` | NOT NULL DEFAULT `0` | Consecutive failed auth attempts since last success |
| `last_login_at` | `timestamptz` | NULL | Timestamp of last successful authentication |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |

**Indexes**:
- `UNIQUE INDEX ON identity.users(mobile_number) WHERE mobile_number IS NOT NULL`
- `UNIQUE INDEX ON identity.users(email) WHERE email IS NOT NULL`
- `INDEX ON identity.users(status)`

**State Transitions**:
```
pending_verification
    → active (on OTP verification)
active
    → locked (on failed attempts threshold)
    → suspended (admin action)
    → deactivated (admin action)
locked
    → active (on recovery flow / admin unlock)
suspended
    → active (admin action)
```

---

## Table: `identity.credentials`

Credential records per user per type. Multiple credential types per user allowed.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK, NOT NULL | UUID v7 |
| `user_id` | `uuid` | NOT NULL, FK → `identity.users.id` | References same schema |
| `credential_type` | `varchar(20)` | NOT NULL | `pin` \| `password` \| `biometric_binding` \| `mfa_totp` \| `mfa_fido2` |
| `credential_hash` | `varchar(255)` | NULL | Argon2id hash for PIN/password; NULL for biometric/FIDO2 |
| `biometric_public_key` | `text` | NULL | Device public key for biometric assertion; populated for `biometric_binding` |
| `fido2_credential_id` | `varchar(500)` | NULL | FIDO2/WebAuthn credential ID (base64url); populated for `mfa_fido2` |
| `fido2_public_key` | `text` | NULL | FIDO2/WebAuthn CBOR-encoded public key; populated for `mfa_fido2` |
| `fido2_sign_count` | `bigint` | NOT NULL DEFAULT `0` | FIDO2 signature counter for replay detection |
| `status` | `varchar(20)` | NOT NULL | `active` \| `blocked` \| `revoked` |
| `failed_attempt_count` | `integer` | NOT NULL DEFAULT `0` | For PIN/MFA credential lockout |
| `expires_at` | `timestamptz` | NULL | For time-limited credentials |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |

**Constraints**:
- `UNIQUE (user_id, credential_type)` — one active credential per type per user
- `FK: user_id → identity.users(id) ON DELETE CASCADE`

**Indexes**:
- `UNIQUE INDEX ON identity.credentials(user_id, credential_type)`
- `INDEX ON identity.credentials(user_id)`
- `INDEX ON identity.credentials(fido2_credential_id) WHERE fido2_credential_id IS NOT NULL`

**Notes**:
- `credential_hash` stores the full Argon2id hash string (includes algorithm, cost, salt prefix) enabling future algorithm migration without schema changes
- `fido2_credential_id` allows efficient lookup during FIDO2 assertion verification
- `fido2_sign_count`: MUST be incremented on each successful FIDO2 assertion; assertion with sign count ≤ stored count MUST be rejected (replay attack)

---

## Table: `identity.sessions`

One record per active authenticated session.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK, NOT NULL | UUID v7; also the `session_id` claim in JWT |
| `user_id` | `uuid` | NOT NULL, FK → `identity.users.id` | |
| `device_id` | `uuid` | NULL, FK → `identity.devices.id` | NULL for non-device flows (e.g., web) |
| `refresh_token_hash` | `varchar(64)` | NOT NULL | SHA-256 hex of the issued refresh token |
| `refresh_token_family_id` | `uuid` | NOT NULL | Groups all rotated tokens in the same logical session family for reuse detection |
| `status` | `varchar(20)` | NOT NULL | `active` \| `mfa_pending` \| `step_up_pending` \| `expired` \| `revoked` \| `logged_out` |
| `actor_type` | `varchar(20)` | NOT NULL | `USER` \| `SERVICE` \| `SYSTEM` — mirrors user's actor_type |
| `step_up_verified_at` | `timestamptz` | NULL | When step-up MFA was last completed for this session |
| `step_up_scope` | `varchar(200)` | NULL | Operation scope for which step-up was granted |
| `last_activity_at` | `timestamptz` | NOT NULL | Updated on each authenticated request |
| `expires_at` | `timestamptz` | NOT NULL | Absolute session expiry (from config) |
| `ip_hash` | `varchar(64)` | NULL | `HMAC-SHA256(platform_secret, ip_address)` — never raw IP |
| `user_agent` | `varchar(500)` | NULL | Raw user-agent string |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |

**Indexes**:
- `UNIQUE INDEX ON identity.sessions(refresh_token_hash)`
- `INDEX ON identity.sessions(user_id)`
- `INDEX ON identity.sessions(user_id, status)`
- `INDEX ON identity.sessions(status, updated_at)` — cleanup job
- `INDEX ON identity.sessions(refresh_token_family_id)` — reuse detection: find all sessions in family
- `FK: user_id → identity.users(id)`
- `FK: device_id → identity.devices(id) ON DELETE SET NULL`

**State Transitions**:
```
active
    → mfa_pending (MFA required after primary credential)
    → step_up_pending (step-up required for operation)
    → expired (TTL elapsed / cleanup job)
    → revoked (admin revocation / reuse detection / all-session logout)
    → logged_out (user logout)
mfa_pending
    → active (MFA verified)
    → revoked (excessive MFA failures)
step_up_pending
    → active (step-up completed)
```

**Reuse Detection**: When a refresh token is presented, look up `refresh_token_hash`. If found but status is not `active` (already rotated), find all sessions sharing `refresh_token_family_id` and revoke all of them.

---

## Table: `identity.devices`

Known/trusted devices associated with a user.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK, NOT NULL | UUID v7 |
| `user_id` | `uuid` | NOT NULL, FK → `identity.users.id` | |
| `device_fingerprint` | `varchar(64)` | NOT NULL | SHA-256 hash of the client-provided fingerprint |
| `device_name` | `varchar(100)` | NULL | User-visible label (e.g., "iPhone 15 Pro") |
| `platform` | `varchar(20)` | NOT NULL | `ios` \| `android` \| `web` |
| `status` | `varchar(20)` | NOT NULL | `pending_verification` \| `trusted` \| `revoked` |
| `trusted_at` | `timestamptz` | NULL | When device was promoted to trusted |
| `last_seen_at` | `timestamptz` | NULL | Updated on each authenticated request from this device |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |

**Constraints**:
- `UNIQUE (user_id, device_fingerprint)`
- `FK: user_id → identity.users.id`

**Indexes**:
- `UNIQUE INDEX ON identity.devices(user_id, device_fingerprint)`
- `INDEX ON identity.devices(user_id)`

**Device Detection Logic**: On each login, compute `hash('sha256', $clientFingerprint)` and query `WHERE user_id = ? AND device_fingerprint = ? AND status = 'trusted'`. If no match → new device detected.

---

## Table: `identity.otp_challenges`

Short-lived OTP/verification challenges for all challenge types.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK, NOT NULL | UUID v7 |
| `user_id` | `uuid` | NULL, FK → `identity.users.id` | NULL for pre-registration (user does not exist yet) |
| `challenge_type` | `varchar(30)` | NOT NULL | `registration` \| `login` \| `device_verification` \| `pin_reset` \| `pin_unblock` \| `mfa_enrollment` \| `step_up` |
| `identifier` | `varchar(255)` | NOT NULL | Mobile or email address (raw; masked in logs and events) |
| `otp_hash` | `varchar(64)` | NOT NULL | SHA-256 hex of the OTP; never stored in plaintext |
| `status` | `varchar(20)` | NOT NULL | `pending` \| `verified` \| `expired` \| `invalidated` |
| `attempt_count` | `integer` | NOT NULL DEFAULT `0` | Failed verification attempts |
| `resend_count` | `integer` | NOT NULL DEFAULT `0` | Number of OTP resend requests |
| `last_resent_at` | `timestamptz` | NULL | Timestamp of last resend for cooldown enforcement |
| `expires_at` | `timestamptz` | NOT NULL | OTP challenge expiry time |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |

**Constraints**:
- No FK outside `identity` schema
- `CHECK (user_id IS NULL AND challenge_type = 'registration') OR (user_id IS NOT NULL)`

**Indexes**:
- `INDEX ON identity.otp_challenges(user_id) WHERE user_id IS NOT NULL`
- `INDEX ON identity.otp_challenges(identifier, challenge_type, status)`
- `INDEX ON identity.otp_challenges(status, expires_at)` — cleanup job

**OTP Active Challenge Rule**: Only one `pending` challenge of each type per user/identifier may be active. Creating a new challenge for the same type/identifier MUST invalidate any existing `pending` challenge.

---

## Platform Configuration (seeded by migration 006)

All identity configuration keys are seeded into `platform.configuration_values` (owned by Platform Foundation). E01 reads all values through `ConfigurationServiceInterface`.

Full registry: see `specs/001-central-identity-authentication/spec.md` Section 8.1.

**Key categories**:
- `auth.step_up.*` — step-up threshold and currency
- `auth.session.*` — inactivity TTLs per actor type
- `auth.refresh_token.*` — refresh token TTL and rotation settings
- `auth.otp.*` — OTP TTL, attempt limits, resend cooldown, sends per hour, provider
- `auth.pin.*` — PIN length and complexity rules
- `auth.lockout.*` — lockout threshold, initial duration, progressive setting
- `auth.mfa.admin.*` — Admin MFA method enablement
- `auth.biometric.*` — biometric enablement
- `security.ip_audit.*` — IP hashing algorithm

**Security minimums** (enforced by `ConfigurationSecurityService`; cannot be configured below these):

| Key | Minimum |
|---|---|
| `auth.otp.ttl` | 60 |
| `auth.otp.max_attempts` | 1 |
| `auth.otp.max_sends_per_hour` | 1 |
| `auth.pin.length` | 6 |
| `auth.lockout.max_failed_attempts` | 1 |
| `auth.lockout.initial_duration` | 60 |
| `auth.step_up_threshold` | 0 |

---

## Redis Data Structures (E01 usage)

| Key Pattern | Type | TTL | Purpose |
|---|---|---|---|
| `identity:revoked:{session_id}` | String | Align with session TTL | Session revocation fast-lookup |
| `identity:refresh_revoked:{token_hash}` | String | Align with refresh token TTL | Refresh token revocation fast-lookup |
| `identity:otp_rate:{identifier}` | Counter | 3600s (1 hour) | OTP send rate limiting |
| `identity:login_rate:{ip_hash}` | Counter | Configurable | Login rate limiting per IP |
| `identity:jwks` | String (JSON) | Configurable (default 300s) | JWKS public key cache |
| `idempotency:{actor_id}:{key}` | String | Idempotency TTL | Shared with Platform Foundation; keyed here for reference |

---

## Retention Policy

| Record Type | Purge Criteria | Purge Job | Protection |
|---|---|---|---|
| `identity.sessions` | `status IN ('expired','revoked','logged_out') AND updated_at < NOW() - INTERVAL '90 days'` | `SessionCleanupJob` (daily) | Active records never purged |
| `identity.otp_challenges` | `status IN ('expired','invalidated','verified') AND updated_at < NOW() - INTERVAL '24 hours'` | `OtpChallengeCleanupJob` (hourly) | Pending records never purged |

Retention interval values sourced from `platform.configuration_values` (exact key names defined in planning phase).

---

## Cross-Module Data Boundaries

| E01 Data | How Other Modules Access It |
|---|---|
| Authenticated actor identity | Via `IdentityModuleContract::resolveActorFromToken()` |
| Permission check | Via `IdentityModuleContract::hasPermission()` (delegates to E04) |
| User UUID | Embedded in JWT access token `sub` claim |
| Session ID | Embedded in JWT access token `session_id` claim |
| Actor type | Embedded in JWT access token `actor_type` claim |
| Roles | Embedded in JWT access token `roles` array |
| Step-up claims | Embedded in JWT step-up token |

No other module may query `identity.*` tables directly. Cross-module access goes through `IdentityModuleContract` (PHP interface, per `module-contracts.md`).
