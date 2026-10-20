# Deployment Guide

## Overview

This guide covers the OleKard platform's environment matrix, secret management approach, infrastructure requirements, and environment-specific configuration layering strategy.

For step-by-step deployment procedures, see `docs/runbooks/deployment.md`.

---

## Environment Matrix

| Environment | Target Audience | Docker Compose File | Database Strategy | Secret Source | Log Level | Health Check URL Pattern |
|-------------|----------------|---------------------|-------------------|---------------|-----------|--------------------------|
| **Development** | Local developers | `docker-compose.yml` + `docker-compose.override.yml` | Shared local PostgreSQL; `migrate:fresh --seed` on demand | `.env` file (gitignored) | `DEBUG` | `http://localhost:8000/health` |
| **Test** | CI pipeline | `docker-compose.ci.yml` | Isolated PostgreSQL per CI run; fresh migrations per test suite | CI environment variables (injected by CI system) | `WARNING` | `http://api:8000/health` (internal CI network) |
| **Staging** | QA team, stakeholders | `docker-compose.staging.yml` | Dedicated PostgreSQL instance; migrations applied on deploy | Secret management store *(placeholder: configure provider)* | `INFO` | `https://staging.api.<domain>/health` |
| **Production** | End users | `docker-compose.prod.yml` | Dedicated PostgreSQL instance (high-availability); migrations applied on deploy with DBA review | Secret management store *(placeholder: configure provider)* | `ERROR` | `https://api.<domain>/health` |

> **Note**: Replace `<domain>` and secret store references with actual values before production launch. Do not invent TBD values — leave placeholders explicit.

---

## Secret Management

### Principles

- Secrets are **never committed to source control** — not in `.env` files, not in Dockerfiles, not in CI config files.
- The `.env.example` files define all required keys with placeholder values. Actual values live in the environment's secret store.
- Application code reads secrets exclusively from environment variables.

### Required Secret Keys

See `services/api/.env.example` for the authoritative list of required environment variables with descriptions.

Key categories:

- Database credentials: `DB_*` per-schema connection variables
- Application key: `APP_KEY` (generated once per environment via `php artisan key:generate`)
- Redis: `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`
- Queue and cache: `QUEUE_CONNECTION`, `CACHE_DRIVER`

### Secret Rotation Procedure

1. Generate the new secret value in the secret management store.
2. Update the secret in the store (the old value remains until the rollout completes).
3. Trigger a rolling container restart to pick up the new value:

   ```bash
   docker compose up -d --no-deps api horizon
   ```

4. Verify the health check passes: `curl -sf http://<host>/health | jq .`
5. Remove the old secret value from the store after confirming all containers have restarted.

---

## Infrastructure Requirements

### Minimum Resource Requirements per Service

| Service | Container | Min CPU | Min RAM | Notes |
|---------|-----------|---------|---------|-------|
| API (Laravel) | `api` | 0.5 vCPU | 256 MB | Scale horizontally for load |
| Queue Worker (Horizon) | `horizon` | 0.5 vCPU | 256 MB | One worker per queue partition minimum |
| Admin (Next.js) | `admin` | 0.25 vCPU | 128 MB | Stateless; scale as needed |
| PostgreSQL | `postgres` | 1 vCPU | 512 MB | Production: use managed service or HA setup |
| Redis | `redis` | 0.25 vCPU | 128 MB | Production: enable persistence (AOF or RDB) |

### Database

- **Version**: PostgreSQL 15.x
- **Schema strategy**: One named schema per domain module (see `docs/adrs/003-schema-per-module.md`)
- **Financial columns**: `NUMERIC(19,4)` for all monetary amounts
- **No cross-schema JOINs** — modules communicate via contracts or events only

### Cache / Queue

- **Version**: Redis 7.x
- **Role**: Application cache and queue broker (via Laravel Horizon)
- **Financial source of truth**: PostgreSQL only — Redis is never authoritative for financial data

### Network Topology

- API container must be able to reach: PostgreSQL (all 9 schemas on same DB instance), Redis
- Admin container must be able to reach: API container
- API container must be reachable from the load balancer or reverse proxy on port 8000
- All inter-service communication on the internal Docker network (not exposed to the internet)
- Only the load balancer / reverse proxy exposes ports 80/443 externally

---

## Environment-Specific Configuration

### Layering Strategy

Configuration is resolved in this precedence order (highest wins):

```
1. Secrets (from secret management store / CI injected vars)
2. Environment-specific variables (e.g., staging.env, ci.env)
3. Application defaults (config/app.php, .env.example defaults)
```

No layer is permitted to contain secrets — secrets are injected at runtime from the store.

### Where Each Layer Lives

| Layer | Location | Committed to git? |
|-------|----------|------------------|
| Application defaults | `config/*.php`, `services/api/.env.example` | Yes (no real values) |
| Environment-specific | `docker-compose.<env>.yml` environment sections | Yes (no secrets) |
| Secrets | Secret management store / CI secrets | No |

### Flutter Mobile Apps

Environment configuration for mobile apps flows through `--dart-define` flags at build time. See `wallet-ecosystem/DEVELOPMENT.md` for the full list of `--dart-define` keys and their defaults.

Build-time flags must be supplied by the CI/CD pipeline for staging and production builds — never hardcoded in source code.
