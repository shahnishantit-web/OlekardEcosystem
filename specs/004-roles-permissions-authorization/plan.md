# Implementation Plan — E04 Roles, Permissions & Authorization

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — all new code is PHP/Laravel; no new technology introduced
- [x] No TBD value invented — TBD-E04-001 and TBD-E04-003 remain open; planning proceeds within confirmed scope
- [x] Authorization remains server-side — `hasPermission()` is evaluated by the backend; JWT claims are for display only
- [x] Financial integrity preserved — no financial data involved; this epic is identity/access management only
- [x] Security/audit requirements incorporated — permission grant/deny events go through `AuditService`; all privilege operations are audited
- [x] UX/design-system requirements incorporated — no customer or agent UI in E04; admin APIs are consumed by the admin Next.js app
- [x] QA strategy incorporated — unit tests for `PermissionResolverService`; integration tests for middleware and admin API endpoints; concurrency tests for permission-cache invalidation

## Technical Context

- **Backend**: Laravel / PHP 8.4+
- **Database**: PostgreSQL — new tables in `identity` schema
- **Queue/cache**: Redis + Laravel Horizon — permission cache keys `identity:permissions:{actorId}`
- **Web**: Next.js + TypeScript — admin app consumes new admin role/permission endpoints
- **Mobile**: Flutter — no changes in E04
- **API style**: REST / OpenAPI — all new endpoints under `/v1/admin/`
- **Testing**: PHPUnit / Pest — unit, integration (test PostgreSQL — SQLite is not supported due to `identity` schema namespacing), concurrency (parallel process assertions)
- **Observability**: Structured logs; `platform.permission.denied` audit events via existing `AuditService`; metrics via `MetricsService`

### Dependencies on Prior Epics

| Epic | Contract consumed by E04 |
|------|--------------------------|
| E01 | `IdentityModuleContract`, `TokenService`, `RegistrationService`, `SessionService`, `CredentialService`, `identity.users` table, `ConfigurationSecurityService`, audit/logging conventions |
| E03 | `AdminSessionController` (inline auth check to be replaced), `AdminDeviceController` (route group remains) |
| Platform | `AuthorizationGuard::require()`, `AuditService::record()`, `MetricsService` |

### Temporary Arrangements Replaced by E04

| Location | Temporary arrangement | Replacement |
|----------|-----------------------|-------------|
| `TokenService` lines 62-63, 133-134 | `'permissions' => $actorType === 'ADMIN' ? ['*'] : []` wildcard | `PermissionResolverService::resolveAllForActor()` returns actual permission slugs |
| `AdminSessionController::revoke()` | `if (strtoupper($adminActorType) !== 'ADMIN') { return 403; }` | `identity.require-permission:session.admin.revoke` on route |
| `ConfigurationUpdateRequest::authorize()` | `return strtoupper($actorType) === 'ADMIN'` | `identity.require-permission:configuration.policy.update` on route |
| `ConfigurationIndexRequest::authorize()` | `return strtoupper($actorType) === 'ADMIN'` | `identity.require-permission:configuration.policy.view` on route |

### Platform Infrastructure Preserved

| Component | E04 relationship |
|-----------|-----------------|
| `AuthorizationGuard::require()` | E04 implements `IdentityModuleContract::hasPermission()` which this calls — no changes to the guard itself |
| `identity.auth` middleware | Unchanged; E04 middlewares must run AFTER it |
| `AuditService::record()` | E04 adds 7 new event types; calls the existing service unchanged |
| `platform.permission.denied` event | Written by `AuthorizationGuard`; E04 must NOT write a second copy |
| `ActorType` value object (USER/SERVICE/SYSTEM) | Unchanged; E04 works with JWT `actor_type` strings (CUSTOMER/AGENT/ADMIN) |

---

## Phase 0 — Research

**Status**: COMPLETE — see `research.md`

Key decisions resolved:

1. **Parameterized middleware**: Standard Laravel `$router->aliasMiddleware()` pattern; no new infrastructure
2. **Permission cache**: Redis key `identity:permissions:{actorId}`, JSON array, TTL from `auth.permission_cache_ttl` (default 300s)
3. **TokenService injection**: `PermissionResolverService` added as constructor dependency; no circular dependency
4. **Migration numbering**: `2026_09_09_001000` through `2026_09_09_009000`
5. **Configuration seed format**: Follows `2026_08_21_006000` pattern with actual column names (`config_key`, `config_value`, etc.)
6. **Artisan command**: In `app/Console/Commands/CreateAdminCommand.php`; uses `$this->secret()` for password
7. **`hasPermission()` boundary**: Checks permissions only — account status is enforced by `identity.auth`
8. **TBD-E04-003 scope**: `agent` role seeded with zero permissions; agent permissions added by Agent epic

---

## Phase 1 — Design

**Status**: COMPLETE

Artifacts produced:

| Artifact | Path |
|----------|------|
| Data model | `specs/004-roles-permissions-authorization/data-model.md` |
| Admin Roles API | `specs/004-roles-permissions-authorization/contracts/admin-roles-api.md` |
| Admin Users API | `specs/004-roles-permissions-authorization/contracts/admin-users-api.md` |
| Authorization Middleware | `specs/004-roles-permissions-authorization/contracts/authorization-middleware.md` |
| Audit Events | `specs/004-roles-permissions-authorization/contracts/events.md` |
| Validation Guide | `specs/004-roles-permissions-authorization/quickstart.md` |

---

## Implementation Approach

### Step 1 — Migrations (9 migrations in order)

Execute strictly in order. Each migration depends on prior ones.

| Migration | Description |
|-----------|-------------|
| `2026_09_09_001000_create_identity_roles_table` | `identity.roles` (id UUID v7, slug unique, display_name, description, is_system_role, soft-delete) |
| `2026_09_09_002000_create_identity_permissions_table` | `identity.permissions` (id UUID v7, slug unique, display_name, module, is_system_permission) |
| `2026_09_09_003000_create_identity_role_permissions_table` | `identity.role_permissions` (id UUID v7, role_id FK, permission_id FK, unique constraint, created_by_actor_id, created_at) |
| `2026_09_09_004000_create_identity_user_roles_table` | `identity.user_roles` (id UUID v7, user_id FK, role_id FK, unique constraint, created_by_actor_id, created_at) |
| `2026_09_09_005000_add_role_fk_to_identity_users` | Add FK constraint to `identity.users.default_role_id` → `identity.roles.id` ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED |
| `2026_09_09_006000_seed_identity_roles` | Insert `customer`, `agent`, `admin` roles (idempotent `insertOrIgnore`) |
| `2026_09_09_007000_seed_identity_permissions_and_role_assignments` | Insert 15 permissions; assign all 15 to `admin` role; `customer`/`agent` get 0 (idempotent) |
| `2026_09_09_008000_seed_permission_cache_ttl_configuration` | Insert `auth.permission_cache_ttl = '300'` into `platform.configuration_values` (idempotent) |
| `2026_09_09_009000_backfill_user_default_roles` | Set `default_role_id` for existing users where null, based on `actor_type`; skips users already assigned |

### Step 2 — Domain Layer

#### 2a. Repositories (interfaces + Eloquent implementations)

- `RoleRepositoryInterface` / `EloquentRoleRepository`
  - `findBySlug(string $slug): ?Role`
  - `findById(string $id): ?Role`
  - `findAllActive(): Collection`
  - `create(RoleData $data): Role`
  - `assignPermissionToRole(string $roleId, string $permissionId, string $grantedBy): void`
  - `removePermissionFromRole(string $roleId, string $permissionId): void`

- `PermissionRepositoryInterface` / `EloquentPermissionRepository`
  - `findAll(): Collection`
  - `findById(string $id): ?Permission`
  - `findBySlug(string $slug): ?Permission`

- `UserRoleRepositoryInterface` / `EloquentUserRoleRepository`
  - `getActorRoleIds(string $actorId): array` — returns all role UUIDs (default_role_id + user_roles rows)
  - `assignRole(string $userId, string $roleId, string $assignedBy): void`
  - `removeRole(string $userId, string $roleId): void`

#### 2b. PermissionResolverService

New domain service in `app/Modules/Identity/Domain/Services/PermissionResolverService.php`:

```
PermissionResolverService
  - resolveAllForActor(string $actorId): array   // returns all permission slugs; warms Redis cache
  - hasPermission(string $actorId, string $permission): bool  // checks Redis first, falls back to DB
  - invalidateCache(string $actorId): void        // deletes Redis key
```

Redis key: `identity:permissions:{actorId}` — JSON array of slugs. TTL from config.

#### 2c. IdentityModuleContract update

Add `hasPermission(string $actorId, string $permission): bool` to `IdentityModuleContract`.

#### 2d. IdentityModule implementation

Implement `hasPermission()` in `IdentityModule.php` by delegating to `PermissionResolverService::hasPermission()`.

### Step 3 — TokenService Update

Remove wildcard `['*']` from `TokenService::issueAccessToken()` and `TokenService::issueTokenPair()`.

Replace with:
```php
->withClaim('permissions', $this->permissions->resolveAllForActor($userId))
->withClaim('roles', $this->roleRepository->getActorRoleSlugs($userId))
```

Inject `PermissionResolverService $permissions` in constructor.

### Step 4 — RegistrationService Update

When a new Customer registers, set `default_role_id` to the seeded `customer` role UUID. This satisfies E01's FR-E01: "A default customer role MUST be assigned to new Customer registrations."

Look up `customer` role UUID via `RoleRepositoryInterface::findBySlug('customer')` — do NOT hard-code the UUID.

### Step 5 — Authorization Middleware

Two new middleware classes:

**`RequirePermissionMiddleware`** (`app/Modules/Identity/Infrastructure/Http/Middleware/RequirePermissionMiddleware.php`):
- Reads `identity.user_id` and `identity.correlation_id` from `$request->attributes`
- Constructs actor; calls `AuthorizationGuard::require($actor, $permission, $correlationId)`
- Returns 403 JSON `AUTHORIZATION_001` on failure

**`RequireActorTypeMiddleware`** (`app/Modules/Identity/Infrastructure/Http/Middleware/RequireActorTypeMiddleware.php`):
- Reads `identity.actor_type` from `$request->attributes`
- Case-insensitive comparison to configured actor type
- Returns 403 JSON `AUTHORIZATION_002` on failure

Register both in `IdentityServiceProvider::boot()`.

Remove inline ADMIN checks from:
- `AdminSessionController::revoke()` — remove `if (strtoupper($adminActorType) !== 'ADMIN')` block
- `ConfigurationUpdateRequest::authorize()` — revert to `return true;`
- `ConfigurationIndexRequest::authorize()` — revert to `return true;`

### Step 6 — HTTP Layer: Admin Role/Permission Controllers

New controllers in `app/Modules/Identity/Infrastructure/Http/Controllers/`:

| Controller | Routes |
|------------|--------|
| `AdminRoleController` | `GET /v1/admin/roles`, `GET /v1/admin/roles/{id}`, `POST /v1/admin/roles` |
| `AdminRolePermissionController` | `POST /v1/admin/roles/{id}/permissions`, `DELETE /v1/admin/roles/{id}/permissions/{permId}` |
| `AdminPermissionController` | `GET /v1/admin/permissions` |
| `AdminRoleAssignmentController` | `GET /v1/admin/users/{id}/roles`, `POST /v1/admin/users/{id}/roles`, `DELETE /v1/admin/users/{id}/roles/{roleId}` |

New controller in `app/Modules/Identity/Infrastructure/Http/Controllers/`:

| Controller | Routes |
|------------|--------|
| `AdminUserController` | `POST /v1/admin/users`, `GET /v1/admin/users`, `GET /v1/admin/users/{id}`, `PATCH /v1/admin/users/{id}/status` |

All controllers:
- Thin — delegate to Application Services
- Use `identity.auth` + `identity.require-actor-type:admin` middleware on route group
- Use `identity.require-permission:{slug}` on each individual route
- Return JSON matching contracts in `contracts/`

### Step 7 — Application Services

- `AdminRoleService` — create role, assign/remove permissions, invalidate actor caches
- `AdminUserService` — create admin user (with default `admin` role), list users, deactivate (revokes sessions via `SessionService`)

Service responsibilities:
- Validate business rules (e.g., self-deactivation guard, slug uniqueness)
- Write audit records via `AuditService::record()`
- Emit metrics via `MetricsService`
- Invalidate Redis cache on role-permission changes

### Step 8 — Route Registration

Update `routes/modules/identity.php`:

```php
Route::middleware(['identity.auth', 'identity.require-actor-type:admin'])
    ->prefix('v1/admin')
    ->group(function () {
        // Roles
        Route::middleware('identity.require-permission:admin.role.read')
             ->get('roles', [AdminRoleController::class, 'index']);
        Route::middleware('identity.require-permission:admin.role.read')
             ->get('roles/{id}', [AdminRoleController::class, 'show']);
        Route::middleware('identity.require-permission:admin.role.create')
             ->post('roles', [AdminRoleController::class, 'store']);
        Route::middleware('identity.require-permission:admin.role.manage')
             ->post('roles/{id}/permissions', [AdminRolePermissionController::class, 'store']);
        Route::middleware('identity.require-permission:admin.role.manage')
             ->delete('roles/{id}/permissions/{permissionId}', [AdminRolePermissionController::class, 'destroy']);
        // Permissions
        Route::middleware('identity.require-permission:admin.permission.read')
             ->get('permissions', [AdminPermissionController::class, 'index']);
        // Users
        Route::middleware('identity.require-permission:admin.user.create')
             ->post('users', [AdminUserController::class, 'store']);
        Route::middleware('identity.require-permission:admin.user.read')
             ->get('users', [AdminUserController::class, 'index']);
        Route::middleware('identity.require-permission:admin.user.read')
             ->get('users/{id}', [AdminUserController::class, 'show']);
        Route::middleware('identity.require-permission:admin.user.deactivate')
             ->patch('users/{id}/status', [AdminUserController::class, 'updateStatus']);
        Route::middleware('identity.require-permission:admin.user.read')
             ->get('users/{id}/roles', [AdminRoleAssignmentController::class, 'index']);
        Route::middleware('identity.require-permission:admin.role.assign')
             ->post('users/{id}/roles', [AdminRoleAssignmentController::class, 'store']);
        Route::middleware('identity.require-permission:admin.role.assign')
             ->delete('users/{id}/roles/{roleId}', [AdminRoleAssignmentController::class, 'destroy']);
        // Existing E01/E03 admin routes — update middleware on:
        // Route paths verified from wallet-ecosystem/services/api/routes/modules/identity.php
        Route::middleware('identity.require-permission:session.admin.revoke')
             ->post('sessions/{session_id}/revoke', [AdminSessionController::class, 'revoke']);
        Route::middleware('identity.require-permission:configuration.policy.view')
             ->get('platform/configuration/security', [ConfigurationController::class, 'index']);
        Route::middleware(['identity.require-permission:configuration.policy.update', IdempotencyMiddleware::class])
             ->put('platform/configuration/security/{key}', [ConfigurationController::class, 'update'])
             ->where('key', '.+');
    });
```

### Step 9 — Artisan Command

`app/Console/Commands/CreateAdminCommand.php`:
- Signature: `identity:create-admin {--email=} {--name=} {--password=} {--force}`
- Interactive prompts for missing options
- Uses `$this->secret()` for password input
- Delegates to `AdminUserService::createAdmin()`
- Does NOT go through HTTP authorization layer
- Writes audit record with `actor_type = SYSTEM`

### Step 10 — Tests

#### Unit tests

- `PermissionResolverServiceTest` — resolves permissions from DB; returns from cache on second call; invalidates cache on permission change; returns empty array for unknown actor
- `RequirePermissionMiddlewareTest` — passes when permission present in claims; 403 when absent; 403 format matches `AUTHORIZATION_001`
- `RequireActorTypeMiddlewareTest` — passes correct actor type; 403 on mismatch; case-insensitive

#### Integration tests

- `AdminRoleControllerTest` — CRUD, pagination, 409 on duplicate slug, 422 on system-role delete
- `AdminPermissionControllerTest` — list all 15 seeded permissions
- `AdminUserControllerTest` — create admin, list, deactivate, 422 self-deactivate, idempotency
- `AdminRoleAssignmentControllerTest` — assign/remove roles from users
- `CreateAdminCommandTest` — creates admin, assigns admin role, fails on duplicate without --force

#### Regression tests

- Re-run E01 test suite — `TokenService` still issues valid tokens
- Re-run E03 test suite — admin session revocation still works

#### Concurrency test

- Two parallel requests call `hasPermission()` for the same actor simultaneously after cache invalidation → both return consistent results (no race condition producing one correct and one stale result)

---

## Data / Financial Controls

E04 has no direct financial data. Controls are access-control specific:

- **Idempotency**: `POST /v1/admin/users` accepts `Idempotency-Key` header
- **Unique constraints**: `identity.roles.slug UNIQUE`, `identity.permissions.slug UNIQUE`, `identity.role_permissions(role_id, permission_id) UNIQUE`, `identity.user_roles(user_id, role_id) UNIQUE`
- **No mutable history**: Deactivation writes a new audit event; does not alter prior events
- **Cache invalidation atomicity**: Permission cache key deleted synchronously after DB write within the same request lifecycle

---

## Observability

- All permission check results (granted/denied) are emitted as metrics via `MetricsService`
- Permission denials trigger `platform.permission.denied` audit records (written by `AuthorizationGuard`)
- Admin CRUD operations write typed audit events (`identity.role.created`, `identity.admin_user.created`, etc.) via `AuditService`
- Correlation IDs (`X-Correlation-ID`) flow through all request paths
- `auth.permission_cache_ttl` config value is observable via admin configuration API

---

## Complexity Tracking

- No ADR required — all changes are within the approved Laravel stack
- `PermissionResolverService` is a new domain service; it does not cross module boundaries
- `hasPermission()` addition to `IdentityModuleContract` is a backward-compatible extension (adds a method; no existing method is changed)
- If Enterprise actor role (TBD-E04-001) is approved, a separate planning addendum is required before implementation
- If Agent permissions (TBD-E04-003) are approved for this epic, a spec amendment and plan addendum are required before implementation starts
