# Runbook: Backup and Recovery

## Overview

This runbook covers PostgreSQL and Redis backup procedures for the OleKard platform, and the steps to restore from a backup.

> **Financial integrity constraint**: PostgreSQL is the authoritative source for all financial data. Redis is a cache — never use Redis state as the source of truth for recovery.

---

## PostgreSQL Backup

### Manual Volume Backup

```bash
# Create a timestamped dump of the entire database
docker compose exec postgres pg_dumpall \
  -U wallet_app \
  > backups/postgres_$(date +%Y%m%d_%H%M%S).sql
```

Store the output file in a durable, off-host location (e.g., object storage). Verify the file is non-empty before considering the backup complete.

### Automated Backups

Configure your hosting provider or a dedicated backup agent to run `pg_dump` or `pg_dumpall` on a schedule appropriate for your RPO.

> **Placeholder**: Configure backup schedule, retention policy, and off-site storage location in your infrastructure configuration before production launch.

### Verify a Backup

```bash
# Inspect dump file headers to confirm it is valid
head -5 backups/postgres_<timestamp>.sql
```

Expected first line: `-- PostgreSQL database cluster dump`

---

## PostgreSQL Point-in-Time Recovery (PITR)

> PITR requires Write-Ahead Log (WAL) archiving to be enabled. Configure WAL archiving in PostgreSQL settings and your hosting provider before production.

### Steps

1. Stop the application to prevent writes during recovery:

   ```bash
   docker compose stop api horizon
   ```

2. Restore the base backup to a new volume (or recovery target path).

3. Configure `recovery.conf` (PostgreSQL 11 and earlier) or `postgresql.conf` recovery settings (PostgreSQL 12+) with the target recovery time:

   ```
   restore_command = 'cp /wal_archive/%f %p'
   recovery_target_time = '<YYYY-MM-DD HH:MM:SS UTC>'
   ```

4. Start PostgreSQL and monitor recovery progress in the PostgreSQL logs.

5. Once recovery is complete and PostgreSQL enters standby or normal mode, run a data integrity check:

   ```bash
   docker compose exec postgres psql -U wallet_app -c "SELECT COUNT(*) FROM platform.audit_log;"
   ```

6. Restart application services and verify health:

   ```bash
   docker compose up -d api horizon
   curl -sf http://<host>/health | jq .
   ```

---

## Redis Backup (RDB Snapshot)

Redis is a cache. Its loss does not cause data loss — the application will rebuild cache state from PostgreSQL.

### Manual RDB Snapshot

```bash
# Trigger an RDB save
docker compose exec redis redis-cli SAVE

# Copy the dump.rdb file
docker compose cp redis:/data/dump.rdb backups/redis_$(date +%Y%m%d_%H%M%S).rdb
```

### Redis Recovery

If Redis data is lost or corrupted:

1. Stop Redis: `docker compose stop redis`
2. Copy backup RDB into the Redis data volume: `docker compose cp backups/redis_<timestamp>.rdb redis:/data/dump.rdb`
3. Start Redis: `docker compose start redis`
4. Verify: `docker compose exec redis redis-cli PING` → `PONG`

Alternatively, simply restart Redis with an empty dataset — the application will reload idempotency records and other cache data from PostgreSQL on next use.

---

## Verification Steps After Recovery

- [ ] `curl -sf http://<host>/health | jq .` → `{"status":"ok"}`
- [ ] Run a sample financial query: `SELECT id, balance FROM wallet.wallets LIMIT 5;`
- [ ] Verify audit log is intact: `SELECT COUNT(*) FROM platform.audit_log;`
- [ ] Check Horizon queue workers are processing jobs
- [ ] Verify no ERROR-level log entries in the first 5 minutes post-recovery
- [ ] Confirm with DBA that schema migrations are at the expected version: `php artisan migrate:status`
- [ ] Update incident ticket with recovery timestamp and verification results
