# Quickstart Validation Guide — E26 Security Hardening

Runnable validation scenarios that prove E26 requirements are met end-to-end.

## Prerequisites

- Docker Compose stack running: `docker compose up -d`
- Admin user seeded: `admin@olekard.local / Admin123!`
- API base URL: `http://localhost:8000/api/v1`
- Tools: `curl`, `jq`

---

## SC-1 — Security Headers Present on All Responses (FR-002, SC-001)

```bash
# Any endpoint — check headers
curl -sI http://localhost:8000/api/v1/health | grep -iE \
  "strict-transport|x-frame|x-content-type|referrer-policy|content-security|permissions-policy"
```

**Expected:** All six headers present with correct values:
```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: ...
Permissions-Policy: ...
```

---

## SC-2 — Brute-Force Lockout Triggers (FR-015, SC-005)

```bash
# Login with wrong password 5 times
TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"phone": "+26771234567", "password": "WrongPass1!"}' | jq -r '.error.code')

for i in $(seq 1 5); do
  curl -s -X POST http://localhost:8000/api/v1/auth/login \
    -H "Content-Type: application/json" \
    -d '{"phone": "+26771234567", "password": "WrongPass1!"}'
done

# 6th attempt — expect account_locked error
curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"phone": "+26771234567", "password": "CorrectPass1!"}' | jq '.error.code'
```

**Expected:** `"ACCOUNT_LOCKED"` on the 6th attempt; `locked_until` timestamp in response.

---

## SC-3 — Log Masking: No Plaintext Secrets in Logs (FR-012, SC-003)

```bash
# Generate a log event that would contain sensitive context
# (Use a known test that triggers identity.account.locked audit event)
docker compose exec api php artisan tinker --execute="
  \Log::info('test sensitive', ['pin' => '123456', 'otp' => '789012',
    'account_number' => 'ACC0001234567890']);
"

# Check the most recent log entry
docker compose exec api tail -n 5 storage/logs/laravel.log | \
  grep -E "123456|789012|ACC0001234567890"
```

**Expected:** No match — values replaced with `***`.

---

## SC-4 — API Response Masking (FR-011, SC-004)

```bash
ADMIN_TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@olekard.local", "password": "Admin123!"}' | jq -r '.data.token')

# Get a wallet — account_number should be masked
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
  http://localhost:8000/api/v1/wallets | jq '.data[0].account_number'
```

**Expected:** Account number like `"****7890"` — only last 4 digits visible.

---

## SC-5 — Create Vulnerability Finding and Suppress It (FR-022, SC-008)

```bash
ADMIN_TOKEN=<admin_token>

# Create a finding
FINDING=$(curl -s -X POST http://localhost:8000/api/v1/security/findings \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "scanner": "manual",
    "severity": "MEDIUM",
    "component": "laravel/framework",
    "title": "Test: Dependency version outdated",
    "detected_at": "2026-10-01T00:00:00Z"
  }')

echo $FINDING | jq '.data.status'
# Expected: "open"

FINDING_ID=$(echo $FINDING | jq -r '.data.id')

# Suppress it with justification
curl -s -X PATCH http://localhost:8000/api/v1/security/findings/$FINDING_ID/status \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "status": "suppressed",
    "suppression_justification": "False positive — version pinned by upstream dependency"
  }' | jq '.data.status'
# Expected: "suppressed"
```

---

## SC-6 — At-Rest Encryption: Credential Value Ciphertext in DB (FR-010)

```bash
# After creating a user with a national ID credential,
# query the raw DB value and verify it is ciphertext, not plaintext.

docker compose exec db psql -U postgres platform -c \
  "SELECT value FROM identity.credentials WHERE type = 'national_id' LIMIT 1;"
```

**Expected:** Value is base64-encoded ciphertext (starts with `eyJ` or similar), NOT a plaintext national ID number.

---

## SC-7 — Secrets Boot Check Fails Closed (FR-009)

```bash
# Temporarily unset APP_KEY and attempt to start the app
docker compose exec api php artisan config:clear
APP_KEY="" php artisan about 2>&1 | grep -i "secret\|key\|boot"
```

**Expected:** Application refuses to start or throws a `RuntimeException` about missing required secret.

---

## SC-8 — Dependency Vulnerability Scan (FR-020, SC-008)

```bash
docker compose exec api composer audit
```

**Expected:** Exits with code 0 (no known vulnerabilities) or lists findings by severity. Any CRITICAL or HIGH findings must be remediated before release.

---

## SC-9 — Security Dashboard Returns Aggregate Counts (US7 AC4)

```bash
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
  http://localhost:8000/api/v1/security/dashboard | jq '.'
```

**Expected:**
```json
{
  "success": true,
  "data": {
    "total_open": 0,
    "findings_by_severity": { "CRITICAL": 0, "HIGH": 0, "MEDIUM": 0, "LOW": 0, "INFO": 0 },
    "findings_by_status": { "open": 0, "suppressed": 1, "resolved": 0 }
  }
}
```

---

## OWASP Top 10 Pen Test Readiness Checklist

| # | Category | Addressed By | Evidence |
|---|----------|--------------|----------|
| A01 | Broken Access Control | E04 RBAC + server-side auth guard | AuthorizationGuard + permission tests |
| A02 | Cryptographic Failures | E26 FR-008 (bcrypt), FR-010 (AES-256), FR-009 (secrets) | At-rest encryption tests + LockoutService |
| A03 | Injection | E26 FR-004 (Eloquent ORM), FR-005 (Form Requests) | Input validation tests + ORM parameterization |
| A04 | Insecure Design | Constitution Principles 4, 6, 7 + E26 audit coverage | Audit event tests |
| A05 | Security Misconfiguration | E26 FR-001 (TLS), FR-002 (headers), SC-7 (secrets boot) | Header scanner + boot test |
| A06 | Vulnerable Components | E26 FR-020 (`composer audit` in CI) | CI pipeline output |
| A07 | Authentication Failures | E01 LockoutService + E26 FR-015 lockout coverage audit | LockoutServiceTest + AuthenticationSecurityTest |
| A08 | Software Integrity Failures | `composer audit` + PHPStan | CI gate |
| A09 | Logging & Monitoring | E23 AuditService + E26 FR-018 new event types + SC-3 log masking | AuditEventTest + SensitiveDataProcessor |
| A10 | SSRF | No external URL resolution from user input; webhook contracts validate provider registry | WebhookApplicationService + IntegrationRegistry |

**Review cadence:** Checklist reviewed before each major release as a release governance gate item (SC-009).
