# Research — E03 Device & Session Management

**Purpose**: Resolve all 4 open TBDs from the specification before implementation begins.
**Date**: 2026-09-02
**Based on**: codebase exploration of `wallet-ecosystem/services/api/app/Modules/Identity/` and Platform Foundation modules.

---

## TBD-E03-001: Device Fingerprint Attribute Schema

**Decision**: Platform-specific attribute sets, server-side normalized, then SHA-256 hashed.

The server receives a JSON `attributes` map from the client. The API does NOT prescribe a strict schema — it accepts any map and applies platform-specific normalization rules server-side. The server deterministically sorts keys and values, removes null/undefined fields, and hashes the resulting canonical JSON string using SHA-256.

| Platform | Required Attributes | Optional Attributes |
|----------|---------------------|---------------------|
| `ios` | `os_version`, `hardware_model`, `idfv` (Identifier for Vendor) | `screen_resolution`, `locale` |
| `android` | `os_version`, `hardware_model`, `android_id` | `screen_resolution`, `locale`, `build_fingerprint` |
| `web` | `user_agent`, `timezone` | `screen_resolution`, `language`, `platform` |

**Entropy calculation**: Shannon entropy computed on the normalized attribute set. Minimum threshold: `device.fingerprint_min_entropy_bits` (default 32 bits). Submissions below threshold are rejected with `400 INSUFFICIENT_ENTROPY`.

**Emulator detection heuristics** (Android):
- `android_id == "0000000000000000"` → likely emulator
- `hardware_model` contains `"sdk"`, `"emulator"`, or `"generic"` (case-insensitive) → likely emulator

**Emulator detection heuristics** (iOS):
- `hardware_model == "x86_64"` or `"i386"` → simulator
- `idfv == "00000000-0000-0000-0000-000000000000"` → simulator

**Rationale**: Deferring attribute schema to the server means mobile clients submit what they can collect and the server applies validation. The hash is the stable identifier; schema evolution does not break stored hashes if attributes are added (old fingerprints remain valid). Emulator detection is a side-channel input to suspicious detection (US-DEVICE-007) and does not block fingerprint generation.

**Alternatives considered**:
- Strict per-platform schema with client-side enforcement → brittle for OS-version variation
- Client-side hashing → defeats server entropy validation and emulator detection

---

## TBD-E03-002: Session Revocation Coordination Mechanism

**Decision**: Synchronous in-process call via extended `SessionService.revokeAllForDevice()` within the same DB transaction.

`SessionRepositoryInterface` (E01) does not have `revokeAllForDevice()`. E03 adds this method:

```php
// SessionRepositoryInterface — extended by E03:
public function listActiveForDevice(string $deviceId): array;  // returns Session[]
public function revokeAllForDevice(string $deviceId): int;     // returns count of revoked sessions
```

E03's `DeviceTrustService` (domain service) injects `SessionService` directly (both in the `Identity` module). The `blockDevice()` and `removeDevice()` operations call `SessionService::revokeAllForDevice()` synchronously within the same `platform` DB transaction:

```
DB::connection('platform')->transaction(function() {
    // 1. Update identity.devices.status = blocked
    // 2. Append identity.device_trust_history
    // 3. SessionService::revokeAllForDevice(deviceId)  ← synch, same tx
    // 4. Publish outbox events
    // 5. AuditService::record()
});
// 6. After tx: Redis eviction for each revoked session_id
```

If session revocation throws, the entire transaction rolls back. The device `status` is NOT changed. E03 returns `500 INTERNAL_ERROR`; the client retries with the same `Idempotency-Key`.

**Rationale**: Synchronous in-process call within the Identity bounded context maintains atomicity without message bus coupling. Both `identity.devices` and `identity.sessions` are in the same PostgreSQL server; a single connection can transact across both tables. Event-driven session revocation would introduce eventual consistency and require a saga pattern, which is excessive for this operation.

**Alternatives considered**:
- Event-driven command via Horizon job → eventual consistency, partial block window
- Direct `identity.sessions` UPDATE from E03 without going through `SessionService` → bypasses Redis eviction, breaks revocation cache

---

## TBD-E03-003: Suspicious Device Detection Rule Set

**Decision**: Three configurable rules implemented in `DeviceSecurityService`; thresholds from `ConfigurationService`.

### Rule 1 — Multi-account fingerprint

**Trigger**: A device fingerprint hash is associated with more than `device.max_accounts_per_fingerprint` distinct `user_id` values in `identity.devices`.

**Implementation**: Query `identity.devices` for `device_fingerprint = $hash GROUP BY user_id HAVING COUNT(DISTINCT user_id) > threshold`. Runs at registration and fingerprint-generation time.

### Rule 2 — Emulator/virtual device indicators

**Trigger**: Platform-specific heuristics detected in the `attributes` map at fingerprint generation time (see TBD-E03-001 emulator heuristics above).

**Implementation**: `DeviceFingerprintService::detectEmulatorIndicators(array $attributes, string $platform): bool`. Returns `true` if any heuristic matches.

### Rule 3 — Abnormal device change rate

**Trigger**: The `user_id` has more than `device.max_device_changes_per_window` change-detection entries in `identity.device_security_events` within the last `device.suspicious_change_window_hours` hours.

**Implementation**: Query `identity.device_security_events WHERE user_id = $uid AND event_type = 'change_detected' AND created_at > NOW() - INTERVAL config_hours`. Runs after each `change_detected` event.

### Detection flow

When any rule fires:
1. Record `identity.device_security_events` with `event_type = suspicious_detected` and `risk_signals` JSON (contains which rule fired and the triggering data).
2. Emit `Device.Device.SuspiciousDetected` via outbox. Payload includes `risk_signals` for E18; `risk_signals` is NEVER returned in API responses.
3. Write audit record with `WARN` severity.

Detection runs as a side-channel — the authentication or registration response does NOT change.

**Background job `SuspiciousDeviceDetectionJob`**: For rule 3 (change-rate anomaly), detection is triggered asynchronously after change detection commits. Job dispatched to Horizon queue.

**Rationale**: Rules are parameter-driven and toggled individually via `ConfigurationService`, not hard-coded. The `risk_signals` payload is opaque to clients; internal only. E18 applies actual risk scoring.

---

## TBD-E03-004: E01 Authentication Middleware Integration Approach

**Decision**: E03 provides `DeviceBlockCheckServiceInterface` in the Identity domain contracts; E01's `IdentityAuthMiddleware` injects and calls it.

**New interface** added to `Identity/Domain/Contracts/DeviceBlockCheckServiceInterface.php`:

```php
interface DeviceBlockCheckServiceInterface
{
    public function isBlocked(string $deviceId): bool;
}
```

**Implementation** in `Identity/Domain/Services/DeviceBlockCheckService.php`:

```php
final class DeviceBlockCheckService implements DeviceBlockCheckServiceInterface
{
    public function __construct(private readonly DeviceRepositoryInterface $devices) {}

    public function isBlocked(string $deviceId): bool
    {
        $device = $this->devices->findById($deviceId);
        return $device !== null && $device->status === 'blocked';
    }
}
```

**Middleware update** (`IdentityAuthMiddleware`):

```php
// After session validation, before request proceeds:
if ($deviceId = $request->attributes->get('identity.device_id')) {
    if ($this->deviceBlockCheck->isBlocked($deviceId)) {
        return response()->json(['error' => ['code' => 'DEVICE_BLOCKED']], 403);
    }
}
```

**Registered** in `IdentityServiceProvider`:
```php
$this->app->bind(DeviceBlockCheckServiceInterface::class, DeviceBlockCheckService::class);
```

**Rationale**: The interface lives in the Identity domain contracts layer (not E03-specific), so `IdentityAuthMiddleware` (E01) depends on the abstraction. E03 provides the implementation. When the Identity module is extracted as a standalone service, both sides of the boundary are explicit. Direct `DeviceRepository::findById()` in the middleware would be simpler but would make the check implementation-coupled to the repository type.

**Alternatives considered**:
- `IdentityAuthMiddleware` reads `identity.devices.status` directly via `DeviceRepositoryInterface` → simpler but bypasses the service boundary
- Feature-flag-gated middleware patch → unnecessary complexity; E03 ships the check as a standard extension

---

## Codebase Patterns Summary

These patterns MUST be followed in all E03 implementation:

| Pattern | Implementation |
|---------|---------------|
| Command objects | `final readonly class XxxCommand` (see `RegisterDeviceCommand.php`) |
| UUID generation | `Uuid::uuid7()` (ramsey/uuid) |
| Audit records | `AuditService::record(new AuditEventData(...))` — INSERT-only |
| Event publishing | `OutboxEventPublisher::publish()` — MUST be inside active DB transaction |
| DB transaction | `DB::connection('platform')->transaction(...)` for cross-module writes |
| Redis cache | `Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)` |
| Configuration | `ConfigurationService::getOrDefault(string $key, mixed $default)` |
| Idempotency | `IdempotencyService::findCompletedResponse()` before handler; `store()` after |
| Migration naming | `YYYY_MM_DD_NNNNNN_description.php` (see existing E01 migrations) |
| Routes | `routes/modules/identity.php` (loaded by `IdentityServiceProvider::boot()`) |
| Status inconsistency fix | E01 `DeviceService::resolveDevice()` uses `'unverified'`; `DeviceController::register()` uses `'pending'`; migration default is `'pending_verification'`. E03 must use `'pending_verification'` (migration default). This inconsistency in E01 should be flagged as a prerequisite fix. |

**Status inconsistency in E01 (pre-condition for E03)**:
- E01 `DeviceService::resolveDevice()` sets `status = 'unverified'`
- E01 `DeviceController::register()` sets `status = 'pending'`
- E01 migration default: `status = 'pending_verification'`

E03 depends on the canonical `pending_verification` status. Before E03 is shipped, E01 must be normalized to `pending_verification` across `DeviceService` and `DeviceController`. This is a blocking prerequisite for E03 (Task T001 in tasks.md).
