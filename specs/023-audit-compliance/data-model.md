# Data Model — E23 Audit & Compliance

All tables reside in the `audit` PostgreSQL schema on the `platform` connection.

---

## audit.audit_events

The core immutable audit record. Every platform event produces one row. No UPDATE or DELETE is permitted on this table.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, NOT NULL | UUID v7 |
| event_category | VARCHAR(32) | NOT NULL | Enum: authentication, authorization, wallet, transaction, kyc, admin, config, security |
| event_type | VARCHAR(128) | NOT NULL | Dot-notation: e.g., `authentication.login.success` |
| actor_id | UUID | NULLABLE | Null for system/automated events |
| actor_type | VARCHAR(16) | NOT NULL | Enum: customer, agent, admin, system |
| entity_type | VARCHAR(64) | NULLABLE | e.g., wallet, transaction, user, device |
| entity_id | UUID | NULLABLE | FK to the referenced entity (soft reference, no FK constraint) |
| action | VARCHAR(255) | NOT NULL | Human-readable action description |
| outcome | VARCHAR(16) | NOT NULL | Enum: success, failure, denied |
| before_state | JSONB | NULLABLE | State snapshot before the action |
| after_state | JSONB | NULLABLE | State snapshot after the action |
| metadata | JSONB | NOT NULL, DEFAULT '{}' | Additional context (source IP, device, user agent, etc.) |
| correlation_id | UUID | NOT NULL | Correlation ID from the source request |
| occurred_at | TIMESTAMPTZ | NOT NULL | Event occurrence time (from source operation) |
| integrity_hash | CHAR(64) | NOT NULL | HMAC-SHA256 over canonical fields |
| created_at | TIMESTAMPTZ | NOT NULL, DEFAULT now() | Write timestamp |

**Indexes**:
- `(event_category, occurred_at DESC)` — category-scoped time queries
- `(actor_id, occurred_at DESC)` — per-actor audit trail
- `(entity_type, entity_id, occurred_at DESC)` — per-entity event history
- `(correlation_id)` — correlation-scoped trace lookup
- `(occurred_at DESC)` — global time-ordered scan

**Constraints**:
- No UPDATE or DELETE privilege for the application DB role
- `before_update` trigger blocks any UPDATE attempt and raises an exception
- `integrity_hash` is computed on INSERT via `AuditEventModel::boot()` before-create hook

---

## audit.audit_events_archive

Mirror structure of `audit_events`. Rows are moved here by the retention enforcement process when `post_retention_action = 'archive'`. Provides long-term storage with reduced indexing overhead.

| Column | Type | Notes |
|--------|------|-------|
| (same columns as audit_events) | | |
| archived_at | TIMESTAMPTZ | NOT NULL, DEFAULT now() |

**Constraints**: Same immutability constraints as `audit_events`.

---

## audit.compliance_status

Denormalized current compliance state per user. One row per user. Updated reactively via integration events from E05 (KYC) and E18 (AML).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, NOT NULL | UUID v7 |
| user_id | UUID | NOT NULL, UNIQUE | References the user across modules |
| kyc_tier | SMALLINT | NOT NULL, DEFAULT 0 | 0=unverified, 1=basic, 2=enhanced, 3=full |
| aml_risk_level | VARCHAR(16) | NOT NULL, DEFAULT 'low' | Enum: low, medium, high, blocked |
| active_restrictions | JSONB | NOT NULL, DEFAULT '[]' | Array of restriction objects |
| last_changed_at | TIMESTAMPTZ | NULLABLE | Timestamp of the most recent status change |
| last_audit_event_id | UUID | NULLABLE | FK → audit_events.id (soft reference) |
| version | INTEGER | NOT NULL, DEFAULT 1 | Optimistic lock counter |
| created_at | TIMESTAMPTZ | NOT NULL, DEFAULT now() | |
| updated_at | TIMESTAMPTZ | NOT NULL, DEFAULT now() | |

**Indexes**:
- `(user_id)` — UNIQUE index (enforces one record per user)
- `(aml_risk_level)` — risk-level filtering for bulk queries

---

## audit.compliance_history

Immutable chronological record of every compliance status change. Append-only.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, NOT NULL | UUID v7 |
| user_id | UUID | NOT NULL | |
| changed_at | TIMESTAMPTZ | NOT NULL | Event occurrence timestamp (from source event) |
| field_changed | VARCHAR(32) | NOT NULL | Enum: kyc_tier, aml_risk_level, restrictions |
| before_value | JSONB | NULLABLE | Previous value |
| after_value | JSONB | NOT NULL | New value |
| source_system | VARCHAR(16) | NOT NULL | Enum: kyc, aml, admin |
| triggering_audit_event_id | UUID | NULLABLE | FK → audit_events.id (soft reference) |
| created_at | TIMESTAMPTZ | NOT NULL, DEFAULT now() | |

**Indexes**:
- `(user_id, changed_at DESC)` — per-user chronological history
- `(triggering_audit_event_id)` — event cross-reference

**Constraints**: No UPDATE or DELETE (append-only, same enforcement as `audit_events`).

---

## audit.compliance_reports

Generated compliance reports. Rows are created on request and populated asynchronously for large date ranges.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, NOT NULL | UUID v7 |
| report_type | VARCHAR(32) | NOT NULL | Enum: compliance_summary, kyc_snapshot, transaction_volume |
| status | VARCHAR(16) | NOT NULL, DEFAULT 'pending' | Enum: pending, generating, completed, failed |
| requested_by_actor_id | UUID | NOT NULL | Admin user who requested the report |
| date_range_start | DATE | NOT NULL | |
| date_range_end | DATE | NOT NULL | |
| filters | JSONB | NOT NULL, DEFAULT '{}' | Optional filters applied |
| idempotency_key | VARCHAR(64) | NOT NULL, UNIQUE | Caller-supplied idempotency key |
| report_data | JSONB | NULLABLE | Populated when status = completed (for small reports) |
| report_file_reference | VARCHAR(255) | NULLABLE | Storage reference for large reports |
| generated_at | TIMESTAMPTZ | NULLABLE | When generation completed |
| expires_at | TIMESTAMPTZ | NULLABLE | When the report is eligible for cleanup |
| created_at | TIMESTAMPTZ | NOT NULL, DEFAULT now() | |
| updated_at | TIMESTAMPTZ | NOT NULL, DEFAULT now() | |

**Indexes**:
- `(idempotency_key)` — UNIQUE index
- `(requested_by_actor_id, created_at DESC)` — per-actor report listing
- `(status, created_at DESC)` — status-filtered listing

---

## audit.suspicious_activity_reports

Structured regulatory filings (SARs). Append-only for core identity fields; `status` and `narrative` are updateable prior to submission.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, NOT NULL | UUID v7 |
| report_number | VARCHAR(32) | NOT NULL, UNIQUE | Format: `SAR-{YYYYMMDD}-{6-digit seq}` |
| subject_user_id | UUID | NOT NULL | |
| status | VARCHAR(16) | NOT NULL, DEFAULT 'draft' | Enum: draft, submitted, filed |
| reason_code | VARCHAR(64) | NOT NULL | e.g., `unusual_transaction_pattern`, `multiple_failed_auth` |
| reason_narrative | TEXT | NOT NULL | Free-text explanation |
| subject_identity_snapshot | JSONB | NOT NULL | User identity at time of filing |
| flagged_transaction_ids | JSONB | NOT NULL, DEFAULT '[]' | Array of transaction UUIDs |
| aml_alert_history | JSONB | NOT NULL, DEFAULT '[]' | Alert records from E18 |
| risk_score_timeline | JSONB | NOT NULL, DEFAULT '[]' | Score history from E18 |
| idempotency_key | VARCHAR(64) | NOT NULL, UNIQUE | |
| created_by_actor_id | UUID | NOT NULL | |
| submitted_at | TIMESTAMPTZ | NULLABLE | |
| filed_at | TIMESTAMPTZ | NULLABLE | |
| created_at | TIMESTAMPTZ | NOT NULL, DEFAULT now() | |
| updated_at | TIMESTAMPTZ | NOT NULL, DEFAULT now() | |

**Indexes**:
- `(report_number)` — UNIQUE
- `(subject_user_id, created_at DESC)` — per-subject SAR listing
- `(status)` — status-filtered listing

---

## audit.retention_policies

Configurable per-category data retention rules. One row per `data_category`. Updated in-place (not append-only).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, NOT NULL | UUID v7 |
| data_category | VARCHAR(64) | NOT NULL, UNIQUE | e.g., `audit.authentication`, `audit.financial`, `compliance.history` |
| minimum_retention_days | INTEGER | NOT NULL, CHECK > 0 | Minimum legal hold period |
| post_retention_action | VARCHAR(8) | NOT NULL | Enum: archive, purge |
| effective_from | DATE | NOT NULL, DEFAULT CURRENT_DATE | When this policy takes effect |
| enforcement_hold_until | DATE | NULLABLE | Grace period end date (set when policy is shortened) |
| configured_by_actor_id | UUID | NOT NULL | Last admin to change this policy |
| previous_minimum_retention_days | INTEGER | NULLABLE | For audit history purposes |
| created_at | TIMESTAMPTZ | NOT NULL, DEFAULT now() | |
| updated_at | TIMESTAMPTZ | NOT NULL, DEFAULT now() | |

**Indexes**:
- `(data_category)` — UNIQUE index

---

## Entity Relationships

```
audit_events ←── (soft ref) ── compliance_history.triggering_audit_event_id
audit_events ←── (soft ref) ── compliance_status.last_audit_event_id
audit_events ──────────────── audit_events_archive (migration target)

compliance_status ──1:N── compliance_history (per user_id)
```

**Soft references** (no FK constraints across schema boundaries) are intentional to prevent cross-module cascade deletes and to allow independent schema evolution per bounded context. Referential integrity is enforced at the application layer.

---

## AuditEntry Value Object

The `AuditEntry` DTO passed to `AuditModuleContract::record()`:

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| eventCategory | AuditEventCategory (enum) | Yes | |
| eventType | string | Yes | Dot-notation |
| actorId | ?string | No | Null for system events |
| actorType | AuditActorType (enum) | Yes | |
| entityType | ?string | No | |
| entityId | ?string | No | |
| action | string | Yes | Human-readable description |
| outcome | AuditOutcome (enum) | Yes | success / failure / denied |
| beforeState | ?array | No | |
| afterState | ?array | No | |
| metadata | array | No | Default [] |
| correlationId | string | Yes | From X-Correlation-ID header |
| occurredAt | Carbon | Yes | Defaults to now() if not provided |
