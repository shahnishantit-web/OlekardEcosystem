# Contract — Authorization Middleware

**Feature**: E04 — Roles, Permissions & Authorization

---

## Overview

E04 registers two new middleware aliases in `IdentityServiceProvider::boot()`:

| Alias | Class | Parameters |
|-------|-------|------------|
| `identity.require-permission` | `RequirePermissionMiddleware` | `{permission_slug}` |
| `identity.require-actor-type` | `RequireActorTypeMiddleware` | `{actor_type}` |

These middleware are applied AFTER `identity.auth` (which validates the JWT and sets request attributes). They MUST NOT be applied without `identity.auth` upstream.

---

## `identity.require-permission:{permission}`

### Behaviour

1. Reads `identity.user_id` from `$request->attributes`.
2. Calls `AuthorizationGuard::require(actor, $permission, $correlationId)`.
3. `AuthorizationGuard` calls `IdentityModuleContract::hasPermission($actorId, $permission)`.
4. If `hasPermission()` returns `true` → call `$next($request)`.
5. If `hasPermission()` returns `false`:
   - `AuthorizationGuard` writes `platform.permission.denied` audit record.
   - Middleware returns `403 JSON` without calling `$next`.

### 403 Response Format

```json
{
  "success": false,
  "error": {
    "code": "AUTHORIZATION_001",
    "message": "Insufficient permissions.",
    "trace_id": "{correlationId}"
  }
}
```

`X-Correlation-ID` response header is always set.

### Route Usage

```php
// Single permission
Route::middleware(['identity.auth', 'identity.require-permission:admin.user.create'])
    ->post('admin/users', [AdminUserController::class, 'store']);

// Permission group on a route group
Route::middleware(['identity.auth', 'identity.require-actor-type:admin'])
    ->prefix('admin')
    ->group(function () {
        Route::middleware('identity.require-permission:admin.role.read')
             ->get('roles', [AdminRoleController::class, 'index']);
    });
```

### Actor Resolution

The middleware constructs an `ActorContract` from request attributes:
- `identity.user_id` → `$actorId`
- `identity.actor_type` → `$actorType`
- `identity.correlation_id` → `$correlationId`

---

## `identity.require-actor-type:{actorType}`

### Behaviour

1. Reads `identity.actor_type` from `$request->attributes` (set by `identity.auth`).
2. Compares case-insensitively to the configured `{actorType}`.
3. If match → call `$next($request)`.
4. If no match → return `403 JSON` immediately.

### 403 Response Format

```json
{
  "success": false,
  "error": {
    "code": "AUTHORIZATION_002",
    "message": "Actor type not permitted.",
    "trace_id": "{correlationId}"
  }
}
```

### Usage

```php
Route::middleware(['identity.auth', 'identity.require-actor-type:admin'])
    ->prefix('v1/admin')
    ->group(function () { ... });
```

**Note**: `identity.require-actor-type:admin` is a coarse guard for the admin route group. Individual sensitive endpoints additionally require specific permissions via `identity.require-permission`.

---

## Middleware Registration

In `IdentityServiceProvider::boot()` (existing, adds two aliases):

```php
$router->aliasMiddleware('identity.require-permission', RequirePermissionMiddleware::class);
$router->aliasMiddleware('identity.require-actor-type',  RequireActorTypeMiddleware::class);
```

File locations:
- `app/Modules/Identity/Infrastructure/Http/Middleware/RequirePermissionMiddleware.php`
- `app/Modules/Identity/Infrastructure/Http/Middleware/RequireActorTypeMiddleware.php`

---

## Replacement of Inline Checks

The following inline authorization checks are REPLACED by middleware after E04:

| File | Removed Check | Replacement |
|------|--------------|-------------|
| `AdminSessionController::revoke()` | `if (strtoupper($adminActorType) !== 'ADMIN') { return 403; }` | `identity.require-permission:session.admin.revoke` on route |
| `ConfigurationUpdateRequest::authorize()` | `return strtoupper($actorType) === 'ADMIN'` | `identity.require-permission:configuration.policy.update` on route |
| `ConfigurationIndexRequest::authorize()` | `return strtoupper($actorType) === 'ADMIN'` | `identity.require-permission:configuration.policy.view` on route |

The `FormRequest::authorize()` methods revert to `return true` (or are removed) after the middleware layer handles authorization. The middleware fires before the form request is instantiated.
