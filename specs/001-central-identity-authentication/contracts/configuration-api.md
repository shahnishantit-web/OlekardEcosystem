# Configuration API Contract — E01 Central Identity & Authentication

**Feature**: `001-central-identity-authentication` | **Base path**: `/v1/admin`

Admin-only endpoints for viewing and updating identity/security configuration. All requests require Admin-level authentication and `platform.configuration.view` or `platform.configuration.update` permissions.

---

## GET /v1/admin/platform/configuration/security

Return all identity and security configuration keys with current values, defaults, metadata, and allowed boundaries.

**Auth**: Bearer token required. Permission: `platform.configuration.view`.

**Response 200 OK**:

```json
{
  "success": true,
  "data": {
    "configuration": [
      {
        "key": "auth.otp.ttl",
        "value": "300",
        "default_value": "300",
        "description": "OTP validity in seconds",
        "data_type": "integer",
        "min": 60,
        "max": 3600,
        "last_updated_at": "2026-08-21T09:00:00Z",
        "last_updated_by": "admin-uuid",
        "is_sensitive": false
      },
      {
        "key": "auth.otp.provider",
        "value": "configured",
        "default_value": "configured",
        "description": "OTP provider selected through deployment configuration",
        "data_type": "string",
        "allowed_values": ["configured"],
        "last_updated_at": "2026-08-21T09:00:00Z",
        "last_updated_by": "system",
        "is_sensitive": false
      }
    ],
    "total": 25
  }
}
```

**Notes**:
- `is_sensitive: true` fields: return `"value": "[REDACTED]"`. No secret values ever returned.
- `min` / `max` / `allowed_values` define the configurable boundary enforced by validation.
- `data_type`: `integer` | `boolean` | `string` | `decimal`.

---

## PUT /v1/admin/platform/configuration/security/{key}

Update a single configuration value.

**Auth**: Bearer token required. Permission: `platform.configuration.update`.

**Idempotency**: Required (`X-Idempotency-Key`).

**Path parameter**: `key` — the exact configuration key (URL-encoded if contains dots, e.g., `auth.otp.ttl` → `auth.otp.ttl`).

**Request**:

```json
{
  "value": "600",
  "version": "2026-08-21T09:00:00Z",
  "reason": "Extending OTP window for international roaming users"
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `value` | string | Yes | New value; always submitted as string; typed validation applied server-side |
| `version` | string | Yes | `last_updated_at` from GET response; used for optimistic concurrency |
| `reason` | string | No | Included in audit record |

**Response 200 OK**:

```json
{
  "success": true,
  "data": {
    "key": "auth.otp.ttl",
    "old_value": "300",
    "new_value": "600",
    "updated_at": "2026-08-21T10:00:00Z",
    "updated_by": "admin-uuid"
  }
}
```

**Error responses**:

| Code | HTTP | Meaning |
|---|---|---|
| `IDENTITY_001` | 400 | Validation failure (value below security minimum, invalid type, out of range) |
| `IDENTITY_006` | 404 | Key not found in registry |
| `IDENTITY_010` | 403 | Insufficient permissions |
| `IDENTITY_016` | 409 | Optimistic concurrency conflict — another admin updated the value after your GET |
| `IDENTITY_012` | 400 | Idempotency key conflict |

`IDENTITY_016` response body:

```json
{
  "success": false,
  "error": {
    "code": "IDENTITY_016",
    "message": "Configuration was updated by another administrator. Re-fetch and retry.",
    "data": {
      "current_updated_at": "2026-08-21T09:55:00Z",
      "current_updated_by": "other-admin-uuid"
    }
  }
}
```

---

## Validation Rules

Server-side typed validation applied to all PUT requests:

| Key | Type | Minimum | Maximum | Notes |
|---|---|---|---|---|
| `auth.otp.ttl` | integer | 60 | 3600 | Seconds |
| `auth.otp.max_attempts` | integer | 1 | 10 | |
| `auth.otp.resend_cooldown` | integer | 0 | 300 | Seconds |
| `auth.otp.max_sends_per_hour` | integer | 1 | 20 | |
| `auth.pin.length` | integer | 6 | 12 | Platform minimum: 6 |
| `auth.pin.reject_sequential` | boolean | — | — | `true` or `false` |
| `auth.pin.reject_repeated` | boolean | — | — | `true` or `false` |
| `auth.pin.reject_common` | boolean | — | — | `true` or `false` |
| `auth.lockout.max_failed_attempts` | integer | 1 | 20 | |
| `auth.lockout.initial_duration` | integer | 60 | 86400 | Seconds |
| `auth.lockout.progressive_enabled` | boolean | — | — | |
| `auth.session.inactivity_ttl_admin` | integer | 300 | 3600 | Seconds |
| `auth.session.inactivity_ttl_customer` | integer | 300 | 86400 | Seconds |
| `auth.session.inactivity_ttl_agent` | integer | 300 | 86400 | Seconds |
| `auth.refresh_token.ttl` | integer | 3600 | 7776000 | Seconds |
| `auth.refresh_token.rotation_enabled` | boolean | — | — | |
| `auth.refresh_token.reuse_detection_enabled` | boolean | — | — | |
| `auth.step_up_threshold` | decimal | 0 | — | No upper bound |
| `auth.step_up_threshold_currency` | string | — | — | Must be a supported currency code |
| `auth.mfa.admin.totp_enabled` | boolean | — | — | |
| `auth.mfa.admin.fido2_enabled` | boolean | — | — | |
| `auth.mfa.admin.sms_enabled` | boolean | — | — | |
| `auth.biometric.enabled` | boolean | — | — | |
| `security.ip_audit.hash_algorithm` | string | — | — | Must be `HMAC-SHA256` (only allowed value) |

Security minimums enforced by `ConfigurationSecurityService` cannot be overridden via API, even by super-admin.

---

## Audit

Every successful PUT generates an `identity.config.updated` audit event in `platform.audit_events`:

```json
{
  "action": "identity.config.updated",
  "severity": "HIGH",
  "actor_id": "admin-uuid",
  "actor_type": "USER",
  "resource_type": "configuration",
  "resource_id": "auth.otp.ttl",
  "metadata": {
    "configuration_key": "auth.otp.ttl",
    "old_value": "300",
    "new_value": "600",
    "reason": "Extending OTP window for international roaming users",
    "ip_hash": "HMAC-SHA256-of-ip",
    "correlation_id": "uuid"
  }
}
```

Sensitive key values (`is_sensitive: true`) use `"[REDACTED]"` for `old_value` and `new_value` in the audit record.
