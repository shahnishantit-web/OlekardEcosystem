# Tasks: E04 — Roles, Permissions & Authorization

**Feature**: `004-roles-permissions-authorization`
**Plan**: `specs/004-roles-permissions-authorization/plan.md`
**Spec**: `specs/004-roles-permissions-authorization/spec.md`
**Tests**: Included — spec.md §15 explicitly defines unit, API, security, integration, and concurrency test requirements.

**Working directory**: `wallet-ecosystem/services/api/`

## Story Legend

| Label | Story ID | Title | Priority |
|-------|----------|-------|----------|
| [US1] | US-PERM-001 | View roles and permissions | P1 |
| [US2] | US-PERM-002 | Create admin user | P1 |
| [US3] | US-PERM-003 | Deactivate admin user | P1 |
| [US4] | US-PERM-004 | Assign role to user | P2 |
| [US5] | US-PERM-005 | System enforces and audits denials | P1 |
| [US6] | US-PERM-006 | Customer cannot access admin | P1 |
| [US7] | US-PERM-007 | Manage role-permission assignments | P2 |
| [US8] | US-PERM-008 | View admin user list and details | P1 |
| [US9] | US-RBAC-001 | Admin creates a role | P0 |
| — | US-RBAC-002 | _(Retired — merged into US-RBAC-001; role creation and role viewing consolidated into one story during specification)_ | — |
| [US10] | US-RBAC-003 | Admin manages role lifecycle | P0 |

---

## Phase 1: Setup — Migrations, Models, Repositories

**Purpose**: Create all database tables, Eloquent models, and repository layer. All user story phases depend on this phase being complete.

**⚠️ CRITICAL**: Migrations MUST run in numeric order. T001 and T002 may be created in parallel (different tables), but T003 depends on both T001 and T002. Follow the ordering constraints documented in each task.

### Migrations

- [X] T001 Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_09_001000_create_identity_roles_table.php` — table `identity.roles` with columns: id (UUID v7 PK), slug (VARCHAR 50 UNIQUE NOT NULL), display_name (VARCHAR 100 NOT NULL), description (TEXT NULL), is_system_role (BOOLEAN NOT NULL DEFAULT false), deleted_at (TIMESTAMPTZ NULL), created_at, updated_at; index on deleted_at
- [X] T002 [P] Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_09_002000_create_identity_permissions_table.php` — table `identity.permissions` with columns: id (UUID v7 PK), slug (VARCHAR 100 UNIQUE NOT NULL), display_name (VARCHAR 150 NOT NULL), description (TEXT NULL), module (VARCHAR 50 NOT NULL), is_system_permission (BOOLEAN NOT NULL DEFAULT false), deleted_at (TIMESTAMPTZ NULL), created_at, updated_at; indexes on slug (unique), module, deleted_at
- [X] T003 Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_09_003000_create_identity_role_permissions_table.php` — table `identity.role_permissions` with columns: id (UUID v7 PK), role_id (UUID NOT NULL FK → identity.roles.id ON DELETE CASCADE), permission_id (UUID NOT NULL FK → identity.permissions.id ON DELETE CASCADE), created_by_actor_id (UUID NULL), created_at (TIMESTAMPTZ NOT NULL); UNIQUE(role_id, permission_id); indexes on role_id, permission_id. Depends on T001, T002.
- [X] T004 [P] Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_09_004000_create_identity_user_roles_table.php` — table `identity.user_roles` with columns: id (UUID v7 PK), user_id (UUID NOT NULL FK → identity.users.id ON DELETE CASCADE), role_id (UUID NOT NULL FK → identity.roles.id ON DELETE RESTRICT), created_by_actor_id (UUID NULL), created_at (TIMESTAMPTZ NOT NULL); UNIQUE(user_id, role_id); indexes on user_id. Depends on T001.
- [X] T005 Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_09_005000_add_role_fk_to_identity_users.php` — ALTER TABLE identity.users ADD CONSTRAINT users_default_role_id_fkey FOREIGN KEY (default_role_id) REFERENCES identity.roles(id) ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED. Depends on T001.
- [X] T006 Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_09_006000_seed_identity_roles.php` — idempotent insertOrIgnore of customer, agent, admin roles (all is_system_role = true) using DB::connection('identity'). Pattern: `2026_08_21_006000_seed_identity_configuration_defaults.php`. Depends on T001, T005.
- [X] T007 Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_09_007000_seed_identity_permissions_and_role_assignments.php` — idempotent insertOrIgnore for all 15 permissions from FR-E04-007 catalogue (is_system_permission = true); then insertOrIgnore into identity.role_permissions for each permission assigned to admin role. Depends on T003, T006.
- [X] T008 [P] Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_09_008000_seed_permission_cache_ttl_configuration.php` — idempotent insertOrIgnore into platform.configuration_values: config_key='auth.permission_cache_ttl', config_value='300', value_type='number', status='active', version=1, is_tbd=false. Uses DB::connection('platform'). Depends on T001 (parallel with T006/T007).
- [X] T009 Create migration `app/Modules/Identity/Infrastructure/Migrations/2026_09_09_009000_backfill_user_default_roles.php` — for each actor_type (CUSTOMER→customer role, AGENT→agent role, ADMIN→admin role): UPDATE identity.users SET default_role_id = (SELECT id FROM identity.roles WHERE slug = ?) WHERE actor_type = ? AND default_role_id IS NULL. Run in batches of 500. Log warning for unrecognized actor_type rows (don't throw). Depends on T006.

### Eloquent Models

- [X] T010 [P] Create `app/Modules/Identity/Infrastructure/Models/Role.php` — Eloquent model: connection='identity', table='identity.roles', fillable=[slug, display_name, description, is_system_role], casts=[is_system_role→boolean, deleted_at→immutable_datetime], use SoftDeletes; relationships: hasMany(RolePermission), hasMany(UserRole)
- [X] T011 [P] Create `app/Modules/Identity/Infrastructure/Models/Permission.php` — Eloquent model: connection='identity', table='identity.permissions', fillable=[slug, display_name, description, module, is_system_permission], casts=[is_system_permission→boolean, deleted_at→immutable_datetime], use SoftDeletes
- [X] T012 [P] Create `app/Modules/Identity/Infrastructure/Models/RolePermission.php` — Eloquent model: connection='identity', table='identity.role_permissions', fillable=[role_id, permission_id, created_by_actor_id]; no soft delete; relationships: belongsTo(Role), belongsTo(Permission)
- [X] T013 [P] Create `app/Modules/Identity/Infrastructure/Models/UserRole.php` — Eloquent model: connection='identity', table='identity.user_roles', fillable=[user_id, role_id, created_by_actor_id]; no soft delete; relationships: belongsTo(User), belongsTo(Role)

### Repository Layer

- [X] T014 Create `app/Modules/Identity/Domain/Repositories/RoleRepositoryInterface.php` — declare: findAll(): Collection, findById(string $id): ?Role, findBySlug(string $slug): ?Role, create(array $data): Role, softDelete(string $id): void, getActorRoleSlugs(string $actorId): array
- [X] T015 [P] Create `app/Modules/Identity/Domain/Repositories/PermissionRepositoryInterface.php` — declare: findAll(): Collection, findById(string $id): ?Permission, findBySlug(string $slug): ?Permission
- [X] T016 [P] Create `app/Modules/Identity/Domain/Repositories/UserRoleRepositoryInterface.php` — declare: getActorRoleIds(string $actorId): array, assignRole(string $userId, string $roleId, string $assignedByActorId): void, removeRole(string $userId, string $roleId): void
- [X] T017 Create `app/Modules/Identity/Domain/Repositories/RolePermissionRepositoryInterface.php` — declare: getPermissionSlugsForRoles(array $roleIds): array, assignPermission(string $roleId, string $permissionId, string $assignedByActorId): void, removePermission(string $roleId, string $permissionId): void, hasPermission(string $roleId, string $permissionId): bool
- [X] T018 Create `app/Modules/Identity/Infrastructure/Repositories/EloquentRoleRepository.php` — implements RoleRepositoryInterface using Role Eloquent model; getActorRoleSlugs loads default_role_id from identity.users + user_roles rows in single query
- [X] T019 [P] Create `app/Modules/Identity/Infrastructure/Repositories/EloquentPermissionRepository.php` — implements PermissionRepositoryInterface using Permission model
- [X] T020 [P] Create `app/Modules/Identity/Infrastructure/Repositories/EloquentUserRoleRepository.php` — implements UserRoleRepositoryInterface using UserRole model
- [X] T021 [P] Create `app/Modules/Identity/Infrastructure/Repositories/EloquentRolePermissionRepository.php` — implements RolePermissionRepositoryInterface; getPermissionSlugsForRoles uses single IN query on role_ids; joins identity.role_permissions with identity.permissions to return slug array

**Checkpoint**: Run `php artisan migrate` — all 9 migrations execute without error. Verify `identity.roles` has 3 seeded rows, `identity.permissions` has 15 rows, `identity.role_permissions` has 15 rows.

---

## Phase 2: Foundational — Authorization Core

**Purpose**: Implement `PermissionResolverService`, `hasPermission()`, update `TokenService` and `RegistrationService`, and register middleware aliases. **NO user story work can begin until this phase is complete.**

**⚠️ CRITICAL**: This phase wires `AuthorizationGuard::require()` → `hasPermission()` which has been an unimplemented forward reference since E01.

- [X] T022 Create `app/Modules/Identity/Domain/Services/PermissionResolverService.php` — resolveAllForActor(string $actorId): array (loads role IDs from UserRoleRepository + RoleRepository, queries RolePermissionRepository for slugs; if TTL from auth.permission_cache_ttl > 0, stores result in Redis key `identity:permissions:{actorId}` as JSON with that TTL; if TTL = 0, skips Redis entirely and returns DB result directly on every call — EC-08, FR-E04-055); hasPermission(string $actorId, string $permission): bool (when TTL > 0: Redis hit first, fallback to resolveAllForActor; when TTL = 0: queries DB directly every call; after evaluating, emit metric counter `identity.authorization.permission_denied` with labels [permission, actor_type] when returning false — FR-E04-052; emit `identity.authorization.permission_granted` with labels [permission, actor_type] when returning true — FR-E04-053); invalidateCache(string $actorId): void (deletes Redis key; no-op when TTL = 0). Redis: use same pattern as E01/E03 (e.g. `app/Modules/Identity/Domain/Services/TokenService.php` for Redis injection pattern). Returns false (not throws) for unknown actor.
- [X] T023 [P] Add `hasPermission(string $actorId, string $permission): bool` declaration to `app/Modules/Identity/Domain/Contracts/IdentityModuleContract.php` — add PHPDoc: "Resolves via role-permission model. Returns false on unknown actor, missing role, or missing permission. MUST NOT throw."
- [X] T024 Implement `hasPermission()` in `app/Modules/Identity/Application/IdentityModule.php` — delegate to `PermissionResolverService::hasPermission()`. Depends on T022, T023.
- [X] T025 Update `app/Modules/Identity/Domain/Services/TokenService.php` — inject PermissionResolverService as constructor parameter; in issueTokenPair() and issueAccessToken(), replace `'permissions' => $actorType === 'ADMIN' ? ['*'] : []` with `'permissions' => $this->permissions->resolveAllForActor($userId)` and replace `'roles' => []` with `'roles' => $this->roleRepository->getActorRoleSlugs($userId)`. Remove wildcard. Depends on T022, T018.
- [X] T026 [P] Update `app/Modules/Identity/Application/Services/RegistrationService.php` — after creating a new CUSTOMER user record, set default_role_id by calling RoleRepository::findBySlug('customer')->id. Do NOT hard-code the UUID. Depends on T018.
- [X] T027 Update `app/Modules/Identity/Providers/IdentityServiceProvider.php` — in register(): bind PermissionResolverService, RoleRepositoryInterface→EloquentRoleRepository, PermissionRepositoryInterface→EloquentPermissionRepository, UserRoleRepositoryInterface→EloquentUserRoleRepository, RolePermissionRepositoryInterface→EloquentRolePermissionRepository. In boot(): add `$router->aliasMiddleware('identity.require-permission', RequirePermissionMiddleware::class)` and `$router->aliasMiddleware('identity.require-actor-type', RequireActorTypeMiddleware::class)`. Depends on T022, T018-T021.

**Checkpoint**: Call `app()->make(IdentityModuleContract::class)->hasPermission($knownAdminId, 'admin.user.create')` in tinker — returns true for seeded admin user, false for unknown ID.

---

## Phase 3: US-PERM-005 (P1) — System Enforces and Audits Denials

**Goal**: Middleware classes are implemented and registered; all existing inline `actor_type === 'ADMIN'` checks are replaced; existing admin routes for sessions, devices, and configuration are protected by proper permission middleware.

**Independent Test**: Call any `POST /v1/admin/sessions/{id}/revoke` with a CUSTOMER token → HTTP 403 `AUTHORIZATION_001`. Call with a valid ADMIN token → HTTP 200. Verify `platform.permission.denied` appears in audit log for the 403 case.

- [X] T028 [US5] Create `app/Modules/Identity/Infrastructure/Http/Middleware/RequirePermissionMiddleware.php` — handle(Request $request, Closure $next, string $permission): reads identity.user_id and identity.correlation_id from $request->attributes; constructs ActorContract; calls AuthorizationGuard::require($actor, $permission, $correlationId); on AuthorizationException returns JSON 403 `{ "success": false, "error": { "code": "AUTHORIZATION_001", "message": "Insufficient permissions.", "trace_id": "..." } }` with X-Correlation-ID header; on success calls $next($request). Depends on T027.
- [X] T029 [P] [US5] Create `app/Modules/Identity/Infrastructure/Http/Middleware/RequireActorTypeMiddleware.php` — handle(Request $request, Closure $next, string $actorType): reads identity.actor_type from $request->attributes; case-insensitive compare to $actorType; on mismatch returns JSON 403 `{ "success": false, "error": { "code": "AUTHORIZATION_002", "message": "Actor type not permitted.", "trace_id": "..." } }`; on match calls $next($request). Depends on T027.
- [X] T030 [US5] Remove inline auth check from `app/Modules/Identity/Infrastructure/Http/Controllers/AdminSessionController.php` — delete the `if (strtoupper($adminActorType) !== 'ADMIN') { return response()->json(..., 403); }` block from revoke(). Authorization is now enforced by route middleware. Depends on T028, T029.
- [X] T031 [P] [US5] Update `app/Modules/Identity/Infrastructure/Http/Requests/ConfigurationUpdateRequest.php` — change authorize() to `return true;` (authorization now at route/middleware layer per FR-E04-040). Depends on T027.
- [X] T032 [P] [US5] Update `app/Modules/Identity/Infrastructure/Http/Requests/ConfigurationIndexRequest.php` — change authorize() to `return true;`. Depends on T027.
- [X] T033 [US5] Update `routes/modules/identity.php` — wrap all existing `/v1/admin/*` routes in `Route::middleware(['identity.auth', 'identity.require-actor-type:admin'])->prefix('v1/admin')->group(...)`. Apply specific permission middleware to each route per plan.md §Step 8: session revocation → `identity.require-permission:session.admin.revoke`; device routes (list, block, unblock, remove, security events) → appropriate `device.admin.*` permissions; configuration view → `identity.require-permission:configuration.policy.view`; configuration update → `identity.require-permission:configuration.policy.update`. Depends on T028, T029, T030, T031, T032.
- [X] T034 [P] [US5] Write unit test `tests/Unit/Modules/Identity/RequirePermissionMiddlewareTest.php` — test: (1) passes through when AuthorizationGuard approves; (2) returns 403 JSON with AUTHORIZATION_001 when guard throws; (3) response has X-Correlation-ID header; (4) does not invoke controller on denial.
- [X] T035 [P] [US5] Write unit test `tests/Unit/Modules/Identity/RequireActorTypeMiddlewareTest.php` — test: (1) passes ADMIN actor type; (2) blocks CUSTOMER with 403 AUTHORIZATION_002; (3) comparison is case-insensitive; (4) does not invoke controller on denial.
- [X] T036 [US5] Write feature test `tests/Feature/Modules/Identity/AdminRouteProtectionTest.php` — test all existing admin routes (session revocation, device block, config view) return 403 for CUSTOMER token, 403 for AGENT token, 401 for unauthenticated request, and 200 for valid ADMIN token with correct permission. Verify `platform.permission.denied` audit record written for each 403.

**Checkpoint**: `GET /v1/admin/roles` (does not exist yet) returns 404. `POST /v1/admin/sessions/{id}/revoke` with CUSTOMER token returns 403 AUTHORIZATION_001 with X-Correlation-ID header. Inline checks are gone from AdminSessionController and form requests.

---

## Phase 4: US-RBAC-001 (P0) — Admin Creates a Role

**Goal**: Admins can create custom roles via API; seeded system roles are visible; role creation produces an audit record.

**Independent Test**: Call `POST /v1/admin/roles` with a valid ADMIN token → HTTP 201. Call `GET /v1/admin/roles` → three seeded system roles appear. Call with duplicate slug → 409 ROLE_SLUG_CONFLICT.

- [X] T037 [US9] Create `app/Modules/Identity/Application/Services/AdminRoleService.php` — findAll(): Collection (active roles with permission slugs), findById(string $id): Role|null (with permissions), createRole(array $data, string $actorId, string $correlationId): Role (validate slug unique including soft-deleted, create role, write audit record identity.role.created via AuditService). Depends on T018.
- [X] T038 [P] [US9] Create `app/Modules/Identity/Infrastructure/Http/Requests/CreateRoleRequest.php` — validate: slug (required, string, max:50, regex:/^[a-z0-9-]+$/, unique in identity.roles including soft-deleted), display_name (required, string, max:100), description (nullable, string, max:500)
- [X] T039 [US9] Create `app/Modules/Identity/Infrastructure/Http/Controllers/AdminRoleController.php` — index() calls AdminRoleService::findAll() → 200 paginated; show(string $id) calls findById() → 200 or 404 ROLE_NOT_FOUND; store(CreateRoleRequest $request) calls createRole() → 201 or 409 ROLE_SLUG_CONFLICT. Controller is thin — no business logic. Depends on T037, T038.
- [X] T040 [US9] Register role routes in `routes/modules/identity.php` inside the admin middleware group: `GET /v1/admin/roles` → `identity.require-permission:admin.role.read` → AdminRoleController::index; `GET /v1/admin/roles/{id}` → same permission → show; `POST /v1/admin/roles` → `identity.require-permission:admin.role.create` → store. Depends on T039, T033.
- [X] T041 [P] [US9] Write feature test `tests/Feature/Modules/Identity/AdminRoleCreationTest.php` — test: (1) POST creates role, returns 201 with empty permissions; (2) GET /v1/admin/roles returns 3 seeded roles with is_system_role=true; (3) duplicate slug returns 409 ROLE_SLUG_CONFLICT; (4) ADMIN without admin.role.create returns 403; (5) identity.role.created audit record written on success.
- [X] T042 [P] [US9] **[BACKLOG — non-blocking; feature behaviour covered by T041 feature test]** Write unit test `tests/Unit/Modules/Identity/AdminRoleServiceTest.php` — test createRole validation, slug uniqueness check (including soft-deleted), audit record emission.

**Checkpoint**: `POST /v1/admin/roles` with valid ADMIN token and body `{"slug":"auditor","display_name":"Auditor"}` → 201. `GET /v1/admin/roles` → includes customer, agent, admin (seeded) and auditor.

---

## Phase 5: US-PERM-002 (P1) — Create Admin User

**Goal**: Super-admins can create new admin user accounts via API and via Artisan command. Password is never returned. Audit record written.

**Independent Test**: `POST /v1/admin/users` with valid body and ADMIN token → 201. Same request with same `Idempotency-Key` → same 201. Same email different `Idempotency-Key` → 409 ADMIN_USER_EXISTS.

- [X] T043 [US2] Create `app/Modules/Identity/Application/Services/AdminUserService.php` (shared across US2, US3, US8) — createAdmin(array $data, string $actorId, string $correlationId): array: create identity.users record with actor_type=ADMIN, call CredentialService to provision hashed password, set default_role_id = admin role UUID via RoleRepository::findBySlug('admin'), write audit identity.admin_user.created (masked email), emit metric identity.admin_user.created. Return user array (no password). Validate: email unique in identity.users where actor_type=ADMIN. Depends on T018, T022.
- [X] T044 [P] [US2] Create `app/Modules/Identity/Infrastructure/Http/Requests/CreateAdminUserRequest.php` — validate: email (required, email, max:255), display_name (required, string, max:100), password (required, string, meets E01 complexity rules from ConfigurationSecurityService)
- [X] T045 [US2] Create `app/Modules/Identity/Infrastructure/Http/Controllers/AdminUserController.php` — store(CreateAdminUserRequest $request) delegates to AdminUserService::createAdmin() → 201. Uses Idempotency-Key pattern from E01 (check platform.idempotency_keys table, return cached response on replay). Returns 409 ADMIN_USER_EXISTS on duplicate email. Depends on T043, T044.
- [X] T046 [US2] Create `app/Console/Commands/CreateAdminCommand.php` — signature: `identity:create-admin {--email=} {--name=} {--password=} {--force}`. Prompt interactively for missing options. Use $this->secret('Password:') for password input. Delegate to AdminUserService::createAdmin(). `--force` flag behavior (FR-E04-026b): if email does not exist, create the admin account normally; if email already exists and `--force` IS provided, exit with code 0 and output "Admin account already exists; no changes made" (idempotent no-op — existing account is NOT modified); if email already exists and `--force` is NOT provided, exit with non-zero code and error "Admin with this email already exists. Use --force to skip." Does NOT bypass password complexity validation. Writes audit record with actor_type=SYSTEM on first-time creation only. Depends on T043.
- [X] T047 [US2] Register POST /v1/admin/users route in `routes/modules/identity.php` inside admin middleware group: `identity.require-permission:admin.user.create` → AdminUserController::store. Depends on T045, T033.
- [X] T048 [P] [US2] Write feature test `tests/Feature/Modules/Identity/AdminUserCreationTest.php` — test: (1) creates admin user, 201, no password in response; (2) duplicate Idempotency-Key returns same 201 (idempotent replay); (3) duplicate email returns 409 ADMIN_USER_EXISTS; (4) password below complexity returns 400; (5) ADMIN without admin.user.create returns 403; (6) audit record identity.admin_user.created written with masked email.
- [X] T049 [P] [US2] **[BACKLOG — non-blocking; command behaviour covered by T048 feature test and manual quickstart validation]** Write unit test `tests/Unit/Modules/Identity/CreateAdminCommandTest.php` — test: (1) creates admin and outputs success; (2) --force flag = idempotent no-op (FR-E04-026b); (3) existing email without --force → error exit.

**Checkpoint**: `php artisan identity:create-admin --email=admin@test.local --name="Test" --password="Admin123!"` succeeds. `POST /v1/admin/users` with Idempotency-Key replays correctly.

---

## Phase 6: US-PERM-008 (P1) — View Admin User List and Details

**Goal**: Admins can list and view admin user accounts. No credential data in responses.

**Independent Test**: `GET /v1/admin/users` with ADMIN token → paginated list of admin accounts. `GET /v1/admin/users/{unknown-id}` → 404 ADMIN_USER_NOT_FOUND. CUSTOMER token on either endpoint → 403.

- [X] T050 [P] [US8] Add index() to `app/Modules/Identity/Infrastructure/Http/Controllers/AdminUserController.php` — list admin users (actor_type=ADMIN), paginated with page/per_page (max 100), optional status filter, return without credential data. Delegates to AdminUserService::listAdmins(). Depends on T045.
- [X] T051 [P] [US8] Add show(string $id) to AdminUserController — return single admin user profile including roles (default_role + additional_roles). 404 ADMIN_USER_NOT_FOUND if not found. Delegates to AdminUserService::getAdmin().
- [X] T052 [P] [US8] Add listAdmins(array $filters): LengthAwarePaginator and getAdmin(string $id): array to `AdminUserService` — listAdmins: query identity.users where actor_type=ADMIN with optional status filter; getAdmin: load user with default_role (from roles table) and additional_roles (from user_roles); return 404-equivalent (null) if not found.
- [X] T053 [US8] Register GET /v1/admin/users and GET /v1/admin/users/{id} routes in `routes/modules/identity.php` with `identity.require-permission:admin.user.read`. Depends on T050, T051, T033.
- [X] T054 [P] [US8] Write feature test `tests/Feature/Modules/Identity/AdminUserViewTest.php` — test: (1) list returns paginated admin accounts, no passwords; (2) show returns full profile with roles; (3) non-existent ID returns 404 ADMIN_USER_NOT_FOUND; (4) CUSTOMER token returns 403; (5) status filter on list works.

**Checkpoint**: `GET /v1/admin/users` returns at least the seeded admin created in Phase 5. Response has `meta.total`, `meta.page`. No `password` or hash in any response field.

---

## Phase 7: US-PERM-003 (P1) — Deactivate Admin User

**Goal**: Admins can deactivate other admin accounts. All sessions are revoked immediately. Self-deactivation is blocked.

**Independent Test**: Deactivate Admin B → Admin B's next API call returns 401. Attempt self-deactivation → 422 ADMIN_SELF_DEACTIVATE. Response includes `sessions_revoked` count.

- [X] T055 [US3] Add deactivate(string $targetUserId, string $actorId, string $correlationId): array and reactivate(string $targetUserId, string $correlationId): array to `AdminUserService` — deactivate: self-deactivation guard; set status=inactive; revoke sessions; write audit identity.admin_user.deactivated; reactivate: set status=active (no session creation, no audit event per FR-E04-024; same-status = no-op). Depends on T043.
- [X] T056 [P] [US3] Create `app/Modules/Identity/Infrastructure/Http/Requests/UpdateAdminUserStatusRequest.php` — validate: status (required, in:active,inactive)
- [X] T057 [US3] Add updateStatus(string $id, UpdateAdminUserStatusRequest $request) to AdminUserController — branch on validated status: status=inactive → AdminUserService::deactivate(); status=active → AdminUserService::reactivate(); return 200 with sessions_revoked count; return 422 ADMIN_SELF_DEACTIVATE on self-deactivation; return 404 ADMIN_USER_NOT_FOUND for unknown user. Depends on T055, T056.
- [X] T058 [US3] Register PATCH /v1/admin/users/{id}/status route with `identity.require-permission:admin.user.deactivate`. Depends on T057, T033.
- [X] T059 [P] [US3] Write feature test `tests/Feature/Modules/Identity/AdminUserDeactivationTest.php` — test: (1) deactivate Admin B → 200 with sessions_revoked > 0; (2) Admin B's existing token returns 401 on next call; (3) self-deactivation returns 422 ADMIN_SELF_DEACTIVATE; (4) non-existent user returns 404; (5) audit record identity.admin_user.deactivated written with sessions_revoked count; (6) ADMIN without admin.user.deactivate returns 403.

**Checkpoint**: Create Admin B, log in as B (get token), deactivate B as Admin A, attempt `GET /v1/admin/roles` with B's token → 401.

---

## Phase 8: US-PERM-001 (P1) — Admin Views Roles and Permissions

**Goal**: Admins can view the full permission catalogue and see which permissions are assigned to each role.

**Independent Test**: `GET /v1/admin/permissions` → 15 permissions, all with `is_system_permission: true`. `GET /v1/admin/roles/admin-role-id` → includes all 15 seeded permission slugs. CUSTOMER token → 403.

- [X] T060 [P] [US1] Create `app/Modules/Identity/Infrastructure/Http/Controllers/AdminPermissionController.php` — index() lists all permissions (paginated, optional module filter). Delegates to PermissionRepositoryInterface::findAll(). Depends on T019.
- [X] T061 [P] [US1] Extend GET /v1/admin/roles/{id} response in AdminRoleController::show() to include full permission objects (id, slug, display_name) not just slugs — update AdminRoleService::findById() to eager-load permissions. Depends on T037.
- [X] T062 [US1] Register GET /v1/admin/permissions route in `routes/modules/identity.php` with `identity.require-permission:admin.permission.read`. Depends on T060, T033.
- [X] T063 [P] [US1] Write feature test `tests/Feature/Modules/Identity/AdminPermissionsViewTest.php` — test: (1) GET /v1/admin/permissions returns 15 permissions, all is_system_permission=true; (2) GET /v1/admin/roles/{admin-id} includes all 15 permission objects; (3) GET /v1/admin/roles returns seeded roles with permissions array; (4) CUSTOMER token returns 403; (5) pagination meta correct.

**Checkpoint**: `GET /v1/admin/permissions` returns exactly 15 rows. `GET /v1/admin/roles` → admin role permissions array has 15 entries.

---

## Phase 9: US-RBAC-003 (P0) — Admin Manages Role Lifecycle

**Goal**: Non-system roles can be soft-deleted. System roles are protected. Soft-deleted role slug remains unique (new role creation with same slug blocked).

**Independent Test**: Create role `auditor`, soft-delete it → `GET /v1/admin/roles` no longer includes it. Attempt to delete `admin` role → 422 SYSTEM_ROLE_DELETION_DENIED. Attempt to create new role with slug `auditor` → 409 ROLE_SLUG_CONFLICT.

- [X] T064 [US10] Add softDelete(string $id, string $actorId): void to `AdminRoleService` — if role.is_system_role=true throw SYSTEM_ROLE_DELETION_DENIED; call RoleRepository::softDelete($id) (sets deleted_at). Write audit if appropriate. Depends on T037.
- [X] T065 [US10] Add destroy(string $id) to `AdminRoleController` — calls AdminRoleService::softDelete(); returns 200 on success, 422 SYSTEM_ROLE_DELETION_DENIED for system roles, 404 ROLE_NOT_FOUND for unknown. Depends on T064.
- [X] T066 [US10] Register DELETE /v1/admin/roles/{id} route with `identity.require-permission:admin.role.manage`. Depends on T065, T033.
- [X] T067 [P] [US10] Write feature test `tests/Feature/Modules/Identity/AdminRoleLifecycleTest.php` — test: (1) soft-delete non-system role → 200, no longer in GET list; (2) delete customer/agent/admin → 422 SYSTEM_ROLE_DELETION_DENIED; (3) deleted role slug blocks new role creation → 409 ROLE_SLUG_CONFLICT; (4) ADMIN without admin.role.manage returns 403.

---

## Phase 10: US-PERM-007 (P2) — Manage Role-Permission Assignments

**Goal**: Admins can add/remove permissions from roles. Cache is invalidated for all affected users. Audit records written.

**Independent Test**: Assign `admin.user.create` to `auditor` role → log in as an auditor user → JWT permissions claim includes `admin.user.create`. Remove it → next login excludes the permission. Invalid role/permission → 404.

- [X] T068 [US7] Add assignPermission(string $roleId, string $permissionId, string $actorId, string $correlationId): void and removePermission(string $roleId, string $permissionId): void to `AdminRoleService` — validate role and permission exist; assignPermission: call RolePermissionRepository::assignPermission, write audit identity.role.permission.assigned, invalidate Redis cache for all users with this role (see note below); removePermission: call removePermission, write audit identity.role.permission.removed, invalidate caches. Depends on T037, T022.
- [X] T069 [P] [US7] Create `app/Modules/Identity/Infrastructure/Http/Controllers/AdminRolePermissionController.php` — store(string $roleId, Request $request): assign permission, return 200 or 409 PERMISSION_ALREADY_ON_ROLE, or 404 ROLE_NOT_FOUND / PERMISSION_NOT_FOUND; destroy(string $roleId, string $permissionId): remove permission, return 200 or 404 PERMISSION_NOT_ON_ROLE. Depends on T068.
- [X] T070 [US7] Register POST /v1/admin/roles/{id}/permissions and DELETE /v1/admin/roles/{id}/permissions/{permId} routes with `identity.require-permission:admin.role.manage`. Depends on T069, T033.
- [X] T071 [P] [US7] Write feature test `tests/Feature/Modules/Identity/AdminRolePermissionTest.php` — test: (1) assign permission → 200; (2) assign duplicate → 409 PERMISSION_ALREADY_ON_ROLE; (3) assign to non-existent role → 404 ROLE_NOT_FOUND; (4) assign non-existent permission → 404 PERMISSION_NOT_FOUND; (5) remove permission → 200; (6) remove not-assigned permission → 404 PERMISSION_NOT_ON_ROLE; (7) audit records written; (8) cache invalidated after assignment change.

> **Note on cache invalidation scope**: When a role's permissions change, all users who hold that role must have their Redis permission cache (`identity:permissions:{actorId}`) invalidated. Strategy: query `identity.user_roles` WHERE role_id = $roleId UNION default_role_id match in `identity.users` → collect actorIds → batch delete Redis keys. Invalidation MUST be synchronous within the same request cycle (SEC-E04-007).

---

## Phase 11: US-PERM-004 (P2) — Assign Role to User

**Goal**: Admins can add additional roles to a user and remove them. Cache is invalidated. Audit records written. Role assignments cumulate with the user's default role.

**Independent Test**: Assign `auditor` role to Admin B → log in as B → JWT roles claim includes both `admin` and `auditor`. Remove `auditor` → next login JWT has only `admin`.

- [X] T072 [US4] Add assignRole(string $userId, string $roleId, string $actorId, string $correlationId): void and removeRole(string $userId, string $roleId): void to `AdminUserService` — validate user and role exist; assignRole: FIRST check whether $roleId equals user.default_role_id — if so, throw ROLE_ALREADY_ASSIGNED (409) without inserting into user_roles (EC-11, FR-E04-034); then call UserRoleRepository::assignRole, write audit identity.user.role.assigned, call PermissionResolverService::invalidateCache($userId); removeRole: call removeRole, write audit identity.user.role.removed, invalidate cache. Depends on T043, T022.
- [X] T073 [P] [US4] Add getUserRoles(string $userId): array to AdminUserService — returns default_role (from users.default_role_id) and additional_roles (from user_roles table). 404 if user not found.
- [X] T074 [US4] Create `app/Modules/Identity/Infrastructure/Http/Controllers/AdminRoleAssignmentController.php` — index(string $userId): 200 with default_role + additional_roles or 404 ADMIN_USER_NOT_FOUND; store(string $userId, Request $request): assigns role or 409 ROLE_ALREADY_ASSIGNED or 404; destroy(string $userId, string $roleId): removes assignment or 404. Depends on T072, T073.
- [X] T075 [US4] Register GET /v1/admin/users/{id}/roles (`admin.user.read`), POST /v1/admin/users/{id}/roles (`admin.role.assign`), DELETE /v1/admin/users/{id}/roles/{roleId} (`admin.role.assign`) routes. Depends on T074, T033.
- [X] T076 [P] [US4] Write feature test `tests/Feature/Modules/Identity/AdminRoleAssignmentTest.php` — test: (1) GET /v1/admin/users/{id}/roles returns default_role; (2) POST assigns role, 200; (3) assigns duplicate → 409 ROLE_ALREADY_ASSIGNED; (4) non-existent user → 404; (5) non-existent role → 404 ROLE_NOT_FOUND; (6) DELETE removes assignment, 200; (7) audit records written; (8) permission cache invalidated for target user; (9) POST with a role matching user's default_role_id (not in user_roles) → 409 ROLE_ALREADY_ASSIGNED (EC-11).

**Checkpoint**: Assign `auditor` role to admin user B. Log B in. Decode JWT — roles claim contains `["admin","auditor"]`.

---

## Phase 12: US-PERM-006 (P1) — Customer Cannot Access Admin (Security Validation)

**Goal**: Verify IDOR protections and actor-type enforcement work end-to-end. Implementation complete from Phase 3 (US5); this phase validates the security requirements.

**Independent Test**: Every admin route rejects CUSTOMER and AGENT tokens with 403 at the routing layer before any domain service executes.

- [X] T077 [US6] Write security test `tests/Feature/Modules/Identity/AdminRouteIdorTest.php` — test all admin endpoints (roles, permissions, users, role-permission management): (1) CUSTOMER token → 403 AUTHORIZATION_001 or 403 AUTHORIZATION_002; (2) AGENT token → 403; (3) 404 responses for non-existent IDs do NOT reveal ID format validity; (4) non-existent user ID on PATCH /v1/admin/users/{id}/status → 404 ADMIN_USER_NOT_FOUND (not 403, proving the request got through actor-type check but then hit 404 for data).
- [X] T078 [P] [US6] **[DEFERRED — implement before the first downstream epic (E05+) that introduces a new permission-gated flow. Primary purpose: verify FR-E04-018 (server-side authority is independent of JWT claims). SC-E04-001 is verified by T088 (static grep) + T084 (E01 regression on TokenService) — T078 is NOT the SC-E04-001 release gate.]** Write security test `tests/Security/Modules/Identity/JwtManipulationTest.php` — test: (1) a JWT with a manually crafted `permissions` claim containing admin permission slugs is denied by server-side `hasPermission()` (FR-E04-018: JWT claim does not grant authority — the persistent role-permission model is authoritative). Governs FR-E04-018.

---

## Phase 13: Polish & Cross-Cutting Concerns

**Purpose**: Concurrency safety, performance validation, regression, seed idempotency, and documentation.

- [X] T079 Write unit test `tests/Unit/Modules/Identity/PermissionResolverServiceTest.php` — test: (1) resolveAllForActor returns correct slugs from DB; (2) second call returns from Redis cache (no DB query); (3) invalidateCache clears Redis key; (4) returns empty array for unknown actorId (does not throw); (5) returns false from hasPermission when actor has no roles; (6) cache TTL is read from platform config auth.permission_cache_ttl; (7) when auth.permission_cache_ttl = 0, each hasPermission() call queries DB directly and does NOT read from or write to Redis (EC-08, FR-E04-055).
- [X] T080 [P] Write integration test `tests/Integration/Modules/Identity/SeedIdempotencyTest.php` — run E04 migrations twice; assert identity.roles count = 3, identity.permissions count = 15, identity.role_permissions count = 15, platform.configuration_values has auth.permission_cache_ttl; no duplicate records (unique constraint not violated).
- [X] T081 [P] Write integration test `tests/Integration/Modules/Identity/BackfillMigrationTest.php` — seed a CUSTOMER user with null default_role_id before migration; run migration; assert user.default_role_id = customer role UUID; assert FK constraint exists; assert unrecognized actor_type user retains null default_role_id and warning was logged.
- [X] T082 [P] Write concurrency test `tests/Concurrency/Modules/Identity/HasPermissionConcurrencyTest.php` — launch 10 concurrent processes calling hasPermission() for the same actor immediately after cache invalidation; assert all return consistent results (no data race); assert concurrent admin user creation with same email: exactly one 201 and one 409.
- [X] T083 Write performance test `tests/Performance/Modules/Identity/HasPermissionPerformanceTest.php` — warm Redis cache for a test actor; measure P95 latency over 100 calls to hasPermission(); assert P95 ≤ 10 ms (SC-E04-010).
- [X] T084 [P] Run E01 regression — execute E01 test suite; assert all TokenService tests pass (JWT now contains explicit permission slugs, not wildcard); assert RegistrationService test creates new customer with non-null default_role_id.
- [X] T085 [P] Run E03 regression — execute E03 test suite; assert admin device routes still work with ADMIN token + device.admin.* permissions; assert inline check removal did not break existing E03 tests.
- [X] T086 [P] Update OpenAPI documentation — add E04 endpoints (admin roles, permissions, users) to `docs/openapi/` or equivalent; update admin route group annotations.
- [X] T087 [P] Run quickstart.md validation scenarios — execute all 11 scenarios from `specs/004-roles-permissions-authorization/quickstart.md` sequentially; document pass/fail per scenario; all must pass before release.
- [X] T088 [P] Static analysis check — grep codebase for `['*']` in permissions claims; assert no occurrences remain (SC-E04-001); grep for inline `strtoupper($actorType) === 'ADMIN'` in controllers and form requests; assert no occurrences remain (SC-E04-002).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 completion — BLOCKS all story phases
- **Phase 3 (US5)**: Depends on Phase 2 — MUST complete before any admin API is accessible
- **Phases 4–12 (User Stories)**: All depend on Phase 2 and Phase 3; can proceed in any order thereafter
- **Phase 13 (Polish)**: Depends on all story phases being complete

### User Story Dependencies Within Story Phases

- **US5 (Phase 3)**: Foundation for all admin endpoint security — complete first
- **US9 (Phase 4)**: Role creation API needed by US7 (phase 10) and US10 (phase 9)
- **US2 (Phase 5)**: AdminUserService created here; shared by US8, US3, US4
- **US8 (Phase 6)**: AdminUserController extended from Phase 5 — parallel with Phase 5
- **US3 (Phase 7)**: Extends AdminUserService from Phase 5
- **US1 (Phase 8)**: AdminPermissionController is independent; AdminRoleController extended from Phase 4
- **US10 (Phase 9)**: Extends AdminRoleService from Phase 4
- **US7 (Phase 10)**: Extends AdminRoleService from Phase 4; depends on Phase 9 for role existence
- **US4 (Phase 11)**: Extends AdminUserService from Phase 5; depends on Phase 4 for role existence
- **US6 (Phase 12)**: Tests only — implementation complete by Phase 3; run after all story phases

### Parallel Opportunities

Within each phase, tasks marked [P] can run in parallel:

```bash
# Phase 1 parallel groups:
Group A (parallel): T001, T002 (independent table migrations)
Group B (parallel): T010, T011, T012, T013 (independent models)
Group C (parallel): T015, T016, T017, T019, T020, T021 (independent repos — after T010-T013)

# Phase 2 parallel:
T023 [P with T022] (contract declaration parallel with service implementation)
T026 [P with T025] (RegistrationService update parallel with TokenService update)

# Phase 3 parallel:
T028, T029 (two middleware classes — different files)
T031, T032 (two form request removals — different files)
T034, T035, T036 (test files — all parallel)

# Phase 4–11: [P] tasks within each phase can run together
```

---

## Implementation Strategy

### MVP (Phase 1 + Phase 2 + Phase 3 — Authorization Core)

1. Complete Phase 1: Migrations, models, repositories
2. Complete Phase 2: PermissionResolverService, hasPermission(), TokenService, RegistrationService, middleware registration
3. Complete Phase 3: Middleware classes, inline check removal, route protection
4. **STOP AND VALIDATE**: Admin endpoints are protected. Wildcard eliminated from JWT. Existing E01/E03 tests pass.

### Incremental Delivery

1. MVP above → Validate E01/E03 regression + SC-E04-001/002
2. Add Phase 4 (US9 - role creation) → Roles can be created and listed
3. Add Phase 5+6 (US2+US8 - admin user CRUD) → Admin user management complete
4. Add Phase 7 (US3 - deactivation) → Full admin lifecycle
5. Add Phase 8 (US1 - permissions view) → Full observability of access model
6. Add Phase 9+10+11 (US10+US7+US4 - role management) → Full role-based access control
7. Complete Phase 12+13 (security + polish) → Release-ready

---

## Notes

- All migrations use `DB::connection('identity')` for identity schema tables and `DB::connection('platform')` for platform configuration.
- All API endpoints follow the E01/E03 response envelope: `{ "success": true, "data": {}, "meta": {} }`.
- Correlation ID (`X-Correlation-ID`) must be propagated in all responses.
- `PermissionResolverService::invalidateCache()` must be called synchronously (not deferred to queue) for all role/permission assignment changes.
- Tasks T028–T088 use paths relative to `wallet-ecosystem/services/api/`.
- `[P]` tasks operate on different files with no shared dependencies — safe to run in parallel.
- Mark each task `[x]` immediately on completion to maintain traceability.
