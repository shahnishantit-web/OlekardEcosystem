# E04 — Roles, Permissions & Authorization

**Feature**: `004-roles-permissions-authorization` | **Epic**: E04 | **Status**: Specified | **Created**: 2026-09-09

---

## 1. Problem Statement

The Wallet Ecosystem's authentication infrastructure (E01) establishes *who* is calling. Nothing currently establishes *what* they are authorized to do in a formal, data-driven way.

Three categories of temporary authorization arrangements are present in the current codebase:

1. **Wildcard ADMIN grant** (`TokenService.php:63,134`): All ADMIN tokens carry `'permissions' => ['*']`. This is an explicit placeholder that grants unrestricted access to any caller with `actor_type = 'ADMIN'`, regardless of what actions they are actually permitted to take.
2. **Inline `actor_type` checks**: `AdminSessionController`, `ConfigurationUpdateRequest`, and `ConfigurationIndexRequest` each contain inline `strtoupper($actorType) === 'ADMIN'` comparisons in place of genuine permission checks. Code comments explicitly note "enforced inline until E04 ships."
3. **Unimplemented interface method**: `AuthorizationGuard::require()` calls `IdentityModuleContract::hasPermission()`, which is referenced in the Platform module's authorization service but is not declared in the contract or implemented anywhere.

Additionally, the `identity.users` table carries a `default_role_id` nullable UUID column, and `RegistrationService` sets it to `null` with the comment "resolved by E04 role seed; null until E04 provides the CUSTOMER role." E01 Business Rule BR-012 mandates that a default CUSTOMER role MUST be assigned to new registrations — but the role does not yet exist.

E04 formalizes the role and permission model, eliminates all temporary authorization arrangements, and provides the enforcement infrastructure that downstream domains depend on for access control.

---

## Clarifications

### Session 2026-09-09

- Q: What mechanism bootstraps the first admin user when no admin exists to call `POST /v1/admin/users`? → A: Option B — an Artisan CLI command `php artisan identity:create-admin` that accepts email, display name, and password as arguments (or interactive prompts). The command bypasses the HTTP authorization layer and is not exposed via any API endpoint. It creates the user record, provisions the password credential via `CredentialService`, and sets `default_role_id` to the seeded `admin` role UUID. The command must be idempotent with `--force` flag to prevent accidental double-creation.

---

## 2. Scope

E04 covers:

- **Role and permission data model** in the `identity` schema (data-driven, not hard-coded)
- **Seeded standard roles**: CUSTOMER, AGENT, ADMIN
- **Seeded permission catalogue**: all permissions currently referenced by E01 and E03
- **`hasPermission(string $actorId, string $permission): bool`** declared on `IdentityModuleContract` and implemented in `IdentityModule`
- **JWT claim correction**: `roles` and `permissions` claims populated from the role-permission model; wildcard `['*']` eliminated
- **Authorization middleware**: `identity.require-permission:{permission}` and `identity.require-actor-type:{actorType}` registered middleware aliases
- **Migration from temporary arrangements**: inline checks replaced by middleware/guard enforcement
- **Admin user management APIs**: create, list, view, deactivate admin users (E01 §4 explicitly defers this to E04)
- **Role and permission management APIs**: role CRUD, permission assignment to roles
- **User role-assignment APIs**: assign/remove additional roles for a user
- **IDOR and server-side authorization enforcement** for all admin APIs
- **Audit records** for all authorization state changes

---

## 3. Goals

1. Replace all temporary `actor_type === 'ADMIN'` inline checks with formal, data-driven permission enforcement.
2. Implement `IdentityModuleContract::hasPermission()` so `AuthorizationGuard::require()` resolves correctly.
3. Populate JWT `roles` and `permissions` claims from the role-permission model; remove the wildcard ADMIN grant.
4. Seed the CUSTOMER default role required by E01 BR-012 and FR-E01-008.
5. Provide a permission-governed API for creating, listing, viewing, and deactivating Admin user accounts.
6. Provide permission-governed APIs for managing roles, permissions, and user-role assignments.
7. Establish the permission naming convention and extensibility model for downstream domains (E05–E25).
8. Produce auditable records of all authorization model changes.

---

## 4. Non-Goals

E04 does NOT:

- Redesign or replace E01 (authentication, token issuance, credential management, session lifecycle).
- Redesign or replace E03 (device management, fingerprint change detection, session admin operations).
- Implement wallet, ledger, KYC, transaction, risk, or notification logic.
- Define permissions for domains not yet implemented (E05–E25); those epics extend the permission catalogue at implementation time via idempotent seed migrations.
- Implement the Admin Portal web UI (Next.js) beyond what requires a backend API from this epic.
- Provide fine-grained row-level security or attribute-based access control (ABAC); E04 establishes role-based permission checks.
- Implement Enterprise actor roles (see TBD-E04-001).
- Change the approved technology stack.

---

## 5. Actors

| Actor | Description | Authorization Model |
|---|---|---|
| **Customer** | End-user of the Customer mobile application | `actor_type = CUSTOMER`. Authorized by resource-ownership checks (e.g., own wallet, own devices). CUSTOMER role carries no platform permissions at this time. |
| **Agent** | Registered field agent using the Agent mobile application | `actor_type = AGENT`. AGENT role permissions to be resolved (see TBD-E04-003). |
| **Admin** | Administrator using the Admin/Enterprise web application | `actor_type = ADMIN`. Authorized by explicit permission grants on the ADMIN role. Replaces the current wildcard. |
| **Enterprise** | Corporate user of the Enterprise web application | Not implemented. See TBD-E04-001. |
| **Service** | Internal backend service using a JWT service-identity token | `actor_type = SERVICE`. Uses `scopes` claim (not `permissions`). `hasPermission()` behavior for SERVICE actors is out of scope for E04; no change to the service authorization model. |
| **System** | Background platform processes | `actor_type = SYSTEM`. No user-facing authorization changes. |

### Actions Requiring Explicit Permission (Admin only)

| Action | Required Permission |
|---|---|
| Create admin user | `admin.user.create` |
| List / view admin users | `admin.user.read` |
| Deactivate admin user | `admin.user.deactivate` |
| List / view roles | `admin.role.read` |
| Create role | `admin.role.create` |
| Assign / remove roles on a user | `admin.role.assign` |
| Manage role-permission assignments | `admin.role.manage` |
| List / view permissions | `admin.permission.read` |
| View platform configuration | `configuration.policy.view` |
| Update platform configuration | `configuration.policy.update` |
| Admin session revocation | `session.admin.revoke` |
| View user devices (admin) | `device.admin.read` |
| Block user device (admin) | `device.admin.block` |
| Remove user device (admin) | `device.admin.remove` |
| Unblock user device (admin) | `device.admin.unblock` |

---

## 6. User Stories

### US-PERM-001 — Admin views roles and permissions (Priority: P1)

As an Admin, I want to view the list of defined roles and their assigned permissions so that I understand the current access control model.

**Why this priority**: Understanding what roles and permissions exist is a prerequisite for every role assignment and delegation decision.

**Independent Test**: Can be fully tested by calling `GET /v1/admin/roles` with a valid admin token and verifying the seeded roles appear in the response.

**Acceptance Scenarios**:

1. **Given** an authenticated ADMIN with `admin.role.read` permission, **When** they call `GET /v1/admin/roles`, **Then** the response lists all non-deleted roles with their slugs and assigned permission slugs in a paginated envelope.
2. **Given** an authenticated CUSTOMER token, **When** they call `GET /v1/admin/roles`, **Then** they receive HTTP 403 with `{ "code": "AUTHORIZATION_001" }`.
3. **Given** an unauthenticated request, **When** they call `GET /v1/admin/roles`, **Then** they receive HTTP 401.

---

### US-PERM-002 — Admin creates an admin user (Priority: P1)

As a super-admin, I want to create a new admin user account so that another person can access the admin application.

**Why this priority**: Admin user management is the most urgent gap — no formal API for creating admin accounts exists (E01 §4 explicitly deferred this to E04).

**Independent Test**: Can be tested by creating an admin user via `POST /v1/admin/users` and verifying the user can subsequently log in and receive an ADMIN token.

**Acceptance Scenarios**:

1. **Given** an authenticated ADMIN with `admin.user.create` permission, **When** they call `POST /v1/admin/users` with valid email, password, and display name, **Then** a new admin user is created with `actor_type = ADMIN`, a password credential is provisioned, the `admin` role is assigned, and an audit record `identity.admin_user.created` is written.
2. **Given** the same email already exists in the system, **When** an admin attempts to create another user with that email, **Then** the system returns HTTP 409 with `{ "code": "ADMIN_USER_EXISTS" }`.
3. **Given** an ADMIN without `admin.user.create` permission, **When** they attempt to create an admin user, **Then** they receive HTTP 403 with `{ "code": "AUTHORIZATION_001" }` and the denial is recorded in `platform.audit_events`.
4. **Given** the request includes a password that fails E01 complexity rules, **When** the request is processed, **Then** HTTP 400 is returned with validation details.

---

### US-PERM-003 — Admin deactivates an admin user (Priority: P1)

As an Admin, I want to deactivate another admin user account so that they immediately lose access when they leave the organization.

**Why this priority**: Access revocation on personnel change is a fundamental security control.

**Independent Test**: Can be tested by deactivating an admin user and verifying their existing access token is rejected on the next API call.

**Acceptance Scenarios**:

1. **Given** an authenticated ADMIN with `admin.user.deactivate` permission, **When** they call `PATCH /v1/admin/users/{id}/status` with `{ "status": "inactive" }`, **Then** the target admin account is deactivated, all their active sessions are immediately revoked, and `identity.admin_user.deactivated` is written to audit.
2. **Given** an admin attempts to deactivate their own account, **When** they call `PATCH /v1/admin/users/{self_id}/status`, **Then** the system returns HTTP 422 with `{ "code": "ADMIN_SELF_DEACTIVATE" }`.
3. **Given** the target user ID does not exist, **When** the request is made, **Then** HTTP 404 with `{ "code": "ADMIN_USER_NOT_FOUND" }` is returned.

---

### US-PERM-004 — Admin assigns a role to a user (Priority: P2)

As an Admin, I want to assign an additional role to a user so that I can grant them specialist capabilities beyond their default role.

**Acceptance Scenarios**:

1. **Given** an authenticated ADMIN with `admin.role.assign` permission, **When** they call `POST /v1/admin/users/{userId}/roles` with a valid role ID, **Then** the role is added to the user's assignments and `identity.user.role.assigned` is written to audit.
2. **Given** the role is already assigned to the user, **When** the same assignment is attempted, **Then** HTTP 409 with `{ "code": "ROLE_ALREADY_ASSIGNED" }` is returned.
3. **Given** the target user or role does not exist, **When** the request is made, **Then** HTTP 404 is returned.

---

### US-PERM-005 — System automatically enforces and audits permission denials (Priority: P1)

As a developer building a downstream module, I want the platform to automatically deny and audit unauthorized API calls so that I do not need to implement per-endpoint deny logic in each controller.

**Independent Test**: Can be tested by calling any endpoint protected by `identity.require-permission:{permission}` with a token lacking that permission and verifying the 403 and audit record.

**Acceptance Scenarios**:

1. **Given** any API endpoint protected by `identity.require-permission:{permission}` middleware, **When** an actor without that permission calls the endpoint, **Then** HTTP 403 with `{ "code": "AUTHORIZATION_001" }` is returned without invoking the controller, and `platform.permission.denied` is written to `platform.audit_events`.
2. **Given** the same request from an actor WITH the required permission, **When** the endpoint is called, **Then** the request proceeds normally and no denial audit is written.
3. **Given** an endpoint protected by `identity.require-actor-type:admin`, **When** a CUSTOMER token calls it, **Then** HTTP 403 with `{ "code": "AUTHORIZATION_002" }` is returned.

---

### US-PERM-006 — Customer cannot access admin functionality (Priority: P1)

As a Customer, if I accidentally or maliciously call an admin endpoint, I should receive a clear error with no information leakage about what the admin endpoint does or requires.

**Acceptance Scenarios**:

1. **Given** an authenticated CUSTOMER token, **When** they call any `/v1/admin/*` endpoint, **Then** HTTP 403 is returned. The error body MUST NOT reveal administrative resource details or permission slugs.
2. **Given** an unauthenticated request, **When** it targets any `/v1/admin/*` endpoint, **Then** HTTP 401 is returned.

---

### US-PERM-007 — Admin manages role-permission assignments (Priority: P2)

As an Admin, I want to add or remove permissions from a role so that I can adjust what capabilities a group of users holds.

**Acceptance Scenarios**:

1. **Given** an ADMIN with `admin.role.manage` permission, **When** they call `POST /v1/admin/roles/{roleId}/permissions` with a valid permission ID, **Then** the permission is assigned to the role and `identity.role.permission.assigned` is written to audit.
2. **Given** an ADMIN with `admin.role.manage` permission, **When** they call `DELETE /v1/admin/roles/{roleId}/permissions/{permissionId}`, **Then** the permission is removed from the role and `identity.role.permission.removed` is written to audit.
3. **Given** an ADMIN attempts to remove a permission from a role that does not hold it, **Then** HTTP 404 with `{ "code": "PERMISSION_NOT_ON_ROLE" }` is returned.

---

### US-RBAC-001 — Admin creates a role (Priority: P0)

As an Admin, I want to create a custom role so that I can define a named permission set for users with specific responsibilities.

**Why this priority**: Role creation is foundational infrastructure — without roles, no data-driven permission model exists.

**Independent Test**: Can be fully tested by calling `POST /v1/admin/roles` with a unique slug and display name, verifying the role appears in `GET /v1/admin/roles` with zero permissions assigned.

**Acceptance Scenarios**:

1. **Given** an authenticated ADMIN with `admin.role.create` permission, **When** they call `POST /v1/admin/roles` with a unique URL-safe slug and a display name, **Then** HTTP 201 is returned with the new role (`is_system_role = false`, empty permissions array), and the role appears in subsequent `GET /v1/admin/roles` responses.
2. **Given** an ADMIN attempts to create a role with a slug matching an existing active or soft-deleted role, **When** the request is made, **Then** HTTP 409 with `{ "code": "ROLE_SLUG_CONFLICT" }` is returned.
3. **Given** an ADMIN without `admin.role.create` permission, **When** they attempt to create a role, **Then** HTTP 403 with `{ "code": "AUTHORIZATION_001" }` is returned.
4. **Given** the E04 seed migration has run, **When** `GET /v1/admin/roles` is called with a valid ADMIN token, **Then** the response includes the three seeded system roles: `customer`, `agent`, and `admin`, each with `is_system_role = true`.

---

### US-RBAC-003 — Admin manages role lifecycle (Priority: P2)

As an Admin, I want to soft-delete a non-system role that is no longer needed so that it is removed from active use while historical assignment records are preserved.

**Why this priority**: Role lifecycle management prevents the role catalogue from accumulating unused entries and protects system roles from accidental removal.

**Independent Test**: Can be tested by soft-deleting a non-system role and verifying it no longer appears in `GET /v1/admin/roles`, then verifying that attempting to delete a system role returns the expected error.

**Acceptance Scenarios**:

1. **Given** an ADMIN with the appropriate permission, **When** a non-system role is soft-deleted, **Then** it no longer appears in active role listings, and its `deleted_at` timestamp is set in the database.
2. **Given** an ADMIN attempts to delete a system-seeded role (`customer`, `agent`, or `admin`), **When** the deletion request is made, **Then** HTTP 422 with `{ "code": "SYSTEM_ROLE_DELETION_DENIED" }` is returned.
3. **Given** a soft-deleted role whose slug is `auditor`, **When** an ADMIN attempts to create a new role with slug `auditor`, **Then** HTTP 409 with `{ "code": "ROLE_SLUG_CONFLICT" }` is returned (slugs are globally unique across active and soft-deleted records).

---

### US-PERM-008 — Admin views admin user list and details (Priority: P1)

As an Admin, I want to list and view the details of existing admin user accounts so that I can understand who currently has administrative access to the system.

**Why this priority**: Visibility into admin accounts is a fundamental audit and access governance control.

**Independent Test**: Can be fully tested by creating an admin user via `POST /v1/admin/users` and confirming it appears in `GET /v1/admin/users` and `GET /v1/admin/users/{id}` with correct profile data and no credential information.

**Acceptance Scenarios**:

1. **Given** an authenticated ADMIN with `admin.user.read` permission, **When** they call `GET /v1/admin/users`, **Then** a paginated list of admin accounts is returned. Response MUST NOT include any credential data (no password, no hash).
2. **Given** an authenticated ADMIN with `admin.user.read` permission, **When** they call `GET /v1/admin/users/{id}` for an existing admin user, **Then** the full profile is returned including the user's assigned roles.
3. **Given** a non-existent user ID, **When** `GET /v1/admin/users/{id}` is called, **Then** HTTP 404 with `{ "code": "ADMIN_USER_NOT_FOUND" }` is returned. The response MUST NOT reveal whether the ID format is valid.
4. **Given** an authenticated CUSTOMER or AGENT token, **When** either the list or detail endpoint is called, **Then** HTTP 403 is returned.

---

## 7. Business Rules

| ID | Rule |
|---|---|
| BR-E04-001 | Roles and permissions MUST be stored in a persistent, data-driven registry in the `identity` schema. Hard-coded role or permission logic in domain services is prohibited. |
| BR-E04-002 | Permission slugs MUST follow the convention `{module}.{resource}.{action}` (e.g., `admin.user.create`, `configuration.policy.update`, `device.admin.block`). |
| BR-E04-003 | Role slugs MUST be lowercase, unique, and URL-safe (e.g., `customer`, `agent`, `admin`). |
| BR-E04-004 | No role MUST receive a wildcard (`*`) permission grant. All permission grants MUST be explicit entries in the role-permission assignment table. The wildcard `['*']` in `TokenService` is temporary and MUST be removed by this epic. |
| BR-E04-005 | After E04 seed migration runs, the CUSTOMER role slug MUST be `customer`, and MUST be used as the `default_role_id` for Customer registrations to satisfy E01 BR-012 and FR-E01-008. |
| BR-E04-006 | Permission evaluation for `hasPermission()` MUST resolve via the persistent role-permission model. JWT claims are for client-side UI optimization; server-side authorization decisions are authoritative. |
| BR-E04-007 | An admin MUST NOT be able to deactivate their own account via the admin user deactivation API. |
| BR-E04-008 | Deactivating an admin account MUST immediately revoke all active sessions for that account by calling the existing session revocation mechanism — not a direct database UPDATE on the session records. |
| BR-E04-009 | Role and permission seeding migrations MUST be idempotent: re-running them MUST NOT create duplicate records. Existing records MUST be preserved. |
| BR-E04-010 | All state-changing authorization operations (role creation, permission assignment, role assignment changes, admin user creation/deactivation) MUST produce immutable audit records in `platform.audit_events`. |
| BR-E04-011 | The `platform.permission.denied` audit record is already written by `AuthorizationGuard::require()`. E04 MUST NOT duplicate this audit sink. |
| BR-E04-012 | Authorization MUST be enforced server-side. Client-reported permissions or roles MUST NOT be the basis for authorization decisions. |
| BR-E04-013 | `hasPermission()` MUST return `false` (not throw) when the actor ID does not exist in the identity store or when the actor has no role-permission entries. |
| BR-E04-014 | `POST /v1/admin/users` MUST support client-controlled idempotency via `Idempotency-Key` header (implemented via `IdempotencyMiddleware`). Other resource-creating admin APIs — `POST /v1/admin/roles`, `POST /v1/admin/roles/{id}/permissions`, and `POST /v1/admin/users/{id}/roles` — achieve safe idempotency through unique database constraints: a re-sent identical request returns a deterministic 409 conflict response (`ROLE_SLUG_CONFLICT`, `PERMISSION_ALREADY_ON_ROLE`, or `ROLE_ALREADY_ASSIGNED` respectively) rather than creating a duplicate. Explicit `Idempotency-Key` infrastructure is not required on these three endpoints. |
| BR-E04-015 | Permission catalogue entries MUST be discoverable via Admin API without requiring direct database access. |
| BR-E04-016 | System-seeded roles (CUSTOMER, AGENT, ADMIN) MUST be protected from deletion. Attempting to physically delete them MUST be rejected. |
| BR-E04-017 | The FK constraint on `identity.users.default_role_id` → `identity.roles.id` MUST be added by E04 migration. The column is nullable; `ON DELETE SET NULL` behaviour applies. |

---

## 8. Functional Requirements

### 8.1 Role and Permission Data Model

| ID | Story | Requirement |
|---|---|---|
| FR-E04-001 | US-RBAC-001 | The system MUST maintain a persistent role registry in the `identity` schema as table `identity.roles`. Each role record MUST contain: UUID v7 primary key, unique slug, display name, optional description, `is_system_role` boolean (prevents deletion of seeded roles), soft-delete timestamp, and standard `created_at` / `updated_at` timestamps. |
| FR-E04-002 | US-RBAC-001 | The system MUST maintain a persistent permission registry in the `identity` schema as table `identity.permissions`. Each permission record MUST contain: UUID v7 primary key, unique slug following the `{module}.{resource}.{action}` convention, display name, optional description, owning module identifier, `is_system_permission` boolean, soft-delete timestamp, and standard timestamps. |
| FR-E04-003 | US-PERM-007 | The system MUST maintain a role-permission assignment table `identity.role_permissions` that records which permissions a role holds. The table MUST enforce a unique constraint on `(role_id, permission_id)`. |
| FR-E04-004 | US-PERM-004 | The system MUST maintain a user-role assignment table `identity.user_roles` for additional role assignments beyond the user's `default_role_id`. The table MUST enforce a unique constraint on `(user_id, role_id)`. |
| FR-E04-005 | US-RBAC-003 | Soft-delete (logical delete via `deleted_at`) MUST be used for roles and permissions. Physical deletion of a system-seeded role MUST be rejected with error `SYSTEM_ROLE_DELETION_DENIED`. Physical deletion of a system-seeded permission MUST be rejected with error `SYSTEM_PERMISSION_DELETION_DENIED`. _(The permission-deletion guard applies when a `DELETE /v1/admin/permissions/{id}` endpoint is introduced by a future epic; no such endpoint is defined or implemented in E04.)_ |

### 8.2 Seeded Roles and Permissions

| ID | Story | Requirement |
|---|---|---|
| FR-E04-006 | US-RBAC-001 | The system MUST seed the following standard roles in an idempotent migration (insert if not exists by slug): `customer` (display name: Customer), `agent` (display name: Agent), `admin` (display name: Administrator). All three MUST have `is_system_role = true`. |
| FR-E04-007 | US-RBAC-001, US-PERM-007 | The system MUST seed the permission catalogue below and assign all listed permissions to the `admin` role. The seed MUST be idempotent (insert if not exists by slug). |

**Seeded Permission Catalogue (FR-E04-007):**

| Permission Slug | Module | Assigned to Role(s) | Source |
|---|---|---|---|
| `admin.user.create` | identity | admin | E04 §8.5 |
| `admin.user.read` | identity | admin | E04 §8.5 |
| `admin.user.deactivate` | identity | admin | E04 §8.5 |
| `admin.role.read` | identity | admin | E04 §8.6 |
| `admin.role.create` | identity | admin | E04 §8.6 |
| `admin.role.assign` | identity | admin | E04 §8.7 |
| `admin.role.manage` | identity | admin | E04 §8.6 |
| `admin.permission.read` | identity | admin | E04 §8.6 |
| `configuration.policy.view` | platform | admin | E01 FR-E01-042 |
| `configuration.policy.update` | platform | admin | E01 FR-E01-042 |
| `session.admin.revoke` | identity | admin | E01 FR-E01-025, E03 |
| `device.admin.read` | identity | admin | E03 FR-E03-033 |
| `device.admin.block` | identity | admin | E03 §8 |
| `device.admin.remove` | identity | admin | E03 §8 |
| `device.admin.unblock` | identity | admin | E03 §8 |

| ID | Story | Requirement |
|---|---|---|
| FR-E04-008 | US-RBAC-001 | After the E04 seed migration, the seeded `customer` role UUID MUST be set as the `default_role_id` in `identity.users` for all users whose `actor_type = 'CUSTOMER'` and whose `default_role_id` is currently `null`. |
| FR-E04-009 | US-RBAC-001 | After the E04 seed migration, the seeded `agent` role UUID MUST be set as the `default_role_id` for all users whose `actor_type = 'AGENT'` and whose `default_role_id` is currently `null`. |
| FR-E04-010 | US-RBAC-001 | After the E04 seed migration, the seeded `admin` role UUID MUST be set as the `default_role_id` for all users whose `actor_type = 'ADMIN'` and whose `default_role_id` is currently `null`. |

### 8.3 Permission Evaluation

| ID | Story | Requirement |
|---|---|---|
| FR-E04-011 | US-PERM-005 | `hasPermission(string $actorId, string $permission): bool` MUST be declared on `IdentityModuleContract` and implemented in `IdentityModule`. |
| FR-E04-012 | US-PERM-005 | `hasPermission()` MUST resolve permissions by: (1) loading the user's `default_role_id` from `identity.users`, (2) loading additional role IDs from `identity.user_roles`, (3) returning `true` if any of those roles holds the requested permission in `identity.role_permissions`. |
| FR-E04-013 | US-PERM-005 | `hasPermission()` MUST support a Redis-based per-actor permission cache. Cache lifetime MUST be read from platform configuration (`auth.permission_cache_ttl`, default 300 s). Cache MUST be invalidated when role assignments or role-permission assignments change for an actor. |
| FR-E04-014 | US-PERM-005 | `hasPermission()` MUST return `false` (not throw) if the actor ID does not exist, the actor has no roles, or no role holds the requested permission. |
| FR-E04-015 | US-PERM-005 | `hasPermission()` MUST be concurrency-safe. Simultaneous calls for the same actor and permission MUST return consistent results with no data-race between cache miss and database read. |

### 8.4 JWT Claim Population

| ID | Story | Requirement |
|---|---|---|
| FR-E04-016 | US-PERM-005 | `TokenService::issueTokenPair()` and `TokenService::issueAccessToken()` MUST be updated to populate the JWT `roles` claim with the actor's resolved role slugs (from `default_role_id` and `identity.user_roles`). |
| FR-E04-017 | US-PERM-005 | `TokenService::issueTokenPair()` and `TokenService::issueAccessToken()` MUST be updated to populate the JWT `permissions` claim with the actor's resolved permission slugs (from the role-permission model). The wildcard `['*']` MUST be removed. After E04 ships, no token MUST contain `'*'` in the `permissions` claim. |
| FR-E04-018 | US-PERM-005 | The JWT `permissions` claim is for informational/client-side use. Server-side authorization decisions MUST use `AuthorizationGuard::require()` → `hasPermission()`, not solely the JWT claim. |

### 8.5 Admin User Management APIs

| ID | Story | Requirement |
|---|---|---|
| FR-E04-019 | US-PERM-002 | The system MUST expose `POST /v1/admin/users` to create an administrator user account. Required fields: `email` (unique, validated), `password` (subject to E01 password complexity rules), `display_name`. The endpoint MUST require `admin.user.create` permission. |
| FR-E04-020 | US-PERM-002 | Admin user creation MUST: create an `identity.users` record with `actor_type = 'ADMIN'`, provision a password credential via E01's `CredentialService` (hashed + salted), set `default_role_id` to the seeded `admin` role UUID, and produce audit record `identity.admin_user.created`. The response MUST NOT include the password or any credential hash. |
| FR-E04-021 | US-PERM-002 | `POST /v1/admin/users` MUST support idempotency via `Idempotency-Key` header. |
| FR-E04-022 | US-PERM-008 | The system MUST expose `GET /v1/admin/users` to list admin users (paginated). Required permission: `admin.user.read`. Response MUST NOT include credential data. |
| FR-E04-023 | US-PERM-008 | The system MUST expose `GET /v1/admin/users/{id}` to retrieve a single admin user's profile. Required permission: `admin.user.read`. Returns 404 if the user does not exist. |
| FR-E04-024 | US-PERM-003 | The system MUST expose `PATCH /v1/admin/users/{id}/status` to change an admin user's account status. Accepted `status` values: `active`, `inactive`. Required permission: `admin.user.deactivate`. Behaviour by value: (a) `status = inactive` — triggers session revocation per FR-E04-025 and audit record per FR-E04-050; (b) `status = active` — re-enables a deactivated account by setting the user's status to active; no sessions are created or restored (the user must authenticate fresh after reactivation). Reactivation produces no separate audit event in E04. Requesting the same status as the current status MUST be accepted as a no-op (200 with `sessions_revoked: 0`). |
| FR-E04-025 | US-PERM-003 | Deactivating an admin user (`status = inactive`) MUST revoke all active sessions for that user. Session revocation MUST call the existing `SessionService` revocation mechanism — not a direct table UPDATE. |
| FR-E04-026 | US-PERM-003 | An admin MUST NOT be able to deactivate their own account. Self-deactivation MUST return HTTP 422 with error code `ADMIN_SELF_DEACTIVATE`. |

### 8.6 Role and Permission Management APIs

| ID | Story | Requirement |
|---|---|---|
| FR-E04-026b _(editorial: logically a distinct FR; `b` suffix preserves existing references)_ | US-PERM-002 | The system MUST provide an Artisan CLI command `php artisan identity:create-admin` that creates an admin user account without requiring an existing authenticated admin. The command MUST: accept `--email`, `--name`, and `--password` options (or prompt interactively); provision the user and password credential via the same `CredentialService` used by the HTTP API; set `default_role_id` to the seeded `admin` role UUID; fail with a clear error (non-zero exit) if the email already exists and `--force` is not supplied; when `--force` IS supplied and the email already exists, exit with code 0 and print "Admin account already exists; no changes made" — the existing account is NOT modified (idempotent no-op). The `--force` flag MUST NOT bypass password complexity validation on first creation. |
| FR-E04-027 | US-PERM-001 | The system MUST expose `GET /v1/admin/roles` to list all non-deleted roles and their assigned permissions. Required permission: `admin.role.read`. Response is paginated. |
| FR-E04-028 | US-PERM-001 | The system MUST expose `GET /v1/admin/roles/{id}` to retrieve a single role with its permission assignments. Required permission: `admin.role.read`. Returns 404 if not found or soft-deleted. |
| FR-E04-029 | US-RBAC-001 | The system MUST expose `POST /v1/admin/roles` to create a new role. Required fields: `slug` (unique, URL-safe), `display_name`. Required permission: `admin.role.create`. Returns 409 if the slug is already used by an active or soft-deleted role. |
| FR-E04-030 | US-PERM-001 | The system MUST expose `GET /v1/admin/permissions` to list all seeded permissions. Required permission: `admin.permission.read`. Response is paginated. |
| FR-E04-031 | US-PERM-007 | The system MUST expose `POST /v1/admin/roles/{roleId}/permissions` to assign a permission to a role. Required permission: `admin.role.manage`. Returns 409 `PERMISSION_ALREADY_ON_ROLE` if the permission is already assigned to the role. |
| FR-E04-032 | US-PERM-007 | The system MUST expose `DELETE /v1/admin/roles/{roleId}/permissions/{permissionId}` to remove a permission from a role. Required permission: `admin.role.manage`. Returns 404 `PERMISSION_NOT_ON_ROLE` if the permission is not assigned to the role. |

### 8.7 User Role Assignment APIs

| ID | Story | Requirement |
|---|---|---|
| FR-E04-033 | US-PERM-004 | The system MUST expose `GET /v1/admin/users/{userId}/roles` to list all role assignments for a user (default role + additional roles). Required permission: `admin.user.read`. Returns 404 if the user does not exist. |
| FR-E04-034 | US-PERM-004 | The system MUST expose `POST /v1/admin/users/{userId}/roles` to assign an additional role to a user. Required permission: `admin.role.assign`. Returns 409 `ROLE_ALREADY_ASSIGNED` if the role is already assigned. Returns 404 if the user or role does not exist. |
| FR-E04-035 | US-PERM-004 | The system MUST expose `DELETE /v1/admin/users/{userId}/roles/{roleId}` to remove an additional role assignment from a user. Required permission: `admin.role.assign`. Returns 404 if the assignment does not exist. |
| FR-E04-036 | US-PERM-004 | Role assignment changes MUST be audited in `platform.audit_events` with action `identity.user.role.assigned` (for adds) or `identity.user.role.removed` (for removes), recording: actor ID, target user ID, role slug, and correlation ID. |
| FR-E04-037 | US-PERM-005 | Cache for the affected actor's permissions MUST be invalidated immediately after a role assignment change. |

### 8.8 Authorization Middleware

| ID | Story | Requirement |
|---|---|---|
| FR-E04-038 | US-PERM-005, US-PERM-006 | The system MUST register middleware alias `identity.require-permission:{permission}` that calls `AuthorizationGuard::require()` with the named permission. If the check fails, the middleware MUST return HTTP 403 with `{ "success": false, "error": { "code": "AUTHORIZATION_001", "message": "Insufficient permissions.", "trace_id": "..." } }` without invoking the controller. |
| FR-E04-039 | US-PERM-005, US-PERM-006 | The system MUST register middleware alias `identity.require-actor-type:{actorType}` that checks `identity.actor_type` from request attributes (case-insensitive). If the actor type does not match, the middleware MUST return HTTP 403 using the platform-standard error envelope (spec §11): `{ "success": false, "error": { "code": "AUTHORIZATION_002", "message": "Actor type not permitted.", "trace_id": "{correlationId}" } }` without invoking the controller. The `X-Correlation-ID` response header MUST be set on all 403 responses. |
| FR-E04-040 | US-PERM-005 | The existing inline `actor_type === 'ADMIN'` checks in `AdminSessionController::revoke()`, `ConfigurationUpdateRequest::authorize()`, and `ConfigurationIndexRequest::authorize()` MUST be replaced with the appropriate `identity.require-permission:{permission}` middleware applied at the route group level. |
| FR-E04-041 | US-PERM-005 | The admin device routes in `routes/modules/identity.php` (list, block, unblock, remove device, security events) MUST be protected by `identity.require-permission` middleware with the corresponding `device.admin.*` permissions from FR-E04-007. |
| FR-E04-042 | US-PERM-005 | The admin session revocation route (`POST /v1/admin/sessions/{session_id}/revoke`) MUST be protected by `identity.require-permission:session.admin.revoke`. |
| FR-E04-043 | US-PERM-005 | The admin configuration routes (`GET /v1/admin/platform/configuration/security`, `PUT /v1/admin/platform/configuration/security/{key}`) MUST be protected by `identity.require-permission:configuration.policy.view` and `identity.require-permission:configuration.policy.update` respectively. |

### 8.9 IDOR Protection

| ID | Story | Requirement |
|---|---|---|
| FR-E04-044 | US-PERM-006 | All admin APIs that operate on a target user ID MUST verify that the target user exists before executing the operation. Non-existent user IDs MUST return HTTP 404. The 404 error MUST NOT reveal whether the ID format is valid or what data exists. |
| FR-E04-045 | US-PERM-006 | CUSTOMER and AGENT actors MUST NOT be able to call any `/v1/admin/*` endpoint. The `identity.require-actor-type:admin` middleware or `identity.require-permission` check MUST reject such requests at the routing layer before any domain logic executes. |
| FR-E04-046 | US-PERM-007 | Role and permission management APIs MUST verify that referenced role and permission IDs exist before executing operations, returning HTTP 404 `ROLE_NOT_FOUND` or `PERMISSION_NOT_FOUND` for non-existent resources. |

### 8.10 Audit Events

| ID | Story | Requirement |
|---|---|---|
| FR-E04-047 | US-RBAC-001 | Role creation MUST produce audit record with action `identity.role.created`: role slug, display name, actor ID, correlation ID, IP hash. |
| FR-E04-048 | US-PERM-007 | Permission assignment to a role MUST produce audit record `identity.role.permission.assigned`; removal MUST produce `identity.role.permission.removed`. Both MUST record: role slug, permission slug, actor ID, correlation ID. |
| FR-E04-049 | US-PERM-002 | Admin user creation MUST produce audit record `identity.admin_user.created`: new user ID, masked email, actor ID, correlation ID. The email MUST NOT appear in plaintext in audit metadata. |
| FR-E04-050 | US-PERM-003 | Admin user deactivation MUST produce audit record `identity.admin_user.deactivated`: target user ID, actor ID, correlation ID, count of sessions revoked. |
| FR-E04-051 | US-PERM-005 | The `platform.permission.denied` audit record written by `AuthorizationGuard` MUST NOT be duplicated by E04 middleware or service code. |

### 8.11 Observability and Metrics

| ID | Story | Requirement |
|---|---|---|
| FR-E04-052 | US-PERM-005 | The system MUST emit metric counter `identity.authorization.permission_denied` when `hasPermission()` returns `false`. Labels: `permission`, `actor_type`. |
| FR-E04-053 | US-PERM-005 | The system MUST emit metric counter `identity.authorization.permission_granted` when `hasPermission()` returns `true`. Labels: `permission`, `actor_type`. |
| FR-E04-054 | US-PERM-002 | The system MUST emit metric counter `identity.admin_user.created` when an admin user is successfully created. |

### 8.12 Configuration

| ID | Story | Requirement |
|---|---|---|
| FR-E04-055 | US-PERM-005 | The permission evaluation cache TTL MUST be configurable via the platform configuration service with key `auth.permission_cache_ttl`. The default seeded value MUST be `300` (seconds). |
| FR-E04-056 | US-PERM-005 | E04 MUST seed `auth.permission_cache_ttl = 300` in `platform.configuration_values` using an idempotent migration that does NOT overwrite an existing configured value. |

---

## 9. Key Entities

### 9.1 Role (`identity.roles`)

| Attribute | Type | Constraints |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `slug` | varchar(50) | Unique (including soft-deleted), URL-safe |
| `display_name` | varchar(100) | Not null |
| `description` | text | Nullable |
| `is_system_role` | boolean | Not null, default false |
| `deleted_at` | timestampTz | Nullable — soft delete |
| `created_at` / `updated_at` | timestampTz | Not null |

### 9.2 Permission (`identity.permissions`)

| Attribute | Type | Constraints |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `slug` | varchar(100) | Unique (including soft-deleted) |
| `display_name` | varchar(150) | Not null |
| `description` | text | Nullable |
| `module` | varchar(50) | Not null (e.g., `identity`, `platform`) |
| `is_system_permission` | boolean | Not null, default false |
| `deleted_at` | timestampTz | Nullable — soft delete |
| `created_at` / `updated_at` | timestampTz | Not null |

### 9.3 RolePermission (`identity.role_permissions`)

| Attribute | Type | Constraints |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `role_id` | UUID | FK → `identity.roles.id`, not null |
| `permission_id` | UUID | FK → `identity.permissions.id`, not null |
| `created_by_actor_id` | UUID | Nullable |
| `created_at` | timestampTz | Not null |

Unique constraint: `(role_id, permission_id)`.

### 9.4 UserRole (`identity.user_roles`)

| Attribute | Type | Constraints |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `user_id` | UUID | FK → `identity.users.id`, not null |
| `role_id` | UUID | FK → `identity.roles.id`, not null |
| `created_by_actor_id` | UUID | Nullable |
| `created_at` | timestampTz | Not null |

Unique constraint: `(user_id, role_id)`.

### 9.5 `identity.users` FK Addition

E04 MUST add a foreign key constraint: `identity.users.default_role_id REFERENCES identity.roles(id) ON DELETE SET NULL`. The column is nullable.

---

## 10. Authorization Model

### Before E04 (current state)

| Component | Current Behavior | Classification |
|---|---|---|
| `TokenService` `permissions` claim | `['*']` for ADMIN, `[]` for others | **Temporary — eliminated by E04** |
| `TokenService` `roles` claim | Always `[]` | **Placeholder — corrected by E04** |
| `AdminSessionController::revoke()` auth | Inline `actor_type === 'ADMIN'` check | **Temporary — replaced by E04** |
| `ConfigurationUpdateRequest::authorize()` | Inline `actor_type === 'ADMIN'` check | **Temporary — replaced by E04** |
| `ConfigurationIndexRequest::authorize()` | Inline `actor_type === 'ADMIN'` check | **Temporary — replaced by E04** |
| `AuthorizationGuard::require()` | Calls `hasPermission()` — not yet in interface | **Forward reference — fulfilled by E04** |
| `IdentityModuleContract::hasPermission()` | Not declared | **Missing — declared and implemented by E04** |
| `identity.users.default_role_id` | Always `null` | **Gap — backfilled by E04 migration** |
| `identity.require-actor-type` middleware | Referenced in comments, not registered | **Forward reference — registered by E04** |

### After E04 (target state)

```
HTTP Request
    │
    ├─ identity.auth → JWT validated → identity.user_id, identity.actor_type,
    │                  identity.permissions (resolved slugs) set on request
    │
    ├─ identity.require-actor-type:{type} (if applied)
    │   └─ checks identity.actor_type → 403 AUTHORIZATION_002 if mismatch
    │
    ├─ identity.require-permission:{permission} (if applied)
    │   └─ AuthorizationGuard::require(actor, permission, correlationId)
    │       └─ IdentityModuleContract::hasPermission(actorId, permission)
    │           └─ Redis cache hit → return cached result
    │           └─ cache miss → query identity.users + identity.user_roles
    │                         → identity.role_permissions → cache + return
    │           → true → proceed to controller
    │           → false → audit platform.permission.denied → 403 AUTHORIZATION_001
    │
    └─ Controller (thin — no inline auth checks)
```

---

## 11. API Contracts

### Error Codes

| Code | HTTP Status | Condition |
|---|---|---|
| `AUTHORIZATION_001` | 403 | Permission denied |
| `AUTHORIZATION_002` | 403 | Actor type not permitted |
| `ADMIN_USER_EXISTS` | 409 | Email already registered |
| `ADMIN_USER_NOT_FOUND` | 404 | Target admin user does not exist |
| `ADMIN_SELF_DEACTIVATE` | 422 | Admin attempted to deactivate own account |
| `ROLE_NOT_FOUND` | 404 | Referenced role does not exist |
| `PERMISSION_NOT_FOUND` | 404 | Referenced permission does not exist |
| `ROLE_ALREADY_ASSIGNED` | 409 | Role already assigned to user |
| `PERMISSION_ALREADY_ON_ROLE` | 409 | Permission already assigned to role |
| `PERMISSION_NOT_ON_ROLE` | 404 | Permission not assigned to role |
| `ROLE_SLUG_CONFLICT` | 409 | Slug matches an active or soft-deleted role |
| `SYSTEM_ROLE_DELETION_DENIED` | 422 | Attempt to delete a system-seeded role |
| `SYSTEM_PERMISSION_DELETION_DENIED` | 422 | Attempt to delete a system-seeded permission — **Reserved for a future epic; no `DELETE /v1/admin/permissions/{id}` endpoint is defined or implemented in E04. The code is reserved here so downstream epics can reference it consistently when they add permission management endpoints.** |

### Response Envelope

All E04 API responses use the platform-standard envelope from E01:

- **Success**: `{ "success": true, "data": { ... }, "meta": { ... } }`
- **Error**: `{ "success": false, "error": { "code": "...", "message": "...", "trace_id": "..." } }`

### Pagination

All list endpoints support `page` and `per_page` query parameters with the standard E03 pagination format:

```json
{
  "success": true,
  "data": [ ... ],
  "meta": { "total": N, "page": 1, "per_page": 20, "last_page": N }
}
```

---

## 12. Database Architecture

### Schema

All E04 tables are created in the `identity` schema, consistent with E01/E03. Database connection: `identity`.

### Migration Order

Migrations are placed in `app/Modules/Identity/Infrastructure/Migrations/`:

1. Create `identity.roles`
2. Create `identity.permissions`
3. Create `identity.role_permissions` (FK to roles and permissions)
4. Create `identity.user_roles` (FK to users and roles)
5. Add FK constraint `identity.users.default_role_id → identity.roles.id` (ALTER TABLE, nullable FK, ON DELETE SET NULL)
6. Seed standard roles (customer, agent, admin)
7. Seed permission catalogue and assign to admin role
8. Seed `auth.permission_cache_ttl = 300` in `platform.configuration_values`
9. Backfill `identity.users.default_role_id` for existing users by actor type

All migrations MUST be idempotent.

---

## 13. Integration with Existing Components

### 13.1 `IdentityModuleContract` (one new method)

```php
/**
 * Check whether the actor identified by $actorId holds the given permission.
 * Resolves via role-permission model. Returns false on unknown actor, missing role, or missing permission.
 * MUST NOT throw on failure.
 */
public function hasPermission(string $actorId, string $permission): bool;
```

All other `IdentityModuleContract` methods are unchanged.

### 13.2 `AuthorizationGuard` (Platform module — no change)

`AuthorizationGuard::require()` already calls `hasPermission()` and writes `platform.permission.denied`. No changes needed unless additional context (e.g., actor type) must be threaded through.

### 13.3 `TokenService` (Identity — modified)

- `issueTokenPair()` and `issueAccessToken()`: Replace `['*']` / `[]` with resolved role slugs and permission slugs from the role-permission repository.
- Permission resolution requires access to a role-permission repository injected into `TokenService`.

### 13.4 `RegistrationService` (Identity — modified)

After the E04 seed migration: `RegistrationService` MUST populate `default_role_id` with the seeded CUSTOMER role UUID. The UUID MUST be resolved at runtime via repository lookup (not hard-coded).

### 13.5 Admin Route Files

`routes/modules/identity.php` references `identity.require-actor-type:admin` in comments. E04 registers this middleware alias. Inline checks in controllers and form requests are replaced by route-level middleware.

---

## 14. Security Requirements

| ID | Requirement |
|---|---|
| SEC-E04-001 | Authorization MUST be enforced server-side. JWT `permissions` claim is advisory for client-side UI optimization only. `hasPermission()` MUST consult the persistent role-permission store. |
| SEC-E04-002 | Admin user creation API MUST validate passwords against the same E01 complexity rules configured in `platform.configuration_values`. |
| SEC-E04-003 | The admin user creation API MUST NOT return the plaintext password in any response. Credentials MUST be stored via E01's `CredentialService` (hashed + salted). |
| SEC-E04-004 | Permission denial 403 responses MUST NOT reveal which specific permission was required (no permission slug in the error message). |
| SEC-E04-005 | Role and permission management APIs MUST be inaccessible to non-ADMIN actors at the routing/middleware layer before any domain service is invoked. |
| SEC-E04-006 | IP addresses in E04 audit records MUST be stored as `HMAC-SHA256(platform_secret, ip_address)`, consistent with E01 BR-027. |
| SEC-E04-007 | Permission cache invalidation MUST be triggered synchronously when role assignments change. A deactivated user MUST be denied access on their next API call (within `auth.permission_cache_ttl` at most if a session revocation race occurs — session revocation is the primary mechanism). |

---

## 15. Test Requirements

### 15.1 Unit Tests

- `hasPermission()` returns `true` when the actor's role holds the permission
- `hasPermission()` returns `false` for unknown actor ID
- `hasPermission()` returns `false` when actor has no roles
- `hasPermission()` returns `false` when role exists but holds no permissions
- ADMIN token contains explicit permission slugs (not `'*'`) after E04 changes
- JWT `roles` claim is non-empty for seeded actor types
- `CUSTOMER` role slug is `customer` after seed

### 15.2 API / Feature Tests

- `POST /v1/admin/users`: success, duplicate email (409), password complexity failure (400), missing fields (400), insufficient permission (403)
- `GET /v1/admin/users`: paginated list, actor type enforcement (403 for CUSTOMER)
- `PATCH /v1/admin/users/{id}/status`: deactivate success, self-deactivation blocked (422), session revocation verified, non-existent user (404)
- `GET /v1/admin/roles`: seeded roles appear, unauthorized (403), unauthenticated (401)
- `POST /v1/admin/roles`: create success, duplicate slug (409)
- `POST /v1/admin/roles/{id}/permissions`: assign success, already assigned (409), non-existent role/permission (404)
- `DELETE /v1/admin/roles/{id}/permissions/{id}`: remove success, not assigned (404)
- `GET /v1/admin/users/{userId}/roles`: list user roles, non-existent user (404)
- `POST /v1/admin/users/{userId}/roles`: assign success, already assigned (409)
- `DELETE /v1/admin/users/{userId}/roles/{roleId}`: remove success, not assigned (404)
- All admin routes return 403 for CUSTOMER/AGENT tokens
- All admin routes return 401 for unauthenticated requests
- `identity.require-permission` blocks unauthorized access and writes audit
- `identity.require-actor-type` blocks wrong actor type

### 15.3 Security Tests

- CUSTOMER token cannot call any `/v1/admin/*` endpoint (IDOR check)
- AGENT token cannot call any `/v1/admin/*` endpoint
- A JWT with a manually crafted `permissions` claim containing admin permission slugs is denied by server-side `hasPermission()` check
- Admin cannot deactivate their own account
- Password is not returned in any admin user API response

### 15.4 Integration Tests

- Seed migration is idempotent (run twice, same state — no duplicates)
- Backfill migration correctly sets `default_role_id` for existing users by actor type
- FK constraint on `default_role_id` is added and prevents orphan references
- Deactivating admin user revokes all their sessions (session records updated, Redis revocation keys set)
- Permission cache invalidates after role-permission assignment changes

### 15.5 Concurrency Tests

- Concurrent `hasPermission()` calls for the same actor return consistent results
- Concurrent admin user creation with the same email: exactly one succeeds, the other returns 409

---

## 16. Edge Cases

| # | Scenario | Expected Behavior |
|---|---|---|
| EC-01 | Admin created but MFA not yet enrolled | Account created successfully. MFA enrollment is a separate E01 flow. |
| EC-02 | `hasPermission()` called for a deactivated user | By the time `hasPermission()` is called, the request has already passed `identity.auth` middleware. Session revocation is enforced at the `identity.auth` layer (which rejects the token); `hasPermission()` itself only checks the role-permission model. Role-permission records are not removed on deactivation — revocation of the session is the primary enforcement mechanism. |
| EC-03 | Role with zero permissions assigned to user | Role appears in JWT `roles`; `hasPermission()` returns `false` for any permission. |
| EC-04 | Migration step 5 (FK constraint) runs before step 6 (role seed) | Step 5 is safe because the column is nullable. The FK constraint does not require any existing role records. |
| EC-05 | Same permission assigned to multiple roles the user holds | `hasPermission()` returns `true` — a single matching role-permission record suffices. |
| EC-06 | Admin role permission removed while admin has an active session | JWT `permissions` claim retains the old set until the token expires. `hasPermission()` returns `false` immediately (cache invalidated on assignment change). The admin's next call to the affected endpoint will be denied. |
| EC-07 | Admin attempts to delete a system-seeded role | HTTP 422 with `SYSTEM_ROLE_DELETION_DENIED`. |
| EC-08 | `auth.permission_cache_ttl` configured to `0` | System treats `0` as "no caching" — every `hasPermission()` call queries the database directly. Minimum configurable value is `0`. |
| EC-09 | Creating a role whose slug matches a soft-deleted role | HTTP 409 with `ROLE_SLUG_CONFLICT`. Slugs must be unique across active and soft-deleted records. |
| EC-10 | Backfill migration targets a user whose actor type is unrecognized | User's `default_role_id` remains `null`. A warning MUST be logged. No error is thrown to preserve migration continuity. |
| EC-11 | `POST /v1/admin/users/{userId}/roles` is called with a role ID that matches the target user's `default_role_id` | HTTP 409 with `{ "code": "ROLE_ALREADY_ASSIGNED" }` is returned. The default role is stored in `identity.users.default_role_id`, not `identity.user_roles`, so the database unique constraint would NOT catch this collision — `AdminUserService::assignRole()` MUST explicitly check `user.default_role_id` before inserting into `user_roles`. |

---

## 17. Configuration Defaults

E04 seeds the following entry via idempotent migration (insert if not exists):

| Key | Default Value | Type | Description |
|---|---|---|---|
| `auth.permission_cache_ttl` | `300` | integer | Seconds to cache `hasPermission()` per-actor resolution in Redis |

---

## 18. Unresolved Decisions (TBDs)

Per Constitution Principle 2, items below MUST NOT be invented or assumed. They require explicit decisions before planning.

### TBD-E04-001 — Enterprise Actor Role

**Question**: Is E04 responsible for defining an Enterprise role and Enterprise actor permission model, or does that belong to a dedicated Enterprise epic?

**Context**: E01 §5 lists Enterprise as a distinct actor type. The `identity.users.actor_type` column currently accepts CUSTOMER, AGENT, and ADMIN. No Enterprise implementation exists anywhere in the codebase.

**Impact on E04**: If Enterprise is in scope, the Enterprise role, its permissions, and Enterprise user management APIs must be specified. If out of scope, this spec is complete as written.

**Decision needed from**: Product / Architecture.

---

### TBD-E04-003 — Agent-Specific Permissions

**Question**: Does the `agent` role hold any explicit permissions distinct from what CUSTOMER holds, and must those be seeded by E04?

**Context**: AGENT actors currently carry empty `permissions: []` in their JWT. The Agent domain (future epics) will define agent-specific capabilities. Whether E04 pre-defines Agent permissions or leaves the agent role with zero permissions for the Agent epic to extend is undecided.

**Impact on E04**: If Agent permissions are in E04 scope, their slugs and descriptions must be specified here. If not, the `agent` role is seeded with zero permissions and extended by the Agent epic.

**Decision needed from**: Product / Agent Epic Owner.

---

## 19. Dependencies on Previous Epics

| Dependency | Source | Nature |
|---|---|---|
| `identity.users` table with `default_role_id` and `actor_type` columns | E01 | E04 adds FK constraint and backfill |
| `IdentityModuleContract` interface | E01 | E04 adds `hasPermission()` |
| `IdentityModule` concrete implementation | E01 | E04 adds `hasPermission()` implementation |
| `TokenService` JWT issuance | E01 | E04 modifies `roles` and `permissions` claim population |
| `AuthorizationGuard::require()` in Platform module | Platform / E01 | E04 provides the `hasPermission()` implementation it calls |
| `AuditService` / `platform.audit_events` table | Platform Foundation | E04 records authorization events |
| `ConfigurationService` | Platform Foundation | E04 reads `auth.permission_cache_ttl` |
| `CredentialService` password credential provisioning | E01 | E04 uses for admin user password setup |
| `SessionService::adminRevokeSession()` | E01/E03 | E04 calls during admin user deactivation |
| E01 BR-012, FR-E01-008 | E01 | Mandate CUSTOMER default role; E04 seeds it |
| E01 FR-E01-042 | E01 | Mandates `configuration.policy.view` / `configuration.policy.update`; E04 seeds them |
| E01 FR-E01-025 | E01 | Admin session revocation; E04 protects endpoint with `session.admin.revoke` |
| E03 admin device routes | E03 | E04 applies `device.admin.*` permission middleware to existing routes |

---

## 20. User Story Coverage Map

| Story ID | Backlog Story | Priority | FRs Covered |
|---|---|---|---|
| US-PERM-001 | View roles and permissions | P1 | FR-E04-027, FR-E04-028, FR-E04-030 |
| US-PERM-002 | Create admin user | P1 | FR-E04-019, FR-E04-020, FR-E04-021, FR-E04-026b, FR-E04-049, FR-E04-054 |
| US-PERM-003 | Deactivate admin user | P1 | FR-E04-024, FR-E04-025, FR-E04-026, FR-E04-050 |
| US-PERM-004 | Assign role to user | P2 | FR-E04-004, FR-E04-033, FR-E04-034, FR-E04-035, FR-E04-036 |
| US-PERM-005 | System enforces and audits denials | P1 | FR-E04-011, FR-E04-012, FR-E04-013, FR-E04-014, FR-E04-015, FR-E04-016, FR-E04-017, FR-E04-018, FR-E04-037, FR-E04-038, FR-E04-039, FR-E04-040, FR-E04-041, FR-E04-042, FR-E04-043, FR-E04-051, FR-E04-052, FR-E04-053, FR-E04-055, FR-E04-056 |
| US-PERM-006 | Customer cannot access admin | P1 | FR-E04-044, FR-E04-045, FR-E04-046 |
| US-PERM-007 | Manage role-permission assignments | P2 | FR-E04-003, FR-E04-031, FR-E04-032, FR-E04-048 |
| US-PERM-008 | View admin user list and details | P1 | FR-E04-022, FR-E04-023 |
| US-RBAC-001 | Create role | P0 | FR-E04-001, FR-E04-002, FR-E04-006, FR-E04-007, FR-E04-008, FR-E04-009, FR-E04-010, FR-E04-029, FR-E04-047 |
| US-RBAC-003 | Manage role lifecycle | P0 | FR-E04-005 |

---

## 21. Success Criteria

| ID | Criterion | How Verified |
|---|---|---|
| SC-E04-001 | Zero `['*']` wildcard values in any JWT `permissions` claim after E04 deployment | **T088** (static grep: assert zero occurrences of `['*']` in permissions claim assignments across the codebase) **+** **T084** (E01 regression: `TokenService` unit tests assert the ADMIN JWT `permissions` claim contains only explicit permission slugs, never `'*'`). Both must pass before release. T078 independently verifies FR-E04-018 (server-side authority vs JWT claim) but is not the SC-E04-001 gate. |
| SC-E04-002 | All inline `actor_type === 'ADMIN'` authorization checks eliminated from controllers and form requests | Static analysis / code review |
| SC-E04-003 | `hasPermission()` returns `true` only for actors whose role holds the requested permission | Feature test suite |
| SC-E04-004 | All E01 and E03 admin endpoints return 403 for CUSTOMER/AGENT tokens | Security test suite |
| SC-E04-005 | Admin user deactivation revokes all sessions within the same API response cycle | Integration test |
| SC-E04-006 | Seed migration is idempotent: running twice produces identical state | Integration test |
| SC-E04-007 | `default_role_id` is non-null for all existing users after backfill migration | Database assertion in migration test |
| SC-E04-008 | All state-changing authorization operations produce audit records in `platform.audit_events` | Audit verification per operation |
| SC-E04-009 | `platform.permission.denied` is emitted for every permission denial | API test suite |
| SC-E04-010 | `hasPermission()` P95 latency ≤ 10 ms with warm Redis cache | Performance test |

---

## 22. Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Cache invalidation race: role-permission change not immediately reflected | Medium | High | Synchronous cache invalidation on role-permission write; `auth.permission_cache_ttl` bounds maximum stale window to 300 s default |
| JWT breaking change: resolved permission list increases token size | Low | Medium | Typical ADMIN holds ≤ 15 permissions; token size increase is acceptable for JWT |
| Backfill migration slow on large `identity.users` table | Low | Medium | Run backfill in batches; wrap in a transaction; rolled back on failure |
| Bootstrap deadlock in new environments (no admin exists) | Medium (dev/staging) | Low (prod) | Resolved by `php artisan identity:create-admin` (FR-E04-026b) — bypasses HTTP authorization layer |
| Future epics add permissions without a consistent naming convention | High (future) | Low | Convention `{module}.{resource}.{action}` is established; future epics follow it with their own seed migrations |

---

## 23. Acceptance Scenarios (QA)

**Scenario 1 — Admin permission check (happy path):**
Given an ADMIN user with `admin.user.read` permission;
When `GET /v1/admin/users` is called with a valid ADMIN Bearer token;
Then HTTP 200 with paginated admin user list is returned.

**Scenario 2 — Customer blocked from admin route:**
Given an authenticated CUSTOMER token;
When `GET /v1/admin/users` is called;
Then HTTP 403 with `{ "error": { "code": "AUTHORIZATION_001" } }` and `platform.permission.denied` audit record is written.

**Scenario 3 — Wildcard eliminated:**
Given an ADMIN user logs in after E04 deployment;
When their JWT is decoded;
Then the `permissions` claim is an array of explicit slugs (e.g., `["admin.user.create", "admin.role.read", ...]`) and does NOT contain `"*"`.

**Scenario 4 — Admin deactivation cascades to sessions:**
Given Admin B has 3 active sessions;
When Admin A (with `admin.user.deactivate`) calls `PATCH /v1/admin/users/{B_id}/status` with `{ "status": "inactive" }`;
Then all 3 of Admin B's sessions are revoked, Admin B's next API call is rejected with 401, and audit record `identity.admin_user.deactivated` is written with sessions revoked count = 3.

**Scenario 5 — hasPermission with no role:**
Given a user whose `default_role_id` is `null` and `identity.user_roles` has no entries;
When `hasPermission(userId, 'admin.user.create')` is called;
Then the method returns `false` without throwing.

**Scenario 6 — Seed idempotency:**
Given the E04 seed migration has already run;
When the migration is run a second time;
Then role and permission counts are unchanged; no duplicate records exist.

**Scenario 7 — Self-deactivation blocked:**
Given Admin A is authenticated;
When Admin A calls `PATCH /v1/admin/users/{A_id}/status` with `{ "status": "inactive" }`;
Then HTTP 422 with `{ "code": "ADMIN_SELF_DEACTIVATE" }` is returned and no deactivation occurs.
