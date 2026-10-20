# Quickstart Validation Guide — E03 Device & Session Management

**Purpose**: Prove the feature works end-to-end before shipping. Runnable scenarios for local and staging environments.
**Prerequisites**: E01 and E02 implementations deployed; API running at `http://localhost:8000`; admin app running at `http://localhost:3001`.

---

## Environment Setup

```bash
# From wallet-ecosystem/services/api/
php artisan migrate        # Run E03 migrations
php artisan db:seed --class=DeviceConfigurationSeeder   # Seed configuration defaults
php artisan horizon        # Start queue worker for background jobs
```

### Seed a test user and admin

```bash
# User for device tests (uses existing E01 seeded user)
# Email: test@olekard.local / Password: Test123!
# Admin: admin@olekard.local / Admin123! (seeded in E01)
```

### Required test state

Before running scenarios, obtain:
- `$USER_TOKEN` — Bearer token for test user (login via `POST /v1/auth/login`)
- `$ADMIN_TOKEN` — Bearer token for admin user

---

## Scenario 1: Complete Device Registration and Trust Flow (AC-001, AC-004, AC-005, AC-006)

### Step 1.1 — Generate device fingerprint

```bash
curl -s -X POST http://localhost:8000/v1/devices/fingerprint \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "platform": "android",
    "attributes": {
      "os_version": "14",
      "hardware_model": "Pixel 9",
      "android_id": "a1b2c3d4e5f67890",
      "screen_resolution": "1080x2400"
    }
  }'
```

**Expected**: `200 OK` with `{ "data": { "fingerprint_hash": "64-char-hex-string" } }`
**Verify**: Response contains `fingerprint_hash`; no `attributes` echoed back; `platform.audit_events` has `identity.device.fingerprint-generated` record.

### Step 1.2 — Register device (save `device_id` and `challenge_id`)

```bash
FINGERPRINT_HASH="..." # from Step 1.1

curl -s -X POST http://localhost:8000/v1/devices \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d "{
    \"device_name\": \"Test Pixel 9\",
    \"platform\": \"android\",
    \"fingerprint_hash\": \"$FINGERPRINT_HASH\"
  }"
```

**Expected**: `201 Created` with `{ "data": { "device_id": "...", "status": "pending_verification", "challenge_id": "..." } }`
**Verify**:
- `identity.devices` has new row with `status = pending_verification`
- `identity.device_trust_history` has entry with `to_status = pending_verification`
- `platform.outbox_events` has `Device.Device.Registered` event
- `platform.audit_events` has `identity.device.registered` record

### Step 1.3 — View device in list

```bash
curl -s http://localhost:8000/v1/devices \
  -H "Authorization: Bearer $USER_TOKEN"
```

**Expected**: Device appears in list with `status: pending_verification`. No `device_fingerprint` field in response.

### Step 1.4 — Mark device as trusted (trust promotion)

```bash
DEVICE_ID="..." # from Step 1.2

curl -s -X POST http://localhost:8000/v1/devices/$DEVICE_ID/trust \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"reason": "Manual trust for testing"}'
```

**Expected**: `200 OK` with `{ "data": { "status": "trusted", "trusted_at": "..." } }`
**Verify**:
- `identity.devices.status = trusted` and `trusted_at` set
- `identity.device_trust_history` has entry `from: pending_verification → to: trusted`
- `platform.outbox_events` has `Device.Trust.Promoted`
- `platform.audit_events` has `identity.device.trusted`

---

## Scenario 2: Idempotency Verification (AC-002)

### Step 2.1 — Register same device twice with same Idempotency-Key

```bash
IDEM_KEY=$(uuidgen)
FINGERPRINT_HASH="..." # Same hash from Scenario 1

curl -s -X POST http://localhost:8000/v1/devices \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -H "Content-Type: application/json" \
  -d '{"device_name": "Test Device", "platform": "android", "fingerprint_hash": "'$FINGERPRINT_HASH'"}'

curl -s -X POST http://localhost:8000/v1/devices \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Idempotency-Key: $IDEM_KEY" \
  -H "Content-Type: application/json" \
  -d '{"device_name": "Test Device", "platform": "android", "fingerprint_hash": "'$FINGERPRINT_HASH'"}'
```

**Expected**: Both calls return `200 OK` with the same `device_id`. Only ONE record in `identity.devices`. Only ONE audit record in `platform.audit_events` for `identity.device.registered`.

---

## Scenario 3: Device Limit Enforcement (AC-003)

```bash
# Configure limit to 1 for testing:
# UPDATE platform.configuration_values SET value = '1' WHERE key = 'device.max_devices_per_user';

# Register first device (succeeds)
curl -s -X POST http://localhost:8000/v1/devices \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"device_name": "Device 1", "platform": "android", "fingerprint_hash": "abc..."}'

# Register second device (fails)
curl -s -X POST http://localhost:8000/v1/devices \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"device_name": "Device 2", "platform": "ios", "fingerprint_hash": "def..."}'
```

**Expected**: Second call returns `409 DEVICE_LIMIT_EXCEEDED`.

---

## Scenario 4: IDOR Prevention (AC-007)

```bash
# Obtain a second user's token (USER_B_TOKEN)
# Attempt to access USER_A's device using USER_B's token

DEVICE_A_ID="..." # device belonging to User A

curl -s http://localhost:8000/v1/devices/$DEVICE_A_ID \
  -H "Authorization: Bearer $USER_B_TOKEN"
```

**Expected**: `403 FORBIDDEN` with code `DEVICE_FORBIDDEN`. NOT `404`.

```bash
# Attempt to delete User A's device as User B
curl -s -X DELETE http://localhost:8000/v1/devices/$DEVICE_A_ID \
  -H "Authorization: Bearer $USER_B_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)"
```

**Expected**: `403 FORBIDDEN`.

---

## Scenario 5: Device Removal with Session Revocation (AC-008)

```bash
# First create an active session on the device (user logs in using the device)
# Then remove the device

DEVICE_ID="..." # device with at least one active session

curl -s -X DELETE http://localhost:8000/v1/devices/$DEVICE_ID \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)"
```

**Expected**: `200 OK` with `{ "data": { "sessions_revoked": 1 } }` (or more if multiple sessions exist)
**Verify**:
- `identity.devices.status = revoked`
- All associated sessions in `identity.sessions` have `status = revoked`
- Redis revocation cache has each session ID set
- `platform.outbox_events` has `Device.Device.Removed`

---

## Scenario 6: Block Device and Authentication Rejection (AC-014, AC-015)

### Step 6.1 — Block a device

```bash
DEVICE_ID="..."

curl -s -X POST http://localhost:8000/v1/devices/$DEVICE_ID/block \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"reason": "Testing block flow"}'
```

**Expected**: `200 OK` with `{ "data": { "status": "blocked", "sessions_revoked": N } }`
**Verify**:
- `identity.devices.status = blocked`, `blocked_at` set, `blocked_reason` set
- `identity.device_trust_history` has `to_status = blocked`

### Step 6.2 — Attempt authentication with blocked device

In E01 login flow, the device_id is passed. With E03's `DeviceBlockCheckService` integrated:

**Expected**: Any authentication attempt including a `device_id` for a `blocked` device returns `403 DEVICE_BLOCKED` before token issuance.
**Verify**: Attempt login with a session/token associated with the blocked device → E01 middleware returns `DEVICE_BLOCKED`.

---

## Scenario 7: New Device Notification and Denial (AC-011, AC-012)

### Step 7.1 — Simulate `Identity.Device.NewDeviceDetected` event

```sql
-- Directly insert the event into outbox to simulate E01 publishing it
INSERT INTO platform.outbox_events (
  id, event_name, event_version, payload, metadata, aggregate_type, aggregate_id,
  correlation_id, causation_id, status, attempts, max_attempts, created_at
) VALUES (
  uuid_generate_v7(),
  'Identity.Device.NewDeviceDetected',
  '1.0.0',
  '{"device_id": "019401aa-0000-7000-0000-000000000001", "user_id": "USER_UUID", "device_name": "Unknown Device", "platform": "android"}',
  '{"correlation_id": "uuid", "causation_id": "uuid", "actor_id": "USER_UUID", "actor_type": "SYSTEM", "occurred_at": "2026-09-02T14:00:00Z"}',
  'identity.device',
  '019401aa-0000-7000-0000-000000000001',
  'uuid', 'uuid', 'pending', 0, 5, now()
);
```

**Then run Horizon**: `php artisan horizon` processes the event.

**Verify**:
- `identity.device_security_events` has `event_type = new_device_notified` with `acknowledgment_expires_at` set
- `platform.outbox_events` has `Device.Device.NewDeviceNotificationSent`

### Step 7.2 — Deny the device ("This wasn't me")

```bash
DEVICE_ID="019401aa-0000-7000-0000-000000000001"

curl -s -X POST http://localhost:8000/v1/devices/$DEVICE_ID/acknowledge \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"acknowledged": false}'
```

**Expected**: `200 OK` with `{ "data": { "status": "blocked", "sessions_revoked": N } }`
**Verify**:
- `identity.devices.status = blocked`
- `identity.device_security_events.resolution = blocked`, `resolved_at` set
- `platform.outbox_events` has `Device.Device.NewDeviceDenied`
- `platform.audit_events` has `identity.device.new-device-denied` at WARN severity

### Step 7.3 — Attempt acknowledgment after window expiry

```sql
-- Set acknowledgment_expires_at to the past for testing
UPDATE identity.device_security_events
SET acknowledgment_expires_at = now() - INTERVAL '1 hour'
WHERE device_id = 'DEVICE_ID' AND event_type = 'new_device_notified';
```

```bash
curl -s -X POST http://localhost:8000/v1/devices/$DEVICE_ID/acknowledge \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"acknowledged": true}'
```

**Expected**: `400 ACKNOWLEDGMENT_WINDOW_EXPIRED`

---

## Scenario 8: Admin Block and Unblock (AC-016, AC-017)

### Step 8.1 — Admin blocks any user's device

```bash
DEVICE_ID="..."

curl -s -X POST http://localhost:8000/v1/admin/devices/$DEVICE_ID/block \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"reason": "Reported stolen by account owner"}'
```

**Expected**: `200 OK` with `{ "data": { "status": "blocked", "subject_user_id": "..." } }`
**Verify**: Audit record has `actor_type = ADMIN`, `actor_id` = admin UUID, `metadata.subject_user_id` = device owner UUID.

### Step 8.2 — Verify user cannot self-unblock

```bash
curl -s -X POST http://localhost:8000/v1/devices/$DEVICE_ID/block \
  -H "Authorization: Bearer $USER_TOKEN" \
  -d '{"reason": "unblocking myself"}'
# Wait... user path is BLOCK not unblock. Let me verify user path.

# This should test that user cannot call admin unblock endpoint:
curl -s -X POST http://localhost:8000/v1/admin/devices/$DEVICE_ID/unblock \
  -H "Authorization: Bearer $USER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"reason": "trying to unblock"}'
```

**Expected**: `403 FORBIDDEN` (not admin actor)

### Step 8.3 — Admin unblocks

```bash
curl -s -X POST http://localhost:8000/v1/admin/devices/$DEVICE_ID/unblock \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"reason": "Owner confirmed device is secure"}'
```

**Expected**: `200 OK` with `{ "data": { "status": "trusted" } }`
**Verify**:
- `identity.devices.status = trusted`
- `blocked_at = NULL`, `blocked_reason = NULL`
- `identity.device_trust_history` shows full transition chain: trusted → blocked → trusted

---

## Scenario 9: Fingerprint in Responses (Security Check)

Run all `GET /v1/devices` and `GET /v1/devices/{id}` scenarios and inspect every response:

```bash
# Check no fingerprint hash in list response
curl -s http://localhost:8000/v1/devices -H "Authorization: Bearer $USER_TOKEN" \
  | jq '.data[] | keys'

# Check no fingerprint hash in detail response
curl -s http://localhost:8000/v1/devices/$DEVICE_ID -H "Authorization: Bearer $USER_TOKEN" \
  | jq 'keys'
```

**Expected**: Neither `device_fingerprint` nor `fingerprint_hash` appears as a key in any device object.

---

## Database Verification Queries

Run after all scenarios to validate data integrity:

```sql
-- Trust history is append-only for the test device
SELECT from_status, to_status, actor_type, created_at
FROM identity.device_trust_history
WHERE device_id = 'YOUR_DEVICE_ID'
ORDER BY created_at;

-- Security events exist
SELECT event_type, resolution, resolved_at
FROM identity.device_security_events
WHERE device_id = 'YOUR_DEVICE_ID';

-- Configuration defaults seeded
SELECT key, value FROM platform.configuration_values
WHERE key LIKE 'device.%';

-- Audit records for device operations
SELECT action, actor_type, outcome, occurred_at
FROM platform.audit_events
WHERE resource_type = 'identity.device'
ORDER BY occurred_at;

-- Outbox events dispatched
SELECT event_name, status, created_at
FROM platform.outbox_events
WHERE aggregate_type = 'identity.device'
ORDER BY created_at;
```

---

## Admin Next.js Validation

1. Navigate to `http://localhost:3001` — log in as `admin@olekard.local / Admin123!`
2. Navigate to a user's device list: `/devices/{userId}`
3. Verify device list loads with correct status badges
4. Click "Block Device" — verify confirmation dialog appears, reason field required, submit succeeds
5. Verify device shows as blocked in the list
6. Click "Unblock Device" — verify reason required, submit succeeds
7. Verify device trust history visible in device security events log

---

## Checklist Summary

| Scenario | Story | AC | Pass Criteria |
|----------|-------|-----|---------------|
| Fingerprint generation | US-DEVICE-002 | AC-004 | Hash returned; no attributes stored |
| Device registration | US-DEVICE-001 | AC-001, AC-002, AC-003 | CRUD, idempotency, limit |
| Trust promotion | US-DEVICE-003 | AC-005 | Status update + history entry + event |
| Device list | US-DEVICE-004 | AC-006 | No fingerprint in response |
| IDOR prevention | All | AC-007 | 403 on cross-user access |
| Device removal | US-DEVICE-005 | AC-008 | Sessions revoked atomically |
| Device block | US-DEVICE-009 | AC-014, AC-015 | Auth rejected for blocked device |
| New device denial | US-DEVICE-008 | AC-011, AC-012, AC-013 | Notification, denial flow, window |
| Admin block/unblock | US-DEVICE-009 | AC-016, AC-017 | Admin audit, user cannot self-unblock |
| Fingerprint not in response | All | AC-006 | Security scan passes |
| Configuration from platform | All | AC-020, AC-021 | No hard-coded values |
