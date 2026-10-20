# Research — E04 Roles, Permissions & Authorization

**Purpose**: Resolve technical implementation decisions before planning.
**Date**: 2026-09-09
**Based on**: codebase exploration of `wallet-ecosystem/services/api/app/Modules/Identity/` and Platform Foundation; E01/E03 implementation patterns.

---

## 1. Parameterized Middleware Aliases (Laravel 11)

**Decision**: Use Laravel's built-in parameter passing for middleware aliases.

Laravel resolves `identity.require-permission:admin.user.create` by passing `'admin.user.create'` as the third argument to `handle(Request $request, Closure $next, string $permission)`. This is the same mechanism used by Laravel's own `throttle:60,1` middleware.

Registration in `IdentityServiceProvider::boot()`:
```php
$router->aliasMiddleware('identity.require-permission', RequirePermissionMiddleware::class);
$router->aliasMiddleware('identity.require-actor-type', RequireActorTypeMiddleware::class);
```

Route usage:
```php
Route::middleware(['identity.auth', 'identity.require-permission:admin.user.create'])->group(...)
```

Multiple permissions (if needed in future): `identity.require-permission:perm1|perm2` — middleware can split on `|` and require any.

**Rationale**: Zero new infrastructure — standard Laravel pattern. Follows the same convention as the existing `identity.latency:{endpoint}` middleware already registered in the project.

---

## 2. Permission Resolution and Caching

**Decision**: Per-actor Redis key with JSON-encoded permission slug array; key derived from `IdentityRedisKeys` pattern.

**Cache key format**: `identity:permissions:{actorId}` → JSON array of permission slugs, e.g. `["admin.user.create","admin.role.read"]`

**TTL**: Read from `ConfigurationService` with key `auth.permission_cache_ttl` (default 300 seconds). Uses the same `ConfigurationSecurityService` pattern as the existing `getSessionInactivityTtl*()` methods.

**Invalidation**: On any write to `identity.role_permissions` or `identity.user_roles` affecting an actor, delete the actor's cache key synchronously within the same database transaction (or immediately after on commit).

**Resolution order** (same transaction):
1. Load `identity.users.default_role_id` → one role UUID (or null)
2. Load all `identity.user_roles.role_id` for the actor → zero or more additional role UUIDs
3. Union of role UUIDs → query `identity.role_permissions` for any matching `permission_id` whose permission slug = the requested string
4. Return `true` if any row found, `false` otherwise

**Cache warm-up for JWT issuance**: `TokenService` calls `PermissionResolverService::resolveAllForActor(actorId)` which returns the full permission array. This result is also stored in the Redis cache (same key/TTL) to pre-warm it at login time.

**Rationale**: Follows `IdentityRedisKeys` conventions (prefix `identity:`, colon-separated segments). Avoids N+1 queries by loading all role-permissions in a single SQL query using `WHERE role_id IN (...)`.

---

## 3. TokenService Dependency Injection

**Decision**: Inject `PermissionResolverService` into `TokenService` as a constructor dependency.

`TokenService` already injects 3 dependencies. Adding `PermissionResolverService` is straightforward. The resolver service itself depends on repositories and Redis — no circular dependencies exist because `TokenService` is in the domain layer and `PermissionResolverService` is also in the domain layer, both in the same `Identity` module.

```php
final class TokenService implements TokenServiceContract
{
    public function __construct(
        private readonly JwksProviderContract         $jwksProvider,
        private readonly SessionRepositoryInterface   $sessions,
        private readonly ConfigurationSecurityService $config,
        private readonly PermissionResolverService    $permissions,  // NEW
    ) {}
```

The service provider already autowires dependencies. Adding the binding for `PermissionResolverService` to `IdentityServiceProvider::register()` is the only infrastructure change.

**Alternative considered**: Separate `TokenEnricher` service that wraps `TokenService` to add claims → rejected because it adds an unnecessary indirection layer for a clean DI addition.

---

## 4. Migration Numbering and Ordering

**Decision**: E04 migrations use timestamp prefix `2026_09_09_NNNNN` with sequential ordering.

E01 identity migrations are at `2026_08_21_NNNNN`. E04 must run after E01 and E03 (which use `2026_08_21_NNNNN` too, just higher numbers). Using `2026_09_09_NNNNN` guarantees E04 runs after all E01/E03 migrations.

**Migration execution order within E04**:
```
2026_09_09_001000_create_identity_roles_table
2026_09_09_002000_create_identity_permissions_table
2026_09_09_003000_create_identity_role_permissions_table
2026_09_09_004000_create_identity_user_roles_table
2026_09_09_005000_add_role_fk_to_identity_users
2026_09_09_006000_seed_identity_roles
2026_09_09_007000_seed_identity_permissions_and_role_assignments
2026_09_09_008000_seed_permission_cache_ttl_configuration
2026_09_09_009000_backfill_user_default_roles
```

The gap between numbers (1000-step) follows the E01/E03 convention and allows insertion of intermediate migrations during review.

---

## 5. `platform.configuration_values` Seed Format

**Decision**: Follow the exact schema from `2026_08_01_000006_create_platform_configuration_values.php` — columns are `config_key`, `config_value`, `value_type`, `status`, `version`, `is_tbd`.

The existing E01 seed at `2026_08_21_006000_seed_identity_configuration_defaults.php` uses `DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([...])` with these column names. E04 follows the same pattern.

Seed entry for `auth.permission_cache_ttl`:
```php
[
    'id'           => (string) Str::uuid(),
    'config_key'   => 'auth.permission_cache_ttl',
    'config_value' => '300',
    'value_type'   => 'number',
    'status'       => 'active',
    'version'      => 1,
    'is_tbd'       => false,
    'description'  => 'Seconds to cache per-actor permission resolution in Redis (0 = no cache)',
    'created_at'   => now()->toDateTimeString(),
    'updated_at'   => now()->toDateTimeString(),
]
```

**Note**: `ConfigurationApplicationService::createValue()` uses different column names (`namespace`, `key`, `value`) — this appears to be a discrepancy in that service relative to the actual schema. E04 seeds directly via `insertOrIgnore` on the actual schema columns, consistent with E01/E03.

---

## 6. Artisan Command Architecture

**Decision**: Standalone Artisan command in `app/Console/Commands/` following the `TestEventCommand` pattern.

The command is placed in `app/Console/Commands/CreateAdminCommand.php` (not inside a module) because it is an operational utility, not a domain service. It injects `UserRepositoryInterface` and `CredentialService` from the Identity module via the service container.

```php
protected $signature = 'identity:create-admin
    {--email= : Admin email address}
    {--name= : Admin display name}
    {--password= : Admin password (prompted if not provided)}
    {--force : Overwrite if admin with this email already exists}';
```

**Security constraint**: The command MUST use `$this->secret('Password:')` for interactive password entry (not `$this->ask()`) to avoid echoing to terminal. If `--password` is supplied as an argument, Laravel will accept it — the team should document that CI/CD usage should pipe via stdin or use a secrets manager, not shell arguments.

---

## 7. `hasPermission()` in `IdentityModule` — Session Revocation Check

**Question**: Should `hasPermission()` check whether the user's account is active/non-deactivated?

**Decision**: No — `hasPermission()` is a permission check, not an account-status check. Account status is enforced at authentication time (the JWT is refused if the session is revoked). By the time `hasPermission()` is called, the request has already passed `identity.auth` middleware which validates the JWT session status. Adding an account-status check in `hasPermission()` would duplicate session validation and create a performance regression on every permission check.

**Boundary**: `identity.auth` middleware owns "is this actor authenticated and active?". `hasPermission()` owns "does this actor have this permission?".

---

## 8. Open TBDs Remaining (not resolved in this session)

- **TBD-E04-001**: Enterprise actor role — deferred to product decision. Planning proceeds without it.
- **TBD-E04-003**: Agent-specific permissions — planning proceeds with the `agent` role seeded with zero explicit permissions. The Agent epic adds permissions via its own idempotent seed migration.
