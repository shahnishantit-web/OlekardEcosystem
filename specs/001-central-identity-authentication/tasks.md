# Tasks: E01 — Central Identity & Authentication

**Feature**: `001-central-identity-authentication` | **Epic**: E01 | **Generated**: 2026-08-21

**Authoritative artifacts**: spec.md, plan.md, research.md, data-model.md, contracts/, quickstart.md

**TBD-004** (biometric SDK), concurrent production VU count, service token lifetime/scope/credential mechanism, and production secret-management provider remain TBD — no tasks may invent values for these.

**GAP-027 is RESOLVED**: `auth.step_up_threshold = 10000 BWP` (runtime-configurable seed). MUST NOT be hard-coded in domain logic.

## Format: `- [ ] T### [P?] [Story?] Description`

- **[P]**: Can run in parallel with other [P] tasks in the same phase (different files, no dependencies)
- File paths are relative to repo root

## User Story Groupings

- **US1**: Registration (US-AUTH-001 to US-AUTH-008, FR-E01-001 to FR-E01-008)
- **US2**: Authentication / Login (US-AUTH-009 to US-AUTH-015, FR-E01-009 to FR-E01-015)
- **US3**: New Device Detection & Verification (US-AUTH-016 to US-AUTH-017, FR-E01-016 to FR-E01-017)
- **US4**: Token & Session Lifecycle (US-AUTH-018 to US-AUTH-027, FR-E01-018 to FR-E01-027)
- **US5**: Admin Session Revocation (US-AUTH-025, FR-E01-025)
- **US6**: PIN & Credential Management (US-AUTH-028 to US-AUTH-033, FR-E01-028 to FR-E01-033)
- **US7**: MFA (US-AUTH-034 to US-AUTH-036, FR-E01-034 to FR-E01-036)
- **US8**: Step-Up Authentication (US-AUTH-037 to US-AUTH-038, FR-E01-037 to FR-E01-038, FR-E01-046 to FR-E01-047)
- **US9**: Service Identity (spec Section 10.5, FR-E01 service token design)
- **US10**: Configuration Management (FR-E01-039 to FR-E01-052)
- **US11**: Flutter Customer UI (spec Section 18.1, 19.1)
- **US12**: Flutter Agent UI (spec Section 18.2, 19.2)
- **US13**: Next.js Admin UI (spec Section 20)

---

## Phase 1: Setup & Module Scaffolding

**Purpose**: Establish module skeleton, Composer dependencies, and service provider wiring. All subsequent phases depend on this phase being complete.

- [X] T001 Create Identity module directory tree under `services/api/app/Modules/Identity/` with subdirectories: Domain/Contracts, Domain/Entities, Domain/Services, Domain/Events, Domain/Exceptions, Domain/ValueObjects, Application/Commands, Application/Queries, Application/DTOs, Infrastructure/Models, Infrastructure/Repositories, Infrastructure/Http/Controllers, Infrastructure/Http/Requests, Infrastructure/Http/Resources, Infrastructure/Migrations, Infrastructure/Jobs, Infrastructure/Jwks, Infrastructure/Stubs, Infrastructure/Providers, Tests/Unit, Tests/Integration, Tests/Feature, Tests/Security, Tests/Performance (plan.md Module Structure; cross-cutting)
- [X] T002 [P] Add `lcobucci/jwt`, `web-auth/webauthn-framework`, and `chillerlan/php-authenticator` to `services/api/composer.json` (FR-E01-018, FR-E01-034, FR-E01-049; research.md Decision 1)
- [X] T003 [P] Create `services/api/app/Modules/Identity/Infrastructure/Providers/IdentityServiceProvider.php` — registers module bindings, command handlers, middleware, and routes; auto-discovered by Laravel (cross-cutting)
- [X] T004 [P] Register `IdentityServiceProvider` in `services/api/config/app.php` providers array (cross-cutting)
- [X] T005 [P] Create `services/api/app/Modules/Identity/Domain/ValueObjects/ActorType.php` — PHP 8.4 backed enum with cases USER, SERVICE, SYSTEM (spec Section 5; cross-cutting)
- [X] T006 [P] Create `services/api/app/Modules/Identity/Domain/ValueObjects/CredentialType.php` — enum: pin, password, biometric_binding, mfa_totp, mfa_fido2 (cross-cutting)
- [X] T007 [P] Create `services/api/app/Modules/Identity/Domain/ValueObjects/SessionStatus.php` — enum: active, mfa_pending, step_up_pending, expired, revoked, logged_out (cross-cutting)
- [X] T008 [P] Create `services/api/app/Modules/Identity/Domain/ValueObjects/OtpChallengeType.php` — enum: registration, login, device_verification, pin_reset, pin_unblock, mfa_enrollment, step_up (cross-cutting)
- [X] T009 [P] Create all domain exception classes in `services/api/app/Modules/Identity/Domain/Exceptions/`: InvalidCredentialsException, AccountLockedException, OtpExpiredException, OtpAttemptExceededException, DeviceVerificationRequiredException, StepUpRequiredException, RefreshTokenReuseException, PinComplexityException, PinBlockedException, ConfigurationConflictException (cross-cutting)
- [X] T010 Create named database connection `identity` in `services/api/config/database.php` pointing to the same PostgreSQL instance as the `platform` connection (ADR-002; cross-cutting)
- [X] T011 [P] Create E01 route file `services/api/routes/identity.php`; register it in `IdentityServiceProvider::boot()` (cross-cutting)
- [X] T012 [P] Configure Redis key namespace for identity (`identity:*` prefix) in `services/api/config/cache.php` or the Redis client configuration (cross-cutting)

---

## Phase 2: Domain Contracts

**Purpose**: Define all abstract interfaces before any concrete implementation. Preserves TBD abstraction boundaries for OTP provider, biometric SDK (TBD-004), and JWKS provider.

- [X] T013 Create `services/api/app/Modules/Identity/Domain/Contracts/OtpDeliveryContract.php` — interface matching contracts/module-contracts.md: `deliver(string $destination, string $otp, string $channelType, string $correlationId): OtpDeliveryResult` (FR-E01-052; spec Section 22.2)
- [X] T014 [P] Create `services/api/app/Modules/Identity/Domain/Contracts/OtpDeliveryResult.php` — readonly DTO: delivered, providerReference, failureReason (FR-E01-052)
- [X] T015 [P] Create `services/api/app/Modules/Identity/Domain/Contracts/BiometricAssertionContract.php` — interface with `verify()` and `validateEnrollment()` per contracts/module-contracts.md; TBD-004 abstraction boundary preserved; do NOT choose a biometric SDK (FR-E01-012)
- [X] T016 [P] Create `services/api/app/Modules/Identity/Domain/Contracts/BiometricVerificationResult.php` and `BiometricEnrollmentResult.php` readonly DTOs (FR-E01-012)
- [X] T017 [P] Create `services/api/app/Modules/Identity/Domain/Contracts/JwksProviderContract.php` — interface: `getSigningKey(): JwkSigningKey`, `getVerificationKeys(): array`, `getCurrentKid(): string` per contracts/module-contracts.md (FR-E01-018; research.md Decision 8)
- [X] T018 [P] Create `services/api/app/Modules/Identity/Domain/Contracts/JwkSigningKey.php` and `JwkPublicKey.php` readonly DTOs; `privateKeyPem` MUST NOT be serialized to any response or log (FR-E01-018)
- [X] T019 [P] Create `services/api/app/Modules/Identity/Domain/Contracts/IdentityModuleContract.php` — public cross-module surface per contracts/module-contracts.md: `resolveActorFromToken()`, `resolveServiceActor()`, `verifyStepUpScope()`, `revokeSession()` (cross-cutting; FR-E01-025)
- [X] T020 [P] Create `services/api/app/Modules/Identity/Domain/Contracts/AuthenticatedActor.php` and `ServiceActor.php` readonly DTOs (cross-cutting)
- [X] T021 Create `services/api/app/Modules/Identity/Infrastructure/Stubs/StubOtpDeliveryProvider.php` — logs OTP at DEBUG level only; MUST NOT appear at INFO or above; used in dev/test only (FR-E01-052; BR-002)
- [X] T022 [P] Create `services/api/app/Modules/Identity/Infrastructure/Stubs/StubBiometricAssertionProvider.php` — returns verified=true for test payloads; TBD-004 boundary preserved (FR-E01-012)
- [X] T023 Create `services/api/app/Modules/Identity/Infrastructure/Jwks/LocalJwksProvider.php` — reads `IDENTITY_JWT_PRIVATE_KEY` and optionally `IDENTITY_JWT_OLD_PUBLIC_KEY` from env; derives kid as first 12 hex chars of SHA-256(DER-encoded public key) per jwks-api.md (FR-E01-018; research.md Decision 8)
- [X] T024 Bind contracts in `IdentityServiceProvider`: OtpDeliveryContract→StubOtpDeliveryProvider (non-prod), JwksProviderContract→LocalJwksProvider, BiometricAssertionContract→StubBiometricAssertionProvider (cross-cutting)

---

## Phase 3: Identity Schema & Persistence

**Purpose**: Create the `identity` schema and all 5 tables via migrations; create Eloquent models and repositories. All domain service phases depend on this.

- [X] T025 Create migration `services/api/app/Modules/Identity/Infrastructure/Migrations/2026_xx_xx_001_create_identity_users_table.php` — `identity.users` table per data-model.md: UUID v7 PK, mobile_number, email, status enum, default_role_id, actor_type, lock_reason, locked_at, lockout_episode_count, failed_attempt_count, last_login_at, timestamps; PARTIAL UNIQUE INDEX on mobile_number WHERE NOT NULL; PARTIAL UNIQUE INDEX on email WHERE NOT NULL; index on status; `$connection = 'identity'` (FR-E01-001, FR-E01-002, FR-E01-007, FR-E01-014; data-model.md)
- [X] T026 [P] Create migration `2026_xx_xx_002_create_identity_credentials_table.php` — `identity.credentials` per data-model.md: UUID v7 PK, user_id FK→users, credential_type, credential_hash, biometric_public_key, fido2_credential_id, fido2_public_key, fido2_sign_count, status, failed_attempt_count, expires_at, timestamps; UNIQUE(user_id, credential_type); partial index on fido2_credential_id; `$connection = 'identity'` (FR-E01-010, FR-E01-012, FR-E01-028, FR-E01-034; data-model.md)
- [X] T027 [P] Create migration `2026_xx_xx_003_create_identity_sessions_table.php` — `identity.sessions` per data-model.md: UUID v7 PK, user_id FK, device_id FK nullable, refresh_token_hash (SHA-256 hex), refresh_token_family_id, status, actor_type, step_up_verified_at, step_up_scope, last_activity_at, expires_at, ip_hash (HMAC-SHA256 only — no raw IP), user_agent, timestamps; UNIQUE(refresh_token_hash); indexes on user_id, (user_id, status), (status, updated_at), refresh_token_family_id; `$connection = 'identity'` (FR-E01-018, FR-E01-019, FR-E01-021; data-model.md)
- [X] T028 [P] Create migration `2026_xx_xx_004_create_identity_devices_table.php` — `identity.devices` per data-model.md: UUID v7 PK, user_id FK, device_fingerprint (SHA-256 only), device_name, platform, status, trusted_at, last_seen_at, timestamps; UNIQUE(user_id, device_fingerprint); index on user_id; `$connection = 'identity'` (FR-E01-016, FR-E01-017; data-model.md)
- [X] T029 [P] Create migration `2026_xx_xx_005_create_identity_otp_challenges_table.php` — `identity.otp_challenges` per data-model.md: UUID v7 PK, user_id nullable FK, challenge_type, identifier, otp_hash (SHA-256 only — NEVER plaintext OTP), status, attempt_count, resend_count, last_resent_at, expires_at, timestamps; CHECK constraint allowing null user_id for registration challenges; indexes on user_id, (identifier, challenge_type, status), (status, expires_at); `$connection = 'identity'` (FR-E01-003 to FR-E01-006; data-model.md)
- [X] T030 Verify all 5 migrations declare `$connection = 'identity'`; migrations MUST NOT use the default or `platform` connection for identity.* tables (ADR-002, ADR-003)
- [X] T031 Create `services/api/app/Modules/Identity/Infrastructure/Models/User.php` — Eloquent model for `identity.users`; `$connection = 'identity'`; `$table = 'identity.users'`; UUID v7 PK generation; fillable, casts, relations to Credential, Session, Device, OtpChallenge (FR-E01-001)
- [X] T032 [P] Create `services/api/app/Modules/Identity/Infrastructure/Models/Credential.php` — `$connection = 'identity'`; `$table = 'identity.credentials'`; `credential_hash` in `$hidden` (never serialized to JSON); belongsTo User (FR-E01-010)
- [X] T033 [P] Create `services/api/app/Modules/Identity/Infrastructure/Models/Session.php` — `$connection = 'identity'`; `$table = 'identity.sessions'`; `refresh_token_hash` in `$hidden`; `ip_hash` stores HMAC-SHA256 only — raw IP MUST NOT be persisted (FR-E01-018; BR-027)
- [X] T034 [P] Create `services/api/app/Modules/Identity/Infrastructure/Models/Device.php` — `$connection = 'identity'`; `$table = 'identity.devices'`; `device_fingerprint` stores SHA-256 hash only; belongsTo User (FR-E01-016)
- [X] T035 [P] Create `services/api/app/Modules/Identity/Infrastructure/Models/OtpChallenge.php` — `$connection = 'identity'`; `$table = 'identity.otp_challenges'`; `otp_hash` in `$hidden` (never serialized); raw OTP MUST NOT be persisted in any column (FR-E01-003; BR-002)
- [X] T036 Create repository interfaces in `services/api/app/Modules/Identity/Domain/Contracts/`: UserRepositoryInterface, CredentialRepositoryInterface, SessionRepositoryInterface, DeviceRepositoryInterface, OtpChallengeRepositoryInterface; bind in IdentityServiceProvider (cross-cutting)
- [X] T037 Create `services/api/app/Modules/Identity/Infrastructure/Repositories/EloquentUserRepository.php` — implements UserRepositoryInterface; methods: findByMobile, findByEmail, findById, create, updateStatus, incrementFailedAttempts, resetFailedAttempts, incrementLockoutEpisode (FR-E01-001, FR-E01-007, FR-E01-014)
- [X] T038 [P] Create `services/api/app/Modules/Identity/Infrastructure/Repositories/EloquentCredentialRepository.php` — implements CredentialRepositoryInterface; methods: findByUserAndType, create, updateHash, updateStatus, incrementFailed (FR-E01-010, FR-E01-028)
- [X] T039 [P] Create `services/api/app/Modules/Identity/Infrastructure/Repositories/EloquentSessionRepository.php` — implements SessionRepositoryInterface; methods: create, findByRefreshHash, findByFamilyId, updateStatus, revokeAllForUser, revokeFamily, listActiveForUser (FR-E01-018, FR-E01-021)
- [X] T040 [P] Create `services/api/app/Modules/Identity/Infrastructure/Repositories/EloquentDeviceRepository.php` — implements DeviceRepositoryInterface; methods: findByUserAndFingerprint, create, updateStatus, listForUser (FR-E01-016)
- [X] T041 [P] Create `services/api/app/Modules/Identity/Infrastructure/Repositories/EloquentOtpChallengeRepository.php` — implements OtpChallengeRepositoryInterface; methods: create, findActive, invalidateExisting, verifyAndConsume (FR-E01-003)
- [X] T042 Write unit tests in `services/api/app/Modules/Identity/Tests/Unit/Models/` verifying: credential_hash absent from JSON serialization; otp_hash absent from serialization; ip_hash is not a raw IP address format; UUID v7 PK auto-generation (BR-002, BR-004, BR-027; data-model.md)
- [X] T043 [P] Write integration tests in `services/api/app/Modules/Identity/Tests/Integration/Repositories/` for each repository: create, find, update, unique constraint enforcement; all queries use `identity` DB connection (cross-cutting)

---

## Phase 4: Configuration Seed & Security Service

**Purpose**: Seed the 25 configuration defaults idempotently. Establish the ConfigurationSecurityService that all subsequent domain services consume.

- [X] T044 Create migration `2026_xx_xx_006_seed_identity_configuration_defaults.php` using `DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([...])` for all 25 keys from spec Section 8.1 with their default values; seed MUST NOT overwrite existing values; connection is `platform` not `identity` (FR-E01-039; BR-017; AC-021; research.md Decision 12)
- [X] T045 Create `services/api/app/Modules/Identity/Domain/Services/ConfigurationSecurityService.php` — reads all 25 identity config keys via Platform Foundation `ConfigurationServiceInterface`; exposes typed getters (getOtpTtl, getLockoutThreshold, getPinLength, getStepUpThreshold, getStepUpThresholdCurrency, etc.); NO direct SQL to `platform.configuration_values` from domain (FR-E01-040; BR-018)
- [X] T046 [P] Implement security minimum enforcement in ConfigurationSecurityService: validate proposed values against platform minimums from spec Section 8.1 before accepting updates; reject values below minimum by throwing ConfigurationConflictException (FR-E01-043; BR-020; AC-024)
- [X] T047 Write feature tests in `services/api/app/Modules/Identity/Tests/Feature/Configuration/ConfigurationSeedTest.php`: all 25 keys seeded on fresh install; re-running migration produces no changes (idempotency); key count equals 25; no existing value overwritten (FR-E01-039; AC-021)
- [X] T048 [P] Write unit tests for ConfigurationSecurityService: each getter returns correctly typed value; security minimum enforcement rejects values below threshold; PIN length cannot be set below 6 (FR-E01-043; AC-024)

---

## Phase 5: Registration & OTP (US1)

**Goal**: A new user can register via mobile number or email, receive an OTP, verify it, and be assigned the default customer role.

**Independent test**: `POST /v1/auth/register` → 202; `POST /v1/auth/otp/verify` → 200 with setup_token; `identity.users.status` = pending_verification; `Identity.User.Registered` in `platform.outbox_events`.

- [X] T049 [US1] Create `services/api/app/Modules/Identity/Domain/Services/OtpService.php` — generates CSPRNG OTP via `random_int()`; hashes via `hash('sha256', $otp)` before storing in `identity.otp_challenges.otp_hash`; invalidates any existing pending challenge of same type+identifier before creating new one; enforces max_sends_per_hour via Redis counter `identity:otp_rate:{identifier}`; all policy values from ConfigurationSecurityService (FR-E01-003 to FR-E01-006; BR-002, BR-003)
- [X] T050 [P] [US1] Create `services/api/app/Modules/Identity/Domain/Services/RegistrationService.php` — validates identifier uniqueness without disclosing existing registrations; creates `identity.users` with status=pending_verification; calls OtpService; assigns default_role_id from config (role code CUSTOMER, not hard-coded numeric ID); emits `Identity.User.Registered` via Platform Foundation EventPublisher (FR-E01-001, FR-E01-002, FR-E01-007, FR-E01-008; AC-001)
- [X] T051 [US1] Create application command classes `services/api/app/Modules/Identity/Application/Commands/RegisterUserCommand.php`, `VerifyOtpCommand.php`, `ResendOtpCommand.php` — thin command objects; validation belongs in Form Requests only (FR-E01-001, FR-E01-003, FR-E01-004)
- [X] T052 [US1] Create controller actions `register()`, `verifyOtp()`, `resendOtp()` in `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/AuthController.php`; delegate to RegistrationService/OtpService; apply Platform Foundation `IdempotencyServiceInterface` on state-changing operations (FR-E01-001, FR-E01-003, FR-E01-004)
- [X] T053 [P] [US1] Create Form Request classes in `services/api/app/Modules/Identity/Infrastructure/Http/Requests/`: `RegisterRequest.php`, `VerifyOtpRequest.php`, `ResendOtpRequest.php` — validation rules only, no business logic (FR-E01-001, FR-E01-003)
- [X] T054 [US1] Create API Resource classes `RegistrationResponseResource.php`, `OtpVerifyResponseResource.php` in `Infrastructure/Http/Resources/` — MUST NOT expose OTP value in any field; use Platform Foundation response envelope format (BR-002; AC-020)
- [X] T055 [US1] Register routes in `routes/identity.php`: `POST /v1/auth/register`, `POST /v1/auth/otp/verify`, `POST /v1/auth/otp/resend`; apply correlation ID middleware and idempotency middleware (FR-E01-001, FR-E01-003, FR-E01-004)
- [X] T056 [US1] Implement OTP verification in OtpService: hash incoming OTP → compare with stored hash; enforce attempt_count limit from config; mark challenge as verified/expired/invalidated; creating a new challenge for same type+identifier invalidates any pending one (FR-E01-005; BR-002; AC-002)
- [X] T057 [US1] Emit audit events via Platform Foundation AuditService: `identity.user.registered` (after OTP verify), `identity.otp.sent` (on delivery); ip_hash = HMAC-SHA256(platform_secret, ip); mobile/email masked in payload (FR-E01-003; BR-027; AC-015, AC-026)
- [X] T058 [P] [US1] Write feature tests in `services/api/app/Modules/Identity/Tests/Feature/Registration/`: registration + OTP happy path; duplicate mobile returns 409 without identifier disclosure (AC-003); OTP expires after TTL (AC-002); resend enforces cooldown; max_sends_per_hour enforced; idempotent duplicate request (same X-Idempotency-Key) returns same result; audit event in platform.audit_events; outbox event in platform.outbox_events (AC-015, AC-016)
- [X] T059 [P] [US1] Write security tests in `services/api/app/Modules/Identity/Tests/Security/Registration/`: OTP not in response body; OTP not in any log line; OTP not in audit payload; plaintext OTP absent from database; duplicate registration disclosure prevention (BR-002, BR-014)
- [X] T060 [P] [US1] Write concurrency test: concurrent OTP verification for same challenge → exactly one succeeds; second returns error (spec Section 24.3)

---

## Phase 6: Authentication / Login (US2)

**Goal**: Registered users authenticate via OTP, PIN, password, or biometric; invalid credentials rejected without identifier disclosure; account lockout is progressive.

**Independent test**: Login with correct PIN → 200 with access_token + refresh_token; wrong PIN N times → 423 IDENTITY_004; no identifier disclosed in error body.

- [X] T061 [US2] Create `services/api/app/Modules/Identity/Domain/Services/LockoutService.php` — reads `auth.lockout.max_failed_attempts`, `auth.lockout.initial_duration`, `auth.lockout.progressive_enabled` from ConfigurationSecurityService; progressive duration = initial_duration × (2^(episode_count−1)); increments lockout_episode_count; sets locked_at; never hard-codes lockout threshold (FR-E01-014, FR-E01-048; BR-006, BR-025; AC-007, AC-027)
- [X] T062 [P] [US2] Create `services/api/app/Modules/Identity/Domain/Services/DeviceService.php` — computes device_fingerprint = SHA-256(clientFingerprint); queries identity.devices for user+fingerprint+status=trusted; detects new devices; emits `Identity.Device.NewDeviceDetected` event when new device found (FR-E01-016; AC-009)
- [X] T063 [US2] Create `services/api/app/Modules/Identity/Domain/Services/AuthenticationService.php` — orchestrates all login credential types; calls LockoutService on failure; calls DeviceService on each login; credential verification: PIN (Argon2id via `Hash::check()`), password (Argon2id), OTP (SHA-256 compare), biometric (via BiometricAssertionContract — TBD-004); NEVER discloses which identifier or credential failed (FR-E01-009 to FR-E01-013; BR-014; AC-004, AC-008)
- [X] T064 [P] [US2] Create command classes `LoginWithOtpCommand.php`, `LoginWithPinCommand.php`, `LoginWithPasswordCommand.php`, `LoginWithBiometricCommand.php` in `Application/Commands/` (FR-E01-009 to FR-E01-012)
- [X] T065 [US2] Add `login()` action to `AuthController.php`; delegate to AuthenticationService; idempotency applied; return Platform Foundation response envelope (FR-E01-009 to FR-E01-013)
- [X] T066 [P] [US2] Create `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/BiometricController.php` — `challenge()` generates nonce stored in Redis with short TTL; `verify()` submits signed assertion via BiometricAssertionContract (FR-E01-012)
- [X] T067 [US2] Register routes: `POST /v1/auth/login`, `POST /v1/auth/login/biometric/challenge`, `POST /v1/auth/login/biometric`; apply rate-limiting middleware keyed on ip_hash; apply correlation ID and idempotency middleware (FR-E01-009 to FR-E01-012)
- [X] T068 [US2] Emit audit events: `identity.session.login-succeeded`, `identity.session.login-failed`, `identity.account.locked` — all with HMAC-SHA256 ip_hash; all with masked identifier (FR-E01-013, FR-E01-014; BR-027; AC-015)
- [X] T069 [US2] Emit outbox events: `Identity.Session.Created`, `Identity.Account.Locked`, `Identity.Device.NewDeviceDetected` via Platform Foundation EventPublisher (AC-016)
- [X] T070 [P] [US2] Write feature tests in `services/api/app/Modules/Identity/Tests/Feature/Authentication/`: PIN login happy path; OTP login happy path; invalid credentials → 401 IDENTITY_002 (no identifier disclosure); wrong PIN N times → lockout; progressive lockout extends duration on 2nd episode; locked account rejects auth; new device triggers device verification challenge; idempotency on duplicate login request (AC-007, AC-008, AC-009, AC-027)
- [X] T071 [P] [US2] Write security tests: no identifier disclosed on any failure path (BR-014); credential value absent from response, logs, and audit; lockout threshold sourced from config not hard-coded (AC-017); PIN not in any log line (BR-004)
- [X] T072 [P] [US2] Write concurrency test: concurrent PIN login attempts produce exactly one session (spec Section 24.3)

---

## Phase 7: JWKS & Token Issuance (US4)

**Goal**: ES256 access tokens issued with kid header. JWKS endpoint serves public keys for local downstream verification. Private key material never exposed.

**Independent test**: Login returns JWT with `alg: "ES256"` and `kid` header; `GET /v1/.well-known/jwks.json` returns matching kid; token verifiable against JWKS public key; private key `d` absent from JWKS.

- [X] T073 [US4] Create `services/api/app/Modules/Identity/Domain/Services/JwksService.php` — uses JwksProviderContract to retrieve current and retired public keys; builds JWK Set JSON array; caches in Redis `identity:jwks` with TTL from config; `d` (private key) MUST NEVER appear in output (FR-E01-018; jwks-api.md; research.md Decision 8)
- [X] T074 [P] [US4] Create `services/api/app/Modules/Identity/Domain/Services/TokenService.php` — uses lcobucci/jwt to sign JWTs with ES256 algorithm; kid from JwksProviderContract.getCurrentKid() placed in JWT header; access token claims: iss, sub, aud, iat, exp, nbf, jti, session_id, actor_type, roles, device_id, kid; TTL from ConfigurationSecurityService; step-up tokens include step_up_scope and restricted aud (FR-E01-018; authentication-api.md JWT Structure)
- [X] T075 [US4] Create `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/JwksController.php` — returns JWK Set from JwksService; `Cache-Control: public, max-age=300`; includes both active and retired public keys during rotation window (jwks-api.md)
- [X] T076 [P] [US4] Register route: `GET /v1/.well-known/jwks.json` — no authentication required; public endpoint (jwks-api.md)
- [X] T077 [US4] Create `services/api/app/Modules/Identity/Application/DTOs/AuthTokenDTO.php` — readonly: access_token, refresh_token, token_type, expires_in, session_id (FR-E01-018, FR-E01-019)
- [X] T078 [P] [US4] Write JWT/JWKS tests in `Tests/Feature/Token/JwksTest.php` and `TokenIssuanceTest.php`: alg=ES256 in header; kid present in JWT header and matches JWKS response; iss claim equals configured issuer; aud claim present; exp = iat + configured TTL; private key field `d` absent from JWKS response; Cache-Control header present (FR-E01-018; jwks-api.md)
- [X] T079 [P] [US4] Write JWKS rotation test: set `IDENTITY_JWT_OLD_PUBLIC_KEY` → both kids in JWKS; old-kid token still verifiable; new-kid token signs correctly; remove old key → only new kid in JWKS (jwks-api.md Key Rotation Protocol)
- [X] T080 [P] [US4] Write security tests: step-up token rejected as general access token (aud/scope enforcement); service token rejected as user token (actor_type check); token with unknown kid rejected; expired token rejected; invalid ES256 signature rejected (authentication-api.md; spec Section 24.2)

---

## Phase 8: Session & Refresh Token Lifecycle (US4)

**Goal**: Refresh tokens rotate on every use; reuse detection revokes the session family; sessions auto-expire per actor type; users can view and revoke their own sessions.

**Independent test**: Token refresh → new access + refresh token; old refresh token rejected; presenting old token again → IDENTITY_015, all family sessions revoked.

- [X] T081 [US4] Create `services/api/app/Modules/Identity/Domain/Services/RefreshTokenService.php` — generates 256-bit opaque token via `random_bytes(32)` → base64url; stores `hash('sha256', $rawToken)` in `identity.sessions.refresh_token_hash`; on refresh: find session by hash → if not active → reuse detected → revoke family and throw RefreshTokenReuseException; if active → rotate (new token, invalidate old); revoked token hashes cached in Redis `identity:refresh_revoked:{hash}` with TTL from config (FR-E01-020, FR-E01-021; BR-008; AC-005, AC-006; research.md Decision 5)
- [X] T082 [P] [US4] Create `services/api/app/Modules/Identity/Domain/Services/SessionService.php` — creates session records; manages last_activity_at; inactivity TTL per actor_type from ConfigurationSecurityService; Redis key `identity:revoked:{session_id}` for fast revocation check; methods: createSession, validateSession, revokeSession, revokeAllForUser, listActiveSessions (FR-E01-022 to FR-E01-024, FR-E01-026)
- [X] T083 [US4] Create `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/TokenController.php` — `refresh()` delegates to RefreshTokenService; idempotency applied (FR-E01-020)
- [X] T084 [P] [US4] Create `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/SessionController.php` — `index()` lists active sessions; `logout()` revokes current session; `logoutAll()` revokes all user sessions; no raw IP in responses (FR-E01-022, FR-E01-023, FR-E01-026)
- [X] T085 [P] [US4] Create `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/DeviceController.php` — `register()`, `index()`, `revoke()` actions; device_fingerprint always stored as SHA-256 hash (FR-E01-026, FR-E01-027)
- [X] T086 [US4] Register routes: `POST /v1/auth/token/refresh`, `POST /v1/auth/logout`, `DELETE /v1/auth/sessions`, `GET /v1/auth/sessions`, `DELETE /v1/auth/sessions/{id}`, `POST /v1/auth/devices/register`, `GET /v1/auth/devices`, `DELETE /v1/auth/devices/{id}` (FR-E01-022 to FR-E01-027)
- [X] T087 [US4] Create `services/api/app/Modules/Identity/Infrastructure/Jobs/SessionCleanupJob.php` — purges sessions where status IN (expired, revoked, logged_out) AND updated_at < NOW() minus retention interval from config (default 90 days); MUST NOT purge active records; scheduled daily via Horizon (data-model.md Retention Policy; FR-E01-024)
- [X] T088 [P] [US4] Create `services/api/app/Modules/Identity/Infrastructure/Jobs/OtpChallengeCleanupJob.php` — purges otp_challenges where status IN (expired, invalidated, verified) AND updated_at < NOW() minus 24h from config; MUST NOT purge pending records; scheduled hourly via Horizon (data-model.md Retention Policy; FR-E01-005)
- [X] T089 [US4] Register both cleanup jobs in Laravel Horizon schedule via `services/api/app/Console/Kernel.php` (cross-cutting)
- [X] T090 [P] [US4] Emit audit events: `identity.session.created`, `identity.session.logout`, `identity.session.all-terminated`, `identity.session.revoked` with HMAC-SHA256 ip_hash (AC-015, AC-026)
- [X] T091 [P] [US4] Emit outbox events: `Identity.Session.Created`, `Identity.Session.Terminated`, `Identity.Session.AllTerminated` via Platform Foundation EventPublisher (AC-016)
- [X] T092 [P] [US4] Write feature tests for refresh token lifecycle: rotation works; old token rejected after rotation; reuse detection revokes all sessions in family (AC-006); inactivity TTL per actor_type enforced; session list excludes ip_hash field in response; logout sets status (AC-005)
- [X] T093 [P] [US4] Write security tests: revoked session rejected on next request; Redis revocation cache used for fast lookup; refresh token reuse triggers `Identity.Session.AllTerminated` event; entire family revoked (BR-008; spec Section 24.2)
- [X] T094 [P] [US4] Write concurrency test: concurrent refresh rotation for same token → exactly one succeeds; parallel request with old token → IDENTITY_015 (spec Section 24.3)
- [X] T095 [P] [US4] Write retention tests: SessionCleanupJob purges expired records older than 90 days; does not purge active records; OtpChallengeCleanupJob purges expired older than 24h; does not purge pending (data-model.md)

---

## Phase 9: New Device Detection & Verification (US3)

**Goal**: Login from an unrecognized device returns DEVICE_VERIFICATION_REQUIRED; OTP verification promotes device to trusted.

**Independent test**: Login with new device fingerprint → DEVICE_VERIFICATION_REQUIRED; complete device OTP → device.status = trusted; second login with same device fingerprint → no challenge.

- [X] T096 [US3] Implement `DeviceService.verifyDeviceByOtp()` — creates device_verification OTP challenge via OtpService; on OTP verify promotes device to status=trusted and sets trusted_at (FR-E01-016, FR-E01-017; AC-009)
- [X] T097 [P] [US3] Add `verify()` action to DeviceController.php — `POST /v1/auth/devices/verify`; delegates to DeviceService.verifyDeviceByOtp(); idempotency applied (FR-E01-017)
- [X] T098 [US3] Register route `POST /v1/auth/devices/verify` with idempotency and correlation middleware (FR-E01-017)
- [X] T099 [US3] Emit audit events: `identity.device.registered` (on trust), `identity.device.revoked` (on revoke) with HMAC-SHA256 ip_hash (AC-015)
- [X] T100 [P] [US3] Emit outbox events: `Identity.Device.NewDeviceDetected` (on login with new device), `Identity.Device.Registered` (when trusted) (AC-016)
- [X] T101 [P] [US3] Write feature tests: new device login returns 403 DEVICE_VERIFICATION_REQUIRED; OTP verifies device; second login on same device proceeds without challenge; revoked device requires re-verification (AC-009; FR-E01-016, FR-E01-017)

---

## Phase 10: Admin Session Revocation (US5)

**Goal**: An admin can revoke any user's session immediately; revocation is reflected in Redis and DB; audit event records admin actor ID.

**Independent test**: Admin POST /v1/admin/sessions/{id}/revoke → identity.sessions.status = revoked; next request with that session token → 401 SESSION_REVOKED.

- [X] T102 [US5] Create `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/AdminSessionController.php` — `revoke()` action (`POST /v1/admin/sessions/{session_id}/revoke`); verifies caller has `platform.admin.sessions.revoke` permission via E04 authorization middleware (FR-E01-025; AC-019)
- [X] T103 [P] [US5] Create `services/api/app/Modules/Identity/Application/Commands/RevokeSessionCommand.php` — delegates to SessionService.revokeSession(); sets Redis `identity:revoked:{session_id}` (FR-E01-025; BR-015)
- [X] T104 [US5] Register admin route `POST /v1/admin/sessions/{session_id}/revoke` with admin auth middleware and permission check (FR-E01-025)
- [X] T105 [US5] Emit audit event `identity.session.revoked` with admin actor ID, revoked session ID, target user ID, HMAC ip_hash (AC-015, AC-019)
- [X] T106 [P] [US5] Write feature tests: admin revokes any session; revoked session rejects next auth immediately; non-admin cannot access revoke endpoint (AC-019; FR-E01-025)

---

## Phase 11: PIN & Credential Management (US6)

**Goal**: Authenticated users can set, change, and reset their PIN; complexity enforced from configuration; block and unblock via OTP recovery.

**Independent test**: Set PIN → credential_hash starts with $argon2id; change PIN with wrong current → 401; sequential PIN 123456 → 400; PIN N failures → blocked; OTP recovery → unblocked.

- [X] T107 [US6] Create `services/api/app/Modules/Identity/Domain/Services/CredentialService.php` — createPin (Argon2id via `Hash::make()` with Argon2id driver), changePin (verify current → hash new), resetPin (OTP-verified recovery), blockPin, unblockPin; reads PIN policy from ConfigurationSecurityService; raw PIN MUST NEVER appear in logs, events, audit records, or responses (FR-E01-028 to FR-E01-033; BR-004, BR-005; AC-010)
- [X] T108 [P] [US6] Implement PIN complexity validation in CredentialService: enforce `auth.pin.length` (exact digit count), `auth.pin.reject_sequential` (e.g., 123456, 654321), `auth.pin.reject_repeated` (e.g., 111111), `auth.pin.reject_common`; all rules sourced from ConfigurationSecurityService — not hard-coded; throw PinComplexityException on violation (FR-E01-031; BR-005; AC-017)
- [X] T109 [US6] Create application commands `CreatePinCommand.php`, `ChangePinCommand.php`, `ResetPinCommand.php`, `UnblockPinCommand.php` in `Application/Commands/` (FR-E01-028 to FR-E01-033)
- [X] T110 [P] [US6] Create `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/PinController.php` — actions: `create()`, `change()`, `resetInitiate()`, `resetComplete()`, `unblock()`; setup_token flow for initial PIN after registration; idempotency on all state-changing operations (FR-E01-028 to FR-E01-033)
- [X] T111 [US6] Register routes: `POST /v1/auth/pin/set`, `POST /v1/auth/pin/change`, `POST /v1/auth/pin/reset/initiate`, `POST /v1/auth/pin/reset/complete`, `POST /v1/auth/pin/unblock` (FR-E01-028 to FR-E01-033)
- [X] T112 [US6] Emit audit events: `identity.credential.pin-created`, `identity.credential.pin-changed`, `identity.credential.pin-reset`, `identity.credential.pin-blocked`, `identity.credential.pin-unblocked` — raw PIN MUST NOT appear in any audit payload (BR-004, BR-013; AC-010, AC-015)
- [X] T113 [P] [US6] Write feature tests: PIN set stores Argon2id hash; change with wrong current fails; reset flow requires OTP verification; complexity rejects sequential/repeated/common PINs; block after N failures; unblock via OTP (FR-E01-028 to FR-E01-033; AC-010 to AC-012)
- [X] T114 [P] [US6] Write security tests: PIN not in DB, logs, responses, or audit events; hash starts with $argon2id; reset flow validates OTP before allowing new PIN (BR-004; AC-010)
- [ ] T215 [US6] Write concurrency test in `services/api/app/Modules/Identity/Tests/Feature/Pin/PinConcurrencyTest.php` — fire PIN change (`POST /v1/auth/pin/change`) and PIN reset (`POST /v1/auth/pin/reset/complete`) concurrently for the same user identity using parallel HTTP requests or a database transaction simulation; assert exactly one operation succeeds with 200 and the other receives a defined conflict or precondition error (do NOT invent a new error code — use existing IDENTITY_00x taxonomy); assert `identity.credentials` has exactly one consistent credential_hash after both operations settle (no partial write); assert audit events in `platform.audit_events` reflect only the winning operation's state change without duplication or omission (spec Section 24.3 concurrency requirement; FR-E01-028 to FR-E01-030; depends on T107–T114)

---

## Phase 12: MFA (US7)

**Goal**: Users enroll TOTP or FIDO2 MFA; Admin enforces MFA at login with TOTP or FIDO2 (not SMS); MFA challenge gate inserted between credential login and session activation.

**Independent test**: Enroll TOTP → QR URI returned; confirm with first code → enrolled; next login → mfa_pending session; verify TOTP → active session; disable MFA → subsequent login skips MFA gate.

- [X] T115 [US7] Create `services/api/app/Modules/Identity/Domain/Services/MfaService.php` — TOTP: generate secret, return otpauth:// URI for enrollment; confirm with first code via `chillerlan/php-authenticator`; verify with time-window code; FIDO2/WebAuthn: generate registration challenge via `web-auth/webauthn-framework`; confirm by storing fido2_credential_id and fido2_public_key; verify assertion and increment fido2_sign_count (replay detection); MFA lockout threshold from ConfigurationSecurityService (do NOT hard-code) (FR-E01-034 to FR-E01-036, FR-E01-049; BR-028; AC-014, AC-018, AC-028)
- [X] T116 [P] [US7] Implement FIDO2 sign_count replay detection: reject any assertion where presented sign_count ≤ stored fido2_sign_count; increment on success (FR-E01-049; spec Section 11)
- [X] T117 [P] [US7] Create commands `EnableMfaCommand.php`, `DisableMfaCommand.php`, `VerifyMfaCommand.php` in `Application/Commands/` (FR-E01-034 to FR-E01-036)
- [X] T118 [US7] Create `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/MfaController.php` — `enroll()`, `confirmEnrollment()`, `verify()`, `disable()` for TOTP and FIDO2; Admin actors only offered TOTP and FIDO2 (`auth.mfa.admin.sms_enabled` = false enforced server-side); mfa_token issued for mid-login MFA step (FR-E01-034 to FR-E01-036, FR-E01-049)
- [X] T119 [P] [US7] Register routes: `POST /v1/auth/mfa/enroll/totp`, `POST /v1/auth/mfa/enroll/totp/confirm`, `POST /v1/auth/mfa/verify`, `POST /v1/auth/mfa/disable`, `POST /v1/auth/mfa/enroll/fido2`, `POST /v1/auth/mfa/enroll/fido2/confirm` (FR-E01-034 to FR-E01-036)
- [X] T120 [P] [US7] Implement mfa_pending session state: when MFA is enrolled, credential login transitions session to mfa_pending and issues opaque mfa_token; MFA verify transitions session to active; MFA failure increments failure count (lockout threshold from config — do NOT invent value) (FR-E01-036; AC-018)
- [X] T121 [US7] Emit audit events: `identity.mfa.enabled`, `identity.mfa.disabled` with HMAC ip_hash (BR-013; AC-014, AC-015)
- [X] T122 [P] [US7] Write feature tests: TOTP enroll + confirm + login MFA gate; FIDO2 enroll + verify; disable MFA removes credential; Admin offered only TOTP/FIDO2 (not SMS); mfa_pending session rejected without MFA verify (FR-E01-034 to FR-E01-036, FR-E01-049; AC-014, AC-018, AC-028)
- [X] T123 [P] [US7] Write security tests: TOTP secret not logged after enrollment; invalid TOTP code rejected; FIDO2 replay attack rejected (sign count check); MFA cannot be bypassed at Admin login (AC-018, AC-028)

---

## Phase 13: Step-Up Authentication (US8)

**Goal**: Step-up required when amount > configured threshold OR risk engine signals. Step-up token is purpose/audience-scoped and cannot be used as a general access token.

**Independent test**: Initiate step-up with amount 15000 BWP (above configured 10000 BWP seed) → step_up_token after MFA; step-up token rejected for a different scope; risk engine signal triggers step-up independently of amount.

- [X] T124 [US8] Create `services/api/app/Modules/Identity/Domain/Services/StepUpService.php` — `evaluateAmount(float $amount, string $currency): bool` reads `auth.step_up_threshold` and `auth.step_up_threshold_currency` from ConfigurationSecurityService at runtime; uses platform FX mechanism for cross-currency comparison; FX unavailable → return true (fail-safe, BR-024); `evaluateRiskSignal(bool $riskRequired): bool` independent trigger path; threshold value MUST NOT be hard-coded in any conditional or constant (FR-E01-037, FR-E01-046, FR-E01-047; BR-023, BR-024; AC-013)
- [X] T125 [P] [US8] Create `RequestStepUpCommand.php` and `CompleteStepUpCommand.php` in `Application/Commands/` (FR-E01-037, FR-E01-038)
- [X] T126 [P] [US8] Create `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/StepUpController.php` — `initiate()` transitions session to step_up_pending; `verify()` after MFA issues step-up token via TokenService with purpose-scoped aud and step_up_scope claim; step-up token TTL from ConfigurationSecurityService (value TBD — do NOT invent) (FR-E01-037, FR-E01-038)
- [X] T127 [P] [US8] Create `services/api/app/Modules/Identity/Application/DTOs/StepUpTokenDTO.php` — step_up_access_token, scope, expires_in (FR-E01-037)
- [X] T128 [US8] Register routes: `POST /v1/auth/step-up/initiate`, `POST /v1/auth/step-up/verify` with auth middleware (FR-E01-037, FR-E01-038)
- [X] T129 [US8] Emit audit events: `identity.mfa.step-up-completed`, `identity.mfa.step-up-failed` with scope and HMAC ip_hash (AC-015)
- [X] T130 [P] [US8] Write feature tests: amount above configured threshold triggers step-up; amount below does not; risk signal independently triggers step-up; step-up token scoped correctly; step-up token rejected for different scope (AC-013; FR-E01-046)
- [X] T131 [P] [US8] Write multi-currency test: amount in non-BWP currency uses platform FX mechanism; FX unavailable → step-up required (fail-safe) (FR-E01-047; BR-024)
- [X] T132 [P] [US8] Write security test: step-up token rejected as general access token (wrong aud); Admin step-up (US-AUTH-038) required for privileged admin operations (FR-E01-038)
- [X] T133 [P] [US8] Write configuration test: step-up threshold read from config at runtime; changing `auth.step_up_threshold` in DB takes effect on next evaluation; 10000 BWP does not appear as a literal in any domain service condition (AC-013, AC-017)

---

## Phase 14: Service Identity (US9)

**Goal**: Internal services obtain E01-issued ES256 JWTs; delegation preserves both service and user identity; tokens are audience-restricted and auditable; jti is unique per issuance.

**Independent test**: Service token issued with actor_type=SERVICE, jti, aud restriction; token verifiable via JWKS; service token rejected at endpoint expecting user token.

- [X] T134 [US9] Create `services/api/app/Modules/Identity/Domain/Services/ServiceIdentityService.php` — issues service JWT via TokenService with actor_type=SERVICE, sub=service_id, aud=declared_audience, jti=UUID v7, on_behalf_of=user_id_or_null; service credential verification mechanism TBD — stub for dev environment; service token lifetime TBD — read from config, do NOT invent (spec Section 10.5; contracts/service-token-api.md)
- [X] T135 [P] [US9] Create `IssueServiceTokenCommand.php` in `Application/Commands/` with fields: service_id, requested_scopes (TBD catalogue), audience, on_behalf_of (spec Section 10.5)
- [X] T136 [P] [US9] Create `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/ServiceTokenController.php` — issues service JWT; service credential verification (mechanism TBD); idempotency applied (contracts/service-token-api.md)
- [X] T137 [P] [US9] Create `services/api/app/Modules/Identity/Application/DTOs/ServiceTokenDTO.php` — access_token, token_type, expires_in (TBD), scopes (TBD) (contracts/service-token-api.md)
- [X] T138 [US9] Register route `POST /v1/auth/service/token` (confirmed path from contracts/service-token-api.md) with idempotency middleware (contracts/service-token-api.md)
- [X] T139 [US9] Emit audit event `identity.service.token-issued` — MUST include service_id, jti, aud, iat, exp, correlation_id as INSERT-only immutable record (spec Section 16; contracts/service-token-api.md)
- [X] T140 [P] [US9] Implement `IdentityModuleContract` concrete class and bind in IdentityServiceProvider: `resolveActorFromToken()` verifies ES256 JWT via JWKS and extracts AuthenticatedActor; `resolveServiceActor()` verifies service token and extracts ServiceActor; `verifyStepUpScope()` validates step-up token claims (contracts/module-contracts.md)
- [X] T141 [P] [US9] Write service token tests in `Tests/Feature/ServiceIdentity/`: service JWT issued with correct claims; actor_type=SERVICE; jti unique per issuance; audience restriction enforced; on_behalf_of delegation chain preserved; audit event created (contracts/service-token-api.md)
- [X] T142 [P] [US9] Write security tests: service token rejected as user token (actor_type check); token issued for audience A rejected at audience B; on_behalf_of misuse — service cannot elevate user permissions; scope validation (stub) (contracts/service-token-api.md Security Constraints)

---

## Phase 15: Admin Configuration API (US10)

**Goal**: Authorized admins view and update all 25 identity configuration keys; security minimums enforced; optimistic concurrency prevents lost updates; every change audited.

**Independent test**: GET /v1/admin/platform/configuration/security → 200, 25 keys; PUT auth.otp.ttl=600 → updated + audited; PUT auth.pin.length=4 → 400; stale version → 409; customer → 403.

- [X] T143 [US10] Create `services/api/app/Modules/Identity/Infrastructure/Http/Controllers/ConfigurationController.php` — `index()` returns all 25 identity config keys via ConfigurationSecurityService with metadata (value, default, description, type, min/max, last_updated_at, last_updated_by, is_sensitive); `update()` validates type + security minimum + optimistic concurrency version; persists via Platform Foundation `ConfigurationServiceInterface`; both require permission middleware (FR-E01-041, FR-E01-042, FR-E01-043, FR-E01-045; AC-022 to AC-025)
- [X] T144 [P] [US10] Create Form Request classes `ConfigurationIndexRequest.php` (permission: `platform.configuration.view`) and `ConfigurationUpdateRequest.php` (permission: `platform.configuration.update` + typed value validation per contracts/configuration-api.md Validation Rules table) (FR-E01-042, FR-E01-043)
- [X] T145 [P] [US10] Implement optimistic concurrency in ConfigurationController.update(): compare submitted `version` (last_updated_at from GET) with current DB value; mismatch → throw ConfigurationConflictException → 409 IDENTITY_016 with current_updated_at and current_updated_by (FR-E01-045; BR-022; AC-025)
- [X] T146 [US10] Register admin routes: `GET /v1/admin/platform/configuration/security`, `PUT /v1/admin/platform/configuration/security/{key}` with admin auth and permission middleware (FR-E01-042)
- [X] T147 [US10] Invalidate config cache after successful PUT: flush configuration cache key so updated security policy takes effect immediately (spec Section 8.1 Runtime Pattern)
- [X] T148 [P] [US10] Emit audit event `identity.config.updated` on every successful PUT: key, old_value, new_value, actor, correlation_id, HMAC ip_hash; sensitive keys use REDACTED for old/new values in audit record (FR-E01-044; BR-021; AC-023)
- [X] T149 [P] [US10] Write feature tests: admin views all 25 keys; valid update persisted and audited; value below security minimum rejected (AC-024); stale version rejected with IDENTITY_016 (AC-025); unauthorized user → 403; is_sensitive keys return REDACTED value (AC-022 to AC-025)
- [X] T150 [P] [US10] Write concurrency test: two admins update same key simultaneously → second update with stale version rejected (AC-025; FR-E01-045)
- [X] T151 [P] [US10] Write security tests: secrets not returned via config API; config endpoints inaccessible without correct permission; security minimum tested for each key with a minimum defined in contracts/configuration-api.md (AC-024; BR-020, BR-026)

---

## Phase 16: Integration Events & Audit Wiring

**Purpose**: Verify all 22 outbox events and all 18 audit actions are correctly wired across all domain services with correct payload masking.

- [X] T152 Audit all event emission points across every domain service: confirm all 22 events from spec Section 16 are emitted via `EventPublisherInterface` through `platform.outbox_events` (outbox pattern); no event bypasses the outbox (AC-016)
- [X] T153 [P] Audit all audit event emission points: confirm all 18 audit actions from spec Section 16 are emitted via Platform Foundation AuditService to `platform.audit_events`; no second audit registry created (AC-015; BR-021)
- [X] T154 [P] Verify ip_hash masking: search codebase for raw IP storage — confirm all ip_hash values use `HMAC-SHA256(platform_secret, ip_address)` where platform_secret is sourced from env/secret manager, NOT from `platform.configuration_values` (FR-E01-050; BR-027; AC-026)
- [X] T155 [P] Verify credential masking: search codebase to confirm raw OTP not in logs/events; raw PIN not in logs/events; JWT private key not in logs/responses; HMAC secret not exposed; TOTP secret not logged after enrollment (BR-002, BR-004, BR-026)
- [X] T156 Write integration tests in `Tests/Integration/Events/`: each major flow emits correct outbox event; event payload contains masked PII; no plaintext credentials in payload (AC-016)
- [X] T157 [P] Write audit tests: each major flow creates `platform.audit_events` record; action strings match canonical audit-action-vocabulary.md; severity correct; no raw IP in ip_hash field (AC-015, AC-026)

---

## Phase 17: Flutter Customer UI (US11)

**Goal**: All E01 authentication flows implemented in Customer Flutter app with Riverpod state management and secure token storage.

**Independent test**: Customer app can register, verify OTP, set PIN, login with PIN, refresh token, logout — all UI states functional on iOS and Android simulators.

- [X] T158 [US11] Create `AuthStateNotifier` Riverpod provider in `apps/customer/lib/modules/auth/state/auth_state_notifier.dart` with states: unauthenticated, otp_pending, pin_pending, mfa_pending, authenticated, step_up_pending, locked (spec Section 19.1; FR-E01-001, FR-E01-009)
- [X] T159 [P] [US11] Implement token secure storage in `apps/customer/lib/modules/auth/storage/auth_token_storage.dart` using `flutter_secure_storage` (iOS Keychain / Android Keystore); access_token and refresh_token MUST NOT be stored in SharedPreferences or unencrypted storage (spec Section 19.1; BR-009; research.md Decision 9)
- [X] T160 [P] [US11] Implement background token refresh in `apps/customer/lib/modules/auth/services/token_refresh_service.dart` — proactively refreshes before expiry on app resume; on failure → transition to unauthenticated state (spec Section 19.1)
- [X] T161 [P] [US11] Create Registration screen `apps/customer/lib/modules/auth/screens/registration/registration_screen.dart` — mobile number input; loading/error/success states; accessibility labels; keyboard navigation (US-AUTH-001)
- [X] T162 [P] [US11] Create OTP verification screen `apps/customer/lib/modules/auth/screens/otp/otp_verify_screen.dart` — 6-digit numeric input; auto-submit on final digit; resend CTA with cooldown timer; error state (US-AUTH-003, US-AUTH-004)
- [X] T163 [P] [US11] Create PIN entry keypad screen `apps/customer/lib/modules/auth/screens/pin/pin_entry_screen.dart` — numeric keypad; obscured display; loading/error states (US-AUTH-010)
- [X] T164 [P] [US11] Create PIN create/change/reset screens `apps/customer/lib/modules/auth/screens/pin/pin_create_screen.dart`, `pin_change_screen.dart`, `pin_reset_screen.dart` (US-AUTH-028, US-AUTH-029, US-AUTH-030)
- [X] T165 [P] [US11] Create biometric auth screen/flow `apps/customer/lib/modules/auth/screens/biometric/biometric_screen.dart` — triggers device biometric prompt; submits signed assertion via BiometricAssertionContract client-side wrapper (TBD-004 abstraction preserved); raw biometric data MUST NOT be sent to server (US-AUTH-012; BR-009)
- [X] T166 [P] [US11] Create MFA screens `apps/customer/lib/modules/auth/screens/mfa/mfa_enable_screen.dart`, `mfa_verify_screen.dart`, `mfa_disable_screen.dart` — TOTP QR display; code entry (US-AUTH-034, US-AUTH-035, US-AUTH-036)
- [X] T167 [P] [US11] Create step-up overlay `apps/customer/lib/modules/auth/screens/step_up/step_up_overlay.dart` — inline bottom sheet that does NOT navigate away from the in-progress operation; MFA challenge entry (US-AUTH-037)
- [X] T168 [P] [US11] Create sessions screen `apps/customer/lib/modules/auth/screens/sessions/sessions_screen.dart` — lists active sessions with device metadata; revoke individual session CTA (US-AUTH-026, US-AUTH-027)
- [X] T169 [P] [US11] Create account locked screen `apps/customer/lib/modules/auth/screens/locked/account_locked_screen.dart` — displays lockout message with PIN recovery CTA (US-AUTH-014)
- [X] T170 [US11] All Customer auth screens MUST consume Platform Foundation design tokens and shared components; implement loading, success, error, and security states per Platform Foundation design system (spec Section 18)
- [X] T171 [P] [US11] Write widget tests for all auth screens: each state (loading, success, error); accessibility labels present; keyboard navigation; OTP auto-submit on 6th digit (spec Section 24.4, 24.5)
- [X] T172 [P] [US11] Write mobile integration tests: secure storage not accessible from outside app; session expiry transitions to unauthenticated; step-up overlay preserves in-progress operation context (spec Section 24.4)
- [X] T173 [P] [US11] Run WCAG AA accessibility audit on all auth screens: contrast ratio, screen-reader labels, focus management, error association (spec Section 24.5; constitution Principle 11)

---

## Phase 18: Flutter Agent UI (US12)

**Goal**: Agent-specific subset of authentication flows implemented in Flutter Agent application.

**Independent test**: Agent app can register via OTP, login with PIN, manage PIN, view/revoke sessions.

- [X] T174 [US12] Create Agent `AuthStateNotifier` in `apps/agent/lib/modules/auth/state/auth_state_notifier.dart` — same Riverpod patterns as Customer; initial scope: mobile registration, PIN login, no biometric (spec Section 19.2)
- [X] T175 [P] [US12] Implement Agent token secure storage using `flutter_secure_storage` (same pattern as Customer) (spec Section 19.2)
- [X] T176 [P] [US12] Create Agent auth screens (subset): registration, OTP verify, PIN login, PIN create/change/reset, new device verify, sessions — reuse Customer screen components where applicable (spec Section 18.2)
- [X] T177 [P] [US12] Implement connectivity-aware degradation for poor network conditions in Agent auth flows (spec Section 19.2)
- [X] T178 [P] [US12] Write widget tests and WCAG AA accessibility tests for Agent auth screens (spec Section 24.4, 24.5)

---

## Phase 19: Next.js Admin UI (US13)

**Goal**: Admin password + mandatory MFA login; session management dashboard; security configuration management pages.

> **Enterprise actor note**: The Enterprise actor (corporate user) authenticates via the same password + mandatory MFA path as Admin. Dedicated Enterprise-specific UI screens and flows are deferred to the Enterprise epic; E01 provides the shared authentication foundation only.

**Independent test**: Admin login with password + TOTP → authenticated; admin can view and revoke user sessions; admin views all 25 config keys and updates a value.

- [X] T179 [US13] Create Admin login page `apps/admin/src/modules/auth/pages/login.tsx` — email + password form; mandatory MFA step (TOTP or FIDO2; SMS disabled); uses Platform Foundation design system components (US-AUTH-011; FR-E01-049; AC-018, AC-028)
- [X] T180 [P] [US13] Create Admin session management page `apps/admin/src/modules/auth/pages/sessions.tsx` — list all user sessions (paginated); search by user; revoke session CTA; no raw IP displayed in any field (FR-E01-025, FR-E01-026; AC-019)
- [X] T181 [P] [US13] Create Admin security configuration pages `apps/admin/src/modules/configuration/pages/security-config.tsx` with sub-sections: Authentication, Session, OTP, MFA, PIN Policy, Account Lockout, Step-Up Authentication, Audit Security; display all 25 config keys with current value, default, description, type, allowed range, last updated, last updated by (FR-E01-041; AC-022)
- [X] T182 [P] [US13] Implement inline config edit UI: click key → edit field with typed validation; submit with change_note → PUT API; optimistic lock conflict shown as actionable error; success shows updated value (FR-E01-041 to FR-E01-045; AC-023, AC-025)
- [X] T183 [P] [US13] Implement step-up overlay for Admin privileged operations (US-AUTH-038); Admin step-up uses TOTP or FIDO2 (FR-E01-038; AC-028)
- [X] T184 [P] [US13] Write E2E tests for Admin login + mandatory MFA flow (spec Section 20.2; AC-018, AC-028)
- [X] T185 [P] [US13] Write accessibility tests for Admin auth and configuration pages (constitution Principle 11)

---

## Phase 20: Observability & Metrics

**Purpose**: Implement all 7 approved OTel metrics using the `identity` meter name.

- [X] T186 Register OTel meter `identity` in `IdentityServiceProvider` via PHP OTel SDK; configure OTLP exporter; meter name MUST be exactly `identity` (spec Section 12; research.md Decision 10)
- [X] T187 [P] Implement `identity.auth.success` counter in AuthenticationService — emit on successful login; labels: actor_type, credential_type; NO PII in labels (spec Section 12)
- [X] T188 [P] Implement `identity.auth.failure` counter in AuthenticationService — emit on failed authentication; labels: actor_type, reason; no credential value in labels (spec Section 12)
- [X] T189 [P] Implement `identity.otp.sent` counter in OtpService — emit on each OTP dispatch; labels: channel_type, challenge_type; no identifier in labels (spec Section 12)
- [X] T190 [P] Implement `identity.account.locked` counter in LockoutService — emit on account lockout; labels: actor_type (spec Section 12)
- [X] T191 [P] Implement `identity.token.refresh` counter in RefreshTokenService — emit on each successful token rotation (spec Section 12)
- [X] T192 [P] Implement `identity.step_up.triggered` counter in StepUpService — emit on each step-up initiation; labels: trigger_type (amount_threshold or risk_signal) (spec Section 12)
- [X] T193 [P] Implement `identity.auth.latency` histogram in login, token/refresh, and otp/verify controller paths via Laravel middleware; buckets: [50, 100, 200, 300, 500, 750, 1000, 2500] ms (spec Section 12; research.md Decision 10)
- [X] T194 Write observability tests in `Tests/Feature/Observability/`: each metric emitted on its trigger event; no PII in metric labels; latency histogram records on login and refresh paths (spec Section 12)

---

## Phase 21: Performance & Security Tests

**Purpose**: Validate P95 latency targets and security abuse cases.

- [X] T195 Create K6 login performance script `services/api/app/Modules/Identity/Tests/Performance/login.js` — PIN login against seeded DB; threshold: `http_req_duration: ['p(95)<500']`; VU count via `K6_VUSER_COUNT` env var; duration via `K6_DURATION`; production VU count is TBD — do NOT hard-code; local smoke-test must be clearly labeled as NOT a production capacity target (spec Section 24.7; quickstart.md Performance Validation)
- [X] T196 [P] Create K6 token refresh script `services/api/app/Modules/Identity/Tests/Performance/token-refresh.js` — refresh against warm Redis cache; threshold: `http_req_duration: ['p(95)<200']`; parametric K6_VUSER_COUNT (spec Section 24.7)
- [X] T197 [P] Create K6 OTP verify script `services/api/app/Modules/Identity/Tests/Performance/otp-verify.js` — OTP verify with pre-generated challenges; threshold: `http_req_duration: ['p(95)<500']`; OTP delivery latency excluded; parametric K6_VUSER_COUNT (spec Section 24.7)
- [X] T198 [P] Create K6 test data setup and teardown scripts `services/api/app/Modules/Identity/Tests/Performance/setup.js` — seeds test users, sessions, OTP challenges before run; teardown clears test data (spec Section 24.7)
- [X] T199 Write comprehensive security abuse test suite `Tests/Security/AbuseTest.php`: invalid credentials (no identifier disclosure); OTP expiration; repeated OTP attempts; OTP replay; refresh token reuse → all-session revoke; revoked session rejection; token misuse (wrong aud, wrong iss, invalid signature, expired, unknown kid); MFA failure lockout; step-up failure; unauthorized service call; service-token wrong audience; on_behalf_of delegation misuse (spec Section 24.2)
- [X] T200 [P] Write JWT security test suite `Tests/Security/JwtTest.php`: expired token rejected; invalid ES256 signature rejected; wrong issuer rejected; wrong audience rejected; unknown kid rejected; step-up token rejected as general access token; service token rejected as user token; all required claims enforced (spec Section 24.2; authentication-api.md JWT Structure)
- [X] T201 [P] Write API security test suite `Tests/Security/ApiTest.php`: all authenticated endpoints require valid Bearer token; no stack traces in error responses; rate limit headers present on applicable endpoints; idempotency prevents duplicate state changes (spec Section 24.6; BR-006)

---

## Phase 22: E2E Authentication Flows

**Purpose**: Run the 5 E2E scenarios from spec Section 23 end-to-end through the full stack.

- [X] T202 Create E2E test `tests/e2e/identity/scenario-1-registration-to-first-login.php` — new Customer registration → OTP verify → account active → login with OTP → PIN create → login with PIN; assert audit trail, session active, PIN hashed in DB (spec Section 23 Scenario 1; quickstart.md Scenario 1)
- [X] T203 [P] Create E2E test `tests/e2e/identity/scenario-2-lockout-and-recovery.php` — fail PIN N times → account locked → audit event → PIN reset via OTP → unlocked → successful PIN login (spec Section 23 Scenario 2; quickstart.md Scenario 3)
- [X] T204 [P] Create E2E test `tests/e2e/identity/scenario-3-step-up-for-transfer.php` — authenticated customer → step-up triggered (amount above configured threshold) → MFA challenge → step-up token issued with correct scope; assert audit and scope restriction (spec Section 23 Scenario 3; quickstart.md Scenario 7)
- [X] T205 [P] Create E2E test `tests/e2e/identity/scenario-4-new-device-login.php` — login with new device fingerprint → DEVICE_VERIFICATION_REQUIRED → OTP device verify → device trusted → subsequent login without challenge (spec Section 23 Scenario 4; quickstart.md Scenario 2)
- [X] T206 [P] Create E2E test `tests/e2e/identity/scenario-5-refresh-token-reuse-attack.php` — establish session → rotate refresh token → present old token → all family sessions revoked → `Identity.Session.AllTerminated` event in outbox (spec Section 23 Scenario 5; quickstart.md Scenario 2)
- [X] T207 Execute quickstart.md validation scenarios 1–9 as part of CI pipeline; all scenarios must pass (quickstart.md)

---

## Phase 23: Polish & Documentation

- [X] T208 [P] Generate OpenAPI 3.x specification from E01 routes and Form Requests; output to `openapi/v1/identity.yaml` (canonical path per project convention — all other modules use openapi/v1/); validate against contracts/authentication-api.md, jwks-api.md, service-token-api.md, configuration-api.md (spec Section 24.6; AC-020)
- [ ] T209 [P] Create ADR `docs/adrs/ADR-006-es256-jwt-signing.md` documenting the ES256 decision, JWKS architecture, and alternatives rejected (research.md Decision 1)
- [ ] T210 [P] Create ADR `docs/adrs/ADR-007-service-identity-jwt.md` documenting service identity JWT decision, actor model, delegation, JWKS reuse, and alternatives rejected (research.md Decision 2)
- [ ] T211 [P] Update Identity module documentation with: module purpose, owned schema, public contract (IdentityModuleContract), key services, preserved TBD items, and configuration keys overview (cross-cutting)
- [ ] T212 [P] Run full test suite and confirm ≥ 80% code coverage for Identity module; all security tests pass; all concurrency tests pass (spec Section 26 Definition of Done)
- [ ] T213 [P] Verify security DoD checklist: no plaintext PIN/OTP in DB, logs, or events; no raw IP in audit; no secrets in config table; all configurable values read from ConfigurationService; no TBD values hard-coded in domain conditions (spec Section 26 DoD; constitution Principle 2, 6)
- [ ] T214 [P] Verify module boundary DoD: no cross-schema JOINs in any Identity query; no direct queries to `identity.*` from any other module; IdentityModuleContract is the only public surface consumed by other epics (constitution Principle 7; ADR-003)

---

## Phase 24: Convergence

- [X] T215 In `ServiceTokenController::issue()`, add `on_behalf_of` to the `identity.service.token-issued` audit `after_state` when non-null: append `'on_behalf_of' => $onBehalfOf` to the afterState array so the originating user identity is independently auditable from the audit record per spec Section 10.5 "Both identities MUST be auditable" (partial)
- [X] T216 [P] Add audit delegation test to `Tests/Feature/ServiceToken/ServiceTokenTest.php`: issue a service token with `on_behalf_of` set, then assert the `identity.service.token-issued` audit record's `after_state` contains `on_behalf_of` with the correct user ID; verify audit record includes both service identity and originating user identity per spec Section 10.5 (partial)

---

## Phase 25: Convergence

- [X] T217 [P] [US12] Write widget tests (loading, success, error states) and WCAG AA accessibility assertions for `apps/agent/lib/modules/auth/screens/pin/pin_create_screen.dart` — headed Semantics, liveRegion error, PinDots/PinKeypad present, mismatch error shown per spec Section 24.4, 24.5 (partial)
- [X] T218 [P] [US12] Write widget tests and WCAG AA accessibility assertions for `apps/agent/lib/modules/auth/screens/pin/pin_change_screen.dart` — three-phase progression, error shown on API failure, no biometric icon per spec Section 24.4, 24.5 (partial)
- [X] T219 [P] [US12] Write widget tests and WCAG AA accessibility assertions for `apps/agent/lib/modules/auth/screens/pin/pin_reset_screen.dart` — mobile-entry → OTP → new PIN → confirm PIN phases; loading state; error display per spec Section 24.4, 24.5 (partial)
- [X] T220 [P] [US12] Write widget tests and WCAG AA accessibility assertions for `apps/agent/lib/modules/auth/screens/pin/pin_unblock_screen.dart` — OTP phase vs unblock-pending phase; error state; Semantics labels per spec Section 24.4, 24.5 (partial)
- [X] T221 [P] [US12] Write widget tests and WCAG AA accessibility assertions for `apps/agent/lib/modules/auth/screens/device/device_verify_screen.dart` — 6-digit OTP auto-submit, error display, loading state, accessibility labels per spec Section 24.4, 24.5 (partial)
- [X] T222 [P] [US12] Write Agent mobile integration tests: (a) secure token storage — keys written via `SecureAuthTokenStorage` are readable by the app and not via plain SharedPreferences; (b) `AuthStateNotifier.build()` with no stored tokens → `AuthUnauthenticated` (session expiry path) per spec Section 24.4 (missing)
- [X] T223 [P] [US12] Confirmed Option A: US-AUTH-033 is implicitly required by US-AUTH-032 + spec AC-012 ("A blocked PIN can be unblocked through OTP-verified recovery" — applies to all actors). Section 18.2 Agent list was incomplete; `pin_unblock_screen.dart` implementation is correct and in-scope. No code change required. (unrequested → resolved)

---

## Dependencies & Execution Order

### Phase Dependency Graph

```
Phase 1 (Scaffolding)
    ↓
Phase 2 (Contracts) ← can overlap with Phase 3
    ↓
Phase 3 (Persistence)
    ↓
Phase 4 (Config Seed)
    ↓
Phases 5–15 (Domain Work — all depend on Phases 1–4)
    │
    ├── Phase 5 (Registration) → Phase 6 (Login) → Phase 8 (Session/Refresh)
    ├── Phase 7 (JWKS/Token) → Phase 14 (Service Identity)
    ├── Phase 9 (Device) ← integrated into Phase 6
    ├── Phase 10 (Admin Session) ← depends on Phase 8
    ├── Phase 11 (PIN) ← depends on Phase 3 only
    ├── Phase 12 (MFA) ← depends on Phases 6, 7
    ├── Phase 13 (Step-Up) ← depends on Phases 12, 7, 4
    └── Phase 15 (Config API) ← depends on Phase 4
    ↓
Phase 16 (Events/Audit Audit) — validate across all backend phases
    ↓
Phases 17–19 (UI) — depend on all backend APIs complete
    ↓
Phase 20 (Observability) — instrument incrementally through Phases 5–16
    ↓
Phase 21 (Performance/Security Tests)
    ↓
Phase 22 (E2E)
    ↓
Phase 23 (Polish)
```

### MVP Scope (Phases 1–8)

Complete the following to reach a working registration → OTP → PIN login → token issuance → session lifecycle MVP:

1. Phase 1: Setup & Scaffolding
2. Phase 2: Domain Contracts
3. Phase 3: Identity Persistence
4. Phase 4: Configuration Seed
5. Phase 5: Registration & OTP — US1 complete
6. Phase 6: Authentication / Login — US2 complete
7. Phase 7: JWKS & Token Issuance — token pipeline complete
8. Phase 8: Session & Refresh Lifecycle — US4 core complete

Validate with quickstart.md Scenarios 1, 2, and 3 before proceeding to MFA, step-up, service identity, and UI phases.

### Parallel Team Strategy

After Phase 4 completes, multiple developers can work in parallel:

| Developer | Phases |
|-----------|--------|
| A | Phase 5 (Registration) → Phase 6 (Login) → Phase 8 (Session) |
| B | Phase 7 (JWKS/Token) → Phase 14 (Service Identity) |
| C | Phase 11 (PIN) → Phase 12 (MFA) → Phase 13 (Step-Up) |
| D | Phase 15 (Config API) → Phase 10 (Admin Session) |
| E | Phase 17 (Flutter Customer) — after Phase 5–8 APIs exist |
| F | Phase 20 (Observability) — instruments as each phase completes |

---

## Phase 26: Convergence

- [X] T224 Fix `setupFilesAfterFramework` typo in `apps/admin/jest.config.ts:9` — correct property name is `setupFilesAfterEnv`; the misspelling silently prevents `__tests__/support/setup.ts` from loading, so `@testing-library/jest-dom` matchers (`toBeInTheDocument`, `toHaveTextContent`) are never registered and T184 test assertions will throw at runtime (partial)
- [X] T225 [P] Add `'auth.otp.provider'` to the OTP key array in `CONFIG_SECTIONS` in `apps/admin/src/modules/configuration/api/security-config.ts` — spec §8.1 lists 25 configuration keys; current array contains 24; `auth.otp.provider` (spec line 267) is absent, causing AC-022 ("admin can view all 25 config keys") to be unmet (partial)

## Contract Deviation Resolution (Pre-Convergence)

Resolved 2026-08-22 by `/speckit-clarify`. All 8 CDEs from the Runtime Contract Gate closed.

| CDE | Status | Resolution |
|-----|--------|-----------|
| CDE-001 | Resolved | Domain error envelope uses `trace_id`; request format validation uses Platform Foundation envelope with `correlation_id`. Gate test updated to test domain errors. |
| CDE-002 | Resolved | `JwksController::index()` now echoes `X-Correlation-ID` header. |
| CDE-003 | Resolved | Login request field renamed from `pin`/`password` → `credential` in `LoginRequest` and `AuthController`. All tests updated. |
| CDE-004 | Resolved | Contract updated to match implementation: `X-Setup-Token` header + `{"success": true}` response for `POST /v1/auth/pin/set`. |
| CDE-005 | Resolved | `IDENTITY_009` (Token revoked) reserved; device verification uses new `IDENTITY_023` (Device verification required). |
| CDE-006 | Resolved | `IDENTITY_010` (Insufficient permissions) reserved for Phase 14 RBAC; auth middleware uses new `IDENTITY_022` (Bearer token required). |
| CDE-007 | Resolved | Contract error code table extended with `IDENTITY_016`–`IDENTITY_024`. |
| CDE-008 | Resolved | Login 200 response includes `mfa_required: false` and `new_device_detected: false`. |
| DB-001  | Resolved | JWT config split documented: TTL via session TTL config keys (already seeded); `iss`/`aud` intentionally environment-only via `APP_URL`. |
| IDENTITY_020 conflict | Resolved | `DeviceController` "fingerprint required" now uses `IDENTITY_024`; `IDENTITY_020` reserved for "PIN already set". |

Runtime Contract Gate: 53 pass, 5 skip, 0 fail (post-resolution)
Full regression: 275 pass, 9 skip, 0 fail (post-resolution)

---

## Phase 24: Convergence

**Assessed 2026-08-23 by `/speckit-converge`.** 4 findings (2 HIGH / 2 MEDIUM). Phases 1–11 implementation verified; pending phases 12–23 captured by existing tasks T115–T214.

- [X] T216 Document `POST /v1/auth/pin/unblock` endpoint in `contracts/authentication-api.md` — add a `### POST /v1/auth/pin/unblock` section: request body (`challenge_id` UUID, `otp` 6-digit string), 200 response `{"success": true}`, error responses `IDENTITY_003` (OTP expired), `IDENTITY_006` (user not found), `IDENTITY_018` (setup token or Bearer required), idempotency applied via `X-Idempotency-Key`; auth requirement matches `POST /v1/auth/pin/set` (X-Setup-Token or Bearer); endpoint is already implemented and tested (US-AUTH-033; FR-E01-033; AC-012) (missing)
- [X] T217 Document `POST /v1/admin/sessions/{session_id}/revoke` endpoint in `contracts/authentication-api.md` — add a `### POST /v1/admin/sessions/{session_id}/revoke` section under a new `## Admin Session Management` heading: path parameter `session_id` (UUID), empty body, 200 response `{"success": true, "data": {"revoked_at": "<timestamp>"}}`, errors `IDENTITY_006` (session not found), `IDENTITY_022` (bearer required), requires `platform.admin.sessions.revoke` permission; idempotency applied; endpoint is already implemented and tested (US-AUTH-025; FR-E01-025; AC-019) (missing)
- [X] T218 Write email registration feature test in `Tests/Feature/Registration/RegistrationTest.php` — register with `email` (not `mobile_number`), assert 202 returned; verify OTP; assert `identity.users.status = active`, `mobile_number IS NULL`, and `email` matches submitted value; assert duplicate email returns 409 without identifier disclosure; assert audit event created (US-AUTH-002; FR-E01-002; AC-003) (partial)
- [X] T219 Reconcile `spec.md` section 13.1 endpoint table with actual implemented routes and `contracts/authentication-api.md` — correct US-AUTH-025 path from `/v1/auth/sessions/{id}/revoke` to `/v1/admin/sessions/{session_id}/revoke`; correct US-AUTH-028 from `POST /v1/auth/pin` to `POST /v1/auth/pin/set`; correct US-AUTH-029 from `PUT /v1/auth/pin` to `POST /v1/auth/pin/change`; correct US-AUTH-030 from `POST /v1/auth/pin/reset` to `POST /v1/auth/pin/reset/initiate` + `POST /v1/auth/pin/reset/complete`; add US-AUTH-033 row `POST /v1/auth/pin/unblock` (currently absent from table) (US-AUTH-025, US-AUTH-028–033; spec section 13.1) (contradicts)

## Phase 25: Convergence

**Assessed 2026-08-23 by `/speckit-converge`.** 2 findings (1 HIGH / 1 LOW). Phases 1–11 fully verified; all implemented routes now cross-checked against `contracts/authentication-api.md`; pending phases 12–23 remain captured by existing tasks T115–T214.

- [X] T220 Document `POST /v1/auth/devices/verify` endpoint in `contracts/authentication-api.md` — add a `### POST /v1/auth/devices/verify` section under `## Device Management`: request body (`challenge_id` UUID, `otp` 6-digit string), 200 response `{"success": true}`, error responses `IDENTITY_002` (invalid/expired challenge), `IDENTITY_003` (OTP invalid/expired), `IDENTITY_024` (device fingerprint required); auth requirement: partial session (device fingerprint header required, no full Bearer); idempotency required; endpoint implemented in Phase 9 (T097) and fully tested (US-AUTH-017; FR-E01-016) (missing)
- [X] T221 Correct HTTP method for biometric challenge in `contracts/authentication-api.md` — change `POST /v1/auth/login/biometric/challenge` (line 436) to `GET /v1/auth/login/biometric/challenge` to match the registered route `Route::get('auth/login/biometric/challenge', ...)` in `routes/modules/identity.php:38`; update the step description accordingly; note GET method means no request body (user_id and device_fingerprint must be query parameters or are not required for the stub) (TBD-004; Phase 9 stub) (contradicts)

## Phase 26: Convergence

**Assessed 2026-08-24 by `/speckit-converge`.** 4 findings (1 HIGH / 2 MEDIUM / 1 LOW). Phase 12 MFA fully implemented (T115–T123 all [X]; 270 regression tests pass, 0 failures); gaps are contract/implementation inconsistencies and missing contract documentation for three Phase 12 FIDO2/disable endpoints.

- [X] T222 Reconcile login MFA-pending response field names between implementation and `contracts/authentication-api.md` — implementation returns `methods` (array), `expires_in` (integer seconds), and `mfa_required: true`; contract MFA login response section specifies `mfa_methods` and `expires_at` (ISO-8601 timestamp); preferred resolution: update contract to use `methods` + `expires_in` + `mfa_required` to match implementation (implementation is more ergonomic and already tested); update the contract response schema and example; if contract is authoritative instead, update `AuthController.php` MfaRequiredException catch block and all Phase 12 MFA tests that assert `data.methods` and `data.expires_in`; update E01RuntimeContractGateTest GATE-14 accordingly (FR-E01-036; AC-018) (contradicts)
- [X] T223 Document the three Phase 12 FIDO2/disable endpoints in `contracts/authentication-api.md` — add contract sections for `POST /v1/auth/mfa/enroll/fido2` (request: none beyond Bearer; response: enrollment_id, challenge), `POST /v1/auth/mfa/enroll/fido2/confirm` (request: enrollment_id, credential_id, public_key, sign_count; response: success), and `POST /v1/auth/mfa/disable` (request: method; response: success); include auth requirement (Bearer JWT via identity.auth middleware), error codes, and idempotency notes; endpoints implemented in `MfaController.php` and routed in `routes/modules/identity.php` lines 118–122 (FR-E01-034; FR-E01-035) (missing)
- [X] T224 Reconcile `IDENTITY_014` HTTP status code between contract and implementation — `contracts/authentication-api.md` error table maps IDENTITY_014 to HTTP 400; `MfaController::verify()` and Phase 12 MFA tests assert HTTP 401 for invalid/expired MFA code; preferred resolution: update contract error table to HTTP 401 (semantically correct for authentication failure — the token/code is wrong, not malformed); if 400 is chosen as authoritative, update `MfaController.php` verify() and all Phase 12 tests asserting 401 for IDENTITY_014 (authentication-api.md error table; FR-E01-036) (contradicts)
- [X] T225 Clarify `IDENTITY_019` semantic scope in `contracts/authentication-api.md` — error table defines IDENTITY_019 as "PIN field required"; `MfaController` uses this code for MFA enrollment field validation failures (enrollment_id / totp_code absent); determine whether IDENTITY_019 covers all "required field missing" cases or is PIN-specific; if PIN-specific, allocate a new error code for MFA field validation and update `MfaController.php`, Phase 12 MFA tests asserting `IDENTITY_019`, and the contract error table; if general, update the contract description to "Required field missing" (authentication-api.md error table; FR-E01-034) (unrequested)

## Phase 27: Convergence

**Assessed 2026-08-24 by `/speckit-converge`.** 3 findings (0 CRITICAL / 1 HIGH / 2 MEDIUM). Phase 13 Step-Up fully implemented (T124–T133 all [X]; 316 regression tests pass, 0 failures); gaps are a missing JWT claim, an incomplete contract response variant, and a hard-coded policy constant.

- [X] T226 Add `step_up_verified_at` claim to `TokenService::issueStepUpToken()` — insert `->withClaim('step_up_verified_at', time())` into the JWT builder chain (after `->withClaim('step_up_scope', $scope)`); update `StepUpSecurityTest.php` and `StepUpTest.php` to assert `$claims['step_up_verified_at']` is a positive integer equal to or near `time()` at verification time; this claim is explicitly required by spec §10.1 and contracts/authentication-api.md lines 1005–1011 (spec §10.1; contracts lines 1005–1011; FR-E01-037) (partial)
- [X] T227 Update `contracts/authentication-api.md` `POST /v1/auth/step-up/initiate` 200 response to document both response variants — add `step_up_required` boolean as the top-level discriminator field in the data object; show the "step_up_required: true" variant (with `step_up_token`, `methods`, `expires_at`) and the "step_up_required: false" variant (data contains only `step_up_required: false`, no token fields); implementation already returns both correctly but the contract at lines 717–727 omits the field and only documents the "required" path (FR-E01-046; BR-023) (partial)
- [X] T228 Replace `private const CHALLENGE_TTL = 300` in `StepUpService.php` with a runtime config read — change `initiateChallenge()` to call `$this->config->getStepUpTokenTtl()` for both the Redis `setex` TTL and the `addSeconds()` call so that the challenge lifetime tracks the configured JWT TTL; remove the constant; update the docblock; after the change re-run `StepUpTest.php` and `StepUpSecurityTest.php` to confirm behaviour is unchanged (BR-016; Constitution Principle 3: policy values from configuration, not code) (partial)

## Phase 28: Convergence

**Assessed 2026-08-24 by `/speckit-converge`.** 1 finding (0 CRITICAL / 0 HIGH / 1 MEDIUM). Phase 15 Configuration Admin API fully implemented (T143–T151 all [X]; 349 regression tests pass, 0 failures; 76 GATE-27 contract gate checks pass); single gap is a response-envelope key mismatch on the IDENTITY_016 conflict response.

- [X] T229 Fix IDENTITY_016 conflict response envelope in `ConfigurationController::conflictResponse()` — change key `meta` to `data` so the response matches `contracts/configuration-api.md` line 117: `error.data.current_updated_at` and `error.data.current_updated_by`; update `ConfigurationConcurrencyTest.php` to assert `$resp->json('error.data.current_updated_at')` (not `error.meta.*`); re-run `php artisan test --filter="Configuration"` to confirm all tests still pass (contracts/configuration-api.md:109–123; FR-E01-045; AC-025) (partial)

## Phase 29: Convergence

**Assessed 2026-08-24 by `/speckit-converge`.** 3 findings (0 CRITICAL / 1 HIGH / 1 MEDIUM / 1 LOW). Phase 16 Event/Audit Wiring verified (T152–T157 all [X]; 418 regression tests pass, 0 failures; 76 E01 Contract Gate pass); two event emission gaps and one unrequested-audit-action review remain.

- [X] T230 Fix registration OTP outbox event names to match spec Section 15 — create `RegistrationOtpRequestedEvent.php` with `eventName()` returning `Identity.User.RegistrationOtpRequested` and `RegistrationOtpResendRequestedEvent.php` returning `Identity.User.RegistrationOtpResendRequested`; update `RegistrationService.register()` to publish `RegistrationOtpRequestedEvent` (not `OtpSentEvent`) and `RegistrationService.resendOtp()` to publish `RegistrationOtpResendRequestedEvent`; preserve `challengeType`, `channelType`, `maskedDestination` payload fields in both; update `Tests/Integration/Events/EventPublishingTest.php` to assert both correct event names; current `OtpSentEvent` (name `Identity.OtpChallenge.Sent`) does not match either required spec event and will break E17 consumers (spec Section 15, FR-E01-003, FR-E01-004) (partial)
- [X] T231 [P] Wire `Identity.Session.Expired` emission in `SessionCleanupJob` — before the terminal-session purge pass, add a first pass that finds sessions with `status = 'active'` AND `expires_at < NOW()`; for each such session, wrap a DB `identity` UPDATE (status → `expired`) and a Platform Foundation `EventPublisherInterface` publish of `SessionExpiredEvent` in a single `DB::connection('platform')->transaction()` (outbox atomicity); inject `EventPublisherInterface` into the job constructor; add an integration test asserting expired sessions emit `Identity.Session.Expired` with correct `session_id` and `user_id`; active sessions MUST NOT be deleted in the same pass (spec Section 15, FR-E01-024) (partial)
- [X] T232 [P] Review and document the four unrequested audit actions beyond the spec Section 16 18-action table — `identity.otp.sent` (RegistrationService.php:138), `identity.session.token-rotated` (RefreshTokenService.php:108), `identity.device.registered` (DeviceController.php:72), `identity.device.revoked` (DeviceController.php:126); for each: confirm whether it is justified by a functional requirement (FR-E01-xxx or BR-xxx) not captured in the Section 16 table; document justification as a code comment or remove the action; update `AuditEventTest.php` to reflect any changes; all 18 required Section 16 actions MUST remain intact (spec Section 16) (unrequested)

## Phase 30: Convergence

**Assessed 2026-08-24 by `/speckit-converge`.** 1 finding (0 CRITICAL / 1 HIGH / 0 MEDIUM / 0 LOW). Phase 17 Flutter Customer UI implemented (T158–T173 all [X]; 45 Flutter auth tests pass, 418 backend regression tests pass, 76 E01 Contract Gate pass); single gap is the PIN blocked/unblock UI flow required by US-AUTH-032/033.

- [X] T233 Create `apps/customer/lib/modules/auth/screens/pin/pin_unblock_screen.dart` — two-step OTP unblock flow calling `AuthApiService.initiatePinUnblock()`/`completePinUnblock()` through new `AuthStateNotifier` transitions (`initiatePinUnblock`/`completePinUnblock`); add `GoRoute(path: '/auth/pin/unblock', ...)` to `apps/customer/lib/app/router.dart`; update `apps/customer/lib/modules/auth/screens/locked/account_locked_screen.dart` "Recover via SMS" CTA from `Navigator.of(context).pushNamed('/auth/pin/reset')` to `context.go('/auth/pin/unblock')` (go_router-compatible); add widget tests for the unblock screen; per US-AUTH-032, US-AUTH-033 (partial)

---

## Phase 31: Convergence

**Assessed 2026-08-25 by `/speckit-converge`.** 1 MEDIUM finding for Phase 20. Phase 20 Observability & Metrics is otherwise fully implemented (T186–T194 all [X]; 446 full-regression pass, 36 security/contract-gate pass, 0 failures); single gap is auth.failure not emitted when a locked account attempts to authenticate.

- [X] T234 Emit `identity.auth.failure` with `reason='account_locked'` inside `LockoutService.assertNotLocked()` — add `$this->metrics->authFailure($user->actor_type, 'account_locked')` immediately before `throw new AccountLockedException(...)` in the "still locked" branch; `IdentityMetrics` is already constructor-injected into `LockoutService`; add a test in `tests/Feature/Observability/ObservabilityTest.php` that stubs a locked user and asserts `identity.auth.failure[actor_type=customer,reason=account_locked]` is incremented; spec §12 `identity.auth.failure` requires "breakdowns by reason (invalid credential, **locked**, etc.)" — the "locked" reason is currently missing (spec §12 auth.failure; partial)

---

## Phase 32: Convergence

**Assessed 2026-08-25 by `/speckit-converge`.** T234 resolved (locked-account auth.failure now emitted). 1 MEDIUM finding remains: `identity.token.refresh` counts only successful rotations; spec §12 requires "success and failure". `IdentityMetrics` is already injected in `RefreshTokenService`.

- [X] T235 Emit `identity.token.refresh` metric on failure paths inside `RefreshTokenService.rotate()` so the counter tracks both success and failure as required — `IdentityMetrics` is already constructor-injected (line 37); add `$this->metrics->tokenRefresh()` (or a `result`-labelled variant) before each throw: (a) before `throw InvalidCredentialsException::make()` at line 63 (null session) and line 77 (null user), and (b) before `throw RefreshTokenReuseException::make()` at line 72; optionally introduce a `result='success'|'failure'` label — update `IdentityMetrics.tokenRefresh()` signature and the existing success call at line 136 accordingly, keeping `result` low-cardinality and PII-free; add tests to `tests/Feature/Observability/ObservabilityTest.php` asserting the counter increments on failure inputs; per spec §12 observability table (`identity.token.refresh` — "Token refresh attempts; success and failure"; partial)

---

## Phase 33: Convergence

**Assessed 2026-08-25 by `/speckit-converge`.** 3 findings (0 CRITICAL / 2 HIGH / 0 MEDIUM / 1 LOW). Phase 21 Performance & Security Tests implemented (T195–T201 all [X]; 493 full-regression pass, 7 skip, 0 failures); two security test and middleware configuration gaps remain, plus one low-severity K6 structure deviation.

- [X] T236 Add "unknown kid rejected" test to `services/api/app/Modules/Identity/Tests/Security/JwtTest.php` — present a JWT whose `kid` header value is absent from the JWKS served by `GET /v1/.well-known/jwks.json` (construct a forged JWT with `kid: "unknown-kid-value"` and an otherwise plausible header/payload); submit as Bearer on `GET /v1/auth/sessions`; assert HTTP 401; this is an explicit T200 requirement ("unknown kid rejected") and a plan §Security Tests requirement ("token with unknown kid rejected") that was not included in the Phase 21 implementation; groups: `identity`, `security`, `jwt` (missing)
- [X] T237 Register Laravel named rate limiters for identity throttle aliases in `services/api/app/Modules/Identity/Infrastructure/Providers/IdentityServiceProvider.php` — call `RateLimiter::for('identity-public', fn($req) => Limit::perMinute($this->config->getRateLimitPublic()))`, `RateLimiter::for('identity-refresh', ...)`, `RateLimiter::for('identity-auth', ...)`, `RateLimiter::for('identity-admin', ...)` in `IdentityServiceProvider::boot()`; rate limit values MUST be sourced from `ConfigurationSecurityService` — not hard-coded (BR-016, Constitution Principle 3); update `ApiTest.php` "Rate limit headers" test to assert `X-RateLimit-Limit` and `X-RateLimit-Remaining` are present on public endpoints; remove the "environment/deployment limitation" note from the test docblock once implemented; per plan §Middleware Stack and spec §24.6 (partial)
- [X] T238 [P] Create `services/api/app/Modules/Identity/Tests/Performance/config.js` per plan §Performance Plan — export a shared `SMOKE_TEST_DEFAULTS` object and a default `options` block with `vus: __ENV.K6_VUSER_COUNT || 1`, `duration: __ENV.K6_DURATION || '10s'`, and threshold templates for each hot path; update `login.js`, `token-refresh.js`, and `otp-verify.js` to import and spread `SMOKE_TEST_DEFAULTS` from `./config.js` rather than each defining their own constant; `setup.js` retains its test-data seeding role unchanged; this aligns the Performance test harness with plan §Performance Plan which names `config.js` as the shared K6 configuration module (partial)

---

## Phase 34: E02 Contact Management Contract Extension

**Purpose**: Provide the concrete E01 implementation of `getUserContactInfo()` and `updateUserContact()` required by E02 ADR-E02-001. E02 task T011 (Phase 1 of Sprint 002) adds the two method signatures to `IdentityModuleContract`, creates the `UserContactInfo` DTO in `App\Modules\Identity\Domain\Contracts\`, and creates `IdentityContactUpdateException` in `App\Modules\Identity\Domain\Exceptions\`. This phase delivers the repository method and service implementations that make those contracts callable. **Gate**: These tasks (T239–T244) must be complete before E02 Phase 3 integration tests run; without them, `CreateProfileOnUserRegisteredListener` and `ContactChangeService` will throw `BadMethodCallException` at runtime.

- [X] T239 Add `updateContactInfo(string $userId, ?string $email, ?string $mobile): void` to `UserRepositoryInterface` in `services/api/app/Modules/Identity/Domain/Contracts/UserRepositoryInterface.php` — contract: atomically updates `identity.users.email` and/or `identity.users.mobile_number` for the given userId; null argument means leave the existing field value unchanged; throws `IdentityContactUpdateException` (`App\Modules\Identity\Domain\Exceptions\IdentityContactUpdateException`, defined by E02 T011) when userId not found (zero rows affected) or when a DB unique constraint violation occurs (new email or mobile_number conflicts with another user record); both arguments null is a silent no-op (ADR-E02-001; E02 module-contracts.md §IdentityModuleContract Extensions; depends on E02 T011 creating IdentityContactUpdateException)

- [X] T240 [P] Implement `updateContactInfo()` in `EloquentUserRepository` (`services/api/app/Modules/Identity/Infrastructure/Repositories/EloquentUserRepository.php`) — build `$fields = []` only from non-null arguments; early-return if `$fields` is empty (both null); execute `DB::connection('identity')->table('identity.users')->where('id', $userId)->update($fields)` and capture affected row count; if `$affected === 0` throw `new IdentityContactUpdateException('User not found: '.$userId)`; catch `\Illuminate\Database\UniqueConstraintViolationException $e` and re-throw as `new IdentityContactUpdateException($e->getMessage(), 0, $e)`; do NOT catch generic `\Throwable`; the UNIQUE partial indexes on `identity.users(email)` and `identity.users(mobile_number)` (both WHERE NOT NULL) provide the DB-level uniqueness enforcement (data-model.md identity.users; ADR-E02-001)

- [X] T241 Implement `getUserContactInfo(string $userId): ?UserContactInfo` in the concrete `IdentityModuleContract` implementation class (`services/api/app/Modules/Identity/Domain/Services/IdentityModuleService.php`, created by T140) — call `$user = $this->userRepository->findById($userId)`; if null return null; return `new \App\Modules\Identity\Domain\Contracts\UserContactInfo(userId: $user->id, email: $user->email, mobileNumber: $user->mobile_number, actorType: $user->actor_type)` where `actor_type` is already stored as CUSTOMER|AGENT|ADMIN|ENTERPRISE per data-model.md — no enum mapping required; the `UserContactInfo` class is defined by E02 T011 (ADR-E02-001; data-model.md Table identity.users actor_type column)

- [X] T242 [P] Implement `updateUserContact(string $userId, ?string $email, ?string $mobile): void` in the concrete `IdentityModuleContract` implementation (same class as T241, `IdentityModuleService.php`) — delegate entirely to `$this->userRepository->updateContactInfo($userId, $email, $mobile)`; do NOT catch `IdentityContactUpdateException`; propagate it to the caller — E02's `ContactChangeService` wraps this call in `DB::transaction()` and expects the exception to surface so the transaction rolls back; swallowing the exception here would silently corrupt the contact change flow (ADR-E02-001; E02 plan §Atomic cross-module contact update)

- [X] T243 Write unit tests in `services/api/app/Modules/Identity/Tests/Unit/Services/IdentityModuleServiceContactTest.php`: (a) `getUserContactInfo()` — mock `UserRepositoryInterface::findById()` returning a hydrated User stub → assert returned `UserContactInfo` has matching userId, email, mobileNumber, and actorType values; (b) `getUserContactInfo()` — mock returning null → assert method returns null; (c) `updateUserContact()` — assert it calls `UserRepositoryInterface::updateContactInfo()` with identical userId, email, and mobile arguments; (d) `updateUserContact()` — mock throws `IdentityContactUpdateException` → assert the same exception propagates out of `updateUserContact()` without wrapping; use mock `UserRepositoryInterface`, no Eloquent or DB (ADR-E02-001; constitution Principle 12)

- [X] T244 [P] Write integration tests in `services/api/app/Modules/Identity/Tests/Integration/Repositories/EloquentUserRepositoryContactIntegrationTest.php` against the `identity` DB connection (factory-created users, transaction-wrapped rollback on teardown): (a) non-null email + null mobile → only email updated, mobile_number unchanged in DB; (b) null email + non-null mobile → only mobile_number updated, email unchanged; (c) both non-null → both fields updated; (d) both null → no-op, updated_at unchanged; (e) new email already exists on a different user → throws `IdentityContactUpdateException`; (f) new mobile_number already exists on a different user → throws `IdentityContactUpdateException`; (g) userId not found → throws `IdentityContactUpdateException`; all assertions query `identity.users` directly via `DB::connection('identity')` (ADR-E02-001; E02 Phase 3 gate; data-model.md UNIQUE partial indexes)
