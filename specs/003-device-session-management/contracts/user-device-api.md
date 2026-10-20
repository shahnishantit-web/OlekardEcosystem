# API Contracts — User Device Management (E03)

**Base path**: `/v1` | **Auth**: Bearer JWT from E01
**Envelope**: `{ "success": bool, "data": object|null, "error": object|null, "meta": object|null }`

---

## Shared Types

### DeviceResource

Returned by all device list and detail endpoints. **Never includes `device_fingerprint`**.

```json
{
  "device_id": "019401aa-0000-7000-0000-000000000001",
  "device_name": "My Pixel 9",
  "platform": "android",
  "status": "trusted",
  "trusted_at": "2026-09-02T10:00:00Z",
  "last_seen_at": "2026-09-02T14:30:00Z",
  "blocked_at": null,
  "created_at": "2026-09-01T08:00:00Z"
}
```

| Field | Type | Nullable | Notes |
|-------|------|----------|-------|
| `device_id` | UUID string | No | |
| `device_name` | string | No | |
| `platform` | `ios` \| `android` \| `web` | No | |
| `status` | `pending_verification` \| `trusted` \| `revoked` \| `blocked` | No | |
| `trusted_at` | ISO 8601 datetime | Yes | NULL unless status is trusted |
| `last_seen_at` | ISO 8601 datetime | Yes | NULL if never seen in a session |
| `blocked_at` | ISO 8601 datetime | Yes | NULL unless status is blocked |
| `created_at` | ISO 8601 datetime | No | |

### Error Codes (E03-specific)

| Code | HTTP Status | Meaning |
|------|-------------|---------|
| `DEVICE_NOT_FOUND` | 404 | Device ID not found for the authenticated user |
| `DEVICE_FORBIDDEN` | 403 | Device belongs to another user (IDOR) |
| `DEVICE_BLOCKED` | 403 | Device is blocked; operation denied |
| `DEVICE_ALREADY_BLOCKED` | 400 | Device is already in `blocked` status |
| `DEVICE_LIMIT_EXCEEDED` | 409 | User has reached `device.max_devices_per_user` |
| `INSUFFICIENT_ENTROPY` | 400 | Fingerprint attributes below entropy minimum |
| `INVALID_ATTRIBUTE_FORMAT` | 400 | Attribute map missing required fields for platform |
| `INVALID_STATUS_TRANSITION` | 400 | Requested operation is invalid for device's current status |
| `ACKNOWLEDGMENT_WINDOW_EXPIRED` | 400 | New-device acknowledgment TTL has elapsed |
| `DEVICE_ALREADY_ACKNOWLEDGED` | 400 | Device notification already acknowledged or denied |

---

## POST /v1/devices/fingerprint

**Story**: US-DEVICE-002
**Auth**: Bearer JWT
**Idempotent**: No (read-only computation)

### Request

```
POST /v1/devices/fingerprint
Authorization: Bearer {access_token}
X-Correlation-ID: {uuid}
Content-Type: application/json
```

```json
{
  "platform": "android",
  "attributes": {
    "os_version": "14",
    "hardware_model": "Pixel 9",
    "android_id": "a1b2c3d4e5f67890",
    "screen_resolution": "1080x2400",
    "locale": "en-US"
  }
}
```

**Request fields**:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `platform` | `ios` \| `android` \| `web` | Yes | Determines required attribute keys |
| `attributes` | object | Yes | Platform-specific map; see research.md TBD-E03-001 for per-platform required keys |

**Platform required attributes**:

| Platform | Required keys |
|----------|---------------|
| `ios` | `os_version`, `hardware_model`, `idfv` |
| `android` | `os_version`, `hardware_model`, `android_id` |
| `web` | `user_agent`, `timezone` |

### Response 200

```json
{
  "success": true,
  "data": {
    "fingerprint_hash": "a3f8c2e1d4b7094f2a1e6c3b8d5f70912a3b4c5d6e7f8091a2b3c4d5e6f70912"
  },
  "error": null,
  "meta": null
}
```

Raw attribute values are NOT echoed back.

### Error Responses

| Condition | Code | Status |
|-----------|------|--------|
| Missing required attribute for platform | `INVALID_ATTRIBUTE_FORMAT` | 400 |
| Entropy below `device.fingerprint_min_entropy_bits` | `INSUFFICIENT_ENTROPY` | 400 |
| Emulator/virtual device detected (informational; not blocking) | *(no error — detection is side-channel)* | — |

---

## POST /v1/devices

**Story**: US-DEVICE-001
**Auth**: Bearer JWT
**Idempotent**: Yes — `Idempotency-Key` header required for safe retry

### Request

```
POST /v1/devices
Authorization: Bearer {access_token}
Idempotency-Key: {uuid}
X-Correlation-ID: {uuid}
Content-Type: application/json
```

```json
{
  "device_name": "My Pixel 9",
  "platform": "android",
  "fingerprint_hash": "a3f8c2e1d4b7094f2a1e6c3b8d5f70912a3b4c5d6e7f8091a2b3c4d5e6f70912"
}
```

| Field | Type | Required | Constraints |
|-------|------|----------|-------------|
| `device_name` | string | Yes | 1–100 characters |
| `platform` | `ios` \| `android` \| `web` | Yes | |
| `fingerprint_hash` | string | Yes | 64-character hex SHA-256 hash; from `/v1/devices/fingerprint` |

### Response 201 (new device)

```json
{
  "success": true,
  "data": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "status": "pending_verification",
    "challenge_id": "019401bb-0000-7000-0000-000000000002"
  },
  "error": null,
  "meta": null
}
```

`challenge_id` is used with E01's `POST /v1/auth/devices/verify` to complete OTP verification and promote the device to `trusted`.

### Response 200 (existing device — idempotent replay)

When `(user_id, fingerprint_hash)` already exists, the existing device is returned. No new OTP challenge if device is already `trusted`.

```json
{
  "success": true,
  "data": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "status": "trusted",
    "challenge_id": null
  },
  "error": null,
  "meta": null
}
```

### Error Responses

| Condition | Code | Status |
|-----------|------|--------|
| User device count ≥ `device.max_devices_per_user` | `DEVICE_LIMIT_EXCEEDED` | 409 |
| Invalid fingerprint_hash format (not 64-char hex) | `INVALID_FINGERPRINT_FORMAT` | 400 |
| Attempting to register device for another user | `DEVICE_FORBIDDEN` | 403 |

---

## GET /v1/devices

**Story**: US-DEVICE-004
**Auth**: Bearer JWT
**Idempotent**: Read-only

### Request

```
GET /v1/devices?page=1&per_page=20&status=trusted&platform=android
Authorization: Bearer {access_token}
X-Correlation-ID: {uuid}
```

**Query parameters**:

| Parameter | Type | Default | Notes |
|-----------|------|---------|-------|
| `page` | integer | 1 | ≥ 1 |
| `per_page` | integer | 20 | 1–100 |
| `status` | string | (all) | Filter by status; optional |
| `platform` | string | (all) | Filter by platform; optional |

### Response 200

```json
{
  "success": true,
  "data": [
    {
      "device_id": "019401aa-0000-7000-0000-000000000001",
      "device_name": "My Pixel 9",
      "platform": "android",
      "status": "trusted",
      "trusted_at": "2026-09-02T10:00:00Z",
      "last_seen_at": "2026-09-02T14:30:00Z",
      "blocked_at": null,
      "created_at": "2026-09-01T08:00:00Z"
    }
  ],
  "error": null,
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 3,
    "last_page": 1
  }
}
```

**`device_fingerprint` is NEVER included in responses.**

---

## GET /v1/devices/{device_id}

**Story**: US-DEVICE-004
**Auth**: Bearer JWT

### Response 200

Returns a single `DeviceResource`. Returns `404 DEVICE_NOT_FOUND` if device does not exist for the authenticated user (IDOR — never returns another user's device, even as a 404).

---

## POST /v1/devices/{device_id}/trust

**Story**: US-DEVICE-003
**Auth**: Bearer JWT
**Idempotent**: Yes — `Idempotency-Key` header

### Request

```
POST /v1/devices/{device_id}/trust
Authorization: Bearer {access_token}
Idempotency-Key: {uuid}
X-Correlation-ID: {uuid}
Content-Type: application/json
```

```json
{
  "reason": "Manually verified ownership"
}
```

`reason` is optional.

### Response 200

```json
{
  "success": true,
  "data": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "status": "trusted",
    "trusted_at": "2026-09-02T14:45:00Z"
  },
  "error": null,
  "meta": null
}
```

### Error Responses

| Condition | Code | Status |
|-----------|------|--------|
| Device is `blocked` | `DEVICE_BLOCKED` | 403 |
| Device is `revoked` | `INVALID_STATUS_TRANSITION` | 400 |
| Device not found for user | `DEVICE_NOT_FOUND` | 404 |
| Device belongs to another user | `DEVICE_FORBIDDEN` | 403 |

---

## DELETE /v1/devices/{device_id}

**Story**: US-DEVICE-005
**Auth**: Bearer JWT
**Idempotent**: Yes — `Idempotency-Key` header

### Request

```
DELETE /v1/devices/{device_id}
Authorization: Bearer {access_token}
Idempotency-Key: {uuid}
X-Correlation-ID: {uuid}
```

### Response 200

```json
{
  "success": true,
  "data": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "sessions_revoked": 2
  },
  "error": null,
  "meta": null
}
```

`sessions_revoked` is 0 if the device had no active sessions.

### Error Responses

| Condition | Code | Status |
|-----------|------|--------|
| Device not found for user | `DEVICE_NOT_FOUND` | 404 |
| Device belongs to another user | `DEVICE_FORBIDDEN` | 403 |
| Session revocation failed (partial) | Internal error | 500 |

---

## POST /v1/devices/{device_id}/acknowledge

**Story**: US-DEVICE-008
**Auth**: Bearer JWT
**Idempotent**: Yes — `Idempotency-Key` header

### Request

```
POST /v1/devices/{device_id}/acknowledge
Authorization: Bearer {access_token}
Idempotency-Key: {uuid}
X-Correlation-ID: {uuid}
Content-Type: application/json
```

```json
{
  "acknowledged": false
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `acknowledged` | boolean | Yes | `true` = "This was me"; `false` = "This wasn't me" |

### Response 200 — acknowledged: true

```json
{
  "success": true,
  "data": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "status": "trusted"
  },
  "error": null,
  "meta": null
}
```

### Response 200 — acknowledged: false (device blocked)

```json
{
  "success": true,
  "data": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "status": "blocked",
    "sessions_revoked": 1
  },
  "error": null,
  "meta": null
}
```

### Error Responses

| Condition | Code | Status |
|-----------|------|--------|
| TTL window has elapsed | `ACKNOWLEDGMENT_WINDOW_EXPIRED` | 400 |
| Already acknowledged or denied | `DEVICE_ALREADY_ACKNOWLEDGED` | 400 |
| Device not found for user | `DEVICE_NOT_FOUND` | 404 |
| Device belongs to another user | `DEVICE_FORBIDDEN` | 403 |

---

## POST /v1/devices/{device_id}/block

**Story**: US-DEVICE-009 (user self-service path)
**Auth**: Bearer JWT
**Idempotent**: Yes — `Idempotency-Key` header

### Request

```
POST /v1/devices/{device_id}/block
Authorization: Bearer {access_token}
Idempotency-Key: {uuid}
X-Correlation-ID: {uuid}
Content-Type: application/json
```

```json
{
  "reason": "Device appears to have been compromised"
}
```

`reason` is optional for user-path block.

### Response 200

```json
{
  "success": true,
  "data": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "status": "blocked",
    "sessions_revoked": 2
  },
  "error": null,
  "meta": null
}
```

### Error Responses

| Condition | Code | Status |
|-----------|------|--------|
| Device already blocked | `DEVICE_ALREADY_BLOCKED` | 400 |
| Device not found for user | `DEVICE_NOT_FOUND` | 404 |
| Device belongs to another user | `DEVICE_FORBIDDEN` | 403 |
