# Event Contracts — E04 Roles, Permissions & Authorization

**Feature**: E04 — Roles, Permissions & Authorization

---

## Audit Events (`platform.audit_events`)

E04 writes the following audit records via `AuditService::record()`. All records include:
- `actor_id` — the authenticated admin performing the action
- `actor_type` — always `ADMIN` for E04 operations
- `correlation_id` — from the request `X-Correlation-ID` header
- `outcome` — `success` or `failure`
- `ip_hash` — `HMAC-SHA256(platform_secret, ip_address)`

### `identity.role.created`

Fired when a new role is created via `POST /v1/admin/roles`.

```json
{
  "action": "identity.role.created",
  "actor_id": "uuid",
  "actor_type": "ADMIN",
  "resource_type": "Role",
  "resource_id": "new-role-uuid",
  "correlation_id": "uuid",
  "outcome": "success",
  "metadata": {
    "slug": "compliance-officer",
    "display_name": "Compliance Officer"
  }
}
```

### `identity.role.permission.assigned`

Fired when a permission is assigned to a role via `POST /v1/admin/roles/{roleId}/permissions`.

```json
{
  "action": "identity.role.permission.assigned",
  "resource_type": "RolePermission",
  "resource_id": "role-permission-uuid",
  "metadata": {
    "role_slug": "admin",
    "permission_slug": "admin.user.create"
  }
}
```

### `identity.role.permission.removed`

Fired when a permission is removed from a role via `DELETE /v1/admin/roles/{roleId}/permissions/{permissionId}`.

```json
{
  "action": "identity.role.permission.removed",
  "resource_type": "RolePermission",
  "resource_id": "role-permission-uuid",
  "metadata": {
    "role_slug": "admin",
    "permission_slug": "admin.user.create"
  }
}
```

### `identity.user.role.assigned`

Fired when a role is assigned to a user via `POST /v1/admin/users/{userId}/roles`.

```json
{
  "action": "identity.user.role.assigned",
  "resource_type": "UserRole",
  "resource_id": "user-role-uuid",
  "metadata": {
    "target_user_id": "uuid",
    "role_slug": "compliance-officer"
  }
}
```

### `identity.user.role.removed`

Fired when a role is removed from a user via `DELETE /v1/admin/users/{userId}/roles/{roleId}`.

```json
{
  "action": "identity.user.role.removed",
  "resource_type": "UserRole",
  "resource_id": "user-role-uuid",
  "metadata": {
    "target_user_id": "uuid",
    "role_slug": "compliance-officer"
  }
}
```

### `identity.admin_user.created`

Fired when a new admin user is created via `POST /v1/admin/users`.

```json
{
  "action": "identity.admin_user.created",
  "resource_type": "User",
  "resource_id": "new-user-uuid",
  "metadata": {
    "email_masked": "ne***@olekard.local",
    "display_name": "Jane Doe",
    "actor_type": "ADMIN"
  }
}
```

**Email masking rule**: Characters between the first character and `@` are replaced with `***`. E.g., `newadmin@olekard.local` → `n***@olekard.local`. Full email MUST NOT appear in audit metadata.

### `identity.admin_user.deactivated`

Fired when an admin user is deactivated via `PATCH /v1/admin/users/{id}/status`.

```json
{
  "action": "identity.admin_user.deactivated",
  "resource_type": "User",
  "resource_id": "deactivated-user-uuid",
  "metadata": {
    "sessions_revoked": 3
  }
}
```

---

## Platform Audit Record — `platform.permission.denied`

Already written by `AuthorizationGuard::require()` when `hasPermission()` returns `false`. E04 MUST NOT write a second copy.

```json
{
  "action": "platform.permission.denied",
  "actor_type": "ADMIN",
  "resource_type": "Permission",
  "metadata": { "permission": "admin.user.create" },
  "outcome": "failure"
}
```

---

## Metrics Emitted

| Metric | Labels | Trigger |
|--------|--------|---------|
| `identity.authorization.permission_denied` | `permission`, `actor_type` | `hasPermission()` returns false |
| `identity.authorization.permission_granted` | `permission`, `actor_type` | `hasPermission()` returns true |
| `identity.admin_user.created` | _(none)_ | Admin user created via API or Artisan |

---

## No Integration Events

E04 does not publish outbox/integration events. Authorization state changes (role creation, permission assignment) are internal identity management operations that do not require downstream event consumption in the current architecture. If downstream event consumption is needed in future epics, an ADR must be raised.
