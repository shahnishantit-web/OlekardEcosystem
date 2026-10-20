# Disaster Recovery Runbook

**Version:** 1.0  
**Last Updated:** 2026-10-04  
**RTO:** 4 hours  
**RPO:** 60 minutes  

---

## 1. Overview

This runbook covers recovery procedures for the Wallet Ecosystem platform. It is triggered when a major incident causes service unavailability that cannot be resolved by normal incident response.

Activate this runbook when:
- Primary database is unrecoverable
- Primary data centre is unavailable
- Platform is experiencing data corruption

---

## 2. Contacts

| Role | Contact | Escalation |
|------|---------|------------|
| On-Call Engineer | PagerDuty rotation | Immediate |
| Platform Lead | Escalate after 30 min | VP Engineering |
| DBA | Escalate after 30 min | Platform Lead |

---

## 3. Recovery Phases

### Phase 1: Declare Incident (0–15 min)

1. Page on-call engineer via PagerDuty
2. Declare P0 incident in incident management system
3. Notify leadership and stakeholders
4. Set status page to "Major Outage"
5. Open a war-room bridge

### Phase 2: Assess Damage (15–30 min)

1. Check health endpoint: `GET /health`
2. Check readiness endpoint: `GET /health/ready`
3. Review database connectivity and replication lag
4. Review queue health via Horizon dashboard
5. Review recent error rates via ops metrics dashboard

### Phase 3: Database Recovery (30–120 min)

**Option A — Promote read replica:**

```bash
# On replica host
pg_ctl promote -D /var/lib/postgresql/data

# Update API connection string in environment
APP_DB_PRIMARY=<new-primary-host>

# Restart API services
docker compose restart api
```

**Option B — Restore from backup:**

```bash
# List available backups
ls -la /var/backups/platform/

# Restore to point-in-time
pg_restore --host=<new-host> --dbname=platform --jobs=4 /var/backups/platform/latest.dump

# Apply WAL logs to target RPO
pg_waldump /var/lib/postgresql/pg_wal/ | grep "LSN < <target-lsn>"
```

### Phase 4: Verify Data Integrity (120–180 min)

1. Run schema verification queries against all module schemas
2. Verify ledger double-entry balances with reconciliation service
3. Spot-check recent transactions (last 1,000)
4. Verify idempotency table completeness
5. Check outbox event backlog — replay any unprocessed events

### Phase 5: Restore Services (180–240 min)

1. Start API service in maintenance mode
2. Run database migrations if any pending
3. Warm Redis caches
4. Start Horizon workers
5. Enable health/ready probes
6. Remove maintenance mode
7. Verify end-to-end via quickstart smoke tests

### Phase 6: Post-Recovery (240+ min)

1. Update status page to "Resolved"
2. Send stakeholder update
3. Record DR test result via: `POST /v1/admin/ops/dr/tests`
4. Schedule post-mortem within 24 hours
5. Update this runbook with lessons learned

---

## 4. Key Configuration

| Key | Description | Default |
|-----|-------------|---------|
| `ops.dr.rto_hours` | Recovery Time Objective | 4 |
| `ops.dr.rpo_minutes` | Recovery Point Objective | 60 |
| `ops.dr.runbook_version` | Current runbook version | 1.0 |
| `ops.dr.last_dr_test_at` | Timestamp of last DR test | null |

---

## 5. Regular DR Testing

DR tests must be conducted quarterly. After each test:

1. Navigate to Admin > Operations > DR Status
2. Click "Record DR Test"
3. Enter outcome (pass/fail/partial) and notes
4. The system records `ops.dr.last_dr_test_at` automatically

Test criteria:
- Full recovery within RTO
- Data loss within RPO tolerance
- All acceptance criteria from E30 US-OPS-011 AC4 met

---

## 6. Rollback Procedure

If recovery worsens the situation:

1. Halt all service restarts immediately
2. Revert to last known good database snapshot
3. Engage DBA and Platform Lead
4. Use deployment rollback: `POST /v1/admin/ops/deployments/rollback`
