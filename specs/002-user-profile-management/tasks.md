# Tasks: E02 — User & Profile Management

**Feature**: `002-user-profile-management` | **Epic**: E02 | **Date**: 2026-08-27
**Artifacts**: spec.md · plan.md · data-model.md · contracts/ · quickstart.md

**Traceability Key**: US1=US-USER-001, US2=US-USER-002, US3=US-USER-003, US4=US-USER-004, US5=US-USER-005, US6=US-USER-006, US7=US-USER-007, US8=US-USER-008, US9=US-USER-009, US10=US-USER-010, US11=US-USER-011, US12=US-USER-012, US13=US-USER-013, US14=US-USER-014, US15=US-USER-015

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Can run in parallel with other [P] tasks in the same phase (different files)
- **[Story]**: User story this task belongs to
- **Checkpoint** lines denote phase completion gates — all tasks above must pass before proceeding

---

## Phase 1: Setup — Domain Contracts & Module Scaffold

**Purpose**: Create the module skeleton, contract interfaces, value objects, and provider wiring. No story can begin until this phase is complete.

**⚠️ CRITICAL — GATE**: Zero application code may be written before this phase finishes.

- [X] T001 Create module directory tree for `App\Modules\Profile\` following the structure in plan.md within `services/api/app/Modules/Profile/`
- [X] T002 [P] Create `ProfilePhotoStorageContract` interface, `PhotoStorageResult` DTO, and `PhotoStorageException` per `specs/002-user-profile-management/contracts/module-contracts.md` in `services/api/app/Modules/Profile/Domain/Contracts/ProfilePhotoStorageContract.php`
- [X] T003 [P] Create `FinancialObligationContract` interface and `ObligationCheckResult` DTO per contracts/module-contracts.md in `services/api/app/Modules/Profile/Domain/Contracts/FinancialObligationContract.php`
- [X] T004 [P] Create `ProfileModuleContract` interface (`getAccountStatus`, `isProfileComplete`) per contracts/module-contracts.md in `services/api/app/Modules/Profile/Domain/Contracts/ProfileModuleContract.php`
- [X] T005 [P] Create all eight domain exception classes (`ProfileNotFoundException`, `ContactChangeInProgressException`, `SameContactValueException`, `InvalidStatusTransitionException`, `ClosureBlockedByObligationsException`, `ExportInProgressException`, `MinimumAgeViolationException`, `FieldNotPermittedException`) in `services/api/app/Modules/Profile/Domain/Exceptions/`
- [X] T006 [P] Create `AccountStatus` value object (constants `active`, `suspended`, `pending_closure`, `closed`; valid-transition map enforced; throws `InvalidStatusTransitionException` on invalid transition) in `services/api/app/Modules/Profile/Domain/ValueObjects/AccountStatus.php`
- [X] T007 [P] Create `ContactType` value object (constants `email`, `mobile`) in `services/api/app/Modules/Profile/Domain/ValueObjects/ContactType.php`
- [X] T008 Create `ProfileServiceProvider` with service container bindings (interface → implementation) and route loading skeleton; register in `services/api/config/app.php` providers array in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T009 [P] Create `LocalDiskProfilePhotoStorage` implementing `ProfilePhotoStorageContract`; uses `Storage::disk('local')`; `generateSignedUrl()` uses `URL::temporarySignedRoute()`; add `intervention/image-laravel` to `services/api/composer.json` if absent in `services/api/app/Modules/Profile/Infrastructure/Storage/LocalDiskProfilePhotoStorage.php`
- [X] T010 [P] Create `StubFinancialObligationService` implementing `FinancialObligationContract`; always returns `hasObligations: true, blockedBy: ['obligations_check_unavailable']` (fail-safe per ADR-E02-003) in `services/api/app/Modules/Profile/Infrastructure/Stubs/StubFinancialObligationService.php`
- [X] T011 Add two new method signatures to `App\Modules\Identity\Domain\Contracts\IdentityModuleContract`: `getUserContactInfo(string $userId): ?UserContactInfo` and `updateUserContact(string $userId, ?string $email, ?string $mobile): void`; add `UserContactInfo` DTO and `IdentityContactUpdateException` per contracts/module-contracts.md in `services/api/app/Modules/Identity/Domain/Contracts/IdentityModuleContract.php`
- [X] T012 Document ADR-E02-001 (synchronous IdentityModuleContract extension for cross-module contact update) in `docs/adrs/ADR-E02-001-identity-module-contract-extension.md`
- [X] T013 [P] Document ADR-E02-002 (ProfilePhotoStorageContract abstraction) in `docs/adrs/ADR-E02-002-profile-photo-storage-contract.md`
- [X] T014 [P] Document ADR-E02-003 (FinancialObligationContract fail-safe stub pattern) in `docs/adrs/ADR-E02-003-financial-obligation-contract-stub.md`

**Checkpoint**: Module scaffold complete — all contracts and value objects exist; `ProfileServiceProvider` compiles without errors.

---

## Phase 2: Database — Migrations, Models, Repositories

**Purpose**: Create all three `profile` schema tables with correct indexes, constraints, named connection, and Eloquent models. Blocks all story phases.

**⚠️ CRITICAL — GATE**: All migrations must run cleanly before Phase 3.

- [X] T015 Create migration 001 (`create_profile_schema_and_user_profiles_table`): `CREATE SCHEMA IF NOT EXISTS profile`; create `profile.user_profiles` with all 22 columns, PK, UNIQUE on `user_id`, B-tree indexes on `account_status`, `email`, `mobile_number`; uses `DB::connection('profile')` per data-model.md in `services/api/database/migrations/2026_08_27_001_create_profile_schema_and_user_profiles_table.php`
- [X] T016 Create migration 002 (`create_profile_account_status_history_table`): create append-only `profile.account_status_history` with all 10 columns; no `updated_at`; composite B-tree index on `(user_id, created_at DESC)` per data-model.md in `services/api/database/migrations/2026_08_27_002_create_profile_account_status_history_table.php`
- [X] T017 Create migration 003 (`create_profile_data_exports_table`): create `profile.data_exports` with all 10 columns; composite index on `(user_id, status)`; single-column index on `status` per data-model.md in `services/api/database/migrations/2026_08_27_003_create_profile_data_exports_table.php`
- [X] T018 Create migration 004 (`seed_profile_configuration_defaults`): seed `platform.configuration_values` rows for all profile config keys (`profile.minimum_user_age_years`, `profile.photo.max_size_bytes`, `profile.photo.accepted_mime_types`, `profile.photo.min_width_px`, `profile.photo.min_height_px`, `profile.photo.signed_url_ttl_seconds`, `profile.contact_change.otp_rate_limit_per_hour`, `profile.maker_checker.enabled`) with `null` values and `is_tbd = true`; uses `insertOrIgnore` via `DB::connection('platform')`; idempotent in `services/api/database/migrations/2026_08_27_004_seed_profile_configuration_defaults.php`
- [X] T019 [P] Create `UserProfileModel` Eloquent model: `$connection = 'profile'`, UUID primary key, `HasUuids` or `creating` hook for UUID v7, `$fillable` contains ONLY personal info fields (excludes `id`, `user_id`, `account_status`, `email`, `mobile_number`, `profile_photo_key`, `pending_*`), `$guarded` explicitly lists protected columns in `services/api/app/Modules/Profile/Infrastructure/Models/UserProfileModel.php`
- [X] T020 [P] Create `AccountStatusHistoryModel` Eloquent model: `$connection = 'profile'`, `$timestamps = false`, manual `created_at` cast, enforces append-only semantics in `services/api/app/Modules/Profile/Infrastructure/Models/AccountStatusHistoryModel.php`
- [X] T021 [P] Create `DataExportModel` Eloquent model: `$connection = 'profile'`, UUID primary key, timestamps enabled in `services/api/app/Modules/Profile/Infrastructure/Models/DataExportModel.php`
- [X] T022 Create `EloquentUserProfileRepository` implementing repository interface; methods: `findByUserId`, `create`, `update`, `exists` in `services/api/app/Modules/Profile/Infrastructure/Repositories/EloquentUserProfileRepository.php`
- [X] T023 [P] Create `EloquentAccountStatusHistoryRepository`: methods: `appendTransition`, `getHistoryForUser` (ordered `created_at DESC`) in `services/api/app/Modules/Profile/Infrastructure/Repositories/EloquentAccountStatusHistoryRepository.php`
- [X] T024 [P] Create `EloquentDataExportRepository`: methods: `create`, `findById`, `findByUserIdAndStatus`, `update` in `services/api/app/Modules/Profile/Infrastructure/Repositories/EloquentDataExportRepository.php`
- [X] T025 Write migration integration tests: all three tables created with correct columns, indexes, and constraints; migration 004 seeds config rows idempotently; `profile` named connection resolves; `UserProfileModel::query()` executes on the `profile` connection in `services/api/app/Modules/Profile/Tests/Integration/MigrationTest.php`

**Checkpoint**: `php artisan migrate` runs without errors; all three tables exist with correct columns and indexes.

---

## Phase 3: US-USER-009 & US-USER-001 — Profile Creation & Profile View

**Goal**: System creates a profile when `Identity.User.Registered` is consumed (US9); authenticated user can view their own profile (US1). 🎯 MVP

**Independent Test**: After registering via E01, `GET /v1/users/profile` returns a valid profile with `account_status = active` and no `profile_photo_key` in the response.

- [X] T026 [US9] Create `CreateProfileOnUserRegisteredListener`: consumes `Identity.User.Registered`; calls `IdentityModuleContract::getUserContactInfo()` synchronously; creates `profile.user_profiles` record via repository using `insertOrIgnore`-equivalent for idempotency; emits `Profile.User.ProfileCreated` via `EventPublisherInterface`; writes `profile.user.profile-created` audit in `services/api/app/Modules/Profile/Infrastructure/Listeners/CreateProfileOnUserRegisteredListener.php`
- [X] T027 [US9] Register `CreateProfileOnUserRegisteredListener` in `ProfileServiceProvider::boot()` against `Identity.User.Registered` event in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T028 [US1] Create `ProfileDTO` (all profile fields including masked photo URL; excludes raw `profile_photo_key`) in `services/api/app/Modules/Profile/Application/DTOs/ProfileDTO.php`
- [X] T029 [US1] Create `GetProfileQuery`: loads `UserProfileModel` by `user_id`; generates signed photo URL via `ProfilePhotoStorageContract::generateSignedUrl()` when `profile_photo_key` is set; throws `ProfileNotFoundException` if no record in `services/api/app/Modules/Profile/Application/Queries/GetProfileQuery.php`
- [X] T030 [US1] Create `ProfileController` with `show()` method: extracts `user_id` from JWT via `IdentityModuleContract`; asserts `user_id` matches own resource (IDOR check); dispatches `GetProfileQuery`; returns `ProfileResource`; increments `profile.view.success` metric in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ProfileController.php`
- [X] T031 [US1] Create `AdminProfileController` with `show()` method: requires `profile.read` permission middleware; accepts `{user_id}` path parameter; dispatches `GetProfileQuery` in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/AdminProfileController.php`
- [X] T032 [US1] Create `ProfileResource` API resource: includes all profile fields; substitutes `profile_photo_url` (signed URL) for `profile_photo_key`; `profile_photo_key` is NOT present in response in `services/api/app/Modules/Profile/Infrastructure/Http/Resources/ProfileResource.php`
- [X] T033 [US1] Register routes in `ProfileServiceProvider`: `GET /v1/users/profile` → `ProfileController@show`; `GET /v1/admin/users/{user_id}/profile` → `AdminProfileController@show`; apply `identity.auth`, `throttle:profile-user` middleware in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T034 [US1] Write API feature test: `GET /v1/users/profile` returns `200` with Platform Foundation envelope; `account_status = active`; `profile_photo_url` is a signed URL; `profile_photo_key` absent from response in `services/api/app/Modules/Profile/Tests/Feature/ProfileViewTest.php`
- [X] T035 [US1] Write API feature test: admin `GET /v1/admin/users/{user_id}/profile` returns profile with `profile.read` permission; without permission returns `403 FORBIDDEN` in `services/api/app/Modules/Profile/Tests/Feature/AdminProfileViewTest.php`
- [X] T036 [US1] Write IDOR security test: authenticated user A calls admin endpoint for user B without admin token → `403 FORBIDDEN`; no profile data disclosed in response body in `services/api/app/Modules/Profile/Tests/Security/ProfileIdorTest.php`
- [X] T037 [US9] Write listener unit test: first `Identity.User.Registered` event → profile created; duplicate event → idempotent (no second record); `IdentityModuleContract::getUserContactInfo()` called once; `Profile.User.ProfileCreated` emitted; audit written in `services/api/app/Modules/Profile/Tests/Unit/CreateProfileListenerTest.php`
- [X] T038 [US9] Write listener integration test: `getUserContactInfo()` throws → event NOT acknowledged; Horizon retries; no partial profile record persisted in `services/api/app/Modules/Profile/Tests/Integration/ProfileCreationRetryTest.php`

**Checkpoint**: `GET /v1/users/profile` returns complete profile; IDOR test passes; duplicate `Identity.User.Registered` produces single profile record; `profile_photo_key` never in response.

---

## Phase 4: US-USER-002 — Personal Information Update

**Goal**: Authenticated user can update personal info fields; admin can update any user's; idempotency enforced; protected fields rejected with `FIELD_NOT_PERMITTED`.

**Independent Test**: `PUT /v1/users/profile/personal` twice with same `Idempotency-Key` → single DB write; `PUT` with `account_status` field → `400 FIELD_NOT_PERMITTED`.

- [X] T039 [US2] Create `UpdatePersonalInfoRequest` form request: validates `first_name` (max 100), `last_name` (max 100), `date_of_birth` (valid calendar date), `gender` (enum: male/female/non_binary/prefer_not_to_say), `address.country` (ISO 3166-1 alpha-2), `preferred_language` (BCP 47 max 5 chars); rejects any field outside this list with `FIELD_NOT_PERMITTED` in `services/api/app/Modules/Profile/Infrastructure/Http/Requests/UpdatePersonalInfoRequest.php`
- [X] T040 [US2] Create `UpdatePersonalInfoCommand` (validated field set, actor_id, idempotency_key) in `services/api/app/Modules/Profile/Application/Commands/UpdatePersonalInfoCommand.php`
- [X] T041 [US2] Implement `ProfileService::updatePersonalInfo()`: IDOR check; minimum age check via `ConfigurationService::get('profile.minimum_user_age_years')` (skip if null); persist via repository; emit `Profile.User.PersonalInfoUpdated`; write `profile.user.personal-info-updated` audit; increment `profile.update.success` metric in `services/api/app/Modules/Profile/Domain/Services/ProfileService.php`
- [X] T042 [US2] Wrap `ProfileService::updatePersonalInfo()` with `IdempotencyServiceInterface::handle()`: same `Idempotency-Key` + payload → cached response; mismatch → `409 IDEMPOTENCY_KEY_MISMATCH` in `services/api/app/Modules/Profile/Domain/Services/ProfileService.php`
- [X] T043 [US2] Add `updatePersonal()` to `ProfileController`: `PUT /v1/users/profile/personal`; dispatches `UpdatePersonalInfoCommand`; returns updated `ProfileResource` in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ProfileController.php`
- [X] T044 [US2] Add `updatePersonal()` to `AdminProfileController`: `PUT /v1/admin/users/{user_id}/profile/personal`; requires `profile.update` permission; audit records both admin actor ID and subject user ID in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/AdminProfileController.php`
- [X] T045 [US2] Register routes: `PUT /v1/users/profile/personal` and `PUT /v1/admin/users/{user_id}/profile/personal` in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T046 [US2] Write API feature tests: happy-path update persists; re-request with same `Idempotency-Key` → single DB write and same `200`; `Profile.User.PersonalInfoUpdated` in outbox; `profile.user.personal-info-updated` in audit in `services/api/app/Modules/Profile/Tests/Feature/PersonalInfoUpdateTest.php`
- [X] T047 [US2] Write security tests: request body with `id`, `user_id`, `account_status`, `email`, `mobile_number`, or `profile_photo_key` → `400 FIELD_NOT_PERMITTED`; protected fields not persisted in `services/api/app/Modules/Profile/Tests/Security/ProtectedFieldsTest.php`
- [X] T048 [US2] Write validation tests: invalid date format → `400 VALIDATION_ERROR`; age below minimum → `422 MINIMUM_AGE_VIOLATION`; invalid ISO country code → `400 VALIDATION_ERROR`; invalid BCP 47 tag → `400 VALIDATION_ERROR` in `services/api/app/Modules/Profile/Tests/Feature/PersonalInfoValidationTest.php`

**Checkpoint**: Protected fields rejected; idempotency prevents double-write; minimum-age validation fires; admin audit includes both actor IDs.

---

## Phase 5: US-USER-003 — Profile Photo Upload

**Goal**: Authenticated user can upload a photo; system validates size, MIME, dimensions via config; old photo cleaned up; signed URL returned (raw key never exposed).

**Independent Test**: Upload valid JPEG → `GET /v1/users/profile` returns signed URL; `profile_photo_key` absent from response; old key cleaned up from storage.

- [X] T049 [US3] Create `UploadProfilePhotoCommand` with uploaded `$file` reference in `services/api/app/Modules/Profile/Application/Commands/UploadProfilePhotoCommand.php`
- [X] T050 [US3] Implement `ProfilePhotoService::upload()`: reads limits from `ConfigurationService`; uses `intervention/image-laravel` to extract dimensions; throws typed exceptions on violations; calls `ProfilePhotoStorageContract::store()`; deletes old photo via `ProfilePhotoStorageContract::delete()` if `profile_photo_key` was set; updates `profile_photo_key` on profile record; emits `Profile.User.PhotoUpdated`; writes `profile.user.photo-updated` audit in `services/api/app/Modules/Profile/Domain/Services/ProfilePhotoService.php`
- [X] T051 [US3] Create `ProfilePhotoController` with `store()` method: accepts `multipart/form-data`; dispatches `UploadProfilePhotoCommand`; returns `{ "profile_photo_url": "..." }` (signed URL only — raw key excluded) in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ProfilePhotoController.php`
- [X] T052 [US3] Register route: `POST /v1/users/profile/photo` → `ProfilePhotoController@store`; apply `identity.auth`, `throttle:profile-photo` middleware in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T053 [US3] Write feature tests: valid JPEG upload → `200`; returned `profile_photo_url` is signed URL; `profile_photo_key` absent from response; old photo storage key cleaned up; `Profile.User.PhotoUpdated` in outbox in `services/api/app/Modules/Profile/Tests/Feature/ProfilePhotoTest.php`
- [X] T054 [US3] Write validation tests: file exceeding `max_size_bytes` → `413 FILE_TOO_LARGE`; disallowed MIME type → `415 UNSUPPORTED_MEDIA_TYPE`; image below minimum dimensions → `422 IMAGE_DIMENSIONS_TOO_SMALL` in `services/api/app/Modules/Profile/Tests/Feature/ProfilePhotoValidationTest.php`

**Checkpoint**: Photo upload returns signed URL only; `profile_photo_key` never in response; size/MIME/dimension errors return correct HTTP codes.

---

## Phase 6: US-USER-004 & US-USER-007 — Email Change Initiation & Verification

**Goal**: User initiates email change (OTP → new email; security alert → old email); verifies OTP; both profile and identity records updated atomically; rollback with preserved challenge if E01 fails.

**Independent Test**: Initiate → `Profile.User.ContactSecurityAlertSent` emitted; verify OTP → both records updated; mock E01 failure → both records unchanged; challenge still active.

- [X] T055 [US4] Create `InitiateEmailChangeCommand` (new_email, actor_id, idempotency_key) in `services/api/app/Modules/Profile/Application/Commands/InitiateEmailChangeCommand.php`
- [X] T056 [US7] Create `VerifyEmailChangeCommand` (challenge_id, otp, actor_id, idempotency_key) in `services/api/app/Modules/Profile/Application/Commands/VerifyEmailChangeCommand.php`
- [X] T057 [US4] Implement `ContactChangeService::initiateEmailChange()` inside `DB::transaction()`: (1) validate RFC 5322 email format; (2) check `new_email !== current_email` → throw `SameContactValueException`; (3) check no existing `pending_email` → throw `ContactChangeInProgressException`; (4) query email B-tree index for uniqueness (silent — identical response whether in-use or not); (5) call E01's `OtpDeliveryContract` to issue OTP challenge → receive `challenge_id`; (6) store `pending_email` + `pending_email_challenge_id`; (7) emit `Profile.User.EmailChangeInitiated`; (8) emit `Profile.User.ContactSecurityAlertSent{contact_type=email}` targeting old email; both via `EventPublisherInterface` within same transaction; write `profile.user.email-change-initiated` audit; increment `profile.contact_change.initiated{contact_type=email}` metric in `services/api/app/Modules/Profile/Domain/Services/ContactChangeService.php`
- [X] T058 [US7] Implement `ContactChangeService::verifyEmailChange()` inside `DB::transaction()`: (1) load profile; (2) assert `pending_email_challenge_id` matches supplied `challenge_id` — if no match throw `404 CHALLENGE_NOT_FOUND`; (3) verify OTP via E01's OTP infrastructure — **if E01 returns `OTP_EXPIRED`, nullify `pending_email` and `pending_email_challenge_id` on the profile record (the challenge is done; user must re-initiate) and propagate `400 OTP_EXPIRED` without emitting any event**; (4) on OTP valid: update `profile.user_profiles.email` = `pending_email`, clear all pending email fields; (5) call `IdentityModuleContract::updateUserContact()` → if throws `IdentityContactUpdateException`, entire transaction rolls back — **the pending fields are restored because the transaction rolled back, preserving the challenge so the user can retry without re-initiating**; response is retryable `500 INTERNAL_ERROR`; (6) emit `Profile.User.EmailChanged`; (7) write `profile.user.email-changed` audit (WARN); increment `profile.contact_change.verified{contact_type=email}` metric in `services/api/app/Modules/Profile/Domain/Services/ContactChangeService.php`
- [X] T059 [US4] Add `initiateEmail()` to `ContactChangeController`: `POST /v1/users/profile/email/change`; dispatches `InitiateEmailChangeCommand`; returns `{ "challenge_id": "..." }` — identical response whether email is in use or not in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ContactChangeController.php`
- [X] T060 [US7] Add `verifyEmail()` to `ContactChangeController`: `POST /v1/users/profile/email/verify`; dispatches `VerifyEmailChangeCommand`; on atomic failure returns `500 INTERNAL_ERROR` with retry guidance in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ContactChangeController.php`
- [X] T061 [US4] Create `InitiateEmailChangeRequest` form request: validates `new_email` as RFC 5322 address; requires `Idempotency-Key` header in `services/api/app/Modules/Profile/Infrastructure/Http/Requests/InitiateEmailChangeRequest.php`
- [X] T062 [US4/7] Create `VerifyContactChangeRequest` form request: requires `challenge_id` (UUID) and `otp` (non-empty string); requires `Idempotency-Key` header in `services/api/app/Modules/Profile/Infrastructure/Http/Requests/VerifyContactChangeRequest.php`
- [X] T063 [US4/7] Register routes: `POST /v1/users/profile/email/change` and `POST /v1/users/profile/email/verify` in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T064 [US4] Write feature test: initiate email change → `200` with `challenge_id`; `Profile.User.EmailChangeInitiated` in outbox; `Profile.User.ContactSecurityAlertSent` in outbox; `GET /v1/users/profile` still shows original email in `services/api/app/Modules/Profile/Tests/Feature/EmailChangeTest.php`
- [X] T065 [US7] Write feature test: verify OTP → `200`; `profile.user_profiles.email` updated; `identity.users.email` updated (via mocked `updateUserContact()`); `Profile.User.EmailChanged` in outbox; `profile.user.email-changed` audit present in `services/api/app/Modules/Profile/Tests/Feature/EmailChangeTest.php`
- [X] T066 [US7] Write atomic rollback test: mock `IdentityModuleContract::updateUserContact()` to throw `IdentityContactUpdateException` → `profile.user_profiles.email` unchanged; `pending_email_challenge_id` preserved (challenge active); response is retryable `500` in `services/api/app/Modules/Profile/Tests/Security/ContactChangeAtomicRollbackTest.php`
- [X] T067 [US4] Write security tests: concurrent email change initiation → only one pending challenge created; second → `409 CONTACT_CHANGE_IN_PROGRESS`; email uniqueness check → identical response whether in use or not in `services/api/app/Modules/Profile/Tests/Security/EmailChangeSecurityTest.php`

**Checkpoint**: Full email change flow passes; security alert event emitted on initiation; atomic rollback leaves challenge active; `profile.user_profiles.email` unchanged until verify succeeds.

---

## Phase 7: US-USER-005 & US-USER-006 — Mobile Number Change Initiation & Verification

**Goal**: Same dual-event + atomic update pattern as email change but for E.164 mobile numbers.

**Independent Test**: Full mobile initiate → verify flow; security alert emitted; atomic rollback preserves challenge.

- [X] T068 [US5] Create `InitiateMobileChangeCommand` in `services/api/app/Modules/Profile/Application/Commands/InitiateMobileChangeCommand.php`
- [X] T069 [US6] Create `VerifyMobileChangeCommand` in `services/api/app/Modules/Profile/Application/Commands/VerifyMobileChangeCommand.php`
- [X] T070 [US5] Implement `ContactChangeService::initiateMobileChange()` following the same transaction pattern as `initiateEmailChange()`: E.164 format validation (`/^\+[1-9]\d{6,14}$/`); duplicate/uniqueness check; OTP challenge via E01; store `pending_mobile` + `pending_mobile_challenge_id`; emit `Profile.User.MobileChangeInitiated`; emit `Profile.User.ContactSecurityAlertSent{contact_type=mobile}`; audit + metric in `services/api/app/Modules/Profile/Domain/Services/ContactChangeService.php`
- [X] T071 [US6] Implement `ContactChangeService::verifyMobileChange()` following the same transaction pattern as `verifyEmailChange()`: (1) assert `pending_mobile_challenge_id` matches `challenge_id`; (2) verify OTP via E01 — **if E01 returns `OTP_EXPIRED`, nullify `pending_mobile` and `pending_mobile_challenge_id` (challenge is done; user must re-initiate) and propagate `400 OTP_EXPIRED`**; (3) on OTP valid: atomic update of `profile.user_profiles.mobile_number` + `IdentityModuleContract::updateUserContact()`; (4) rollback on E01 failure — pending fields restored by transaction rollback, preserving the challenge for retry; emit `Profile.User.MobileChanged`; audit + metric in `services/api/app/Modules/Profile/Domain/Services/ContactChangeService.php`
- [X] T072 [US5] Add `initiateMobile()` to `ContactChangeController`: `POST /v1/users/profile/mobile/change` in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ContactChangeController.php`
- [X] T073 [US6] Add `verifyMobile()` to `ContactChangeController`: `POST /v1/users/profile/mobile/verify` in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ContactChangeController.php`
- [X] T074 [US5] Create `InitiateMobileChangeRequest` form request: validates `new_mobile` as valid E.164 string; requires `Idempotency-Key` in `services/api/app/Modules/Profile/Infrastructure/Http/Requests/InitiateMobileChangeRequest.php`
- [X] T075 [US5/6] Register routes: `POST /v1/users/profile/mobile/change` and `POST /v1/users/profile/mobile/verify` in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T076 [US5/6] Write API feature tests: full mobile change flow; security alert emitted; original mobile unchanged before verify; `profile.user_profiles.mobile_number` updated after verify in `services/api/app/Modules/Profile/Tests/Feature/MobileChangeTest.php`
- [X] T077 [US6] Write atomic rollback test for mobile: mocked `updateUserContact()` throws → both records unchanged; `pending_mobile_challenge_id` preserved; retryable `500` in `services/api/app/Modules/Profile/Tests/Security/MobileChangeAtomicRollbackTest.php`

**Checkpoint**: Both email and mobile change flows pass; security alerts emitted on initiation; rollback preserves challenge; raw mobile never in event payload.

---

## Phase 8: US-USER-008 — Account Status View

**Goal**: User can view their own current account status and transition history; admin can view any user's status with `actor_id` in history.

**Independent Test**: After suspend + reactivate, `GET /v1/users/profile/status` returns two history entries in descending order; user-path excludes `actor_id`.

- [X] T078 [US8] Create `AccountStatusDTO` in `services/api/app/Modules/Profile/Application/DTOs/AccountStatusDTO.php`
- [X] T079 [US8] Create `GetAccountStatusQuery`: loads `account_status` from `UserProfileModel` and ordered history from `AccountStatusHistoryModel`; excludes `actor_id` for user path, includes for admin path in `services/api/app/Modules/Profile/Application/Queries/GetAccountStatusQuery.php`
- [X] T080 [US8] Create `AccountStatusController` with `show()` method: `GET /v1/users/profile/status`; user path excludes `actor_id` from history entries in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/AccountStatusController.php`
- [X] T081 [US8] Add `status()` to `AdminProfileController`: `GET /v1/admin/users/{user_id}/profile/status`; includes `actor_id` in history; requires `profile.read` permission in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/AdminProfileController.php`
- [X] T082 [US8] Create `AccountStatusResource` in `services/api/app/Modules/Profile/Infrastructure/Http/Resources/AccountStatusResource.php`
- [X] T083 [US8] Register routes: `GET /v1/users/profile/status` and `GET /v1/admin/users/{user_id}/profile/status` in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T084 [US8] Write feature tests: status endpoint returns `account_status` and history; user-path history excludes `actor_id`; admin-path history includes `actor_id`; history ordered by `created_at DESC` in `services/api/app/Modules/Profile/Tests/Feature/AccountStatusViewTest.php`

**Checkpoint**: Status and history returned correctly; `actor_id` hidden from user path; admin path shows full history.

---

## Phase 9: US-USER-010 & US-USER-011 — Account Suspension & Reactivation

**Goal**: Admin with `account.suspend` can suspend active accounts; admin with `account.reactivate` can reactivate; state machine rejects invalid transitions.

**Independent Test**: Suspend → `suspended`; Reactivate → `active`; status history shows both transitions; suspension without reason → `400`.

- [X] T085 [US10/11] Create `AccountStatusService` with `suspend()` and `reactivate()` methods: (1) validate transition via `AccountStatus` value object; (2) update `profile.user_profiles.account_status` and append `profile.account_status_history` atomically in `DB::transaction()`; (3) emit event; (4) write audit with admin actor ID + subject user ID; (5) increment `profile.account_status.transition` metric in `services/api/app/Modules/Profile/Domain/Services/AccountStatusService.php`
- [X] T086 [US10] Create `SuspendAccountCommand` and `ReactivateAccountCommand` in `services/api/app/Modules/Profile/Application/Commands/`
- [X] T087 [US10] Create `SuspendAccountRequest` form request: requires `reason` (non-empty string) in `services/api/app/Modules/Profile/Infrastructure/Http/Requests/SuspendAccountRequest.php`
- [X] T088 [US10] Add `suspend()` to `AdminProfileController`: `POST /v1/admin/users/{user_id}/suspend`; requires `account.suspend` permission in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/AdminProfileController.php`
- [X] T089 [US11] Add `reactivate()` to `AdminProfileController`: `POST /v1/admin/users/{user_id}/reactivate`; requires `account.reactivate` permission in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/AdminProfileController.php`
- [X] T090 [US10/11] Register routes: `POST /v1/admin/users/{user_id}/suspend` and `POST /v1/admin/users/{user_id}/reactivate` in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T091 [US10/11] Write feature tests: suspend active → `suspended`; reactivate suspended → `active`; `Profile.Account.Suspended` and `Profile.Account.Reactivated` in outbox; audit records include both `actor_id` and `subject_user_id` in `services/api/app/Modules/Profile/Tests/Feature/AccountSuspendReactivateTest.php`
- [X] T092 [US10] Write invalid transition and permission tests: suspend already-suspended → `400 INVALID_STATUS_TRANSITION`; reactivate active → `400`; suspend without `account.suspend` permission → `403 FORBIDDEN`; missing reason → `400 VALIDATION_ERROR` in `services/api/app/Modules/Profile/Tests/Feature/AccountSuspendReactivateTest.php`

**Checkpoint**: Suspend/reactivate pass; invalid transitions rejected; permissions enforced; audit includes both actor IDs.

---

## Phase 10: US-USER-013 — Closure Request & Cancellation

**Goal**: User can request closure (transitions to `pending_closure` if gate passes); user or admin can cancel pending closure.

**Independent Test**: Self-service cancel → `active`; `ClosureCancelled` event emitted; status history record created; wrong status → `400`.

- [X] T093 [US13] Create `RequestClosureCommand` and `CancelClosureCommand` in `services/api/app/Modules/Profile/Application/Commands/`
- [X] T094 [US13] Implement `AccountStatusService::requestClosure()`: (1) check via `ClosureGateService`; (2) if blocked → emit `Profile.Account.ClosureBlocked`; write audit; increment `profile.closure.blocked`; throw `ClosureBlockedByObligationsException`; (3) if passes → transition to `pending_closure`; emit `Profile.Account.ClosureRequested`; write audit in `services/api/app/Modules/Profile/Domain/Services/AccountStatusService.php`
- [X] T095 [US13] Implement `AccountStatusService::cancelClosure()`: (1) validate current status is `pending_closure`; (2) transition to `active`; append history; (3) emit `Profile.Account.ClosureCancelled`; (4) write `profile.account.closure-cancelled` audit with actor ID + actor type in `services/api/app/Modules/Profile/Domain/Services/AccountStatusService.php`
- [X] T096 [US13] Create `ClosureController` with `request()` (`POST /v1/users/profile/closure/request`) and `cancel()` (`DELETE /v1/users/profile/closure/request`) in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ClosureController.php`
- [X] T097 [US13] Add `cancelClosure()` to `AdminProfileController`: `POST /v1/admin/users/{user_id}/cancel-closure`; requires `account.reactivate` permission in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/AdminProfileController.php`
- [X] T098 [US13] Register routes: `POST /v1/users/profile/closure/request`, `DELETE /v1/users/profile/closure/request`, `POST /v1/admin/users/{user_id}/cancel-closure` in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T099 [US13] Write feature tests: user self-cancels → `active`; `profile.account_status_history` record with `actor_type = USER`; `Profile.Account.ClosureCancelled` in outbox; `profile.account.closure-cancelled` audit in `services/api/app/Modules/Profile/Tests/Feature/ClosureCancellationTest.php`
- [X] T100 [US13] Write invalid transition and admin tests: `DELETE` on non-`pending_closure` → `400 INVALID_STATUS_TRANSITION`; admin cancel without `account.reactivate` → `403` in `services/api/app/Modules/Profile/Tests/Feature/ClosureCancellationTest.php`

**Checkpoint**: Self-service and admin closure cancellation pass; `ClosureCancelled` event emitted; invalid transitions rejected.

---

## Phase 11: US-USER-014 — Financial Obligation Gate

**Goal**: `ClosureGateService` integrates with `FinancialObligationContract`; stub always blocks; API response enumerates obligation categories.

**Independent Test**: Stub active → `409 CLOSURE_BLOCKED_BY_OBLIGATIONS` with `blocked_by: ['obligations_check_unavailable']`; mock returning zero obligations → gate passes.

- [X] T101 [US14] Implement `ClosureGateService::checkAndEnforce()`: calls `FinancialObligationContract::checkObligations()`; if `hasObligations` → throw `ClosureBlockedByObligationsException` with `$result->blockedBy`; else return void in `services/api/app/Modules/Profile/Domain/Services/ClosureGateService.php`
- [X] T102 [US14] Wire `StubFinancialObligationService` in `ProfileServiceProvider` with comment: "Replaced by E06/E11 real implementations when available" in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T103 [US14] Write unit tests: stub → always throws; mock `hasObligations: false` → gate passes; mock `hasObligations: true, blockedBy: ['wallet_balance']` → exception with correct array in `services/api/app/Modules/Profile/Tests/Unit/ClosureGateServiceTest.php`
- [X] T104 [US14] Write API feature test: closure request with stub → `409`; response body `blocked_by: ['obligations_check_unavailable']`; account status unchanged in `services/api/app/Modules/Profile/Tests/Feature/ClosureObligationTest.php`
- [X] T105 [US14] Write admin path obligation test: admin `POST /v1/admin/users/{user_id}/close` on account with obligations → `409 CLOSURE_BLOCKED_BY_OBLIGATIONS` even on admin path in `services/api/app/Modules/Profile/Tests/Feature/ClosureObligationTest.php`

**Checkpoint**: Obligation gate blocks closure in all paths; `blocked_by` populated correctly; admin path also gated.

---

## Phase 12: US-USER-012 — Permanent Account Closure

**Goal**: Admin with `account.close` can permanently close `pending_closure` accounts; final obligation check; `closed` is terminal.

**Independent Test**: Close `pending_closure` account → `closed`; second close attempt → `400 INVALID_STATUS_TRANSITION`.

- [X] T106 [US12] Create `PermanentlyCloseAccountCommand` in `services/api/app/Modules/Profile/Application/Commands/PermanentlyCloseAccountCommand.php`
- [X] T107 [US12] Implement `AccountStatusService::permanentlyClose()`: (1) validate current status is `pending_closure`; (2) final obligation check via `ClosureGateService`; (3) transition to `closed`; (4) append history; (5) emit `Profile.Account.Closed`; (6) write `profile.account.closed` audit (WARN) with admin actor ID + subject user ID in `services/api/app/Modules/Profile/Domain/Services/AccountStatusService.php`
- [X] T108 [US12] Add `close()` to `AdminProfileController`: `POST /v1/admin/users/{user_id}/close`; requires `account.close` permission in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/AdminProfileController.php`
- [X] T109 [US12] Register route: `POST /v1/admin/users/{user_id}/close` in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T110 [US12] Write feature tests: successful close → `closed`; `Profile.Account.Closed` in outbox; subsequent close attempt → `400 INVALID_STATUS_TRANSITION` in `services/api/app/Modules/Profile/Tests/Feature/PermanentClosureTest.php`
- [X] T111 [US12] Write security tests: close without `account.close` permission → `403`; close on non-`pending_closure` status → `400 INVALID_STATUS_TRANSITION` in `services/api/app/Modules/Profile/Tests/Feature/PermanentClosureTest.php`

**Checkpoint**: `closed` is terminal; `account.close` permission enforced; final obligation check fires; admin audit includes both actor IDs.

---

## Phase 13: US-USER-015 — Personal Data Export

**Goal**: User can request async data export; Horizon job compiles JSON; signed URL returned; export excludes credentials, raw IPs, and `profile_photo_key`.

**Independent Test**: Request → `processing`; job runs → JSON has personal fields; JSON has NO `profile_photo_key`, credential hashes, or raw IPs; duplicate request → `409`.

- [X] T112 [US15] Create `RequestDataExportCommand` and `GetDataExportStatusQuery` in `services/api/app/Modules/Profile/Application/Commands/` and `Queries/`
- [X] T113 [US15] Implement `DataExportService::request()`: (1) check for in-progress export via repository → throw `ExportInProgressException`; (2) create `profile.data_exports` row (`status = processing`); (3) dispatch `ProcessDataExportJob` to Horizon; (4) write `profile.user.data-export-requested` audit; (5) emit `Profile.User.DataExportRequested`; (6) increment `profile.data_export.requested` in `services/api/app/Modules/Profile/Domain/Services/DataExportService.php`
- [X] T114 [US15] Implement `ProcessDataExportJob`: compile JSON (personal fields, status history; excludes `profile_photo_key`, credential hashes, OTP values, raw IPs, session tokens); store via `ProfilePhotoStorageContract`; generate signed URL; update `profile.data_exports` to `ready`; emit `Profile.User.DataExportReady`; on error → mark `failed` in `services/api/app/Modules/Profile/Infrastructure/Jobs/ProcessDataExportJob.php`
- [X] T115 [US15] Create `DataExportController` with `request()` (`POST`) and `status()` (`GET`) methods in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/DataExportController.php`; `status()` MUST assert that the loaded `DataExportModel::user_id` matches the authenticated token's `user_id` — a mismatch MUST return `403 FORBIDDEN` (never `404`) per the IDOR/BOLA prevention requirement (plan §IDOR Prevention; FR-E02-004)
- [X] T116 [US15] Register routes: `POST /v1/users/profile/data-export` and `GET /v1/users/profile/data-export/{export_id}` in `services/api/routes/modules/profile.php`
- [X] T117 [US15] Write feature tests: request → `200` with `export_id` and `status = processing`; second request before completion → `409 EXPORT_IN_PROGRESS` in `services/api/app/Modules/Profile/Tests/Feature/DataExportTest.php`
- [X] T118 [US15] Write job integration test: job completes → `status = ready`; JSON contains personal fields; JSON does NOT contain `profile_photo_key`, password hashes, OTP values, or raw IPs; `Profile.User.DataExportReady` in outbox in `services/api/app/Modules/Profile/Tests/Integration/DataExportJobTest.php`

**Checkpoint**: Export queued; job produces correct JSON; excluded fields absent; duplicate request blocked.

---

## Phase 14: Events, Audit Completeness & Observability

**Purpose**: OTel metrics, PII masking helper, cleanup job, and audit completeness tests.

- [X] T119 Register OTel `MeterInterface` with meter name `profile` in `ProfileServiceProvider`; inject into all services in `services/api/app/Modules/Profile/Infrastructure/Providers/ProfileServiceProvider.php`
- [X] T120 [P] Verify all 9 metrics wired per plan.md: `profile.view.success`, `profile.update.success`, `profile.contact_change.initiated`, `profile.contact_change.verified`, `profile.contact_change.alert_delivery_failed`, `profile.account_status.transition`, `profile.closure.blocked`, `profile.data_export.requested`, `profile.api.latency`; confirm no PII in metric labels across all services in `services/api/app/Modules/Profile/Domain/Services/`
- [X] T121 [P] Implement PII masking helper: `maskEmail(string $email): string` (→ `j*****@domain.com`); `maskMobile(string $mobile): string` (→ `+267*****678`); apply in all service logging and audit `before_state`/`after_state` payloads in `services/api/app/Modules/Profile/Domain/Services/PiiMaskingHelper.php`
- [X] T122 [P] Create `ExpiredContactChallengeCleanupJob`: hourly schedule; nullifies `pending_email`/`pending_mobile` fields on profiles where challenge has expired according to E01 in `services/api/app/Modules/Profile/Infrastructure/Jobs/ExpiredContactChallengeCleanupJob.php`
- [X] T123 Write audit completeness integration test: after each of the 14 auditable operations, assert `platform.audit_events` contains exactly one record with correct `action`, `actor_id`, `correlation_id`; admin-path records include `subject_user_id` in `services/api/app/Modules/Profile/Tests/Integration/AuditCompletenessTest.php`
- [X] T124 Write PII masking test: structured log output for email/mobile operations contains masked values (not raw contact); `platform.outbox_events` payloads use masked values in `services/api/app/Modules/Profile/Tests/Security/PiiMaskingTest.php`

**Checkpoint**: All 9 metrics wired; PII masked in logs and event payloads; audit completeness test passes; cleanup job scheduled.

---

## Phase 15: Flutter Customer — Profile Screens

**Purpose**: Implement all 11 Customer profile screens (OleKard design system; Riverpod state management; all loading/success/error states per spec §15.1).

- [X] T125 Create `ProfileStateNotifier` Riverpod provider and `ProfileState` sealed class (`loading`, `loaded`, `error`, `notFound`) in `apps/customer/lib/modules/profile/state/profile_state_notifier.dart`
- [X] T126 [P] Implement profile view screen: all fields displayed; signed photo in `CircleAvatar`; `account_status` using design system `Badge`; loading skeleton; error retry CTA in `apps/customer/lib/modules/profile/screens/profile_view_screen.dart`
- [X] T127 [P] Implement personal info edit screen: design system `Input` + `Button`; inline validation errors; age-violation error state; loading indicator on submit in `apps/customer/lib/modules/profile/screens/profile_edit_screen.dart`
- [X] T128 [P] Implement photo upload screen: image picker; upload progress; size/MIME/dimension error states using design system `Alert` in `apps/customer/lib/modules/profile/screens/profile_photo_screen.dart`
- [X] T129 Implement email change initiation screen: `Input` for new email; OTP challenge sent feedback; rate-limit error state in `apps/customer/lib/modules/profile/screens/email_change_screen.dart`
- [X] T130 Implement email OTP verification screen: 6-digit OTP entry; retry on same challenge; expired/invalid OTP error; retryable server error state in `apps/customer/lib/modules/profile/screens/email_verify_screen.dart`
- [X] T131 [P] Implement mobile change initiation screen: E.164 phone `Input`; same error states as email change in `apps/customer/lib/modules/profile/screens/mobile_change_screen.dart`
- [X] T132 [P] Implement mobile OTP verification screen in `apps/customer/lib/modules/profile/screens/mobile_verify_screen.dart`
- [X] T133 [P] Implement account status screen: color-coded `Badge`; `ListView.builder` for history; keyboard-navigable in `apps/customer/lib/modules/profile/screens/account_status_screen.dart`
- [X] T134 Implement closure request screen: confirm `Dialog`; blocked state with obligation list via design system `Card`; cancel closure CTA when `pending_closure` in `apps/customer/lib/modules/profile/screens/closure_request_screen.dart`
- [X] T135 [P] Implement data export request screen: `Button` to request; submitted confirmation; `EXPORT_IN_PROGRESS` handling in `apps/customer/lib/modules/profile/screens/data_export_screen.dart`
- [X] T136 Write Flutter widget tests for all 11 screens: loading skeleton renders; error state shows retry; success state displays data; all interactive elements keyboard-accessible; screen-reader semantic labels present in `apps/customer/test/modules/profile/`

**Checkpoint**: All 11 screens render all three states; design system components used exclusively; widget tests pass.

---

## Phase 16: Flutter Agent — Profile Screens

**Purpose**: Implement the 5-screen Agent subset per spec §15.2.

- [X] T137 Create `AgentProfileStateNotifier` Riverpod provider in `apps/agent/lib/features/profile/providers/agent_profile_state_notifier.dart`
- [X] T138 [P] Implement agent profile view screen (US1; agent auth context) in `apps/agent/lib/features/profile/screens/agent_profile_view_screen.dart`
- [X] T139 [P] Implement agent personal info edit screen (US2) in `apps/agent/lib/features/profile/screens/agent_profile_edit_screen.dart`
- [X] T140 [P] Implement agent photo upload screen (US3) in `apps/agent/lib/features/profile/screens/agent_photo_screen.dart`
- [X] T141 Implement agent mobile change + verify screens (US5 + US6; email change NOT in agent scope) in `apps/agent/lib/features/profile/screens/`
- [X] T142 [P] Implement agent account status screen (US8) in `apps/agent/lib/features/profile/screens/agent_status_screen.dart`
- [X] T143 Write Flutter widget tests for all 5 Agent screens in `apps/agent/test/features/profile/`

**Checkpoint**: All 5 agent screens functional; email change navigation absent from agent app.

---

## Phase 17: Admin Next.js — Profile Management Screens

**Purpose**: Implement 5 admin profile pages using `[data-theme="admin"]` dark teal theme per spec §15.3.

- [X] T144 [P] Implement user profile detail page: read-only view with edit mode; all profile fields; signed photo; design system `Card`, `Button`, `Input`; edit gated on `profile.update` permission in `apps/admin/src/modules/profile/pages/user-profile.tsx` + route at `apps/admin/app/(admin)/users/[id]/profile/page.tsx`
- [X] T145 [P] Implement account status + history page: current status `Badge` (color-coded); history `Table` with `actor_id`, `from_status`, `to_status`, `reason`, `created_at`; keyboard-navigable in `apps/admin/src/modules/profile/pages/account-status.tsx` + route at `apps/admin/app/(admin)/users/[id]/profile/status/page.tsx`
- [X] T146 Implement suspend account dialog: required `reason` `Input`; `Button` disabled until reason entered; confirmation before API call; gated on `account.suspend` permission in `apps/admin/components/profile/SuspendDialog.tsx`
- [X] T147 [P] Implement reactivate and cancel-closure confirmation dialogs: `Dialog` with optional reason; gated on `account.reactivate` permission in `apps/admin/components/profile/ReactivateDialog.tsx`
- [X] T148 Implement permanent close confirmation dialog: `Dialog` with obligation check result displayed if blocked; two-step confirmation; gated on `account.close` permission in `apps/admin/components/profile/CloseAccountDialog.tsx`
- [X] T149 Write Next.js integration tests: profile page renders all fields; suspend dialog requires reason before enabling submit; close dialog shows obligation list when blocked; all destructive actions require correct permission in `apps/admin/__tests__/features/profile/`

**Checkpoint**: All 5 admin pages render; destructive actions require confirmation; permissions gate UI rendering.

---

## Phase 18: Security, Concurrency & Accessibility Tests

**Purpose**: Comprehensive security verification and concurrency safety per spec §19.

- [X] T150 [P] Write concurrency test: 10 concurrent `PUT /v1/users/profile/personal` with same `Idempotency-Key` → exactly one DB write; all return same `200` in `services/api/app/Modules/Profile/Tests/Feature/PersonalInfoUpdateTest.php`
- [X] T151 [P] Write concurrency test: 5 concurrent `POST /v1/users/profile/email/change` → one pending challenge; all others `409 CONTACT_CHANGE_IN_PROGRESS` in `services/api/app/Modules/Profile/Tests/Security/EmailChangeSecurityTest.php`
- [X] T152 [P] Write concurrency test: concurrent closure request + admin suspension → state machine serializes; no invalid intermediate state in `services/api/app/Modules/Profile/Tests/Feature/AccountStatusConcurrencyTest.php`
- [X] T153 [P] Write comprehensive IDOR test: user A cannot `GET`, `PUT`, or `DELETE` resources belonging to user B even with arbitrary UUIDs; all mismatches return `403` never `404` in `services/api/app/Modules/Profile/Tests/Security/ProfileIdorTest.php`
- [X] T154 [P] Write privileged operations test: `account.suspend`, `account.reactivate`, `account.close` endpoints return `403` for regular users and for admin tokens missing the specific permission in `services/api/app/Modules/Profile/Tests/Security/AdminPrivilegeTest.php`
- [X] T155 [P] Write photo URL security test: `profile_photo_key` never in any API response; signed URL has expiry; signed URL does not embed raw storage credentials in `services/api/app/Modules/Profile/Tests/Security/PhotoUrlSecurityTest.php`
- [X] T156 [P] Write data export security test: parse export JSON and verify `profile_photo_key`, credential hashes, OTP values, raw IPs, and session tokens are absent in `services/api/app/Modules/Profile/Tests/Security/DataExportSecurityTest.php`
- [X] T157 [P] Write WCAG AA accessibility tests for Flutter Customer screens: form inputs have semantic labels; error messages associated via semantics; run Flutter accessibility test tools in `apps/customer/test/features/profile/accessibility_test.dart`
- [X] T158 [P] Write WCAG AA accessibility tests for admin Next.js pages: history table keyboard-navigable; dialogs focus-trapped; error messages linked via `aria-describedby`; verify with `axe-core` in `apps/admin/__tests__/features/profile/accessibility.test.tsx`

**Checkpoint**: All security, concurrency, and accessibility tests pass.

---

## Phase 19: Performance Tests (K6)

**Purpose**: Prove P95 latency targets per spec §9.

- [X] T159 Create K6 config with shared environment variables and P95 threshold definitions in `tests/performance/profile/config.js`
- [X] T160 [P] Create K6 script for `GET /v1/users/profile`: P95 ≤ 200 ms; 60 s at `K6_VUSER_COUNT` VUs in `tests/performance/profile/view-profile.js`
- [X] T161 [P] Create K6 script for `PUT /v1/users/profile/personal`: P95 ≤ 300 ms; unique `Idempotency-Key` per VU per iteration in `tests/performance/profile/update-personal.js`
- [X] T162 [P] Create K6 script for `POST /v1/users/profile/email/change`: P95 ≤ 500 ms in `tests/performance/profile/initiate-contact-change.js`
- [X] T163 [P] Create K6 script for `GET /v1/users/profile/status` with 100-entry history seeded: P95 ≤ 200 ms in `tests/performance/profile/view-status.js`

**Checkpoint**: All 4 K6 scripts run without errors; thresholds defined; P95 targets verifiable.

---

## Phase 20: E2E Integration

**Purpose**: Full-lifecycle E2E scenarios per spec §18 spanning E01 + E02.

- [X] T164 Write E2E scenario 1 (Registration → Profile View): register via E01 → consume `Identity.User.Registered` → `GET /v1/users/profile` returns `account_status = active`; personal fields NULL; `profile.user.profile-created` audit present in `services/api/app/Modules/Profile/Tests/Feature/E2eProfileLifecycleTest.php`
- [X] T165 [P] Write E2E scenario 2 (Personal Info Update): `PUT /v1/users/profile/personal` → re-fetch → fields reflected; audit + event in outbox in `services/api/app/Modules/Profile/Tests/Feature/E2eProfileLifecycleTest.php`
- [X] T166 Write E2E scenario 3 (Mobile Change): initiate → security alert event emitted → verify OTP → both `profile.user_profiles.mobile_number` and `identity.users.mobile_number` updated → `Profile.User.MobileChanged` in outbox in `services/api/app/Modules/Profile/Tests/Feature/E2eContactChangeTest.php`
- [X] T167 [P] Write E2E scenario 4 (Suspend/Reactivate): admin suspend → GET status `suspended` with reason → admin reactivate → `active` → history has both transitions in `services/api/app/Modules/Profile/Tests/Feature/E2eAccountLifecycleTest.php`
- [X] T168 Write E2E scenario 5 (Closure + Cancel + Close): stub blocks → inject mock E06 zero obligations → request succeeds → `pending_closure` → user self-cancels → `active` → admin closes → `closed` in `services/api/app/Modules/Profile/Tests/Feature/E2eAccountLifecycleTest.php`

**Checkpoint**: All 5 E2E scenarios pass; cross-module boundaries exercised; no partial states.

---

## Dependencies & Execution Order

### Phase Dependencies

| Phase | Depends On | Notes |
|---|---|---|
| Phase 1 (Setup) | None | Start immediately |
| Phase 2 (Database) | Phase 1 | Blocks all story phases |
| Phase 3 (US9/US1) | Phases 1, 2 | 🎯 MVP gate |
| Phase 4 (US2) | Phase 3 | Requires `ProfileService` base |
| Phase 5 (US3) | Phase 3 | Requires profile record + storage contract |
| Phase 6 (US4/US7) | Phase 3, T011 (IdentityModuleContract extension) | |
| Phase 7 (US5/US6) | Phase 6 | Reuses `ContactChangeService` |
| Phase 8 (US8) | Phase 3 | Requires status field + history table |
| Phase 9 (US10/US11) | Phase 8 | Requires `AccountStatusService` |
| Phase 10 (US13) | Phases 9, 11 | Requires gate + status service |
| Phase 11 (US14) | Phase 2 | Requires `ClosureGateService` |
| Phase 12 (US12) | Phases 10, 11 | |
| Phase 13 (US15) | Phase 3 | Requires profile record + storage |
| Phase 14 (Observability) | Phase 3 onwards | Complete alongside Phase 13 |
| Phases 15–17 (Frontend) | Phase 3 | Can scaffold in parallel with Phases 4–13 |
| Phase 18 (Security) | Phases 6–13 | Full test suite |
| Phase 19 (Performance) | Phases 3–13 | |
| Phase 20 (E2E) | All backend phases | |

### Parallel Opportunities Per Phase

All tasks marked `[P]` within the same phase run concurrently (different files).

Backend phases 4–13 can be parallelized across developers after Phase 3 completes.

Flutter (Phase 15) and Admin Next.js (Phase 17) can scaffold state management and routing in parallel with backend Phase 4+ work.

---

## Acceptance Criterion → Task Coverage

| AC | Requirement | Task(s) | Test(s) |
|---|---|---|---|
| AC-001 | Profile view | T029, T030, T032 | T034 |
| AC-002 | IDOR on profile view | T030, T033 | T036, T153 |
| AC-003 | Personal info update | T041, T043 | T046 |
| AC-004 | Protected fields rejected | T039, T041 | T047 |
| AC-005 | Minimum age validation | T041 | T048 |
| AC-006 | Photo upload + signed URL | T050, T051 | T053 |
| AC-007 | Photo size limit | T050 | T054 |
| AC-008 | Email change full flow | T057, T058 | T064, T065 |
| AC-009 | Original email unchanged before verify | T057 | T064 |
| AC-010 | Mobile change full flow | T070, T071 | T076 |
| AC-011 | Mobile uniqueness no disclosure | T070 | T067 |
| AC-012 | Status view + history | T079, T080 | T084 |
| AC-013 | Admin suspend with reason | T085, T088 | T091 |
| AC-014 | Admin reactivate | T085, T089 | T091 |
| AC-015 | Closure blocked by wallet balance | T101, T104 | T104 |
| AC-016 | Closure request → pending_closure | T094, T096 | T099 |
| AC-017 | Admin permanent close | T107, T108 | T110 |
| AC-018 | Admin close blocked by obligations | T107, T105 | T111 |
| AC-019 | Data export + audit | T113 | T117 |
| AC-020 | Export excludes credentials | T114 | T118 |
| AC-021 | Profile created idempotently on event | T026, T027 | T037, T038 |
| AC-022 | All mutations audited | T123 (covers all 14) | T123 |
| AC-023 | Events via outbox | T026, T057, T070, T085, T094, T113 | T034, T064, T076, T091, T099 |
| AC-024 | No hard-coded config values | T018, T041, T050, T085 | T025, T048 |
| AC-025 | Idempotency on personal update | T042, T043 | T046, T150 |
| AC-026 | Security alert on contact change | T057, T070 | T064, T076 |
| AC-027 | Self-service closure cancellation | T095, T096 | T099, T100 |
| AC-028 | Atomic rollback; challenge preserved | T058, T071 | T066, T077 |

---

## Implementation Strategy

### MVP Scope (Phases 1–3 Only)

1. Complete Phase 1 (Setup — contracts, scaffold, ADRs)
2. Complete Phase 2 (Database — migrations, models, repositories)
3. Complete Phase 3 (Profile creation + view)
4. **VALIDATE**: `GET /v1/users/profile` returns profile; IDOR test passes; `Profile.User.ProfileCreated` audited; duplicate event idempotent
5. Deploy/demo minimal read capability

### Incremental Delivery

Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phases 6+7 (contact change) → Phase 8 → Phase 9 → Phase 11 → Phase 10 → Phase 12 → Phase 13 → Phase 14 → Phases 15–17 → Phase 18 → Phase 19 → Phase 20

---

## Phase 21: Gap Remediation

**Purpose**: Close all HIGH and MEDIUM findings from the `/speckit-analyze` quality gate, plus safe LOW findings. Finding codes in task descriptions (C1–C7, I2–I4) trace directly to the analysis report. No existing task T001–T168 is modified or deleted. Each task identifies the existing phase it extends and the dependency it gates on.

**APPEND-ONLY**: T169–T180. Do not renumber, reorder, or remove any task in this section.

---

### C1 — E01 Concrete Contract Implementation Gate

- [X] T169 Before running Phase 3 integration tests (T037, T038), confirm that E01 Phase 34 tasks T239–T244 (appended to `specs/001-central-identity-authentication/tasks.md`) are all marked `[X]`; specifically verify that `IdentityModuleService::getUserContactInfo()` and `IdentityModuleService::updateUserContact()` are implemented and bound in E01's `IdentityServiceProvider`; if any are incomplete, block Phase 3 integration test execution and document the blocking status; `CreateProfileOnUserRegisteredListener` (T026) calls `getUserContactInfo()`; `ContactChangeService::verifyEmailChange()` and `verifyMobileChange()` (T058, T071) call `updateUserContact()` — both throw `BadMethodCallException` at runtime until E01 T239–T244 are complete (C1; ADR-E02-001; E02 plan §Dependencies row "E01 IdentityModuleContract extension"; **gate for Phase 3 integration tests**)

---

### C2 — OTP Edge Case Tests for Email and Mobile Change

- [X] T170 Write three OTP edge-case feature tests in `services/api/app/Modules/Profile/Tests/Feature/EmailChangeTest.php` (append to existing test class; do not remove T064 or T065): (a) **OTP_EXPIRED** — initiate email change to obtain `challenge_id`; use `Carbon::setTestNow()` to advance time past the OTP TTL configured in E01; call `POST /v1/users/profile/email/verify` with the expired challenge → assert `400` with `error.code = OTP_EXPIRED`; assert `profile.user_profiles.pending_email` is `null` and `pending_email_challenge_id` is `null` (fields nullified because the expired challenge is done; user must re-initiate to get a fresh OTP — per plan §Database Retention and data-model.md column note "cleared on verify or expiry"); (b) **SAME_CONTACT_VALUE** — call `POST /v1/users/profile/email/change` where `new_email` is identical to the profile's current `email` value → assert `400 SAME_CONTACT_VALUE`; assert no pending challenge created; assert no outbox event emitted; (c) **CHALLENGE_NOT_FOUND** — call `POST /v1/users/profile/email/verify` with a `challenge_id` that is a valid UUID but belongs to no record (or belongs to a different user) → assert `404 CHALLENGE_NOT_FOUND` (C2; spec §16 error codes; contracts/profile-api.md §POST /v1/users/profile/email/verify; AC-008; **extends Phase 6**)

- [X] T171 [P] Write the same three OTP edge-case feature tests in `services/api/app/Modules/Profile/Tests/Feature/MobileChangeTest.php` (append to existing test class; do not remove T076): (a) **OTP_EXPIRED** for mobile verify — same `Carbon::setTestNow()` pattern; assert `400 OTP_EXPIRED`; assert `profile.user_profiles.pending_mobile` is `null` and `pending_mobile_challenge_id` is `null` (fields nullified because the expired challenge is done; user must re-initiate — per plan §Database Retention); (b) **SAME_CONTACT_VALUE** for mobile change initiation — `new_mobile` equals current `mobile_number` → `400 SAME_CONTACT_VALUE`; no challenge created; no outbox event; (c) **CHALLENGE_NOT_FOUND** on mobile verify with unknown `challenge_id` → `404 CHALLENGE_NOT_FOUND`; each assertion mirrors T170 exactly but targets `POST /v1/users/profile/mobile/change` and `POST /v1/users/profile/mobile/verify` (C2; spec §16; contracts/profile-api.md §POST /v1/users/profile/mobile/verify; AC-010; **extends Phase 7**)

---

### C3 — Data-Export Poll Endpoint Feature Test

- [X] T172 Write feature tests for `GET /v1/users/profile/data-export/{export_id}` in `services/api/app/Modules/Profile/Tests/Feature/DataExportTest.php` (append to existing test class; do not remove T117 or T118): (a) **Processing state** — after `POST /v1/users/profile/data-export` (export created, job not yet run), call `GET /v1/users/profile/data-export/{export_id}` → assert `200`; `data.status = processing`; `data.download_url = null`; `data.expires_at = null`; (b) **Ready state** — seed a `profile.data_exports` record directly with `status = ready`, a signed `download_url`, and a future `expires_at`; call the GET endpoint → assert `200`; `data.status = ready`; `data.download_url` is a non-null string (signed URL); `data.expires_at` is a future ISO-8601 timestamp; (c) **IDOR** — authenticate as user B; attempt to GET an export that belongs to user A → assert `403 FORBIDDEN` (not `404`); no export data disclosed in response body; (d) **Not found** — GET with a UUID that exists in no record → assert `404` (C3; contracts/profile-api.md §GET /v1/users/profile/data-export/{export_id}; AC-019; **extends Phase 13**)

---

### C4 — API-Layer Financial Obligation Gate Test with `wallet_balance`

- [X] T173 Write an API feature test in `services/api/app/Modules/Profile/Tests/Feature/ClosureObligationTest.php` (append to existing test class; do not remove T104 or T105) using a mock — not the stub — `FinancialObligationContract` returning `hasObligations: true, blockedBy: ['wallet_balance']`: (a) **User path** — bind the mock in the test's service container; call `POST /v1/users/profile/closure/request` → assert `409`; `error.code = CLOSURE_BLOCKED_BY_OBLIGATIONS`; `error.details.blocked_by` array contains `wallet_balance`; assert `profile.user_profiles.account_status` is unchanged (not `pending_closure`); assert `Profile.Account.ClosureBlocked` is in `platform.outbox_events` with `blocked_by: ['wallet_balance']`; (b) **Admin path** — same mock; call `POST /v1/admin/users/{user_id}/close` on a `pending_closure` account → assert `409`; `blocked_by = ['wallet_balance']`; this satisfies AC-015 ("blocked_by includes `wallet_balance`") at the HTTP API layer; T103 covers the service unit level, T104 covers the stub path — this test closes the API-layer gap (C4; AC-015; contracts/profile-api.md §POST /v1/users/profile/closure/request; **extends Phase 11**)

---

### C5 — Config-Source Verification for Photo Limits and Age Threshold

- [X] T174 Write feature tests in `services/api/app/Modules/Profile/Tests/Feature/ProfilePhotoValidationTest.php` (append to existing test class; do not remove T054) that prove validation limits are sourced from `platform.configuration_values` at runtime, not from hard-coded constants: (a) **Photo size limit is config-driven** — seed `profile.photo.max_size_bytes = 100` via `ConfigurationService` in test setup; upload a 200-byte valid-MIME file → assert `413 FILE_TOO_LARGE`; then update the config to `profile.photo.max_size_bytes = 1048576` without restarting; upload the same 200-byte file → assert `200` (if this test fails, the service is reading a constant, not the config); (b) **Age threshold is config-driven** — in `PersonalInfoValidationTest.php` (append only), seed `profile.minimum_user_age_years = 18`; call `PUT /v1/users/profile/personal` with `date_of_birth` resulting in age 17 → assert `422 MINIMUM_AGE_VIOLATION`; then update config to `profile.minimum_user_age_years = null`; resubmit the same `date_of_birth` → assert `200` (null means "check not active" per spec); confirms no hard-coded age fallback (C5; AC-024; FR-E02-044; spec §8 Configuration Values; **extends Phases 5 and 4**)

---

### C6 — OpenAPI Generation and Contract Gate

- [X] T175 Add E02 profile routes to the project's OpenAPI generation pipeline: update `services/api/config/scramble.php` (or equivalent OpenAPI generation config) to include E02's route prefix (`/v1/users/profile`, `/v1/admin/users`); run `php artisan scramble:export` to produce `services/api/docs/openapi/profile.yaml`; validate the output contains all 19 endpoints defined in `contracts/profile-api.md` with correct HTTP methods, path parameters, `Authorization: Bearer` header documented, `Idempotency-Key` header documented for all state-changing endpoints (`PUT`, `POST`, `DELETE`), and error response shapes matching the common error-code table; add an `openapi:generate` CI step that fails if the generated YAML omits any of the 19 endpoints or if a documented error code is absent; this satisfies DoD §21 "OpenAPI documentation generated and accurate" (C6; DoD §21; **add to Phase 14 completion gate**)

- [X] T176 [P] Write a contract gate test in `services/api/app/Modules/Profile/Tests/Feature/ProfileContractGateTest.php`: for each of the 19 endpoints in `contracts/profile-api.md`, assert (1) the Laravel route is registered (`Route::has()` or `Route::getRoutes()` lookup); (2) the bound controller class and method exist and are callable via reflection; (3) for state-changing routes, the Form Request class declares `Idempotency-Key` as a required header; (4) for at least one happy-path call per endpoint group, the response envelope contains the Platform Foundation keys (`success`, `data`, `error`, `meta`); run this gate as part of Phase 14 and in every PR CI check; a failing gate means a route or form request was removed or renamed without updating the contract (C6; DoD §21; contracts/profile-api.md; **add to Phase 14**)

---

## Phase 22: Admin Dashboard Overview (Profile Oversight)

**Purpose**: Add the dashboard surface that gives admins a profile-centric operational overview for profile health, account lifecycle risk, and pending profile change activity.

- [X] T177 Create admin dashboard summary route and page at `apps/admin/app/dashboard/page.tsx` and `apps/admin/src/modules/dashboard/pages/admin-dashboard.tsx` with KPI cards for total users, active accounts, suspended accounts, pending closure requests, and recently changed contact records.
- [X] T178 Implement `AdminDashboardSummaryDTO` and dashboard query/service layer to aggregate profile/account counts and recent profile-change activity from the E02 profile APIs and platform audit records without exposing raw PII in summary payloads.
- [X] T179 Add admin dashboard API contract and route: `GET /v1/admin/profile/dashboard/summary` returning only aggregate counts and masked identifiers for recent activity; require `profile.read` and admin-only permission checks on the server side.
- [X] T180 Add admin dashboard UI behaviors: loading, empty, and error states; account-status color coding; quick links to profile status and suspension controls; accessibility labels and keyboard navigation for all cards and actions.
- [X] T181 Write tests for the dashboard summary flow: API feature test for authorized access, IDOR/permission denial test for non-admin actors, and Next.js render test covering KPI values, empty states, and quick links.

**Checkpoint**: Admin dashboard summary loads for authorized admins; non-admin access is denied; cards display aggregated profile-health metrics without exposing raw PII.
---

### C7 — Profile Mutation Rate-Limit Configuration and Enforcement

- [X] T177 Extend the migration file created by T018 (`2026_08_27_004_seed_profile_configuration_defaults.php`) to include two additional `insertOrIgnore` rows for `profile.rate_limit.user_per_minute` (`null`, `is_tbd = true`) and `profile.rate_limit.photo_per_minute` (`null`, `is_tbd = true`); then register the two named throttle groups in `ProfileServiceProvider::boot()` via `RateLimiter::for('profile-user', fn($req) => Limit::perMinute($this->config->get('profile.rate_limit.user_per_minute') ?? 60)->by($req->user()?->id))` and `RateLimiter::for('profile-photo', fn($req) => Limit::perMinute($this->config->get('profile.rate_limit.photo_per_minute') ?? 20)->by($req->user()?->id))`; the `?? 60` and `?? 20` fallbacks apply ONLY when the config value is `null` (TBD); no business-policy constant may be used as a non-null default; this also resolves finding I4 (throttle aliases must exist before routes reference them) (C7/I4; spec §9 Security; plan §Security Plan; AC-024; **extends Phase 1 service provider and Phase 2 migration**)

- [X] T178 [P] Write rate-limit enforcement feature tests in `services/api/app/Modules/Profile/Tests/Feature/RateLimitEnforcementTest.php`: (a) **Profile mutation throttle** — seed `profile.rate_limit.user_per_minute = 2`; send request 1 and request 2 each with a unique `Idempotency-Key` → both `200`; send request 3 → assert `429` with `error.code = RATE_LIMIT_EXCEEDED` and `Retry-After` header present; (b) **Photo upload throttle** — seed `profile.rate_limit.photo_per_minute = 1`; first upload → `200`; second upload → `429`; (c) **Per-user isolation** — user A at limit does not affect user B on same endpoint; (d) **Reset between tests** — call `RateLimiter::clear('profile-user:'.$userId)` in `tearDown()` to prevent cross-test pollution; all config values set via `ConfigurationService` mock in test setup — no `Config::set()` of hard-coded values (C7; spec §9; plan §Security Plan; contracts/profile-api.md §Rate limiting; **adds to Phase 18**)

---

### LOW Findings — I2 (ProfileModuleService), I3 (PiiMaskingHelper pre-check)

- [X] T179 Create `ProfileModuleService` implementing `ProfileModuleContract` in `services/api/app/Modules/Profile/Domain/Services/ProfileModuleService.php`: `getAccountStatus(string $userId): ?string` → call `$this->userProfileRepository->findByUserId($userId)` and return `$profile->account_status ?? null`; `isProfileComplete(string $userId): bool` → return true iff profile exists and `first_name`, `last_name`, and `date_of_birth` are all non-null; bind `ProfileModuleContract::class → ProfileModuleService::class` as a singleton in `ProfileServiceProvider`; write unit tests in `services/api/app/Modules/Profile/Tests/Unit/ProfileModuleServiceTest.php`: (a) `getAccountStatus()` returns correct status string for known user; returns `null` for unknown user; (b) `isProfileComplete()` returns `true` when all three fields populated; returns `false` when any is null; mock `EloquentUserProfileRepository` (I2; contracts/module-contracts.md §ProfileModuleContract; **extends Phase 1; prerequisite for downstream modules consuming E02**)

- [X] T180 [P] Before implementing T121 (`PiiMaskingHelper`): search `services/api/app/Modules/Identity/Domain/Services/`, `services/api/app/Modules/Platform/`, and any shared Platform Foundation utility namespace for an existing PII masking utility (look for `maskEmail`, `maskMobile`, or equivalent static methods); if found, import and use it directly in `ProfilePhotoService`, `ContactChangeService`, and any other profile service that masks email or mobile — do NOT duplicate the masking logic; if NOT found, proceed to implement `PiiMaskingHelper.php` as described in T121 with the `maskEmail(string $email): string` and `maskMobile(string $mobile): string` methods; add a one-line comment in `ProfileServiceProvider.php` documenting the outcome: either `// PiiMaskingHelper reuses E01 masking logic` or `// PiiMaskingHelper: no equivalent found in E01 or Platform Foundation`; consistent masking rules across modules are required by spec §PII masking (I3; spec §PII masking; plan §Security Plan; **prerequisite to T121**)

---

## Gap Remediation Traceability Table

| Finding | Severity | New Task(s) | Requirement / AC | Test Coverage |
|---|---|---|---|---|
| C1 — E01 contract gate | HIGH | T169 | ADR-E02-001; AC-008, AC-010 (indirect) | Gate check before T037, T038 run |
| C2 — OTP edge cases (email) | MEDIUM | T170 | AC-008; spec §16 OTP_EXPIRED, SAME_CONTACT_VALUE, CHALLENGE_NOT_FOUND | T170 (3 test cases) |
| C2 — OTP edge cases (mobile) | MEDIUM | T171 | AC-010; spec §16 same 3 codes | T171 (3 test cases) |
| C3 — export poll endpoint | MEDIUM | T172 | AC-019; contracts/profile-api.md §GET export/{id} | T172 (processing + ready + IDOR + 404 cases) |
| C4 — wallet_balance API test | MEDIUM | T173 | AC-015; contracts/profile-api.md §closure/request | T173 (user path + admin path with mock) |
| C5 — config-source proof | MEDIUM | T174 | AC-024; FR-E02-044 | T174 (photo + age config-driven assertions) |
| C6 — OpenAPI generation | MEDIUM | T175, T176 | DoD §21 | T176 (contract gate test for all 19 endpoints) |
| C7 — rate-limit config + test | MEDIUM | T177, T178 | spec §9; AC-024 | T178 (throttle enforcement + per-user isolation) |
| I2 — ProfileModuleService | LOW | T179 | contracts/module-contracts.md §ProfileModuleContract | T179 (unit tests for both methods) |
| I3 — PiiMaskingHelper check | LOW | T180 | spec §PII masking; plan §Security Plan | Prerequisite to T121 |
| I4 — throttle alias registration | LOW | T177 (merged) | plan §Security Plan; spec §9 | T178 covers enforcement |

---

## Phase 22: Convergence

**Purpose**: Close all findings from the `/speckit-converge` checkpoint after Phases 1–3 (T001–T038, T177). Finding codes (F1–F6) trace to the convergence report. No existing task T001–T180 is modified or deleted.

**APPEND-ONLY**: T181–T186. Do not renumber, reorder, or remove any task in this section.

---

### F1 — Missing `profile.view.success` Metric (HIGH)

- [X] T181 Create `ProfileMetrics` service in `services/api/app/Modules/Profile/Domain/Services/ProfileMetrics.php`: wraps `MetricsCollectorInterface`; exposes `viewSuccess(string $actorType): void` that calls `$this->collector->increment('profile.view.success', ['actor_type' => strtolower($actorType)])` following the E01 `IdentityMetrics` pattern; register as singleton in `ProfileServiceProvider`; inject into `ProfileController` and call `$this->metrics->viewSuccess($actorType)` immediately before returning the 200 response in `show()`; add a unit test in `services/api/app/Modules/Profile/Tests/Unit/ProfileMetricsTest.php` asserting the collector receives the correct metric name and label on each call (F1; T030; plan §Observability; **extends Phase 3 ProfileController**)

---

### F2 — Admin Permission Gate Incomplete (HIGH)

- [X] T182 Implement `profile.permission:{permission}` middleware in `services/api/app/Modules/Profile/Infrastructure/Http/Middleware/ProfilePermissionMiddleware.php`: reads `identity.permissions` from `$request->attributes` (populated by `IdentityAuthMiddleware` from JWT claims once E04 is live); if the attribute is absent or the required permission is not in the set, return `403 FORBIDDEN` with `error.code = FORBIDDEN`; register the middleware alias `profile.permission` in `ProfileServiceProvider::boot()` via `$router->aliasMiddleware('profile.permission', ProfilePermissionMiddleware::class)`; apply `profile.permission:profile.read` to the `GET /v1/admin/users/{user_id}/profile` route; apply `profile.permission:profile.update` to the `PUT /v1/admin/users/{user_id}/profile/personal` route when registered in Phase 4 (T045); note: until E04 populates `identity.permissions` in JWTs, `identity.permissions` will be absent — the middleware MUST return `403` in that case (fail-closed); write feature tests in `services/api/app/Modules/Profile/Tests/Feature/AdminPermissionTest.php`: (a) request with `identity.permissions` containing `profile.read` → `200`; (b) request with `identity.permissions` missing `profile.read` → `403`; (c) request with `identity.permissions` absent (pre-E04 state) → `403`; update `FakeIdentityAuthMiddleware` to accept an `X-Test-Permissions` header (comma-separated list) and set `identity.permissions` on request attributes (F2; T031; plan §Security §IDOR "enforced at service layer"; constitution P5; **extends Phase 3 admin route; prerequisite for Phase 4 T044, T045**)

---

### F3 — Audit actorId Incorrect for Profile Creation (MEDIUM)

- [X] T183 In `CreateProfileOnUserRegisteredListener::handle()`, replace `actorId: $userId` with `actorId: '00000000-0000-0000-0000-000000000000'` and `actorType: 'SYSTEM'` in the `AuditEventData` record for `profile.user.profile-created`; per plan §Audit Plan: "System (from event listener); zero-UUID or system actor"; update `CreateProfileListenerTest.php` assertion to verify `actorId` is the zero-UUID and `actorType` is `SYSTEM` in the audit record (F3; plan.md §Audit Plan; **extends Phase 3 listener**)

---

### F4 — Missing `profile-admin` Rate Limiter (MEDIUM)

- [X] T184 Add `profile.rate_limit.admin_per_minute` config key (null, is_tbd=true) to the `insertOrIgnore` block in migration `2026_08_27_004000_seed_profile_configuration_defaults.php` (or via a new migration `2026_08_27_005000_add_profile_admin_rate_limit_config.php` if migration 004 is already deployed); register `RateLimiter::for('profile-admin', ...)` in `ProfileServiceProvider::boot()` reading `profile.rate_limit.admin_per_minute` from `ConfigurationServiceInterface` with `?? 120` null-only fallback; update the `GET /v1/admin/users/{user_id}/profile` route (and all future admin routes) in `routes/modules/profile.php` to use `throttle:profile-admin` instead of `throttle:profile-user`; update `MigrationTest.php` to assert the `profile.rate_limit.admin_per_minute` config key is seeded (F4; plan.md §API Plan §Middleware Stack; plan.md §Middleware Stack "throttle:profile-admin"; **extends Phase 1 ServiceProvider and Phase 2 migration**)

---

### F5 — API Envelope `meta` Field Discrepancy (LOW)

- [X] T185 [P] Resolve the `meta` field discrepancy between `contracts/profile-api.md` and the established E01 platform pattern: (a) inspect all E01 `JsonResource` and controller error responses to confirm none include a `meta: { correlation_id, request_id, timestamp }` field; (b) if E01 has no `meta` field: update `contracts/profile-api.md` to remove the `meta` key from the envelope spec and document the correction with a note "Aligned to E01 platform pattern (no meta field in Platform Foundation envelope)"; (c) if E01 does include `meta`: add the `meta` field to `ProfileResource::toArray()` and both controllers' `errorResponse()` helpers with `correlation_id` from `X-Correlation-ID` header, `request_id` as a fresh UUID, `timestamp` as `now()->toIso8601String()`; update `ProfileViewTest.php` to assert `meta.correlation_id` is present; only one outcome is correct — do not implement both (F5; contracts/profile-api.md §Envelope; **no-op if E01 lacks meta; extends contracts doc or ProfileResource**)

---

### F6 — Dead `IdentityModuleContract` Injection (LOW)

- [X] T186 [P] Remove the unused `IdentityModuleContract` constructor parameter from `ProfileController` (`services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ProfileController.php`): delete the `private readonly IdentityModuleContract $identity` property, the corresponding constructor parameter, and the `use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;` import; `user_id` is correctly read from `$request->attributes->get('identity.user_id')` set by `IdentityAuthMiddleware` — no direct `IdentityModuleContract` call is needed in the controller; verify `ProfileViewTest.php` and `ProfileIdorTest.php` still pass after removal (F6; T030; **extends Phase 3 ProfileController**)

---

## Phase 23: Convergence

**Purpose**: Close all HIGH and MEDIUM findings from the `/speckit-converge` checkpoint after Phase 6 (T055–T067). Finding codes (F1–F4) trace to the convergence report. No existing task T001–T186 is modified or deleted.

**APPEND-ONLY**: T187–T190. Do not renumber, reorder, or remove any task in this section.

---

### F1 — Idempotency Acquire-Lock/Replay Order Inverted in ContactChangeController (HIGH)

- [X] T187 Fix idempotency order in `ContactChangeController::initiateEmail()` and `verifyEmail()` in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ContactChangeController.php`: in both methods, move `acquireLock()` (with `$requestHash`) to execute **before** `findCompletedResponse()`, matching the pattern already applied to `ProfileController::updatePersonal()` during Phase 6; the corrected order is: (1) compute `$requestHash = hash('sha256', $request->getContent())`, (2) `$lock = $this->idempotency->acquireLock(...)` — throws `IdempotencyKeyMismatchException` on payload hash mismatch, (3) `$cached = $this->idempotency->findCompletedResponse(...)` — replay only if lock acquired with matching hash; then write two feature-test cases in `services/api/app/Modules/Profile/Tests/Feature/EmailChangeTest.php` (append; do not remove T064): (a) **IDEMPOTENCY_KEY_MISMATCH on initiation** — call `POST /v1/users/profile/email/change` once with key K and `new_email = a@example.com` → `200`; then call again with the same key K but `new_email = b@example.com` (different payload) → assert `409` with `error.code = IDEMPOTENCY_KEY_MISMATCH`; assert no second OTP challenge is created; (b) **IDEMPOTENCY_KEY_MISMATCH on verification** — call `POST /v1/users/profile/email/verify` once with key K and an OTP → `200`; then call again with the same key K but a different OTP string → assert `409 IDEMPOTENCY_KEY_MISMATCH` (F1; spec §10.1 idempotent: Yes; Platform Foundation idempotency contract; **extends Phase 6 ContactChangeController and EmailChangeTest**)

---

### F2 — No Dedicated Contact-Change Rate Limiter; Seeded Config Key Unused (HIGH)

- [X] T188 Register a dedicated `profile-contact-change` named rate limiter in `ProfileServiceProvider::boot()` reading from `ConfigurationServiceInterface::get('profile.contact_change.otp_rate_limit_per_hour')`; when the config value is null (TBD), apply `Limit::none()` — do NOT use a hard-coded numeric fallback (constitution P2); the limiter is per-hour (use `Limit::perHour($limit)->by($r->user()?->id ?? $r->ip())`); update `routes/modules/profile.php` to apply `throttle:profile-contact-change` to both `POST users/profile/email/change` and `POST users/profile/email/verify` routes (replacing `throttle:profile-user` on those two routes); write a feature test in `services/api/app/Modules/Profile/Tests/Feature/RateLimitEnforcementTest.php` (append; do not remove T178): seed `profile.contact_change.otp_rate_limit_per_hour = 2` via `ConfigurationService`; make two initiation requests (unique idempotency keys, same user) → both `200`; third request → assert `429` with `error.code = RATE_LIMIT_EXCEEDED` and `Retry-After` header present; seed `profile.contact_change.otp_rate_limit_per_hour = null`; make a request → assert it is NOT rate-limited (Limit::none() path); call `RateLimiter::clear('profile-contact-change:' . $userId)` in tearDown (F2; spec §9 Security "stricter rate limits"; FR-E02-044; seeded config key `profile.contact_change.otp_rate_limit_per_hour`; **extends Phase 6 ProfileServiceProvider and routes**)

---

### F3 — CHALLENGE_ALREADY_COMPLETED Not Distinguishable from CHALLENGE_NOT_FOUND (HIGH)

- [X] T189 Add `last_completed_email_challenge_id` nullable UUID column to `profile.user_profiles` via a new migration `services/api/app/Modules/Profile/Infrastructure/Migrations/2026_08_30_006000_add_last_completed_challenge_tracking.php`; also add `last_completed_mobile_challenge_id` nullable UUID for symmetry with Phase 7 (T071); add both columns to `$guarded` in `UserProfileModel`; update `ContactChangeService::verifyEmailChange()` in `services/api/app/Modules/Profile/Domain/Services/ContactChangeService.php`: on successful verification, set `last_completed_email_challenge_id = $command->challengeId` within the same `DB::transaction()` that clears `pending_email_challenge_id`; update the `ChallengeNotFoundException` branch: if `$profile->pending_email_challenge_id === null` AND `$profile->last_completed_email_challenge_id === $command->challengeId`, throw a new `ChallengeAlreadyCompletedException` (create this exception class in `Domain/Exceptions/ChallengeAlreadyCompletedException.php`); handle `ChallengeAlreadyCompletedException` in `ContactChangeController::verifyEmail()` → return `409` with `error.code = CHALLENGE_ALREADY_COMPLETED`; write a feature test in `services/api/app/Modules/Profile/Tests/Feature/EmailChangeTest.php` (append; do not remove T065): initiate email change, verify OTP successfully → `200`; then call `POST /v1/users/profile/email/verify` again with a **different** `Idempotency-Key` but the same `challenge_id` → assert `409` with `error.code = CHALLENGE_ALREADY_COMPLETED` (F3; spec §10.2 email verify error conditions; **extends Phase 6 ContactChangeService, ContactChangeController, and UserProfileModel**)

---

### F4 — Events Emitted Outside DB Transaction in initiateEmailChange() (MEDIUM)

- [X] T190 Move the two `$this->events->publish()` calls in `ContactChangeService::initiateEmailChange()` inside the `DB::connection('profile')->transaction()` block in `services/api/app/Modules/Profile/Domain/Services/ContactChangeService.php`; the corrected order within the transaction is: (1) `$this->profiles->update(...)` — persist `pending_email` and `pending_email_challenge_id`, (2) `$this->events->publish(new EmailChangeInitiatedEvent(...))`, (3) `$this->events->publish(new ContactSecurityAlertEvent(...))`; the `$this->audit->record()` and `$this->metrics->contactChangeInitiated()` calls remain outside the transaction (audit and metrics are not part of the atomic profile state); **Note**: if `EventPublisherInterface` writes to `platform.outbox_events` on a separate database connection and the PHP database driver does not support cross-connection transactions, document this constraint as a comment and escalate via ADR — do NOT use XA transactions without an explicit architecture decision; verify T064 (`EmailChangeTest`) still passes after the move; verify that if the transaction rolls back (simulate by making `profiles->update()` throw), no events are published (F4; T057 "both via EventPublisherInterface within same transaction"; FR-E02-043; **extends Phase 6 ContactChangeService**)

---

## Phase 24: Convergence

**Purpose**: Close the MEDIUM gap identified during the `/speckit-converge` checkpoint after Phase 7 (T068–T077). Finding code (F1) traces to the convergence report. No existing task T001–T190 is modified or deleted.

**APPEND-ONLY**: T191. Do not renumber, reorder, or remove any task in this section.

---

### F1 — No Concurrent Mobile Change Security Test (MEDIUM, partial)

- [X] T191 Write concurrency test: spawn 5 concurrent `POST /v1/users/profile/mobile/change` requests for the same user with distinct idempotency keys → assert exactly one `pending_mobile_challenge_id` is created in `profile.user_profiles`; assert all other requests return `409` with `error.code = CONTACT_CHANGE_IN_PROGRESS`; place the test in `services/api/app/Modules/Profile/Tests/Security/MobileChangeSecurityTest.php`; mirror the structure of the existing `EmailChangeConcurrencyTest` (T151) which covers the same invariant for `pending_email_challenge_id`; the `ContactChangeInProgressException` guard in `ContactChangeService::initiateMobileChange()` is the enforcement point — the test must exercise it under real concurrent load, not sequential simulation (F1; spec §19.2 security tests "Concurrent email and mobile change requests: only one pending change per contact type allowed at a time; second request returns 409 CONTACT_CHANGE_IN_PROGRESS"; spec §16 edge case; US5/US6; partial)

---

## Phase 25: Convergence

**Purpose**: Close the MEDIUM gap identified during the `/speckit-converge` checkpoint after Phase 9 (T085–T092). Finding code (F1) traces to the convergence report. No existing task T001–T191 is modified or deleted.

**APPEND-ONLY**: T192. Do not renumber, reorder, or remove any task in this section.

---

### F1 — Metric `profile.account_status.transition` missing `from_status` label (MEDIUM, partial)

- [X] T192 Update `ProfileMetrics::accountStatusTransition()` to accept both `fromStatus` and `toStatus` string parameters and emit both as metric labels (`['from_status' => $fromStatus, 'to_status' => $toStatus]`); update the two call sites in `AccountStatusService::suspend()` (pass `$profile->account_status` as fromStatus, `AccountStatus::SUSPENDED->value` as toStatus) and `AccountStatusService::reactivate()` (pass `$profile->account_status` as fromStatus, `AccountStatus::ACTIVE->value` as toStatus); the spec §17.1 metric table states "Account status transitions; broken down by from/to status" — only `to_status` is currently emitted in `services/api/app/Modules/Profile/Domain/Services/ProfileMetrics.php` and `services/api/app/Modules/Profile/Domain/Services/AccountStatusService.php` (F1; spec §17.1 observability table `profile.account_status.transition`; US10/US11; partial)

---

## Phase 26: Convergence

**Purpose**: Close the gaps identified during `/speckit-converge` after Phase 10 (T093–T100). Two partial findings: missing idempotency on the user self-service DELETE cancel-closure endpoint, and the optional reason body not wired through. No existing task T001–T192 is modified or deleted.

**APPEND-ONLY**: T193–T194. Do not renumber, reorder, or remove any task in this section.

---

### F1 — DELETE /v1/users/profile/closure/request missing Idempotency-Key handling (MEDIUM, partial)

- [X] T193 Add `IdempotencyServiceInterface` to `ClosureController` constructor and implement idempotency in `cancel()`: (1) extract `Idempotency-Key` header — if empty return `422 VALIDATION_ERROR`; (2) call `findCompletedResponse($idempKey, $userId)` and replay cached 200 if found; (3) call `acquireLock($idempKey, $userId, hash('sha256', $request->getContent()), 'DELETE', '/v1/users/profile/closure/request', $correlationId)`; (4) on success call `recordResponse($lock, 200, $responseBody)`; (5) on exception call `recordFailure($lock, $e)` and rethrow; mirror the idempotency pattern in `ProfileController::updatePersonal()`; also pass `$request->input('reason')` to `CancelClosureCommand` while editing this file in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ClosureController.php`; update `ClosureCancellationTest.php` to include `Idempotency-Key` header on the self-service DELETE tests and assert duplicate-key replays 200 without double-write (F1; spec §9 DELETE /v1/users/profile/closure/request "Request data: Idempotency-Key header"; CLAUDE.md "State-changing financial APIs require idempotency"; partial)

### F2 — User cancel closure endpoint not consuming optional reason body field (LOW, partial)

- [X] T194 In `ClosureController::cancel()`, change the `CancelClosureCommand` construction to pass `reason: $request->input('reason')` so the optional request body field `{ "reason": "..." }` is wired through to the history record; add an assertion to `ClosureCancellationTest.php` that when a user cancels with `body = ['reason' => 'Changed my mind.']` the history entry reflects that reason in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ClosureController.php` (F2; spec §9 DELETE "optional { \"reason\": \"...\" } body"; US-USER-013; partial)

---

## Phase 27: Convergence

**Purpose**: Close the gaps identified during `/speckit-converge` after Phase 11 (T101–T105). Two MEDIUM gaps: POST closure/request missing idempotency, and admin permanent close not emitting closure-blocked event/audit/metric. One LOW gap: `closure_obligations` column never populated. No existing task T001–T194 is modified or deleted.

**APPEND-ONLY**: T195–T197. Do not renumber, reorder, or remove any task in this section.

---

### F1 — POST /v1/users/profile/closure/request missing Idempotency-Key handling (MEDIUM, missing)

- [X] T195 Add Idempotency-Key enforcement to `ClosureController::request()`: (1) extract `Idempotency-Key` header — if empty return `422 VALIDATION_ERROR`; (2) call `findCompletedResponse($idempKey, $userId)` and replay cached response if found; (3) call `acquireLock()` before processing; (4) on success call `recordResponse($lock, 200, $responseBody)`; (5) on `ClosureBlockedByObligationsException` call `recordFailure($lock, $e)` before returning 409; (6) on other exceptions call `recordFailure($lock, $e)` and rethrow; update `ClosureObligationTest.php` to pass `Idempotency-Key` on POST calls and assert duplicate-key replays 409 without double-emitting `ClosureBlocked` event in `services/api/app/Modules/Profile/Infrastructure/Http/Controllers/ClosureController.php` (F1; spec §10.1 idempotency "Yes" for US-USER-013 POST; all other "Yes" endpoints implement idempotency pattern; missing)

### F2 — Admin permanent close not auditing blocked closure (MEDIUM, partial)

- [X] T196 In `AccountStatusService::permanentlyClose()`, catch `ClosureBlockedByObligationsException` after `$this->closureGate->checkAndEnforce()`: emit `AccountClosureBlockedEvent($command->targetUserId, $command->actorId, $command->correlationId, $e->blockedBy)`, write `profile.account.closure-blocked` audit with `actorType = 'ADMIN'` and `metadata = ['subject_user_id' => $command->targetUserId, 'blocked_by' => $e->blockedBy]`, increment `$this->metrics->closureBlocked()`, then re-throw; add a test in `ClosureObligationTest.php` asserting that `Profile.Account.ClosureBlocked` is written to outbox when admin close is blocked by stub in `services/api/app/Modules/Profile/Domain/Services/AccountStatusService.php` (F2; BR-020 "closure rejection MUST be auditable"; spec §12 audit table `profile.account.closure-blocked` applies to both paths; partial)

### F3 — closure_obligations column never populated in account_status_history (LOW, partial)

- [X] T197 When `requestClosure()` or `permanentlyClose()` catch a blocked `ClosureBlockedByObligationsException`, write a history row via `$this->history->appendTransition([..., 'from_status' => $current->value, 'to_status' => $current->value, 'closure_obligations' => $e->blockedBy, ...])` before re-throwing, so the spec §11.2 `closure_obligations` column is populated; add assertion to `ClosureObligationTest.php` that after a blocked closure the history table contains a row with `closure_obligations` equal to `['obligations_check_unavailable']` in `services/api/app/Modules/Profile/Domain/Services/AccountStatusService.php` (F3; spec §11.2 data model "`closure_obligations` | jsonb NULL | Populated when closure is blocked"; partial)

---

## Phase 28: Convergence

### F1 — Export JSON omits contact information required by FR-E02-040 (HIGH, partial)

- [X] T245 In `services/api/app/Modules/Profile/Infrastructure/Jobs/ProcessDataExportJob.php`, add `'email' => $profile->email` and `'mobile_number' => $profile->mobile_number` to the `$exportData` array (lines 57-72), so the export satisfies FR-E02-040 "contact information" requirement; update the "exported JSON contains personal profile fields" test in `services/api/app/Modules/Profile/Tests/Integration/DataExportJobTest.php` to assert `$exportData['email']` is non-null and `$exportData['mobile_number']` is non-null when the profile has contact info set; email and mobile are personal data, not credentials, so they are explicitly required and not excluded by the spec exclusion list (FR-E02-040, partial)

### F2 — "Profile change audit summary" scope in export unclear (MEDIUM, partial)

- [X] T246 Clarify the "profile change audit summary" requirement from FR-E02-040 with the product owner; if the decision is to include personal-info and contact-change audit history from `platform.audit_events`, add a filtered query in `ProcessDataExportJob::handle()` (e.g., `DB::connection('platform')->table('platform.audit_events')->where('resource_id', $this->userId)->whereIn('action', ['profile.user.personal-info-updated', 'profile.user.email-changed', 'profile.user.mobile-changed', 'profile.user.photo-updated'])->get()`) and include the result as `profile_change_summary` in the export; if the decision is that `account_status_history` already satisfies the requirement, document the decision in a comment in `ProcessDataExportJob.php` and add a cross-reference note in `specs/002-user-profile-management/spec.md` under the FR-E02-040 clarification; do not implement cross-module queries without product approval — note decision in spec as a clarification per constitution P2 (FR-E02-040, partial)

### F3 — DataExportDTO not created; plan specifies it (LOW, missing)

- [X] T247 Create `services/api/app/Modules/Profile/Application/DTOs/DataExportDTO.php` as a readonly class with properties `id: string`, `userId: string`, `status: string`, `requestedAt: ?\DateTimeImmutable`, `completedAt: ?\DateTimeImmutable`, `expiresAt: ?\DateTimeImmutable`, `downloadKey: ?string`; update `GetDataExportStatusQuery::execute()` to return `?DataExportDTO` instead of `?DataExportModel`, and update `DataExportController::status()` to consume `DataExportDTO`; this decouples the HTTP layer from the infrastructure model, consistent with the pattern established by `ProfileDTO` and `AccountStatusDTO` (plan: Application/DTOs/DataExportDTO.php, missing)

### F4 — DataExportResource not created; plan specifies it (LOW, missing)

- [X] T248 Create `services/api/app/Modules/Profile/Infrastructure/Http/Resources/DataExportResource.php` implementing the standard resource pattern (cf. `AccountStatusResource.php`, `ProfileResource.php`); it should format `DataExportDTO` (or `DataExportModel`) into the response shape defined in `contracts/profile-api.md §GET /v1/users/profile/data-export/{export_id}`; update `DataExportController::status()` to use `DataExportResource` for its 200 response body, ensuring consistent envelope formatting across all profile endpoints (plan: Infrastructure/Http/Resources/DataExportResource.php, missing)

---

## Phase 29: Convergence

**Purpose**: Close the gaps identified during `/speckit-converge` after Phase 14 (T119–T124, T180). One CRITICAL constitution violation (cross-module Eloquent model access in cleanup job), one HIGH partial (missing `fields_changed` label on `profile.update.success` metric), one MEDIUM missing (no tests for `ExpiredContactChallengeCleanupJob`). No existing task T001–T248 is modified or deleted.

**APPEND-ONLY**: T249–T251. Do not renumber, reorder, or remove any task in this section.

### F1 — `ExpiredContactChallengeCleanupJob` reads E01's `OtpChallenge` Eloquent model directly, violating Constitution P7 (CRITICAL, contradicts)

- [X] T249 Replace the direct `OtpChallenge::find()` calls in `services/api/app/Modules/Profile/Infrastructure/Jobs/ExpiredContactChallengeCleanupJob.php` with a call through an approved E01 contract: (1) add `isExpired(string $challengeId): bool` to `App\Modules\Identity\Domain\Contracts\OtpServiceContract` in `services/api/app/Modules/Identity/Domain/Contracts/OtpServiceContract.php`; (2) implement the method in the concrete `OtpService` (or its infrastructure adapter) — return `true` if the challenge row does not exist or `expires_at` is in the past; (3) inject `OtpServiceContract` into `ExpiredContactChallengeCleanupJob` instead of importing `OtpChallenge` directly; (4) remove the `use App\Modules\Identity\Infrastructure\Models\OtpChallenge;` import from the job; verify that no other Profile module file imports `OtpChallenge` directly (Constitution P7: "domains MUST have explicit interfaces; modular-monolith MUST remain extraction-ready"; contradicts)

### F2 — `profile.update.success` metric missing `fields_changed` label (HIGH, partial)

- [X] T250 In `services/api/app/Modules/Profile/Domain/Services/ProfileMetrics.php`, change `public function updateSuccess(string $actorType): void` to accept a second parameter `array $fieldsChanged` and pass it as the `fields_changed` label in the `increment()` call (e.g., `['actor_type' => strtolower($actorType), 'fields_changed' => implode(',', $fieldsChanged)]`); update the call site in `services/api/app/Modules/Profile/Domain/Services/ProfileService.php` (`updatePersonalInfo`) to derive `$fieldsChanged` from `array_keys($command->fields)` and pass it; add a test to `services/api/app/Modules/Profile/Tests/Unit/ProfileMetricsTest.php` asserting that `profile.update.success` emits both `actor_type` and `fields_changed` labels and that `fields_changed` contains the expected field names (plan.md line 521: `profile.update.success | Counter | actor_type, fields_changed`; partial)

### F3 — No test exists for `ExpiredContactChallengeCleanupJob` (MEDIUM, missing)

- [X] T251 Create `services/api/app/Modules/Profile/Tests/Integration/ExpiredContactChallengeCleanupJobTest.php` with three integration tests using `DatabaseTransactions`: (1) a profile with a non-expired `pending_email_challenge_id` — after running the job the `pending_email` and `pending_email_challenge_id` fields remain unchanged; (2) a profile with an expired or missing `pending_email_challenge_id` — after running the job `pending_email`, `pending_email_challenge_id`, `pending_mobile`, and `pending_mobile_challenge_id` are all `null`; (3) running the job twice on the same expired record produces identical results (idempotent); use real database state (seed `OtpChallenge` rows with controlled `expires_at`) and assert via `UserProfileModel::find($userId)` after `(new ExpiredContactChallengeCleanupJob())->handle()` (plan.md line 586: "ExpiredContactChallengeCleanupJob: stale pending fields cleared correctly"; missing)

---

## Phase 30: Convergence

**Purpose**: Close two gaps found during /speckit-converge after Phase 15 (T125�T136). One CRITICAL compile-time error (non-existent destructive parameter on AppButton), one MEDIUM partial (photo picker stubbed, image_picker absent). No existing task T001�T251 is modified or deleted.

**APPEND-ONLY**: T252�T253.

### F1 � AppButton(destructive: true) is a compile error; AppButton has no destructive property (CRITICAL, contradicts)

- [X] T252 In apps/customer/lib/modules/profile/screens/closure_request_screen.dart line 227, replace destructive: true with variant: ButtonVariant.danger; add import 'package:customer/modules/profile/...'; is already present � just ensure ButtonVariant is visible via the existing button.dart import; update closure_request_screen_test.dart to assert the ''Request account closure'' button renders with a danger/error-coloured style (per ButtonVariant.danger) so the test exercises the corrected variant (plan: design system AppButton; contradicts)

### F2 � _pickPhoto() in profile photo screen is a non-functional stub; image_picker absent from pubspec (MEDIUM, partial)

- [X] T253 Add image_picker: ^1.1.2 to the dependencies section of apps/customer/pubspec.yaml; replace the stub _pickPhoto() body in apps/customer/lib/modules/profile/screens/profile_photo_screen.dart with a real ImagePicker().pickImage(source: ImageSource.gallery) call, convert the returned XFile to dart:io File, and assign it to _selected; handle null (user cancelled) and PlatformException (permission denied) � show a SnackBar for the permission-denied case; update profile_photo_screen_test.dart to mock the ImagePicker and assert that after a successful pick _selected is set and the Upload button is enabled (FR-E02-010; US-USER-003; partial)

---

## Phase 31: Convergence

**Purpose**: Two LOW plan-deviation gaps found during convergence after Phase 30 (T252-T253). Both are plan structure items that were implemented via an alternative approach; each needs either the planned artifact or a documented deviation. Maximum task ID before this phase: T253.

**APPEND-ONLY**: T254-T255.

### F1 -- Domain/Entities/ classes absent from wallet-ecosystem/services/api/app/Modules/Profile/Domain/Entities/ (LOW, missing)

- [X] T254 Create three non-Eloquent domain entity PHP classes in `wallet-ecosystem/services/api/app/Modules/Profile/Domain/Entities/`: (1) `UserProfile.php` -- read-only value class holding user_id, first_name, last_name, date_of_birth, gender, address, preferred_language, email, mobile_number, profile_photo_url, account_status; (2) `AccountStatusHistory.php` -- value class holding id, from_status, to_status, reason, actor_id, actor_type, created_at; (3) `ContactChangeChallenge.php` -- value class holding challenge_id, type (email|mobile), pending_contact, expires_at; each class is final, readonly in PHP 8.4, has a named constructor `fromArray(array $data): static`, and has no Eloquent dependency; update `EloquentUserProfileRepository`, `EloquentAccountStatusHistoryRepository` to return these entity types from their primary read methods (plan: Domain/Entities/; missing)

### F2 -- CreateProfileCommand.php absent from wallet-ecosystem/services/api/app/Modules/Profile/Application/Commands/ (LOW, missing)

- [X] T255 Either (a) create `wallet-ecosystem/services/api/app/Modules/Profile/Application/Commands/CreateProfileCommand.php` as a readonly final class carrying `userId: string`, `actorType: string`, `email: string`, `mobileNumber: string`, and update `CreateProfileOnUserRegisteredListener::handle()` to construct and dispatch this command to `ProfileService::createProfile(CreateProfileCommand $cmd)` instead of building data inline; OR (b) add a code comment block to `CreateProfileOnUserRegisteredListener::handle()` stating "Deviation from plan: profile creation is performed inline in the listener; a separate CreateProfileCommand was omitted because the listener is the sole caller and no other entry point requires the command object -- see plan.md deviation note"; write a brief ADR addendum in `wallet-ecosystem/docs/adrs/ADR-E02-001-identity-module-contract-extension.md` recording the decision (plan: Application/Commands/CreateProfileCommand.php; missing)

---

## Phase 32: Convergence

**Purpose**: Close two gaps found during `/speckit-converge` after Phase 18 (T150–T158). One HIGH `contradicts` (T156 calls non-existent storage method at runtime) and one MEDIUM `contradicts` (T157 uses incorrect Flutter semantics API). Maximum task ID before this phase: T255.

**APPEND-ONLY**: T256–T257. Do not renumber, reorder, or remove any task in this section.

---

### F1 — `DataExportSecurityTest.php` calls `getExportContent()` which does not exist on `ProfilePhotoStorageContract` (HIGH, contradicts)

- [X] T256 [P] In `wallet-ecosystem/services/api/app/Modules/Profile/Tests/Security/DataExportSecurityTest.php`, rewrite the `runExportJob()` helper function to capture the export content via a Mockery partial mock of `ProfilePhotoStorageContract`: (1) create `$capturingStorage = Mockery::mock(ProfilePhotoStorageContract::class)->makePartial()`; (2) add a `$capturedContent = null` variable; (3) use `$capturingStorage->shouldReceive('storeExport')->once()->andReturnUsing(function (string $userId, string $content) use (&$capturedContent, $capturingStorage): string { $capturedContent = $content; return 'exports/' . $userId . '/test.json'; })`; (4) bind it via `app()->instance(ProfilePhotoStorageContract::class, $capturingStorage)`; (5) run the job; (6) return `json_decode($capturedContent, true, 512, JSON_THROW_ON_ERROR)` — removing the now-deleted `$storage->getExportContent()` call which does not exist on `ProfilePhotoStorageContract`; the five assertion tests (`profile_photo_key absent`, `credential hashes absent`, `OTP values absent`, `session tokens absent`, `IP addresses absent`, `required personal fields present`) require no other changes — only the helper function changes; also restore the `beforeEach` middleware alias for `throttle` which was already present in the file (T156; `ProfilePhotoStorageContract` interface has `store`, `delete`, `generateSignedUrl`, `storeExport` only — no `getExportContent`; contradicts)

### F2 — `accessibility_test.dart` uses `tester.semantics.hasSemantics()` and `nodeWithKey()` which are not methods on `SemanticsTester` (MEDIUM, contradicts)

- [X] T257 [P] In `apps/customer/test/features/profile/accessibility_test.dart`, fix two incorrect Flutter semantics API calls: (1) in the `ProfileViewScreen` "loaded state has a header semantics node" test, replace the incorrect `tester.semantics.hasSemantics(find.byType(Scaffold), textContaining: 'Profile', isHeader: true)` call with the established pattern from `profile_view_screen_test.dart:62-65` — use `final headerNodes = tester.semantics.nodesWith(isHeader: true).toList()` and `expect(headerNodes, isNotEmpty, reason: '...')` — this is the only valid `SemanticsTester` API for querying header nodes; (2) in the `ProfileEditScreen` "all form fields have semantic labels" test, remove the `tester.semantics.nodeWithKey(field.widget.key)` call (no such method exists on `SemanticsTester`) and replace the inner assertion loop with `final labeledNodes = tester.semantics.nodesWith(hasLabel: true); expect(labeledNodes.isNotEmpty, isTrue, reason: 'Form must have at least one semantically labeled element');` — a simpler form that uses the real `SemanticsTester.nodesWith(hasLabel: true)` API; no other tests in the file require changes (T157; `SemanticsTester` API: `nodesWith(...)`, `find`, no `hasSemantics()` or `nodeWithKey()` methods; contradicts)

---

## Phase 33: Convergence

**Purpose**: Close two gaps found during `/speckit-converge` after Phases 1–32 (T001–T257). One HIGH `contradicts` (rate-limited requests return 500 instead of 429 because `Handler.php` has no `ThrottleRequestsException` handler) and one LOW `partial` (agent mobile verify screen has no widget test despite the screen existing). The Admin Dashboard gap (spec §2 Scope) is already tracked as Phase 22 tasks T177–T181 (all `[ ]`) and is not duplicated here. Maximum task ID before this phase: T257.

**APPEND-ONLY**: T258–T259. Do not renumber, reorder, or remove any task in this section.

---

### F1 — `Handler.php` has no `ThrottleRequestsException` handler; rate-limited requests return 500 instead of 429 (HIGH, contradicts)

- [X] T258 In `wallet-ecosystem/services/api/app/Exceptions/Handler.php`, add a `match`/`instanceof` case inside `render()` for `Illuminate\Http\Exceptions\ThrottleRequestsException` (which extends `Symfony\Component\HttpFoundation\Exception\TooManyRequestsHttpException`): return a `JsonResponse` with HTTP status 429 using the Platform Foundation error envelope `{ "success": false, "data": null, "error": { "code": "RATE_LIMIT_EXCEEDED", "message": "Too many requests — please slow down", "details": {} }, "meta": { "correlation_id": ... } }`; copy the `Retry-After` header from the exception via `$e->getHeaders()['Retry-After'] ?? null` and set it on the response; add a Pest feature test in `services/api/app/Modules/Profile/Tests/Feature/RateLimitEnforcementTest.php` (or a new `ThrottleHandlerTest.php`) asserting that the registered rate limiter correctly produces a response with `status = 429`, `json('error.code') = 'RATE_LIMIT_EXCEEDED'`, and a `Retry-After` header; this unblocks the T178 rate-limit enforcement tests which currently receive 500 from the default exception handler (F1; spec §9 Security Requirements "Rate limiting — 429 RATE_LIMIT_EXCEEDED"; AC-024; T178; contradicts)

### F3 — Agent mobile verify screen exists but has no widget test (LOW, partial)

- [X] T259 [P] Create `apps/agent/test/features/profile/screens/agent_mobile_verify_screen_test.dart`: write widget tests for `AgentMobileVerifyScreen` covering (1) loading state shows progress indicator; (2) OTP input accepts 6-digit entry and the `Verify` button is enabled; (3) invalid OTP server error shows inline error message with retry option; (4) expired OTP challenge shows expiry message with re-initiate CTA; (5) success state navigates or shows confirmation; (6) all interactive elements have `Semantics` labels (screen-reader accessible); follow the established pattern in `agent_mobile_change_screen_test.dart` for service mocking and `AgentProfileStateNotifier` stubbing; tests must pass `flutter test` with the same pub dependencies already in `apps/agent/pubspec.yaml` (F3; T143; spec §15.2 US-USER-006; partial)

## Phase 34: Convergence

### F1 — Controller error responses deviate from the platform API contract (HIGH, partial)

- [X] T260 Align all 7 Profile module controllers' private `errorResponse()` helper to the platform envelope contract defined by `Handler::errorEnvelope()`: replace `{ success: false, error: { code, message, trace_id } }` with `{ success: false, data: null, error: { code, message, details: [] }, correlation_id: <value>, meta: null }` — `correlation_id` MUST be a top-level key (not nested inside `error`), `data` MUST be `null`, `meta` MUST be `null`, and the non-standard `trace_id` key MUST be removed; apply the fix to `ProfileController`, `ProfilePhotoController`, `DataExportController`, `ClosureController`, `ContactChangeController`, `AccountStatusController`, and `AdminProfileController` in `wallet-ecosystem/services/api/app/Modules/Profile/Infrastructure/Http/Controllers/`; also fix the custom `CLOSURE_BLOCKED_BY_OBLIGATIONS` response body in `ClosureController::request()` to move `blocked_by` into `error.details` as a structured detail entry; add a Pest feature test (or extend an existing feature test) that asserts a controller-caught error response contains `correlation_id` at the top level, `data: null`, `meta: null`, and an `error.details` array — confirming the body matches the platform envelope schema (F1; plan §9 Error Handling; P8 API Contracts; partial)

## Phase 35: Convergence

### F1 — AdminDashboardController success response places correlation_id inside meta instead of top level (MEDIUM, partial)

- [X] T261 Fix `wallet-ecosystem/services/api/app/Modules/Profile/Infrastructure/Http/Controllers/AdminDashboardController.php` `summary()` method: change `'meta' => ['correlation_id' => $correlationId]` to place `correlation_id` at the top level of the response array and set `meta` to `null` so the success envelope matches the platform contract — `{ success: true, data: {...}, error: null, correlation_id: "...", meta: null }`; extend or add a Pest feature test for `GET /v1/admin/profile/dashboard/summary` that asserts `correlation_id` is a top-level string field, `meta` is `null`, and `error` is `null` in the success response (F1; plan §9 Error Handling; P8 API Contracts; partial)
