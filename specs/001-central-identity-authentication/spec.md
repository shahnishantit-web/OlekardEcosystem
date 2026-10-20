# E01 — Central Identity & Authentication

**Feature**: `001-central-identity-authentication` | **Epic**: E01 | **Stories**: 38 | **Status**: Specified

---

## 1. Problem Statement

The Wallet Ecosystem requires a single, centralized authentication and identity service shared by the Customer mobile application, the Agent mobile application, the Admin/Enterprise web application, backend API services, and service-to-service identities. Without it, each application would need to implement and maintain its own credential store, session lifecycle, OTP delivery, PIN management, and MFA logic — creating security inconsistencies, audit gaps, and extraction friction.

E01 establishes the authenticated identity context that every downstream business domain (KYC, Wallet, Ledger, Risk, Notifications, Audit, Agent) depends on. It does not implement any of those downstream domains.

---

## Clarifications

### Session 2026-08-21

- Q: Are the stale GAP-027 TBD references in BR-011, Section 6.10, FR-E01-037, and Section 23 Scenario 3 consistent with the resolved status documented in Sections 3, 4, and 8.1? → A: No — all four references were stale. Updated to reflect GAP-027 resolved: `auth.step_up_threshold = 10000 BWP`, runtime-configurable via `ConfigurationService`, MUST NOT be hard-coded. The `constitution.md` Principle 5 was updated in the same pass.

### Session 2026-08-20

- Q: Which JWT signing algorithm should the access token (and step-up token) use? → A: ES256 (ECDSA P-256, asymmetric). Access tokens and step-up tokens use ES256. E01 is the sole signing authority. Downstream services verify JWTs locally using cached JWKS public keys and MUST NOT call E01 for routine signature verification. JWT header MUST include `kid` for key rotation. E01 MUST expose a JWKS endpoint. Key rotation MUST support overlapping old/new public keys until old tokens expire. Step-up tokens MUST be purpose/audience-scoped and MUST NOT be accepted as general access tokens.
- Q: What are the acceptable P95 response-time targets for the authentication API's hot paths? → A: Login P95 ≤ 500 ms; token refresh P95 ≤ 200 ms; OTP verification P95 ≤ 500 ms — server-side targets for a mobile-first payment auth service on typical African mobile network conditions (LTE/3G). OTP delivery latency excluded.
- Q: Which application-level metrics should E01 emit for operations dashboards and alerting? → A: Standard set (Option B) — auth success/failure counters, OTP send rate, lockout rate, token refresh rate, step-up trigger rate, and P95 latency histograms per hot-path endpoint. Low-level cache metrics deferred to the platform observability layer.
- Q: What is the retention policy for expired/revoked session records and expired OTP challenge records before purge? → A: Sessions: 90 days after expiry/revocation; OTP challenges: 24 hours after expiry or invalidation. Retention values sourced from `platform.configuration_values`; not hard-coded in cleanup jobs.
- Q: How should internal backend services authenticate when calling E01-protected APIs or validating tokens? → A: JWT service-identity tokens issued by E01 (Option A). E01 is the central issuer. Service JWTs use ES256 with `kid`; downstream validate via cached JWKS. Service tokens are audience-restricted, time-bounded (lifetime configurable/TBD — not indefinitely long-lived), uniquely identified via `jti`, and auditable. Three actor types: USER, SERVICE, SYSTEM. A service acting on behalf of a user MUST preserve both service actor identity and subject/user identity. Authorization is capability/scope-based — a valid service JWT does not grant unrestricted access. A token issued for one audience MUST NOT be accepted by another. Service-token lifetime, credential storage mechanism, rotation interval, scope catalogue, and production secret-management provider are TBD.

---

## 2. Scope

E01 covers exactly the 38 stories assigned to Epic E01 in the approved 538-story backlog:

- **Registration**: mobile-number and email-based account creation, OTP verification, OTP resend, OTP retry/cooldown enforcement, duplicate prevention, default role assignment
- **Authentication/Login**: OTP login, PIN login, password login, biometric login, invalid-credential detection, account lockout, suspicious-login notification, new-device detection, new-device verification
- **Session & Token Lifecycle**: access-token issuance, refresh-token issuance, token refresh, refresh-token rotation, current-session logout, all-sessions logout, session auto-expiry, compromised-session revocation, active-session viewing, individual-device revocation
- **PIN & Credential Management**: PIN creation, PIN change, PIN reset, PIN complexity enforcement, PIN block on repeated failure, PIN unblock through recovery
- **MFA & Step-Up**: MFA enable, MFA disable, MFA challenge verification, step-up for high-risk transactions, step-up for privileged administration

Out of scope for E01: all wallet, ledger, KYC, transaction, risk, agent, notification, reconciliation, and settlement logic. Full RBAC management belongs to E04. User profile management belongs to E02. Device/session management beyond authentication belongs to E03.

---

## 3. Goals

1. Provide a single authentication and identity capability shared across all applications and actors.
2. Establish authenticated actor identity (who is calling) for every protected API request.
3. Deliver secure credential management: OTP, PIN, password, and biometric binding.
4. Implement a stateless token lifecycle (access + refresh) with rotation and revocation.
5. Enforce configurable security policies (lockout, OTP retry, PIN complexity, session TTL) through the Platform Foundation configuration framework.
6. Support step-up authentication for high-risk and privileged operations; default threshold = 10000 BWP (runtime-configurable); dual-trigger: amount threshold OR risk-engine signal.
7. Produce auditable, structured authentication and security events consumable by the Platform Foundation audit infrastructure.
8. Remain extraction-ready: the Identity bounded context must be isolatable into an independent service in the enterprise target state.

---

## 4. Non-Goals

E01 does NOT:
- Implement wallet creation, wallet balances, ledger posting, or financial transactions.
- Implement KYC verification workflows or biographic/document checks.
- Implement full RBAC permission management (E04 owns this).
- Implement user profile management beyond the identity record required for authentication (E02).
- Implement notification delivery (E17 owns delivery; E01 emits events).
- Implement device management beyond what authentication requires (E03 owns extended device management).
- Implement fraud scoring or AML risk rules (E18).
- Implement geolocation-based suspicious-login or impossible-geolocation detection (E18). E01 emits the `identity.session.created` and `identity.user.new-device-detected` events that E18 may consume for geolocation analysis; E01 does not perform geolocation analysis itself.
- Provision password credentials for Admin or Enterprise actors (E04 admin onboarding owns password credential creation and initial provisioning). E01 implements password authentication and login only.
- Hard-code the step-up threshold — it must remain runtime-configurable even though a default value is now seeded (GAP-027 resolved).
- Create a second Platform Foundation infrastructure (no new outbox, no new audit table, no new idempotency store).
- Hard-code any configurable security policy value (lockout threshold, OTP retry limit, session TTL, rate limits, retention periods).

---

## 5. Actors

| Actor | Description | Auth Methods |
|---|---|---|
| **Customer** | End-user of the Customer mobile application | Mobile OTP, PIN, password, biometric |
| **Agent** | Registered field agent using the Agent mobile application | Mobile OTP, PIN |
| **Admin** | Administrator using the Admin/Enterprise web application | Password + mandatory MFA |
| **Enterprise** | Enterprise/corporate user using the Enterprise web application | Password + MFA |
| **System Service** | Internal backend service (e.g., E09 Transfer, E18 Risk, E23 Audit) calling E01-protected APIs | JWT service-identity token (ES256, audience-restricted, time-bounded, issued by E01) |
| **Risk Engine** | Internal risk evaluation service that may trigger step-up | Internal; uses service-identity token if calling E01 APIs directly |

**Actor types**: E01 tokens carry one of three `actor_type` values: `USER` (human actors — Customer, Agent, Admin, Enterprise), `SERVICE` (internal backend services), `SYSTEM` (platform background processes). Authorization decisions in downstream services (E04) MUST account for all three types. A service JWT MUST NOT receive the same permissions as a USER JWT merely because it is signed by E01.

---

## 6. User Journeys

### 6.1 Customer Registration Journey
New Customer downloads the app → enters mobile number → receives OTP → verifies OTP → account created with default customer role → proceeds to KYC (E05).

### 6.2 Customer Login Journey (OTP)
Customer opens app → enters mobile number → requests OTP → receives OTP → verifies OTP → access token + refresh token issued → enters the authenticated application.

### 6.3 Customer Login Journey (PIN)
Customer opens app → enters mobile number or selects stored identity → enters PIN → PIN verified → access token issued.

### 6.4 Customer Login Journey (Biometric)
Customer opens app → triggers device biometric prompt → device authenticates locally → app presents signed biometric assertion to API → server validates assertion → access token issued.

### 6.5 New Device Login Journey
Customer logs in on a new device → system detects new device fingerprint → issues additional verification challenge → user completes challenge → device recorded → session granted.

### 6.6 Session Refresh Journey
Access token nears expiry → app presents refresh token → new access token + rotated refresh token issued → old refresh token invalidated.

### 6.7 Logout Journey
User requests logout → current session tokens invalidated → user returned to unauthenticated state.

### 6.8 PIN Management Journey
Authenticated user sets initial PIN → PIN stored (hashed) → user may change PIN (requires current PIN) → PIN reset via verified OTP recovery if forgotten.

### 6.9 MFA Setup Journey
Authenticated user enables MFA → MFA method configured (OTP-based) → subsequent logins require MFA challenge completion.

### 6.10 Step-Up Journey
User attempts a high-risk transaction or privileged admin action → system determines step-up is required (threshold from `auth.step_up_threshold`, default 10000 BWP, runtime-configurable; GAP-027 resolved) → step-up MFA challenge issued → user completes challenge → elevated context token issued → protected operation proceeds.

### 6.11 Account Lockout Journey
User fails credential verification beyond the configurable threshold → account locked → security event emitted → notification event fired → admin can unlock; user can recover through verified flow after cooldown.

---

## 7. Business Rules

| ID | Rule |
|---|---|
| BR-001 | A mobile number or email address MUST be unique per registered account. Duplicate registration MUST be rejected. |
| BR-002 | OTP codes MUST NOT be logged, stored in plaintext, or returned in API error responses. |
| BR-003 | OTP retry limit and resend cooldown are configurable via the Platform Foundation configuration framework. Values are NOT hard-coded. |
| BR-004 | PIN MUST be stored as a salted cryptographic hash. The raw PIN value MUST NOT be logged or persisted in plaintext. |
| BR-005 | PIN complexity requirements are configurable via the Platform Foundation configuration framework. Values are NOT hard-coded. |
| BR-006 | Account lockout threshold (failed attempts) is configurable. The lockout duration is configurable. Values are NOT hard-coded. |
| BR-007 | Access tokens are short-lived (TTL configurable). Refresh tokens have a longer configurable TTL. |
| BR-008 | Refresh token rotation: presenting a refresh token MUST invalidate the presented token and issue a new one. Reuse of an already-rotated refresh token MUST trigger session revocation for security. |
| BR-009 | Biometric authentication: actual biometric data NEVER leaves the user's device. The server validates a signed assertion provided by the device SDK. |
| BR-010 | Registering a new device requires additional verification before the device is trusted. The verification method uses an OTP or equivalent out-of-band challenge. |
| BR-011 | Step-up authentication for high-risk transactions and privileged administration is required. The threshold for "high-risk" is governed by `auth.step_up_threshold` (GAP-027 resolved; approved default seed 10000 BWP, runtime-configurable via Platform Configuration). The threshold MUST NOT be hard-coded in domain logic — it MUST be read from `ConfigurationService` at runtime. |
| BR-012 | A default customer role MUST be assigned to new Customer registrations. Role definitions belong to E04; E01 assigns the pre-seeded default role identifier. |
| BR-013 | Sensitive credential operations (PIN change, MFA enable/disable, all-sessions logout) MUST be audited. |
| BR-014 | Invalid credential responses MUST NOT reveal whether the identifier (mobile/email) exists in the system. |
| BR-015 | Session revocation MUST take effect immediately; revoked tokens MUST be rejected on the next validation attempt. |
| BR-016 | All configurable policy values (lockout, OTP retry, session TTL, PIN complexity, rate limits) are consumed from `platform.configuration_values` — E01 MUST NOT assert business policy values in code. |
| BR-017 | The identity security configuration seed MUST be idempotent: it only inserts keys that do not already exist and MUST NOT overwrite existing configured values. |
| BR-018 | E01 domain and application services MUST retrieve configuration through the `ConfigurationService` abstraction; direct SQL access to `platform.configuration_values` from domain code is prohibited. |
| BR-019 | Configuration updates for security-sensitive keys MUST require `platform.configuration.update` permission. Configuration reads MUST require `platform.configuration.view` permission. |
| BR-020 | Configuration values MUST be validated against typed boundaries and security minimums before persistence. An administrator MUST NOT be able to weaken security below the platform-defined minimum (e.g., PIN length below 6, OTP TTL of 0). |
| BR-021 | Every configuration change MUST generate an immutable audit record in `platform.audit_events` containing: configuration key, old value, new value, actor, timestamp, correlation ID, and HMAC-protected IP representation. Secret values MUST NOT appear in audit records. |
| BR-022 | Configuration updates MUST implement optimistic concurrency: an update based on a stale version MUST be rejected rather than silently overwriting a concurrent change. |
| BR-023 | Step-up authentication MUST be triggered by either (a) transaction amount exceeding the configurable threshold OR (b) an explicit signal from the risk engine. Neither trigger mechanism may be removed. |
| BR-024 | When a transaction's currency differs from `auth.step_up_threshold_currency`, the system MUST use the platform FX mechanism for comparison. If FX conversion is unavailable, step-up MUST be required (fail-safe, never bypassed). |
| BR-025 | Account lockout MUST implement progressive lockout: each lockout episode after the first uses a longer duration. The progression formula is an implementation detail; the initial duration is configurable. |
| BR-026 | Secrets — JWT signing keys, HMAC keys, OTP provider credentials — MUST be stored in the platform secret-management mechanism (environment secrets / secret manager). Secrets MUST NOT be stored in `platform.configuration_values`. |
| BR-027 | IP address representation in `platform.audit_events` for all E01 audit records MUST use `HMAC-SHA256(platform_secret, ip_address)`. The HMAC secret MUST NOT be stored in `platform.configuration_values` and MUST NOT be exposed through any Admin API. |
| BR-028 | Admin MFA MUST default to TOTP (enabled) and FIDO2/WebAuthn (enabled); SMS MFA MUST be disabled by default for Admin actors. |

---

## 8. Functional Requirements

| ID | Story | Requirement |
|---|---|---|
| FR-E01-001 | US-AUTH-001 | The system MUST support new user registration using a mobile phone number as the primary identifier. |
| FR-E01-002 | US-AUTH-002 | The system MUST support new user registration using an email address as the primary identifier. |
| FR-E01-003 | US-AUTH-003 | The system MUST send an OTP to the registration identifier and verify it before activating the account. |
| FR-E01-004 | US-AUTH-004 | The system MUST allow the user to request a registration OTP resend within configured cooldown and retry constraints. |
| FR-E01-005 | US-AUTH-005 | The system MUST enforce a configurable maximum OTP verification attempt limit; exceeding it MUST invalidate the current OTP and require a new one. |
| FR-E01-006 | US-AUTH-006 | The system MUST enforce a configurable cooldown period between OTP resend requests. |
| FR-E01-007 | US-AUTH-007 | The system MUST detect and reject duplicate account registration for an identifier already associated with an existing account. |
| FR-E01-008 | US-AUTH-008 | The system MUST assign the pre-seeded default customer role to a newly registered customer account. |
| FR-E01-009 | US-AUTH-009 | The system MUST support authentication via mobile number + OTP for Customer and Agent actors. |
| FR-E01-010 | US-AUTH-010 | The system MUST support authentication via PIN for actors with a configured PIN credential. |
| FR-E01-011 | US-AUTH-011 | The system MUST support authentication via password for actors with a configured password credential. |
| FR-E01-012 | US-AUTH-012 | The system MUST support authentication via device biometric assertion for mobile actors with biometric binding. |
| FR-E01-013 | US-AUTH-013 | The system MUST detect invalid credentials and reject authentication without disclosing whether the identifier exists. |
| FR-E01-014 | US-AUTH-014 | The system MUST lock an account after a configurable number of consecutive failed authentication attempts. |
| FR-E01-015 | US-AUTH-015 | The system MUST emit a suspicious-login event when unusual authentication patterns are detected (e.g., impossible geolocation, new device). |
| FR-E01-016 | US-AUTH-016 | The system MUST detect authentication from a device not previously associated with the user's account. |
| FR-E01-017 | US-AUTH-017 | The system MUST require additional verification before granting a session from a newly detected device. |
| FR-E01-018 | US-AUTH-018 | The system MUST issue a short-lived signed access token upon successful authentication. |
| FR-E01-019 | US-AUTH-019 | The system MUST issue a refresh token alongside the access token upon successful authentication. |
| FR-E01-020 | US-AUTH-020 | The system MUST accept a valid refresh token and return a new access token when the current access token has expired. |
| FR-E01-021 | US-AUTH-021 | The system MUST rotate refresh tokens on use: the presented token is invalidated and a new one issued. |
| FR-E01-022 | US-AUTH-022 | The system MUST allow an authenticated user to terminate their current session; all tokens for that session MUST be immediately invalidated. |
| FR-E01-023 | US-AUTH-023 | The system MUST allow an authenticated user to terminate all of their active sessions; all tokens for all sessions MUST be immediately invalidated. |
| FR-E01-024 | US-AUTH-024 | The system MUST automatically expire sessions that remain inactive beyond the configured inactivity TTL. |
| FR-E01-025 | US-AUTH-025 | The system MUST support immediate administrative revocation of a session identified as compromised. |
| FR-E01-026 | US-AUTH-026 | An authenticated user MUST be able to retrieve a list of their currently active sessions including device metadata. |
| FR-E01-027 | US-AUTH-027 | An authenticated user MUST be able to revoke an individual device session by session ID. |
| FR-E01-028 | US-AUTH-028 | The system MUST allow an authenticated user to create a transaction PIN. |
| FR-E01-029 | US-AUTH-029 | The system MUST allow an authenticated user to change their transaction PIN, requiring the current PIN for verification. |
| FR-E01-030 | US-AUTH-030 | The system MUST support resetting a forgotten PIN through a verified recovery flow (e.g., OTP re-verification). |
| FR-E01-031 | US-AUTH-031 | The system MUST enforce PIN complexity rules sourced from platform configuration (e.g., length, no sequential/repeated digits). |
| FR-E01-032 | US-AUTH-032 | The system MUST block PIN usage after a configurable number of consecutive failed PIN verifications. |
| FR-E01-033 | US-AUTH-033 | The system MUST allow unblocking of a blocked PIN through a verified recovery flow. |
| FR-E01-034 | US-AUTH-034 | An authenticated user MUST be able to enable MFA on their account. |
| FR-E01-035 | US-AUTH-035 | An authenticated user MUST be able to disable MFA on their account, subject to any policy restrictions. |
| FR-E01-036 | US-AUTH-036 | The system MUST issue MFA challenges and verify responses; failed MFA verification MUST be recorded. |
| FR-E01-037 | US-AUTH-037 | The system MUST require step-up MFA for high-risk transactions, with the risk threshold sourced from `auth.step_up_threshold` in platform configuration (GAP-027 resolved; default seed 10000 BWP, runtime-configurable; MUST NOT be hard-coded). |
| FR-E01-038 | US-AUTH-038 | The system MUST require step-up MFA for privileged administration actions. |
| FR-E01-039 | Config | The system MUST seed all identity security configuration defaults in `platform.configuration_values` using an idempotent migration that does NOT overwrite existing values. |
| FR-E01-040 | Config | E01 domain and application services MUST retrieve all security policy values through the `ConfigurationService` abstraction; no hard-coded policy values are permitted in domain logic. |
| FR-E01-041 | Config | The Admin Portal MUST provide a dedicated Security Configuration section allowing authorized admins to view and update all identity security configuration values via the UI. |
| FR-E01-042 | Config | The system MUST expose `GET /v1/admin/platform/configuration/security` and `PUT /v1/admin/platform/configuration/security/{key}` API endpoints restricted to Admin actors with `platform.configuration.view` / `platform.configuration.update` permissions respectively. |
| FR-E01-043 | Config | Configuration update MUST validate values against typed ranges and security minimums before persisting. Values below the platform security minimum MUST be rejected with a clear error. |
| FR-E01-044 | Config | Every configuration change MUST produce an immutable audit record in `platform.audit_events` with: key, old value, new value, actor, timestamp, correlation ID, and HMAC-SHA256 IP representation. |
| FR-E01-045 | Config | Configuration updates MUST use optimistic concurrency: a stale-version update MUST be rejected with `CONFIGURATION_CONFLICT` rather than silently overwriting a concurrent change. |
| FR-E01-046 | Config | Step-up authentication MUST be triggerable both by amount exceeding `auth.step_up_threshold` AND by an explicit risk-engine signal; both trigger paths MUST be independently functional. |
| FR-E01-047 | Config | When the transaction currency differs from `auth.step_up_threshold_currency`, the system MUST use the platform FX mechanism for comparison. If FX conversion is unavailable, step-up MUST be required. |
| FR-E01-048 | Config | Account lockout MUST implement progressive lockout: each lockout episode after the first uses a longer duration based on `auth.lockout.initial_duration` and the episode count. |
| FR-E01-049 | Config | Admin MFA MUST support TOTP and FIDO2/WebAuthn credential types. SMS MFA for Admin MUST be disabled by default (`auth.mfa.admin.sms_enabled = false`). |
| FR-E01-050 | Config | IP address representation in all E01 `platform.audit_events` records MUST use `HMAC-SHA256(platform_secret, ip_address)`. The HMAC secret MUST be stored in the platform secret-management mechanism, never in `platform.configuration_values`. |
| FR-E01-051 | Config | Configuration API responses MUST never include secrets. Secrets MUST NOT appear in audit logs, API responses, Admin Portal UI, or event payloads. |
| FR-E01-052 | Config | The OTP delivery abstraction (`OtpDeliveryContract`) MUST support both SMS and email delivery channels. Provider selection MUST be represented as configuration (`auth.otp.provider`); provider credentials MUST be secrets. |

---

## 8.1 Configuration Registry

All 25 identity security configuration keys are seeded into `platform.configuration_values` via an idempotent migration. Defaults are initial values only; all are configurable at runtime through the Admin Portal by authorized administrators.

**Secret values are NOT stored in `platform.configuration_values`.** Secrets (JWT signing keys, HMAC keys, OTP provider credentials) must be stored in the platform secret-management mechanism (environment secrets or equivalent).

### Platform Security Minimums (enforcement bounds — cannot be configured below these)

| Configuration Key | Minimum Value | Notes |
|---|---|---|
| `auth.otp.ttl` | 60 | At least 1 minute |
| `auth.otp.max_attempts` | 1 | At least one attempt |
| `auth.otp.max_sends_per_hour` | 1 | Cannot be zero |
| `auth.pin.length` | 6 | Platform minimum PIN length |
| `auth.lockout.max_failed_attempts` | 1 | Must lock after at least 1 failure |
| `auth.lockout.initial_duration` | 60 | At least 1 minute |
| `auth.step_up_threshold` | 0 | 0 = always require step-up |

### Seeded Configuration Defaults

| Key | Default | Type | Description |
|---|---|---|---|
| `auth.step_up_threshold` | `10000` | number | Transaction amount (in threshold currency) above which step-up MFA is required |
| `auth.step_up_threshold_currency` | `"BWP"` | string | Currency ISO code for the step-up threshold |
| `auth.session.inactivity_ttl_admin` | `900` | number | Admin session inactivity timeout in seconds (15 minutes) |
| `auth.session.inactivity_ttl_customer` | `1800` | number | Customer session inactivity timeout in seconds (30 minutes) |
| `auth.session.inactivity_ttl_agent` | `1800` | number | Agent session inactivity timeout in seconds (30 minutes) |
| `auth.refresh_token.ttl` | `2592000` | number | Refresh token lifetime in seconds (30 days) |
| `auth.refresh_token.rotation_enabled` | `true` | boolean | Rotate refresh token on every successful refresh |
| `auth.refresh_token.reuse_detection_enabled` | `true` | boolean | Revoke token family on refresh-token reuse detection |
| `auth.otp.ttl` | `300` | number | OTP validity window in seconds (5 minutes) |
| `auth.otp.max_attempts` | `5` | number | Maximum OTP verification attempts before challenge invalidation |
| `auth.otp.resend_cooldown` | `60` | number | Minimum seconds between OTP resend requests |
| `auth.otp.max_sends_per_hour` | `5` | number | Maximum OTP sends per account/channel per hour |
| `auth.pin.length` | `6` | number | Required PIN length in digits |
| `auth.pin.reject_sequential` | `true` | boolean | Reject sequential PINs (e.g., 123456, 654321) |
| `auth.pin.reject_repeated` | `true` | boolean | Reject repeated-digit PINs (e.g., 111111, 000000) |
| `auth.pin.reject_common` | `true` | boolean | Reject known/common PIN values |
| `auth.lockout.max_failed_attempts` | `5` | number | Consecutive failures before lockout |
| `auth.lockout.initial_duration` | `900` | number | Initial lockout duration in seconds (15 minutes) |
| `auth.lockout.progressive_enabled` | `true` | boolean | Increase lockout duration for repeated lockout episodes |
| `auth.mfa.admin.totp_enabled` | `true` | boolean | Allow TOTP MFA enrollment for Admin actors |
| `auth.mfa.admin.fido2_enabled` | `true` | boolean | Allow FIDO2/WebAuthn MFA enrollment for Admin actors |
| `auth.mfa.admin.sms_enabled` | `false` | boolean | Allow SMS MFA for Admin actors (disabled by default) |
| `auth.biometric.enabled` | `true` | boolean | Enable biometric authentication on supported devices |
| `auth.otp.provider` | `"configured"` | string | OTP provider identifier; actual credentials stored as secrets |
| `security.ip_audit.hash_algorithm` | `"HMAC-SHA256"` | string | Algorithm for IP representation in audit records |

### Runtime Configuration Pattern

```
Domain/Application Service
        ↓
ConfigurationService (contract)
        ↓
ConfigurationRepository (cached, TTL-invalidated)
        ↓
platform.configuration_values (PostgreSQL)
```

Cache invalidation: on Admin configuration change, the `configuration.changed` audit event fires and the ConfigurationService cache MUST be purged. Stale security configuration MUST NOT persist beyond the configured cache TTL.

---

## 9. Authentication Flows

### 9.1 Mobile OTP Registration + Login

```
CLIENT (App)                    API (E01)                   OTP Provider (TBD)
    │                               │                               │
    ├─ POST /v1/auth/register ──────►│                               │
    │  { mobile: "+267xxxxxxx" }    ├─ validate format              │
    │                               ├─ check no existing account    │
    │                               ├─ create pending user record   │
    │                               ├─ create OTP challenge ────────►│
    │◄── 202 { challenge_id } ──────┤◄────── OTP delivered ─────────┤
    │                               │                               │
    ├─ POST /v1/auth/otp/verify ────►│                               │
    │  { challenge_id, otp }        ├─ validate OTP (TTL, attempts) │
    │                               ├─ mark user active             │
    │                               ├─ assign default role          │
    │                               ├─ emit Identity.User.Registered│
    │◄── 201 { user_id } ───────────┤                               │
```

### 9.2 PIN Login

```
CLIENT                          API (E01)                   Redis (session store)
    │                               │                               │
    ├─ POST /v1/auth/login ─────────►│                               │
    │  { mobile, pin }              ├─ look up user by mobile       │
    │                               ├─ verify PIN hash              │
    │                               ├─ check account not locked     │
    │                               ├─ create session record        │
    │                               ├─ issue access + refresh tokens│
    │                               ├─ store refresh token ─────────►│
    │◄── 200 { access_token,        │                               │
    │          refresh_token }──────┤                               │
```

### 9.3 Token Refresh

```
CLIENT                          API (E01)
    │                               │
    ├─ POST /v1/auth/token/refresh ─►│
    │  { refresh_token }            ├─ validate refresh token (not revoked, not expired)
    │                               ├─ issue new access token
    │                               ├─ rotate refresh token (invalidate old, issue new)
    │◄── 200 { access_token,        │
    │          refresh_token } ─────┤
```

### 9.4 Step-Up (GAP-027 threshold TBD)

```
CLIENT                          API (E01)                   CALLING DOMAIN (e.g. E09 Transfer)
    │                               │                               │
    │                               │◄── step-up required ──────────┤
    │◄── 403 { requires_step_up,    │                               │
    │          challenge_id } ──────┤                               │
    │                               │                               │
    ├─ POST /v1/auth/step-up ───────►│                               │
    │  { challenge_id, mfa_code }   ├─ verify MFA challenge         │
    │                               ├─ issue step-up scoped token   │
    │◄── 200 { step_up_token } ─────┤                               │
    │                               │                               │
    ├─ retry protected operation    │                               │
    │  with step_up_token ──────────────────────────────────────────►│
```

---

## 10. Session / Token Flows

### 10.1 Token Design
- **Access token**: Short-lived JWT signed by E01 using **ES256** (ECDSA P-256). Header MUST include `kid` to identify the signing key. Claims: `user_id`, `session_id`, `actor_type`, `roles` (list of role IDs from E04 seed data), `correlation_id`, `iss`, `aud`, `iat`, `exp`.
- **Refresh token**: Opaque, stored server-side (hashed) in `identity.sessions`. Linked to a session record. TTL is configurable.
- **Step-up token**: Short-lived JWT signed with **ES256**. Contains `step_up_verified_at`, `step_up_scope`, and a restricted `aud` claim scoped to the protected operation. Step-up tokens MUST NOT be accepted as general access tokens; signature verification alone is insufficient — consuming services MUST also validate `aud` and `step_up_scope`.

**JWT Signing Architecture**:
- E01 is the sole signing authority for all access and step-up tokens.
- E01 MUST expose `GET /v1/.well-known/jwks.json` listing all active and recently rotated public keys.
- Downstream services (E04, E09, E23, etc.) MUST verify token signatures locally using cached JWKS public keys and MUST NOT call E01 for routine verification.
- Key rotation: E01 MUST support serving overlapping public keys (old and new) until all tokens signed with the old key have expired. The active signing key is identified by `kid`.
- Private signing keys MUST be stored in the platform secret-management mechanism; MUST NOT be in `platform.configuration_values` or application config files.

### 10.2 Session Record
A session record is created for each successful authentication. It tracks: session ID, user ID, device fingerprint, IP (hashed), refresh token hash, last activity, expiry, revocation state.

### 10.3 Revocation
Token revocation is immediate. Revoked session IDs and refresh token hashes are stored in Redis for fast rejection during token validation. The database session record is the authoritative state.

### 10.4 Refresh Token Reuse Detection
If a previously rotated (already replaced) refresh token is presented, the session MUST be fully revoked and a security event emitted. This detects token theft scenarios.

### 10.5 Service Token Design
E01 issues time-bounded JWT service-identity tokens to registered internal services (e.g., E09, E18, E23).

**Token characteristics**:
- Signed with ES256; header includes `kid`; validated by downstream services via cached JWKS — same infrastructure as access tokens
- Claims: `sub` (registered service identifier), `actor_type: "SERVICE"`, `aud` (restricted to declared audience), `jti` (unique per issuance for auditability), `iat`, `exp`
- Audience restriction is enforced: a token issued for one audience MUST NOT be accepted by another; consuming services MUST validate `aud`
- Lifetime: configurable; MUST NOT be indefinitely long-lived; exact value and configuration key are TBD — not hard-coded
- `jti` enables revocation tracking where required

**Delegation (on-behalf-of)**: When a service acts on behalf of a human user, the token MUST carry both the service actor identity (`actor_type: "SERVICE"`, `sub` = service name) and the originating user identity (e.g., `on_behalf_of` or `user_sub` claim). Both identities MUST be auditable. Exact claim naming is an implementation detail for the planning phase.

**Authorization**: Capability/scope-based only. A valid service JWT does not grant unrestricted access. The service's permitted scopes are validated by E04 authorization layer. The scope catalogue is TBD — not invented here.

**Service credential provisioning endpoint**: E01 exposes a dedicated endpoint for issuing service tokens to registered internal services. Exact path, registration mechanism, credential storage mechanism, and rotation interval are TBD — to be defined in the planning phase.

**Auditability**: Every service token issuance MUST produce an immutable `identity.service.token-issued` audit record in `platform.audit_events` including: service identifier, `jti`, `aud`, `iat`, `exp`, and correlation ID.

---

## 11. MFA / Step-Up Flows

### 11.1 MFA Enable (US-AUTH-034)
- Requires active authenticated session.
- User selects MFA method (OTP-based; other methods TBD by provider selection — TBD-002).
- Server generates enrollment challenge; user completes it.
- MFA enrollment stored in `identity.credentials` with `credential_type = 'mfa_totp'`.
- Audit event `identity.mfa.enabled` recorded.

### 11.2 MFA Verification (US-AUTH-036)
- After primary credential verification, system checks if MFA is enabled.
- If enabled, issues MFA challenge; session is in `mfa_pending` state.
- User submits MFA code; server verifies and transitions session to `active`.
- Failed MFA attempts counted; excessive failures produce lockout per configurable threshold.

### 11.3 Step-Up for High-Risk Transactions (US-AUTH-037)
- Downstream domain (e.g., E09 Transfer) signals that the requested operation exceeds the risk threshold, **or** the Risk Engine (E18) explicitly requests step-up.
- E01 evaluates whether the current session has a valid step-up claim for the requested operation.
- If not, returns 403 with `STEP_UP_REQUIRED` and a `challenge_id`.
- User completes MFA challenge; step-up token issued.
- **GAP-027 resolved**: Default step-up threshold = `10000 BWP` (`auth.step_up_threshold = 10000`, `auth.step_up_threshold_currency = "BWP"`). Both values are runtime-configurable. The threshold must NOT be hard-coded.
- **Multi-currency**: When the transaction currency differs from `auth.step_up_threshold_currency`, the platform FX mechanism is used. If FX conversion is unavailable, step-up is ALWAYS required (fail-safe — never bypassed).
- **Dual-trigger architecture**: Step-up must support BOTH the amount-threshold trigger AND an independent risk-engine signal. The architecture must not make either trigger depend on the other.
- **FIDO2/WebAuthn**: Admin actors can complete step-up via TOTP or FIDO2/WebAuthn.

### 11.4 Step-Up for Privileged Administration (US-AUTH-038)
- Admin-facing operations flagged as privileged (defined by E04 permission records) require step-up.
- Flow identical to 11.3 but scoped to admin actor and admin-facing operations.
- Admin MFA methods: TOTP (enabled by default), FIDO2/WebAuthn (enabled by default), SMS (disabled by default). All are runtime-configurable.

---

## 12. Security Requirements

| Requirement | Detail |
|---|---|
| Credential storage | All passwords and PINs MUST be stored as salted cryptographic hashes (Argon2id or bcrypt). Raw values MUST NOT appear in logs, responses, or events. |
| OTP protection | OTP codes MUST NOT appear in logs. OTP codes MUST have a short configurable TTL. OTP retry limits are enforced. |
| Token signing | Access tokens and step-up tokens MUST be signed. Signing key rotation is supported without requiring immediate session termination. |
| Transport | All API communication MUST use HTTPS/TLS. Established by Platform Foundation (FR-071). |
| Credential non-disclosure | Authentication failure responses MUST NOT reveal whether an identifier exists (`INVALID_CREDENTIALS` only). |
| Account lock security state | Locked accounts MUST reject authentication without further credential verification, preventing brute-force through the lock state. |
| Biometric assertion | Biometric authentication MUST use device-local verification. Server validates a signed assertion; biometric data MUST NOT transit the network. |
| PII protection | Phone numbers and email addresses in logs and events MUST be masked (not omitted, masked — sufficient for audit purposes without PII exposure). |
| Refresh token security | Refresh tokens MUST be stored hashed server-side. Token reuse after rotation triggers session revocation. |
| Rate limiting | OTP request rates, login attempt rates, and token refresh rates are subject to configurable rate limiting via platform configuration. Values are NOT hard-coded. |
| Security headers | Inherit from Platform Foundation API foundation (HSTS, X-Content-Type-Options, X-Frame-Options). |
| Secure error responses | Error responses MUST use the standard API error envelope and MUST NOT include stack traces or internal state in production. |
| Audit | All authentication events (success, failure, lockout, token operations, PIN operations, MFA operations) MUST be recorded in `platform.audit_events`. |
| IP protection in audit records | IP address representation in all E01 `platform.audit_events` records MUST use `HMAC-SHA256(platform_secret, ip_address)`. The raw IP MUST NOT be stored. The HMAC secret MUST be stored in the platform secret-management mechanism (environment/KMS), never in `platform.configuration_values`, and MUST NOT be exposed through any API or Admin Portal. |
| Secrets management | JWT signing keys, HMAC keys, and OTP provider credentials MUST be stored in the platform secret-management mechanism. MUST NOT be stored in `platform.configuration_values`. MUST NOT appear in logs, audit records, API responses, or event payloads. |
| Configuration validation | Configuration updates MUST be validated against typed boundaries and security minimums. An admin MUST NOT be able to weaken security below platform-defined minimums (e.g., PIN length below 6). |

### Performance Requirements

API server-side latency targets for the E01 authentication hot paths. Measured at the server boundary. OTP delivery latency (external provider) is excluded. Network latency from mobile clients (LTE/3G, typical African mobile network conditions) is additional and not included in these targets.

| Operation | P95 Target | Notes |
|---|---|---|
| Login (PIN or OTP) | ≤ 500 ms | Includes credential lookup, hash verification, session creation, token signing |
| Token refresh | ≤ 200 ms | Redis cache warm; token validation + rotation + signing |
| OTP verification | ≤ 500 ms | Includes OTP hash comparison, challenge state transition, account activation |

Performance tests MUST be included in the pre-release load test suite. Concurrent user volume and load profile are TBD — not defined here. Tests run with Redis cache warm and reflect expected production database indexing.

### Observability Requirements

E01 MUST emit the following application-level metrics (per Constitution Principle 13). Metric naming follows the platform convention; label/dimension specifics are implementation details for the planning phase.

| Metric | Type | Description |
|---|---|---|
| `identity.auth.success` | Counter | Successful authentications; breakdowns by actor type and method |
| `identity.auth.failure` | Counter | Failed authentication attempts; breakdowns by reason (invalid credential, locked, etc.) |
| `identity.otp.sent` | Counter | OTP sends; breakdowns by channel (SMS / email) |
| `identity.account.locked` | Counter | Account lockout events |
| `identity.token.refresh` | Counter | Token refresh attempts; success and failure |
| `identity.step_up.triggered` | Counter | Step-up triggers; breakdowns by reason (amount threshold / risk-engine signal) |
| `identity.auth.latency` | Histogram (P95) | Server-side latency per hot-path operation (login, token refresh, OTP verification) |

Low-level Redis cache hit/miss rates and token validation rates are deferred to the platform observability layer. Metric emission MUST NOT log or expose credentials, OTP codes, or raw PII in metric labels.

### Data Retention Requirements

Expired and revoked identity records are purged by scheduled Laravel Horizon cleanup jobs. Retention values MUST be sourced from `platform.configuration_values` — not hard-coded in job definitions. Cleanup jobs MUST use status + timestamp criteria and MUST NOT purge records still within the retention window.

| Record Type | Retention Window | Trigger Condition | Rationale |
|---|---|---|---|
| `identity.sessions` | 90 days after `expires_at` or `updated_at` (whichever is later for revoked/logged-out) | `status IN ('expired','revoked','logged_out') AND updated_at < now() - interval '90 days'` | Security forensics window for session-based investigations |
| `identity.otp_challenges` | 24 hours after `expires_at` or `updated_at` (whichever is later for invalidated/verified) | `status IN ('expired','invalidated','verified') AND updated_at < now() - interval '24 hours'` | Limit table growth; OTP challenges have no forensic value after their TTL |

Active records (`identity.sessions.status = 'active'`, `identity.otp_challenges.status = 'pending'`) MUST NEVER be purged by cleanup jobs regardless of age.

---

## 13. API Contracts

All endpoints use the Platform Foundation API conventions:
- Base path: `/v1`
- Standard response envelope: `{ success, data, error, meta }`
- Standard error taxonomy with error codes
- `X-Correlation-ID` header propagated
- `X-Request-ID` header supported
- Where applicable: `Idempotency-Key` header for state-changing operations
- OpenAPI documentation generated for all endpoints

### 13.1 Endpoint Summary

| Story | Method | Path | Idempotent | Auth Required |
|---|---|---|---|---|
| US-AUTH-001 | POST | `/v1/auth/register` | Yes (mobile) | No |
| US-AUTH-002 | POST | `/v1/auth/register` | Yes (email) | No |
| US-AUTH-003 | POST | `/v1/auth/otp/verify` | Yes | No |
| US-AUTH-004 | POST | `/v1/auth/otp/resend` | No (rate-limited) | No |
| US-AUTH-005 | — | Policy enforced within OTP verification flow | — | — |
| US-AUTH-006 | — | Policy enforced within OTP resend flow | — | — |
| US-AUTH-007 | — | Enforced within registration flow | — | — |
| US-AUTH-008 | — | Performed internally on registration completion | — | — |
| US-AUTH-009 | POST | `/v1/auth/login` | No | No |
| US-AUTH-010 | POST | `/v1/auth/login` | No | No |
| US-AUTH-011 | POST | `/v1/auth/login` | No | No |
| US-AUTH-012 | POST | `/v1/auth/login/biometric` | No | No |
| US-AUTH-013 | — | Enforced within login flow | — | — |
| US-AUTH-014 | — | Enforced within login flow | — | — |
| US-AUTH-015 | — | Event emitted within login/device-detection flow | — | — |
| US-AUTH-016 | — | Enforced within login flow | — | — |
| US-AUTH-017 | POST | `/v1/auth/devices/verify` | Yes | Partial session |
| US-AUTH-018 | — | Token issued within login response | — | — |
| US-AUTH-019 | — | Token issued within login response | — | — |
| US-AUTH-020 | POST | `/v1/auth/token/refresh` | No | Refresh token |
| US-AUTH-021 | — | Rotation enforced within refresh flow | — | — |
| US-AUTH-022 | POST | `/v1/auth/logout` | Yes | Yes |
| US-AUTH-023 | DELETE | `/v1/auth/sessions` | Yes | Yes |
| US-AUTH-024 | — | Background job / session middleware | — | — |
| US-AUTH-025 | POST | `/v1/admin/sessions/{session_id}/revoke` | Yes | Admin |
| US-AUTH-026 | GET | `/v1/auth/sessions` | — | Yes |
| US-AUTH-027 | DELETE | `/v1/auth/sessions/{id}` | Yes | Yes |
| US-AUTH-028 | POST | `/v1/auth/pin/set` | Yes | Setup token or Bearer |
| US-AUTH-029 | POST | `/v1/auth/pin/change` | Yes | Yes |
| US-AUTH-030 | POST | `/v1/auth/pin/reset/initiate` + `POST /v1/auth/pin/reset/complete` | Yes | Partial (OTP) |
| US-AUTH-031 | — | Enforced within PIN creation/change flow | — | — |
| US-AUTH-032 | — | Enforced within PIN verification flow | — | — |
| US-AUTH-033 | POST | `/v1/auth/pin/unblock` | Yes | None (recovery flow) |
| US-AUTH-034 | POST | `/v1/auth/mfa/enable` | Yes | Yes |
| US-AUTH-035 | POST | `/v1/auth/mfa/disable` | Yes | Yes |
| US-AUTH-036 | POST | `/v1/auth/mfa/verify` | No | Partial session |
| US-AUTH-037 | POST | `/v1/auth/step-up` | No | Yes (step-up required) |
| US-AUTH-038 | POST | `/v1/auth/step-up` | No | Admin |
| JWKS | GET | `/v1/.well-known/jwks.json` | — | No |
| Service token | POST | TBD — service credential provisioning endpoint (path defined in planning) | Yes | Service credential |

Exact request/response schemas, error codes, and OpenAPI definitions are produced in the `contracts/` directory during the planning phase.

---

## 14. DB Entities

E01 owns the `identity` PostgreSQL schema. Platform Foundation has already created the `identity` schema (empty). E01 creates all tables within it.

### `identity.users`
Core identity record. One record per registered account.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUID v7 |
| `mobile_number` | `varchar(20)` UNIQUE NULL | Normalised E.164 format; NULL if email-only registration |
| `email` | `varchar(255)` UNIQUE NULL | Lowercase; NULL if mobile-only registration |
| `status` | `varchar(20)` NOT NULL | `pending_verification`, `active`, `locked`, `suspended`, `deactivated` |
| `default_role_id` | `uuid` NULL | References E04 seeded role ID; stored as value (no FK across schemas) |
| `actor_type` | `varchar(20)` NOT NULL | `CUSTOMER`, `AGENT`, `ADMIN`, `ENTERPRISE` |
| `lock_reason` | `varchar(100)` NULL | Reason for locked status |
| `locked_at` | `timestamptz` NULL | When account was locked |
| `failed_attempt_count` | `integer` NOT NULL DEFAULT `0` | Consecutive failed auth attempts |
| `last_login_at` | `timestamptz` NULL | Last successful authentication |
| `created_at` | `timestamptz` NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` NOT NULL DEFAULT `now()` | |

### `identity.credentials`
Credential records per user per type. Multiple credential types per user allowed.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUID v7 |
| `user_id` | `uuid` NOT NULL | References `identity.users.id` (same schema) |
| `credential_type` | `varchar(20)` NOT NULL | `pin`, `password`, `biometric_binding`, `mfa_totp` |
| `credential_hash` | `varchar(255)` NULL | Argon2id/bcrypt hash; NULL for `biometric_binding` |
| `biometric_public_key` | `text` NULL | Device public key for biometric assertion; only for `biometric_binding` type |
| `status` | `varchar(20)` NOT NULL | `active`, `blocked`, `revoked` |
| `failed_attempt_count` | `integer` NOT NULL DEFAULT `0` | For PIN/MFA lockout |
| `expires_at` | `timestamptz` NULL | For time-limited credentials |
| `created_at` | `timestamptz` NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` NOT NULL DEFAULT `now()` | |

**Unique constraint**: `(user_id, credential_type)` — one active credential per type per user.

### `identity.sessions`
One record per active authenticated session.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUID v7; also the `session_id` embedded in tokens |
| `user_id` | `uuid` NOT NULL | References `identity.users.id` |
| `device_id` | `uuid` NULL | References `identity.devices.id` (NULL for non-device flows) |
| `refresh_token_hash` | `varchar(64)` NOT NULL | SHA-256 of the issued refresh token |
| `status` | `varchar(20)` NOT NULL | `active`, `mfa_pending`, `step_up_pending`, `expired`, `revoked`, `logged_out` |
| `actor_type` | `varchar(20)` NOT NULL | Mirrors `identity.users.actor_type` for fast token validation |
| `step_up_verified_at` | `timestamptz` NULL | When step-up was last completed in this session |
| `step_up_scope` | `varchar(100)` NULL | Operation scope for which step-up was granted |
| `last_activity_at` | `timestamptz` NOT NULL | Updated on each token use |
| `expires_at` | `timestamptz` NOT NULL | Absolute expiry from config |
| `ip_hash` | `varchar(64)` NULL | Hashed IP for audit; never raw IP in persistent store |
| `user_agent` | `varchar(500)` NULL | |
| `created_at` | `timestamptz` NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` NOT NULL DEFAULT `now()` | |

### `identity.devices`
Known/trusted devices associated with a user.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUID v7 |
| `user_id` | `uuid` NOT NULL | References `identity.users.id` |
| `device_fingerprint` | `varchar(255)` NOT NULL | Stable device identifier (platform-specific; hashed) |
| `device_name` | `varchar(100)` NULL | User-visible label |
| `platform` | `varchar(20)` NOT NULL | `ios`, `android`, `web` |
| `status` | `varchar(20)` NOT NULL | `pending_verification`, `trusted`, `revoked` |
| `trusted_at` | `timestamptz` NULL | When device was promoted to trusted |
| `last_seen_at` | `timestamptz` NULL | |
| `created_at` | `timestamptz` NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` NOT NULL DEFAULT `now()` | |

**Unique constraint**: `(user_id, device_fingerprint)`.

### `identity.otp_challenges`
Short-lived OTP/verification challenges for all challenge types.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUID v7 |
| `user_id` | `uuid` NULL | NULL for pre-registration challenges |
| `challenge_type` | `varchar(30)` NOT NULL | `registration`, `login`, `device_verification`, `pin_reset`, `pin_unblock`, `mfa_enrollment`, `step_up` |
| `identifier` | `varchar(255)` NOT NULL | Mobile or email (masked in logs) |
| `otp_hash` | `varchar(64)` NOT NULL | SHA-256 of the OTP; never stored plaintext |
| `status` | `varchar(20)` NOT NULL | `pending`, `verified`, `expired`, `invalidated` |
| `attempt_count` | `integer` NOT NULL DEFAULT `0` | Failed verification attempts |
| `resend_count` | `integer` NOT NULL DEFAULT `0` | OTP resend requests |
| `last_resent_at` | `timestamptz` NULL | For cooldown enforcement |
| `expires_at` | `timestamptz` NOT NULL | |
| `created_at` | `timestamptz` NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` NOT NULL DEFAULT `now()` | |

**Cross-module constraint**: No FK to any non-`identity` schema table.

---

## 15. Events

All events use the Platform Foundation outbox pattern and event envelope. Events follow the `{Module}.{Aggregate}.{Verb}` naming convention from the platform audit-action vocabulary.

| Event Name | Trigger | Consumers |
|---|---|---|
| `Identity.User.Registered` | Account successfully created and OTP verified | E02 (profile creation), E05 (KYC trigger), E17 (welcome notification) |
| `Identity.User.RegistrationOtpRequested` | OTP issued for registration | E17 (OTP delivery) |
| `Identity.User.RegistrationOtpResendRequested` | OTP resend request accepted | E17 (OTP delivery) |
| `Identity.Session.Created` | Successful authentication | E17 (login notification), E26 (security monitoring) |
| `Identity.Session.LoginFailed` | Authentication failure | E18 (risk signal), E26 (security monitoring) |
| `Identity.Account.Locked` | Account locked after excessive failures | E17 (lockout notification), E26 (security alert) |
| `Identity.Device.NewDeviceDetected` | Login from unrecognized device | E17 (new-device notification), E26 (security alert) |
| `Identity.Device.Verified` | New device verification completed | — |
| `Identity.Session.Terminated` | User logout (current session) | — |
| `Identity.Session.AllTerminated` | User logged out of all sessions | E17 (notification) |
| `Identity.Session.Expired` | Session auto-expired by cleanup job | — |
| `Identity.Session.Revoked` | Session administratively revoked | E17 (security notification) |
| `Identity.Credential.PinCreated` | Transaction PIN created | — |
| `Identity.Credential.PinChanged` | Transaction PIN changed | E17 (notification) |
| `Identity.Credential.PinReset` | PIN reset via recovery | E17 (notification) |
| `Identity.Credential.PinBlocked` | PIN blocked after repeated failures | E17 (notification) |
| `Identity.Credential.PinUnblocked` | PIN unblocked via recovery | E17 (notification) |
| `Identity.MFA.Enabled` | User enabled MFA | E17 (notification) |
| `Identity.MFA.Disabled` | User disabled MFA | E17 (notification) |
| `Identity.MFA.ChallengeVerified` | MFA challenge successfully verified | — |
| `Identity.MFA.StepUpCompleted` | Step-up MFA completed | Calling domain (Transfer, Admin) |
| `Identity.MFA.StepUpRequired` | Step-up triggered for operation | — |

**Event envelope**: Follows Platform Foundation `platform.outbox_events` schema. All events include `correlation_id`, `causation_id`, `event_version: "1.0.0"`, `actor_id`, and `aggregate_id`.

**Delivery guarantee**: At-least-once. Consumers MUST implement idempotency using Platform Foundation idempotency infrastructure.

---

## 16. Audit Requirements

All authentication and security events MUST be recorded in `platform.audit_events` using the Platform Foundation `AuditService`. E01 does NOT create a second audit registry.

| Audit Action | Trigger | Severity |
|---|---|---|
| `identity.user.registered` | Successful account registration | INFO |
| `identity.session.created` | Successful login | INFO |
| `identity.session.login-failed` | Authentication failure | WARN |
| `identity.account.locked` | Account locked | WARN |
| `identity.device.new-device-detected` | New device login | WARN |
| `identity.session.terminated` | Logout (current) | INFO |
| `identity.session.all-terminated` | Logout (all sessions) | INFO |
| `identity.session.revoked` | Compromised session revoked | WARN |
| `identity.credential.pin-created` | PIN created | INFO |
| `identity.credential.pin-changed` | PIN changed | INFO |
| `identity.credential.pin-reset` | PIN reset | WARN |
| `identity.credential.pin-blocked` | PIN blocked | WARN |
| `identity.credential.pin-unblocked` | PIN unblocked | INFO |
| `identity.mfa.enabled` | MFA enabled | INFO |
| `identity.mfa.disabled` | MFA disabled | WARN |
| `identity.mfa.step-up-completed` | Step-up MFA completed | INFO |
| `identity.mfa.step-up-failed` | Step-up MFA failed | WARN |
| `identity.service.token-issued` | Service-identity JWT issued to a registered internal service | INFO |

**Audit payload rules**:
- Actor ID: always the authenticated user's UUID (or zero-UUID for unauthenticated events such as registration)
- `before_state` / `after_state`: MUST mask or omit credential hashes, OTP codes, PII (mobile/email masked)
- `ip_address`: Hashed; raw IP MUST NOT appear in `platform.audit_events`
- Audit records are INSERT-only; E01 MUST NOT attempt UPDATE or DELETE on `platform.audit_events`

---

## 17. Permissions / Authorization Boundary

### 17.1 What E01 Provides

E01 establishes **authenticated identity and actor context** only. After a successful authentication flow, the system has:

```
Authentication (E01)
      ↓
Authenticated Actor Context: { user_id, session_id, actor_type, roles[], step_up_claims }
      ↓
Authorization / Permission Enforcement (E04)
      ↓
Protected Domain Operation (E06, E09, E11, etc.)
```

E01 embeds the authenticated user's `role_id` list in the access token. It does NOT evaluate permissions against resources — that is E04's responsibility.

### 17.2 What E01 Does NOT Provide

- Permission management, role CRUD, role assignment changes — E04.
- Resource-level access control policies — E04.
- Operation-level permission evaluation — E04 and downstream domains.

### 17.3 Default Role Assignment (US-AUTH-008)

Upon registration, E01 assigns the pre-seeded default customer role ID to `identity.users.default_role_id`. The role definitions and permission assignments are E04's responsibility. E01 only stores and embeds the ID.

### 17.4 Self-Service vs Administrative Operations

| Operation | Actor Constraint |
|---|---|
| Register, verify OTP, login | Unauthenticated (pre-auth) |
| Create PIN, change PIN, enable MFA, view sessions | Authenticated user (self only) |
| Reset PIN, unblock PIN | Partial authentication (OTP verified but no full session) |
| Revoke any session (compromised) | Admin actor with admin permission (enforced by E04) |
| View/revoke own sessions | Authenticated user (self only) |
| Require step-up (trigger) | Internal system call from downstream domain |

---

## 18. UI Screens

Design tokens, shared components, accessibility foundation, loading/error/empty/success/security states, and responsive foundations are provided by the Platform Foundation design system. E01 screens extend them.

### 18.1 Customer Flutter — Authentication Screens

| Screen | Stories |
|---|---|
| Registration: Enter mobile number | US-AUTH-001 |
| Registration: Enter email | US-AUTH-002 |
| Registration: OTP verification | US-AUTH-003 |
| Registration: OTP resend | US-AUTH-004 |
| Registration: Account exists (generic error — no disclosure) | US-AUTH-007 |
| Login: Enter mobile/identifier | US-AUTH-009, US-AUTH-010, US-AUTH-011 |
| Login: OTP entry | US-AUTH-009 |
| Login: PIN entry keypad | US-AUTH-010 |
| Login: Biometric prompt | US-AUTH-012 |
| Login: Invalid credentials (generic error) | US-AUTH-013 |
| Login: Account locked (with recovery CTA) | US-AUTH-014 |
| Login: New device verification | US-AUTH-016, US-AUTH-017 |
| PIN management: Create PIN | US-AUTH-028 |
| PIN management: Change PIN | US-AUTH-029 |
| PIN management: Forgot PIN / reset | US-AUTH-030 |
| PIN management: PIN blocked / unblock | US-AUTH-032, US-AUTH-033 |
| MFA: Enable MFA | US-AUTH-034 |
| MFA: Disable MFA | US-AUTH-035 |
| MFA: Verify MFA challenge | US-AUTH-036 |
| Step-up: MFA challenge overlay | US-AUTH-037 |
| Sessions: View active sessions | US-AUTH-026 |
| Sessions: Revoke device session | US-AUTH-027 |

All screens include: loading state, success state, error state, retry CTA, accessibility labels, keyboard navigation, and screen-reader compatibility per Platform Foundation accessibility foundation.

### 18.2 Agent Flutter — Authentication Screens

Agent authentication uses a subset of Customer flows:
- Mobile OTP registration and login (US-AUTH-001, US-AUTH-003, US-AUTH-004, US-AUTH-009)
- PIN login (US-AUTH-010)
- PIN management (US-AUTH-028, US-AUTH-029, US-AUTH-030, US-AUTH-031, US-AUTH-032)
- New device detection and verification (US-AUTH-016, US-AUTH-017)
- Session management: view and revoke own sessions (US-AUTH-026, US-AUTH-027)

---

## 19. Mobile Flows

### 19.1 Customer Application (Flutter)

- **Riverpod** for state management (Platform Foundation architectural decision)
- Authentication state provider with states: `unauthenticated`, `otp_pending`, `pin_pending`, `mfa_pending`, `authenticated`, `step_up_pending`, `locked`
- Token storage: Secure enclave / Android Keystore (never in plain SharedPreferences or unencrypted local storage)
- Biometric flow: Flutter device biometric integration for local biometric verification; assertion signed with device key and sent to API
- Background token refresh: handled in app lifecycle with graceful re-authentication on refresh failure
- Session expiry: user returned to login screen with informative message; no silent loss of application state
- Step-up: inline overlay/bottom sheet with MFA challenge; does not navigate away from the in-progress operation screen

### 19.2 Agent Application (Flutter)

- Same Riverpod and secure storage patterns as Customer application
- Agent auth flows are a simplified subset (no email registration in initial scope; biometric per approved backlog)
- Connectivity-aware: flow degrades gracefully on poor connectivity

---

## 20. Admin Flows

### 20.1 Admin / Enterprise Next.js

- Password + mandatory MFA login (US-AUTH-011, US-AUTH-034, US-AUTH-036, US-AUTH-038)
- Admin requires step-up for privileged operations (US-AUTH-038)
- Admin screens:
  - Session management: view and revoke any user's session (US-AUTH-025)
  - Account status visibility: view locked accounts (US-AUTH-014 administrative path)
  - MFA enforcement visibility: view users' MFA enrollment status (read-only; management in E04)

### 20.2 Admin Authentication Flow

```
Admin opens web app → enters email + password → server validates credentials
→ issues MFA challenge (mandatory for Admin) → admin enters TOTP/OTP
→ MFA verified → access token issued with admin actor type and role
→ privileged operations require step-up MFA (US-AUTH-038)
```

---

## 21. Error / Edge Cases

| Scenario | Expected Behaviour |
|---|---|
| OTP expired at verification | Reject with `OTP_EXPIRED`; prompt user to request new OTP |
| OTP attempt limit exceeded | Invalidate OTP; require new OTP request; record in audit |
| OTP resend before cooldown | Reject with `OTP_COOLDOWN_ACTIVE`; return `retry_after` duration |
| Duplicate registration (same mobile/email) | Reject with generic `IDENTIFIER_UNAVAILABLE` (no disclosure of existing account) |
| Invalid credentials at login | Reject with `INVALID_CREDENTIALS`; increment failure count; no identifier disclosure |
| Account locked during login | Reject with `ACCOUNT_LOCKED`; include recovery options in response |
| Expired access token (valid refresh) | 401 with `TOKEN_EXPIRED`; client uses refresh flow |
| Expired refresh token | 401 with `REFRESH_TOKEN_EXPIRED`; client prompts re-authentication |
| Revoked refresh token reuse | Revoke all sessions for the user; return `REFRESH_TOKEN_REUSE_DETECTED` |
| Concurrent login attempts | Idempotency enforced at session creation; duplicate concurrent requests produce same session |
| PIN complexity rejection | Return `PIN_COMPLEXITY_VIOLATION` with configurable policy description |
| PIN block | Return `PIN_BLOCKED`; include recovery CTA |
| Step-up required (no active step-up) | Return `STEP_UP_REQUIRED` with `challenge_id` |
| Step-up MFA failure | Increment MFA failure count; lockout after configurable threshold |
| New device login (unverified) | Return `DEVICE_VERIFICATION_REQUIRED` with `challenge_id` |
| Biometric assertion invalid | Reject with `BIOMETRIC_ASSERTION_INVALID`; fall back to PIN/OTP |
| Session revoked mid-use | Next API request using revoked session returns `SESSION_REVOKED` |
| Concurrent refresh token rotation race | First succeeds; subsequent request with old token triggers `REFRESH_TOKEN_REUSE_DETECTED` |

---

## 22. Integration Dependencies

### 22.1 Platform Foundation (Required)

| Dependency | Usage |
|---|---|
| `platform.outbox_events` + OutboxService | All E01 domain/integration events |
| `platform.audit_events` + AuditService | All authentication and security audit records |
| `platform.configuration_values` + ConfigurationService | OTP TTL, retry limits, lockout threshold, session TTL, PIN policy, rate limits |
| `platform.idempotency_records` + IdempotencyService | Idempotent state-changing auth operations |
| Platform API envelope (`ApiResponse`) | Standard response format for all E01 endpoints |
| Platform error taxonomy | Standard error codes for all E01 errors |
| Correlation ID middleware | `X-Correlation-ID` propagated through all E01 requests |
| Redis (via Laravel) | Refresh token revocation cache; rate limiting counters; OTP TTL tracking |
| Laravel Horizon | Background jobs: session expiry cleanup, OTP cleanup |
| PostgreSQL `identity` schema | E01's dedicated schema; empty when Platform Foundation finishes |

### 22.2 OTP Provider (TBD)

OTP delivery requires an external SMS/email gateway. Provider selection is TBD (TBD-001). E01 MUST abstract OTP delivery behind an `OtpDeliveryContract` interface. The concrete implementation is injected via a service provider. The contract supports mock/stub implementation for testing without a live provider.

### 22.3 Future Epic Integration Boundaries

| Epic | Integration Point from E01 |
|---|---|
| E02 User & Profile Management | Consumes `Identity.User.Registered` to create profile record |
| E03 Device & Session Management | Extended device management builds on `identity.devices` and E01 session model |
| E04 Roles, Permissions & Authorization | E04 seeds role definitions; E01 assigns default role ID; E04 evaluates permissions using actor context from E01 tokens |
| E05 KYC & Identity Verification | Consumes `Identity.User.Registered` to trigger KYC flow |
| E09 Wallet-to-Wallet Transfer | Requests step-up via E01 `STEP_UP_REQUIRED` flow (US-AUTH-037) |
| E17 Notifications | Consumes E01 events for OTP delivery, login alerts, lockout notifications |
| E18 Fraud, Risk & AML | Consumes `Identity.Session.LoginFailed`, `Identity.Account.Locked`, `Identity.Device.NewDeviceDetected` as risk signals |
| E19 Admin Portal | Uses E01 admin authentication; invokes session revocation and account management |
| E23 Audit & Compliance | Uses `platform.audit_events` records produced by E01 |
| E26 Security | Consumes E01 security events for monitoring and alerting |

---

## 23. E2E Scenarios

### Scenario 1: New Customer — Registration to First Login
1. Customer submits mobile number → OTP sent
2. Customer verifies OTP → account created, default role assigned, `Identity.User.Registered` emitted
3. Customer logs in with mobile OTP → access + refresh tokens issued
4. Customer creates transaction PIN
5. Customer logs in with PIN on second attempt
6. **Verify**: `platform.audit_events` contains registration, session creation, PIN creation records
7. **Verify**: `identity.users` has `status=active`; `identity.sessions` has one active session

### Scenario 2: Account Lockout and Recovery
1. Customer fails PIN login N times (N = configurable threshold)
2. Account status set to `locked`; `identity.account.locked` audit record created
3. `Identity.Account.Locked` event emitted; E17 delivers lockout notification
4. Customer requests PIN reset via mobile OTP recovery flow
5. OTP verified → PIN reset → account unlocked
6. Customer successfully logs in with new PIN
7. **Verify**: full audit trail in `platform.audit_events`

### Scenario 3: Step-Up Authentication for Transfer
1. Customer initiates a high-value transfer (E09)
2. E09 determines step-up required (threshold from `auth.step_up_threshold`; default 10000 BWP, runtime-configurable; GAP-027 resolved)
3. E01 returns `STEP_UP_REQUIRED` with `challenge_id`
4. Customer completes MFA challenge
5. E01 issues step-up token
6. Customer retries transfer with step-up token
7. E09 proceeds with transfer
8. **Verify**: `identity.mfa.step-up-completed` audit record; step-up token TTL enforced

### Scenario 4: New Device Login
1. Customer logs in from unrecognized device fingerprint
2. System detects new device; returns `DEVICE_VERIFICATION_REQUIRED`
3. `Identity.Device.NewDeviceDetected` event emitted; E17 delivers notification
4. Customer completes device OTP verification
5. Device promoted to trusted; session created
6. **Verify**: `identity.devices` record with `status=trusted`

### Scenario 5: Refresh Token Reuse Attack
1. Session established; refresh token issued
2. Token refresh performed; old token invalidated, new token issued
3. Attacker presents original (now rotated) refresh token
4. System detects reuse: all sessions for this user revoked
5. `Identity.Session.AllTerminated` event emitted
6. **Verify**: all sessions in `identity.sessions` have `status=revoked`; audit record created

---

## 24. QA Scenarios

### 24.1 Functional Tests (per story)
Every story requires:
- Happy-path test with valid data and expected success response
- Required-field validation test (missing/null fields)
- Format validation test (invalid mobile number format, invalid email format, etc.)
- Idempotency test where applicable (same request twice → same result, no side effects)
- Audit event verification (assert `platform.audit_events` record created)

### 24.2 Security Tests
- Invalid credentials at login: assert no identifier disclosure
- OTP after expiry: assert rejection with `OTP_EXPIRED`
- OTP replay (same code used twice): assert second use rejected
- Account lockout: assert lockout after threshold; assert rejection while locked
- Revoked token use: assert 401 with `SESSION_REVOKED`
- Refresh token reuse: assert all-session revocation
- PIN stored as hash: assert no plaintext PIN in DB, logs, or event payloads
- OTP not in logs: assert structured log output contains no OTP values
- Step-up token scope: assert step-up token rejected for operations outside its scope
- Admin login without MFA: assert MFA is enforced and cannot be bypassed

### 24.3 Concurrency Tests
- Concurrent OTP verification requests for same challenge: exactly one succeeds, duplicate rejected
- Concurrent login requests: exactly one session created; duplicate concurrent attempts idempotent
- Concurrent refresh token rotation: exactly one rotation succeeds; parallel attempt with same token returns `REFRESH_TOKEN_REUSE_DETECTED`
- Concurrent PIN change and PIN reset: conflict detection with appropriate error

### 24.4 Mobile Tests (Flutter)
- Biometric authentication flow on both iOS and Android simulators
- Token secure storage verified (not accessible from non-app contexts)
- Session expiry: app returns to login screen correctly
- Step-up overlay does not lose in-progress operation context
- OTP input: numeric-only; auto-submit on final digit

### 24.5 Accessibility Tests
- All authentication screens pass WCAG AA contrast requirements
- Keyboard navigation through all form fields and CTAs
- Screen-reader labels on all interactive elements
- Error messages associated with relevant form fields
- Loading states announced to screen readers

### 24.6 API Tests
- All endpoints return standard Platform Foundation response envelope
- `X-Correlation-ID` header present in all responses
- Error responses use correct error codes from taxonomy
- OpenAPI schema validation for all request/response shapes

### 24.7 Performance Tests
- Login (PIN and OTP): P95 ≤ 500 ms measured at server under sustained load (concurrent volume TBD)
- Token refresh: P95 ≤ 200 ms measured at server with Redis cache warm
- OTP verification: P95 ≤ 500 ms measured at server
- Tests run with expected production indexing in place; Redis pre-warmed
- OTP external delivery latency excluded from all measurements
- Performance suite is mandatory before release; failure blocks release (per Definition of Done)

---

## 25. Acceptance Criteria

| ID | Criterion |
|---|---|
| AC-001 | A new customer can register using mobile number, receive OTP, verify OTP, and receive a confirmation response |
| AC-002 | OTP codes expire and are rejected after their configured TTL |
| AC-003 | A duplicate registration for an existing mobile/email returns an error without disclosing account existence |
| AC-004 | A successful login returns a signed access token and a refresh token |
| AC-005 | Presenting a valid refresh token returns a new access token and a rotated refresh token; the old refresh token is rejected after rotation |
| AC-006 | Presenting a previously rotated refresh token triggers all-session revocation |
| AC-007 | A user's account is locked after the configured number of consecutive failed authentication attempts |
| AC-008 | A locked account rejects authentication with a specific error code; the raw credential is not disclosed |
| AC-009 | Login from a new device returns `DEVICE_VERIFICATION_REQUIRED`; session is only granted after verification |
| AC-010 | A transaction PIN is stored hashed; the raw PIN never appears in logs, events, or API responses |
| AC-011 | PIN block is triggered after the configured number of failed PIN verifications |
| AC-012 | A blocked PIN can be unblocked through OTP-verified recovery |
| AC-013 | Step-up MFA is required when a transaction amount exceeds the configured threshold (default 10000 BWP) OR when the risk engine explicitly requests step-up, independently of the amount |
| AC-014 | MFA can be enabled and disabled by an authenticated user; each operation is audited |
| AC-015 | All authentication and security events appear in `platform.audit_events` with correct action strings |
| AC-016 | All E01 domain events are published through `platform.outbox_events` using the outbox pattern |
| AC-017 | All configurable policy values (lockout threshold, OTP TTL, session TTL, PIN complexity) are read from `platform.configuration_values` and are not hard-coded |
| AC-018 | Admin login requires password + MFA; MFA cannot be bypassed |
| AC-019 | An admin can revoke any user's compromised session; the revocation takes effect immediately |
| AC-020 | All E01 API endpoints return the standard Platform Foundation response envelope |
| AC-021 | The identity security configuration seed executes without error; running it a second time produces no changes to existing configuration values |
| AC-022 | An authorized admin can view all identity security configuration keys and their current values via the Admin Portal Security Configuration section |
| AC-023 | An authorized admin can update a configuration value via the Admin API; the change is audited with old value, new value, actor, timestamp, correlation ID, and HMAC-SHA256 IP representation |
| AC-024 | Attempting to set `auth.pin.length` below 6 (or any configuration key below its platform minimum) is rejected with a validation error |
| AC-025 | A concurrent configuration update based on a stale version is rejected with `CONFIGURATION_CONFLICT` |
| AC-026 | All E01 `platform.audit_events` IP fields contain HMAC-SHA256 representations; no raw IP addresses appear in audit records |
| AC-027 | Account lockout duration increases for each successive lockout episode when `auth.lockout.progressive_enabled = true` |
| AC-028 | Admin MFA enrollment supports TOTP and FIDO2/WebAuthn; SMS MFA is not offered to Admin actors by default |

---

## 26. Definition of Done

**Story-level**:
- All story-specific acceptance criteria implemented and passing
- API endpoint(s) returning standard envelope with correct status codes
- DB entities created with migrations; indexes and constraints applied
- Domain/integration events published via outbox for all required transitions
- Audit records written to `platform.audit_events` for all auditable operations
- All configurable values sourced from platform configuration (no hard-coding)
- Unit tests, integration tests, and API tests written and passing
- Security test cases written and passing (no plaintext credentials in logs/events/responses)
- Flutter screens implemented for applicable stories with all states (loading, success, error)
- Accessibility requirements met for all screens
- OpenAPI documentation generated and accurate

**Epic-level**:
- All 38 stories meet their story-specific DoD
- Full E2E authentication flow (registration → login → token refresh → step-up → logout) passes
- Cross-story dependency tests pass (lockout recovery, device verification, refresh rotation)
- Security review: no plaintext credentials in any log, event, or response
- No unresolved CRITICAL or HIGH defects blocking release
- Test coverage ≥ 80% for the identity module
- OpenAPI contract published and validated
- `platform.audit_events` immutability tests pass for identity events
- All TBD items explicitly flagged and not invented
- Identity security configuration defaults seeded via idempotent migration
- Admin Portal Security Configuration section accessible to authorized admins
- Configuration API validated: unauthorized access rejected, invalid values rejected, changes audited
- HMAC-SHA256 IP protection verified across all E01 audit events
- Progressive lockout verified across multiple lockout episodes
- FIDO2/WebAuthn enrollment available for Admin actors

---

## 27. Dependencies on Platform Foundation

E01 requires all Platform Foundation outputs as pre-conditions:

| Platform Foundation Deliverable | E01 Usage |
|---|---|
| `identity` PostgreSQL schema (empty) | E01 creates all `identity.*` tables here |
| `platform.outbox_events` + `OutboxService` | All E01 integration events |
| `platform.audit_events` + `AuditService` | All E01 audit records |
| `platform.configuration_values` + `ConfigurationService` | All E01 policy values |
| `platform.idempotency_records` + `IdempotencyService` + `IdempotencyMiddleware` | Idempotent auth operations |
| Standard API envelope + error taxonomy | All E01 API responses |
| `X-Correlation-ID` middleware | Correlation ID in all E01 logs and events |
| Redis connection (Laravel configured) | Token revocation cache, rate limiting |
| Laravel Horizon | OTP cleanup jobs, session expiry jobs |
| Design system tokens and shared components | All E01 Flutter and Next.js screens |
| Canonical audit-action vocabulary (`docs/architecture/audit-action-vocabulary.md`) | E01 audit action strings must be registered here |
| Module boundary architecture (PSR-4, Laravel service provider pattern) | E01's `Identity` module structure |

---

## 28. Dependencies on Future Epics

| Epic | What E01 Needs from It |
|---|---|
| E02 User & Profile Management | E02 creates user profile on `Identity.User.Registered`; profile data not available until E02 |
| E03 Device & Session Management | E03 extends device management; E01 provides the base `identity.devices` model |
| E04 Roles, Permissions & Authorization | E04 seeds the role definitions that E01 references by ID; E01 must be aware of the default role ID seed |
| E17 Notifications | OTP delivery and auth alerts depend on E17; without E17, OTP delivery is stub/mock only |
| E18 Fraud, Risk & AML | Risk scoring for suspicious-login detection uses signals from E01 events |

---

## 29. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| OTP provider not selected (TBD-001) | High | High | Abstract behind `OtpDeliveryContract`; use stub/mock for test and development environments |
| GAP-027 step-up threshold TBD | High | Medium | Architecture supports configurable threshold via platform configuration; key reserved with `null` value |
| Biometric SDK fragmentation across iOS/Android (TBD-004) | Medium | Medium | Use Flutter abstraction layer; fallback to PIN/OTP if biometric unavailable |
| Refresh token reuse attack (token theft) | Medium | High | Rotation + all-session revocation on reuse detection; audit trail |
| Session expiry job failures | Low | Medium | Horizon monitoring; dead-letter handling; Platform Foundation outbox pattern |
| Performance under concurrent login load | Medium | Medium | Redis-backed token validation; connection pooling; load test before go-live |
| PIN/credential hash algorithm migration | Low | High | Design `identity.credentials.credential_hash` to support algorithm version prefix; migration path documented |
| E04 role seed not available at E01 registration time | Medium | Medium | E01 uses a well-known default role identifier constant; E04 must seed it before E01 is activated in production |

---

## 30. TBD / Open Questions

Items marked **RESOLVED** have been formally decided and incorporated into the spec. Items marked **OPEN** remain pending.

| ID | Status | Item | Resolution / Impact |
|---|---|---|---|
| GAP-027 | **RESOLVED** | Step-up authentication threshold | Default = `10000 BWP`. Keys: `auth.step_up_threshold = 10000`, `auth.step_up_threshold_currency = "BWP"`. Both runtime-configurable. Dual-trigger: amount OR risk-engine signal. |
| TBD-001 | **RESOLVED** | OTP provider selection | OTP delivery abstracted behind `OtpDeliveryContract`. Provider selected by `auth.otp.provider` configuration. Credentials stored as secrets. Stub used until concrete provider deployed. |
| TBD-002 | **RESOLVED** | Admin MFA method(s) | TOTP enabled by default (`auth.mfa.admin.totp_enabled = true`). FIDO2/WebAuthn enabled by default (`auth.mfa.admin.fido2_enabled = true`). SMS disabled by default (`auth.mfa.admin.sms_enabled = false`). |
| TBD-003 | **RESOLVED** | Access token signing algorithm | ES256 (ECDSA P-256, asymmetric). E01 is sole signing authority. Downstream services verify via cached JWKS. `kid` header required. Key rotation via overlapping JWKS entries. Step-up tokens ES256 with purpose-scoped `aud`. Private keys in secret manager. |
| TBD-004 | **OPEN** | Biometric assertion protocol details (platform-specific SDK) | Affects Flutter biometric binding implementation |
| TBD-005 | **RESOLVED** | Session inactivity TTL values | Admin = 900s (15 min), Customer = 1800s (30 min), Agent = 1800s (30 min). All runtime-configurable. |
| TBD-006 | **RESOLVED** | Refresh token TTL | Default = 2592000s (30 days). Rotation enabled. Reuse detection enabled. All runtime-configurable. |
| TBD-007 | **RESOLVED** | OTP TTL, retry limit, resend cooldown | TTL = 300s, max attempts = 5, resend cooldown = 60s, max sends/hour = 5. All runtime-configurable. |
| TBD-008 | **RESOLVED** | PIN complexity rules | Length = 6, reject sequential = true, reject repeated = true, reject common = true. All runtime-configurable with platform minimums enforced. |
| TBD-009 | **RESOLVED** | Account lockout threshold and duration | Max attempts = 5, initial duration = 900s, progressive = enabled. All runtime-configurable. |
| TBD-010 | **RESOLVED** | Default customer role identifier | Role code = `CUSTOMER`. E01 references by stable role code, not numeric DB ID. E04 must seed the role before E01 activates in production. |
| TBD-011 | **RESOLVED** | DR/RTO/RPO targets | RTO = 1 hour, RPO = 15 minutes. Operational targets only; documented in operational runbooks, not application config. |
| TBD-012 | **RESOLVED** | IP protection algorithm in audit records | `HMAC-SHA256(platform_secret, ip_address)`. Secret stored in platform secret manager, not in `platform.configuration_values`. Algorithm recorded in `security.ip_audit.hash_algorithm = "HMAC-SHA256"`. |

---

## Platform Alignment

- Backend API: Laravel / PHP 8.4+
- Admin/Web: Next.js + TypeScript
- Mobile: Flutter (Customer and Agent)
- DB: PostgreSQL (`identity` schema)
- Cache/Queue: Redis + Laravel Horizon
- API: REST/OpenAPI
- Design: shared token-driven design system
- State management: Riverpod (Flutter)
- No TBD values invented

---

## Epic Stories

### US-AUTH-001 — Register using mobile number (P0)
**Actor:** Unauthenticated new user (Customer or Agent)

**Acceptance Criteria:** User can submit a mobile number, receive an OTP, and upon verification have an active account with default role assigned. Duplicate mobile number returns an error without identifier disclosure. Invalid mobile format is rejected.

**API:** `POST /v1/auth/register` with `{ identifier_type: "mobile", mobile_number }`

**DB Entities:** `identity.users`, `identity.otp_challenges`

**Events:** `Identity.User.RegistrationOtpRequested`; `Identity.User.Registered` (on OTP verification)

**Permissions:** None (unauthenticated endpoint)

**UI Screens:** Customer: Enter mobile number screen (loading, success, error, duplicate-error states)

**QA Scenarios:** Happy-path mobile registration. | Invalid mobile format rejected. | Duplicate mobile returns generic error (no disclosure). | OTP sent and received. | Idempotency: same registration request twice → same pending state. | Audit event recorded.

**Definition of Done:** All criteria met; API, DB migration, OTP challenge, events, audit, and screen implemented and tested.

---

### US-AUTH-002 — Register using email (P1)
**Actor:** Unauthenticated new user

**Acceptance Criteria:** User can submit an email address, receive an OTP, and upon verification have an active account. Duplicate email returns error without disclosure.

**API:** `POST /v1/auth/register` with `{ identifier_type: "email", email }`

**DB Entities:** `identity.users`, `identity.otp_challenges`

**Events:** `Identity.User.RegistrationOtpRequested`; `Identity.User.Registered`

**Permissions:** None

**UI Screens:** Customer: Enter email screen (loading, success, error states)

**QA Scenarios:** Happy-path email registration. | Invalid email format rejected. | Duplicate email returns generic error. | OTP delivered. | Audit event recorded.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing.

---

### US-AUTH-003 — Verify registration OTP (P0)
**Actor:** Unauthenticated user in registration flow

**Acceptance Criteria:** User submits OTP; system verifies it against the unexpired, unverified challenge; account activated on success. Expired or invalid OTP rejected. OTP attempt count incremented on failure.

**API:** `POST /v1/auth/otp/verify` with `{ challenge_id, otp }`

**DB Entities:** `identity.otp_challenges`, `identity.users`

**Events:** `Identity.User.Registered` (on successful verification)

**Permissions:** None

**UI Screens:** Customer: OTP entry screen (digit input, auto-submit, expired/invalid error states)

**QA Scenarios:** Happy-path OTP verification. | Expired OTP rejected. | Invalid OTP rejected (attempt count incremented). | Already-verified OTP rejected. | Audit event on registration completion.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing.

---

### US-AUTH-004 — Resend registration OTP (P0)
**Actor:** Unauthenticated user in registration flow

**Acceptance Criteria:** User can request a new OTP within cooldown/retry constraints. Resend rejected if within cooldown. Previous OTP invalidated on resend.

**API:** `POST /v1/auth/otp/resend` with `{ challenge_id }`

**DB Entities:** `identity.otp_challenges`

**Events:** `Identity.User.RegistrationOtpResendRequested`

**Permissions:** None

**UI Screens:** Customer: Resend OTP CTA with cooldown countdown timer

**QA Scenarios:** Successful resend after cooldown. | Resend within cooldown → `OTP_COOLDOWN_ACTIVE` with `retry_after`. | Previous OTP invalidated after resend. | Resend count tracked.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing.

---

### US-AUTH-005 — Enforce OTP retry limit (P0)
**Actor:** System policy enforcement

**Acceptance Criteria:** When OTP verification attempts exceed the configured limit, the challenge is invalidated. User must request a new OTP. Limit sourced from platform configuration.

**API:** Policy enforced within OTP verification flow

**DB Entities:** `identity.otp_challenges` (attempt_count), `platform.configuration_values`

**Events:** None (internal policy enforcement)

**Permissions:** N/A

**UI Screens:** Customer: OTP entry — "Too many attempts, please request a new code" error state

**QA Scenarios:** N attempts with wrong OTP → challenge invalidated. | N+1 attempt on invalidated challenge → rejected. | Threshold sourced from configuration. | Test with different configured thresholds.

**Definition of Done:** Criteria + logic/test implemented and passing.

---

### US-AUTH-006 — Enforce OTP resend cooldown (P0)
**Actor:** System policy enforcement

**Acceptance Criteria:** Resend requests before the configured cooldown expires are rejected with the remaining wait time. Cooldown sourced from platform configuration.

**API:** Policy enforced within OTP resend flow

**DB Entities:** `identity.otp_challenges` (last_resent_at), `platform.configuration_values`

**Events:** None

**Permissions:** N/A

**UI Screens:** Customer: Cooldown timer display; resend button disabled during cooldown

**QA Scenarios:** Resend within cooldown → rejected with `retry_after`. | Resend after cooldown → accepted. | Cooldown sourced from configuration.

**Definition of Done:** Criteria + logic/test implemented and passing.

---

### US-AUTH-007 — Prevent duplicate account registration (P0)
**Actor:** System (enforced during registration)

**Acceptance Criteria:** If mobile/email already exists in `identity.users`, registration is rejected. Error response does not disclose that the identifier exists.

**API:** Enforced within `POST /v1/auth/register`

**DB Entities:** `identity.users` (UNIQUE constraint on `mobile_number`, `email`)

**Events:** None

**Permissions:** N/A

**UI Screens:** Customer: Generic error state on registration form

**QA Scenarios:** Registration with existing mobile → `IDENTIFIER_UNAVAILABLE` (no "already registered" disclosure). | Registration with existing email → same generic error. | DB unique constraint prevents bypass. | Concurrent registration race: exactly one succeeds.

**Definition of Done:** Criteria + constraint/test implemented and passing.

---

### US-AUTH-008 — Create default customer role (P0)
**Actor:** System (on registration completion)

**Acceptance Criteria:** On account activation, the system assigns the pre-seeded default customer role ID to `identity.users.default_role_id`. The role ID is a well-known constant coordinated with E04.

**API:** Internal operation within registration completion flow

**DB Entities:** `identity.users` (default_role_id)

**Events:** `Identity.User.Registered` (includes role_id in payload)

**Permissions:** N/A

**UI Screens:** None (internal operation)

**QA Scenarios:** After registration, user record has non-null `default_role_id`. | Role ID matches E04-seeded value. | Access token includes the role ID.

**Definition of Done:** Criteria + DB/event/test implemented and passing.

---

### US-AUTH-009 — Login using mobile and OTP (P0)
**Actor:** Customer or Agent

**Acceptance Criteria:** User submits mobile number, receives OTP, verifies OTP; system issues access + refresh tokens. Account must be active (not locked). New device triggers additional verification.

**API:** `POST /v1/auth/login` (OTP initiation); `POST /v1/auth/otp/verify` (OTP verification for login)

**DB Entities:** `identity.users`, `identity.otp_challenges`, `identity.sessions`, `identity.devices`

**Events:** `Identity.Session.Created`

**Permissions:** None (pre-auth)

**UI Screens:** Customer/Agent: Mobile number entry; OTP entry; loading/error states

**QA Scenarios:** Happy-path OTP login. | Locked account rejected. | Invalid OTP rejected. | New device triggers `DEVICE_VERIFICATION_REQUIRED`. | Audit event on session creation. | Concurrent OTP login: idempotent.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing.

---

### US-AUTH-010 — Login using PIN (P0)
**Actor:** Customer or Agent

**Acceptance Criteria:** User submits identifier and PIN; system verifies PIN hash; issues access + refresh tokens if valid. Blocked PIN rejected. Incorrect PIN increments failure count.

**API:** `POST /v1/auth/login` with `{ method: "pin", identifier, pin }`

**DB Entities:** `identity.users`, `identity.credentials` (type=pin), `identity.sessions`

**Events:** `Identity.Session.Created`

**Permissions:** None (pre-auth)

**UI Screens:** Customer/Agent: PIN entry keypad; loading/error states; locked-PIN error state

**QA Scenarios:** Happy-path PIN login. | Blocked PIN rejected. | Wrong PIN increments count. | Audit event on session creation.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing.

---

### US-AUTH-011 — Login using password (P1)
**Actor:** Admin, Enterprise, or Customer

**Acceptance Criteria:** User submits identifier and password; system verifies Argon2id hash; issues tokens. Invalid password increments failure count without identifier disclosure.

**API:** `POST /v1/auth/login` with `{ method: "password", identifier, password }`

**DB Entities:** `identity.users`, `identity.credentials` (type=password), `identity.sessions`

**Events:** `Identity.Session.Created`

**Permissions:** None (pre-auth)

**UI Screens:** Admin/Enterprise/Customer: Email+password form; loading/error states

**QA Scenarios:** Happy-path password login. | Wrong password → generic `INVALID_CREDENTIALS`. | Admin login triggers mandatory MFA after password validation. | Audit event.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing.

---

### US-AUTH-012 — Login using biometric authentication (P1)
**Actor:** Customer (mobile)

**Acceptance Criteria:** App performs local biometric verification; submits signed assertion to API; API validates assertion against stored public key; issues tokens. Biometric data never leaves device.

**API:** `POST /v1/auth/login/biometric` with `{ user_id, device_id, biometric_assertion }`

**DB Entities:** `identity.credentials` (type=biometric_binding), `identity.sessions`

**Events:** `Identity.Session.Created`

**Permissions:** None (pre-auth)

**UI Screens:** Customer: System biometric prompt (Face ID / Fingerprint); fallback to PIN/OTP on failure

**QA Scenarios:** Valid assertion → session created. | Invalid signature → rejected. | Unregistered device → rejected. | Biometric data verified not in logs/events. | Fallback to PIN on biometric failure.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing.

---

### US-AUTH-013 — Detect invalid credentials (P0)
**Actor:** System (enforced in all login flows)

**Acceptance Criteria:** Any failed credential verification returns `INVALID_CREDENTIALS` without disclosing whether the identifier exists. Failure recorded; failure count incremented.

**API:** Enforced within all login flows

**DB Entities:** `identity.users` (failed_attempt_count), `platform.audit_events`

**Events:** `Identity.Session.LoginFailed`

**Permissions:** N/A

**UI Screens:** Customer/Admin: Generic "Invalid credentials" error message

**QA Scenarios:** Wrong password → `INVALID_CREDENTIALS` (no user-existence disclosure). | Failure count incremented. | `Identity.Session.LoginFailed` emitted. | Audit event.

**Definition of Done:** Criteria + logic/event/test implemented and passing.

---

### US-AUTH-014 — Lock account after excessive failed attempts (P0)
**Actor:** System (policy enforcement)

**Acceptance Criteria:** When `failed_attempt_count` reaches the configured threshold, `identity.users.status` is set to `locked`. Subsequent authentication rejected regardless of credential. Threshold from platform configuration.

**API:** Enforced within login flow; `identity.account.locked` audit action

**DB Entities:** `identity.users` (status=locked, locked_at), `platform.configuration_values`

**Events:** `Identity.Account.Locked`

**Permissions:** N/A

**UI Screens:** Customer: Account locked screen with recovery CTA

**QA Scenarios:** N failed attempts → account locked. | Locked account rejects valid credentials. | Threshold from configuration. | `Identity.Account.Locked` emitted. | Audit event.

**Definition of Done:** Criteria + logic/event/audit/test implemented and passing.

---

### US-AUTH-015 — Notify user of suspicious login (P0)
**Actor:** System (security event emission)

**Acceptance Criteria:** When a suspicious login pattern is detected (e.g., new device), a security event is emitted for downstream notification delivery (E17).

**API:** Event-driven; no dedicated endpoint

**DB Entities:** `platform.outbox_events`, `platform.audit_events`

**Events:** `Identity.Session.Created` (with suspicious indicator in metadata)

**Permissions:** N/A

**UI Screens:** N/A (notification delivered by E17)

**QA Scenarios:** New device login → event with suspicious indicator emitted. | No false positives on known-device login. | E17 notification trigger verified by event assertion.

**Definition of Done:** Criteria + event emission/test implemented and passing.

---

### US-AUTH-016 — Detect new device login (P0)
**Actor:** System (login flow)

**Acceptance Criteria:** On each login, device fingerprint compared to known trusted devices. Unrecognized device → response signals `DEVICE_VERIFICATION_REQUIRED` with `challenge_id`.

**API:** Enforced within login flow; response includes `requires_device_verification: true, challenge_id`

**DB Entities:** `identity.devices`, `identity.otp_challenges`

**Events:** `Identity.Device.NewDeviceDetected`

**Permissions:** N/A

**UI Screens:** Customer: "New device detected" informational screen with verification CTA

**QA Scenarios:** Known device → no challenge. | New device → `DEVICE_VERIFICATION_REQUIRED`. | Event emitted. | Audit record.

**Definition of Done:** Criteria + API/event/test implemented and passing.

---

### US-AUTH-017 — Require additional verification for new device (P0)
**Actor:** User in new-device flow

**Acceptance Criteria:** User completes OTP verification for the new device. On success, device promoted to trusted and session created.

**API:** `POST /v1/auth/device/verify` with `{ challenge_id, otp }`

**DB Entities:** `identity.otp_challenges`, `identity.devices`, `identity.sessions`

**Events:** `Identity.Device.Verified`; `Identity.Session.Created`

**Permissions:** None (pre-session)

**UI Screens:** Customer: OTP entry for device verification; device trusted confirmation

**QA Scenarios:** Valid OTP → device trusted, session created. | Invalid OTP → rejected (attempt tracked). | Expired OTP → rejected. | Audit event.

**Definition of Done:** Criteria + API/DB/events/test implemented and passing.

---

### US-AUTH-018 — Issue access token (P0)
**Actor:** System (authentication completion)

**Acceptance Criteria:** On successful authentication, a short-lived signed access token is issued. Token contains `user_id`, `session_id`, `actor_type`, `roles`, `issued_at`, `expires_at`. TTL from platform configuration.

**API:** Token included in login success response

**DB Entities:** `identity.sessions`

**Events:** `Identity.Session.Created`

**Permissions:** N/A

**UI Screens:** N/A (token stored by app)

**QA Scenarios:** Token claims correct. | Token TTL matches configured value. | Expired token rejected. | Token signature verified.

**Definition of Done:** Criteria + token issuance/test implemented and passing.

---

### US-AUTH-019 — Issue refresh token (P0)
**Actor:** System (authentication completion)

**Acceptance Criteria:** On successful authentication, a refresh token issued alongside access token. Stored hashed in `identity.sessions`. TTL from platform configuration.

**API:** Refresh token included in login success response

**DB Entities:** `identity.sessions` (refresh_token_hash)

**Events:** Included in `Identity.Session.Created`

**Permissions:** N/A

**UI Screens:** N/A (token stored securely by app)

**QA Scenarios:** Refresh token issued with each successful login. | Raw refresh token not stored in DB (hash only). | Refresh token TTL matches configuration.

**Definition of Done:** Criteria + issuance/hash storage/test implemented and passing.

---

### US-AUTH-020 — Refresh expired access token (P0)
**Actor:** Authenticated user (via app)

**Acceptance Criteria:** Client presents valid, unexpired refresh token; system returns new access token and rotated refresh token. Expired refresh token → `REFRESH_TOKEN_EXPIRED`.

**API:** `POST /v1/auth/token/refresh` with `{ refresh_token }`

**DB Entities:** `identity.sessions`

**Events:** None (internal operation)

**Permissions:** Refresh token (no access token required)

**UI Screens:** Transparent to user (handled in app refresh interceptor)

**QA Scenarios:** Valid refresh token → new access + rotated refresh returned. | Expired refresh → `REFRESH_TOKEN_EXPIRED`. | Revoked refresh → `SESSION_REVOKED`. | Session last_activity_at updated.

**Definition of Done:** Criteria + API/DB/test implemented and passing.

---

### US-AUTH-021 — Rotate refresh token (P0)
**Actor:** System (enforced in token refresh flow)

**Acceptance Criteria:** Presented refresh token invalidated and new one issued atomically. Presentation of a previously rotated token triggers all-session revocation.

**API:** Enforced within token refresh flow

**DB Entities:** `identity.sessions`

**Events:** `Identity.Session.AllTerminated` (on reuse detection)

**Permissions:** N/A

**UI Screens:** N/A (transparent)

**QA Scenarios:** Rotation: old token rejected; new token accepted. | Reuse detection: second use of rotated token → all sessions revoked. | Concurrent refresh race: exactly one succeeds. | Audit event on reuse detection.

**Definition of Done:** Criteria + rotation/reuse-detection/test implemented and passing.

---

### US-AUTH-022 — Logout current session (P0)
**Actor:** Authenticated user

**Acceptance Criteria:** User requests logout of current session. Session status set to `logged_out`. Access and refresh tokens for this session immediately rejected.

**API:** `POST /v1/auth/logout`

**DB Entities:** `identity.sessions`

**Events:** `Identity.Session.Terminated`

**Permissions:** Authenticated (own session only)

**UI Screens:** Customer/Admin: Logout button; redirect to unauthenticated state

**QA Scenarios:** Successful logout → tokens rejected on next use. | Logout idempotent (double-logout returns success). | Audit event.

**Definition of Done:** Criteria + API/DB/event/test implemented and passing.

---

### US-AUTH-023 — Logout all sessions (P0)
**Actor:** Authenticated user

**Acceptance Criteria:** User requests logout of all active sessions across all devices. All sessions set to `logged_out`. All tokens immediately rejected.

**API:** `POST /v1/auth/logout/all`

**DB Entities:** `identity.sessions`

**Events:** `Identity.Session.AllTerminated`

**Permissions:** Authenticated (own sessions)

**UI Screens:** Customer: "Sign out everywhere" option; confirmation step

**QA Scenarios:** All sessions terminated. | Tokens from other sessions rejected. | Event emitted. | Audit event.

**Definition of Done:** Criteria + API/DB/event/test implemented and passing.

---

### US-AUTH-024 — Automatically expire inactive session (P0)
**Actor:** System (background job)

**Acceptance Criteria:** Sessions with `last_activity_at` older than configured inactivity TTL transitioned to `expired` by scheduled Horizon job. Tokens for expired sessions rejected.

**API:** Background job (no endpoint)

**DB Entities:** `identity.sessions`, `platform.configuration_values`

**Events:** `Identity.Session.Expired`

**Permissions:** N/A (system job)

**UI Screens:** Customer: Session expired → return to login with informative message

**QA Scenarios:** Session inactive beyond TTL → expired by job. | Token for expired session → `SESSION_EXPIRED`. | TTL from configuration. | Job idempotent.

**Definition of Done:** Criteria + job/event/test implemented and passing.

---

### US-AUTH-025 — Revoke compromised session (P0)
**Actor:** Admin

**Acceptance Criteria:** Admin can revoke any user session by session ID. Session status set to `revoked`. Tokens immediately rejected. Operation audited.

**API:** `POST /v1/auth/sessions/{id}/revoke`

**DB Entities:** `identity.sessions`, `platform.audit_events`

**Events:** `Identity.Session.Revoked`

**Permissions:** Admin actor with admin permission (enforced by E04 middleware)

**UI Screens:** Admin: Session management screen with revoke action

**QA Scenarios:** Admin revokes session → tokens rejected. | Non-admin cannot revoke another user's session. | Event emitted. | Audit event.

**Definition of Done:** Criteria + API/DB/event/audit/permission/test implemented and passing.

---

### US-AUTH-026 — View active sessions (P1)
**Actor:** Authenticated user

**Acceptance Criteria:** User retrieves list of their active sessions with device name, platform, last activity, session ID.

**API:** `GET /v1/auth/sessions`

**DB Entities:** `identity.sessions`, `identity.devices`

**Events:** None

**Permissions:** Authenticated (own sessions only)

**UI Screens:** Customer: Active sessions list; device name, platform, last-seen; revoke CTA per session

**QA Scenarios:** Returns only own sessions. | Expired/revoked sessions excluded or clearly marked. | Device metadata displayed.

**Definition of Done:** Criteria + API/DB/screen/test implemented and passing.

---

### US-AUTH-027 — Revoke individual device (P1)
**Actor:** Authenticated user

**Acceptance Criteria:** User revokes a specific session by session ID. Session terminated; tokens rejected.

**API:** `DELETE /v1/auth/sessions/{id}`

**DB Entities:** `identity.sessions`

**Events:** `Identity.Session.Terminated`

**Permissions:** Authenticated (own sessions only)

**UI Screens:** Customer: Revoke button per session in sessions list; confirmation dialog

**QA Scenarios:** Own session revoked → tokens rejected. | Cannot revoke other user's session (403). | Event emitted. | Audit event.

**Definition of Done:** Criteria + API/DB/event/test implemented and passing.

---

### US-AUTH-028 — Create transaction PIN (P0)
**Actor:** Authenticated user

**Acceptance Criteria:** Authenticated user creates a transaction PIN. Validated against configurable complexity rules. Stored as Argon2id hash. One active PIN per user.

**API:** `POST /v1/auth/pin`

**DB Entities:** `identity.credentials` (type=pin)

**Events:** `Identity.Credential.PinCreated`

**Permissions:** Authenticated (own credential)

**UI Screens:** Customer/Agent: PIN creation flow — enter PIN, confirm PIN; complexity guidance

**QA Scenarios:** Valid PIN → stored hashed. | Complexity violation → `PIN_COMPLEXITY_VIOLATION`. | Duplicate PIN creation rejected. | Raw PIN not in logs. | Audit event.

**Definition of Done:** Criteria + API/DB/event/security/test implemented and passing.

---

### US-AUTH-029 — Change transaction PIN (P0)
**Actor:** Authenticated user

**Acceptance Criteria:** Authenticated user submits current PIN and new PIN. Current PIN verified. New PIN validated for complexity. Old hash replaced atomically.

**API:** `PUT /v1/auth/pin`

**DB Entities:** `identity.credentials`

**Events:** `Identity.Credential.PinChanged`

**Permissions:** Authenticated (own credential)

**UI Screens:** Customer/Agent: Enter current PIN → enter new PIN → confirm new PIN

**QA Scenarios:** Valid change → new PIN accepted; old PIN rejected. | Wrong current PIN → rejected (failure count incremented). | New PIN complexity violation → rejected. | Audit event.

**Definition of Done:** Criteria + API/DB/event/security/test implemented and passing.

---

### US-AUTH-030 — Reset forgotten PIN (P0)
**Actor:** User initiating PIN recovery

**Acceptance Criteria:** User initiates PIN reset via OTP-verified flow. OTP sent to registered identifier. On OTP verification, user sets new PIN. PIN complexity enforced. Audited as security event.

**API:** `POST /v1/auth/pin/reset` with `{ challenge_id, otp, new_pin }`

**DB Entities:** `identity.otp_challenges`, `identity.credentials`

**Events:** `Identity.Credential.PinReset`

**Permissions:** OTP-verified partial authentication (no full session required)

**UI Screens:** Customer/Agent: "Forgot PIN" flow → OTP entry → set new PIN

**QA Scenarios:** Valid OTP + valid PIN → PIN reset. | Invalid OTP → rejected. | Complexity violation → rejected. | Audit event (WARN severity).

**Definition of Done:** Criteria + API/DB/event/security/test implemented and passing.

---

### US-AUTH-031 — Enforce PIN complexity (P0)
**Actor:** System (policy enforcement)

**Acceptance Criteria:** PIN complexity rules sourced from platform configuration and enforced during PIN creation and change. Rules are NOT hard-coded.

**API:** Enforced within PIN creation/change flows

**DB Entities:** `platform.configuration_values`

**Events:** None

**Permissions:** N/A

**UI Screens:** Customer: Inline complexity guidance and error messages

**QA Scenarios:** Short PIN → rejected. | Sequential digits PIN → rejected per config rule. | All-same-digit PIN → rejected per config rule. | Rules sourced from configuration.

**Definition of Done:** Criteria + validation logic/configuration/test implemented and passing.

---

### US-AUTH-032 — Block PIN after repeated failures (P0)
**Actor:** System (policy enforcement in PIN verification)

**Acceptance Criteria:** After configured number of consecutive failed PIN verifications, PIN credential set to `blocked`. Subsequent PIN use rejected until unblocked. Threshold from platform configuration.

**API:** Enforced within PIN verification (login, step-up)

**DB Entities:** `identity.credentials` (status=blocked, failed_attempt_count)

**Events:** `Identity.Credential.PinBlocked`

**Permissions:** N/A

**UI Screens:** Customer/Agent: PIN blocked screen with recovery CTA

**QA Scenarios:** N failures → PIN blocked. | Blocked PIN → `PIN_BLOCKED`. | Threshold from configuration. | Event emitted. | Audit event.

**Definition of Done:** Criteria + logic/event/test implemented and passing.

---

### US-AUTH-033 — Unlock PIN through verified recovery (P1)
**Actor:** User initiating PIN unblock

**Acceptance Criteria:** User initiates unblock via OTP recovery flow. OTP verified. PIN credential status reset to `active`; failure count reset to 0.

**API:** `POST /v1/auth/pin/unblock` with `{ challenge_id, otp }`

**DB Entities:** `identity.otp_challenges`, `identity.credentials`

**Events:** `Identity.Credential.PinUnblocked`

**Permissions:** OTP-verified partial authentication

**UI Screens:** Customer/Agent: "Unblock PIN" screen; OTP entry

**QA Scenarios:** Valid OTP → PIN unblocked. | Invalid OTP → rejected. | Failure count reset on unblock. | Audit event.

**Definition of Done:** Criteria + API/DB/event/test implemented and passing.

---

### US-AUTH-034 — Enable MFA (P1)
**Actor:** Authenticated user

**Acceptance Criteria:** Authenticated user initiates MFA enrollment. System issues enrollment challenge. User completes challenge. MFA credential stored. MFA method TBD-002.

**API:** `POST /v1/auth/mfa/enable`

**DB Entities:** `identity.credentials` (type=mfa_totp)

**Events:** `Identity.MFA.Enabled`

**Permissions:** Authenticated (own MFA)

**UI Screens:** Customer/Admin: MFA setup flow — setup instructions; verification step

**QA Scenarios:** Successful enrollment → MFA flag active. | Enrollment challenge verification required. | Cannot enable if already enabled. | Audit event.

**Definition of Done:** Criteria + API/DB/event/test implemented and passing.

---

### US-AUTH-035 — Disable MFA (P1)
**Actor:** Authenticated user

**Acceptance Criteria:** Authenticated user can disable MFA subject to policy. MFA credential revoked. Subsequent logins no longer require MFA challenge.

**API:** `POST /v1/auth/mfa/disable`

**DB Entities:** `identity.credentials` (status=revoked for mfa credential)

**Events:** `Identity.MFA.Disabled`

**Permissions:** Authenticated (own MFA; Admin may have policy restriction)

**UI Screens:** Customer: Disable MFA option with confirmation

**QA Scenarios:** User disables MFA → subsequent login skips MFA challenge. | Admin MFA disable blocked by policy (if configured). | Audit event (WARN severity).

**Definition of Done:** Criteria + API/DB/event/test implemented and passing.

---

### US-AUTH-036 — Verify MFA challenge (P1)
**Actor:** User in MFA-pending session

**Acceptance Criteria:** System issues MFA challenge after primary credential verification when MFA is enabled. User submits code. Successful verification transitions session from `mfa_pending` to `active`. Failed attempts tracked; excessive failures produce lockout per configurable threshold.

**API:** `POST /v1/auth/mfa/verify` with `{ session_id, mfa_code }`

**DB Entities:** `identity.sessions`, `identity.credentials`

**Events:** `Identity.MFA.ChallengeVerified`

**Permissions:** Partial session (mfa_pending state)

**UI Screens:** Customer/Admin: MFA code entry screen; countdown timer; error state

**QA Scenarios:** Valid code → session activated. | Invalid code → failure tracked. | Expired code → rejected. | Excessive failures → session revoked. | Audit event.

**Definition of Done:** Criteria + API/DB/event/test implemented and passing.

---

### US-AUTH-037 — Require MFA for high-risk transaction (P0)
**Actor:** Risk engine / downstream domain

**Acceptance Criteria:** When a downstream domain signals step-up is required, E01 evaluates whether the current session has a valid step-up claim. If not, returns `STEP_UP_REQUIRED`. The risk threshold is governed by GAP-027 — **threshold value is TBD**. The `auth.step_up_threshold` configuration key exists with `null` value until resolved.

**API:** `POST /v1/auth/step-up` (MFA challenge); response: step-up token

**DB Entities:** `identity.sessions` (step_up_verified_at, step_up_scope), `platform.configuration_values`

**Events:** `Identity.MFA.StepUpRequired`; `Identity.MFA.StepUpCompleted`

**Permissions:** Authenticated user (requested by downstream domain)

**UI Screens:** Customer: Step-up MFA overlay on top of active operation screen

**QA Scenarios:** No step-up claim → `STEP_UP_REQUIRED`. | Valid step-up → operation proceeds. | Step-up token TTL enforced. | Threshold from config (null/TBD handled gracefully). | Audit event.

**Definition of Done:** Criteria + API/DB/event/test implemented and passing. GAP-027 TBD acknowledged; threshold stored as null.

---

### US-AUTH-038 — Require MFA for privileged administration (P0)
**Actor:** Admin actor performing privileged operations

**Acceptance Criteria:** Privileged admin operations require step-up MFA. Step-up requirement triggered by E04 permission evaluation. E01 provides the step-up flow; E04 defines which operations are privileged.

**API:** `POST /v1/auth/step-up` (same endpoint as US-AUTH-037; scope `admin.privileged`)

**DB Entities:** `identity.sessions` (step_up_verified_at, step_up_scope='admin.privileged')

**Events:** `Identity.MFA.StepUpRequired`; `Identity.MFA.StepUpCompleted`

**Permissions:** Admin actor

**UI Screens:** Admin: Step-up MFA prompt overlay for privileged operations

**QA Scenarios:** Privileged operation without step-up → `STEP_UP_REQUIRED`. | After step-up → operation proceeds. | Non-admin cannot obtain admin step-up scope. | Audit event.

**Definition of Done:** Criteria + API/DB/event/test implemented and passing.

---

## Epic-level Definition of Done
- All 38 stories meet their story-specific Definition of Done
- Full E2E authentication flow passes: registration → OTP verification → login → token refresh → step-up → logout
- Cross-story tests pass: lockout → recovery; new device → verification → session; refresh rotation → reuse detection
- All audit events recorded in `platform.audit_events` with correct action strings
- All domain events published via `platform.outbox_events`
- No plaintext credentials (PIN, password, OTP, token) in any log, event, or API response
- All configurable policy values sourced from `platform.configuration_values`; none hard-coded
- Test coverage ≥ 80% for the Identity module
- Flutter screens: loading, success, error, and security states implemented for all applicable stories
- Admin Next.js flows implemented for applicable stories
- OpenAPI documentation published and validated
- WCAG AA accessibility requirements met for all screens
- No unresolved CRITICAL or HIGH defects
- All TBD items explicitly preserved; none invented
