# API Contracts — Admin Device Management (E03)

**Base path**: `/v1/admin` | **Auth**: Bearer JWT with `actor_type = admin`
**Envelope**: `{ "success": bool, "data": object|null, "error": object|null, "meta": object|null }`

All admin endpoints require `actor_type = admin` in the JWT claims (enforced by `IdentityAuthMiddleware` attribute `identity.actor_type`). Named permission enforcement (`device.read`, `device.block`, etc.) is checked in `AdminDeviceController` until E04 (Roles & Permissions) ships full RBAC middleware. Without E04, actor type check is the authorization gate.

---

## GET /v1/admin/users/{user_id}/devices

**Story**: US-DEVICE-004
**Required permission**: `device.read` (admin actor)
**Idempotent**: Read-only

### Request

```
GET /v1/admin/users/{user_id}/devices?page=1&per_page=20
Authorization: Bearer {admin_access_token}
X-Correlation-ID: {uuid}
```

**Path parameters**:

| Parameter | Type | Required | Notes |
|-----------|------|----------|-------|
| `user_id` | UUID | Yes | Subject user whose devices are listed |

**Query parameters**: same as `GET /v1/devices` — `page`, `per_page`, `status`, `platform`.

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
    "total": 1,
    "last_page": 1
  }
}
```

**`device_fingerprint` is NEVER included in responses.**

### Error Responses

| Condition | Code | Status |
|-----------|------|--------|
| Caller is not admin actor | `FORBIDDEN` | 403 |
| Admin actor lacks `device.read` permission | `FORBIDDEN` | 403 |
| Subject user not found | `USER_NOT_FOUND` | 404 |

---

## DELETE /v1/admin/devices/{device_id}

**Story**: US-DEVICE-005
**Required permission**: `device.remove` (admin actor)
**Idempotent**: Yes — `Idempotency-Key` header

### Request

```
DELETE /v1/admin/devices/{device_id}
Authorization: Bearer {admin_access_token}
Idempotency-Key: {uuid}
X-Correlation-ID: {uuid}
```

**Path parameters**:

| Parameter | Type | Required | Notes |
|-----------|------|----------|-------|
| `device_id` | UUID | Yes | Device to remove (any user's device) |

### Response 200

```json
{
  "success": true,
  "data": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "sessions_revoked": 3
  },
  "error": null,
  "meta": null
}
```

### Audit Record

The audit record for admin device removal MUST include both:
- `actor_id`: admin user UUID
- `resource_id`: device UUID
- `metadata.subject_user_id`: subject user UUID

This differs from the user-path audit record which only includes the acting user's UUID.

### Error Responses

| Condition | Code | Status |
|-----------|------|--------|
| Caller is not admin actor | `FORBIDDEN` | 403 |
| Admin actor lacks `device.remove` permission | `FORBIDDEN` | 403 |
| Device not found | `DEVICE_NOT_FOUND` | 404 |
| Device is already `revoked` | `INVALID_STATUS_TRANSITION` | 400 |

---

## POST /v1/admin/devices/{device_id}/block

**Story**: US-DEVICE-009
**Required permission**: `device.block` (admin actor)
**Idempotent**: Yes — `Idempotency-Key` header

### Request

```
POST /v1/admin/devices/{device_id}/block
Authorization: Bearer {admin_access_token}
Idempotency-Key: {uuid}
X-Correlation-ID: {uuid}
Content-Type: application/json
```

```json
{
  "reason": "Reported stolen by account owner"
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `reason` | string | **Yes** | Mandatory for admin-path block (unlike user-path where reason is optional). 1–500 characters. |

### Response 200

```json
{
  "success": true,
  "data": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "status": "blocked",
    "sessions_revoked": 2,
    "subject_user_id": "019401cc-0000-7000-0000-000000000003"
  },
  "error": null,
  "meta": null
}
```

`subject_user_id` is the user whose device was blocked — included in admin-path responses for audit traceability.

### Audit Record

Audit record fields:
- `action`: `identity.device.blocked`
- `actor_id`: admin UUID
- `actor_type`: `ADMIN`
- `resource_id`: device UUID
- `resource_type`: `identity.device`
- `metadata.subject_user_id`: subject user UUID
- `metadata.reason`: the provided reason
- `outcome`: `success`
- Severity: `WARN`

### Error Responses

| Condition | Code | Status |
|-----------|------|--------|
| Caller is not admin actor | `FORBIDDEN` | 403 |
| Admin actor lacks `device.block` permission | `FORBIDDEN` | 403 |
| `reason` field missing or empty | `VALIDATION_ERROR` | 422 |
| Device not found | `DEVICE_NOT_FOUND` | 404 |
| Device already blocked (with same `Idempotency-Key`) | Idempotent replay | 200 |
| Device already blocked (different `Idempotency-Key`) | `DEVICE_ALREADY_BLOCKED` | 400 |

---

## POST /v1/admin/devices/{device_id}/unblock

**Story**: US-DEVICE-009
**Required permission**: `device.unblock` (admin actor)
**Idempotent**: Yes — `Idempotency-Key` header

Self-service unblocking is NOT permitted. Only admins with `device.unblock` can unblock a blocked device.

### Request

```
POST /v1/admin/devices/{device_id}/unblock
Authorization: Bearer {admin_access_token}
Idempotency-Key: {uuid}
X-Correlation-ID: {uuid}
Content-Type: application/json
```

```json
{
  "reason": "Owner confirmed device is secure after factory reset"
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `reason` | string | Yes | Mandatory. 1–500 characters. |

### Response 200

Unblocking restores the device to `trusted` status. The `blocked_at` and `blocked_reason` columns are cleared.

```json
{
  "success": true,
  "data": {
    "device_id": "019401aa-0000-7000-0000-000000000001",
    "status": "trusted",
    "subject_user_id": "019401cc-0000-7000-0000-000000000003"
  },
  "error": null,
  "meta": null
}
```

### Audit Record

- `action`: `identity.device.unblocked`
- `actor_id`: admin UUID
- `actor_type`: `ADMIN`
- `resource_id`: device UUID
- `metadata.subject_user_id`: subject user UUID
- `metadata.reason`: unblock reason
- Severity: `INFO`

### Error Responses

| Condition | Code | Status |
|-----------|------|--------|
| Caller is not admin actor | `FORBIDDEN` | 403 |
| Admin actor lacks `device.unblock` permission | `FORBIDDEN` | 403 |
| `reason` field missing or empty | `VALIDATION_ERROR` | 422 |
| Device not found | `DEVICE_NOT_FOUND` | 404 |
| Device is not in `blocked` status | `INVALID_STATUS_TRANSITION` | 400 |

---

## Admin-Specific Security Requirements

- All admin responses include `subject_user_id` to make the subject of the operation explicit
- Admin operations MUST produce audit records with BOTH `actor_id` (admin) and `subject_user_id` (subject user) — BR-018
- Admin endpoints are accessed only via the Admin Next.js application using the `admin_token` cookie (translated to Bearer at the API client layer)
- Admin actors cannot block themselves (circular dependency prevention at service layer)
- Rate limiting: admin device endpoints share the `identity-auth` throttle group
