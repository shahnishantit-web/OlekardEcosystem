# Data Model — E25 API & Integration Platform

All tables live in the `integration` PostgreSQL schema. All primary keys are UUID v7. All timestamps are `timestamptz` (UTC). Foreign keys to other modules reference by UUID only — no cross-schema FK constraints.

---

## Entity: ApiClient

**Table**: `integration.api_clients`

Represents a registered external or internal system authorized to call the platform API.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | UUID | PK | UUID v7 |
| `name` | varchar(255) | NOT NULL | Human-readable client name |
| `api_key` | varchar(64) | NOT NULL, UNIQUE | 32-char hex public identifier |
| `secret_hash` | varchar(255) | NOT NULL | bcrypt hash of secret |
| `status` | enum | NOT NULL, DEFAULT `active` | `active`, `inactive` |
| `permissions` | jsonb | NOT NULL, DEFAULT `[]` | Array of permission strings |
| `rate_limit_per_minute` | int | NOT NULL, DEFAULT 60 | Per-client quota |
| `last_used_at` | timestamptz | NULLABLE | Updated on each authenticated request |
| `created_by_user_id` | UUID | NULLABLE | Admin user who created the client |
| `created_at` | timestamptz | NOT NULL | |
| `updated_at` | timestamptz | NOT NULL | |

**Indexes**:
- `UNIQUE (api_key)`
- Index on `(status)` for active-client lookups

---

## Entity: ApiClientKeyRotation

**Table**: `integration.api_client_key_rotations`

Immutable audit log of every secret rotation for an API client.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | UUID | PK | UUID v7 |
| `api_client_id` | UUID | NOT NULL, FK → api_clients | |
| `old_key_prefix` | varchar(8) | NOT NULL | First 8 chars of old API key |
| `rotated_by_user_id` | UUID | NULLABLE | Admin who triggered rotation |
| `rotated_at` | timestamptz | NOT NULL | |
| `reason` | text | NULLABLE | Optional reason note |

**Note**: Rows in this table are never updated or deleted (append-only audit trail).

---

## Entity: Integration (Provider Configuration)

**Table**: `integration.integrations`

Configures an external provider connection for a specific provider type.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | UUID | PK | UUID v7 |
| `provider_type` | varchar(32) | NOT NULL | `otp`, `sms`, `email`, `kyc`, `payment`, `bank`, `mobile_money` |
| `provider_slug` | varchar(64) | NOT NULL | e.g., `twilio`, `sendgrid`, `smile-id` — must match service container binding key |
| `display_name` | varchar(255) | NOT NULL | Human-readable label |
| `credentials_reference` | varchar(255) | NOT NULL | Platform config key pointing to credentials (never raw credentials) |
| `endpoint_override` | varchar(500) | NULLABLE | Sandbox / staging endpoint; NULL uses provider default |
| `status` | enum | NOT NULL, DEFAULT `active` | `active`, `inactive` |
| `is_default` | boolean | NOT NULL, DEFAULT false | True = used when no specific provider is requested |
| `configuration` | jsonb | NULLABLE | Provider-specific non-secret settings (timeouts, feature flags) |
| `created_by_user_id` | UUID | NULLABLE | Admin who registered this integration |
| `created_at` | timestamptz | NOT NULL | |
| `updated_at` | timestamptz | NOT NULL | |

**Constraints**:
- `UNIQUE (provider_type, provider_slug)` — one configuration per provider per type
- Only one row per `provider_type` may have `is_default = true` (enforced at application layer + partial unique index `WHERE is_default = TRUE`)

---

## Entity: ProviderCallLog

**Table**: `integration.provider_call_logs`

Immutable audit record of every outbound call to an external provider.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | UUID | PK | UUID v7 |
| `integration_id` | UUID | NOT NULL, FK → integrations | Which provider was called |
| `correlation_id` | UUID | NOT NULL | End-to-end correlation ID |
| `provider_reference` | varchar(255) | NULLABLE | Provider's own reference/transaction ID |
| `request_summary` | jsonb | NOT NULL | Method, endpoint path, payload shape — NO credentials or PII |
| `response_code` | int | NULLABLE | HTTP or provider-specific response code |
| `outcome` | enum | NOT NULL | `success`, `failure`, `timeout` |
| `retry_eligible` | boolean | NOT NULL, DEFAULT false | Whether domain layer may retry |
| `elapsed_ms` | int | NULLABLE | Round-trip time in milliseconds |
| `created_at` | timestamptz | NOT NULL | |

**Note**: Rows are never updated (append-only). Indexed on `(integration_id, created_at)` for log queries and `(correlation_id)` for tracing.

---

## Entity: WebhookEvent

**Table**: `integration.webhook_events`

Stores every inbound provider webhook with its full lifecycle.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | UUID | PK | UUID v7 |
| `provider_type` | varchar(32) | NOT NULL | e.g., `payment` |
| `provider_slug` | varchar(64) | NOT NULL | e.g., `mock-payment` |
| `idempotency_key` | varchar(255) | NOT NULL | Provider-supplied event ID (from `NormalizedWebhookEvent.idempotencyKey`) |
| `event_type` | varchar(100) | NOT NULL | Normalized event type string |
| `raw_payload` | text | NOT NULL | Verbatim request body (for audit/replay) |
| `source_ip_hash` | varchar(64) | NOT NULL | SHA-256 hash of source IP |
| `status` | enum | NOT NULL, DEFAULT `received` | `received`, `processing`, `processed`, `failed`, `dead_letter` |
| `retry_count` | int | NOT NULL, DEFAULT 0 | Number of processing attempts made |
| `max_retry_count` | int | NOT NULL, DEFAULT 5 | Configurable per provider type |
| `last_error` | text | NULLABLE | Most recent handler exception message |
| `platform_entity_id` | UUID | NULLABLE | Resolved platform entity (may be null until deferred resolution) |
| `platform_entity_type` | varchar(64) | NULLABLE | e.g., `funding_transaction`, `kyc_verification` |
| `received_at` | timestamptz | NOT NULL | When the HTTP request was received |
| `processed_at` | timestamptz | NULLABLE | When handler confirmed `processed` |
| `created_at` | timestamptz | NOT NULL | |
| `updated_at` | timestamptz | NOT NULL | |

**Constraints**:
- `UNIQUE (provider_slug, idempotency_key)` — database-level idempotency enforcement
- Partial index on `(status, created_at) WHERE status IN ('failed', 'dead_letter')` for retry queries

**State Machine**:
```
received → processing  (on job dispatch)
processing → processed (on handler success)
processing → failed    (on handler exception)
failed → processing    (on retry attempt, retry_count < max_retry_count)
failed → dead_letter   (when retry_count >= max_retry_count)
dead_letter → received (operator manual re-queue only)
```

---

## Entity: WebhookProcessingAttempt

**Table**: `integration.webhook_processing_attempts`

Immutable log of every processing attempt for a webhook event.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | UUID | PK | UUID v7 |
| `webhook_event_id` | UUID | NOT NULL, FK → webhook_events | |
| `attempt_number` | int | NOT NULL | 1-based attempt index |
| `outcome` | enum | NOT NULL | `success`, `failure` |
| `error_message` | text | NULLABLE | Exception message on failure |
| `attempted_at` | timestamptz | NOT NULL | |

**Note**: Rows are append-only. Indexed on `(webhook_event_id, attempted_at)`.

---

## Relationships Summary

```
ApiClient ──< ApiClientKeyRotation
Integration ──< ProviderCallLog
WebhookEvent ──< WebhookProcessingAttempt
```

No cross-module foreign key constraints. Integration module references other modules' entity IDs (e.g., `platform_entity_id` in `WebhookEvent`) as plain UUID columns only.

---

## Enums

**`api_clients.status`**: `active`, `inactive`

**`integrations.provider_type`**: `otp`, `sms`, `email`, `kyc`, `payment`, `bank`, `mobile_money`

**`integrations.status`**: `active`, `inactive`

**`provider_call_logs.outcome`**: `success`, `failure`, `timeout`

**`webhook_events.status`**: `received`, `processing`, `processed`, `failed`, `dead_letter`

**`webhook_processing_attempts.outcome`**: `success`, `failure`
