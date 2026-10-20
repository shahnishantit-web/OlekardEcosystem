# API Contract — Notification Preferences

**Base path**: `/v1/notifications/preferences`
**Auth**: `auth:sanctum`
**Permission**: `notifications.preferences.manage` (own preferences only)

---

## GET /v1/notifications/preferences

Returns the authenticated user's current notification preference settings.

### Response 200

```json
{
  "data": {
    "user_id": "01932b8f-7c3e-7c3e-8000-000000000001",
    "push_enabled_categories": ["financial", "security", "operational"],
    "sms_enabled_categories": ["financial", "security"],
    "email_enabled_categories": ["security"],
    "marketing_opted_in": false,
    "updated_at": "2026-10-01T12:00:00Z"
  }
}
```

### Errors

| Code | Status | Condition |
|------|--------|-----------|
| `UNAUTHENTICATED` | 401 | No valid session token |

---

## PUT /v1/notifications/preferences

Replaces the user's notification preference settings. Mandatory categories (`financial`, `security`) are always enforced regardless of what is submitted — the API rejects any request that attempts to remove them.

### Request Body

```json
{
  "push_enabled_categories": ["financial", "security", "operational"],
  "sms_enabled_categories": ["financial", "security"],
  "email_enabled_categories": ["financial", "security"],
  "marketing_opted_in": false
}
```

**Validation rules**:
- `push_enabled_categories`, `sms_enabled_categories`, `email_enabled_categories` — array of strings; each value must be one of `financial`, `security`, `operational`, `marketing`
- `financial` and `security` must remain present in any non-empty array submitted for a channel — cannot be removed
- `marketing_opted_in` — boolean; defaults to `false` if omitted

### Response 200

Same shape as GET.

### Errors

| Code | Status | Condition |
|------|--------|-----------|
| `VALIDATION_ERROR` | 422 | Invalid category value in array |
| `MANDATORY_CATEGORY_REQUIRED` | 422 | Attempt to remove `financial` or `security` from a category array |
| `UNAUTHENTICATED` | 401 | No valid session token |

---

## Response Envelope

All responses follow the platform standard envelope:

```json
{
  "data": { ... }
}
```

Errors:

```json
{
  "error": {
    "code": "MANDATORY_CATEGORY_REQUIRED",
    "message": "The financial and security notification categories cannot be disabled.",
    "correlation_id": "01932b8f-0000-7000-0000-000000000099"
  }
}
```
