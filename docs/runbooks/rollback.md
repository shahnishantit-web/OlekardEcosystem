# Runbook: Rollback

## When to Execute

Execute this runbook immediately when:
- A deployment health check fails
- Post-deploy error rate exceeds 0.1%
- A critical bug is discovered in production within the post-deploy monitoring window
- On-call engineer judges the deployment unsafe to leave running

---

## Scripted Rollback

The rollback script re-deploys the previous stable tag.

```bash
bash scripts/ci/rollback.sh <previous-stable-tag>
```

**Example:**

```bash
bash scripts/ci/rollback.sh v1.4.2
```

The script will:
1. Pull the specified image tag
2. Restart services with the prior image
3. Run the health check
4. Report success or failure

If the script exits non-zero, proceed to **Manual Rollback** below.

---

## Manual Rollback Steps

### 1. Identify the Prior Stable Image

```bash
docker images <registry>/<image> --format "{{.Tag}}\t{{.CreatedAt}}" | sort -r | head -5
```

Note the tag immediately prior to the failed deployment.

### 2. Restart Services with Prior Image

```bash
IMAGE_TAG=<previous-stable-tag> docker compose up -d --no-deps api horizon
```

### 3. Verify Health

```bash
curl -sf http://<host>/health | jq .
```

### 4. Verify Queue Workers

```bash
docker compose exec api php artisan horizon:status
```

---

## Database Migration Rollback

> **Warning**: Laravel migrations are not automatically reversible. Evaluate each migration individually.

### Reversible Migrations

If the deployed migration has a `down()` method:

```bash
docker compose exec api php artisan migrate:rollback --step=1 --force
```

Verify the schema state:

```bash
docker compose exec api php artisan migrate:status
```

### Irreversible Migrations (Destructive Schema Changes)

If the migration is irreversible (e.g., column drop, data transformation):

1. **Do not run `migrate:rollback`** — this will cause data loss.
2. Engage DBA for point-in-time recovery from the pre-deploy backup.
3. Refer to `docs/runbooks/backup-recovery.md` for the recovery procedure.
4. Notify the incident channel immediately.

---

## Post-Rollback Verification

- [ ] Health check returns `{"status": "ok"}`
- [ ] Smoke tests pass on the rolled-back version
- [ ] Error rate returns below 0.1%
- [ ] Horizon workers processing queue jobs
- [ ] Deployment ticket updated with rollback action and timestamp
- [ ] Incident opened if rollback was triggered by a production incident (see `docs/runbooks/incident-response.md`)

---

## Contact

See `docs/runbooks/incident-response.md` for escalation contacts.
