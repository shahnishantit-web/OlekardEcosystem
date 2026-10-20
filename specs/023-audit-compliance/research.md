# Research — E23 Audit & Compliance

## Decision 1: Immutability Enforcement Strategy

**Decision**: Two-layer enforcement — application layer + database privilege restriction.

**Rationale**: Application layer alone is insufficient (any direct DB access bypasses it). Database-only enforcement loses the ability to produce a meaningful error response with a security event. Two layers provides defense-in-depth consistent with Constitution Principle 6 (Zero Trust).

**Mechanism**:
- `AuditEventModel` and `ComplianceHistoryModel` override `update()` and `delete()` to throw `AuditImmutabilityViolationException`; the exception handler records a security event before re-throwing as HTTP 403.
- The PostgreSQL application role (`platform_app`) has ONLY `INSERT` and `SELECT` on `audit.audit_events` and `audit.compliance_history` — no `UPDATE`, `DELETE`, or `TRUNCATE`.
- A `before_audit_record_mutation` database trigger also blocks any UPDATE or DELETE at the DB level and logs to the PostgreSQL server log.

**Alternatives considered**:
- Event sourcing / append-only event store (e.g., EventStore) — rejected; over-engineering for current modular-monolith stage; adds external dependency outside the approved stack.
- PostgreSQL row-level security (RLS) — complementary but insufficient alone; adds complexity without full coverage.

---

## Decision 2: Integrity Verification Token

**Decision**: SHA-256 HMAC over the canonicalized event fields, stored in `integrity_hash` column. Key stored in application secrets (never in DB).

**Canonical field order for HMAC**: `id|event_category|event_type|actor_id|entity_type|entity_id|outcome|occurred_at|correlation_id`

**Rationale**: HMAC-SHA256 is widely understood, fast, and produces a 64-character hex token that fits in a `char(64)` column. Using a server-side secret key means an attacker who reads the database cannot forge a valid hash without the key. Verification is performed on read via `AuditEventModel::verifyIntegrity()`.

**Key management**: `AUDIT_HMAC_KEY` environment variable; rotated via a key-rotation job that re-computes hashes (the only sanctioned "write" operation on audit records — done as a background migration, not an ad-hoc update).

**Alternatives considered**:
- SHA-256 without HMAC key — rejected; anyone with DB read access can re-compute valid hashes after tampering.
- Blockchain / Merkle tree — rejected; unnecessary complexity and outside approved stack.
- Database-native row hashing — not available in PostgreSQL without custom extensions.

---

## Decision 3: AuditModuleContract Write Pattern

**Decision**: Synchronous write within the caller's transaction, with outbox fallback for cross-system event delivery.

**Rationale**: Constitution Principle 9 (Event Reliability) and the pattern established in E07–E21 require that important state changes are persisted atomically. Audit events MUST be co-committed with the source operation to guarantee the "100% capture" success criterion (SC-001). Separate delivery via OutboxEventPublisher handles downstream fan-out (e.g., streaming to SIEM).

**Contract signature**:
```php
interface AuditModuleContract {
    public function record(AuditEntry $entry): AuditEventId;
}
```

`AuditEntry` is a value object carrying all fields. The implementation writes to `audit.audit_events` in the current DB connection's transaction context. If no transaction is active, it writes directly (fire-and-commit). An `AuditEventRecorded` integration event is published via outbox after the transaction commits.

**Callers**: E01 (auth events), E03 (security/session events), E04 (authz events), E05 (KYC events), E06 (wallet events), E07–E10 (financial transaction events), E11 (transaction events), E12–E14 (agent events), E18 (AML/security events), E19 (admin events), E20–E22 (config/compliance/support events).

**Alternatives considered**:
- Asynchronous-only via a dedicated queue — rejected; violates SC-001 (atomic capture guarantee); brief audit gap on source operation failure is unacceptable for compliance.
- Dual-write (sync + queue) — effectively the chosen approach; the "outbox" is the durable queue for cross-system delivery.

---

## Decision 4: Compliance Status Aggregation

**Decision**: E23 maintains a denormalized `compliance_status` record per user, updated reactively via integration events from E05 (KYC) and E18 (AML).

**Event subscriptions**:
- `KycStatusChangedEvent` (E05) → updates `compliance_status.kyc_tier` and appends a `ComplianceHistoryEntry`
- `AmlRiskLevelChangedEvent` (E18) → updates `compliance_status.aml_risk_level` and appends a `ComplianceHistoryEntry`
- `AmlRestrictionAppliedEvent` / `AmlRestrictionLiftedEvent` (E18) → updates `compliance_status.active_restrictions`

**Rationale**: Denormalization enables fast reads (SC-003: <300ms) without joining across module schemas at query time. The compliance_history table provides the full audit timeline without querying E05/E18 event logs.

**Alternatives considered**:
- Real-time federated query (join E05 + E18 tables on demand) — rejected; violates module boundary principle (Principle 7); adds cross-module coupling.
- Event sourcing replay — rejected; adds complexity; denormalized view is simpler and sufficient.

---

## Decision 5: Suspicious Activity Report (SAR) Field Set

**Decision**: Common-denominator international SAR fields aligned with FATF Recommendation 20 guidance. Not jurisdiction-specific; jurisdiction adaptations are deferred.

**Required fields**:
- Filing institution (platform identity, from platform config)
- Filing date
- Subject identity snapshot (name, DOB, ID numbers, address — from user profile at time of filing)
- Subject account information (wallet IDs, account types)
- Suspicious activity description (narrative text, reason code)
- Flagged transaction list (tx IDs, dates, amounts, currencies, counterparties)
- AML alert history (alert IDs, dates, descriptions, risk scores)
- Risk score timeline (from E18)
- Disposition (draft, submitted, filed)

**Report number format**: `SAR-{YYYYMMDD}-{6-digit sequence}` (e.g., `SAR-20260928-000001`)

**Alternatives considered**:
- FinCEN-specific XML format — too US-specific; platform targets multiple jurisdictions.
- Generic JSON blob with no required fields — rejected; insufficient for compliance review.

---

## Decision 6: Retention Enforcement Scheduling

**Decision**: Laravel Scheduler command running daily at 02:00 UTC. Soft-delete → archive window → hard purge sequence.

**Mechanism**:
- `php artisan audit:enforce-retention` — registered in `app/Console/Kernel.php`
- Per-category processing: for each `RetentionPolicy`, query records older than `(today - minimum_retention_days)` using a cursor-paginated scan
- Post-retention action `archive`: soft-deletes the record and copies to `audit.audit_events_archive` table
- Post-retention action `purge`: hard-deletes from the primary table (never from archive)
- Produces an `AuditRetentionEnforcedEvent` (audit event) summarising categories processed, record counts, and any errors

**Grace period**: A 24-hour "enforcement hold" applies when a RetentionPolicy is newly shortened — records due under the new policy are not processed until the next scheduled run after the hold expires.

**Alternatives considered**:
- Immediate purge on policy update — rejected; spec requires a review window.
- External cron job — rejected; Laravel Scheduler is the approved pattern (Horizon manages queue workers; Scheduler manages periodic tasks).

---

## Decision 7: Schema and Module Path Conventions

**Decision**: Follow established patterns from all prior epics.

- **Laravel module path**: `App\Modules\Audit\`
- **PostgreSQL schema**: `audit` (on `platform` DB connection)
- **Tables**: `audit.audit_events`, `audit.compliance_status`, `audit.compliance_history`, `audit.compliance_reports`, `audit.suspicious_activity_reports`, `audit.retention_policies`, `audit.audit_events_archive`
- **OpenAPI file**: `openapi/v1/audit.yaml`
- **Config file**: `config/audit.php`
- **Service provider**: `AuditServiceProvider`
- **Module contract**: `AuditModuleContract` — registered in `AuditServiceProvider`, bound to `AuditService`

**Rationale**: Consistency with E06–E22 reduces cognitive overhead and ensures extraction-readiness (Principle 7).

---

## Decision 8: Audit Event Category and Type Taxonomy

**Decision**: Two-level taxonomy — `event_category` (enum, 8 values) + `event_type` (string, dot-notation).

**Categories** (maps directly to US-AUDIT-001 through US-AUDIT-008):
- `authentication` — login, logout, token refresh, MFA challenge
- `authorization` — permission check, role assignment, access denied
- `wallet` — wallet created, activated, suspended, closed, limit changed
- `transaction` — transaction initiated, completed, failed, reversed, disputed
- `kyc` — document submitted, verified, rejected, tier upgraded/downgraded
- `admin` — user managed, dispute approved/rejected, account unlocked
- `config` — configuration key changed, fee rate updated, policy modified
- `security` — brute-force lockout, suspicious login, device revoked, failed MFA, tamper attempt

**Event type examples**: `authentication.login.success`, `wallet.created`, `kyc.tier.upgraded`, `config.fee_rate.changed`, `security.tamper.attempt`

**Rationale**: Category enables fast index-scans and coarse filtering; event_type provides precise event identification without a wide enum. Follows the same pattern as E17 (Notifications) event taxonomy.
