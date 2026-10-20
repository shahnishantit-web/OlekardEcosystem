# Development Guide

## Prerequisites

| Tool | Minimum Version | Install |
|---|---|---|
| Docker Desktop | 4.x | https://docker.com |
| PHP | 8.4+ | Via Docker (recommended) |
| Composer | 2.x | https://getcomposer.org |
| Node.js | 20.x LTS | https://nodejs.org |
| npm | 10.x | Bundled with Node.js |
| Flutter | 3.x | https://flutter.dev (for mobile) |
| git | 2.x | https://git-scm.com |

## Quick Start (≤ 30 minutes)

```bash
# 1. Clone
git clone <repo-url> wallet-ecosystem
cd wallet-ecosystem

# 2. Environment
cp .env.example .env
# Edit .env: set DB_PASSWORD and any other required values

cp services/api/.env.example services/api/.env
php artisan key:generate   # or let Docker do it on first start

# 3. Start all services
docker compose up -d --wait

# 4. Run migrations
docker compose exec api php artisan migrate

# 5. Verify
curl http://localhost:8080/health
# Expected: {"status":"ok"}
```

## Development Workflow

### Backend (Laravel)

```bash
# Run tests
docker compose exec api php artisan test

# Run Pest specifically
docker compose exec api ./vendor/bin/pest

# Static analysis
docker compose exec api ./vendor/bin/phpstan analyse

# Code style
docker compose exec api ./vendor/bin/pint

# Run artisan commands
docker compose exec api php artisan <command>
```

### Admin (Next.js)

The local override mounts source and runs `npm run dev`:

```bash
# Access at http://localhost:3000
docker compose up admin
```

### Mobile Apps (Flutter)

Flutter apps use `--dart-define` for environment configuration. Never hardcode URLs or credentials.

**Available `--dart-define` flags:**

| Flag | Default | Description |
|------|---------|-------------|
| `APP_ENV` | `development` | Environment: `development`, `test`, or `production` |
| `API_BASE_URL` | `http://localhost:8080` | Backend API base URL |
| `API_CONNECT_TIMEOUT_MS` | `5000` | Connection timeout in milliseconds |
| `API_RECEIVE_TIMEOUT_MS` | `10000` | Receive timeout in milliseconds |

**Running locally (Customer app):**

```bash
cd apps/customer
flutter run \
  --dart-define=APP_ENV=development \
  --dart-define=API_BASE_URL=http://localhost:8080
```

**Running locally (Agent app):**

```bash
cd apps/agent
flutter run \
  --dart-define=APP_ENV=development \
  --dart-define=API_BASE_URL=http://localhost:8080
```

**Building for production:**

```bash
flutter build apk \
  --dart-define=APP_ENV=production \
  --dart-define=API_BASE_URL=https://api.example.com
```

Do not commit `.env` files for Flutter — all config flows through `--dart-define` at build time.

### Database

```bash
# Connect directly (when postgres port is exposed via override)
psql -h localhost -U wallet_app -d wallet_ecosystem

# Run migrations
docker compose exec api php artisan migrate

# Fresh migration with seeds
docker compose exec api php artisan migrate:fresh --seed
```

## Architecture

See [specs/999-platform-foundation/spec.md](../specs/999-platform-foundation/spec.md) for the full architecture specification.

Key rules:
- **No cross-schema SQL JOINs** — modules communicate via contracts or events
- **UUID v7** for all primary keys
- **Idempotency-Key required** on all state-changing financial endpoints
- **All money** stored as `NUMERIC(19,4)` in DB; integer in memory

## Module Structure

```
services/api/app/Modules/
├── Platform/        # Cross-cutting (fully implemented here)
├── Identity/        # Stub → E01–E03
├── Wallet/          # Stub → E06
├── Ledger/          # Stub → E07
├── Kyc/             # Stub → E05
├── Transaction/     # Stub → E11
├── Risk/            # Stub
├── Agent/           # Stub
└── Notification/    # Stub
```

## Environment Variables

See `.env.example` and `services/api/.env.example` for all variables with documentation.
TBD values must remain blank — do not invent values (constitution Principle 2).
