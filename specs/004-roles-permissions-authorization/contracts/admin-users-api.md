# API Contract — Admin User Management

**Feature**: E04 — Roles, Permissions & Authorization
**Base path**: `/v1/admin/users`
**Auth**: `identity.auth` + `identity.require-actor-type:admin` + permission checks

---

## Error Codes

| Code | HTTP | Condition |
|------|------|-----------|
| `AUTHORIZATION_001` | 403 | Actor lacks required permission |
| `ADMIN_USER_EXISTS` | 409 | Email already registered |
| `ADMIN_USER_NOT_FOUND` | 404 | Target user does not exist |
| `ADMIN_SELF_DEACTIVATE` | 422 | Admin attempting to deactivate own account |

---

## `POST /v1/admin/users`

Create a new administrator user account.

**Required permission**: `admin.user.create`
**Idempotency**: `Idempotency-Key` header supported

**Request body**:

```json
{
  "email": "newadmin@olekard.local",
  "display_name": "Jane Doe",
  "password": "Str0ngP@ssw0rd!"
}
```

| Field | Type | Required | Constraints |
|-------|------|----------|-------------|
| `email` | string | Yes | Valid email, unique, max 255 chars |
| `display_name` | string | Yes | Max 100 chars |
| `password` | string | Yes | Meets E01 password complexity rules from platform config |

**Response 201**:

```json
{
  "success": true,
  "data": {
    "id": "uuid-v7",
    "email": "newadmin@olekard.local",
    "display_name": "Jane Doe",
    "actor_type": "ADMIN",
    "status": "active",
    "default_role": { "slug": "admin", "display_name": "Administrator" },
    "created_at": "2026-09-09T00:00:00Z"
  }
}
```

**Note**: Password is NEVER returned in any response.

**Errors**:
- `400` — validation failure (missing fields, password complexity)
- `409 ADMIN_USER_EXISTS` — email already registered
- `403 AUTHORIZATION_001` — insufficient permission

---

## `GET /v1/admin/users`

List administrator users (paginated).

**Required permission**: `admin.user.read`

**Query parameters**:

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 20 | Items per page (max 100) |
| `status` | string | — | Filter by status (`active`, `inactive`) |

**Response 200**:

```json
{
  "success": true,
  "data": [
    {
      "id": "uuid-v7",
      "email": "admin@olekard.local",
      "display_name": "System Admin",
      "actor_type": "ADMIN",
      "status": "active",
      "last_login_at": "2026-09-09T10:00:00Z",
      "created_at": "2026-09-09T00:00:00Z"
    }
  ],
  "meta": { "total": 2, "page": 1, "per_page": 20, "last_page": 1 }
}
```

---

## `GET /v1/admin/users/{id}`

Retrieve a single administrator user profile.

**Required permission**: `admin.user.read`

**Response 200**:

```json
{
  "success": true,
  "data": {
    "id": "uuid-v7",
    "email": "admin@olekard.local",
    "display_name": "System Admin",
    "actor_type": "ADMIN",
    "status": "active",
    "default_role": { "id": "uuid", "slug": "admin", "display_name": "Administrator" },
    "additional_roles": [],
    "last_login_at": "2026-09-09T10:00:00Z",
    "created_at": "2026-09-09T00:00:00Z",
    "updated_at": "2026-09-09T00:00:00Z"
  }
}
```

**Response 404**: `ADMIN_USER_NOT_FOUND`

---

## `PATCH /v1/admin/users/{id}/status`

Change an administrator user's account status.

**Required permission**: `admin.user.deactivate`
**Idempotency**: Not required (PATCH is idempotent by nature for same-value updates)

**Request body**:

```json
{ "status": "inactive" }
```

| Field | Type | Required | Constraints |
|-------|------|----------|-------------|
| `status` | string | Yes | `active` or `inactive` |

**Response 200**:

```json
{
  "success": true,
  "data": {
    "id": "uuid-v7",
    "status": "inactive",
    "sessions_revoked": 3,
    "updated_at": "2026-09-09T12:00:00Z"
  }
}
```

`sessions_revoked` contains the count of sessions that were revoked as part of this deactivation.

**Errors**:
- `404 ADMIN_USER_NOT_FOUND` — target user does not exist
- `422 ADMIN_SELF_DEACTIVATE` — actor is attempting to deactivate their own account
- `403 AUTHORIZATION_001` — insufficient permission

---

## Side Effects of Deactivation

When `status` is set to `inactive`:

1. All active sessions for the target user are revoked (via `SessionService`)
2. Each session revocation sets the Redis revocation key (`identity:revoked:{sessionId}`)
3. The next API call made with any of the revoked access tokens receives `401 Unauthorized`
4. Audit record `identity.admin_user.deactivated` is written with `sessions_revoked` count
