# Research — E28 Configuration Management

## Decision 1: Workflow table design — change requests vs extending configuration_values

**Decision**: Create separate `platform.configuration_change_requests`, `platform.configuration_approvals`, and `platform.configuration_versions` tables rather than adding workflow columns to `platform.configuration_values`.

**Rationale**: The existing `ConfigurationService::get()` reads from `platform.configuration_values` and must not be changed (backward compatibility). The workflow entities have different lifecycles: a `ConfigurationChangeRequest` is mutable until resolved; a `ConfigurationVersion` is immutable forever. Mixing these into `configuration_values` would couple the clean read path with approval-state columns. Separation keeps the read path clean, mirrors the spec entity model, and allows each table to have appropriate constraints (append-only for versions via REVOKE UPDATE/DELETE, mutable for requests).

**Alternatives considered**:
- Add `maker_id`, `checker_id`, `status` columns to `configuration_values` — rejected: pollutes the read model; would require non-null columns on existing rows or nullable columns that are always null for pre-E28 records.
- Use `platform.configuration_audit` for version history — rejected: that table references `configuration_value_id`; it doesn't capture change request state transitions (rejected/withdrawn) that never produce a `configuration_value`.

---

## Decision 2: Activation path — stay within existing ConfigurationActivationJob

**Decision**: When a change request is approved, write a `draft` record to `platform.configuration_values` with `effective_from = NOW()`. The existing `ConfigurationActivationJob` (scheduled every minute) will promote it to `active` on its next run, busting the Redis cache. This satisfies the 60-second activation SLA (FR-011) without changes to `ConfigurationService`.

**Rationale**: The `ConfigurationActivationJob` already handles `draft → active` promotion atomically with Redis cache invalidation. Reusing it avoids duplicating that logic. At worst, there is a ~60-second lag between approval and activation, which the spec explicitly permits ("within 60 seconds").

**Alternatives considered**:
- Synchronous activation in the approval HTTP handler — rejected: makes the API handler dependent on a DB write that could be slow; also makes the handler non-idempotent if retried.
- New activation queue job dispatched immediately on approval — acceptable but adds complexity. The minute-scheduled job already satisfies the SLA.

---

## Decision 3: Concurrent approval prevention — lockForUpdate on change request row

**Decision**: The approval service uses `lockForUpdate()` on the `configuration_change_requests` row inside a DB transaction, mirroring the pattern established in E27 `DeadLetterService`. Two simultaneous approval calls will both acquire the lock, but only the first will see `status = 'pending_approval'`; the second will see `status = 'approved'` after the first commits and will return HTTP 409.

**Rationale**: PostgreSQL `READ COMMITTED` without `SELECT FOR UPDATE` would allow both concurrent transactions to read `pending_approval` before either writes. Same root cause as the E27 dead-letter fix (T078).

---

## Decision 4: One pending change per key — unique partial index

**Decision**: Enforce `UNIQUE (config_key) WHERE status = 'pending_approval'` as a partial index on `platform.configuration_change_requests`. Application-layer check will return HTTP 409 if a pending request exists; the DB constraint is a backstop.

**Rationale**: FR-007 requires exactly one pending change per key. A partial index (only on `pending_approval` rows) allows the same key to have historical approved/rejected records while preventing concurrent submissions.

---

## Decision 5: Domain taxonomy — enum in PHP code, no separate DB table

**Decision**: The configuration domain is a PHP-backed string enum (`ConfigurationDomain`) with values `financial_policy`, `operational_policy`, `geographic`. Domain metadata (display name, requires_maker_checker) is hardcoded in the enum. No `platform.configuration_domains` DB table is needed for MVP.

**Rationale**: E28 has exactly 3 domains that will not change at runtime. A DB table for domains would add unnecessary complexity and joins. The domain value is stored as a string column on `configurations` and `change_requests` tables, validated via the PHP enum at the application layer.

---

## Decision 6: Domain-specific validation — PHP validator classes

**Decision**: Each domain has a dedicated PHP class implementing `ConfigurationDomainValidatorInterface::validate(string $key, mixed $value): void`. Validation is invoked at change-request submission time (FR-003) and again at activation time (FR-013).

**Rationale**: Storing validation schema as JSONB in the database would require a schema-validation library and complicate the query path. PHP classes are simpler, testable, and co-located with the domain logic.

---

## Decision 7: Permissions — three new slugs in PlatformPermissions

**Decision**: Add three permissions:
- `configuration.read` — read any configuration key/domain/history
- `configuration.write` — submit change requests
- `configuration.approve` — approve or reject change requests

The existing `configuration.policy.update` is retained for backward compatibility (used by the existing `ConfigurationController` routes).

**Rationale**: The spec requires separate maker (`configuration.write`) and checker (`configuration.approve`) permissions; combining them would prevent self-approval enforcement. `configuration.read` is separate so operators can have read-only visibility.

---

## Decision 8: Admin portal — new section under /admin/configuration

**Decision**: New Next.js pages under `wallet-ecosystem/apps/admin/app/(admin)/configuration/`. Four views:
1. Domain list + key browser (`/admin/configuration`)
2. Key detail + version history (`/admin/configuration/[domain]/[key]`)
3. Pending approvals queue (`/admin/configuration/approvals`)
4. Change request detail + approve/reject actions (`/admin/configuration/approvals/[id]`)

**Rationale**: These mirror the admin portal pattern established in E19, E22, E27.

---

## Decision 9: Escalation check — Artisan command + scheduled daily

**Decision**: A `configuration:escalation-check` Artisan command scans for pending change requests older than the configurable escalation threshold (default: 24 hours from `config/configuration.php`) and emits an alert-level audit event for each. This is registered in `Console/Kernel.php` to run hourly.

**Rationale**: FR-010 requires alert emission after the threshold; hourly is sufficiently responsive while not being noisy.

---

## Decision 10: config/configuration.php — new config file

**Decision**: Create `config/configuration.php` with env-var-backed defaults:
- `escalation_threshold_hours` (default: 24)
- `max_history_per_page` (default: 50)

**Rationale**: Consistent with `config/reliability.php` pattern from E27. These values are operationally tunable without code deployment.
