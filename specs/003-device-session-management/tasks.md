# Tasks — E03 Device & Session Management

**Feature**: `003-device-session-management` | **Epic**: E03 | **Stories**: 9
**Spec**: [spec.md](spec.md) | **Plan**: [plan.md](plan.md) | **Data Model**: [data-model.md](data-model.md)

**Format**: `- [ ] [TaskID] [P?] [Story?] Description — file path`
- `[P]` = parallelizable (no file conflicts, no incomplete dependencies)
- `[US#]` = maps to user story (US1=US-DEVICE-001, US2=US-DEVICE-002, etc.)
- All Laravel paths relative to `wallet-ecosystem/services/api/`
- All Flutter paths relative to `wallet-ecosystem/apps/`

---

## Phase 1: Setup — Pre-conditions and E01 Normalization

**Purpose**: Fix E01 inconsistencies and extend E01 session infrastructure before E03 can be implemented. These tasks MUST be complete before Phase 2.

**⚠️ BLOCKING**: These fix pre-existing E01 bugs that E03 depends on. Do not begin Phase 2 until all Phase 1 tasks are complete.

- [X] T001 Fix E01 `DeviceService::resolveDevice()` to use status `'pending_verification'` instead of `'unverified'` — `app/Modules/Identity/Domain/Services/DeviceService.php:` change `status = 'unverified'` to `status = 'pending_verification'` in the `create()` call inside `resolveDevice()`
- [X] T002 Fix E01 `DeviceController::register()` to use status `'pending_verification'` instead of `'pending'` — `app/Modules/Identity/Infrastructure/Http/Controllers/DeviceController.php:` change the hardcoded `'pending'` status string to `'pending_verification'`
- [X] T003 Add `listActiveForDevice(string $deviceId): array` and `revokeAllForDevice(string $deviceId): int` to `app/Modules/Identity/Domain/Contracts/SessionRepositoryInterface.php`
- [X] T004 Implement `listActiveForDevice()` and `revokeAllForDevice()` in `app/Modules/Identity/Infrastructure/Repositories/EloquentSessionRepository.php` — query `identity.sessions WHERE device_id = $deviceId AND status = 'active'`; update status to `'revoked'` and return count
- [X] T005 Add `revokeAllForDevice(string $deviceId, string $actorType, string $correlationId, string $ipHash): int` method to `app/Modules/Identity/Domain/Services/SessionService.php` — follows `revokeAllForUser()` pattern: DB transaction sets sessions revoked, publishes event per session, writes audit, then Redis eviction for each session_id after tx

**Checkpoint**: E01 status strings consistent; `SessionService::revokeAllForDevice()` available for E03 to call.

---

## Phase 2: Foundational — Database, Domain Contracts, and Shared Infrastructure

**Purpose**: Database schema, domain interfaces, Eloquent models, repositories, and E01 middleware extension. ALL must complete before user story phases.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

### Database Migrations

- [X] T006 Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_02_001000_add_blocked_columns_to_identity_devices.php` — `ALTER TABLE identity.devices ADD COLUMN blocked_at TIMESTAMPTZ NULL, ADD COLUMN blocked_reason VARCHAR(255) NULL` (nullable, no DEFAULT, zero-downtime)
- [X] T007 Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_02_002000_create_identity_device_trust_history_table.php` — creates `identity.device_trust_history` with columns: `id UUID PK`, `device_id UUID NOT NULL`, `user_id UUID NOT NULL`, `from_status VARCHAR(30) NULL`, `to_status VARCHAR(30) NOT NULL`, `actor_id UUID NOT NULL`, `actor_type VARCHAR(20) NOT NULL`, `reason TEXT NULL`, `correlation_id UUID NOT NULL`, `created_at TIMESTAMPTZ NOT NULL DEFAULT now()` — no `updated_at` (append-only); indexes on `(device_id, created_at DESC)` and `(user_id, created_at DESC)`
- [X] T008 Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_02_003000_create_identity_device_security_events_table.php` — creates `identity.device_security_events` with all columns from data-model.md; indexes on `(user_id, event_type, created_at DESC)` and `(device_id, event_type) WHERE device_id IS NOT NULL`
- [X] T009 Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_02_004000_seed_device_configuration_defaults.php` — INSERT with `ON CONFLICT (key) DO NOTHING` for all 9 `device.*` configuration keys from data-model.md section "Configuration Seed"

### Domain Contracts (Interfaces)

- [X] T010 [P] Create `app/Modules/Identity/Domain/Contracts/DeviceBlockCheckServiceInterface.php` — single method `isBlocked(string $deviceId): bool`
- [X] T011 [P] Create `app/Modules/Identity/Domain/Contracts/DeviceTrustHistoryRepositoryInterface.php` — methods: `append(array $attributes): DeviceTrustHistory`, `listForDevice(string $deviceId): array`
- [X] T012 [P] Create `app/Modules/Identity/Domain/Contracts/DeviceSecurityEventRepositoryInterface.php` — methods: `create(array $attributes): DeviceSecurityEvent`, `findPendingForDevice(string $deviceId): ?DeviceSecurityEvent`, `markResolved(string $id, string $resolution, string $resolvedAt): void`
- [X] T013 [P] Add E03 methods to `app/Modules/Identity/Domain/Contracts/DeviceRepositoryInterface.php` — `findByFingerprint(string $hash): array`, `countForUser(string $userId): int`, `listForUserPaginated(string $userId, int $page, int $perPage, ?string $status, ?string $platform): array`, `blockDevice(string $id, string $blockedAt, ?string $blockedReason): void`, `unblockDevice(string $id): void`

### Domain Exceptions

- [X] T014 [P] Create exception classes in `app/Modules/Identity/Domain/Exceptions/`: `DeviceBlockedException.php`, `DeviceLimitExceededException.php`, `DeviceAlreadyBlockedException.php`, `DeviceAlreadyAcknowledgedException.php`, `AcknowledgmentWindowExpiredException.php`, `InsufficientFingerprintEntropyException.php`, `InvalidStatusTransitionException.php` — each `final class Xxx extends \DomainException` with a static `make()` factory following the existing E01 exception pattern (e.g. `InvalidCredentialsException`); `InvalidStatusTransitionException::make(string $from, string $to)` accepts current and attempted status strings

### Eloquent Models

- [X] T015 [P] Create `app/Modules/Identity/Infrastructure/Models/DeviceTrustHistory.php` — `protected $connection = 'identity'`, `protected $table = 'identity.device_trust_history'`, `public $timestamps = false`, fillable and casts per data-model.md
- [X] T016 [P] Create `app/Modules/Identity/Infrastructure/Models/DeviceSecurityEvent.php` — `protected $connection = 'identity'`, `protected $table = 'identity.device_security_events'`, fillable and casts per data-model.md; cast `risk_signals` as `'array'`
- [X] T017 Update `app/Modules/Identity/Infrastructure/Models/Device.php` — add `'blocked_at'`, `'blocked_reason'` to `$fillable`; add `'blocked_at' => 'immutable_datetime'` to `$casts`

### Repositories

- [X] T018 [P] Create `app/Modules/Identity/Infrastructure/Repositories/EloquentDeviceTrustHistoryRepository.php` — implements `DeviceTrustHistoryRepositoryInterface`; `append()` does `DB::connection('identity')->table('identity.device_trust_history')->insert([..., 'id' => Uuid::uuid7(), 'created_at' => now()])` (must be called inside an active transaction); `listForDevice()` queries by `device_id ORDER BY created_at ASC`
- [X] T019 [P] Create `app/Modules/Identity/Infrastructure/Repositories/EloquentDeviceSecurityEventRepository.php` — implements `DeviceSecurityEventRepositoryInterface`; `create()` uses `identity.device_security_events` table; `findPendingForDevice()` queries `WHERE device_id = $id AND event_type = 'new_device_notified' AND resolution IS NULL AND acknowledgment_expires_at > now()` ORDER BY `created_at DESC LIMIT 1`; `markResolved()` updates `resolution`, `resolved_at`, `updated_at`
- [X] T020 Implement E03 methods in `app/Modules/Identity/Infrastructure/Repositories/EloquentDeviceRepository.php` — add `findByFingerprint()`, `countForUser()`, `listForUserPaginated()`, `blockDevice()`, `unblockDevice()` following existing method patterns (DB::connection('identity'), model hydration)

### Domain Services (Shared)

- [X] T021 [P] Create `app/Modules/Identity/Domain/Services/DeviceBlockCheckService.php` — implements `DeviceBlockCheckServiceInterface`; constructor injects `DeviceRepositoryInterface`; `isBlocked()` calls `$this->devices->findById($deviceId)` and returns `$device !== null && $device->status === 'blocked'`
- [X] T022 [P] Create `app/Modules/Identity/Domain/Services/DeviceFingerprintService.php` — methods: `computeHash(array $attributes, string $platform): string` (normalize → sort keys → canonical JSON → SHA-256 hex); `validateEntropy(array $attributes, string $platform, int $minBits): void` (throws `InsufficientFingerprintEntropyException`); `detectEmulatorIndicators(array $attributes, string $platform): bool` (heuristics from research.md TBD-E03-001)
- [X] T023 [P] Create `app/Modules/Identity/Domain/Services/DeviceTrustService.php` — methods: `trustDevice(...)`, `removeDevice(...)`, `blockDevice(...)`, `unblockDevice(...)` — each method uses `DB::connection('platform')->transaction(fn() => ...)` wrapping: repository updates, `DeviceTrustHistoryRepositoryInterface::append()`, `SessionService::revokeAllForDevice()` (for remove/block), `OutboxEventPublisher::publish()`, `AuditService::record()`; Redis eviction after tx commit
- [X] T024 [P] Create `app/Modules/Identity/Domain/Services/DeviceSecurityService.php` — methods: `detectChange(Device $device, string $submittedHash): bool` (compares fingerprints; if differs: demotes device, appends history, creates security event, publishes event, writes audit); `detectSuspicious(Device $device, string $userId, bool $isEmulator = false): void` (runs 3 rules from research.md TBD-E03-003 — Rule 2 uses `$isEmulator` flag passed from the job since raw attributes are not re-available; records `suspicious_detected` security event; emits event; writes audit); `processAcknowledgment(Device $device, bool $acknowledged, ...): int` (returns sessions_revoked; if false: calls `DeviceTrustService::blockDevice()`, records denial event; if true: marks acknowledged)
- [X] T025 Extend `app/Modules/Identity/Domain/Services/IdentityMetrics.php` with E03 counters: `incrementDeviceRegistered(string $platform)`, `incrementDeviceRemoved(string $actorType)`, `incrementDeviceBlocked(string $actorType)`, `incrementDeviceChangeDetected()`, `incrementDeviceSuspiciousDetected(string $rule)`, `incrementNewDeviceNotificationSent()`, `incrementNewDeviceNotificationFailed()` (required by spec §9 Observability — metric name `device.new_device_notification.failed`; called in the failure catch block of `HandleNewDeviceDetectedListener`, see T059), `incrementNewDeviceNotificationAcknowledged(bool $acknowledged)`

### E01 Middleware Integration (FR-E03-045)

- [X] T026 Update `app/Modules/Identity/Infrastructure/Http/Middleware/IdentityAuthMiddleware.php` — inject `DeviceBlockCheckServiceInterface` via constructor; after session validation and before `$next($request)`: if `$deviceId = $request->attributes->get('identity.device_id')` and `$this->deviceBlockCheck->isBlocked($deviceId)` → return `response()->json(['success' => false, 'error' => ['code' => 'DEVICE_BLOCKED', 'message' => 'This device has been blocked.']], 403)`

### Service Provider and Listener Registration

- [X] T027 Update `app/Modules/Identity/Providers/IdentityServiceProvider.php` — register service bindings only: `DeviceBlockCheckServiceInterface::class → DeviceBlockCheckService::class`, `DeviceTrustHistoryRepositoryInterface::class → EloquentDeviceTrustHistoryRepository::class`, `DeviceSecurityEventRepositoryInterface::class → EloquentDeviceSecurityEventRepository::class`; **do NOT register event listeners here** — listener class files do not exist yet; listener registration is done in T131 (Phase 7) after listener files are created

**Checkpoint**: Database migrated, all contracts defined, all repositories implemented, E01 middleware checks blocked devices. Smoke test: call `POST /v1/auth/login` with a blocked device → expect `403 DEVICE_BLOCKED`.

---

## Phase 3: US-DEVICE-002 — Generate Device Fingerprint (P0)

**Goal**: Mobile clients can compute a validated SHA-256 fingerprint from device attributes. Raw attributes are never stored.

**Independent Test**: `POST /v1/devices/fingerprint` with valid Android attributes → 200 with 64-char hex hash. Same attributes → same hash. Missing required attribute → 400.

- [X] T028 [P] [US2] Create command `app/Modules/Identity/Application/Commands/GenerateDeviceFingerprintCommand.php` — `final readonly class` with fields: `userId: string`, `platform: string`, `attributes: array`, `actorType: string`, `correlationId: string`, `ipHash: string`
- [X] T029 [US2] Create handler `app/Modules/Identity/Application/Handlers/GenerateDeviceFingerprintHandler.php` — `handle(GenerateDeviceFingerprintCommand): array` — validates platform; calls `DeviceFingerprintService::computeHash()`; calls `DeviceFingerprintService::validateEntropy()`; calls `DeviceFingerprintService::detectEmulatorIndicators()` and stores the boolean result in a local variable for metric/log purposes only (NO job dispatch here — `SuspiciousDeviceDetectionJob` does not exist until Phase 5/T047; any emulator-aware suspicious detection is dispatched from registration and change-detection handlers in Phases 4–5 where the job is available); writes audit record `identity.device.fingerprint-generated`; returns `['fingerprint_hash' => $hash]`
- [X] T030 [P] [US2] Create request validation `app/Modules/Identity/Infrastructure/Http/Requests/GenerateFingerprintRequest.php` — validates: `platform` in `[ios, android, web]` required; `attributes` required array; per-platform required keys from research.md TBD-E03-001 (ios: `os_version`, `hardware_model`, `idfv`; android: `os_version`, `hardware_model`, `android_id`; web: `user_agent`, `timezone`)
- [X] T031 [US2] Create `app/Modules/Identity/Infrastructure/Http/Controllers/DeviceManagementController.php` with `fingerprint(GenerateFingerprintRequest $request): JsonResponse` method — extract actor from `$request->attributes`; dispatch `GenerateDeviceFingerprintCommand`; return `ApiResponse::success(['fingerprint_hash' => $result['fingerprint_hash']])`
- [X] T032 [US2] Add route `Route::post('devices/fingerprint', [DeviceManagementController::class, 'fingerprint'])` inside the `identity.auth` middleware group in `routes/modules/identity.php`
- [X] T033 [US2] Write unit tests `app/Modules/Identity/Tests/Unit/DeviceFingerprintServiceTest.php` — test: hash determinism (same input → same output); platform normalization; entropy validation (below minimum throws); emulator detection (android_id zero → true); SHA-256 output is 64-char hex
- [X] T034 [US2] Write API test `app/Modules/Identity/Tests/Api/GenerateDeviceFingerprintTest.php` — test: happy path android returns 64-char hash; happy path ios; happy path web; missing required attribute returns 400 INVALID_ATTRIBUTE_FORMAT; entropy too low returns 400 INSUFFICIENT_ENTROPY; unauthenticated returns 401; audit record created for each successful request; raw attributes NOT in response

**Checkpoint**: `POST /v1/devices/fingerprint` returns deterministic hash; raw attributes not stored; audit record written.

---

## Phase 4: US-DEVICE-001 — Register Device (P0)

**Goal**: Authenticated users can explicitly register a device. Registration is idempotent. Device limit is enforced. OTP challenge initiated.

**Independent Test**: `POST /v1/devices` with valid fingerprint → 201 with `device_id` and `challenge_id`; same request with same `Idempotency-Key` → 200 same `device_id`; at limit → 409.

- [X] T035 [P] [US1] Create `app/Modules/Identity/Domain/Events/DeviceRegisteredEvent.php` — implements `IntegrationEventInterface`; `eventName()` returns `'Device.Device.Registered'`; `eventVersion()` returns `'1.0.0'`; `aggregateType()` returns `'identity.device'`; `aggregateId()` returns `device_id`; `payload()` returns device fields per events.md contract
- [X] T036 [P] [US1] Create command `app/Modules/Identity/Application/Commands/RegisterExplicitDeviceCommand.php` — fields: `userId: string`, `deviceName: string`, `platform: string`, `fingerprintHash: string`, `actorType: string`, `correlationId: string`, `ipHash: string`, `idempotencyKey: string`
- [X] T037 [US1] Create handler `app/Modules/Identity/Application/Handlers/RegisterExplicitDeviceHandler.php` — (1) check idempotency cache; (2) check existing device by `(userId, fingerprintHash)` → if exists return it; (3) count user's devices vs `device.max_devices_per_user` → throw `DeviceLimitExceededException` if at limit; (4) `DB::connection('platform')->transaction()`: create device record via `DeviceRepositoryInterface::create()` with `status = 'pending_verification'`, append trust history entry, publish `DeviceRegisteredEvent`, write audit `identity.device.registered`; (5) initiate OTP challenge via `OtpDeliveryContract`; (6) store idempotency response; (7) call `IdentityMetrics::incrementDeviceRegistered($platform)`
- [X] T038 [P] [US1] Create request validation `app/Modules/Identity/Infrastructure/Http/Requests/RegisterDeviceRequest.php` — validates: `device_name` required string max:100; `platform` required in `[ios, android, web]`; `fingerprint_hash` required string size:64 regex:/^[0-9a-f]{64}$/
- [X] T039 [US1] Add `register(RegisterDeviceRequest $request): JsonResponse` method to `app/Modules/Identity/Infrastructure/Http/Controllers/DeviceManagementController.php` — check `Idempotency-Key` header required; dispatch `RegisterExplicitDeviceCommand`; return `201` for new device with `{device_id, status, challenge_id}` or `200` for idempotent replay
- [X] T040 [US1] Add route `Route::post('devices', [DeviceManagementController::class, 'register'])` in `routes/modules/identity.php` inside auth middleware group
- [X] T041 [US1] Write unit tests `app/Modules/Identity/Tests/Unit/RegisterExplicitDeviceHandlerTest.php` — test: new device created; idempotent (same fingerprint + user → existing device returned); device limit reached throws `DeviceLimitExceededException`; OTP challenge dispatched on new registration; `DeviceRegisteredEvent` published inside transaction
- [X] T042 [US1] Write API test `app/Modules/Identity/Tests/Api/RegisterDeviceTest.php` — test: 201 for new device; 200 for idempotent replay (same `Idempotency-Key`); 200 for same user+fingerprint different key (returns existing device); 409 DEVICE_LIMIT_EXCEEDED at configured maximum; 422 for invalid fingerprint_hash format; 403 for unauthenticated; `platform.outbox_events` has `Device.Device.Registered`; `platform.audit_events` has `identity.device.registered`

**Checkpoint**: `POST /v1/devices` creates device with `pending_verification` status, initiates OTP challenge, enforces device limit.

---

## Phase 5: US-DEVICE-006 — Detect Device Change (P0, Internal)

**Goal**: When a known device presents a changed fingerprint, the device is demoted to `pending_verification` and the user must re-verify.

**Independent Test**: With change detection enabled, login with `device_id` + new fingerprint → device status changes to `pending_verification` in `identity.devices`; `change_detected` entry in `identity.device_security_events`; `Device.Device.ChangeDetected` event in outbox.

**⚠️ INTRA-PHASE ORDERING**: `T047` (`SuspiciousDeviceDetectionJob`) MUST be implemented before `T045` (`DetectDeviceChangeHandler`). The handler dispatches the job; the job class must exist before the handler is tested. Implement T043, T044, T047 first, then T045, then T046.

- [X] T043 [P] [US6] Create `app/Modules/Identity/Domain/Events/DeviceChangeDetectedEvent.php` — `eventName()` = `'Device.Device.ChangeDetected'`; payload per events.md including `previous_fingerprint_hash`, `new_fingerprint_hash`, `previous_status`, `new_status`
- [X] T044 [P] [US6] Create command `app/Modules/Identity/Application/Commands/DetectDeviceChangeCommand.php` — fields: `deviceId: string`, `userId: string`, `submittedFingerprintHash: string`, `previousFingerprintHash: string`, `actorType: string`, `correlationId: string`, `ipHash: string`
- [X] T045 [US6] Create handler `app/Modules/Identity/Application/Handlers/DetectDeviceChangeHandler.php` — calls `DeviceSecurityService::detectChange()`; if change detected: demotes device status to `pending_verification` via `DeviceRepositoryInterface::updateStatus()`, appends `device_trust_history` entry (`to_status = pending_verification`, reason: 'fingerprint_change_detected'), creates `device_security_events` record (`event_type = change_detected`, stores both hashes), publishes `DeviceChangeDetectedEvent`, writes audit `identity.device.change-detected` at WARN severity; dispatches `SuspiciousDeviceDetectionJob::dispatch($command->deviceId, $command->userId, $command->submittedFingerprintHash, isEmulator: false)` (raw attributes not available at this stage; emulator flag defaults to false; **requires T047 to exist first — see intra-phase ordering note above**)
- [X] T046 [US6] Integrate change detection into `app/Modules/Identity/Domain/Services/DeviceService.php` — in `resolveDevice()`: when a device_id is provided and the submitted fingerprint differs from `device->device_fingerprint`, and `ConfigurationService::get('device.change_detection_enabled') === 'true'`, call `DetectDeviceChangeHandler::handle()`; if change detected, demote device and proceed to OTP verification flow (do NOT issue session immediately); NOTE: spec §6.6 refers to this interception as a "change detection middleware hook" — in this implementation, the hook is this in-service check inside `DeviceService::resolveDevice()`, not a separate Laravel middleware class; NOTE: the post-verification fingerprint update (FR-E03-046 — preventing re-detection loops) is implemented in T156; T046 must pass the submitted fingerprint hash to `initiateVerification()` via the mechanism described in T156(d)
- [X] T047 [P] [US6] Create `app/Modules/Identity/Infrastructure/Jobs/SuspiciousDeviceDetectionJob.php` — Laravel Horizon job; constructor/dispatch signature: `SuspiciousDeviceDetectionJob::dispatch(string $deviceId, string $userId, string $fingerprintHash, bool $isEmulator = false)` — this is the **canonical signature** for all dispatch callers; `handle()` loads device from `DeviceRepositoryInterface::findById($deviceId)`, then calls `DeviceSecurityService::detectSuspicious($device, $userId, $isEmulator)` to run the 3 suspicious detection rules (Rule 2 uses `$isEmulator` flag directly since raw attributes are not available at dispatch time); catch all exceptions and log (non-blocking); **MUST be implemented before T045**
- [X] T048 [US6] Write tests `app/Modules/Identity/Tests/Unit/DeviceChangeDetectionTest.php` — test: detectChange returns true when hashes differ; device demoted to `pending_verification`; `device_security_events` record created; `DeviceChangeDetectedEvent` published; detectChange returns false when hashes match; change detection disabled when `device.change_detection_enabled = false` (device unchanged, no events)
- [X] T049 [US6] Write API/integration test `app/Modules/Identity/Tests/Api/DeviceChangeDetectionTest.php` — test: login with `device_id` and changed fingerprint → device status = `pending_verification` in DB; OTP challenge required before session issued; change detection disabled via config → device unchanged, session issued normally

**Checkpoint**: Fingerprint drift triggers demotion and re-verification. Detection is togglable via config.

---

## Phase 6: US-DEVICE-007 — Detect Suspicious Device (P0, Internal)

**Goal**: Suspicious device patterns (multi-account fingerprint, emulator, abnormal change rate) are detected and emitted as risk signals to E18. No detection detail in API responses.

**Independent Test**: Register same fingerprint for more than `device.max_accounts_per_fingerprint` users → `Device.Device.SuspiciousDetected` event in outbox with `risk_signals`; API response for registering user contains no detection detail.

- [X] T050 [P] [US7] Create `app/Modules/Identity/Domain/Events/DeviceSuspiciousDetectedEvent.php` — `eventName()` = `'Device.Device.SuspiciousDetected'`; payload per events.md including `risk_signals` object
- [X] T051 [US7] Implement rule engine in `app/Modules/Identity/Domain/Services/DeviceSecurityService.php` `detectSuspicious()` method — Rule 1 (multi-account): `DeviceRepositoryInterface::findByFingerprint($hash)` → count distinct user_ids; if count > `device.max_accounts_per_fingerprint` → fire; Rule 2 (emulator): check `DeviceFingerprintService::detectEmulatorIndicators()` result from job arguments; Rule 3 (change rate): query `identity.device_security_events WHERE user_id = $uid AND event_type = 'change_detected' AND created_at > NOW() - INTERVAL config_hours`; if count > `device.max_device_changes_per_window` → fire; if any rule fires: create `suspicious_detected` security event with `risk_signals`, publish `DeviceSuspiciousDetectedEvent`, write audit WARN
- [X] T052 [US7] Extend `SuspiciousDeviceDetectionJob::handle()` to pass `$this->isEmulator` into `DeviceSecurityService::detectSuspicious()` for Rule 2 evaluation (job constructor already accepts `isEmulator` per T047); verify the 3-rule engine in `DeviceSecurityService::detectSuspicious()` (implemented in T051) receives all required arguments and the job handles exceptions without rethrowing
- [X] T053 [US7] Add `SuspiciousDeviceDetectionJob` dispatch to `RegisterExplicitDeviceHandler` (after transaction commit, before idempotency response stored): `SuspiciousDeviceDetectionJob::dispatch($deviceId, $userId, $command->fingerprintHash, isEmulator: false)` — raw attributes not available at registration time; confirm T045 (`DetectDeviceChangeHandler`) also dispatches with the same canonical signature; both callers use `isEmulator: false` since attribute-level emulator detection is not available outside of Phase 3 fingerprint generation
- [X] T054 [P] [US7] Write unit tests `app/Modules/Identity/Tests/Unit/SuspiciousDeviceDetectionTest.php` — test: multi-account rule fires when fingerprint shared by > configured max accounts; emulator rule fires on android_id zero; change-rate rule fires when change count exceeds window threshold; no detection when disabled via `device.suspicious_detection_enabled = false`; `risk_signals` contains correct rule identifiers; API response for triggering request contains no risk_signals field
- [X] T055 [US7] Write integration test `app/Modules/Identity/Tests/Api/SuspiciousDetectionIntegrationTest.php` — test: register device for 4 users with same fingerprint; `SuspiciousDeviceDetectionJob` fires; `Device.Device.SuspiciousDetected` in outbox with `risk_signals.rules_fired = [multi_account_fingerprint]`; registration response for user 4 contains no detection detail

**Checkpoint**: Suspicious patterns detected and emitted as risk signals. Detection is side-channel — API responses unaffected.

---

## Phase 7: US-DEVICE-008 — New Device Notification (P0, Event-Driven)

**Goal**: When E01 detects a new device login, E03 triggers a notification via E17 and provides an acknowledgment endpoint. Denial auto-blocks the device.

**Independent Test**: Insert `Identity.Device.NewDeviceDetected` event into outbox; Horizon processes it; `device_security_events` record created; `Device.Device.NewDeviceNotificationSent` in outbox. Then `POST /v1/devices/{id}/acknowledge` with `acknowledged: false` → device blocked, sessions revoked.

- [X] T056 [P] [US8] Create `app/Modules/Identity/Domain/Events/DeviceNewDeviceNotificationSentEvent.php` — `eventName()` = `'Device.Device.NewDeviceNotificationSent'`; payload per events.md
- [X] T057 [P] [US8] Create `app/Modules/Identity/Domain/Events/DeviceNewDeviceAcknowledgedEvent.php` — `eventName()` = `'Device.Device.NewDeviceAcknowledged'`
- [X] T058 [P] [US8] Create `app/Modules/Identity/Domain/Events/DeviceNewDeviceDeniedEvent.php` — `eventName()` = `'Device.Device.NewDeviceDenied'`; payload includes `sessions_revoked`
- [X] T059 [US8] Create `app/Modules/Identity/Infrastructure/Listeners/HandleNewDeviceDetectedListener.php` — listens to `NewDeviceDetectedEvent` (E01 domain event); `handle()`: idempotency check (skip if `device_security_events` record already exists for device + `new_device_notified`); compute `acknowledgment_expires_at = now() + device.new_device_acknowledgment_ttl`; `DB::connection('platform')->transaction()`: create `device_security_events` record (`event_type = new_device_notified`), publish `DeviceNewDeviceNotificationSentEvent`; write audit `identity.device.new-device-notified`; on success call `IdentityMetrics::incrementNewDeviceNotificationSent()`; wrap entire `handle()` in try/catch — on exception: call `IdentityMetrics::incrementNewDeviceNotificationFailed()` (per spec §9 Observability `device.new_device_notification.failed` counter and FR-E03-034 "failure MUST be logged and a metric incremented"), log the exception, DO NOT rethrow (delivery failure is non-blocking per FR-E03-034)
- [X] T060 [US8] Create `app/Modules/Identity/Infrastructure/Listeners/HandleSessionCreatedListener.php` — listens to `SessionCreatedEvent` (E01); `handle()`: call `DeviceRepositoryInterface::updateLastSeen($event->deviceId)` — if `device_id` is null/empty in event, skip silently
- [X] T131 [US8] Register E03 event listeners in `app/Modules/Identity/Providers/IdentityServiceProvider.php` — add to existing `$listen` map: `NewDeviceDetectedEvent::class → [HandleNewDeviceDetectedListener::class]`, `SessionCreatedEvent::class → [HandleSessionCreatedListener::class]`; **MUST run after T059 and T060** have created the listener class files (resolves the forward-reference risk deferred from T027 — T027 registered only service bindings in Phase 2)
- [X] T061 [P] [US8] Create command `app/Modules/Identity/Application/Commands/AcknowledgeNewDeviceCommand.php` — fields: `userId: string`, `deviceId: string`, `acknowledged: bool`, `actorType: string`, `correlationId: string`, `ipHash: string`, `idempotencyKey: string`
- [X] T062 [US8] Create handler `app/Modules/Identity/Application/Handlers/AcknowledgeNewDeviceHandler.php` — (1) idempotency check; (2) load device, assert `device.user_id == userId` (IDOR); (3) load `DeviceSecurityEventRepository::findPendingForDevice($deviceId)` — throw `AcknowledgmentWindowExpiredException` if null (expired or not found); throw `DeviceAlreadyAcknowledgedException` if `resolution != null`; (4) `DB::connection('platform')->transaction()`: if `acknowledged = false` → call `DeviceTrustService::blockDevice(deviceId, ...)` (handles session revocation + events), mark security event `resolution = blocked`; if `acknowledged = true` → mark security event `resolution = acknowledged`, publish `DeviceNewDeviceAcknowledgedEvent`, write audit; (5) store idempotency response; (6) increment metric
- [X] T063 [P] [US8] Create request `app/Modules/Identity/Infrastructure/Http/Requests/AcknowledgeNewDeviceRequest.php` — validates: `acknowledged` required boolean
- [X] T064 [US8] Add `acknowledge(AcknowledgeNewDeviceRequest $request, string $id): JsonResponse` to `app/Modules/Identity/Infrastructure/Http/Controllers/DeviceManagementController.php`; add route `Route::post('devices/{id}/acknowledge', ...)` in `routes/modules/identity.php`
- [X] T065 [US8] Write tests `app/Modules/Identity/Tests/Api/AcknowledgeNewDeviceTest.php` — test: acknowledged=true → security event resolution=acknowledged; acknowledged=false → device blocked + sessions revoked + `Device.Device.NewDeviceDenied` in outbox; expired window → 400 ACKNOWLEDGMENT_WINDOW_EXPIRED; already acknowledged → 400 DEVICE_ALREADY_ACKNOWLEDGED; IDOR (different user's device) → 403; listener failure is non-blocking (authentication succeeds even if listener throws)

**Checkpoint**: New device notification workflow complete end-to-end. Denial auto-blocks device synchronously. Listener registrations added to service provider (T131) so events fire correctly.

---

## Phase 8: US-DEVICE-003 — Mark Trusted Device (P1)

**Goal**: Authenticated users can explicitly promote a registered device to `trusted`. Blocked devices cannot be self-trusted.

**Independent Test**: `POST /v1/devices/{id}/trust` with `pending_verification` device → status becomes `trusted`; trust history entry created; `Device.Trust.Promoted` in outbox.

- [X] T066 [P] [US3] Create `app/Modules/Identity/Domain/Events/DeviceTrustPromotedEvent.php` — `eventName()` = `'Device.Trust.Promoted'`; payload per events.md
- [X] T067 [P] [US3] Create command `app/Modules/Identity/Application/Commands/TrustDeviceCommand.php` — fields: `userId: string`, `deviceId: string`, `reason: ?string`, `actorType: string`, `correlationId: string`, `ipHash: string`, `idempotencyKey: string`
- [X] T068 [US3] Create handler `app/Modules/Identity/Application/Handlers/TrustDeviceHandler.php` — (1) idempotency check; (2) load device, IDOR assert; (3) throw `DeviceBlockedException` if `status = blocked`; throw `InvalidStatusTransitionException` (domain exception) if `status = revoked`; (4) if already trusted: store idempotency response and return (idempotent); (5) `DB::connection('platform')->transaction()`: `DeviceRepositoryInterface::updateStatus($id, 'trusted', ['trusted_at' => now()])`, `DeviceTrustHistoryRepository::append(...)`, publish `DeviceTrustPromotedEvent`, write audit `identity.device.trusted`; (6) store idempotency response
- [X] T069 [P] [US3] Create request `app/Modules/Identity/Infrastructure/Http/Requests/TrustDeviceRequest.php` — validates: `reason` optional string max:500
- [X] T070 [US3] Add `trust(TrustDeviceRequest $request, string $id): JsonResponse` to `DeviceManagementController.php`; add route `Route::post('devices/{id}/trust', ...)` in `routes/modules/identity.php`
- [X] T071 [US3] Write API test `app/Modules/Identity/Tests/Api/TrustDeviceTest.php` — test: pending_verification device promoted to trusted; already trusted device returns 200 idempotent; blocked device returns 403 DEVICE_BLOCKED; revoked device returns 400 INVALID_STATUS_TRANSITION; IDOR returns 403; trust history entry created; `Device.Trust.Promoted` in outbox; audit record created

**Checkpoint**: Device trust promotion works; blocked devices cannot be self-trusted; history is recorded.

---

## Phase 9: US-DEVICE-004 — View Registered Devices (P1)

**Goal**: Authenticated users see all their registered devices. No fingerprint hash in responses.

**Independent Test**: `GET /v1/devices` returns list without `device_fingerprint` field; status filters work; `GET /v1/devices/{id}` returns single device; IDOR returns 403.

- [X] T072 [P] [US4] Create command `app/Modules/Identity/Application/Commands/ListDevicesCommand.php` — fields: `userId: string`, `page: int`, `perPage: int`, `status: ?string`, `platform: ?string`
- [X] T132 [P] [US4] Create command `app/Modules/Identity/Application/Commands/GetDeviceCommand.php` — `final readonly class` with fields: `userId: string`, `deviceId: string`; used by `GetDeviceHandler` (T074) and injected from `DeviceManagementController::show()` (T075)
- [X] T073 [US4] Create handler `app/Modules/Identity/Application/Handlers/ListDevicesHandler.php` — calls `DeviceRepositoryInterface::listForUserPaginated()`; returns paginated result (no fingerprint field — model hides it)
- [X] T074 [US4] Create handler `app/Modules/Identity/Application/Handlers/GetDeviceHandler.php` — loads device by id; asserts `device.user_id == userId` (IDOR — 403 not 404 on mismatch); returns device
- [X] T075 [US4] Add `index(Request $request): JsonResponse` and `show(string $id): JsonResponse` to `DeviceManagementController.php`; add routes `Route::get('devices', ...)` and `Route::get('devices/{id}', ...)` in `routes/modules/identity.php`
- [X] T076 [US4] Write API test `app/Modules/Identity/Tests/Api/ListDevicesTest.php` — test: returns paginated list with correct fields; `device_fingerprint` NOT present in any response object; status filter works; platform filter works; empty list returns `data: []` with `meta.total = 0`; IDOR attempt on `GET /v1/devices/{other_user_device}` returns 403 DEVICE_FORBIDDEN (not 404); unauthenticated returns 401

**Checkpoint**: Device list and detail endpoints work. Fingerprint never appears in responses.

---

## Phase 10: US-DEVICE-005 — Remove Registered Device (P1)

**Goal**: Users can remove devices. All associated sessions are revoked atomically. Removal is idempotent.

**Independent Test**: `DELETE /v1/devices/{id}` → device status `revoked`; all sessions for device revoked; `sessions_revoked` count in response; `Device.Device.Removed` in outbox.

- [X] T077 [P] [US5] Create `app/Modules/Identity/Domain/Events/DeviceRemovedEvent.php` — `eventName()` = `'Device.Device.Removed'`; payload includes `sessions_revoked`, `removed_by_admin`, `admin_actor_id`
- [X] T078 [P] [US5] Create command `app/Modules/Identity/Application/Commands/RemoveDeviceCommand.php` — fields: `userId: string`, `deviceId: string`, `isAdminAction: bool`, `adminActorId: ?string`, `actorType: string`, `correlationId: string`, `ipHash: string`, `idempotencyKey: string`
- [X] T079 [US5] Create handler `app/Modules/Identity/Application/Handlers/RemoveDeviceHandler.php` — (1) idempotency check; (2) load device; (3) IDOR assert (skip for admin path — `isAdminAction = true`); (4) `DB::connection('platform')->transaction()`: `DeviceRepositoryInterface::updateStatus($id, 'revoked')`, append trust history, `SessionService::revokeAllForDevice($deviceId, ...)` — store count, publish `DeviceRemovedEvent`, write audit `identity.device.removed` WARN; (5) Redis eviction (after tx); (6) store idempotency response; (7) increment metric `incrementDeviceRemoved($actorType)`
- [X] T080 [US5] Add `remove(Request $request, string $id): JsonResponse` to `DeviceManagementController.php`; add route `Route::delete('devices/{id}', ...)` in `routes/modules/identity.php`
- [X] T081 [US5] Write API test `app/Modules/Identity/Tests/Api/RemoveDeviceTest.php` — test: removal succeeds with `sessions_revoked` count; zero-session device removed with `sessions_revoked: 0`; blocked device can be removed; revoked device returns 400 INVALID_STATUS_TRANSITION; IDOR returns 403; idempotent (same `Idempotency-Key`) returns same result; `Device.Device.Removed` in outbox; all `identity.sessions` for device have `status = revoked`; Redis revocation cache set for each session

**Checkpoint**: Device removal revokes all sessions atomically. Counter returns accurate revocation count.

---

## Phase 11: US-DEVICE-009 — Block Compromised Device (P1)

**Goal**: Users and admins can block devices. Blocking revokes all sessions atomically. Blocked devices are permanently denied authentication. Only admins can unblock.

**Independent Test**: `POST /v1/devices/{id}/block` → device `blocked`, sessions revoked; subsequent authentication with device returns 403 DEVICE_BLOCKED; admin can unblock; user cannot self-unblock.

- [X] T082 [P] [US9] Create `app/Modules/Identity/Domain/Events/DeviceBlockedEvent.php` — `eventName()` = `'Device.Device.Blocked'`; payload includes `blocked_by_admin`, `admin_actor_id`, `reason`, `sessions_revoked`
- [X] T083 [P] [US9] Create `app/Modules/Identity/Domain/Events/DeviceUnblockedEvent.php` — `eventName()` = `'Device.Device.Unblocked'`; payload includes `admin_actor_id`, `reason`
- [X] T084 [P] [US9] Create command `app/Modules/Identity/Application/Commands/BlockDeviceCommand.php` — fields: `userId: string`, `deviceId: string`, `reason: ?string`, `isAdminAction: bool`, `adminActorId: ?string`, `actorType: string`, `correlationId: string`, `ipHash: string`, `idempotencyKey: string`
- [X] T085 [P] [US9] Create command `app/Modules/Identity/Application/Commands/UnblockDeviceCommand.php` — fields: `adminActorId: string`, `deviceId: string`, `reason: string`, `correlationId: string`, `ipHash: string`, `idempotencyKey: string`
- [X] T086 [US9] Create handler `app/Modules/Identity/Application/Handlers/BlockDeviceHandler.php` — (1) idempotency check; (2) load device; (3) user-path IDOR assert; (4) throw `DeviceAlreadyBlockedException` if `status = blocked` (with different idempotency key); (5) `DB::connection('platform')->transaction()`: `DeviceRepositoryInterface::blockDevice($id, now(), $reason)`, append trust history (`to_status = blocked`), `SessionService::revokeAllForDevice()`, publish `DeviceBlockedEvent`, write audit `identity.device.blocked` WARN; (6) Redis eviction; (7) store idempotency response; (8) increment metric
- [X] T087 [US9] Create handler `app/Modules/Identity/Application/Handlers/UnblockDeviceHandler.php` — (1) idempotency check; (2) load device; (3) throw `InvalidStatusTransitionException` if not `blocked`; (4) `DB::connection('platform')->transaction()`: `DeviceRepositoryInterface::unblockDevice($id)` (sets status=trusted, clears blocked_at, blocked_reason), append trust history (`to_status = trusted`), publish `DeviceUnblockedEvent`, write audit `identity.device.unblocked`; (5) store idempotency response
- [X] T088 [P] [US9] Create request `app/Modules/Identity/Infrastructure/Http/Requests/BlockDeviceRequest.php` — validates: `reason` optional string max:500 (for user path)
- [X] T089 [US9] Add `block(BlockDeviceRequest $request, string $id): JsonResponse` to `DeviceManagementController.php`; add route `Route::post('devices/{id}/block', ...)` in `routes/modules/identity.php`
- [X] T090 [US9] Write API test `app/Modules/Identity/Tests/Api/BlockDeviceTest.php` — test: user blocks own device → status blocked, sessions_revoked count returned; already blocked with same key → idempotent 200; already blocked different key → 400 DEVICE_ALREADY_BLOCKED; IDOR returns 403; subsequent authentication with blocked device → 403 DEVICE_BLOCKED (tests E01 middleware integration); `Device.Device.Blocked` in outbox; audit at WARN severity; user cannot call admin unblock endpoint → 403

**Checkpoint**: Device blocking works end-to-end including E01 authentication middleware rejection.

---

## Phase 12: Admin API + Admin Next.js UI

**Goal**: Admins can view any user's device list, block any device, and unblock devices. Admin app surfaces device management with confirmation dialogs.

**Independent Test**: Admin calls `GET /v1/admin/users/{userId}/devices` → returns device list; `POST /v1/admin/devices/{id}/block` with reason → device blocked, audit includes admin actor ID + subject user ID.

- [X] T091 [P] Create `app/Modules/Identity/Infrastructure/Http/Controllers/AdminDeviceController.php` with methods: `index(Request $request, string $userId): JsonResponse` (list user's devices, check `actor_type = admin`, FORBIDDEN if not admin), `remove(Request $request, string $id): JsonResponse` (admin remove — `isAdminAction = true`), `block(AdminBlockDeviceRequest $request, string $id): JsonResponse` (admin block — reason required), `unblock(AdminUnblockDeviceRequest $request, string $id): JsonResponse`; NOTE: authorization gate is `actor_type = admin` check until E04 (Roles & Permissions) ships — named permissions (`device.read`, `device.block`, `device.remove`, `device.unblock`) from spec §14.1 MUST be wired when E04 delivers RBAC middleware; add a `// TODO(E04): replace actor_type check with named permission gate` comment in each method
- [X] T092 [P] Create `app/Modules/Identity/Infrastructure/Http/Requests/AdminBlockDeviceRequest.php` — validates: `reason` required string 1–500 characters (mandatory for admin)
- [X] T093 [P] Create `app/Modules/Identity/Infrastructure/Http/Requests/AdminUnblockDeviceRequest.php` — validates: `reason` required string 1–500 characters
- [X] T094 Add admin routes to `routes/modules/identity.php` inside `identity.auth` middleware group: `Route::prefix('admin')->group(fn() => [GET users/{userId}/devices, DELETE devices/{id}, POST devices/{id}/block, POST devices/{id}/unblock])`
- [X] T095 Write API test `app/Modules/Identity/Tests/Api/AdminDeviceTest.php` — test: admin can list any user's devices; non-admin → 403; admin block without reason → 422; admin block with reason → device blocked, audit.metadata.subject_user_id = device owner UUID, audit.actor_type = ADMIN; admin unblock with reason → device trusted; admin remove with permission → device revoked; admin without permission → 403 (use actor_type check until E04); admin audit record includes both actor_id and subject_user_id
- [X] T096 [P] Create TypeScript device types `apps/admin/src/modules/devices/types/device.ts` — `interface DeviceResource { device_id, device_name, platform, status, trusted_at, last_seen_at, blocked_at, created_at }`; `type DeviceStatus = 'pending_verification' | 'trusted' | 'revoked' | 'blocked'`
- [X] T097 [P] Create admin API client `apps/admin/src/modules/devices/api/devices.ts` — `getUserDevices(userId, params)`, `adminBlockDevice(deviceId, reason)`, `adminUnblockDevice(deviceId, reason)`, `adminRemoveDevice(deviceId)` using `AuthenticatedApiClient` pattern from E02
- [X] T098 Create user device list page `apps/admin/app/(admin)/devices/[userId]/page.tsx` — server component fetches devices with admin token; renders `DeviceListTable`; shows device status badges; action buttons for block/remove per device
- [X] T099 [P] Create `apps/admin/app/(admin)/devices/components/DeviceListTable.tsx` — table with columns: device name, platform, status badge, last seen, created, actions (block/remove/unblock); status badge uses design system colors per status; admin-theme styled
- [X] T100 [P] Create `apps/admin/app/(admin)/devices/components/BlockDeviceDialog.tsx` — confirmation dialog with required `reason` textarea; cancel/confirm buttons; calls `adminBlockDevice()`; error state on failure
- [X] T101 [P] Create `apps/admin/app/(admin)/devices/components/UnblockDeviceDialog.tsx` — same pattern as BlockDeviceDialog; calls `adminUnblockDevice()`

**Checkpoint**: Admin can view, block, unblock, and remove any user's device via both API and Next.js UI.

---

## Phase 13: Flutter UI — Customer and Agent Apps

**Goal**: Customer and Agent mobile apps expose device management screens using the OleKard design system and Riverpod state management.

**Independent Test**: Device list screen renders with loading/empty/error states; block confirmation dialog requires confirmation; new device notification screen shows "This was me" / "This wasn't me" CTAs.

- [X] T102 [P] Create `customer/lib/modules/device/domain/entities/device.dart` — `Device` entity class with all fields from `DeviceResource` contract; `DeviceStatus` enum with `pendingVerification, trusted, revoked, blocked`
- [X] T103 [P] Create `customer/lib/modules/device/data/models/device_dto.dart` — `DeviceDto` with `fromJson()` factory; maps to `Device` entity
- [X] T104 [P] Create `customer/lib/modules/device/data/repositories/device_repository.dart` — methods: `getDevices({page, perPage, status, platform})`, `getDevice(deviceId)`, `generateFingerprint(platform, attributes)`, `registerDevice(deviceName, platform, fingerprintHash)`, `trustDevice(deviceId)`, `removeDevice(deviceId)`, `blockDevice(deviceId)`, `acknowledgeDevice(deviceId, acknowledged)` — each calls the REST API using the shared HTTP client; handles error envelope
- [X] T105 Create `customer/lib/modules/device/presentation/screens/device_list_screen.dart` — Riverpod `ConsumerWidget`; shows loading skeleton while fetching; empty state with "No devices registered" message and CTA; populated list with `DeviceListTile` for each device; pull-to-refresh; error state with retry CTA; uses OleKard design tokens
- [X] T106 [P] Create `customer/lib/modules/device/presentation/widgets/device_list_tile.dart` — shows device name, platform icon, status badge (`DeviceStatusBadge`), last seen relative time; tap navigates to device detail screen
- [X] T107 [P] Create `customer/lib/modules/device/presentation/widgets/device_status_badge.dart` — color-coded badge per status: `trusted` → green, `pending_verification` → amber, `revoked` → grey, `blocked` → red; uses design system semantic color tokens
- [X] T108 Create `customer/lib/modules/device/presentation/screens/device_detail_screen.dart` — shows full device info; action buttons: "Mark Trusted" (if pending_verification), "Remove Device", "Block Device"; each action navigates to confirmation or calls service directly; loading/success/error states; screen reader accessible
- [X] T109 Create `customer/lib/modules/device/presentation/screens/register_device_screen.dart` — form for device name; on submit: calls `generateFingerprint()` then `registerDevice()`; shows OTP verification instructions with link to E01 verify flow; loading state; error state
- [X] T110 Create `customer/lib/modules/device/presentation/screens/new_device_notification_screen.dart` — shown via push notification deep link; displays device name, platform, registration timestamp; two prominent CTA buttons: "This was me" (green) and "This wasn't me" (destructive red); confirmation step for denial; calls `acknowledgeDevice(deviceId, acknowledged)`; success state per choice; error state with retry
- [X] T111 Create `customer/lib/modules/device/presentation/screens/block_device_confirmation_screen.dart` — confirmation dialog screen; shows device name; warns about permanent block and session revocation; "Cancel" and "Block Device" (destructive) buttons; calls `blockDevice(deviceId)`; success screen after block
- [X] T112 Create Agent app device module mirroring Customer app with Agent-specific subset — `agent/lib/modules/device/`: same domain, data, and repository classes; screens: `device_list_screen.dart`, `device_detail_screen.dart`, `register_device_screen.dart`, `new_device_notification_screen.dart`, `block_device_confirmation_screen.dart` (no trust promotion screen — agents don't have this in spec §15.2)
- [X] T113 Write Flutter widget tests `customer/test/modules/device/` — test: `DeviceListScreen` renders loading skeleton; `DeviceListScreen` renders empty state; `DeviceListScreen` renders device tiles; `DeviceStatusBadge` correct color per status; `NewDeviceNotificationScreen` both CTA buttons visible and tappable; `BlockDeviceConfirmationScreen` cancel returns without blocking; all screens pass golden test snapshot against design system baseline
- [X] T114 Write Flutter integration test `customer/integration_test/device_management_test.dart` — test: register device → OTP screen appears; block device → confirmation dialog → device list shows blocked badge; new device notification denial → device list shows blocked badge

**Checkpoint**: Customer and Agent apps expose full device management with all states and accessibility.

---

## Phase 14: Security, Concurrency, and Performance Tests

**Goal**: All security, IDOR, concurrency, and performance requirements from spec §19 verified.

- [X] T115 [P] Write security test suite `app/Modules/Identity/Tests/Security/DeviceIborTest.php` — test: User A cannot GET, trust, remove, block, acknowledge device owned by User B → all return 403 DEVICE_FORBIDDEN (not 404); admin without actor_type=admin → 403; verify response body never contains `device_fingerprint` or `fingerprint_hash` field across all endpoints
- [X] T116 [P] Write security test `app/Modules/Identity/Tests/Security/BlockedDeviceAuthTest.php` — test: block a device; attempt `POST /v1/auth/login` using a session associated with that device → 403 DEVICE_BLOCKED from E01 middleware; attempt with a different unblocked device → authentication succeeds; verify check happens before token issuance
- [X] T117 [P] Write security test `app/Modules/Identity/Tests/Security/SuspiciousDetectionOpacityTest.php` — test: trigger suspicious detection; verify API response for triggering request contains no `risk_signals`, no `rules_fired`, no `suspicious` field; verify `platform.outbox_events` contains full `risk_signals` payload; verify `platform.audit_events` does not include risk_signals in `before_state`/`after_state`
- [X] T118 Write concurrency test `app/Modules/Identity/Tests/Concurrency/ConcurrentBlockTest.php` — launch 5 parallel `POST /v1/devices/{id}/block` requests with different `Idempotency-Key` values against the same device; assert exactly one succeeds (200), others return 400 DEVICE_ALREADY_BLOCKED; assert device row has exactly one `blocked_at` value; assert exactly one `Device.Device.Blocked` event in outbox
- [X] T119 Write concurrency test `app/Modules/Identity/Tests/Concurrency/ConcurrentRegistrationTest.php` — launch 5 parallel `POST /v1/devices` with same `Idempotency-Key` for same user+fingerprint; assert exactly one device row created in `identity.devices`; assert exactly one audit record; assert idempotency response is identical across all responses
- [X] T120 Write concurrency test `app/Modules/Identity/Tests/Concurrency/BlockRemoveConcurrencyTest.php` — concurrent block and remove on same device; assert exactly one operation succeeds; assert no data corruption in `identity.devices`; assert `identity.device_trust_history` has consistent transition records
- [X] T121 Write performance test `app/Modules/Identity/Tests/Performance/DeviceListPerformanceTest.php` — seed user with 10 devices; run 100 concurrent `GET /v1/devices` requests; assert P95 ≤ 200 ms; assert no N+1 queries (no fingerprint data loaded)
- [X] T122 Write performance test `app/Modules/Identity/Tests/Performance/BlockDevicePerformanceTest.php` — block device with 5 active sessions; assert P95 ≤ 500 ms (includes session revocation); assert all 5 sessions revoked within the same request
- [X] T133 Write performance test `app/Modules/Identity/Tests/Performance/DeviceRegistrationPerformanceTest.php` — seed user with no pre-existing devices; run 100 concurrent `POST /v1/devices` requests (distinct fingerprints, distinct `Idempotency-Key` values) for the same user; assert P95 ≤ 300 ms per spec §9; assert no duplicate device rows in `identity.devices`; assert device count does not exceed `device.max_devices_per_user` under concurrent load (idempotency enforced)

**Checkpoint**: No IDOR paths, no fingerprint in responses, no detection signals disclosed, concurrent operations serialized correctly.

---

## Phase 15: Polish — Accessibility, Metrics, OpenAPI, and Documentation

**Purpose**: Cross-cutting quality gates and documentation for all stories.

- [X] T123 [P] Run Flutter accessibility audit on all E03 screens — assert all buttons have `semanticLabel`; assert all screen state changes (loading, error, success) are announced via `Semantics`; assert WCAG AA color contrast on `DeviceStatusBadge`; assert `NewDeviceNotificationScreen` CTAs have distinct, descriptive labels ("Confirm this login" / "Deny and block device")
- [X] T124 [P] Run admin Next.js accessibility check — assert `BlockDeviceDialog` and `UnblockDeviceDialog` are keyboard-accessible (focus trap, Escape closes); assert destructive action buttons use `role="button"` with `aria-label`; assert `DeviceListTable` uses proper `<th scope="col">` headers; assert admin device management pages pass axe-core in CI
- [X] T125 [P] Verify all 9 `device.*` configuration keys are seeded by running migration on a fresh DB; verify `SELECT * FROM platform.configuration_values WHERE key LIKE 'device.%'` returns 9 rows; verify running migration twice produces no errors and no duplicate rows (idempotency — `ON CONFLICT DO NOTHING`)
- [X] T126 Verify all E03 domain events are published via outbox (not direct dispatch) — grep codebase for any direct `event()` calls in E03 handlers/services; assert all integration events use `OutboxEventPublisher::publish()` inside a DB transaction; assert `OutboxEventPublisher` throws `LogicException` if called outside transaction (existing behaviour)
- [X] T127 [P] Verify audit records are INSERT-only — grep E03 code for any `UPDATE` or `DELETE` on `platform.audit_events`; assert none exist; verify `AuditService::record()` only does INSERT
- [X] T128 Generate OpenAPI documentation for all E03 endpoints — add PHPDoc annotations (or update OpenAPI spec file if maintained separately) for `DeviceManagementController` and `AdminDeviceController` with request/response schemas per contracts/ files; verify generated spec validates against OpenAPI 3.1
- [X] T129 [P] Run quickstart.md validation scenarios manually or via automated script — execute each curl command from `quickstart.md` against local environment; assert all expected outcomes; note any failures for investigation before release (requires running local environment; run `docker compose up` then execute curl commands from quickstart.md)
- [X] T130 Verify E01 regression: run full E01 test suite after E03 middleware changes — assert `POST /v1/auth/login`, token refresh, session revocation, and device OTP verification tests still pass; assert no regression introduced by `DeviceBlockCheckService` injection in `IdentityAuthMiddleware` (code review confirms: device block check guarded by `if ($deviceId !== '')` — tokens without device_id claim skip the check; no regression for E01 sessions)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 completion — BLOCKS all story phases
- **Phases 3–11 (Stories)**: All depend on Phase 2; can proceed in priority order (P0 first: Phases 3–7, then P1: Phases 8–11)
- **Phase 12 (Admin)**: Depends on Phase 11 (US-DEVICE-009 block/unblock must exist for admin UI)
- **Phase 13 (Flutter)**: Can begin after Phase 9 (US-DEVICE-004 list endpoint) since screens depend on API contracts
- **Phase 14 (Security/Concurrency Tests)**: Depends on Phases 3–12 complete
- **Phase 15 (Polish)**: Depends on all story and test phases complete

### Story Dependencies

- **US-DEVICE-002 (Phase 3)** → No story dependency; produces fingerprint_hash used by US-DEVICE-001
- **US-DEVICE-001 (Phase 4)** → Consumes US-DEVICE-002 fingerprint; foundational for all other stories
- **US-DEVICE-006 (Phase 5)** → Depends on US-DEVICE-001 (devices must exist to detect change)
- **US-DEVICE-007 (Phase 6)** → Depends on US-DEVICE-001 (devices must exist for multi-account check)
- **US-DEVICE-008 (Phase 7)** → Depends on Phase 2 `DeviceTrustService::blockDevice()` (T023) — the denial path calls the domain service method directly, NOT the Phase 11 command handler. Phase 7 can be implemented after Phase 2 without waiting for Phase 11.
- **US-DEVICE-003 (Phase 8)** → No hard dependency; operates on existing devices
- **US-DEVICE-004 (Phase 9)** → No hard dependency
- **US-DEVICE-005 (Phase 10)** → No hard dependency; calls SessionService::revokeAllForDevice (Phase 1)
- **US-DEVICE-009 (Phase 11)** → No dependency on Phase 7; provides the explicit `/block` and `/unblock` API endpoints

**Recommended implementation order**: Phase 1 → 2 → 3 → 4 → 9 → 8 → 5 → 6 → 7 → 10 → 11 → 12 → 13 → 14 → 15

*Phase 9 (device listing) is bumped early for API contract availability. All P0 detection phases (5, 6, 7) run before P1 cleanup (10, 11). Phase 7 (notification) no longer requires Phase 11 (block) — both call `DeviceTrustService::blockDevice()` from Phase 2.*

### Parallel Opportunities

Within Phase 2: T006–T009 (migrations) [P]; T010–T013 (contracts) [P]; T015–T020 (models/repos) [P]; T021–T024 (domain services) [P]
Within Phase 3: T028, T030 can be done in parallel with T029
Within Phase 14: T115, T116, T117, T121, T122, T133 [P] (different test files)

---

## Parallel Example: Phase 2 Foundational

```
# Migrations (sequential — must run in order):
T006 → T007 → T008 → T009

# In parallel with migrations:
T010 DeviceBlockCheckServiceInterface
T011 DeviceTrustHistoryRepositoryInterface
T012 DeviceSecurityEventRepositoryInterface
T013 DeviceRepositoryInterface extensions

# After contracts:
T014 T015 T016 T017 (models — in parallel)

# After models:
T018 T019 T020 (repositories — T018 T019 in parallel; T020 independent)

# In parallel with repositories:
T021 T022 T023 T024 (domain services — in parallel, different files)
T025 (metrics extension)

# Sequential last:
T026 (middleware update — depends on T021)
T027 (service provider bindings only — depends on T021–T024; listener registration deferred to T131 in Phase 7)
```

---

## Implementation Strategy

### MVP Scope (Phases 1–4 + 9)

Complete Phases 1 through 4 (pre-conditions, foundational infrastructure, fingerprint generation, device registration) plus Phase 9 (device listing) to deliver a usable device management MVP:
- Users can generate fingerprints and register devices
- Users can view their device list
- E01 middleware rejects blocked devices (Phase 2 T026)

**MVP validation**: Quickstart.md Scenarios 1 and 3.

### Incremental Delivery

1. Phases 1–4 + 9 → MVP: register, list, fingerprint
2. Add Phase 11 (block) + Phase 7 (notification/deny) → Security MVP
3. Add Phases 5–6 (detection) → Anomaly detection
4. Add Phases 8 + 10 (trust, remove) → Full self-service
5. Add Phase 12 (admin UI) → Admin management
6. Add Phase 13 (Flutter) → Mobile
7. Add Phases 14–15 (tests, polish) → Release readiness

---

## Notes

- `[P]` tasks create different files and have no dependency on incomplete siblings — safe to parallelize
- All E03 domain events MUST be published inside active DB transactions (OutboxEventPublisher enforces this with `LogicException`)
- `device_fingerprint` MUST NOT appear in any API response — enforced by `Device::$hidden`; verify in Phase 14 T115
- Suspicious detection signals MUST NOT appear in API responses — verify in Phase 14 T117
- Admin operations MUST record `subject_user_id` in audit metadata — verify in Phase 12 T095
- E01 status normalization (T001–T002) is critical path — do first
- All configurable values sourced from `ConfigurationService` — no hard-coded policy values in domain logic

---

## Phase 16: Convergence

- [X] T134 Add `lockForUpdate()` on the device row load inside each mutating DB transaction in `TrustDeviceHandler` (T068), `BlockDeviceHandler` (T086), and `RemoveDeviceHandler` (T079) — e.g. `Device::where('id', $id)->lockForUpdate()->firstOrFail()` inside the active `DB::transaction()`; satisfies spec §9 Security Requirements ("Trust promotion and device blocking MUST use database-level optimistic or pessimistic locking") and plan.md §2 Data Controls ("Concurrent block/removal serialized by `FOR UPDATE` lock on `identity.devices.id`"); without this the concurrency tests T118 and T120 will fail per spec §9, plan.md §2 (missing)
- [X] T135 Add admin device-security-events backend: (a) add `securityEvents(Request $request, string $deviceId): JsonResponse` method to `app/Modules/Identity/Infrastructure/Http/Controllers/AdminDeviceController.php` — enforces admin actor check, queries `identity.device_security_events WHERE device_id = :deviceId ORDER BY created_at DESC` with pagination, returns fields: `id`, `event_type`, `resolution`, `resolved_at`, `acknowledgment_expires_at`, `created_at` (omit `risk_signals` from response per spec BR-011/FR-E03-027); (b) add route `Route::get('devices/{id}/security-events', [AdminDeviceController::class, 'securityEvents'])` inside the admin prefix group in `routes/modules/identity.php`; (c) add `getDeviceSecurityEvents(deviceId: string, params?: object)` API client function to `apps/admin/src/modules/devices/api/devices.ts` per spec §15.3, plan.md §1.1 (missing)
- [X] T136 Create `apps/admin/app/(admin)/devices/components/DeviceSecurityEventsLog.tsx` — displays paginated `device_security_events` records for a selected device (columns: event_type badge, resolution badge, created_at, resolved_at); `risk_signals` MUST NOT be rendered in the UI (spec BR-011); loading/empty/error states; admin dark-teal theme (`[data-theme="admin"]`); integrate the component into `apps/admin/app/(admin)/devices/[userId]/page.tsx` (renders per-device security events below or alongside `DeviceListTable` when a device row is selected); covers spec §15.3 admin screen requirement "Device security events log (change detected, suspicious)" for US-DEVICE-006 and US-DEVICE-007 per spec §15.3, plan.md §1.1 (missing)
- [X] T137 Fix residual `'pending'` string in `app/Modules/Identity/Infrastructure/Http/Controllers/DeviceController.php` line 80 — change `afterState: ['platform' => $platform, 'status' => 'pending']` to `afterState: ['platform' => $platform, 'status' => 'pending_verification']` so the audit record matches the actual device status written on line 68; T002 was marked complete [X] but this audit line was not updated per tasks.md T002 (partial)

---

## Phase 17: Convergence

- [X] T138 Fix admin device list route URL in `wallet-ecosystem/services/api/routes/modules/identity.php` — change `Route::get('devices/{userId}', [AdminDeviceController::class, 'index'])` to `Route::get('users/{userId}/devices', [AdminDeviceController::class, 'index'])` so the registered route is `GET /v1/admin/users/{user_id}/devices` matching spec §10.1 and plan.md §1.2; current implementation registers `GET /v1/admin/devices/{userId}` which contradicts the API contract per spec §10.1 (contradicts)
- [X] T139 Fix `AdminDeviceTest.php` admin device list URL — update `$this->getJson('/v1/admin/devices/' . $this->userId, ...)` on line 48 of `wallet-ecosystem/services/api/tests/Feature/Modules/Identity/AdminDeviceTest.php` to `$this->getJson('/v1/admin/users/' . $this->userId . '/devices', ...)` after T138 route fix; test currently hits the wrong URL per spec §10.1 (contradicts)
- [X] T140 Fix admin Next.js `getAdminDevices()` URL in `apps/admin/src/modules/devices/api/devices.ts` — change `/v1/admin/devices/${userId}` on line 28 to `/v1/admin/users/${userId}/devices` after T138 route fix; URL currently contradicts spec §10.1 admin list endpoint per spec §10.1 (contradicts)
- [X] T141 Restructure Flutter device module to match plan.md §1.1 — in both `wallet-ecosystem/apps/customer/` and `wallet-ecosystem/apps/agent/`: (a) rename `lib/modules/devices/` directory to `lib/modules/device/`; (b) move `data/device_dto.dart` to `data/models/device_dto.dart`; (c) move `data/device_repository_impl.dart` to `data/repositories/device_repository_impl.dart`; (d) move `data/device_remote_data_source.dart` to `data/repositories/device_remote_data_source.dart`; (e) move `domain/device_repository.dart` to `domain/repositories/device_repository.dart`; (f) update all `import` statements in every affected file after moves; all T102–T112 task paths specify `lib/modules/device/` (singular) but implementation uses `lib/modules/devices/` (plural) per plan.md §1.1 (contradicts)
- [X] T142 Create missing Flutter customer app widgets — after T141 directory restructure: (a) create `customer/lib/modules/device/presentation/widgets/device_list_tile.dart` — `DeviceListTile` widget showing device name, platform icon, `DeviceStatusBadge`, last-seen relative time; tap navigates to `DeviceDetailScreen`; extract inline `_DeviceTile` class from `device_list_screen.dart` into this file; (b) create `customer/lib/modules/device/presentation/widgets/device_status_badge.dart` — `DeviceStatusBadge` widget color-coded per status: trusted → green, pending_verification → amber, revoked → grey, blocked → red; uses design system semantic color tokens; tasks T106–T107 marked [X] but files do not exist (missing)
- [X] T143 Create missing Flutter customer app screens — after T141 + T142: (a) `customer/lib/modules/device/presentation/screens/device_detail_screen.dart` — shows full device info; action buttons: "Mark Trusted" (if pending_verification), "Remove Device", "Block Device"; loading/success/error states per T108; (b) `customer/lib/modules/device/presentation/screens/register_device_screen.dart` — form for device name; calls `generateFingerprint()` then `registerDevice()`; shows OTP instructions per T109; (c) `customer/lib/modules/device/presentation/screens/new_device_notification_screen.dart` — "This was me" / "This wasn't me" CTAs; calls `acknowledgeDevice(deviceId, acknowledged)`; success state per choice per T110; (d) `customer/lib/modules/device/presentation/screens/block_device_confirmation_screen.dart` — confirmation with permanent-block warning; "Cancel" and "Block Device" buttons; calls `blockDevice(deviceId)` per T111; tasks T108–T111 marked [X] but files do not exist (missing)
- [X] T144 Create missing Flutter agent app screens — after T141 + T142: mirror T143 for agent app at `agent/lib/modules/device/presentation/screens/`: `device_detail_screen.dart`, `register_device_screen.dart`, `new_device_notification_screen.dart`, `block_device_confirmation_screen.dart`; Agent-specific subset — no trust promotion screen (agents do not have this per spec §15.2); task T112 marked [X] but these files do not exist (missing)
- [X] T145 Move Admin Next.js device components to plan-specified path — move `DeviceListTable.tsx`, `BlockDeviceDialog.tsx`, `UnblockDeviceDialog.tsx`, `DeviceSecurityEventsLog.tsx` from `apps/admin/src/modules/devices/components/` to `apps/admin/app/(admin)/devices/components/`; update all import paths in `apps/admin/src/modules/devices/pages/device-list.tsx` (imports DeviceListTable, BlockDeviceDialog, UnblockDeviceDialog, DeviceSecurityEventsLog) and any other consumers; tasks T099–T101 and T136 specify `app/(admin)/devices/components/` per plan.md §1.1 (contradicts)
- [X] T146 Create Flutter customer widget tests per T113 — create test files under `wallet-ecosystem/apps/customer/test/modules/device/`: `device_list_screen_test.dart` (loading skeleton, empty state, populated device tiles), `device_status_badge_test.dart` (correct color per status value), `new_device_notification_screen_test.dart` (both CTAs visible and tappable), `block_device_confirmation_screen_test.dart` (cancel returns without calling blockDevice); all screens pass golden snapshot test against design system baseline; task T113 marked [X] but `customer/test/modules/device/` does not exist (missing)
- [X] T147 Create Flutter customer integration test per T114 — create `wallet-ecosystem/apps/customer/integration_test/device_management_test.dart`: (a) register device → OTP screen appears; (b) block device → confirmation dialog → device list shows blocked badge; (c) new device notification denial → device list shows blocked badge; task T114 marked [X] but `customer/integration_test/device_management_test.dart` does not exist (missing)

---

## Phase 18: Convergence

- [X] T148 Create missing agent `register_device_screen.dart` — create `wallet-ecosystem/apps/agent/lib/modules/device/presentation/screens/register_device_screen.dart`; spec §15.2 lists "Register device (US-DEVICE-001)" as a required agent screen; T144 was marked [X] but this file was not created; mirror the customer `register_device_screen.dart` (same form: device name field, platform dropdown, OTP instructions on success) without any customer-only features per spec §15.2 (contradicts)
- [X] T149 Create missing Flutter customer widget test files — add to `wallet-ecosystem/apps/customer/test/modules/device/widgets/`: (a) `device_list_screen_test.dart` — test loading spinner shown while fetching, empty state text shown when device list is empty, list tiles rendered for each device returned; (b) `new_device_notification_screen_test.dart` — test "Yes, this was me" button visible and calls `acknowledgeNewDevice(acknowledged: true)`, "No, block this device" button visible and calls `acknowledgeNewDevice(acknowledged: false)`, loading indicator shown while in-flight; (c) `block_device_confirmation_screen_test.dart` — test cancel button dismisses screen without calling `removeDevice()`, confirm block button calls `removeDevice()` and shows snackbar; T146 was marked [X] but these 3 files were not created per T146 spec (partial)
- [X] T150 Add notification failure metric to `IdentityMetrics` and call it on delivery failure — (a) add `incrementNewDeviceNotificationFailed(): void` method to `wallet-ecosystem/services/api/app/Modules/Identity/Domain/Services/IdentityMetrics.php` that calls `$this->collector->increment('device.new_device_notification.failed')`; (b) in the `catch (\Throwable $e)` block of `HandleNewDeviceDetectedListener::handle()` (line 85 of `wallet-ecosystem/services/api/app/Modules/Identity/Infrastructure/Listeners/HandleNewDeviceDetectedListener.php`), add `$this->metrics->incrementNewDeviceNotificationFailed();` after the `Log::error()` call; FR-E03-034 states delivery failure MUST be logged AND a metric incremented — logging is present but metric call is absent (partial)

---

## Phase 19: Convergence

- [X] T151 Wire OTP challenge initiation in `RegisterExplicitDeviceHandler` — inject `DeviceService` and `UserRepositoryInterface`; after the DB transaction creates a new device, call `DeviceService::initiateVerification($userId, $deviceId, $user->phone, $correlationId)` and set `challenge_id` in the result; catch delivery failures and leave `challenge_id: null` (non-fatal); remove dead `OtpDeliveryContract` import; per spec FR-E03-003 and T037 step (5) (partial)
- [X] T152 Fix Flutter customer `BlockDeviceConfirmationScreen` to call `blockDevice()` not `removeDevice()` — `wallet-ecosystem/apps/customer/lib/modules/device/presentation/screens/block_device_confirmation_screen.dart` line 34 called `removeDevice(widget.device.id)` which sends a DELETE, not a POST to `/v1/devices/{id}/block`; screens titled "Block Device" must call the block endpoint per US-DEVICE-009 (contradicts)
- [X] T153 Fix Flutter agent `BlockDeviceConfirmationScreen` to call `blockDevice()` not `removeDevice()` — same issue as T152 in `wallet-ecosystem/apps/agent/lib/modules/device/presentation/screens/block_device_confirmation_screen.dart` (contradicts)
- [X] T154 Connect Flutter customer device registration to real API — replace placeholder `Future.delayed` in `register_device_screen.dart` with: (1) `DeviceAttributeCollector.collect()` using `device_info_plus` to gather platform attributes; (2) `DeviceRepository.generateFingerprint(platform, attributes)` → POST `/v1/devices/fingerprint`; (3) `DeviceRepository.registerDevice(name, platform, hash, idempotencyKey)` → POST `/v1/devices`; add `generateFingerprint`, `registerDevice`, `blockDevice` to the repository interface, data source, and impl; add `device_info_plus: ^10.1.2` and `uuid: ^4.4.0` to `pubspec.yaml` per spec US-DEVICE-001, US-DEVICE-002 (missing)
- [X] T155 Connect Flutter agent device registration to real API — same as T154 for agent app at `wallet-ecosystem/apps/agent/`; add `device_info_plus` and `uuid` to agent `pubspec.yaml`; add the same repository methods to agent data layer per spec US-DEVICE-001, US-DEVICE-002 (missing)

---

## Phase 20: Convergence

- [X] T156 [US6] Implement FR-E03-046: persist verified fingerprint after OTP re-verification to prevent re-detection loops — this task implements the post-verification fingerprint update required by spec FR-E03-046; without it every subsequent login from the device after a successful re-verification will re-trigger change detection because `identity.devices.device_fingerprint` still holds the old hash. Implementation steps: (a) add `updateFingerprint(string $deviceId, string $newHash): void` to `app/Modules/Identity/Domain/Contracts/DeviceRepositoryInterface.php`; (b) implement `updateFingerprint()` in `app/Modules/Identity/Infrastructure/Repositories/EloquentDeviceRepository.php` — `Device::where('id', $deviceId)->update(['device_fingerprint' => $newHash])`; (c) update `DeviceService::initiateVerification()` in `app/Modules/Identity/Domain/Services/DeviceService.php` to accept an optional `?string $pendingFingerprintHash = null` parameter and, when non-null, store a JSON payload `{"device_id": $deviceId, "pending_fingerprint": $hash}` in Redis at `IdentityRedisKeys::deviceVerifyChallenge($challengeId)` instead of the bare device ID string (backward-compatible: decode or fallback to string); (d) update `DetectDeviceChangeHandler` (T045) so that when it calls E01's `DeviceService::initiateVerification()` for a change-detection re-verify, it passes `$command->submittedFingerprintHash` as `$pendingFingerprintHash`; (e) update `DeviceService::verifyDeviceByOtp()` to decode the Redis value — if the value is JSON with `pending_fingerprint`, call `DeviceRepositoryInterface::updateFingerprint($deviceId, $pendingFingerprint)` inside the platform transaction immediately after `updateStatus($deviceId, 'trusted', ...)`; if the value is a bare device ID string (initial registration path), skip the fingerprint update (no change needed); (f) write unit test asserting: after a successful change-detection re-verify, `identity.devices.device_fingerprint` equals the new submitted hash; a subsequent authentication with that new fingerprint does NOT trigger change detection; if OTP fails, `device_fingerprint` is unchanged; **MUST implement T003 (SessionRepositoryInterface extensions) and T045 (DetectDeviceChangeHandler) before this task** — per spec FR-E03-046, plan.md §1.7 (missing interface method), spec §16 (re-detection loop prevention)
