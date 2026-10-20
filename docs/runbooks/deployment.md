# Runbook: Deployment

## Scope

Step-by-step procedure for promoting the OleKard platform through environments: `development → test → staging → production`.

---

## Environment Promotion Path

```
development  →  test  →  staging  →  production
```

Each promotion requires the prior environment's CI to be green and a tagged release.

---

## Pre-Deploy Checklist

Complete every item before starting the deployment:

- [ ] CI pipeline for the release branch is **green** (all tests pass, coverage thresholds met)
- [ ] Migration dry-run executed: `php artisan migrate --pretend` — no destructive or irreversible changes without DBA sign-off
- [ ] Rollback plan documented in the deployment ticket (prior stable tag identified)
- [ ] `docs/runbooks/rollback.md` reviewed by on-call engineer
- [ ] Secret values verified in target environment's secret store (no TBD or placeholder values)
- [ ] Database backup confirmed for staging/production targets (see `docs/runbooks/backup-recovery.md`)
- [ ] Stakeholder notification sent for staging/production (if user-visible change)

---

## Deployment Steps

### 1. Tag the Release

```bash
git tag v<MAJOR>.<MINOR>.<PATCH>
git push origin v<MAJOR>.<MINOR>.<PATCH>
```

### 2. Pull the New Image

On the target host or CI runner:

```bash
docker pull <registry>/<image>:<tag>
```

Replace `<registry>`, `<image>`, and `<tag>` with values from the environment's infrastructure configuration.

### 3. Apply Database Migrations

```bash
docker compose exec api php artisan migrate --force
```

`--force` is required in non-local environments. Verify output shows no errors.

### 4. Restart Application Services

```bash
docker compose up -d --no-deps api horizon
```

Wait for containers to reach `healthy` state:

```bash
docker compose ps
```

All services must show `(healthy)` before proceeding.

### 5. Health Check

```bash
curl -sf http://<host>/health | jq .
```

Expected response:

```json
{
  "status": "ok",
  "timestamp": "<UTC ISO-8601>"
}
```

If health check fails, immediately execute `docs/runbooks/rollback.md`.

### 6. Smoke Test

Run the environment's smoke test suite:

```bash
# From project root
bash scripts/ci/backend.sh smoke --env <environment>
```

Verify critical paths:
- Authentication flow
- Health endpoint
- Configuration read endpoint

### 7. Post-Deploy Verification

- [ ] Monitor logs for `ERROR` / `CRITICAL` entries for 10 minutes post-deploy
- [ ] Verify Horizon queue workers are processing jobs (check Horizon dashboard)
- [ ] Confirm outbox processor is running (check scheduler logs)
- [ ] Update deployment ticket with deployed tag and timestamp

---

## Rollback

If any step fails, execute `docs/runbooks/rollback.md` immediately. Do not attempt to fix forward during an active incident.

---

## Contact

See `docs/runbooks/incident-response.md` for escalation contacts.
