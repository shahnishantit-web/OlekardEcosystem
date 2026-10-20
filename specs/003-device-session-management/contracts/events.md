# Event Contracts — E03 Device & Session Management

**Pattern**: Platform Foundation outbox (`platform.outbox_events`)
**Naming convention**: `{Module}.{Aggregate}.{Verb}` (past tense)
**Envelope**: All events implement `IntegrationEventInterface`

---

## Event Envelope (all events)

Every E03 event includes these fields in `metadata()`:

```json
{
  "correlation_id": "{uuid}",
  "causation_id": "{uuid}",
  "event_version": "1.0.0",
  "actor_id": "{uuid}",
  "actor_type": "USER | ADMIN | SYSTEM",
  "occurred_at": "2026-09-02T14:00:00Z"
}
```

`aggregate_type()` for all E03 events: `identity.device`
`aggregate_id()`: the `device_id` UUID

---

## Events Published by E03

### Device.Device.Registered

**Trigger**: `POST /v1/devices` — new device registered
**Consumers**: E17 (optional notification), E26 (security monitoring)

```json
{
  "event_name": "Device.Device.Registered",
  "payload": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002",
    "platform": "android",
    "device_name": "My Pixel 9",
    "status": "pending_verification"
  }
}
```

---

### Device.Trust.Promoted

**Trigger**: `POST /v1/devices/{id}/trust` — device marked trusted
**Consumers**: E17 (optional confirmation notification)

```json
{
  "event_name": "Device.Trust.Promoted",
  "payload": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002",
    "platform": "android",
    "trusted_at": "2026-09-02T14:45:00Z"
  }
}
```

---

### Device.Device.Removed

**Trigger**: `DELETE /v1/devices/{id}` or `DELETE /v1/admin/devices/{id}`
**Consumers**: E17 (notification), E26

```json
{
  "event_name": "Device.Device.Removed",
  "payload": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002",
    "platform": "android",
    "sessions_revoked": 2,
    "removed_by_admin": false,
    "admin_actor_id": null
  }
}
```

---

### Device.Device.ChangeDetected

**Trigger**: Fingerprint drift detected during authentication (US-DEVICE-006)
**Consumers**: E17 (security alert), E18 (risk signal), E26

```json
{
  "event_name": "Device.Device.ChangeDetected",
  "payload": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002",
    "platform": "android",
    "previous_status": "trusted",
    "new_status": "pending_verification",
    "previous_fingerprint_hash": "abc123...",
    "new_fingerprint_hash": "def456..."
  }
}
```

---

### Device.Device.SuspiciousDetected

**Trigger**: Suspicious device pattern detected (US-DEVICE-007)
**Consumers**: E18 (risk signal), E26 (security alert)
**IMPORTANT**: `risk_signals` in payload is internal only. API responses NEVER include this data.

```json
{
  "event_name": "Device.Device.SuspiciousDetected",
  "payload": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002",
    "platform": "android",
    "risk_signals": {
      "rules_fired": ["multi_account_fingerprint"],
      "multi_account_count": 5,
      "account_ids": ["uuid1", "uuid2", "uuid3", "uuid4", "uuid5"]
    }
  }
}
```

---

### Device.Device.NewDeviceNotificationSent

**Trigger**: `Identity.Device.NewDeviceDetected` consumed; notification dispatched to E17
**Consumers**: None (informational marker event)

```json
{
  "event_name": "Device.Device.NewDeviceNotificationSent",
  "payload": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002",
    "platform": "android",
    "device_name": "Unknown Device",
    "acknowledgment_expires_at": "2026-09-03T14:00:00Z"
  }
}
```

---

### Device.Device.NewDeviceAcknowledged

**Trigger**: `POST /v1/devices/{id}/acknowledge` with `acknowledged: true`
**Consumers**: None

```json
{
  "event_name": "Device.Device.NewDeviceAcknowledged",
  "payload": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002"
  }
}
```

---

### Device.Device.NewDeviceDenied

**Trigger**: `POST /v1/devices/{id}/acknowledge` with `acknowledged: false`
**Consumers**: E18 (risk signal — user denied unknown device), E26

```json
{
  "event_name": "Device.Device.NewDeviceDenied",
  "payload": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002",
    "platform": "android",
    "sessions_revoked": 1
  }
}
```

---

### Device.Device.Blocked

**Trigger**: `POST /v1/devices/{id}/block` (user or admin path)
**Consumers**: E17 (notification to user), E26

```json
{
  "event_name": "Device.Device.Blocked",
  "payload": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002",
    "platform": "android",
    "blocked_by_admin": false,
    "admin_actor_id": null,
    "reason": "Device appears compromised",
    "sessions_revoked": 2
  }
}
```

---

### Device.Device.Unblocked

**Trigger**: `POST /v1/admin/devices/{id}/unblock`
**Consumers**: E17 (notification to user that device unblocked)

```json
{
  "event_name": "Device.Device.Unblocked",
  "payload": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002",
    "admin_actor_id": "019401cc-0000-7000-0000-000000000003",
    "reason": "Owner confirmed device is secure"
  }
}
```

---

## Events Consumed by E03

### Identity.Device.NewDeviceDetected (from E01)

**Producer**: E01 `DeviceService::resolveDevice()` → `NewDeviceDetectedEvent`
**Listener**: `HandleNewDeviceDetectedListener`

Payload (published by E01 to `platform.outbox_events`):
```json
{
  "event_name": "Identity.Device.NewDeviceDetected",
  "payload": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002",
    "device_name": "Unknown Device",
    "platform": "android"
  }
}
```

E03 listener action:
1. Create `identity.device_security_events` record with `event_type = new_device_notified`, set `acknowledgment_expires_at`
2. Publish `Device.Device.NewDeviceNotificationSent` (E17 picks this up for delivery)
3. Write audit record

Idempotency: Listener checks for existing `device_security_events` record with same `device_id` and `event_type = new_device_notified` to prevent duplicate processing.

---

### Identity.Session.Created (from E01)

**Producer**: E01 `DeviceService::verifyDeviceByOtp()` → `SessionCreatedEvent`
**Listener**: `HandleSessionCreatedListener`

Payload:
```json
{
  "event_name": "Identity.Session.Created",
  "payload": {
    "session_id": "019401dd-0000-7000-0000-000000000004",
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "user_id": "019401bb-0000-7000-0000-000000000002"
  }
}
```

E03 listener action:
- `DeviceRepositoryInterface::updateLastSeen($deviceId)` — updates `identity.devices.last_seen_at`
- No event emitted, no audit record (high-frequency; performance-sensitive)

Idempotency: Last-seen update is idempotent by nature (always sets to NOW()).
