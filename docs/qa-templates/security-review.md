# Security Review Template

## Review Information

| Field | Value |
|-------|-------|
| PR / Branch | <!-- e.g., feature/US-042-wallet-creation --> |
| Reviewer | <!-- Name --> |
| Date | <!-- YYYY-MM-DD --> |
| Scope | <!-- What changed: files / endpoints / modules --> |

---

## OWASP Top 10 Checklist

| # | Vulnerability | Checked | Finding | Severity |
|---|---------------|---------|---------|----------|
| A01 | Broken Access Control | [ ] | <!-- None / Description --> | <!-- N/A / Low / Med / High / Critical --> |
| A02 | Cryptographic Failures | [ ] | | |
| A03 | Injection (SQL, NoSQL, LDAP, OS) | [ ] | | |
| A04 | Insecure Design | [ ] | | |
| A05 | Security Misconfiguration | [ ] | | |
| A06 | Vulnerable and Outdated Components | [ ] | | |
| A07 | Identification and Authentication Failures | [ ] | | |
| A08 | Software and Data Integrity Failures | [ ] | | |
| A09 | Security Logging and Monitoring Failures | [ ] | | |
| A10 | Server-Side Request Forgery (SSRF) | [ ] | | |

---

## Dependency Vulnerability Check

- [ ] `composer audit` or `npm audit` run — no HIGH/CRITICAL findings
- [ ] Pinned dependencies reviewed for known CVEs
- [ ] New third-party packages approved by tech lead

**Findings**: <!-- None / List CVEs -->

---

## Authentication & Authorization

- [ ] All new endpoints behind `auth:sanctum` middleware (or explicitly public and documented)
- [ ] Policy / gate checks present for privileged operations
- [ ] Middleware ordering verified (auth before business logic)
- [ ] JWT / token claims not trusted without verification
- [ ] No hardcoded credentials, API keys, or secrets in code

---

## Sensitive Data Exposure

- [ ] PII masked before log persistence (AuditService / RequestLoggingMiddleware verified)
- [ ] Sensitive fields not returned in API responses unnecessarily
- [ ] Database columns storing sensitive data use correct type and no plaintext where encryption expected
- [ ] Secrets loaded from environment / secret store — not from code or config files

---

## Input Validation

- [ ] Request validation present for all user-controlled inputs (Laravel FormRequest or equivalent)
- [ ] File uploads (if any) validated for type and size
- [ ] Path traversal and injection characters blocked

---

## Financial Operation Integrity

- [ ] Idempotency-Key enforced on all state-changing financial endpoints
- [ ] No direct wallet balance mutation — all changes via ledger posting
- [ ] Audit log entries created and immutable (INSERT + SELECT only)
- [ ] Double-entry journal entries verified for new financial flows

---

## Result

**Overall Security Review Result**: <!-- PASS / FAIL / CONDITIONAL PASS -->
**Conditions (if conditional)**: <!-- List blocking items that must be resolved -->
**Reviewer Signature**: <!-- Name + Date -->
