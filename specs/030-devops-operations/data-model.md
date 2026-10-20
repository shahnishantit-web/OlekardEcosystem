# Data Model — E30 DevOps & Operations

**DB Schema:** `ops` (PostgreSQL schema)

---

## Entity: LogEntry

**Table:** `ops.log_entries`

Structured log records emitted by all platform services. Partitioned by month for retention management.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| service_name | VARCHAR(100) | NOT NULL | Emitting service (e.g., "api", "horizon") |
| correlation_id | UUID | NOT NULL | Request-level trace ID |
| severity | VARCHAR(20) | NOT NULL | `debug` \| `info` \| `warning` \| `error` \| `critical` |
| message | TEXT | NOT NULL | Human-readable log message |
| context | JSONB | NOT NULL DEFAULT '{}' | Structured fields (no secrets) |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | Partition key |

**Indexes:**
- `idx_log_entries_correlation_id` ON (correlation_id)
- `idx_log_entries_severity_time` ON (severity, created_at DESC)
- `idx_log_entries_service_time` ON (service_name, created_at DESC)
- `idx_log_entries_context_gin` GIN ON (context) — JSONB full-text search

**Rules:**
- Records are append-only; no updates or deletes (except partition drops for retention).
- `context` MUST NOT contain plaintext passwords, PINs, or API secrets (enforced by LogSanitizer middleware).
- Partitioned by `created_at` monthly; retention job drops partitions older than configured window.

---

## Entity: ServiceMetric

**Table:** `ops.service_metrics`

Time-series performance measurements emitted by services and aggregated for historical queries.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| service_name | VARCHAR(100) | NOT NULL | |
| metric_name | VARCHAR(100) | NOT NULL | e.g., "http.request_count", "http.latency_p95" |
| value | DECIMAL(18,4) | NOT NULL | |
| unit | VARCHAR(50) | NOT NULL | e.g., "ms", "count", "percent" |
| granularity | VARCHAR(10) | NOT NULL DEFAULT 'raw' | `raw` \| `minute` \| `hour` \| `day` |
| recorded_at | TIMESTAMPTZ | NOT NULL | Bucket start time for aggregated rows |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**Indexes:**
- `idx_service_metrics_lookup` ON (service_name, metric_name, granularity, recorded_at DESC)
- `idx_service_metrics_time` ON (recorded_at DESC)

**Rules:**
- Raw rows retained for 24 hours; hourly aggregates for 30 days; daily aggregates per configured retention.
- `MetricsAggregationJob` (scheduled hourly) rolls up raw → minute → hour rows.

---

## Entity: HealthSnapshot

**Table:** `ops.health_snapshots`

Point-in-time health assessment snapshots recorded by periodic health check jobs.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| service_name | VARCHAR(100) | NOT NULL | |
| overall_status | VARCHAR(20) | NOT NULL | `healthy` \| `degraded` \| `unhealthy` |
| components | JSONB | NOT NULL | Array of `{name, status, details, latency_ms}` |
| checked_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**Indexes:**
- `idx_health_snapshots_service_time` ON (service_name, checked_at DESC)

---

## Entity: ProviderHealthCheck

**Table:** `ops.provider_health_checks`

Individual payment provider probe results (rolling log of probes).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| provider_code | VARCHAR(100) | NOT NULL | FK → E25 provider registry |
| probe_status | VARCHAR(20) | NOT NULL | `success` \| `timeout` \| `error` |
| response_time_ms | INTEGER | NULL | NULL if timeout or error |
| error_message | TEXT | NULL | |
| checked_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**Indexes:**
- `idx_provider_health_checks_lookup` ON (provider_code, checked_at DESC)

---

## Entity: ProviderStatus

**Table:** `ops.provider_status`

Current aggregated status for each payment provider (maintained by rolling-window evaluation).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| provider_code | VARCHAR(100) | PK | |
| current_status | VARCHAR(20) | NOT NULL | `up` \| `degraded` \| `down` |
| last_checked_at | TIMESTAMPTZ | NOT NULL | |
| status_changed_at | TIMESTAMPTZ | NOT NULL | When current_status last changed |
| consecutive_failures | INTEGER | NOT NULL DEFAULT 0 | |
| consecutive_successes | INTEGER | NOT NULL DEFAULT 0 | |
| updated_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

---

## Entity: AlertRule

**Table:** `ops.alert_rules`

Operator-configured threshold rules that trigger alert notifications.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| name | VARCHAR(255) | NOT NULL | Human-readable rule name |
| description | TEXT | NULL | |
| metric_condition | JSONB | NOT NULL | `{metric, operator, threshold, duration_seconds}` |
| severity | VARCHAR(20) | NOT NULL | `info` \| `warning` \| `critical` |
| notification_channels | JSONB | NOT NULL | Array of `{type, config}` (e.g., email, webhook) |
| grouping_key | VARCHAR(100) | NULL | Groups related alerts for de-duplication |
| is_enabled | BOOLEAN | NOT NULL DEFAULT true | |
| created_by | UUID | NOT NULL | FK → identity.users(id) |
| updated_by | UUID | NULL | FK → identity.users(id) |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |
| updated_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

---

## Entity: Alert

**Table:** `ops.alerts`

Triggered alert instances produced when an AlertRule's condition is satisfied.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| alert_rule_id | UUID | NOT NULL | FK → ops.alert_rules(id) |
| status | VARCHAR(20) | NOT NULL | `firing` \| `resolved` |
| condition_summary | TEXT | NOT NULL | Human-readable description of what triggered the alert |
| fired_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |
| resolved_at | TIMESTAMPTZ | NULL | |
| notification_sent_at | TIMESTAMPTZ | NULL | When firing notification was delivered |
| resolution_notification_sent_at | TIMESTAMPTZ | NULL | |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**Indexes:**
- `idx_alerts_rule_status` ON (alert_rule_id, status)
- `idx_alerts_fired_at` ON (fired_at DESC)

**State transitions:** `firing → resolved` (when condition clears)

---

## Entity: BackupJob

**Table:** `ops.backup_jobs`

Execution record for each scheduled or manually triggered database backup.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| backup_type | VARCHAR(20) | NOT NULL | `full` \| `incremental` |
| status | VARCHAR(20) | NOT NULL DEFAULT 'pending' | `pending` \| `running` \| `completed` \| `failed` |
| started_at | TIMESTAMPTZ | NULL | |
| completed_at | TIMESTAMPTZ | NULL | |
| size_bytes | BIGINT | NULL | Set on completion |
| storage_location | TEXT | NULL | Provider-specific reference; set on completion |
| failure_reason | TEXT | NULL | |
| triggered_by | UUID | NULL | FK → identity.users(id); NULL = scheduled |
| idempotency_key | VARCHAR(255) | UNIQUE NOT NULL | |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**Indexes:**
- `idx_backup_jobs_status_time` ON (status, created_at DESC)

**State transitions:** `pending → running → completed` | `pending → running → failed`

**Rules:**
- Records are immutable once in `completed` or `failed` state.
- Failed jobs trigger an alert via AlertDispatchService.

---

## Entity: DeploymentVersion

**Table:** `ops.deployment_versions`

Records of each application deployment, used as rollback targets.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| version_tag | VARCHAR(100) | NOT NULL | e.g., "v2.4.1", "git-sha-abc123" |
| deployed_at | TIMESTAMPTZ | NOT NULL | |
| deployed_by | UUID | NULL | FK → identity.users(id); NULL = automated CI |
| is_current | BOOLEAN | NOT NULL DEFAULT false | |
| notes | TEXT | NULL | |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**Indexes:**
- `idx_deployment_versions_time` ON (deployed_at DESC)
- `idx_deployment_versions_current` ON (is_current) WHERE is_current = true

---

## Entity: DeploymentRollback

**Table:** `ops.deployment_rollbacks`

Rollback requests and their execution outcomes.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK, default uuid_generate_v7() | |
| from_version_id | UUID | NOT NULL | FK → ops.deployment_versions(id) |
| to_version_id | UUID | NOT NULL | FK → ops.deployment_versions(id) |
| status | VARCHAR(20) | NOT NULL DEFAULT 'pending' | `pending` \| `in_progress` \| `completed` \| `failed` |
| initiated_by | UUID | NOT NULL | FK → identity.users(id) |
| initiated_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |
| completed_at | TIMESTAMPTZ | NULL | |
| failure_reason | TEXT | NULL | |
| idempotency_key | VARCHAR(255) | UNIQUE NOT NULL | |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() | |

**State transitions:** `pending → in_progress → completed` | `pending → in_progress → failed`

---

## Relationships

```
identity.users (E01)
  └─< ops.alert_rules (created_by)
  └─< ops.backup_jobs (triggered_by)
  └─< ops.deployment_rollbacks (initiated_by)

ops.alert_rules
  └─< ops.alerts

ops.deployment_versions
  └─< ops.deployment_rollbacks (from_version_id)
  └─< ops.deployment_rollbacks (to_version_id)

ops.provider_health_checks → (references E25 provider registry by provider_code)
ops.provider_status → (references E25 provider registry by provider_code)
```

---

## External References

| Table / service | Purpose |
|---|---|
| E17 Notifications | Receives AlertFiredEvent / AlertResolvedEvent for delivery |
| E25 ProviderRegistryInterface | Source of provider list for health polling |
| E23 AuditLogger | Receives audit events for alert rule changes, backup ops, rollbacks |
| E28 ConfigurationService | Retention policies, backup schedules, alert thresholds |
