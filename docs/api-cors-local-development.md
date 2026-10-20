# API CORS and local dependencies

The API allows the local admin origins on ports 3000 and 3001 through
`services/api/config/cors.php`. Set `CORS_ALLOWED_ORIGINS` to a comma-separated
list when using other origins. Include the scheme and port, without a trailing
slash. The policy applies to `/v1/*` and the configured health endpoints.

Preflight requests must not require Redis, PostgreSQL, authentication, or funding
configuration. Funding configuration is checked by `FundingCreditService` before
ledger posting, rather than during application boot. Exception responses also
use the framework CORS policy so the browser can read their error envelopes.

## Running PHP on Windows

`redis` and `postgres` are Docker network hostnames. They cannot be used by a
Windows `php artisan serve` process unless separately configured in local DNS.
The repository's Docker Compose development overrides publish PostgreSQL on
5433 and Redis on 6380. Start Docker Desktop, then from `wallet-ecosystem` run:

```powershell
docker compose up -d postgres redis
```

For a Windows PHP server using those containers, set the following in
`wallet-ecosystem/services/api/.env` (preserving the database credentials):

```dotenv
DB_HOST=127.0.0.1
DB_PORT=5433
REDIS_HOST=127.0.0.1
REDIS_PORT=6380
```

Run `php artisan config:clear` in `services/api` and restart the PHP server.
When PHP itself runs inside Compose, retain `DB_HOST=postgres`, `DB_PORT=5432`,
`REDIS_HOST=redis`, and `REDIS_PORT=6379`.

## Verification

```powershell
curl.exe -i -X OPTIONS http://127.0.0.1:8000/v1/auth/login -H "Origin: http://localhost:3000" -H "Access-Control-Request-Method: POST" -H "Access-Control-Request-Headers: content-type,authorization,x-correlation-id,idempotency-key"
```

Expect HTTP 204 and `Access-Control-Allow-Origin: http://localhost:3000`.
Run `php vendor/bin/phpunit tests/Feature/ApiCorsTest.php --no-coverage` in
`services/api` for regression coverage. A backend 500 with correct CORS headers
is a separate service/configuration failure; inspect the PHP server's stderr.
