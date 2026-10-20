# Data Model — E03 Device & Session Management

**Feature**: `003-device-session-management` | **Schema**: `identity` (PostgreSQL)

---

## Overview

E03 extends the `identity` schema (no new schema created). Three changes:
1. **ALTER** `identity.devices` — add `blocked_at`, `blocked_reason`; add `blocked` status value
2. **CREATE** `identity.device_trust_history` — append-only trust transition log
3. **CREATE** `identity.device_security_events` — security detection events (change, suspicious, notification)

A fourth change updates `platform.configuration_values` via idempotent migration seed.

---

## Entity 1: `identity.devices` (Extended by E03)

**Existing table** (created by E01 migration `2026_08_21_004000_create_identity_devices_table.php`). E03 adds two nullable columns and the `blocked` status value.

### E03 Migration Additions

```sql
ALTER TABLE identity.devices
    ADD COLUMN blocked_at     TIMESTAMPTZ     NULL,
    ADD COLUMN blocked_reason VARCHAR(255)    NULL;
```

No DEFAULT. Nullable. Existing rows are unaffected. Zero-downtime migration in PostgreSQL.

### Full Column Inventory (after E03)

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7; generated at creation |
| `user_id` | UUID | NOT NULL | References `identity.users.id` (same schema) |
| `device_fingerprint` | VARCHAR(64) | NOT NULL | SHA-256 hash of device attributes; never exposed in responses |
| `device_name` | VARCHAR(100) | NOT NULL | Human-readable device label |
| `platform` | VARCHAR(20) | NOT NULL | `ios`, `android`, `web` |
| `status` | VARCHAR(30) | NOT NULL, DEFAULT `pending_verification` | See status values below |
| `trusted_at` | TIMESTAMPTZ | NULL | When device was promoted to `trusted` |
| `last_seen_at` | TIMESTAMPTZ | NULL | Updated on each `Identity.Session.Created` event |
| `blocked_at` | TIMESTAMPTZ | NULL | **E03 new** — when device was blocked; NULL if not blocked |
| `blocked_reason` | VARCHAR(255) | NULL | **E03 new** — reason for block; NULL if not blocked |
| `created_at` | TIMESTAMPTZ | NOT NULL, DEFAULT `now()` | |
| `updated_at` | TIMESTAMPTZ | NOT NULL, DEFAULT `now()` | |

### Status Values (Updated by E03)

| Status | Set by | Meaning |
|--------|--------|---------|
| `pending_verification` | E01 (new device detected); E03 (change detection demotes) | Awaiting OTP verification |
| `trusted` | E01 (OTP verified); E03 (trust promotion, admin unblock) | Verified and trusted |
| `revoked` | E01 (session revocation); E03 (device removal) | De-registered |
| `blocked` | **E03 new** (user block, admin block, new-device denial) | Permanently denied authentication |

**Status transition rules** (enforced at service layer):
- `pending_verification → trusted` — via OTP verification (E01) or explicit trust (E03)
- `trusted → revoked` — via device removal (E03)
- `trusted → blocked` — via device block (E03)
- `pending_verification → blocked` — via new-device denial (E03)
- `blocked → trusted` — admin unblock only (E03; self-service forbidden)
- `blocked → revoked` — via device removal (E03; permitted)
- Any status → `pending_verification` — via change detection demotion (E03)

### Indexes (Existing)

```sql
-- From E01 migration:
UNIQUE (user_id, device_fingerprint)
INDEX (user_id)  -- for listForUser queries
```

### Eloquent Model

**File**: `Infrastructure/Models/Device.php` (existing — E03 adds blocked_at, blocked_reason to fillable/casts)

```php
protected $fillable = [...existing..., 'blocked_at', 'blocked_reason'];
protected $hidden   = ['device_fingerprint'];  // NEVER in API responses
protected $casts    = [
    'trusted_at'  => 'immutable_datetime',
    'last_seen_at'=> 'immutable_datetime',
    'blocked_at'  => 'immutable_datetime',   // E03 new
    'created_at'  => 'immutable_datetime',
    'updated_at'  => 'immutable_datetime',
];
```

---

## Entity 2: `identity.device_trust_history` (New in E03)

Append-only log of all device trust and status transitions. No UPDATE or DELETE — the table is an immutable audit trail of status changes specifically for device state machines.

### DDL

```sql
CREATE TABLE identity.device_trust_history (
    id              UUID            PRIMARY KEY DEFAULT uuid_generate_v7(),
    device_id       UUID            NOT NULL,   -- identity.devices.id (no FK — same schema, value reference)
    user_id         UUID            NOT NULL,   -- identity.users.id (value reference)
    from_status     VARCHAR(30)     NULL,       -- NULL for initial entry (device first created)
    to_status       VARCHAR(30)     NOT NULL,
    actor_id        UUID            NOT NULL,   -- User UUID, admin UUID, or system UUID
    actor_type      VARCHAR(20)     NOT NULL,   -- 'USER' | 'ADMIN' | 'SYSTEM'
    reason          TEXT            NULL,       -- Required for block ops; optional otherwise
    correlation_id  UUID            NOT NULL,   -- From X-Correlation-ID header
    created_at      TIMESTAMPTZ     NOT NULL    DEFAULT now()
    -- No updated_at — append-only
);
```

### Indexes

```sql
CREATE INDEX idx_device_trust_history_device ON identity.device_trust_history (device_id, created_at DESC);
CREATE INDEX idx_device_trust_history_user   ON identity.device_trust_history (user_id, created_at DESC);
```

### Field Details

| Field | Required | Notes |
|-------|----------|-------|
| `from_status` | No | NULL on first history entry (device creation); previous status string on all subsequent entries |
| `reason` | Conditional | MUST be non-null for `to_status = blocked`; optional for all other transitions |
| `actor_type` | Yes | `USER` for self-service; `ADMIN` for admin operations; `SYSTEM` for automated operations (change detection, suspicious detection auto-block) |

### Eloquent Model

**File**: `Infrastructure/Models/DeviceTrustHistory.php` (new)

```php
namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class DeviceTrustHistory extends Model
{
    protected $connection = 'identity';
    protected $table      = 'identity.device_trust_history';
    public    $timestamps = false;  // Only created_at; managed manually

    protected $fillable = [
        'id', 'device_id', 'user_id', 'from_status', 'to_status',
        'actor_id', 'actor_type', 'reason', 'correlation_id', 'created_at',
    ];
    protected $casts = ['created_at' => 'immutable_datetime'];
}
```

### Repository Interface

**File**: `Domain/Contracts/DeviceTrustHistoryRepositoryInterface.php`

```php
interface DeviceTrustHistoryRepositoryInterface
{
    public function append(array $attributes): DeviceTrustHistory;
    /** @return DeviceTrustHistory[] */
    public function listForDevice(string $deviceId): array;
}
```

---

## Entity 3: `identity.device_security_events` (New in E03)

Records device-level security detection events: fingerprint change detection, suspicious detection, new device notifications and their acknowledgment lifecycle.

### DDL

```sql
CREATE TABLE identity.device_security_events (
    id                          UUID            PRIMARY KEY DEFAULT uuid_generate_v7(),
    device_id                   UUID            NULL,       -- NULL if device not yet registered at detection time
    user_id                     UUID            NOT NULL,
    event_type                  VARCHAR(50)     NOT NULL,   -- see event_type values below
    fingerprint_hash            VARCHAR(64)     NULL,       -- fingerprint hash at detection time
    previous_fingerprint_hash   VARCHAR(64)     NULL,       -- prior hash (change_detected only)
    risk_signals                JSONB           NULL,       -- internal only; NEVER in API responses
    resolution                  VARCHAR(30)     NULL,       -- see resolution values below
    resolved_at                 TIMESTAMPTZ     NULL,
    acknowledgment_expires_at   TIMESTAMPTZ     NULL,       -- for new_device_notified events
    correlation_id              UUID            NOT NULL,
    created_at                  TIMESTAMPTZ     NOT NULL    DEFAULT now(),
    updated_at                  TIMESTAMPTZ     NOT NULL    DEFAULT now()
);
```

### Indexes

```sql
CREATE INDEX idx_device_security_events_user_type
    ON identity.device_security_events (user_id, event_type, created_at DESC);

CREATE INDEX idx_device_security_events_device_type
    ON identity.device_security_events (device_id, event_type)
    WHERE device_id IS NOT NULL;
```

### `event_type` Values

| Value | Trigger | Resolution path |
|-------|---------|-----------------|
| `change_detected` | Fingerprint drift on known device | No resolution; informational + suspicious detection |
| `suspicious_detected` | Suspicious pattern rule fired | No resolution; signal to E18 |
| `new_device_notified` | `Identity.Device.NewDeviceDetected` received; notification sent | `acknowledged`, `blocked`, `expired` |

### `resolution` Values

| Value | Meaning |
|-------|---------|
| `acknowledged` | User confirmed "This was me" |
| `blocked` | User denied "This wasn't me" → device blocked |
| `expired` | TTL window passed with no response |
| NULL | No resolution required (change_detected, suspicious_detected) or pending |

### `risk_signals` JSONB Schema

For `suspicious_detected` events (internal only; NEVER in API responses):

```json
{
  "rules_fired": ["multi_account_fingerprint" | "emulator_indicator" | "abnormal_change_rate"],
  "multi_account_count": 5,
  "account_ids": ["uuid1", "uuid2"],   // Other user_ids sharing the fingerprint
  "emulator_indicators": ["android_id_zero"],
  "change_count_in_window": 4,
  "window_hours": 24
}
```

For `change_detected` events:
```json
{
  "previous_fingerprint_hash": "abc123...",
  "new_fingerprint_hash": "def456..."
}
```

### Field Notes

- `fingerprint_hash` and `previous_fingerprint_hash` store the SHA-256 hash only — raw attributes are NEVER stored anywhere
- `risk_signals` MUST NOT appear in any API response. It is populated on write and read only by internal services and admin tooling
- `acknowledgment_expires_at` is set to `NOW() + device.new_device_acknowledgment_ttl` seconds when event_type = `new_device_notified`

### Eloquent Model

**File**: `Infrastructure/Models/DeviceSecurityEvent.php` (new)

```php
namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class DeviceSecurityEvent extends Model
{
    protected $connection = 'identity';
    protected $table      = 'identity.device_security_events';

    protected $fillable = [
        'id', 'device_id', 'user_id', 'event_type',
        'fingerprint_hash', 'previous_fingerprint_hash', 'risk_signals',
        'resolution', 'resolved_at', 'acknowledgment_expires_at',
        'correlation_id',
    ];
    protected $casts = [
        'risk_signals'             => 'array',
        'resolved_at'              => 'immutable_datetime',
        'acknowledgment_expires_at'=> 'immutable_datetime',
        'created_at'               => 'immutable_datetime',
        'updated_at'               => 'immutable_datetime',
    ];
    // risk_signals excluded from default API serialization via Resource class
}
```

---

## Configuration Seed (platform.configuration_values)

Migration `2026_09_02_004000_seed_device_configuration_defaults.php`:

```sql
INSERT INTO platform.configuration_values (key, value, value_type, description, is_sensitive)
VALUES
  ('device.max_devices_per_user',             '10',     'number',  'Maximum registered devices per user',                                        false),
  ('device.new_device_notification_enabled',  'true',   'boolean', 'Send notification on new device registration',                               false),
  ('device.new_device_acknowledgment_ttl',    '86400',  'number',  'Seconds user has to acknowledge a new device (default 24h)',                  false),
  ('device.change_detection_enabled',         'true',   'boolean', 'Enable fingerprint change detection on known devices',                        false),
  ('device.suspicious_detection_enabled',     'true',   'boolean', 'Enable suspicious device pattern detection',                                  false),
  ('device.fingerprint_min_entropy_bits',     '32',     'number',  'Minimum entropy bits required for a valid device fingerprint',                false),
  ('device.max_accounts_per_fingerprint',     '3',      'number',  'Maximum accounts sharing a fingerprint before suspicious detection fires',    false),
  ('device.max_device_changes_per_window',    '3',      'number',  'Max fingerprint changes per user per rolling window',                         false),
  ('device.suspicious_change_window_hours',   '24',     'number',  'Rolling window (hours) for device-change anomaly counting',                   false)
ON CONFLICT (key) DO NOTHING;  -- Idempotent; does not overwrite runtime-changed values
```

---

## Relationship Diagram

```
identity.users (E01)
  ↑ user_id (value ref)
  │
identity.devices (E01, extended by E03)
  ├── id (uuid v7, PK)
  ├── user_id (FK → identity.users.id)
  ├── device_fingerprint (sha256, hidden)
  ├── status: pending_verification | trusted | revoked | blocked
  ├── blocked_at, blocked_reason (E03 new)
  │
  ├──→ identity.device_trust_history (E03 new, append-only)
  │       device_id, user_id, from_status, to_status, actor_id, actor_type, reason, correlation_id
  │
  └──→ identity.device_security_events (E03 new)
          device_id, user_id, event_type, fingerprint_hash, previous_fingerprint_hash,
          risk_signals (internal only), resolution, acknowledgment_expires_at

identity.sessions (E01)
  ├── device_id (FK → identity.devices.id)
  └── status: active | logged_out | expired | revoked
        ← revokeAllForDevice() added to SessionRepositoryInterface by E03
```

---

## DeviceRepositoryInterface Extensions

These methods are added to the existing `Domain/Contracts/DeviceRepositoryInterface.php` by E03:

```php
/** Find all devices with the given fingerprint hash (across all users) */
public function findByFingerprint(string $fingerprintHash): array;

/** Count registered devices for a user (for max-device enforcement) */
public function countForUser(string $userId): int;

/** Paginated list with optional filters */
public function listForUserPaginated(
    string  $userId,
    int     $page,
    int     $perPage,
    ?string $status   = null,
    ?string $platform = null,
): array;

/** Set status=blocked, blocked_at, blocked_reason atomically */
public function blockDevice(string $id, string $blockedAt, ?string $blockedReason): void;

/** Restore device to trusted status; clear blocked_at, blocked_reason */
public function unblockDevice(string $id): void;
```

## SessionRepositoryInterface Extensions

These methods are added to the existing `Domain/Contracts/SessionRepositoryInterface.php` by E03:

```php
/** List all active sessions for a given device */
public function listActiveForDevice(string $deviceId): array;

/** Revoke all active sessions for a device; returns count of revoked sessions */
public function revokeAllForDevice(string $deviceId): int;
```
