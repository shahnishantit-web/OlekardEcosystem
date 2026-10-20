# Data Model — E26 Security Hardening

## New Schema

All new tables live in the `security` PostgreSQL schema, accessed via the `platform` DB connection.

---

## New Entity: VulnerabilityFinding

Tracks security vulnerabilities identified by automated scanners or manual review. Supports the admin security dashboard and the CI build gate.

### Table: `security.vulnerability_findings`

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `uuid` | PK | UUID v7 |
| `scanner` | `varchar(100)` | NOT NULL | Source: `composer-audit`, `phpstan`, `zap`, `manual`, `pen-test` |
| `finding_ref` | `varchar(200)` | nullable | CVE ID, GHSA ID, rule ID, or manual reference |
| `severity` | `varchar(20)` | NOT NULL | Enum: `CRITICAL`, `HIGH`, `MEDIUM`, `LOW`, `INFO` |
| `component` | `varchar(500)` | NOT NULL | Package name, file path, or endpoint |
| `title` | `varchar(500)` | NOT NULL | Short description |
| `description` | `text` | nullable | Full details |
| `status` | `varchar(20)` | NOT NULL, default `open` | Enum: `open`, `suppressed`, `resolved` |
| `suppression_justification` | `text` | nullable | Required when status = `suppressed` |
| `suppressed_by` | `uuid` | nullable FK → `identity.users.id` | Actor who suppressed |
| `detected_at` | `timestamptz` | NOT NULL | When finding was first identified |
| `resolved_at` | `timestamptz` | nullable | When finding was resolved |
| `created_at` | `timestamptz` | NOT NULL | |
| `updated_at` | `timestamptz` | NOT NULL | |

**Indexes:**
- `idx_vuln_findings_severity_status` on `(severity, status)` — supports dashboard queries
- `idx_vuln_findings_scanner` on `(scanner)` — supports import deduplication
- `idx_vuln_findings_finding_ref` on `(finding_ref)` — unique per scanner

**Constraints:**
- `chk_suppression_justification`: `suppression_justification IS NOT NULL` when `status = 'suppressed'`
- `chk_resolved_at`: `resolved_at IS NOT NULL` when `status = 'resolved'`

**State transitions:**

```
open → suppressed (actor, justification required)
open → resolved   (actor, resolved_at set)
suppressed → open (reopen)
suppressed → resolved
resolved → open   (regression)
```

---

## Existing Entities — Extended by E26

These entities belong to prior epics but gain new fields or audit event types in E26.

### `identity.users` (E01) — No schema changes

`LockoutService` already uses `failed_attempt_count`, `locked_at`, `lockout_episode_count` on this table. E26 verifies coverage — no migration needed.

### `identity.credentials` (E01) — At-rest encryption

The `value` column stores national ID / passport numbers. E26 adds the `encrypted` Eloquent cast to this field. No schema change (encryption/decryption is transparent application-layer). Key management via `APP_KEY`.

### `identity.audit_events` (E23/Platform) — New action types

E26 emits new action type strings via the existing `AuditService`. No schema changes — the `action` column is `varchar(255)`. New action constants:

```
security.pii.accessed
security.config.rate_limit_changed
security.config.lockout_changed
security.vuln_finding.suppressed
security.vuln_finding.resolved
security.secrets.boot_check_failed
```

---

## Entities Referenced but NOT New to E26

| Entity | Owned By | E26 Usage |
|--------|----------|-----------|
| `identity.audit_events` | Platform / E23 | Security event emission |
| `identity.users` | Identity / E01 | Brute-force lockout state |
| `identity.credentials` | Identity / E01 | At-rest encryption on `value` |
| `kyc.applicants` | KYC / E05 | At-rest encryption on `date_of_birth` |
| `kyc.documents` | KYC / E05 | At-rest encryption on `document_number`, `extracted_data` |
| `platform.configuration_entries` | Platform | Rate limit + lockout threshold config |

---

## New Eloquent Models

### `VulnerabilityFindingModel`

- Namespace: `App\Modules\Security\Infrastructure\Models\VulnerabilityFindingModel`
- Table: `security.vulnerability_findings`
- Connection: `platform`
- Casts: `detected_at` → `immutable_datetime`, `resolved_at` → `immutable_datetime`
- Status enum: `VulnerabilityFindingStatus` (open / suppressed / resolved)
- Severity enum: `VulnerabilityFindingSeverity` (CRITICAL / HIGH / MEDIUM / LOW / INFO)

---

## At-Rest Encryption: Designated Fields

Fields receiving the `'encrypted'` Eloquent cast in E26:

| Model | Field | Module |
|-------|-------|--------|
| `Credential` (Identity) | `value` | E01 |
| `KycApplicant` | `date_of_birth` | E05 |
| `KycDocument` | `document_number` | E05 |
| `KycDocument` | `extracted_data` | E05 |

Fields already considered exempt (hashed, not plaintext-stored):
- National ID: stored as HMAC-SHA256 via `PiiMasker::hashNationalId()` in E05 — no encryption needed on the hash.
- IP addresses: stored as HMAC-SHA256 via `PiiMasker::hashIp()` — no encryption needed.
- Passwords/PINs: stored as bcrypt hashes — no encryption needed (hashing is stronger).

---

## Log Masking: SensitiveDataProcessor

New file: `app/Modules/Platform/Infrastructure/Logging/SensitiveDataProcessor.php`

Sensitive key patterns detected and masked:
```
password, pin, otp, secret, token, card_number, cvv, id_number,
passport, national_id, account_number, wallet_number, iban, bban,
raw_payload, credentials_reference, raw_secret, document_number,
extracted_data, date_of_birth
```

Registration: added to `config/logging.php` global processors array applied to all channels.
