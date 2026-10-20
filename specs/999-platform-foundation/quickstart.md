# Quickstart Validation Guide: Platform Foundation

**Branch**: `999-platform-foundation` | **Date**: 2026-08-16

This guide validates that all Platform Foundation components are working correctly. Use this after implementation to confirm the foundation is ready for E01–E34 development.

---

## Prerequisites

- Docker Desktop installed and running
- PHP 8.4+ with Composer (for local CLI tasks outside Docker)
- Node.js 20+ and npm/pnpm (for Next.js)
- Flutter 3.x+ (stable channel)
- Git

---

## 1. Developer Onboarding Validation (SC-001)

**Target**: New developer completes local setup in under 30 minutes.

```bash
# Clone and start
git clone <repo-url> wallet-ecosystem
cd wallet-ecosystem

# Start all services
docker compose up -d --wait

# Verify all services healthy
docker compose ps
# Expected: laravel-api, postgres, redis, horizon — all "healthy"

# Run migrations
docker compose exec api php artisan migrate

# Run backend tests
docker compose exec api php artisan test
# Expected: All tests pass, no failures

# Start Next.js admin app
cd apps/admin && npm install && npm run dev
# Expected: http://localhost:3000 responds with the Admin shell

# Start Flutter (Customer app)
cd apps/customer && flutter pub get && flutter run -d chrome
# Expected: App launches without errors
```

**Pass criteria**: All commands complete without errors. Local setup time ≤ 30 minutes.

---

## 2. Repository Structure Validation (FR-001–FR-005)

```bash
# Verify top-level structure
ls -la
# Expected: apps/  services/  packages/  specs/  docs/  scripts/  tests/

# Verify module directories
ls services/api/app/Modules/
# Expected: Platform/ Identity/ Wallet/ Ledger/ Kyc/ Transaction/ Risk/ Agent/ Notification/

# Run module boundary tests
docker compose exec api php artisan test --filter=ArchitectureTest
# Expected: All architecture boundary tests pass
```

---

## 3. API Foundation Validation (FR-025–FR-035, SC-002)

```bash
# Health check endpoints
curl http://localhost:8000/health
# Expected: HTTP 200, {"status": "ok", "checks": {...}}

curl http://localhost:8000/ready
# Expected: HTTP 200, {"status": "ready"}

# API responds with correct envelope (unauthenticated)
curl -i http://localhost:8000/health
# Expected: HTTP 200 with X-Correlation-ID header and JSON envelope

# API returns 401 for protected routes without auth
curl -i http://localhost:8000/v1/configuration
# Expected: HTTP 401, {"success": false, "error": {"code": "AUTHENTICATION_REQUIRED"}}

# Correlation ID is auto-generated if not provided
curl -i http://localhost:8000/health
# Check headers: X-Correlation-ID present and is a valid UUID

# Correlation ID is propagated if provided
CORR_ID="019263a8-0000-7000-8000-000000000001"
curl -i -H "X-Correlation-ID: $CORR_ID" http://localhost:8000/health
# Expected: Response X-Correlation-ID matches provided value
```

---

## 4. Database Schema Validation (FR-016–FR-024, SC-008)

```bash
# Connect to PostgreSQL and verify schemas
docker compose exec postgres psql -U postgres -d wallet_ecosystem -c "\dn"
# Expected: platform, identity, wallet, ledger, kyc, transaction, risk, agent, notification

# Verify platform tables exist
docker compose exec postgres psql -U postgres -d wallet_ecosystem \
  -c "SELECT table_name FROM information_schema.tables WHERE table_schema='platform';"
# Expected: idempotency_records, outbox_events, audit_events, configuration_values, configuration_audit

# Verify UUID v7 primary keys (check column type)
docker compose exec postgres psql -U postgres -d wallet_ecosystem \
  -c "SELECT column_name, data_type FROM information_schema.columns \
      WHERE table_schema='platform' AND column_name='id';"
# Expected: data_type = 'uuid' for all tables

# Verify UTC timestamps
docker compose exec postgres psql -U postgres -d wallet_ecosystem \
  -c "SELECT column_name, data_type FROM information_schema.columns \
      WHERE table_schema='platform' AND column_name='created_at';"
# Expected: data_type = 'timestamp with time zone' for all tables

# Run database tests
docker compose exec api php artisan test --filter=ModuleBoundaryTest
# Expected: All schema constraint tests pass
```

---

## 5. Idempotency Validation (FR-043–FR-048, SC-004)

```bash
# Test duplicate request prevention
KEY="019263a8-0000-7000-8000-000000000010"

# First request (creates)
curl -i -X POST http://localhost:8000/v1/test/idempotency-demo \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $KEY" \
  -H "Authorization: Bearer $TEST_TOKEN" \
  -d '{"action": "test"}'
# Expected: HTTP 201, response body with new resource

# Second request (replay)
curl -i -X POST http://localhost:8000/v1/test/idempotency-demo \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $KEY" \
  -H "Authorization: Bearer $TEST_TOKEN" \
  -d '{"action": "test"}'
# Expected: HTTP 201, IDENTICAL response body (no new resource created)

# Mismatch payload with same key
curl -i -X POST http://localhost:8000/v1/test/idempotency-demo \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $KEY" \
  -H "Authorization: Bearer $TEST_TOKEN" \
  -d '{"action": "different"}'
# Expected: HTTP 409, {"error": {"code": "IDEMPOTENCY_KEY_MISMATCH"}}

# Run idempotency tests (including concurrency)
docker compose exec api php artisan test --filter=IdempotencyIntegration
# Expected: All tests pass including concurrent duplicate tests
```

---

## 6. Event Architecture Validation (FR-049–FR-056, SC-012)

```bash
# Trigger a test event and verify outbox
docker compose exec api php artisan platform:test-event

# Verify event in outbox (status=pending)
docker compose exec postgres psql -U postgres -d wallet_ecosystem \
  -c "SELECT id, event_name, status FROM platform.outbox_events ORDER BY created_at DESC LIMIT 1;"
# Expected: Row with status='pending'

# Wait for Horizon to process (5 seconds)
sleep 6

# Verify event delivered
docker compose exec postgres psql -U postgres -d wallet_ecosystem \
  -c "SELECT id, event_name, status FROM platform.outbox_events ORDER BY created_at DESC LIMIT 1;"
# Expected: Same row with status='delivered'

# Run event tests
docker compose exec api php artisan test --filter=OutboxDispatch
# Expected: All tests pass including idempotent consumer and retry tests
```

---

## 7. Security Foundation Validation (FR-071–FR-079, SC-007, SC-013)

```bash
# Unauthenticated request returns 401
curl -i http://localhost:8000/v1/configuration
# Expected: HTTP 401

# Invalid token returns 401 without user existence disclosure
curl -i -H "Authorization: Bearer invalid_token" http://localhost:8000/v1/configuration
# Expected: HTTP 401, error.code = TOKEN_INVALID, no user data in response

# Security headers present
curl -I http://localhost:8000/v1/health
# Expected headers: Strict-Transport-Security, X-Content-Type-Options, X-Frame-Options, X-Content-Type-Options

# No credentials in logs — test with password-like data
docker compose exec api php artisan test --filter=PiiMaskerTest
# Expected: Tests confirm no passwords/OTPs/tokens appear in log output

# Run security scanning
docker compose exec api composer run security-check
# Expected: No known vulnerabilities in dependencies
```

---

## 8. Audit Foundation Validation (FR-080–FR-084)

```bash
# Trigger an audited action (e.g., permission denied)
curl -i -H "Authorization: Bearer $TEST_TOKEN" -H "X-Test-Permission: admin.denied" \
  http://localhost:8000/v1/test/audit-trigger

# Verify audit event created
docker compose exec postgres psql -U postgres -d wallet_ecosystem \
  -c "SELECT actor_type, action, outcome, correlation_id FROM platform.audit_events ORDER BY created_at DESC LIMIT 1;"
# Expected: Row with action='platform.permission.denied', outcome='failure'

# Verify immutability — attempt UPDATE (should fail)
docker compose exec postgres psql -U postgres -d wallet_ecosystem \
  -c "UPDATE platform.audit_events SET action='tampered' WHERE id=(SELECT id FROM platform.audit_events LIMIT 1);"
# Expected: ERROR: permission denied for table audit_events

# Run audit tests
docker compose exec api php artisan test --filter=AuditService
# Expected: All tests pass including immutability tests
```

---

## 9. Observability Validation (FR-085–FR-093, SC-003, SC-014)

```bash
# Make a request and verify correlation ID in logs
CORR_ID="019263a8-0000-7000-8000-000000000020"
curl -H "X-Correlation-ID: $CORR_ID" http://localhost:8000/v1/health

# Search logs for correlation ID
docker compose logs api | grep "$CORR_ID"
# Expected: Multiple log lines containing the correlation ID, all in JSON format

# Verify JSON log format
docker compose logs api --tail=5
# Expected: Lines are valid JSON with keys: timestamp, level, message, correlation_id

# Health endpoints
curl http://localhost:8000/health
curl http://localhost:8000/ready
curl http://localhost:8000/startup
# All expected to return HTTP 200 with structured JSON

# Verify no PII in logs
docker compose exec api php artisan test --filter=PiiMaskingTest
# Expected: No email, phone, password, token values in any log output
```

---

## 10. Configuration Framework Validation (FR-062–FR-070, SC-015)

```bash
# Verify seeded configuration (currencies)
docker compose exec postgres psql -U postgres -d wallet_ecosystem \
  -c "SELECT config_key, value, status FROM platform.configuration_values WHERE status='active';"
# Expected: Rows for supported currencies (BWP, USD, EUR, ZAR, CNY, USDT)

# Verify TBD items are null (not invented)
docker compose exec postgres psql -U postgres -d wallet_ecosystem \
  -c "SELECT config_key, value FROM platform.configuration_values WHERE value IS NULL;"
# Expected: Rows for GAP-027 threshold and other TBD items

# Verify configuration change creates audit trail
docker compose exec api php artisan test --filter=ConfigurationService
# Expected: All tests pass; audit events created for every config change

# Run configuration tests
docker compose exec api php artisan test --filter=ConfigurationServiceTest
# Expected: All tests pass including effective-date scheduling
```

---

## 11. Design System Validation (FR-094–FR-099, SC-010, SC-011)

```bash
# Run Next.js component tests
cd apps/admin && npm run test
# Expected: All component unit tests pass

# Run accessibility tests
npm run test:a11y
# Expected: No WCAG AA violations in any shared component

# Verify design tokens available in Next.js
npm run build
# Expected: Build succeeds, CSS custom properties visible in output

# Flutter design system tests
cd apps/customer && flutter test
# Expected: All widget tests pass

# Storybook / component gallery (if configured)
cd apps/admin && npm run storybook
# Expected: Storybook starts with all shared components documented
```

---

## 12. CI/CD Pipeline Validation

```bash
# Run full backend CI locally
docker compose exec api composer run ci
# Expected: lint, static analysis, tests, security scan all pass

# Run Next.js CI locally
cd apps/admin && npm run ci
# Expected: lint, type-check, test, build all pass

# Run Flutter CI locally
cd apps/customer && flutter analyze && flutter test
# Expected: No analysis issues, all tests pass
```

---

## 13. Full Platform Validation (SC-001–SC-015)

```bash
# Run the complete platform test suite
docker compose exec api php artisan test --coverage --min=80
# Expected: All tests pass, coverage ≥ 80%

# Run architecture tests
docker compose exec api php artisan test --filter=ModuleBoundary
# Expected: All module boundary tests pass

# No business domain implementations check
docker compose exec api php artisan platform:check-scope
# Expected: No business domain logic detected (auth flows, wallet logic, etc.)
```

---

## Acceptance Checklist

After running all validations, confirm:

- [ ] Docker Compose starts all services and health checks pass
- [ ] All PostgreSQL schemas created (9 schemas)
- [ ] Platform tables created and indexed correctly
- [ ] API envelope format consistent across all endpoints
- [ ] Correlation IDs present in 100% of API responses
- [ ] Idempotency prevents duplicate processing (tested with concurrency)
- [ ] Events persisted in outbox and delivered by Horizon worker
- [ ] Audit events recorded and immutable
- [ ] Configuration values support effective dates and audit history
- [ ] Security headers present on all responses
- [ ] No PII or credentials in any log output
- [ ] Design tokens available in both Next.js and Flutter
- [ ] All shared components pass WCAG AA accessibility tests
- [ ] CI/CD pipelines pass for backend, Next.js, and Flutter
- [ ] Test coverage ≥ 80% for platform layer
- [ ] No business domain implementations exist
- [ ] No TBD values invented (TBD items stored as null/sentinel)
- [ ] Module boundary tests pass (no cross-domain violations)
