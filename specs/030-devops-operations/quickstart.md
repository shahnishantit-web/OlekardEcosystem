# Quickstart Validation Guide — E30 DevOps & Operations

## Prerequisites

- Docker Compose stack running (`docker compose up -d` from `wallet-ecosystem/`)
- Admin user seeded: `admin@olekard.local` / `Admin123!` with permissions `ops.view_logs`, `ops.view_metrics`, `ops.view_health`, `ops.manage_alerts`, `ops.manage_backup`, `ops.manage_deployments`
- API base URL: `http://localhost:8000/api`

Obtain an admin token:
```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' \
  | jq -r '.data.access_token')
```

---

## SC-01: Health probe (US-OPS-003)

```bash
curl -s http://localhost:8000/api/v1/ops/health | jq .
```

**Expected**:
```json
{
  "success": true,
  "data": {
    "overall_status": "healthy",
    "components": [
      { "name": "database", "status": "healthy", "latency_ms": ... },
      { "name": "cache",    "status": "healthy", "latency_ms": ... },
      { "name": "queue",    "status": "healthy", "latency_ms": ... }
    ],
    "checked_at": "..."
  }
}
```

Status must be `200` and `overall_status` must be `healthy` when all services are running.

---

## SC-02: Readiness probe (US-OPS-004)

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/api/v1/ops/health/ready
```

**Expected**: `200`

To simulate not-ready: stop the database container, retry — expect `503`.

---

## SC-03: Liveness probe (US-OPS-005)

```bash
curl -s http://localhost:8000/api/v1/ops/health/live | jq .data.status
```

**Expected**: `"live"`

Must complete in under 2 seconds and must not fail when database is stopped.

---

## SC-04: Log search by correlation ID (US-OPS-001)

Make any API call that produces log entries:
```bash
CORR=$(uuidgen)
curl -s http://localhost:8000/api/v1/ops/health \
  -H "X-Correlation-ID: $CORR" > /dev/null

sleep 2  # allow log persistence
```

Search for entries with that correlation ID:
```bash
curl -s "http://localhost:8000/api/v1/admin/ops/logs?correlation_id=$CORR" \
  -H "Authorization: Bearer $TOKEN" | jq '.data | length'
```

**Expected**: at least `1` log entry.

Verify no secrets in any entry:
```bash
curl -s "http://localhost:8000/api/v1/admin/ops/logs?correlation_id=$CORR" \
  -H "Authorization: Bearer $TOKEN" \
  | jq '.data[].context | to_entries[] | select(.key | test("password|pin|secret|token"; "i"))'
```

**Expected**: empty output (no sensitive keys in `context`).

---

## SC-05: Metrics query (US-OPS-002)

```bash
curl -s "http://localhost:8000/api/v1/admin/ops/metrics?service_name=api&granularity=minute" \
  -H "Authorization: Bearer $TOKEN" | jq '.data | length'
```

**Expected**: at least `1` data point with `metric_name`, `value`, `unit`, and `recorded_at`.

---

## SC-06: Payment provider monitoring (US-OPS-008)

```bash
curl -s http://localhost:8000/api/v1/admin/ops/providers \
  -H "Authorization: Bearer $TOKEN" | jq '.data'
```

**Expected**: array of provider status entries. Each entry has `provider_code`, `current_status` (one of `up/degraded/down`), `avg_response_time_ms`, `error_rate_5m`, and `last_checked_at`.

---

## SC-07: Alert rule lifecycle (US-OPS-009)

**Create a rule:**
```bash
IK=$(uuidgen)
RULE=$(curl -s -X POST http://localhost:8000/api/v1/admin/ops/alert-rules \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IK" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test CPU Rule",
    "metric_condition": {
      "metric": "api.error_rate",
      "operator": "gt",
      "threshold": 0.5,
      "duration_seconds": 60
    },
    "severity": "warning",
    "notification_channels": [{"type":"email","config":{"address":"ops@olekard.local"}}]
  }')
echo $RULE | jq .data.id
RULE_ID=$(echo $RULE | jq -r .data.id)
```

**Expected**: HTTP 201, `data.severity = "warning"`, `data.is_enabled = true`.

**Disable the rule:**
```bash
curl -s -X PATCH "http://localhost:8000/api/v1/admin/ops/alert-rules/$RULE_ID" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"is_enabled": false}' | jq .data.is_enabled
```

**Expected**: `false`.

**Delete the rule:**
```bash
curl -s -X DELETE "http://localhost:8000/api/v1/admin/ops/alert-rules/$RULE_ID" \
  -H "Authorization: Bearer $TOKEN" | jq .success
```

**Expected**: `true`.

---

## SC-08: Manual backup (US-OPS-010)

```bash
IK=$(uuidgen)
JOB=$(curl -s -X POST http://localhost:8000/api/v1/admin/ops/backup-jobs \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IK" \
  -H "Content-Type: application/json" \
  -d '{"backup_type":"full"}')
echo $JOB | jq '{id: .data.id, status: .data.status}'
```

**Expected**: HTTP 201, `data.status = "pending"` or `"running"`.

Wait for completion (poll or check after a delay):
```bash
JOB_ID=$(echo $JOB | jq -r .data.id)
# Refresh by listing and checking status
curl -s "http://localhost:8000/api/v1/admin/ops/backup-jobs?status=completed" \
  -H "Authorization: Bearer $TOKEN" | jq ".data[] | select(.id == \"$JOB_ID\") | .status"
```

**Expected**: `"completed"`.

**Idempotency replay (same key):**
```bash
REPLAY=$(curl -s -X POST http://localhost:8000/api/v1/admin/ops/backup-jobs \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IK" \
  -H "Content-Type: application/json" \
  -d '{"backup_type":"full"}')
echo $REPLAY | jq .data.id
```

**Expected**: same job ID as first request.

---

## SC-09: Deployment version tracking and rollback (US-OPS-012)

List deployment history:
```bash
curl -s http://localhost:8000/api/v1/admin/ops/deployments \
  -H "Authorization: Bearer $TOKEN" | jq '.data | length'
```

**Expected**: at least `1` entry (the seeded current deployment).

Initiate a rollback to the previous version (requires at least 2 versions):
```bash
PREV_ID=$(curl -s http://localhost:8000/api/v1/admin/ops/deployments \
  -H "Authorization: Bearer $TOKEN" \
  | jq -r '.data | sort_by(.deployed_at) | reverse | .[1].id')

IK=$(uuidgen)
curl -s -X POST "http://localhost:8000/api/v1/admin/ops/deployments/$PREV_ID/rollback" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $IK" | jq '{status: .data.status, to_version: .data.to_version_id}'
```

**Expected**: HTTP 201, `data.status = "pending"` or `"in_progress"`.

---

## SC-10: Authorization checks

Verify unauthorized access is rejected:
```bash
# No token → 401
curl -s -o /dev/null -w "%{http_code}" \
  http://localhost:8000/api/v1/admin/ops/logs

# Valid customer token without ops.manage → 403
CUST_TOKEN=<customer_jwt>
curl -s -o /dev/null -w "%{http_code}" \
  http://localhost:8000/api/v1/admin/ops/logs \
  -H "Authorization: Bearer $CUST_TOKEN"
```

**Expected**: `401` for no token, `403` for wrong role.

---

## Validation Summary

| SC | Story | Validates |
|----|-------|-----------|
| SC-01 | US-OPS-003 | Aggregate health check (healthy + component breakdown) |
| SC-02 | US-OPS-004 | Readiness probe (200 when ready, 503 when not) |
| SC-03 | US-OPS-005 | Liveness probe (completes independently of external deps) |
| SC-04 | US-OPS-001 | Log search by correlation ID; no secrets in context |
| SC-05 | US-OPS-002 | Metrics query returns data points |
| SC-06 | US-OPS-008 | Provider status list with current health |
| SC-07 | US-OPS-009 | Alert rule create, update, delete; audit-logged |
| SC-08 | US-OPS-010 | Manual backup trigger; idempotency replay |
| SC-09 | US-OPS-012 | Deployment version list; rollback initiation |
| SC-10 | All | Authorization enforcement (401/403) |
