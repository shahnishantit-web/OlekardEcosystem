# Data Model: Platform Foundation

**Branch**: `999-platform-foundation` | **Date**: 2026-08-16

This document defines all database entities created by Platform Foundation. All entities are in the `platform` schema. Other module schemas (`identity`, `wallet`, `ledger`, etc.) are created as empty schemas with ownership declared — their tables are the responsibility of E01–E34.

All primary keys are UUID v7 (`uuid` PostgreSQL type). All timestamps are `TIMESTAMP WITH TIME ZONE` in UTC.

---

## Schema Ownership Map

| PostgreSQL Schema | Owning Module | Created By | Tables Created By |
|---|---|---|---|
| `platform` | Platform | Platform Foundation | Platform Foundation |
| `identity` | Identity | Platform Foundation (schema only) | E01, E02, E03 |
| `wallet` | Wallet | Platform Foundation (schema only) | E06 |
| `ledger` | Ledger | Platform Foundation (schema only) | E07 |
| `kyc` | Kyc | Platform Foundation (schema only) | E05 |
| `transaction` | Transaction | Platform Foundation (schema only) | E11 |
| `risk` | Risk | Platform Foundation (schema only) | Epic TBD |
| `agent` | Agent | Platform Foundation (schema only) | E13–E18 |
| `notification` | Notification | Platform Foundation (schema only) | Epic TBD |

**Rule**: No migration in one schema may create a `FOREIGN KEY` constraint referencing a table in another schema. Cross-module references use UUID values only, validated at the application layer.

---

## Platform Tables

### `platform.idempotency_records`

Authoritative store for idempotency key processing state. Redis caches this; PostgreSQL is the source of truth.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK NOT NULL | UUID v7 |
| `idempotency_key` | `varchar(255)` | NOT NULL | Client-provided UUID v7 |
| `actor_id` | `uuid` | NOT NULL | Calling actor; use zero UUID (`00000000-0000-0000-0000-000000000000`) for unauthenticated system calls |
| `actor_type` | `varchar(20)` | NOT NULL DEFAULT `'USER'` | Enum: `USER`, `SERVICE`, `SYSTEM` |
| `request_hash` | `varchar(64)` | NOT NULL | SHA-256 of (method + path + body) — detects payload mismatch |
| `http_method` | `varchar(10)` | NOT NULL | e.g., `POST` |
| `http_path` | `varchar(500)` | NOT NULL | e.g., `/v1/wallets/transfers` |
| `status` | `varchar(20)` | NOT NULL DEFAULT `'processing'` | Enum: `processing`, `completed`, `failed` |
| `response_status` | `integer` | NULL | HTTP status of the completed response |
| `response_body` | `jsonb` | NULL | Stored response for replay |
| `processing_started_at` | `timestamptz` | NULL | Set when processing begins; used to detect stuck-processing records |
| `attempt_count` | `integer` | NOT NULL DEFAULT `1` | Number of processing attempts |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` | NOT NULL DEFAULT `now()` | Updated on `recordResponse()` and `recordFailure()` |
| `expires_at` | `timestamptz` | NOT NULL | `created_at + config(idempotency.ttl_seconds)` |

**Indexes**:
- `PRIMARY KEY (id)`
- `UNIQUE INDEX ON (actor_id, idempotency_key)` — key uniqueness is scoped per actor; different actors may reuse the same key value
- `INDEX ON (status)` — for status-based filtering
- `INDEX ON (processing_started_at)` — for stuck-processing cleanup job
- `INDEX ON (updated_at)` — for stuck-processing cleanup query (`status='processing' AND updated_at < now() - timeout`)

**Cleanup**: A Horizon scheduled job deletes rows where `expires_at < now()`. A separate cleanup job resets rows where `status = 'processing'` and `updated_at < now() - config(events.outbox.processing_timeout_seconds)` back to `processing` with a reset (recovers from worker crashes).

---

### `platform.outbox_events`

Durable event store for the Outbox pattern. Events are written here within the domain database transaction, then dispatched asynchronously.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK NOT NULL | UUID v7 |
| `aggregate_id` | `uuid` | NOT NULL | ID of the root aggregate that emitted the event |
| `aggregate_type` | `varchar(100)` | NOT NULL | e.g., `Transfer`, `User`, `LedgerEntry` |
| `actor_id` | `uuid` | NULL | Who triggered the state change |
| `correlation_id` | `uuid` | NOT NULL | Propagated from the originating HTTP request |
| `causation_id` | `uuid` | NULL | ID of the event that caused this event (event chains) |
| `event_name` | `varchar(255)` | NOT NULL | Dot-notation PascalCase (e.g., `Wallet.Transfer.Completed`) |
| `event_version` | `varchar(20)` | NOT NULL DEFAULT `'1.0.0'` | SemVer |
| `payload` | `jsonb` | NOT NULL | Event-specific data |
| `metadata` | `jsonb` | NOT NULL DEFAULT `'{}'` | Environment, app version, routing hints |
| `status` | `varchar(20)` | NOT NULL DEFAULT `'pending'` | Enum: `pending`, `processing`, `delivered`, `failed`, `dead_letter` |
| `attempts` | `smallint` | NOT NULL DEFAULT `0` | |
| `max_attempts` | `smallint` | NOT NULL DEFAULT `5` | Configurable per event type via `configuration_values` |
| `next_attempt_at` | `timestamptz` | NULL | Exponential backoff target |
| `last_error` | `text` | NULL | Last dispatch error for debugging |
| `processing_started_at` | `timestamptz` | NULL | Set when dispatcher acquires record; used to detect stuck-processing records |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |
| `delivered_at` | `timestamptz` | NULL | Set when status → `delivered` |

**Indexes**:
- `PRIMARY KEY (id)`
- `INDEX ON (status, next_attempt_at)` — for dispatcher worker query
- `INDEX ON (status, processing_started_at)` — for stuck-processing cleanup job
- `INDEX ON (aggregate_id, event_name)` — for event history lookups
- `INDEX ON (correlation_id)` — for trace correlation

**Permissions**: Application role has INSERT, SELECT, UPDATE on `status`, `attempts`, `next_attempt_at`, `last_error`, `processing_started_at`, `delivered_at`. No DELETE.

**Stuck-processing recovery**: A scheduled cleanup job resets records where `status = 'processing'` AND `processing_started_at < now() - config(events.outbox.processing_timeout_seconds)` back to `status = 'pending'` with `processing_started_at = NULL`. The `attempts` counter is NOT reset. This recovers events lost when a dispatcher worker crashes mid-processing.

---

### `platform.audit_events`

Immutable audit log. Application role has INSERT and SELECT only — no UPDATE or DELETE.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK NOT NULL | UUID v7 |
| `actor_id` | `uuid` | NULL | Nullable for system/anonymous events |
| `actor_type` | `varchar(50)` | NOT NULL | Enum: `user`, `service`, `system` |
| `actor_name_masked` | `varchar(255)` | NULL | Masked display name (no PII) |
| `action` | `varchar(100)` | NOT NULL | Verb describing the action (e.g., `login`, `config.update`, `permission.denied`) |
| `resource_type` | `varchar(100)` | NOT NULL | e.g., `User`, `Configuration`, `WalletTransfer` |
| `resource_id` | `varchar(255)` | NULL | ID of the affected resource |
| `before_state` | `jsonb` | NULL | State before the action (PII fields pre-masked) |
| `after_state` | `jsonb` | NULL | State after the action (PII fields pre-masked) |
| `outcome` | `varchar(20)` | NOT NULL DEFAULT `'success'` | Enum: `success`, `failure`, `partial` |
| `correlation_id` | `uuid` | NOT NULL | Links to the originating request |
| `ip_address` | `inet` | NULL | Masked/hashed for GDPR compliance TBD |
| `user_agent` | `text` | NULL | |
| `metadata` | `jsonb` | NOT NULL DEFAULT `'{}'` | Arbitrary context (module, operation type) |
| `occurred_at` | `timestamptz` | NOT NULL DEFAULT `now()` | Immutable event timestamp; set by AuditService at insert. `created_at` is intentionally absent — the table is INSERT-only and the event time is the record time. |

**Indexes**:
- `PRIMARY KEY (id)`
- `INDEX ON (actor_id, occurred_at)`
- `INDEX ON (resource_type, resource_id)`
- `INDEX ON (action, occurred_at)`
- `INDEX ON (correlation_id)`

**Immutability**: Migration revokes UPDATE and DELETE on this table from the application DB role. Archival to WORM storage is a TBD operational decision; the table design does not depend on the chosen mechanism.

---

### `platform.configuration_values`

Stores versioned configuration and policy values. Supports effective dates and draft/active lifecycle.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK NOT NULL | UUID v7 |
| `config_key` | `varchar(255)` | NOT NULL | Dot-notation key (e.g., `transaction.limit.daily`) |
| `value` | `jsonb` | NOT NULL | Typed value; `null` for TBD items |
| `value_type` | `varchar(20)` | NOT NULL | Enum: `string`, `number`, `boolean`, `json`, `null` |
| `version` | `integer` | NOT NULL DEFAULT `1` | Increments on each update |
| `status` | `varchar(20)` | NOT NULL DEFAULT `'draft'` | Enum: `draft`, `active`, `archived` |
| `effective_from` | `timestamptz` | NULL | NULL = effective immediately on activation |
| `effective_until` | `timestamptz` | NULL | NULL = no expiry |
| `description` | `text` | NULL | Human-readable documentation |
| `approved_by_id` | `uuid` | NULL | Actor who approved activation |
| `approved_at` | `timestamptz` | NULL | |
| `created_by_id` | `uuid` | NULL | |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |

**Indexes**:
- `PRIMARY KEY (id)`
- `UNIQUE INDEX ON (config_key, version)`
- `INDEX ON (config_key, status, effective_from)` — for active value lookup
- `INDEX ON (status, effective_from)` — for scheduled activation job

---

### `platform.configuration_audit`

Immutable audit trail for all configuration changes. Same immutability rules as `platform.audit_events`.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK NOT NULL | UUID v7 |
| `configuration_id` | `uuid` | NOT NULL | References `platform.configuration_values(id)` (same schema — FK is valid) |
| `actor_id` | `uuid` | NULL | |
| `action` | `varchar(50)` | NOT NULL | Enum: `created`, `updated`, `activated`, `archived`, `approved` |
| `old_value` | `jsonb` | NULL | |
| `new_value` | `jsonb` | NULL | |
| `reason` | `text` | NULL | Optional change reason |
| `correlation_id` | `uuid` | NOT NULL | |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |

---

## Value Objects (PHP, no DB table)

These are used throughout the application layer and are not persisted independently.

### `Money`
```
amount: integer (smallest currency unit, e.g., thebe for BWP)
currency: Currency
```
Never use floats. Store as integer in DB (BIGINT). `NUMERIC(19,4)` is acceptable for display/reporting columns.

### `Currency`
```
code: string (ISO 4217, e.g., "BWP")
precision: integer (e.g., 2 for BWP)
name: string
```
Supported (seeded): BWP (primary), USD, EUR, ZAR, CNY, USDT.

### `CorrelationId`
```
value: uuid (UUID v7 or v4, provided by client or generated by middleware)
```

### `IdempotencyKey`
```
value: uuid (UUID v7, client-provided)
```

### `Actor`
```
id: uuid
type: enum (user | service | system)
```

---

## Schema Creation Migrations (Platform Foundation Responsibility)

Platform Foundation creates all schemas but only populates `platform.*` tables. Other schemas are created empty for future modules.

**Migration order**:
1. `2026_08_01_000001_create_all_schemas.php` — Creates all 9 schemas
2. `2026_08_01_000002_create_platform_idempotency_records.php`
3. `2026_08_01_000003_create_platform_outbox_events.php`
4. `2026_08_01_000004_create_platform_audit_events.php`
5. `2026_08_01_000005_create_platform_configuration_values.php`
6. `2026_08_01_000006_create_platform_configuration_audit.php`
7. `2026_08_01_000007_seed_platform_configuration_defaults.php` — Seeds approved currency list + TBD placeholders

---

## Cross-Module Reference Strategy

When Module A needs data from Module B:

| Pattern | When to Use | Implementation |
|---|---|---|
| **Application Service Call** | Synchronous, same request scope | Module A calls Module B's public `ContractInterface` via DI |
| **Event consumption** | Async, eventual consistency acceptable | Module A subscribes to Module B's `IntegrationEvent` and maintains its own read projection |
| **Read model / projection** | Complex queries spanning modules | Module A maintains a `{ModuleName}Projection` table in its own schema, updated via events |
| **API call** | Post-extraction (microservices phase only) | HTTP client call to Module B's API (not used in monolith phase) |

**Strictly forbidden**: `JOIN identity.users ON wallet.wallets.owner_id = identity.users.id` — cross-schema SQL JOIN.
