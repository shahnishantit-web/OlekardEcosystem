# OWASP Top 10 — Evidence Summary

**Feature**: E26 Security Hardening  
**Date**: 2026-10-02  
**Status**: Implemented — Docker-environment tests deferred (T071)

Each row links to the primary test file that provides evidence. Approved exceptions with justification are noted.

| # | Category | Status | Primary Evidence | Notes |
|---|----------|--------|-----------------|-------|
| A01 | Broken Access Control | ✅ PASS | `tests/Feature/Modules/Security/Audit/SecurityAuditEventTest.php` | E04 permission middleware enforces RBAC server-side on all routes; admin routes protected by `identity.require-permission` middleware; T047 test validates config change audit |
| A02 | Cryptographic Failures | ✅ PASS | `tests/Feature/Modules/Security/Credentials/AtRestEncryptionTest.php` | KYC PII fields (date_of_birth, document_number, extracted_data) encrypted with AES-256-CBC via Laravel Encrypter; credential_hash bcrypt-hashed (T023/T027/T028) |
| A03 | Injection | ✅ PASS | `tests/Feature/Modules/Security/Http/InjectionPreventionTest.php` | SQL injection probes return 422 (FormRequest validation), never 500; all DB calls use parameterized bindings (T017–T021 raw-query audit confirmed); CSP header blocks XSS execution |
| A04 | Insecure Design | ✅ PASS | `tests/Feature/Modules/Security/Http/FormRequestCoverageTest.php` | Double-entry ledger enforced (E07); all state-changing endpoints require FormRequest validation (T020); idempotency keys prevent duplicate financial operations |
| A05 | Security Misconfiguration | ✅ PASS | `tests/Feature/Modules/Security/Http/SecurityHeadersTest.php` | 6 security headers set by SecurityHeadersMiddleware (T010/T013–T015); CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy all validated |
| A06 | Vulnerable & Outdated Components | ⚠️ PENDING | `composer.json` (T022 `audit` script) | `composer audit` script added; CI gate in place (T079); full scan requires Docker (T067/T071); no known critical findings in composer.lock at spec time |
| A07 | Identification & Authentication Failures | ✅ PASS | `tests/Feature/Modules/Security/Authentication/BruteForceProtectionTest.php` | Account lockout after 5 failures (T038–T041); OTP login path enforces lockout (T038); session tokens rotated on login (E03); JWT bearer auth used for API (not cookies — no CSRF needed) |
| A08 | Software & Data Integrity Failures | ✅ PASS | `tests/Unit/Providers/SecretsBootCheckTest.php` | APP_KEY and DB_PASSWORD required at boot (T011/T029); idempotency prevents duplicate processing; CI gated on composer audit (T079) |
| A09 | Security Logging & Monitoring Failures | ✅ PASS | `tests/Feature/Modules/Security/Logging/LogMaskingTest.php` + `tests/Feature/Modules/Security/VulnerabilityFinding/VulnerabilityFindingTest.php` | PII masked in all log channels (T012/T034); security events (lockout, config changes, suppression, resolution) written to audit log (T047–T049/T063); vulnerability findings tracked in `security.vulnerability_findings` |
| A10 | Server-Side Request Forgery (SSRF) | ✅ PASS | `docs/security/raw-query-audit.md` | No user-controlled URLs used in server-side HTTP calls; all external provider adapters use fixed base URLs from config (not user input); no file fetch from user-supplied paths |

## Approved Exceptions

| Exception | Justification | Accepted By |
|-----------|---------------|-------------|
| A06 — full CVE scan requires Docker | `composer audit` is run locally and in CI; Docker-gated full scan (T067) will complete during integration testing; no high/critical findings at spec time | Security review — E26 spec |

## Evidence Gaps (Planned)

- **T071** (Docker quickstart): End-to-end validation of all security controls against running containers — deferred to integration environment.
- **T067** (composer audit full run): Package vulnerability scan — blocked on Docker; CI gate (T079) covers this in the pipeline.
