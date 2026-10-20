# Runbook: Incident Response

## Severity Definitions

| Level | Definition | Response Time | Examples |
|-------|------------|---------------|---------|
| **P1** | Complete platform outage or financial data integrity failure | Immediate (< 15 min) | All APIs returning 5xx, ledger corruption detected, auth broken |
| **P2** | Significant degradation affecting a major feature or user group | < 1 hour | Payment processing unavailable, wallet reads failing, >5% error rate |
| **P3** | Minor degradation or non-critical feature impacted | < 4 hours | Slow response on non-critical endpoints, cosmetic UI errors |

---

## On-Call Escalation Path

1. **Primary On-Call Engineer** — check the current on-call rotation in the team schedule *(placeholder: configure in incident management tool)*
2. **Secondary / Backup Engineer** — *(placeholder: configure backup rotation)*
3. **Engineering Lead** — escalate if P1 is not resolved within 30 minutes
4. **Head of Engineering / CTO** — escalate if P1 financial integrity is at risk

> **Note**: Replace the placeholder contacts above with real names and contact details from your incident management system before going to production.

---

## Initial Triage Steps

### 1. Determine Severity

Use the severity table above. When in doubt, start at P2 and adjust.

### 2. Open an Incident

- Create an incident ticket in the issue tracker
- Post to the engineering incident channel: `#incidents` *(placeholder: configure channel)*
- Assign an Incident Commander (IC)

### 3. Gather Correlation IDs

Every request logs a `correlation_id`. Use it to trace the full request lifecycle:

```bash
# Search API logs for a specific correlation ID
docker compose exec api grep '"correlation_id":"<UUID>"' /var/log/api/app.log | jq .
```

```bash
# Search for recent ERROR-level entries
docker compose exec api grep '"level":"ERROR"' /var/log/api/app.log | tail -50 | jq .
```

### 4. Check System Health

```bash
# API health
curl -sf http://<host>/health | jq .

# Queue / Horizon status
docker compose exec api php artisan horizon:status

# Container status
docker compose ps
```

### 5. Financial Operation Freeze (P1 Only)

If financial data integrity is at risk or a double-spend is suspected:

1. **Disable write endpoints immediately** — update load balancer rules to return HTTP 503 for POST/PATCH routes
2. **Do not modify any ledger entries** — treat all ledger data as immutable evidence
3. **Engage DBA** before any database intervention
4. **Notify compliance / legal** per regulatory obligations *(placeholder: confirm notification requirement with legal team)*

---

## Communication

| Audience | Channel | Frequency |
|----------|---------|-----------|
| Engineering team | `#incidents` | Every 15 min update during P1 |
| Stakeholders | Email / Slack `#status` | At incident open, resolution, and post-mortem scheduled |
| Customers (if user-visible) | Status page | At incident open and resolution |

---

## Resolution Checklist

- [ ] Root cause identified
- [ ] Fix deployed or rollback completed (see `docs/runbooks/rollback.md`)
- [ ] Health checks passing
- [ ] Error rate below 0.1%
- [ ] Financial data integrity verified (for financial incidents)
- [ ] Incident ticket updated with timeline and resolution
- [ ] Post-mortem scheduled within 48 hours of P1 resolution

---

## Post-Mortem

Use the blameless post-mortem format:

1. **Timeline** — sequence of events with timestamps
2. **Root Cause** — what caused the incident
3. **Contributing Factors** — system or process gaps
4. **Impact** — duration, user/financial impact
5. **Action Items** — concrete tasks with owners and due dates

Post-mortem document stored in: `docs/post-mortems/YYYY-MM-DD-<title>.md` *(create directory as needed)*
