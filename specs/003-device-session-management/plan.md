# Implementation Plan — E03 Device & Session Management

**Feature**: `003-device-session-management` | **Epic**: E03 | **Branch**: `003-device-session-management`
**Date**: 2026-09-02
**Spec**: [spec.md](spec.md) | **Research**: [research.md](research.md) | **Data Model**: [data-model.md](data-model.md)

---

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — no new dependencies beyond existing `ramsey/uuid`, Pest, PHPUnit
- [x] No TBD value invented — all 4 TBDs resolved in research.md with concrete decisions
- [x] Authorization remains server-side — IDOR guard in controller layer; admin permission check via `IdentityAuthMiddleware` + `AuthorizationGuard`
- [x] Financial integrity preserved — E03 has no financial operations; double-entry requirements not applicable
- [x] Security/audit requirements incorporated — all 12 audit actions, `AuditService` via INSERT-only, IP HMAC from E01 BR-027
- [x] UX/design-system requirements incorporated — Flutter screens use OleKard design tokens; admin screens use `[data-theme="admin"]`
- [x] QA strategy incorporated — unit, integration, API, security, concurrency, and accessibility tests specified

---

## Technical Context

| Dimension | Decision |
|-----------|----------|
| Backend API | Laravel / PHP 8.4+, `App\Modules\Identity\` namespace |
| Database | PostgreSQL `identity` schema (same as E01); `platform` schema for audit/outbox/idempotency |
| Queue | Redis + Laravel Horizon; `SuspiciousDeviceDetectionJob` on default queue |
| Cache | Redis via `IdentityRedisKeys::REDIS_CONNECTION`; session revocation cache extended for device sessions |
| Web admin | Next.js + TypeScript; `apps/admin/app/(admin)/devices/` directory |
| Mobile | Flutter (Customer + Agent apps); Riverpod state management |
| API style | REST/OpenAPI; standard `{ success, data, error, meta }` envelope |
| Testing | Pest (PHP); Flutter test; Next.js Jest; concurrency tests via parallel HTTP clients |
| Observability | `IdentityMetrics` extended with E03 counters; structured logs; `X-Correlation-ID` propagated |

---

## Pre-conditions (Blocking)

Before E03 implementation begins:

1. **E01 status normalization**: `DeviceService::resolveDevice()` uses `'unverified'`; `DeviceController::register()` uses `'pending'`; migration default is `'pending_verification'`. All E01 occurrences must use `'pending_verification'` before E03 ships. (Task T001)
2. **E01 session repository extension**: `SessionRepositoryInterface` must gain `listActiveForDevice(string $deviceId): array` and `revokeAllForDevice(string $deviceId): int` methods. (Task T002)
3. **E01 `SessionService` extension**: `revokeAllForDevice(string $deviceId, string $actorType, string $correlationId, string $ipHash): int` method. (Task T003)

---

## Phase 0 — Research

**Status**: Complete. See [research.md](research.md).

Resolved:
- TBD-E03-001: Fingerprint attribute schema → platform-specific maps; server-side SHA-256; entropy validation
- TBD-E03-002: Session revocation → synchronous `SessionService::revokeAllForDevice()` in same DB transaction
- TBD-E03-003: Suspicious detection rules → 3 configurable rules in `DeviceSecurityService`
- TBD-E03-004: E01 middleware integration → `DeviceBlockCheckServiceInterface` + implementation in E03; injected into `IdentityAuthMiddleware`

---

## Phase 1 — Design

### 1.1 File Structure

All new E03 files are within `wallet-ecosystem/services/api/app/Modules/Identity/` unless noted.

#### Application Layer

```
Application/
├── Commands/
│   ├── RegisterExplicitDeviceCommand.php      # US-DEVICE-001
│   ├── GenerateDeviceFingerprintCommand.php   # US-DEVICE-002
│   ├── TrustDeviceCommand.php                 # US-DEVICE-003
│   ├── ListDevicesCommand.php                 # US-DEVICE-004
│   ├── GetDeviceCommand.php                   # US-DEVICE-004 (single)
│   ├── RemoveDeviceCommand.php                # US-DEVICE-005
│   ├── DetectDeviceChangeCommand.php          # US-DEVICE-006 (internal)
│   ├── AcknowledgeNewDeviceCommand.php        # US-DEVICE-008
│   ├── BlockDeviceCommand.php                 # US-DEVICE-009 (user + admin)
│   └── UnblockDeviceCommand.php              # US-DEVICE-009 (admin only)
└── Handlers/
    ├── RegisterExplicitDeviceHandler.php
    ├── GenerateDeviceFingerprintHandler.php
    ├── TrustDeviceHandler.php
    ├── ListDevicesHandler.php
    ├── GetDeviceHandler.php
    ├── RemoveDeviceHandler.php
    ├── DetectDeviceChangeHandler.php
    ├── AcknowledgeNewDeviceHandler.php
    ├── BlockDeviceHandler.php
    └── UnblockDeviceHandler.php
```

#### Domain Layer

```
Domain/
├── Contracts/
│   ├── DeviceBlockCheckServiceInterface.php      # E01 middleware calls this (TBD-E03-004)
│   ├── DeviceTrustHistoryRepositoryInterface.php # identity.device_trust_history
│   └── DeviceSecurityEventRepositoryInterface.php # identity.device_security_events
├── Events/ (IntegrationEventInterface implementations)
│   ├── DeviceRegisteredEvent.php                 # Device.Device.Registered
│   ├── DeviceTrustPromotedEvent.php              # Device.Trust.Promoted
│   ├── DeviceRemovedEvent.php                    # Device.Device.Removed
│   ├── DeviceChangeDetectedEvent.php             # Device.Device.ChangeDetected
│   ├── DeviceSuspiciousDetectedEvent.php         # Device.Device.SuspiciousDetected
│   ├── DeviceNewDeviceNotificationSentEvent.php  # Device.Device.NewDeviceNotificationSent
│   ├── DeviceNewDeviceAcknowledgedEvent.php      # Device.Device.NewDeviceAcknowledged
│   ├── DeviceNewDeviceDeniedEvent.php            # Device.Device.NewDeviceDenied
│   ├── DeviceBlockedEvent.php                    # Device.Device.Blocked
│   └── DeviceUnblockedEvent.php                  # Device.Device.Unblocked
├── Exceptions/
│   ├── DeviceBlockedException.php
│   ├── DeviceLimitExceededException.php
│   ├── DeviceAlreadyBlockedException.php
│   ├── DeviceAlreadyAcknowledgedException.php
│   ├── AcknowledgmentWindowExpiredException.php
│   ├── InsufficientFingerprintEntropyException.php
│   └── InvalidStatusTransitionException.php
└── Services/
    ├── DeviceBlockCheckService.php               # Implements DeviceBlockCheckServiceInterface
    ├── DeviceFingerprintService.php              # Hashing, entropy validation, emulator detection
    ├── DeviceSecurityService.php                 # Change detection + suspicious detection rules
    └── DeviceTrustService.php                    # Trust promotion, blocking, removal, unblocking
```

#### Infrastructure Layer

```
Infrastructure/
├── Http/
│   ├── Controllers/
│   │   ├── DeviceManagementController.php        # US-DEVICE-001 to -005, -008, -009 (user)
│   │   └── AdminDeviceController.php             # US-DEVICE-004, -005, -009 (admin)
│   └── Requests/
│       ├── RegisterDeviceRequest.php
│       ├── GenerateFingerprintRequest.php
│       ├── TrustDeviceRequest.php
│       ├── AcknowledgeNewDeviceRequest.php
│       ├── BlockDeviceRequest.php
│       ├── AdminBlockDeviceRequest.php
│       └── AdminUnblockDeviceRequest.php
├── Jobs/
│   └── SuspiciousDeviceDetectionJob.php          # US-DEVICE-007 background rule check
├── Listeners/
│   ├── HandleNewDeviceDetectedListener.php       # Consumes Identity.Device.NewDeviceDetected
│   └── HandleSessionCreatedListener.php          # Consumes Identity.Session.Created → updates last_seen_at
├── Migrations/
│   ├── 2026_09_02_001000_add_blocked_columns_to_identity_devices.php
│   ├── 2026_09_02_002000_create_identity_device_trust_history_table.php
│   ├── 2026_09_02_003000_create_identity_device_security_events_table.php
│   └── 2026_09_02_004000_seed_device_configuration_defaults.php
├── Models/
│   ├── DeviceTrustHistory.php
│   └── DeviceSecurityEvent.php
└── Repositories/
    ├── EloquentDeviceTrustHistoryRepository.php
    └── EloquentDeviceSecurityEventRepository.php
```

#### Files Modified in E01

```
Domain/Contracts/SessionRepositoryInterface.php     # Add listActiveForDevice(), revokeAllForDevice()
Domain/Services/SessionService.php                  # Add revokeAllForDevice()
Domain/Services/DeviceService.php                   # Fix status 'unverified' → 'pending_verification'
Infrastructure/Http/Controllers/DeviceController.php # Fix status 'pending' → 'pending_verification'
Infrastructure/Http/Middleware/IdentityAuthMiddleware.php # Inject DeviceBlockCheckServiceInterface, add blocked check
Infrastructure/Repositories/EloquentSessionRepository.php # Implement listActiveForDevice(), revokeAllForDevice()
Providers/IdentityServiceProvider.php              # Register new bindings + listeners
routes/modules/identity.php                         # Add E03 routes
```

#### Admin Next.js (apps/admin)

```
app/(admin)/devices/
├── [userId]/
│   └── page.tsx                   # User device list (admin view)
├── components/
│   ├── DeviceListTable.tsx
│   ├── BlockDeviceDialog.tsx
│   ├── UnblockDeviceDialog.tsx
│   └── DeviceSecurityEventsLog.tsx
src/modules/devices/
├── api/
│   └── devices.ts                 # API client calls
└── types/
    └── device.ts                  # TypeScript types
```

#### Flutter Customer App

```
wallet-ecosystem/apps/customer/lib/modules/device/
├── domain/entities/device.dart
├── data/
│   ├── models/device_dto.dart
│   └── repositories/device_repository.dart
└── presentation/
    ├── screens/
    │   ├── device_list_screen.dart
    │   ├── device_detail_screen.dart
    │   ├── register_device_screen.dart
    │   ├── new_device_notification_screen.dart
    │   └── block_device_confirmation_screen.dart
    └── widgets/
        ├── device_list_tile.dart
        └── device_status_badge.dart
```

Flutter Agent app mirrors the Customer device module with the Agent-specific subset of screens (list, register, remove, notification acknowledgment, block).

---

### 1.2 Route Registration

New routes added to `wallet-ecosystem/services/api/routes/modules/identity.php`:

```php
// -------------------------------------------------------------------------
// E03: Device Management (T0XX)
// -------------------------------------------------------------------------
Route::middleware(['throttle:identity-auth', 'identity.auth'])->group(static function (): void {

    // User device management
    Route::get('devices',                              [DeviceManagementController::class, 'index']);
    Route::get('devices/{id}',                         [DeviceManagementController::class, 'show']);
    Route::post('devices',                             [DeviceManagementController::class, 'register']);
    Route::post('devices/fingerprint',                 [DeviceManagementController::class, 'fingerprint']);
    Route::post('devices/{id}/trust',                  [DeviceManagementController::class, 'trust']);
    Route::delete('devices/{id}',                      [DeviceManagementController::class, 'remove']);
    Route::post('devices/{id}/acknowledge',            [DeviceManagementController::class, 'acknowledge']);
    Route::post('devices/{id}/block',                  [DeviceManagementController::class, 'block']);

    // Admin device management — actor type admin check in controller
    Route::prefix('admin')->group(static function (): void {
        Route::get('users/{userId}/devices',           [AdminDeviceController::class, 'index']);
        Route::delete('devices/{id}',                  [AdminDeviceController::class, 'remove']);
        Route::post('devices/{id}/block',              [AdminDeviceController::class, 'block']);
        Route::post('devices/{id}/unblock',            [AdminDeviceController::class, 'unblock']);
    });
});
```

---

### 1.3 Database Migration Strategy

All E03 migrations use the `identity` connection. Migrations run in order:

| Order | File | Type | Risk |
|-------|------|------|------|
| 1 | `2026_09_02_001000_add_blocked_columns_to_identity_devices.php` | ALTER TABLE (nullable columns, no DEFAULT) | Zero-downtime — nullable ADD COLUMN |
| 2 | `2026_09_02_002000_create_identity_device_trust_history_table.php` | CREATE TABLE | None |
| 3 | `2026_09_02_003000_create_identity_device_security_events_table.php` | CREATE TABLE | None |
| 4 | `2026_09_02_004000_seed_device_configuration_defaults.php` | INSERT with conflict ignore | Idempotent |

Migration 1 adds `blocked_at TIMESTAMPTZ NULL` and `blocked_reason VARCHAR(255) NULL` without a DEFAULT. This is a zero-downtime operation in PostgreSQL — existing rows are unaffected; the application checks `status = 'blocked'` rather than `blocked_at IS NOT NULL`.

---

### 1.4 Service Interaction Flow

#### Block Device Flow (US-DEVICE-009)

```
HTTP POST /v1/devices/{id}/block
  → DeviceManagementController::block()
  → IdempotencyService::findCompletedResponse()  [replay if duplicate key]
  → BlockDeviceHandler::handle(BlockDeviceCommand)
      → DeviceTrustService::blockDevice()
          → DeviceRepositoryInterface::findById()  [load device]
          → IDOR assertion: device.user_id == token.user_id
          → throw DeviceAlreadyBlockedException if status == blocked
          → DB::connection('platform')->transaction():
              → DeviceRepositoryInterface::updateStatus(id, 'blocked', [blocked_at, blocked_reason])
              → DeviceTrustHistoryRepository::append(device_id, from_status, 'blocked', ...)
              → SessionService::revokeAllForDevice(device_id, ...)  ← synch, same tx
              → OutboxEventPublisher::publish(DeviceBlockedEvent)
              → AuditService::record(identity.device.blocked, WARN)
          → Redis: evict sessions (after tx commit)
          → MetricsCollector::increment('device.blocked', ['actor_type' => 'USER'])
  → IdempotencyService::store(key, response)
  → HTTP 200 { device_id, status: blocked, sessions_revoked: N }
```

#### Change Detection Flow (US-DEVICE-006)

```
POST /v1/auth/login (E01 authentication)
  → DeviceService::resolveDevice()  [E01]
  → DeviceSecurityService::detectChange(device, submittedFingerprintHash)  [E03 — called from DeviceService]
      → If device.device_fingerprint != submittedHash and change_detection_enabled:
          → DeviceRepositoryInterface::updateStatus(id, 'pending_verification')
          → DeviceTrustHistoryRepository::append(device_id, old_status, 'pending_verification', ...)
          → DeviceSecurityEventRepository::create(change_detected, ...)
          → OutboxEventPublisher::publish(DeviceChangeDetectedEvent)
          → AuditService::record(identity.device.change-detected, WARN)
          → SuspiciousDeviceDetectionJob::dispatch(device_id, user_id)  [async]
  → E01 proceeds to OTP verification for demoted device
```

#### New Device Notification Flow (US-DEVICE-008)

```
Identity.Device.NewDeviceDetected (E01 outbox event)
  → HandleNewDeviceDetectedListener::handle(event)
      → DeviceSecurityEventRepository::create(new_device_notified, acknowledgment_expires_at)
      → OutboxEventPublisher::publish(DeviceNewDeviceNotificationSentEvent)
      → AuditService::record(identity.device.new-device-notified, INFO)
      → MetricsCollector::increment('device.new_device_notification.sent')
      [E17 receives DeviceNewDeviceNotificationSentEvent and delivers notification]

POST /v1/devices/{id}/acknowledge { acknowledged: false }
  → AcknowledgeNewDeviceHandler::handle()
      → DeviceSecurityService::processAcknowledgment()
          → Load DeviceSecurityEvent for device; check expires_at; check not already resolved
          → If acknowledged = false:
              → DeviceTrustService::blockDevice(...)  [full block flow above]
              → DeviceSecurityEventRepository::update(id, resolution: blocked, resolved_at)
              → OutboxEventPublisher::publish(DeviceNewDeviceDeniedEvent)
              → AuditService::record(identity.device.new-device-denied, WARN)
          → If acknowledged = true:
              → DeviceSecurityEventRepository::update(id, resolution: acknowledged, resolved_at)
              → OutboxEventPublisher::publish(DeviceNewDeviceAcknowledgedEvent)
              → AuditService::record(identity.device.new-device-acknowledged, INFO)
```

---

### 1.5 Authorization Pattern

E03 uses two authorization patterns matching E01 conventions:

**User-path IDOR guard** (in handler or service):
```php
if ($device->user_id !== $command->actorUserId) {
    throw new \App\Exceptions\ForbiddenException('DEVICE_FORBIDDEN');
}
```

**Admin-path actor type check** (in `AdminDeviceController`):
```php
if ($request->attributes->get('identity.actor_type') !== 'admin') {
    return response()->json(['error' => ['code' => 'FORBIDDEN']], 403);
}
// Then check named permission once E04 ships; for now, actor_type=admin is sufficient
```

---

### 1.6 Idempotency Integration

All state-changing E03 endpoints accept `Idempotency-Key` header. Pattern from E01:

```php
// In handler, before main logic:
$existing = $idempotency->findCompletedResponse($command->idempotencyKey, $command->actorUserId);
if ($existing !== null) {
    return $existing;
}

// ... main logic ...

// After success:
$idempotency->store($command->idempotencyKey, $command->actorUserId, $responseArray, 200);
```

---

### 1.7 DeviceRepositoryInterface Extensions (E03)

New methods added to `DeviceRepositoryInterface`:

```php
public function findByFingerprint(string $fingerprintHash): array;   // For multi-account detection
public function countForUser(string $userId): int;                    // For max-device check
public function listForUserPaginated(string $userId, int $page, int $perPage, ?string $status, ?string $platform): array;
public function blockDevice(string $id, string $blockedAt, ?string $blockedReason): void;
public function unblockDevice(string $id): void;
```

---

### 1.8 Metrics Extension

`IdentityMetrics` (existing `Identity/Domain/Services/IdentityMetrics.php`) is extended with E03 counters:

```php
public function incrementDeviceRegistered(string $platform): void;
public function incrementDeviceRemoved(string $actorType): void;
public function incrementDeviceBlocked(string $actorType): void;
public function incrementDeviceChangeDetected(): void;
public function incrementDeviceSuspiciousDetected(string $rule): void;
public function incrementNewDeviceNotificationSent(): void;
public function incrementNewDeviceNotificationAcknowledged(bool $acknowledged): void;
```

---

## Implementation Approach

1. **Database first**: Migrations + seed (Tasks T004–T007)
2. **Pre-conditions**: Fix E01 status inconsistency; extend `SessionRepository` (Tasks T001–T003)
3. **Domain/application layer**: Commands, handlers, services, events, exceptions (Tasks T008–T040)
4. **Persistence**: Models, repositories, `DeviceRepositoryInterface` extensions (Tasks T041–T050)
5. **API layer**: Controllers, request validation, route registration (Tasks T051–T065)
6. **E01 integration**: Middleware update, listener registration (Tasks T066–T072)
7. **Background jobs**: `SuspiciousDeviceDetectionJob` (Tasks T073–T075)
8. **Admin Next.js**: Device management screens (Tasks T076–T090)
9. **Flutter screens**: Customer + Agent device management (Tasks T091–T115)
10. **Tests**: Unit, integration, API, security, concurrency (Tasks T116–T160)
11. **Observability**: Metrics, structured logging, OpenAPI docs (Tasks T161–T170)

---

## Data / Financial Controls

E03 has no financial operations. The following non-financial integrity controls apply:

- Device status transitions are append-only in `identity.device_trust_history` — no UPDATE or DELETE
- Audit records in `platform.audit_events` are INSERT-only — E03 MUST NOT attempt UPDATE/DELETE
- Outbox inserts MUST be within the same DB transaction as the domain state change
- Concurrent block/removal serialized by `FOR UPDATE` lock on `identity.devices.id`

### Cross-Schema Transaction Boundary

All named Laravel database connections (`identity`, `platform`, and others) are configured by `pgsql_config()` in `config/database.php` to use the **same PostgreSQL host, port, and database** (`wallet_ecosystem`). They differ only in `search_path` (schema). In the monolith phase each named connection uses a dedicated PDO handle.

The established E01 pattern — carried forward unchanged in E03 — is:

- `DB::connection('platform')->transaction()` is the outer wrapper for each mutating operation.
- Platform-schema writes (`platform.outbox_events`, `platform.audit_events`) execute through the platform PDO handle and are **atomically committed or rolled back** together by that transaction.
- Identity-schema writes (device status in `identity.devices`, session status in `identity.sessions`, trust history in `identity.device_trust_history`) execute through Eloquent models whose `$connection = 'identity'`. These writes go through the **identity PDO handle** and are **not** enrolled in the platform transaction; they auto-commit as they occur.

**Practical consequence**: if an identity write (e.g., `revokeAllForDevice()`) throws an exception before the platform transaction commits, the platform transaction rolls back — no orphaned outbox event or audit record is written. Identity writes that already executed remain committed. This is consistent with the existing `SessionService::revokeAllForUser()` and `SessionService::revokeAllForDevice()` pattern in E01 (verified in `app/Modules/Identity/Domain/Services/SessionService.php`).

**Implication for spec §16 edge case** ("Device block MUST NOT be committed if session revocation cannot complete"): in this implementation, a `revokeAllForDevice()` exception propagates out of the platform transaction closure, causes the platform rollback (event/audit not written), and surfaces as a `500 INTERNAL_ERROR` to the caller — satisfying the spec intent. The device status row written before the exception (if any) remains committed in the identity schema; this is an edge case the caller must retry.

**ADR required** before changing this connection-boundary model (e.g., switching to a single shared connection or a two-phase commit strategy).

---

## Observability

All E03 requests carry `X-Correlation-ID`. Structured logs follow the `module.action.result` pattern (e.g., `device.block.success`, `device.suspicious_detected.emulator`). PII (email, phone) MUST be masked in all logs using the Platform Foundation `PiiMasker`. Risk signals in `device_security_events.risk_signals` MUST NOT appear in logs at INFO level.

---

## Complexity Tracking

No approved stack changes. No new external dependencies required beyond what E01 already uses. Any deviation requires an ADR and constitution check update before implementation proceeds.
