# Quickstart Validation Guide — E24 Reporting & Analytics

## Prerequisites

- Docker environment running: `docker compose up -d` from `wallet-ecosystem/`
- API service healthy: `curl -s http://localhost:8080/health | jq .status`
- Admin user seeded: `admin@olekard.local` / `Admin123!`
- Required permissions seeded: `admin.read`, `finance.reports`, `compliance.reports`, `reports.schedule`, `compliance.pii.read`
- E24 migrations run: `docker compose exec api php artisan migrate`
- E24 report definitions seeded: `docker compose exec api php artisan db:seed --class=ReportDefinitionSeeder`

Set a shell variable for convenience:
```bash
BASE=http://localhost:8080/v1
TOKEN=$(curl -s -X POST $BASE/auth/login -H 'Content-Type: application/json' \
  -d '{"identifier":"admin@olekard.local","password":"Admin123!"}' | jq -r '.data.access_token')
```

---

## SC1 — Report Catalogue Returns 10 Active Report Types

```bash
curl -s -H "Authorization: Bearer $TOKEN" $BASE/reports/catalogue | jq '.data | length'
# Expected: 10
curl -s -H "Authorization: Bearer $TOKEN" $BASE/reports/catalogue | jq '[.data[].type]'
# Expected: includes "user-report", "wallet-report", "transaction-report",
#   "agent-report", "commission-report", "kyc-report", "reconciliation-report",
#   "settlement-report", "risk-report", "financial-summary"
```

---

## SC2 — Submit a Transaction Report Job and Poll to Completion

```bash
JOB=$(curl -s -X POST $BASE/reports/jobs \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "report_type": "transaction-report",
    "filters": {
      "date_range_start": "2026-01-01",
      "date_range_end": "2026-12-31",
      "status": "completed"
    }
  }')
echo $JOB | jq '.data.status'
# Expected: "requested"

JOB_ID=$(echo $JOB | jq -r '.data.id')

# Poll until complete (should complete within 30s for an empty/small dataset)
for i in $(seq 1 30); do
  STATUS=$(curl -s -H "Authorization: Bearer $TOKEN" $BASE/reports/jobs/$JOB_ID | jq -r '.data.status')
  echo "Status: $STATUS"
  [ "$STATUS" = "complete" ] && break || sleep 2
done
# Expected final status: "complete"

# Verify as-of timestamp is set
curl -s -H "Authorization: Bearer $TOKEN" $BASE/reports/jobs/$JOB_ID | jq '.data.as_of_at'
# Expected: non-null ISO 8601 timestamp
```

---

## SC3 — Export a Completed Job to CSV

```bash
EXPORT=$(curl -s -X POST $BASE/reports/jobs/$JOB_ID/exports \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"format": "csv"}')
echo $EXPORT | jq '.data.status'
# Expected: "pending"

EXPORT_ID=$(echo $EXPORT | jq -r '.data.id')

# Poll until ready
for i in $(seq 1 30); do
  STATUS=$(curl -s -H "Authorization: Bearer $TOKEN" $BASE/reports/exports/$EXPORT_ID | jq -r '.data.status')
  echo "Status: $STATUS"
  [ "$STATUS" = "ready" ] && break || sleep 2
done
# Expected: "ready"

# Get download URL
DOWNLOAD_URL=$(curl -s -H "Authorization: Bearer $TOKEN" $BASE/reports/exports/$EXPORT_ID | jq -r '.data.download_url')
echo "Download URL: $DOWNLOAD_URL"
# Expected: non-null URL

# Verify download returns a valid CSV
curl -s "$DOWNLOAD_URL" | head -1
# Expected: CSV header row with column names
```

---

## SC4 — Per-User Concurrent Job Limit (3 jobs max)

```bash
# Submit 3 jobs quickly
for i in 1 2 3; do
  curl -s -X POST $BASE/reports/jobs \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"report_type":"wallet-report","filters":{"date_range_start":"2026-01-01","date_range_end":"2026-12-31"}}' \
    | jq '.data.status'
done
# Expected: all three return "requested"

# 4th submission must be rejected
RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" -X POST $BASE/reports/jobs \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"report_type":"wallet-report","filters":{"date_range_start":"2026-01-01","date_range_end":"2026-12-31"}}')
echo "HTTP status: $RESPONSE"
# Expected: 429
```

---

## SC5 — Authorization Enforcement

```bash
# Obtain a token for a user WITHOUT finance.reports permission
BASIC_TOKEN=$(curl -s -X POST $BASE/auth/login -H 'Content-Type: application/json' \
  -d '{"identifier":"customer@example.com","password":"Password1!"}' | jq -r '.data.access_token')

RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" -X POST $BASE/reports/jobs \
  -H "Authorization: Bearer $BASIC_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"report_type":"reconciliation-report","filters":{"date_range_start":"2026-01-01","date_range_end":"2026-12-31"}}')
echo "HTTP status: $RESPONSE"
# Expected: 403
```

---

## SC6 — Financial Summary Balance Validation

```bash
FS_JOB=$(curl -s -X POST $BASE/reports/jobs \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"report_type":"financial-summary","filters":{"date_range_start":"2026-01-01","date_range_end":"2026-12-31"}}')
FS_JOB_ID=$(echo $FS_JOB | jq -r '.data.id')

for i in $(seq 1 30); do
  STATUS=$(curl -s -H "Authorization: Bearer $TOKEN" $BASE/reports/jobs/$FS_JOB_ID | jq -r '.data.status')
  [ "$STATUS" = "complete" ] && break || sleep 2
done

# Verify balance validation flag on result_data
curl -s -H "Authorization: Bearer $TOKEN" $BASE/reports/jobs/$FS_JOB_ID \
  | jq '.data.result_data.balance_validates'
# Expected: true

# Inspect by-currency breakdown
curl -s -H "Authorization: Bearer $TOKEN" $BASE/reports/jobs/$FS_JOB_ID \
  | jq '.data.result_data.currencies'
# Expected: array of objects with {currency, total_inflow, total_outflow, total_fees, net_position}
```

---

## SC7 — Create and Trigger a Report Schedule

```bash
SCHED=$(curl -s -X POST $BASE/reports/schedules \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "report_type": "agent-report",
    "name": "Daily Agent Report",
    "filters": {"date_range_start": "RELATIVE:-1d", "date_range_end": "RELATIVE:today"},
    "cadence": "daily",
    "cadence_time": "06:00",
    "email_notification_enabled": false,
    "recipients": []
  }')
echo $SCHED | jq '.data.status'
# Expected: "active"

SCHED_ID=$(echo $SCHED | jq -r '.data.id')

# Manually trigger via Artisan (simulates scheduler tick)
docker compose exec api php artisan reports:process-schedules --dry-run
# Expected: output lists the schedule as "would trigger"

# Deactivate
curl -s -o /dev/null -w "%{http_code}" -X DELETE $BASE/reports/schedules/$SCHED_ID \
  -H "Authorization: Bearer $TOKEN"
# Expected: 204

curl -s -H "Authorization: Bearer $TOKEN" $BASE/reports/schedules/$SCHED_ID | jq '.data.status'
# Expected: "inactive"
```

---

## SC8 — Audit Events Emitted

After completing SC2 (job submission) and SC3 (export generation):

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "$BASE/audit/events?event_category=report&per_page=10" \
  | jq '[.data[].event_type]'
# Expected: includes "REPORT_JOB_REQUESTED", "REPORT_JOB_COMPLETED", "REPORT_EXPORT_GENERATED"
```

---

## SC9 — Export Link Expiry Returns 410

```bash
# (Assumes an export was generated in SC3; manually expire the token in the DB for testing)
docker compose exec api php artisan tinker --execute="
  \$export = \App\Modules\Reporting\Infrastructure\Models\ReportExportModel::find('$EXPORT_ID');
  \$export->token_expires_at = now()->subHour();
  \$export->save();
"

# Attempt to use the old download URL — should fail
curl -s -o /dev/null -w "%{http_code}" "$DOWNLOAD_URL"
# Expected: 410 or 403 (expired pre-signed URL)

# Regenerate link
NEW_EXPORT=$(curl -s -X POST $BASE/reports/exports/$EXPORT_ID/regenerate-link \
  -H "Authorization: Bearer $TOKEN")
echo $NEW_EXPORT | jq '.data.download_url'
# Expected: new non-null URL with a future expiry
```

---

## Artisan Commands Reference

| Command | Purpose |
|---|---|
| `reports:process-schedules` | Process due report schedules (run every minute in production) |
| `reports:process-schedules --dry-run` | List schedules that would be triggered without dispatching |
| `reports:purge-exports` | Purge export files past their file retention expiry |
| `db:seed --class=ReportDefinitionSeeder` | Seed the 10 report type definitions |
