# Quickstart Validation Guide — E01 Central Identity & Authentication

**Feature**: `001-central-identity-authentication`

This guide documents the runnable validation scenarios that prove E01 works end-to-end. Use it to verify a fresh implementation against the spec, after changes, or as a regression checklist.

**Prerequisites**:
- Platform Foundation fully deployed (migrations run, Redis running, Horizon running)
- `identity` DB connection configured
- `platform` DB connection configured
- `IDENTITY_JWT_PRIVATE_KEY` environment secret set (EC P-256 key pair)
- OTP provider configured (stub/mock acceptable in non-prod)
- Admin account seeded (for admin-only scenarios)
- `php artisan migrate` run (including E01 migrations 001–006)

---

## Scenario 1 — Customer Registration + First Login

**Covers**: FR-E01-001, FR-E01-002, FR-E01-003, FR-E01-006, FR-E01-007, AC-001, AC-002

**Steps**:

1. `POST /v1/auth/register` with a new mobile number.
   - Expected: `202 Accepted`, `challenge_id` returned.
   - Verify: `identity.otp_challenges` row inserted with `status='pending'`.
   - Verify: OTP delivery attempted (check stub provider log or mock).

2. `POST /v1/auth/otp/verify` with the correct OTP.
   - Expected: `200 OK`, `setup_token` returned, `next_step: SET_PIN`.
   - Verify: `identity.otp_challenges` row status → `verified`.
   - Verify: `identity.users` row exists with `status='pending_verification'`.

3. `POST /v1/auth/pin/set` with a valid 6-digit PIN.
   - Expected: `201 Created`, `access_token` + `refresh_token` returned.
   - Verify: `identity.users` status → `active`.
   - Verify: `identity.credentials` row with `credential_type='pin'`, `credential_hash` starts with `$argon2id`.
   - Verify: `identity.sessions` row with `status='active'`.
   - Verify: PIN value is NOT in any response, log, or audit record.

4. Decode the `access_token` (JWT).
   - Verify: `actor_type='USER'`, `roles=['CUSTOMER']`, `kid` present.
   - Verify: `exp - iat` equals the configured access token TTL.

5. `GET /v1/.well-known/jwks.json`.
   - Expected: `200 OK`, `keys` array with the matching `kid`.
   - Verify: `"d"` (private key) absent from all key objects.

**Pass criteria**: User is active, first session is active, access token is verifiable against JWKS, PIN is hashed.

---

## Scenario 2 — PIN Login + Token Refresh + Logout

**Covers**: FR-E01-008, FR-E01-009, FR-E01-016, FR-E01-017, AC-003, AC-007

**Steps**:

1. `POST /v1/auth/login` with a registered mobile + correct PIN.
   - Expected: `200 OK`, `access_token` + `refresh_token`.
   - Verify: `identity.sessions` row with `status='active'`.

2. Wait for access token to near expiry (or use a short TTL in test config), then `POST /v1/auth/token/refresh` with the refresh token.
   - Expected: `200 OK`, NEW `access_token` + NEW `refresh_token`.
   - Verify: old `refresh_token_hash` in `identity.sessions` is no longer active (session record updated with new hash).

3. Attempt `POST /v1/auth/token/refresh` again with the OLD refresh token.
   - Expected: `401 IDENTITY_015` — reuse detected, session family revoked.
   - Verify: all sessions for this user in the family have `status='revoked'`.
   - Verify: audit event `identity.session.revoked` emitted via outbox.

4. Log in again. `POST /v1/auth/logout`.
   - Expected: `200 OK`.
   - Verify: `identity.sessions` status → `logged_out`.
   - Verify: Redis key `identity:revoked:{session_id}` set.

**Pass criteria**: Token rotation works, reuse detection revokes the family, logout sets correct status and Redis entry.

---

## Scenario 3 — Failed Login → Progressive Lockout

**Covers**: FR-E01-010, FR-E01-011, FR-E01-012, AC-005, AC-006

**Steps**:

1. `POST /v1/auth/login` with correct mobile + wrong PIN, repeated N times (N = `auth.lockout.max_failed_attempts` from config).
   - Each attempt: Expected `401 IDENTITY_002` (no disclosure of what was wrong).
   - Verify: `identity.users.failed_attempt_count` increments.

2. On the Nth+1 attempt:
   - Expected: `423 IDENTITY_004` — account locked.
   - Verify: `identity.users.status='locked'`, `locked_at` set.
   - Verify: `identity.users.lockout_episode_count` incremented.
   - Verify: audit event `identity.account.locked` emitted.

3. Admin unlocks the account via admin session endpoint (Scenario 5 prerequisite) or wait for lockout duration.
   - Verify: `identity.users.status='active'`, `failed_attempt_count` reset.

4. Lock the account a second time. Verify: `lockout_episode_count` = 2.
   - If progressive lockout is enabled: verify the lockout duration is longer than the first episode.

**Pass criteria**: Lockout threshold enforced, progressive lockout increases duration on repeat, audit events emitted.

---

## Scenario 4 — OTP Expiry and Rate Limiting

**Covers**: FR-E01-004, FR-E01-005, AC-004

**Steps**:

1. `POST /v1/auth/register` — receive `challenge_id`.

2. Wait for OTP to expire (set `auth.otp.ttl` to 60 seconds in test config, or advance time in test).

3. `POST /v1/auth/otp/verify` with the expired OTP.
   - Expected: `401 IDENTITY_003`.
   - Verify: `identity.otp_challenges.status='expired'`.

4. `POST /v1/auth/otp/resend` 5 times in rapid succession (N = `auth.otp.max_sends_per_hour`).
   - First resend within cooldown: Expected `429 IDENTITY_005` (resend cooldown).
   - After cooldown: Resends succeed until hourly limit reached → `429 IDENTITY_005`.

**Pass criteria**: OTP expiry enforced, resend cooldown enforced, hourly send limit enforced.

---

## Scenario 5 — Admin Session Management

**Covers**: FR-E01-032, FR-E01-033, AC-016

**Steps**:

1. Log in as Admin user. Verify MFA required (TOTP or FIDO2 based on config).

2. `GET /v1/admin/sessions` — list all active user sessions.
   - Verify: response contains sessions; no raw IP addresses in response (only `ip_hash`).

3. `POST /v1/admin/sessions/{session_id}/revoke` for a specific customer session.
   - Expected: `200 OK`.
   - Verify: `identity.sessions.status='revoked'` for the target session.
   - Verify: Redis key `identity:revoked:{session_id}` set.
   - Verify: audit event `identity.session.revoked` emitted with admin actor ID.

**Pass criteria**: Admin can list and revoke sessions; revoked sessions cannot use their tokens; audit event records admin actor.

---

## Scenario 6 — Security Configuration Admin API

**Covers**: FR-E01-044, FR-E01-045, FR-E01-047, FR-E01-048, FR-E01-049, FR-E01-051, AC-021, AC-022, AC-024, AC-025

**Steps**:

1. Log in as Admin. `GET /v1/admin/platform/configuration/security`.
   - Expected: `200 OK`, all 25 keys with current values, defaults, ranges.

2. `PUT /v1/admin/platform/configuration/security/auth.otp.ttl` with `value: "600"`.
   - Expected: `200 OK`, `old_value: "300"`, `new_value: "600"`.
   - Verify: `platform.configuration_values` row updated.
   - Verify: Audit event emitted containing `old_value`, `new_value`, `changed_by`, `correlation_id`.

3. `PUT /v1/admin/platform/configuration/security/auth.otp.ttl` with `value: "30"` (below minimum 60).
   - Expected: `400 IDENTITY_001` — security minimum validation rejected.

4. `PUT /v1/admin/platform/configuration/security/auth.otp.ttl` again with `value: "900"` but with the OLD `version` from step 1.
   - Expected: `409 IDENTITY_016` — optimistic concurrency conflict.

5. Log in as a Customer user. Attempt `GET /v1/admin/platform/configuration/security`.
   - Expected: `403 IDENTITY_010` — insufficient permissions.

**Pass criteria**: Admin can read/update configuration; security minimums are enforced; optimistic concurrency prevents lost updates; non-admin users are blocked; every change is audited.

---

## Scenario 7 — Step-Up Authentication

**Covers**: FR-E01-023, FR-E01-024, FR-E01-025, AC-013

**Steps**:

1. Authenticated as a customer. `POST /v1/auth/step-up/initiate` with `amount: 15000, currency: "BWP"` (above configured threshold of 10000 BWP).
   - Expected: `200 OK`, `step_up_token` returned.

2. `POST /v1/auth/step-up/verify` with correct TOTP code.
   - Expected: `200 OK`, `step_up_access_token` scoped to `payment:high-value`.
   - Verify: step-up token `aud` is purpose-restricted.

3. Repeat with `amount: 5000, currency: "BWP"` (below threshold).
   - Step-up should NOT be required (unless risk engine mandates it).

4. Verify: step-up token cannot be used for a different scope than granted.

**Pass criteria**: Step-up triggers above threshold (or on risk engine signal), step-up token is purpose-scoped, below-threshold operations proceed without step-up.

---

## Scenario 8 — JWKS Key Rotation

**Covers**: FR-E01-031, AC-011; Decision 1 in research.md; Section 10.1 in spec.md

**Steps**:

1. Note the current `kid` from `GET /v1/.well-known/jwks.json`.

2. Rotate key: update `IDENTITY_JWT_PRIVATE_KEY` with a new EC P-256 key; add old public key as `IDENTITY_JWT_OLD_PUBLIC_KEY`; flush Redis JWKS cache (`identity:jwks`); deploy.

3. `GET /v1/.well-known/jwks.json`.
   - Expected: both old `kid` and new `kid` present in `keys` array.

4. Issue a new access token (via login). Verify new token's `kid` matches the new key.

5. Verify existing tokens (signed with the old `kid`) are still accepted by downstream verification.

6. After all old tokens expire: remove `IDENTITY_JWT_OLD_PUBLIC_KEY`. Flush cache. Verify JWKS returns only the new key.

**Pass criteria**: Overlapping JWKS during rotation allows both old and new tokens to be verified; no downtime during key rotation.

---

## Scenario 9 — Configuration Seed Idempotency

**Covers**: FR-E01-039, FR-E01-052, AC-021

**Steps**:

1. Run `php artisan migrate` on a fresh database.
   - Verify: all 25 configuration keys seeded in `platform.configuration_values` with default values.

2. Modify one key directly in the DB (simulate an admin update).

3. Run `php artisan migrate` again.
   - Verify: modified key retains its non-default value (seed did NOT overwrite it).
   - Verify: all other keys still present.

**Pass criteria**: Seed is idempotent; existing values are never overwritten; re-running migrations is safe.

---

## Performance Validation

**Covers**: Section 12 Performance Requirements in spec.md

Run K6 scripts from `tests/performance/identity/` with an appropriate `K6_VUSER_COUNT`.

> **Note**: The value `10` shown below is a minimal local smoke-test example only, NOT a production capacity target. Concurrent user volume for production load testing is TBD — replace with the approved target when determined.

```powershell
k6 run --env K6_VUSER_COUNT=$env:K6_VUSER_COUNT --env K6_DURATION=60s tests/performance/identity/login.js
k6 run --env K6_VUSER_COUNT=$env:K6_VUSER_COUNT --env K6_DURATION=60s tests/performance/identity/token-refresh.js
k6 run --env K6_VUSER_COUNT=$env:K6_VUSER_COUNT --env K6_DURATION=60s tests/performance/identity/otp-verify.js
```

For a minimal local smoke run before the production VU count is approved, use `K6_VUSER_COUNT=10`. Do not treat this as a load target.

**Expected thresholds** (see ENV vars injected in each K6 script):
- Login P95 ≤ 500ms
- Token refresh P95 ≤ 200ms
- OTP verify P95 ≤ 500ms

Concurrent user volume (`K6_VUSER_COUNT`) is TBD for production load targets; inject the approved value when determined.

---

## Observability Validation

**Covers**: Section 12 Observability Requirements in spec.md

After running scenarios 1–5:

1. Verify the following OTel metrics are emitted (meter name: `identity`):
   - `identity.auth.success` — counter incremented on each successful login
   - `identity.auth.failure` — counter incremented on each failed credential check
   - `identity.otp.sent` — counter incremented on each OTP delivery
   - `identity.account.locked` — counter incremented on each lockout
   - `identity.token.refresh` — counter incremented on each token rotation
   - `identity.step_up.triggered` — counter incremented on each step-up initiation
   - `identity.auth.latency` — histogram with P95 measurements

2. Verify no PII, credentials, OTP codes, or secrets appear in metric labels.

---

## Audit Validation

After running all scenarios:

- `identity.user.registered` — emitted after Scenario 1 step 1
- `identity.otp.verified` — emitted after Scenario 1 step 2
- `identity.user.pin-set` — emitted after Scenario 1 step 3
- `identity.session.created` — emitted after each login
- `identity.session.revoked` — emitted after Scenario 2 step 3 and Scenario 5 step 3
- `identity.account.locked` — emitted after Scenario 3 step 2
- `identity.config.updated` — emitted after Scenario 6 step 2
- `identity.step_up.initiated` — emitted after Scenario 7 step 1

Verify: all audit records in `platform.audit_events` contain `ip_hash` (HMAC-SHA256, not raw IP). No raw IP addresses in audit records.

---

## Reference

- API contracts: `contracts/authentication-api.md`, `contracts/jwks-api.md`, `contracts/service-token-api.md`, `contracts/configuration-api.md`
- Data model: `data-model.md`
- Module interfaces: `contracts/module-contracts.md`
- Full spec: `spec.md`
- Implementation plan: `plan.md`
