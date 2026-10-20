# CI Security Pipeline — E26 Security Hardening

**Feature**: E26 Security Hardening  
**Date**: 2026-10-02

## Overview

The security pipeline runs as part of the standard CI test suite via Docker Compose.
All steps below are blocking — a failure in any step prevents deployment.

## Step 1 — Dependency Vulnerability Scan (T079)

```bash
# Run after composer install, before test suite
docker compose -f docker-compose.test.yml run --rm api \
  composer audit --no-interaction
```

- Exits non-zero if any HIGH or CRITICAL vulnerability is detected in `composer.lock`
- Script alias: `composer run audit` (defined in `composer.json`)
- Findings must be remediated or suppressed in the `security.vulnerability_findings` table before the pipeline can pass

## Step 2 — Security Test Suite (T078)

```bash
# Run the Security PHPUnit testsuite
docker compose -f docker-compose.test.yml run --rm api \
  php artisan test --testsuite=Security
```

The Security testsuite includes:

| Test Class | FR Coverage | What it proves |
|-----------|-------------|----------------|
| `SecurityHeadersTest` | FR-001, FR-002 | All 6 security headers present in every response |
| `CsrfBearerAuthTest` | FR-003 | Bearer-token CSRF strategy confirmed; VerifyCsrfToken NOT in API group |
| `InjectionPreventionTest` | FR-007 | SQL injection probes return 422 not 500; CSP blocks XSS |
| `FormRequestCoverageTest` | FR-007 | All state-changing endpoints use FormRequest validation |
| `AtRestEncryptionTest` | FR-009 | KYC PII fields encrypted as ciphertext in DB |
| `SecretsBootCheckTest` | FR-011 | APP_KEY/DB_PASSWORD missing → RuntimeException at boot |
| `ApiResponseMaskingTest` | FR-010 | PII masked in API Resources |
| `LogMaskingTest` | FR-010 | PII masked in all log channels |
| `BruteForceProtectionTest` | FR-015, FR-016 | Lockout after 5 failures; 429 + Retry-After on burst |
| `SecurityAuditEventTest` | FR-017, FR-018 | Audit events emitted for lockout config + PII access |
| `VulnerabilityFindingTest` | FR-019, FR-020 | CRUD, status transitions, audit events |
| `PinHashTest` | FR-008 | All credential types store bcrypt/argon2 hashes, not plaintext |
| `PurgeExpiredPiiTest` | FR-014 | PII purge respects retention config; noop when days=0 |

## Step 3 — Optional: SAST Static Analysis

```bash
# Run semgrep PHP ruleset (free, zero-config SAST)
docker compose -f docker-compose.test.yml run --rm api \
  semgrep --config p/php --error app/
```

- Non-blocking by default (advisory); elevate to blocking for pre-release pipelines
- `p/php` ruleset covers injection, hardcoded secrets, and insecure crypto patterns

## Makefile Integration

Add to `Makefile` (or adapt to your CI platform):

```makefile
.PHONY: test-security ci-security

test-security:
	docker compose -f docker-compose.test.yml run --rm api \
		php artisan test --testsuite=Security

ci-security: install
	docker compose -f docker-compose.test.yml run --rm api \
		composer audit --no-interaction
	$(MAKE) test-security
```

## Evidence

- `docs/security/owasp-top10-evidence.md` — maps each OWASP Top 10 category to a test file
- `docs/security/raw-query-audit.md` — confirms all raw queries are parameterized
- `docs/security/masking-audit.md` — confirms PII masking coverage
