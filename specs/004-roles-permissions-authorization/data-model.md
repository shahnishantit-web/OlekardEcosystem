# Data Model — E04 Roles, Permissions & Authorization

**Feature**: `004-roles-permissions-authorization` | **Schema**: `identity` (PostgreSQL)

---

## Overview

E04 creates four new tables in the `identity` schema and alters one existing column on `identity.users`.

| Change | Type | Table |
|--------|------|-------|
| Create | NEW | `identity.roles` |
| Create | NEW | `identity.permissions` |
| Create | NEW | `identity.role_permissions` |
| Create | NEW | `identity.user_roles` |
| Alter | CONSTRAINT ADDED | `identity.users.default_role_id` (FK added) |
| Seed | DATA | `identity.roles` (3 rows) |
| Seed | DATA | `identity.permissions` (15 rows) |
| Seed | DATA | `identity.role_permissions` (15 rows for admin role) |
| Seed | DATA | `platform.configuration_values` (1 row) |
| Backfill | DATA UPDATE | `identity.users.default_role_id` (set by actor_type) |

---

## Entity 1: `identity.roles`

**New table** created by migration `2026_09_09_001000_create_identity_roles_table.php`.

### Column Inventory

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7; generated at creation |
| `slug` | VARCHAR(50) | NOT NULL, UNIQUE (all rows including soft-deleted) | URL-safe lowercase (e.g., `admin`, `customer`) |
| `display_name` | VARCHAR(100) | NOT NULL | Human-readable name |
| `description` | TEXT | NULL | Optional description |
| `is_system_role` | BOOLEAN | NOT NULL, DEFAULT false | Prevents deletion of seeded roles |
| `deleted_at` | TIMESTAMPTZ | NULL | Soft delete — null = active |
| `created_at` | TIMESTAMPTZ | NOT NULL, DEFAULT `now()` | |
| `updated_at` | TIMESTAMPTZ | NOT NULL, DEFAULT `now()` | |

### Indexes

```sql
UNIQUE INDEX identity_roles_slug_unique ON identity.roles (slug);   -- enforces global slug uniqueness
INDEX identity_roles_deleted_at_idx ON identity.roles (deleted_at); -- list active roles efficiently
```

### Seeded Rows (idempotent — insertOrIgnore by slug)

| slug | display_name | is_system_role |
|------|---|---|
| `customer` | Customer | true |
| `agent` | Agent | true |
| `admin` | Administrator | true |

### Eloquent Model

**File**: `app/Modules/Identity/Infrastructure/Models/Role.php`
**Connection**: `identity`
**Table**: `identity.roles`

```php
protected $fillable = ['slug', 'display_name', 'description', 'is_system_role'];
protected $casts    = ['is_system_role' => 'boolean', 'deleted_at' => 'immutable_datetime'];
use SoftDeletes;
```

---

## Entity 2: `identity.permissions`

**New table** created by migration `2026_09_09_002000_create_identity_permissions_table.php`.

### Column Inventory

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `slug` | VARCHAR(100) | NOT NULL, UNIQUE | `{module}.{resource}.{action}` |
| `display_name` | VARCHAR(150) | NOT NULL | Human-readable name |
| `description` | TEXT | NULL | |
| `module` | VARCHAR(50) | NOT NULL | Owning module (e.g., `identity`, `platform`) |
| `is_system_permission` | BOOLEAN | NOT NULL, DEFAULT false | Prevents deletion of seeded permissions |
| `deleted_at` | TIMESTAMPTZ | NULL | Soft delete |
| `created_at` | TIMESTAMPTZ | NOT NULL, DEFAULT `now()` | |
| `updated_at` | TIMESTAMPTZ | NOT NULL, DEFAULT `now()` | |

### Indexes

```sql
UNIQUE INDEX identity_permissions_slug_unique ON identity.permissions (slug);
INDEX identity_permissions_module_idx ON identity.permissions (module);
INDEX identity_permissions_deleted_at_idx ON identity.permissions (deleted_at);
```

### Seeded Rows

All 15 permissions in the catalogue from FR-E04-007. All are system permissions (`is_system_permission = true`).

| slug | module | display_name |
|------|--------|---|
| `admin.user.create` | identity | Create Admin User |
| `admin.user.read` | identity | Read Admin Users |
| `admin.user.deactivate` | identity | Deactivate Admin User |
| `admin.role.read` | identity | Read Roles |
| `admin.role.create` | identity | Create Role |
| `admin.role.assign` | identity | Assign/Remove Roles to Users |
| `admin.role.manage` | identity | Manage Role Permission Assignments |
| `admin.permission.read` | identity | Read Permissions |
| `configuration.policy.view` | platform | View Platform Configuration |
| `configuration.policy.update` | platform | Update Platform Configuration |
| `session.admin.revoke` | identity | Administratively Revoke Sessions |
| `device.admin.read` | identity | Read User Devices (Admin) |
| `device.admin.block` | identity | Block User Devices (Admin) |
| `device.admin.remove` | identity | Remove User Devices (Admin) |
| `device.admin.unblock` | identity | Unblock User Devices (Admin) |

### Eloquent Model

**File**: `app/Modules/Identity/Infrastructure/Models/Permission.php`
**Connection**: `identity` | **Table**: `identity.permissions`

```php
protected $fillable = ['slug', 'display_name', 'description', 'module', 'is_system_permission'];
protected $casts    = ['is_system_permission' => 'boolean', 'deleted_at' => 'immutable_datetime'];
use SoftDeletes;
```

---

## Entity 3: `identity.role_permissions`

**New join table** created by migration `2026_09_09_003000_create_identity_role_permissions_table.php`.

### Column Inventory

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `role_id` | UUID | NOT NULL, FK → `identity.roles.id` | |
| `permission_id` | UUID | NOT NULL, FK → `identity.permissions.id` | |
| `created_by_actor_id` | UUID | NULL | Actor who made the assignment (audit trace) |
| `created_at` | TIMESTAMPTZ | NOT NULL, DEFAULT `now()` | |

### Indexes and Constraints

```sql
UNIQUE INDEX identity_role_permissions_unique ON identity.role_permissions (role_id, permission_id);
INDEX identity_role_permissions_role_id_idx ON identity.role_permissions (role_id);
INDEX identity_role_permissions_permission_id_idx ON identity.role_permissions (permission_id);
FOREIGN KEY (role_id) REFERENCES identity.roles(id) ON DELETE CASCADE;
FOREIGN KEY (permission_id) REFERENCES identity.permissions(id) ON DELETE CASCADE;
```

`ON DELETE CASCADE`: removing a role removes its permission assignments; removing a permission removes its role assignments. Soft-delete on roles/permissions prevents accidental cascades for system records.

### Seeded Rows

All 15 permissions from the catalogue assigned to the `admin` role. Seeded by migration `2026_09_09_007000`.

### Eloquent Model

**File**: `app/Modules/Identity/Infrastructure/Models/RolePermission.php`
**Connection**: `identity` | **Table**: `identity.role_permissions`

No soft delete (the join record is removed directly on permission removal from role).

---

## Entity 4: `identity.user_roles`

**New join table** created by migration `2026_09_09_004000_create_identity_user_roles_table.php`.

Additional role assignments beyond a user's `default_role_id`. A user may have zero or more entries here (their default role is separate and does not appear in this table).

### Column Inventory

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `user_id` | UUID | NOT NULL, FK → `identity.users.id` | |
| `role_id` | UUID | NOT NULL, FK → `identity.roles.id` | |
| `created_by_actor_id` | UUID | NULL | Actor who made the assignment |
| `created_at` | TIMESTAMPTZ | NOT NULL, DEFAULT `now()` | |

### Indexes and Constraints

```sql
UNIQUE INDEX identity_user_roles_unique ON identity.user_roles (user_id, role_id);
INDEX identity_user_roles_user_id_idx ON identity.user_roles (user_id);
FOREIGN KEY (user_id) REFERENCES identity.users(id) ON DELETE CASCADE;
FOREIGN KEY (role_id) REFERENCES identity.roles(id) ON DELETE RESTRICT; -- do not silently remove role assignments
```

`ON DELETE RESTRICT` on `role_id`: prevents deleting a non-system role that has user assignments without explicitly removing those assignments first.

### Eloquent Model

**File**: `app/Modules/Identity/Infrastructure/Models/UserRole.php`
**Connection**: `identity` | **Table**: `identity.user_roles`

---

## Entity 5: `identity.users` (Altered)

**Existing table** (created by E01). E04 adds one constraint.

### E04 Migration Change

```sql
ALTER TABLE identity.users
    ADD CONSTRAINT identity_users_default_role_id_fk
    FOREIGN KEY (default_role_id)
    REFERENCES identity.roles(id)
    ON DELETE SET NULL
    DEFERRABLE INITIALLY DEFERRED;
```

`DEFERRABLE INITIALLY DEFERRED`: allows the backfill migration to set `default_role_id` within a transaction even before FK validation completes at statement level. Standard PostgreSQL practice for self-referencing or ordered-seed scenarios.

### Backfill (migration `2026_09_09_009000`)

```sql
UPDATE identity.users SET default_role_id = (
    SELECT id FROM identity.roles WHERE slug = 'customer'
) WHERE actor_type = 'CUSTOMER' AND default_role_id IS NULL;

UPDATE identity.users SET default_role_id = (
    SELECT id FROM identity.roles WHERE slug = 'agent'
) WHERE actor_type = 'AGENT' AND default_role_id IS NULL;

UPDATE identity.users SET default_role_id = (
    SELECT id FROM identity.roles WHERE slug = 'admin'
) WHERE actor_type = 'ADMIN' AND default_role_id IS NULL;
```

Any user with an unrecognized `actor_type` retains `default_role_id = null` (logged as a warning).

---

## Permission Resolver — Redis Cache

**Key**: `identity:permissions:{actorId}` (string, JSON-encoded array)
**Value**: `["admin.user.create","admin.role.read",...]`
**TTL**: Value of `auth.permission_cache_ttl` (seconds); read at resolution time
**Invalidation**: Delete key synchronously when any `identity.role_permissions` or `identity.user_roles` row changes for a user

**Key for "no permissions"** (actor exists but has no permissions): Cached as `[]` (empty JSON array) to prevent repeated DB lookups. The TTL still applies so role changes propagate within the configured window.

---

## Relationship Diagram

```
identity.users
    │ default_role_id (FK → identity.roles.id, nullable)
    │
    ├─── identity.user_roles (additional assignments)
    │         │ role_id (FK → identity.roles.id)
    │
    └─── (resolved via union of default + additional)
              │
         identity.roles
              │ id
              │
         identity.role_permissions
              │ role_id / permission_id
              │
         identity.permissions
                   │ slug
                   └─ "admin.user.create" → hasPermission() returns true
```

---

## New `platform.configuration_values` Seed

Seeded by migration `2026_09_09_008000` using `insertOrIgnore` (idempotent).

| config_key | config_value | value_type | status | description |
|---|---|---|---|---|
| `auth.permission_cache_ttl` | `300` | number | active | Seconds to cache per-actor permission resolution in Redis (0 = no cache) |
