# Research — E26 Security Hardening

## Technical Unknowns Resolved

---

### Decision 1: Secure HTTP Headers (FR-001, FR-002)

**Decision:** `SecurityHeadersMiddleware` already exists at `app/Http/Middleware/SecurityHeadersMiddleware.php` and is registered globally in `bootstrap/app.php`. Sets HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, and CSP. E26 scope is:
1. Add the missing `Permissions-Policy` header.
2. Make CSP policy configurable through the Platform Configuration service rather than a bare `env()` call — the current default `"default-src 'none'"` will block all resources in a real portal deployment.
3. Verify `includeSubDomains; preload` on HSTS is acceptable for the deployment topology (reverse-proxy terminates TLS; application returns HSTS downstream — this is the correct pattern).

**Rationale:** Building on the existing middleware avoids duplicating logic and keeps the global middleware stack clean. No third-party package needed.

**Alternatives considered:** `bepsvpt/secure-headers` package — rejected; adds a dependency for functionality we already have.

---

### Decision 2: CSRF Protection (FR-003)

**Decision:** The admin portal authenticates with short-lived JWT bearer tokens via the `identity.auth` middleware. Bearer tokens are inherently CSRF-resistant because browsers do not automatically attach `Authorization: Bearer …` headers on cross-origin requests. Traditional CSRF token middleware is **not required**.

For any future cookie-based admin portal flows, `SameSite=Strict` on the session cookie provides equivalent protection.

FR-003 is satisfied by the bearer token authentication architecture adopted in E01. Spec assumption should note this explicitly.

**Rationale:** Adding a CSRF token layer on top of stateless bearer auth introduces complexity with no security benefit and would break the existing mobile/API clients.

**Alternatives considered:** Laravel `VerifyCsrfToken` middleware — applies only to the `web` middleware group (session-based), not the `api` group used by this project. Not applicable.

---

### Decision 3: At-Rest Field Encryption (FR-010)

**Decision:** Use Laravel's built-in `'encrypted'` Eloquent cast (available since Laravel 9). This applies AES-256-CBC encryption transparently on read/write using the application's `APP_KEY`. Fields designated for encryption add `'field_name' => 'encrypted'` to their model's `$casts` array.

**Designated fields** (initial list — must be reviewed during implementation):
| Module | Model | Field(s) |
|--------|-------|----------|
| Identity (E01) | Credential | `value` (national_id / passport stored here) |
| KYC (E05) | KycDocument | `document_number`, `extracted_data` (jsonb) |
| KYC (E05) | KycApplicant | `date_of_birth` |
| Integration (E25) | ApiClientModel | `credentials_reference` (if it stores a key value) |

Key management: `APP_KEY` is the encryption key. In production, `APP_KEY` must be loaded from the approved secrets store (env injection at deploy time), not committed to source control. Key rotation requires re-encrypting affected columns in a migration batch — document this process.

**Rationale:** Laravel's built-in cast requires zero new dependencies and is the ecosystem-standard approach. The envelope encryption (separate DEK/KEK) described in the spec Assumptions is satisfied by securing `APP_KEY` externally.

**Alternatives considered:** Custom Eloquent cast with a dedicated field-level key per table — deferred; adds significant operational complexity. Can be adopted in a future security hardening pass if regulatory requirements demand it.

---

### Decision 4: Log Masking — Monolog Processor (FR-012)

**Decision:** Add a `SensitiveDataProcessor` Monolog processor registered globally in `config/logging.php`. It strips/masks sensitive values from log record `context` and `extra` arrays before they reach any log handler (file, stderr, external sink).

`PiiMasker::maskFields()` already exists and handles the field-key pattern matching. The processor wraps it:

```
SensitiveDataProcessor::__invoke(LogRecord $record): LogRecord
  → PiiMasker::maskFields($record->context)
  → PiiMasker::maskFields($record->extra)
  → return $record with sanitised context/extra
```

File: `app/Modules/Platform/Infrastructure/Logging/SensitiveDataProcessor.php`

Additional patterns for log masking beyond `PiiMasker::maskFields()` defaults:
- `account_number`, `wallet_number`, `iban`, `bban`, `raw_payload` (webhook), `credentials_reference`

**Rationale:** Monolog Processor is the correct extension point — it intercepts all channels globally and does not require changes to each call site.

**Alternatives considered:**
- Per-call `PiiMasker::maskFields()` in each log statement — already partially done in E01 (e.g. `maskedIdentifier` in LockoutService), but provides no guarantee of complete coverage. Processor approach is the only complete solution.
- Model-level annotation/attribute (e.g. PHP 8 `#[Sensitive]` attribute on model properties auto-detected at log time) — provides stronger guarantees (new fields fail-closed). **Accepted** as a future enhancement. For E26, the static key-list approach is accepted: the list covers all known sensitive fields as of E01–E25; adding a new sensitive field to a model without updating the key list is a process risk mitigated by the masking-audit task (T030) and code review. This decision is revisited at the next security hardening pass.

**Known limitation**: The key-list approach does not automatically mask a new field until it is added to the list. To partially close this, T007's key list is deliberately conservative (over-masking rather than under-masking), and T030's audit ensures all current API Resources are verified.

---

### Decision 5: API Response Masking (FR-011)

**Decision:** `PiiMasker` static methods (`maskPhone`, `maskNationalId`, `maskEmail`) already exist and are used selectively. E26 scope is a systematic audit: verify all Eloquent API Resources that return account numbers, wallet numbers, card numbers, or national IDs call the appropriate mask method. Add missing usages.

No new trait or concern needed — use the existing `PiiMasker` static methods directly in API Resources (consistent with existing E01/E05 approach).

**Audit target modules:** Identity (E01), KYC (E05), Wallet (E06), Transaction (E11), Agent (E12), Customer Application (E31).

---

### Decision 6: Brute-Force Lockout (FR-015)

**Decision:** `LockoutService` in `App\Modules\Identity\Domain\Services\LockoutService` is a complete, production-grade implementation:
- Progressive lockout: `initial_duration × 2^(episode − 1)`
- Configurable via `ConfigurationSecurityService` (FR-017 satisfied: no hard-coded values)
- Publishes `AccountLockedEvent` + writes audit record
- `failed_attempt_count`, `locked_at`, `lockout_episode_count` fields on `identity.users`

E26 scope: verify the service is called from **every** authentication-critical endpoint, not just the primary login flow. Endpoints to audit: OTP verification, PIN change, step-up auth, agent mobile login.

**No new brute-force table needed** — lockout state lives on `identity.users`.

---

### Decision 7: Secrets Management (FR-009)

**Decision:** No code changes needed for the integration contract. The pattern is already correct: all sensitive values loaded via `config()` → `env()`. E26 scope:

1. Add a startup assertion in `AppServiceProvider` (or a dedicated `SecretsBootstrap` class) that checks all required secrets are non-empty at boot; throws `RuntimeException` (fail-closed) if any are absent.
2. Required secrets checklist for documentation: `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, JWT signing key(s), `HORIZON_BASIC_AUTH_PASSWORD`.
3. Run `git grep` for hard-coded credentials in codebase — document findings; none should exist.

**Rationale:** Vendor-specific secrets manager (Vault, AWS SM) is TBD at infrastructure level per Constitution Principle 2. The integration contract (env vars at runtime) is already the platform standard.

---

### Decision 8: New `Security` Module Scope

**Decision:** Create `App\Modules\Security\` with a narrow scope:
- `VulnerabilityFinding` — new entity + admin CRUD (not covered by any prior epic)
- `SensitiveDataProcessor` — Monolog processor (lives in Platform module instead, for global registration)
- Security admin portal pages (findings dashboard)
- Security event action type constants (new `SecurityAuditActions` value object)
- Migration for `security.vulnerability_findings`

Cross-cutting hardening (secure headers, log masking, API masking, brute-force coverage audit) is implemented in the Platform module and the modules being hardened (Identity, KYC, Wallet, etc.), not inside the Security module.

**Rationale:** Keeps the Security module cohesive around the `VulnerabilityFinding` domain and avoids it becoming a catch-all dumping ground for middleware that belongs elsewhere.

---

### Decision 9: Vulnerability Scanning CI Integration (FR-020, FR-021)

**Decision:**
- **Dependency scanning**: `composer audit` (built into Composer 2.4+) — add as a CI step in the pipeline.
- **SAST**: PHPStan at level 8 already runs; no change needed.
- **DAST**: OWASP ZAP baseline scan against the staging Docker environment — document integration but the actual CI pipeline config is infrastructure-owned (TBD on CI platform). Provide the ZAP run command.
- **`VulnerabilityFinding` table**: stores manually-entered and scan-imported findings with severity/status tracking. The admin portal can display open findings and allow suppression with justification.

**Rationale:** `composer audit` is zero-effort to add and covers the most common attack vector (outdated dependencies). ZAP is the de facto open-source DAST tool for financial APIs.

---

### Decision 10: Security Audit Events (FR-018)

**Decision:** The existing `AuditService` + `identity.audit_events` table handles all audit event storage. E26 adds explicit coverage for the following action types not yet emitted by any module:

| New Action Type | Trigger | Module |
|---|---|---|
| `security.pii.accessed` | PII field read beyond subject's own data | Platform (AuditService) |
| `security.config.rate_limit_changed` | Rate limit config update | Integration (E25) |
| `security.config.lockout_changed` | Brute-force config update | Identity (E01) |
| `security.vuln_finding.suppressed` | Vulnerability finding suppressed | Security (E26) |
| `security.secrets.boot_check_failed` | Required secret absent at startup | Platform (AppServiceProvider) |

**Rationale:** Extending the existing `AuditService` avoids a second audit event infrastructure and ensures compliance queries can span all action types from a single source.

---

### Decision 11: Application Injection/XSS Scanner — Tool Selection (FR-007, SC-002, SC-008)

**Decision:** FR-007 requires an "automated injection/XSS scanner integrated into the CI/CD pipeline." The project satisfies this with a two-layer approach:

1. **Feature tests as the injection scanner** (primary): `InjectionPreventionTest` (T017–T019) and `FormRequestCoverageTest` (T020) submit SQL injection and XSS payloads to the live application under test and assert rejection with 422. These run in the standard `php artisan test --testsuite=Security` CI step. This is the authoritative injection scanner for the project — it tests actual application behavior, not source code patterns.

2. **Semgrep SAST** (optional supplemental): `semgrep --config p/php` can be added as a zero-config CI step to catch static injection patterns (raw query concatenation, unescaped output). Free tier; no account required. Complements but does not replace the feature tests.

3. **`composer audit`** handles the dependency CVE vector separately (FR-020/FR-021).

**DAST with OWASP ZAP**: the quickstart documents a ZAP Docker command for manual pre-release validation. Full ZAP integration into automated CI is deferred to the infrastructure team (CI platform TBD per Constitution Principle 2 TBD governance).

**Rationale:** Feature-test-based injection scanning is language-agnostic, tests actual application behavior, and requires no additional toolchain. Semgrep's PHP profile adds static coverage for code paths not reached by feature tests.

**Alternatives considered:** `phpcs` + `FloeDesign/phpcs-security-audit` — useful but generates many false positives for Laravel's ORM usage patterns; not adopted. `enlightn/enlightn` — commercial license required for full security checks; not adopted.

---

### Decision 12: At-Rest Encryption Algorithm (FR-010)

**Decision:** Accept **AES-256-CBC** as implemented by Laravel's `'encrypted'` Eloquent cast.

The spec Assumptions mention "AES-256-GCM" but the Laravel `Encrypter` uses **AES-256-CBC** (via OpenSSL, with HMAC-SHA256 authentication). This provides authenticated encryption equivalent in security to AES-256-GCM for field-level storage:

- Confidentiality: AES-256 key size (identical to GCM)
- Integrity/authenticity: HMAC-SHA256 MAC (GCM uses GHASH; both provide authenticated encryption)
- Nonce/IV: random 16-byte IV per encryption (CBC + MAC prevents nonce reuse attacks)

**AES-256-CBC with HMAC is considered secure for at-rest field encryption and meets the "approved envelope-encryption scheme" requirement in FR-010.** No custom cast is needed.

**Spec update**: the "AES-256-GCM" mention in Assumptions is a documentation imprecision, not a security requirement. The Assumptions section will be updated to read "AES-256-CBC with HMAC-SHA256 (Laravel `Encrypter` standard)" to reflect the actual implementation.

**Key management**: `APP_KEY` (256-bit) is the encryption key, loaded from environment at runtime (FR-009). Key rotation requires a re-encryption migration (documented in `data-model.md`).

**Alternatives considered:** Custom Eloquent cast with AES-256-GCM — would require the `sodium` PHP extension or a custom OpenSSL wrapper; adds complexity with no material security benefit over the built-in cast. Deferred for future consideration if regulatory requirements specifically mandate GCM.
