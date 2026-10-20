# Implementation Plan — E02 User & Profile Management

**Feature**: `002-user-profile-management` | **Epic**: E02 | **Date**: 2026-08-27 | **Status**: Ready for Tasks

---

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — no stack changes; no ADRs required beyond E02-specific decisions below
- [x] No TBD value invented — TBD-E02-001 (photo provider), TBD-E02-003 (export format — JSON chosen as technical detail, delivery TBD), TBD-E02-004 (minimum age default value), TBD-E02-005 (maker-checker config) remain TBD; config key names resolved as technical decisions
- [x] Authorization remains server-side — IDOR prevention at service layer; permission checks via E01 JWT claims; admin permission names per spec
- [x] Financial integrity preserved — E02 has no financial operations; closure gate is fail-safe stub until E06/E11 wire real implementations
- [x] Security/audit requirements incorporated — 14 audit actions; PII masking; HMAC-SHA256 IP; dual-event security alert on contact change
- [x] UX/design-system requirements incorporated — OleKard design system reused; all loading/success/error states required; no second design system
- [x] QA strategy incorporated — unit, integration, API, security, concurrency, performance, Flutter, Next.js, E2E
- [x] Platform Foundation consumed, not recreated — uses outbox, audit, config, idempotency, correlation ID services exclusively

**New architecture decisions (document in `docs/adrs/` during implementation)**:

| ADR | Decision |
|---|---|
| ADR-E02-001 (proposed) | Synchronous `IdentityModuleContract` extension for cross-module contact update (TBD-E02-002 resolution) |
| ADR-E02-002 (proposed) | `ProfilePhotoStorageContract` abstraction; `LocalDiskProfilePhotoStorage` for dev; production provider TBD-E02-001 |
| ADR-E02-003 (proposed) | `FinancialObligationContract` fail-safe stub pattern until E06/E11 supply implementations |

---

## Technical Context

| Concern | Decision |
|---|---|
| Backend | Laravel / PHP 8.4+ (modular monolith, `App\Modules\Profile\`) |
| Database | PostgreSQL; `profile` schema; `profile` named connection (ADR-002, ADR-003) |
| Queue / Cache | Redis + Laravel Horizon |
| Web Admin | Next.js + TypeScript |
| Mobile | Flutter + Riverpod |
| API | REST/OpenAPI; `/v1/` prefix; Platform Foundation envelope |
| Photo validation | `intervention/image-laravel` package (verify/add) |
| Metrics | OTel Meter (`profile`); standard set (see Observability section) |
| Performance Tests | K6; parametric load (concurrent users TBD at run time) |
| Module namespace | `App\Modules\Profile\` |

---

## Architecture Decisions

Existing Platform Foundation ADRs apply directly:

| ADR | Application to E02 |
|---|---|
| ADR-002 Database Connection Naming | E02 uses connection name `profile` |
| ADR-003 Schema-per-Module | E02 owns `profile.*` schema; no cross-schema JOINs |
| ADR-004 Idempotency Strategy | E02 idempotent endpoints use `platform.idempotency_records` via `IdempotencyServiceInterface` |
| ADR-005 Outbox Pattern | All E02 integration events via `EventPublisherInterface` + `platform.outbox_events` |

New E02-specific ADRs (to be documented during implementation):

| ADR | Decision | Rationale |
|---|---|---|
| ADR-E02-001 | Synchronous `IdentityModuleContract` extension for cross-module contact updates | Transaction-safe; no new event infrastructure; consistent with module boundary pattern |
| ADR-E02-002 | `ProfilePhotoStorageContract` abstraction | TBD-E02-001 provider deferral; extraction-ready |
| ADR-E02-003 | Fail-safe `FinancialObligationContract` stub | Prevents accidental closure while E06/E11 are unavailable |

---

## Module Structure

```
services/api/app/Modules/Profile/
├── Domain/
│   ├── Contracts/
│   │   ├── ProfilePhotoStorageContract.php        # Photo storage abstraction (TBD-E02-001)
│   │   ├── FinancialObligationContract.php        # Closure gate abstraction (E06/E11 stub)
│   │   └── ProfileModuleContract.php              # Public surface for other modules
│   ├── Entities/
│   │   ├── UserProfile.php                        # Domain entity (not Eloquent)
│   │   ├── AccountStatusHistory.php
│   │   └── ContactChangeChallenge.php
│   ├── Services/
│   │   ├── ProfileService.php                     # View/update personal info
│   │   ├── ProfilePhotoService.php                # Photo upload, validation, cleanup
│   │   ├── ContactChangeService.php               # Email/mobile change + verify (dual-event alert)
│   │   ├── AccountStatusService.php               # Suspend, reactivate, close, cancel-closure
│   │   ├── ClosureGateService.php                 # Financial obligation check gate
│   │   └── DataExportService.php                  # Export compilation + signed URL
│   ├── Exceptions/
│   │   ├── ProfileNotFoundException.php
│   │   ├── ContactChangeInProgressException.php
│   │   ├── SameContactValueException.php
│   │   ├── InvalidStatusTransitionException.php
│   │   ├── ClosureBlockedByObligationsException.php
│   │   ├── ExportInProgressException.php
│   │   ├── MinimumAgeViolationException.php
│   │   └── FieldNotPermittedException.php
│   └── ValueObjects/
│       ├── AccountStatus.php                      # active|suspended|pending_closure|closed
│       └── ContactType.php                        # email|mobile
├── Application/
│   ├── Commands/
│   │   ├── CreateProfileCommand.php               # Triggered by Identity.User.Registered
│   │   ├── UpdatePersonalInfoCommand.php
│   │   ├── UploadProfilePhotoCommand.php
│   │   ├── InitiateEmailChangeCommand.php
│   │   ├── VerifyEmailChangeCommand.php
│   │   ├── InitiateMobileChangeCommand.php
│   │   ├── VerifyMobileChangeCommand.php
│   │   ├── SuspendAccountCommand.php
│   │   ├── ReactivateAccountCommand.php
│   │   ├── RequestClosureCommand.php
│   │   ├── CancelClosureCommand.php
│   │   ├── PermanentlyCloseAccountCommand.php
│   │   └── RequestDataExportCommand.php
│   ├── Queries/
│   │   ├── GetProfileQuery.php
│   │   ├── GetAccountStatusQuery.php
│   │   └── GetDataExportStatusQuery.php
│   └── DTOs/
│       ├── ProfileDTO.php
│       ├── AccountStatusDTO.php
│       └── DataExportDTO.php
├── Infrastructure/
│   ├── Storage/
│   │   └── LocalDiskProfilePhotoStorage.php       # Dev/test implementation of ProfilePhotoStorageContract
│   ├── Stubs/
│   │   └── StubFinancialObligationService.php     # Fail-safe stub (always blocks)
│   ├── Models/
│   │   ├── UserProfileModel.php                   # profile.user_profiles
│   │   ├── AccountStatusHistoryModel.php          # profile.account_status_history
│   │   └── DataExportModel.php                    # profile.data_exports
│   ├── Repositories/
│   │   ├── EloquentUserProfileRepository.php
│   │   ├── EloquentAccountStatusHistoryRepository.php
│   │   └── EloquentDataExportRepository.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── ProfileController.php              # GET, PUT /v1/users/profile/personal
│   │   │   ├── ProfilePhotoController.php         # POST /v1/users/profile/photo
│   │   │   ├── ContactChangeController.php        # /email/change, /email/verify, /mobile/change, /mobile/verify
│   │   │   ├── AccountStatusController.php        # GET /v1/users/profile/status
│   │   │   ├── ClosureController.php              # POST, DELETE /v1/users/profile/closure/request
│   │   │   ├── DataExportController.php           # POST, GET /v1/users/profile/data-export
│   │   │   └── AdminProfileController.php         # All /v1/admin/users/{user_id}/... routes
│   │   ├── Requests/
│   │   │   ├── UpdatePersonalInfoRequest.php
│   │   │   ├── InitiateEmailChangeRequest.php
│   │   │   ├── VerifyContactChangeRequest.php
│   │   │   ├── InitiateMobileChangeRequest.php
│   │   │   ├── SuspendAccountRequest.php
│   │   │   └── CancelClosureRequest.php
│   │   └── Resources/
│   │       ├── ProfileResource.php
│   │       ├── AccountStatusResource.php
│   │       └── DataExportResource.php
│   ├── Migrations/
│   │   ├── 2026_xx_xx_001_create_profile_schema_and_user_profiles_table.php
│   │   ├── 2026_xx_xx_002_create_profile_account_status_history_table.php
│   │   ├── 2026_xx_xx_003_create_profile_data_exports_table.php
│   │   └── 2026_xx_xx_004_seed_profile_configuration_defaults.php
│   ├── Jobs/
│   │   ├── ProcessDataExportJob.php               # Async: compile JSON + store + emit DataExportReady
│   │   └── ExpiredContactChallengeCleanupJob.php  # Purge stale pending_email/pending_mobile rows
│   ├── Listeners/
│   │   └── CreateProfileOnUserRegisteredListener.php  # Consumes Identity.User.Registered
│   └── Providers/
│       └── ProfileServiceProvider.php
└── Tests/
    ├── Unit/
    ├── Integration/
    ├── Feature/
    ├── Security/
    └── Performance/
```

---

## Database Plan

### Schema Ownership

E02 owns the `profile` PostgreSQL schema exclusively. Migration 001 begins with `CREATE SCHEMA IF NOT EXISTS profile`. All tables use the `profile` named database connection (ADR-002). No cross-schema foreign keys (ADR-003).

**Named connection**: `profile`  
**Config key**: `database.connections.profile`  
**Search path**: `profile`

### Migrations (ordered)

All migrations use `DB::connection('profile')` and fully qualify table names as `profile.{table}`.

| # | Migration | Creates |
|---|---|---|
| 001 | `create_profile_schema_and_user_profiles_table` | `profile` schema + `profile.user_profiles` |
| 002 | `create_profile_account_status_history_table` | `profile.account_status_history` |
| 003 | `create_profile_data_exports_table` | `profile.data_exports` |
| 004 | `seed_profile_configuration_defaults` | Rows in `platform.configuration_values` |

Migration 004 uses `DB::connection('platform')` and `insertOrIgnore` — idempotent; never overwrites. TBD config values are inserted with `null` value and `is_tbd = true`.

### Indexes

See `data-model.md` for full index definitions. Key indexes:

| Table | Index | Type | Purpose |
|---|---|---|---|
| `profile.user_profiles` | `user_id` | UNIQUE | Profile lookup; prevent duplicates |
| `profile.user_profiles` | `account_status` | B-tree | Admin queries by status |
| `profile.user_profiles` | `email` | B-tree | Contact uniqueness check |
| `profile.user_profiles` | `mobile_number` | B-tree | Contact uniqueness check |
| `profile.account_status_history` | `user_id` | B-tree | History queries per user |
| `profile.account_status_history` | `(user_id, created_at DESC)` | B-tree | Ordered history |
| `profile.data_exports` | `(user_id, status)` | B-tree | In-progress check |

### Retention

| Job | Schedule | Criteria |
|---|---|---|
| `ExpiredContactChallengeCleanupJob` | Hourly | `pending_email IS NOT NULL AND pending_email_challenge_id` references an expired E01 challenge → nullify pending fields |

Expired E01 OTP challenges are cleaned up by E01's own job. E02 nullifies its own `pending_email`/`pending_mobile` fields when `/email/verify` or `/mobile/verify` receives `OTP_EXPIRED` — the expired challenge cannot be retried; the user must re-initiate the change flow to obtain a fresh OTP.

**Important distinction**: OTP_EXPIRED clears the pending fields (challenge is done). An E01 service failure *after* a successful OTP verification (FR-E02-022a, FR-E02-024a) preserves the pending fields so the user can retry the verify call without re-initiating.

---

## API Plan

All endpoints use `/v1/` prefix, Platform Foundation `ApiResponse` envelope, and platform error taxonomy. See `contracts/profile-api.md` for full request/response schemas and error codes.

### Endpoint Map

| Story | Method | Path | Controller | Auth | Idempotent |
|---|---|---|---|---|---|
| US-USER-001 | GET | `/v1/users/profile` | `ProfileController@show` | Bearer | — |
| US-USER-002 | PUT | `/v1/users/profile/personal` | `ProfileController@updatePersonal` | Bearer | Yes |
| US-USER-003 | POST | `/v1/users/profile/photo` | `ProfilePhotoController@store` | Bearer | No |
| US-USER-004 | POST | `/v1/users/profile/email/change` | `ContactChangeController@initiateEmail` | Bearer | Yes |
| US-USER-007 | POST | `/v1/users/profile/email/verify` | `ContactChangeController@verifyEmail` | Bearer | Yes |
| US-USER-005 | POST | `/v1/users/profile/mobile/change` | `ContactChangeController@initiateMobile` | Bearer | Yes |
| US-USER-006 | POST | `/v1/users/profile/mobile/verify` | `ContactChangeController@verifyMobile` | Bearer | Yes |
| US-USER-008 | GET | `/v1/users/profile/status` | `AccountStatusController@show` | Bearer | — |
| US-USER-013 | POST | `/v1/users/profile/closure/request` | `ClosureController@request` | Bearer | Yes |
| US-USER-013 | DELETE | `/v1/users/profile/closure/request` | `ClosureController@cancel` | Bearer | Yes |
| US-USER-015 | POST | `/v1/users/profile/data-export` | `DataExportController@request` | Bearer | Yes |
| US-USER-015 | GET | `/v1/users/profile/data-export/{id}` | `DataExportController@status` | Bearer | — |
| US-USER-001 | GET | `/v1/admin/users/{user_id}/profile` | `AdminProfileController@show` | Admin + `profile.read` | — |
| US-USER-002 | PUT | `/v1/admin/users/{user_id}/profile/personal` | `AdminProfileController@updatePersonal` | Admin + `profile.update` | Yes |
| US-USER-008 | GET | `/v1/admin/users/{user_id}/profile/status` | `AdminProfileController@status` | Admin + `profile.read` | — |
| US-USER-010 | POST | `/v1/admin/users/{user_id}/suspend` | `AdminProfileController@suspend` | Admin + `account.suspend` | Yes |
| US-USER-011 | POST | `/v1/admin/users/{user_id}/reactivate` | `AdminProfileController@reactivate` | Admin + `account.reactivate` | Yes |
| US-USER-013 | POST | `/v1/admin/users/{user_id}/cancel-closure` | `AdminProfileController@cancelClosure` | Admin + `account.reactivate` | Yes |
| US-USER-012 | POST | `/v1/admin/users/{user_id}/close` | `AdminProfileController@close` | Admin + `account.close` | Yes |

### Route Registration

Routes registered in `ProfileServiceProvider::boot()` via `Route::prefix('v1')->group(...)`.

### Middleware Stack

- **Bearer-authenticated (user)**: `cors`, `throttle:profile-user`, `correlation-id`, `identity.auth`, `profile.require-actor-type:user`
- **Admin**: `cors`, `throttle:profile-admin`, `correlation-id`, `identity.auth`, `profile.require-actor-type:admin`, `profile.permission:{permission}`
- **Photo upload**: `cors`, `throttle:profile-photo`, `correlation-id`, `identity.auth`

All throttle rates sourced from `platform.configuration_values` — not hard-coded.

### IDOR Prevention (critical)

Every profile operation in `ProfileService`, `ContactChangeService`, `AccountStatusService`, and `ClosureGateService` MUST:
1. Extract `user_id` from the authenticated JWT (via `IdentityModuleContract::resolveActorFromToken()`)
2. Compare token `user_id` against the resource's `user_id`
3. Mismatch → throw `ForbiddenException` (HTTP 403) — never 404

Admin paths accept `{user_id}` path parameter but require the named permission middleware; without it, HTTP 403 always.

---

## Service Layer Design

### ProfileService

Handles view and personal-info update. Thin: delegates to Eloquent repository. Enforces:
- Mass-assignment protection: only `$fillable` fields accepted; protected fields (`id`, `user_id`, `account_status`, `email`, `mobile_number`, `profile_photo_key`, `pending_*`) rejected with `FieldNotPermittedException`
- Minimum age validation via `ConfigurationService::get('profile.minimum_user_age_years')` (throws `MinimumAgeViolationException` if set and violated)
- Idempotency via `IdempotencyServiceInterface`
- Audit via `AuditServiceInterface`
- Event via `EventPublisherInterface::publish(new PersonalInfoUpdatedEvent(...))`

### ContactChangeService

Most complex service. Handles initiation and verification for both email and mobile.

**Initiation flow** (inside `DB::transaction()`):
1. Validate format (email regex or E.164)
2. Check `new_contact !== current_contact` → `SameContactValueException`
3. Check no pending change of same type → `ContactChangeInProgressException`
4. Check contact not in use by another account (B-tree index query; identical response whether in-use or not)
5. Call `IdentityModuleContract::getUserContactInfo()` if needed (already have contact from token context)
6. Issue OTP challenge via E01 `OtpDeliveryContract` → receive `challenge_id`
7. Store `pending_email`/`pending_mobile` + `pending_*_challenge_id` on profile record
8. Emit `EmailChangeInitiatedEvent` (triggers E17 OTP delivery)
9. Emit `ContactSecurityAlertEvent` targeting old contact (triggers E17 security alert)
10. Both events published atomically with DB update

**Verification flow** (inside `DB::transaction()`):
1. Load profile; assert `pending_*_challenge_id` matches `challenge_id`
2. Verify OTP via E01 infrastructure (challenge lookup + hash compare)
3. Update `profile.user_profiles.email`/`mobile_number` + clear `pending_*` fields
4. Call `IdentityModuleContract::updateUserContact()` → if this throws, entire transaction rolls back; challenge preserved for retry
5. Emit `EmailChangedEvent`/`MobileChangedEvent`
6. Audit

### AccountStatusService

State machine enforcement. Valid transitions verified before any DB write:
- `active → suspended` (admin `account.suspend`)
- `suspended → active` (admin `account.reactivate`)
- `active → pending_closure` (user self-service or admin)
- `pending_closure → active` (user cancel or admin `account.reactivate`)
- `pending_closure → closed` (admin `account.close` + final obligation check)

Each transition writes to both `profile.user_profiles.account_status` and appends a row to `profile.account_status_history` atomically. Event emitted within same transaction.

### ClosureGateService

Calls `FinancialObligationContract::checkObligations()`. Current implementation: `StubFinancialObligationService` (always blocks). E06/E11 implementations are bound in their respective `ServiceProvider`s.

### DataExportService

Request: creates `profile.data_exports` row + dispatches `ProcessDataExportJob` to Horizon queue. Job: queries all profile data, serializes to JSON (excluding credentials), stores via `ProfilePhotoStorageContract`, emits `DataExportReadyEvent` with signed URL.

---

## Event Plan

All events via `EventPublisherInterface` + `platform.outbox_events`. Events emitted within the same DB transaction as the state change.

| Event Name | Trigger | Version | Consumers |
|---|---|---|---|
| `Profile.User.ProfileCreated` | Profile record created | 1.0.0 | E05, E17 |
| `Profile.User.PersonalInfoUpdated` | Personal info updated | 1.0.0 | E17 (optional) |
| `Profile.User.PhotoUpdated` | Photo updated | 1.0.0 | E17 (optional) |
| `Profile.User.EmailChangeInitiated` | Email change OTP issued | 1.0.0 | E17 (OTP delivery) |
| `Profile.User.ContactSecurityAlertSent` | Security alert to old contact | 1.0.0 | E17 (alert delivery) |
| `Profile.User.EmailChanged` | Email change verified + applied | 1.0.0 | E17 (confirmation) |
| `Profile.User.MobileChangeInitiated` | Mobile change OTP issued | 1.0.0 | E17 (OTP delivery) |
| `Profile.User.MobileChanged` | Mobile change verified + applied | 1.0.0 | E17 (confirmation) |
| `Profile.Account.Suspended` | Account suspended | 1.0.0 | E17, E18, downstream |
| `Profile.Account.Reactivated` | Account reactivated | 1.0.0 | E17 |
| `Profile.Account.ClosureRequested` | User requested closure | 1.0.0 | E17, admin workflow |
| `Profile.Account.ClosureCancelled` | Closure cancelled | 1.0.0 | E17 |
| `Profile.Account.ClosureBlocked` | Closure blocked by obligations | 1.0.0 | E17 |
| `Profile.Account.Closed` | Account permanently closed | 1.0.0 | E17, E06, downstream |
| `Profile.User.DataExportRequested` | Export requested | 1.0.0 | E17 (processing notification) |
| `Profile.User.DataExportReady` | Export package ready | 1.0.0 | E17 (download link) |

### Event Payload Standard

All events include Platform Foundation envelope fields: `id`, `aggregate_id` (`user_id`), `aggregate_type` (`Profile.User` or `Profile.Account`), `actor_id`, `correlation_id`, `causation_id`, `occurred_at`, `event_name`, `event_version: "1.0.0"`.

PII masking: email → `j*****@domain.com`; mobile → `+267*****678`. Raw contact values MUST NOT appear in any event payload.

### Consumer Idempotency

`CreateProfileOnUserRegisteredListener` MUST be idempotent. It uses `profile.user_profiles.user_id UNIQUE` constraint — a duplicate `Identity.User.Registered` event triggers a DB `insertOrIgnore`-equivalent that silently succeeds without creating a duplicate record.

---

## Audit Plan

All audit records via `AuditServiceInterface` → `platform.audit_events`. E02 does NOT create a secondary audit registry.

| Audit Action | Severity | Actor ID Rule |
|---|---|---|
| `profile.user.profile-created` | INFO | System (from event listener); zero-UUID or system actor |
| `profile.user.personal-info-updated` | INFO | Authenticated user UUID; admin-path: admin UUID + `subject_user_id` |
| `profile.user.photo-updated` | INFO | Authenticated user UUID |
| `profile.user.email-change-initiated` | INFO | Authenticated user UUID |
| `profile.user.email-changed` | WARN | Authenticated user UUID |
| `profile.user.mobile-change-initiated` | INFO | Authenticated user UUID |
| `profile.user.mobile-changed` | WARN | Authenticated user UUID |
| `profile.account.suspended` | WARN | Admin UUID + `subject_user_id` |
| `profile.account.reactivated` | INFO | Admin UUID + `subject_user_id` |
| `profile.account.closure-requested` | INFO | Authenticated user UUID |
| `profile.account.closure-cancelled` | INFO | User or admin UUID; admin-path: admin UUID + `subject_user_id` |
| `profile.account.closure-blocked` | INFO | System (closure gate evaluation) |
| `profile.account.closed` | WARN | Admin UUID + `subject_user_id` |
| `profile.user.data-export-requested` | INFO | Authenticated user UUID |

**Audit payload rules**:
- Mobile/email in `before_state`/`after_state`: MUST be masked before passing to `AuditService::record()`
- Admin-path operations: `before_state`/`after_state` includes both `actor_id` (admin) and `subject_user_id`
- `ip_address`: `HMAC-SHA256(platform_secret, ip_address)` — same standard as E01 (BR-027)
- Records are INSERT-only; E02 MUST NOT attempt UPDATE or DELETE on `platform.audit_events`

---

## Security Plan

### IDOR / BOLA Prevention

Every service method compares `user_id` from the authenticated JWT to the `user_id` on the loaded record. Admin paths require explicit named permissions checked before any data access. This check is enforced at the **service layer**, not just the controller, so it holds even if a controller route is misconfigured.

### Mass Assignment Protection

`UserProfileModel::$fillable` lists ONLY the user-editable personal info fields. All other columns (`id`, `user_id`, `account_status`, `email`, `mobile_number`, `profile_photo_key`, `pending_*`) are in `$guarded` or absent from `$fillable`. Form requests additionally validate that forbidden fields are not present in the request body.

### PII Protection

| Data | Treatment |
|---|---|
| Mobile numbers in logs | Masked: `+267*****678` |
| Emails in logs | Masked: `j*****@domain.com` |
| `profile_photo_key` | Never returned in any API response; signed URL generated and returned instead |
| `before_state`/`after_state` in audit | PII masked before passing to AuditService |
| Data export | Credentials, tokens, raw IPs excluded; HMAC-SHA256 IP representation only |

### Contact Change Security

- OTP delivered to NEW contact via E01 `OtpDeliveryContract`
- Security alert emitted as separate event to OLD contact (consumed by E17)
- Pending contact fields stored in profile; `challenge_id` is the opaque reference returned to client
- Challenge preserved on atomic update failure so user can retry without re-initiating
- Contact uniqueness check result never disclosed in response (identical response whether in-use or not)

### Photo Security

- `profile_photo_key` never appears in API responses or event payloads
- Signed URL generated with `TTL = profile.photo.signed_url_ttl_seconds` (from config)
- `intervention/image-laravel` used for dimension check — prevents malformed image uploads from reaching storage

### Privileged Operations

Account suspension, reactivation, and permanent closure require Admin Bearer token AND the named permission middleware (`account.suspend`, `account.reactivate`, `account.close`). These endpoints are in the `/v1/admin/` prefix with the admin middleware group.

---

## Flutter Customer Plan (E02 Scope)

Flutter app uses Riverpod for state management (consistent with E01). All profile state lives in `ProfileStateNotifier`.

### Profile State

```
loading
    → loaded (profile data available)
    → error (network/server error)
    → not_found (404 — profile not yet created; retry)
```

### Screens (Customer)

| Screen | Stories | Required States |
|---|---|---|
| Profile: View | US-USER-001 | Loading, loaded (with photo), error, retry |
| Profile: Edit personal info | US-USER-002 | Default, loading, success, validation error, age-violation error |
| Profile: Upload/change photo | US-USER-003 | Default, selecting, uploading, success, size error, type error, dimension error |
| Profile: Change email — enter new | US-USER-004 | Default, loading, success (challenge sent), format error, in-progress error, rate-limit error |
| Profile: Change email — enter OTP | US-USER-007 | Default, loading, success, invalid OTP, expired OTP, retry guidance |
| Profile: Change mobile — enter new | US-USER-005 | Same as email change |
| Profile: Change mobile — enter OTP | US-USER-006 | Same as email verify |
| Profile: Account status | US-USER-008 | Loading, active, suspended, pending-closure, closed; history list |
| Profile: Request closure | US-USER-013 | Default, confirm dialog, loading, success (pending_closure), blocked (with reasons) |
| Profile: Cancel closure | US-USER-013 | Confirm dialog, loading, success (active) |
| Profile: Data export | US-USER-015 | Default, loading, submitted, in-progress error |

All screens: loading skeleton, empty-state, error state with retry CTA, accessibility labels, keyboard navigation, screen-reader compatibility.

### Token Storage (Flutter)

No new storage mechanism — reuses E01's `flutter_secure_storage` pattern for the Bearer token. Profile API calls go through the same `AuthInterceptor` (Dio) that handles token refresh.

---

## Flutter Agent Plan (E02 Scope)

Agent profile is a subset of Customer profile:
- View profile (US-USER-001)
- Update personal information (US-USER-002)
- Update profile photo (US-USER-003)
- Change mobile number + verify (US-USER-005, US-USER-006)
- View account status (US-USER-008)

No email change (Agents use mobile-only registration in E01 scope). No self-service account closure (Agent closure requires E12 workflow).

---

## Next.js Admin Plan (E02 Scope)

Admin screens use the `[data-theme="admin"]` dark teal theme from the OleKard design system. All destructive actions (suspend, close) require confirmation dialogs before form submission.

### Admin E02 Pages

| Page | Route | Stories |
|---|---|---|
| User profile detail | `/admin/users/[id]/profile` | US-USER-001, US-USER-002 |
| Account status + history | `/admin/users/[id]/profile/status` | US-USER-008 |
| Suspend account | `/admin/users/[id]/suspend` (modal/page) | US-USER-010 |
| Reactivate account | `/admin/users/[id]/reactivate` (confirm) | US-USER-011 |
| Cancel pending closure | `/admin/users/[id]/cancel-closure` (confirm) | US-USER-013 (admin path) |
| Permanently close account | `/admin/users/[id]/close` (confirm + obligation result) | US-USER-012 |

### Admin Components

All from OleKard design system (no new components):
- `Card`, `Button`, `Input`, `Badge` (account status badge), `Alert`, `Dialog` (confirm), `Table` (status history), `Loading`

---

## Observability Plan

### Structured Logging

All E02 service methods emit structured logs at appropriate levels. `correlation_id` present in every log entry. PII masked before logging.

### Metrics

Meter name: `profile` (OTel `MeterInterface`).

| Metric | Type | Labels |
|---|---|---|
| `profile.view.success` | Counter | `actor_type` |
| `profile.update.success` | Counter | `actor_type`, `fields_changed` |
| `profile.contact_change.initiated` | Counter | `contact_type` (email/mobile) |
| `profile.contact_change.verified` | Counter | `contact_type` |
| `profile.contact_change.alert_delivery_failed` | Counter | `contact_type` |
| `profile.account_status.transition` | Counter | `from_status`, `to_status` |
| `profile.closure.blocked` | Counter | `blocked_reason` |
| `profile.data_export.requested` | Counter | — |
| `profile.api.latency` | Histogram (P95) | `operation`; buckets: [50,100,200,300,500,750,1000] ms |

Labels MUST NOT carry user IDs, mobile numbers, email addresses, or any PII.

---

## Performance Plan

### Targets

| Operation | P95 Target | Notes |
|---|---|---|
| `GET /v1/users/profile` | ≤ 200 ms | Single record read; photo URL signing included |
| `PUT /v1/users/profile/personal` | ≤ 300 ms | Validation + write + audit |
| Initiate contact change | ≤ 500 ms | Includes OTP challenge creation and E01 service call |
| `GET /v1/users/profile/status` | ≤ 200 ms | Status record + history list |

### Test Harness (K6)

```
tests/performance/profile/
├── view-profile.js          # P95 ≤ 200ms
├── update-personal.js       # P95 ≤ 300ms
├── initiate-contact-change.js  # P95 ≤ 500ms
├── view-status.js           # P95 ≤ 200ms
└── config.js                # Shared env vars + thresholds (K6_VUSER_COUNT TBD)
```

---

## Test Plan

### Unit Tests (Pest)

- `AccountStatus` value object: all valid/invalid transitions
- `ContactChangeService`: initiation happy path, duplicate detection, same-value rejection
- `ClosureGateService`: stub always-blocks behavior; mock E06 returning real obligations
- `ProfileService`: mass-assignment protection; forbidden field rejection; minimum age validation
- `DataExportService`: JSON structure; excluded fields assertion
- PII masking helper: email and mobile masking output
- HMAC-SHA256 IP hashing (same as E01 audit standard)

### API / Feature Tests (Pest + Laravel HTTP test helpers)

- All 19 endpoints: happy path, validation errors, auth errors
- All E02 error codes returned correctly
- Standard Platform Foundation envelope on all responses
- `X-Correlation-ID` present in all responses
- Idempotency: same request twice → same response, single DB write
- Admin-only endpoints reject non-admin tokens (403)
- Admin endpoints with wrong permission reject (403)

### Integration Tests

- Migrations: run cleanly; indexes and constraints present; schema created
- Platform Foundation services wired: `AuditServiceInterface`, `EventPublisherInterface`, `ConfigurationServiceInterface`, `IdempotencyServiceInterface`
- `CreateProfileOnUserRegisteredListener`: profile created on event; duplicate event → idempotent (no second record)
- `ProcessDataExportJob`: JSON generated; stored; `DataExportReady` event emitted
- `ExpiredContactChallengeCleanupJob`: stale pending fields cleared correctly

### Security Tests

- IDOR: authenticated user cannot read or modify another user's profile (403)
- IDOR: admin endpoint without `profile.read` permission → 403
- Protected fields: include `id` in PUT body → `FIELD_NOT_PERMITTED`
- Protected fields: include `account_status` in PUT body → `FIELD_NOT_PERMITTED`
- Contact change without OTP: direct PUT with `email` field → field ignored/rejected
- Email change: confirm `identity.users.email` unchanged until OTP verified
- Photo URL: response contains signed URL, not raw `profile_photo_key`
- Data export: raw credentials absent from export JSON
- Suspension without `account.suspend` permission → 403
- PII masking: email/mobile masked in structured log output
- Security alert: `Profile.User.ContactSecurityAlertSent` event emitted on initiation
- Atomic rollback: mock E01 `updateUserContact()` to throw → verify E02 record unchanged; challenge preserved

### Concurrency Tests

- Concurrent PUT personal info with same `Idempotency-Key`: exactly one DB write
- Concurrent email change initiation: only one pending challenge created; second → 409
- Concurrent closure request and admin suspension: state machine serializes correctly (DB-level lock)
- Concurrent profile reads: consistent results

### Financial Obligation Gate Tests

- Stub: closure always blocked with `obligations_check_unavailable`
- Mock E06 returning non-zero wallet balance: `blocked_by` includes `wallet_balance`
- Mock E06 returning zero balance and E11 returning no pending tx: closure allowed
- Admin permanent closure with obligations: rejected even on admin path

### Mobile Tests (Flutter)

- Profile view with photo: signed URL displayed; refreshed on expiry
- Edit personal info: validation errors display inline
- Photo upload: progress indicator; size error shown correctly
- OTP contact change: full flow from initiation through verification
- Account status screen: all statuses rendered correctly
- Request closure: blocked state shows obligation list
- Cancel closure: success → status returns to active

### Web Tests (Next.js)

- Admin profile detail: displays all fields; edit mode works
- Status history table: all transitions shown with correct labels
- Suspend dialog: reason required; confirmation required before submit
- Close account: obligation check result shown; confirmation required
- All destructive actions: dialog shown; cancel aborts; confirm proceeds

### Accessibility Tests

- WCAG AA contrast on all E02 screens (Flutter + Next.js)
- Profile edit form: all inputs keyboard-accessible with labels
- Photo upload control: keyboard-accessible; screen-reader labeled
- Status history list: keyboard-navigable
- Error messages: associated with their form fields via ARIA
- Confirmation dialogs: focus trapped; Escape closes; screen-reader announced

### Performance Tests (K6)

- `GET /v1/users/profile` P95 ≤ 200 ms
- `PUT /v1/users/profile/personal` P95 ≤ 300 ms
- Load injected via `K6_VUSER_COUNT` env var (TBD value)

### E2E Tests

- Scenario 1: Registration → profile auto-created → GET returns active status + email/mobile from E01
- Scenario 2: Update personal info → re-fetch → fields reflected → audit record present
- Scenario 3: Mobile change → OTP → verify → both profile and identity records updated → security alert event emitted
- Scenario 4: Admin suspend → GET status shows suspended → reactivate → status active → history shows both transitions
- Scenario 5: Closure request → blocked by stub → mock E06 → request succeeds → pending_closure → cancel → active

---

## Implementation Sequencing

Phases MUST be executed in order. No phase begins until its predecessor's tests pass.

| Phase | Name | Key Deliverables |
|---|---|---|
| P0 | Domain Contracts & Scaffold | `ProfilePhotoStorageContract`, `FinancialObligationContract`, `ProfileModuleContract`; `ProfileServiceProvider` scaffold; module namespace; route skeleton; value objects |
| P1 | Profile Persistence | Migrations 001–003; Eloquent models; repository interfaces and implementations; indexes; constraints |
| P2 | Configuration Seed | Migration 004; `ConfigurationService` integration; seed tests |
| P3 | Profile View & Personal Info | `ProfileService`; `GET /v1/users/profile`; `PUT /v1/users/profile/personal`; admin equivalents; IDOR enforcement; idempotency; audit; events |
| P4 | Profile Photo | `ProfilePhotoService`; `LocalDiskProfilePhotoStorage`; `POST /v1/users/profile/photo`; validation; signed URL; cleanup of old photo |
| P5 | Contact Change | `ContactChangeService`; `OtpDeliveryContract` consumption via E01; dual-event alert pattern; initiate + verify endpoints for email and mobile; `IdentityModuleContract` extension (`getUserContactInfo`, `updateUserContact`); atomic rollback on E01 failure; audit |
| P6 | Account Status & Lifecycle | `AccountStatusService`; state machine; `profile.account_status_history`; suspend, reactivate, request closure, cancel closure, permanent close; `ClosureGateService` + stub; admin endpoints; audit; events |
| P7 | Event Listener | `CreateProfileOnUserRegisteredListener`; idempotent profile creation; E01 contact-info fetch; audit |
| P8 | Data Export | `DataExportService`; `ProcessDataExportJob`; export JSON structure; `ExpiredContactChallengeCleanupJob` |
| P9 | Flutter Customer | Riverpod providers; `ProfileStateNotifier`; all Customer screens (P3–P6 scope) |
| P10 | Flutter Agent | Agent subset screens |
| P11 | Next.js Admin | All Admin E02 pages; confirmation dialogs; permission-gated UI |
| P12 | Observability | OTel meter setup; all 9 metrics; latency histograms; structured logging audit |
| P13 | Performance & Security Testing | K6 scripts; all security test assertions; concurrency tests; atomic rollback tests |
| P14 | E2E | Full E2E scenarios 1–5 |

---

## TBD Governance

| Item | TBD | Impact |
|---|---|---|
| TBD-E02-001 | Object storage provider (photo + exports) | `ProfilePhotoStorageContract` holds; `LocalDiskProfilePhotoStorage` used in dev/test |
| TBD-E02-003 | Data export delivery mechanism and format (JSON chosen as technical decision; format details TBD) | `ProcessDataExportJob` uses pluggable format; signed URL delivery implemented |
| TBD-E02-004 | Minimum user age default value | `profile.minimum_user_age_years` seeded as `null`; age check skipped when null |
| TBD-E02-005 | Maker-checker config (which operations require dual approval) | Config key `profile.maker_checker.enabled` defaults to `false`; hook point in `AccountStatusService` |
| K6 concurrent users | Load test volume | Injected via `K6_VUSER_COUNT` env var at run time |
| `profile.photo.*` limits | All photo limits | Seeded as `null`; photo validation skipped when null (dev-safe; production blocks) |
| `profile.contact_change.otp_rate_limit_per_hour` | Rate limit value | Seeded as `null`; rate limiting disabled when null |

**TBD-E02-002 RESOLVED**: Cross-module contact update uses synchronous `IdentityModuleContract` extension (ADR-E02-001). Not TBD.

Any implementation that hard-codes a remaining TBD value MUST be rejected in code review.

---

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| E01 `IdentityModuleContract` extension not delivered before P5 | Medium | High | E02 P5 uses a mock/stub of the new methods; integration tests skipped until E01 delivers; contract defined in E02 spec |
| Object storage provider not selected (TBD-E02-001) | High | Medium | `ProfilePhotoStorageContract` abstraction; `LocalDiskProfilePhotoStorage` for dev; photo upload disabled in production until provider wired |
| Atomic contact update failure at E01 boundary | Low | High | Full DB rollback; challenge preserved for retry; retryable 500 response; documented in spec |
| Financial obligation gate stub blocking valid closures in production | High | Medium | Fail-safe is intentional; admin can manually override; E06/E11 integration must be completed before closure is user-accessible in production |
| E01 OTP challenge infrastructure API change affecting contact-change flow | Low | Medium | E02 consumes via `OtpDeliveryContract` interface; interface change requires coordinated E01 + E02 update |
| Profile record creation race (duplicate `Identity.User.Registered` delivery) | Low | Medium | `user_id UNIQUE` constraint on `profile.user_profiles`; idempotent insert; no duplicate records created |
| Data export performance for users with large history | Low | Medium | Async `ProcessDataExportJob` via Horizon; streaming JSON if history is large |
| `intervention/image-laravel` not present in `composer.json` | Low | Low | Verify and add in P0; no other packages required |

---

## Dependencies

| Dependency | Required From | Status |
|---|---|---|
| `profile` PostgreSQL schema (created by E02 migration 001) | E02 P1 | Created in this epic |
| `platform.outbox_events` + `EventPublisherInterface` | Platform Foundation | ✅ |
| `platform.audit_events` + `AuditServiceInterface` | Platform Foundation | ✅ |
| `platform.configuration_values` + `ConfigurationServiceInterface` | Platform Foundation | ✅ |
| `platform.idempotency_records` + `IdempotencyServiceInterface` | Platform Foundation | ✅ |
| Standard API envelope + error taxonomy | Platform Foundation | ✅ |
| `X-Correlation-ID` middleware | Platform Foundation | ✅ |
| Redis + Laravel Horizon | Platform Foundation | ✅ |
| Design system tokens and shared components | Platform Foundation | ✅ |
| `Identity.User.Registered` event | E01 | ✅ |
| `OtpDeliveryContract` | E01 | ✅ |
| `IdentityModuleContract` (existing methods) | E01 | ✅ |
| `IdentityModuleContract` extensions (`getUserContactInfo`, `updateUserContact`) | E01 — new methods | ⚠️ Required before P5 |
| `profile.read`, `profile.update` permission seeds | E04 | ⚠️ Stub/mock until E04 seeds |
| `account.suspend`, `account.reactivate`, `account.close` permission seeds | E04 | ⚠️ Stub/mock until E04 seeds |
| `FinancialObligationContract` real implementation | E06, E11 | ⚠️ Stub active until E06/E11 implement |
| Notification delivery (E17) | E17 | ⚠️ Events emitted; E17 delivers; stub in dev |
