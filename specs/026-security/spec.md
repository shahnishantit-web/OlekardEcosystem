# Feature Specification: E26 — Security Hardening

**Feature Branch**: `026-security`

**Created**: 2026-10-01

**Status**: Draft

**Input**: User description: "E26 security"

## User Scenarios & Testing *(mandatory)*

<!--
  Security hardening spans 16 backlog stories (US-SEC-001 to US-SEC-016).
  Stories are grouped into user-value clusters, each independently testable.
  Original story IDs are preserved in parentheses.
-->

### User Story 1 — Secure Transport & HTTP Layer (Priority: P1)

As a **security administrator**, I want all API and web traffic to use encrypted transport and carry security-hardening HTTP headers, so that data in transit is protected from eavesdropping and clickjacking attacks.

*Covers: US-SEC-001 (TLS enforcement), US-SEC-007 (CSRF protection), US-SEC-011 (Secure headers)*

**Why this priority**: Transport security is the outermost defence layer. Without TLS and secure headers, every other security control can be bypassed by a network-level attacker.

**Independent Test**: Deploy the API and admin portal behind a TLS-terminating reverse proxy; use a security-header scanner to verify all responses carry the required headers and that HTTP is rejected or redirected.

**Acceptance Scenarios**:

1. **Given** a client makes a plain-HTTP request to any API or admin endpoint, **When** the request arrives at the service layer, **Then** it is rejected or permanently redirected to HTTPS.
2. **Given** any API or admin portal HTTP response, **When** inspected by a security-header scanner, **Then** `Strict-Transport-Security`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, and a `Content-Security-Policy` header are all present.
3. **Given** a browser-initiated state-changing request to the admin portal, **When** the CSRF token is absent or invalid, **Then** the request is rejected with HTTP 403.
4. **Given** a valid CSRF token, **When** the admin portal submits a form, **Then** the request is accepted and processed normally.

---

### User Story 2 — Injection Prevention & Input Validation (Priority: P1)

As a **developer** and **security reviewer**, I want every user-supplied input to be validated and all database queries to use parameterized forms, so that SQL injection, XSS, and malformed-input attacks cannot succeed against any API endpoint.

*Covers: US-SEC-008 (Input validation), US-SEC-009 (SQL injection prevention), US-SEC-010 (XSS prevention)*

**Why this priority**: Injection vulnerabilities are perennial OWASP Top 10 threats; a single exploited injection point could expose the entire customer financial dataset.

**Independent Test**: Submit payloads containing SQL metacharacters, JavaScript injection strings, and oversized inputs to every public API endpoint; verify all return structured validation errors rather than executing injected content.

**Acceptance Scenarios**:

1. **Given** any API field accepting user input, **When** a SQL injection payload is submitted, **Then** the system returns a structured 422 validation error and no SQL is executed from the payload.
2. **Given** a text field whose value is stored and later displayed, **When** an XSS payload is submitted, **Then** the stored/displayed content is escaped and the script does not execute.
3. **Given** any API endpoint, **When** required fields are missing or values exceed stated schema limits, **Then** the request is rejected with HTTP 422 and a structured error envelope.
4. **Given** the full API surface, **When** an automated injection scanner runs, **Then** zero HIGH or CRITICAL SQL-injection or XSS findings are reported.

---

### User Story 3 — Credential & Secret Hygiene (Priority: P1)

As a **security administrator**, I want passwords and PINs stored using strong one-way hashing, application secrets managed externally, and sensitive database fields encrypted at rest, so that a database breach does not expose usable credentials or secrets.

*Covers: US-SEC-002 (At-rest encryption), US-SEC-003 (Password/PIN hashing), US-SEC-004 (Secrets protection)*

**Why this priority**: Credential and secret exposure in a breach is catastrophic and irreversible; strong hashing and secrets management are non-negotiable foundational controls.

**Independent Test**: Verify the stored password field for any account is a recognizable adaptive hash; confirm environment secrets are loaded from external configuration rather than code; directly query the database for an encrypted column and verify it contains ciphertext.

**Acceptance Scenarios**:

1. **Given** a user sets a password or PIN, **When** the value is persisted, **Then** only a one-way adaptive hash is stored — the plaintext never appears in the database or any log.
2. **Given** any application secret (database credential, signing key, API key), **When** deployed to any environment, **Then** the secret is loaded from the approved external secrets store, not from committed source code or unencrypted config files.
3. **Given** database columns designated for at-rest encryption, **When** those columns are read directly from the database without application-layer decryption, **Then** the values are ciphertext.
4. **Given** a secret rotation event, **When** the new secret is deployed, **Then** the service continues without downtime and no old plaintext value remains in the running process or logs.

---

### User Story 4 — Data Masking & PII Protection (Priority: P2)

As a **customer** and **compliance officer**, I want sensitive personal and financial data to be masked in API responses and log output, so that support staff, developers, and log consumers cannot access full account numbers, card numbers, or identity document values.

*Covers: US-SEC-012 (Sensitive information masking), US-SEC-013 (PII protection)*

**Why this priority**: PII exposure in logs and API responses is a regulatory violation (POPIA/GDPR); masking must be pervasive before production launch.

**Independent Test**: Call the customer profile, wallet, and transaction list endpoints; verify account numbers show only the last 4 digits; scrape application logs and confirm no full PAN, national ID, or PIN appears in any log line.

**Acceptance Scenarios**:

1. **Given** an API response containing account or card numbers, **When** the response is inspected, **Then** only the last 4 digits are shown; the remaining digits are replaced with a masking character.
2. **Given** application log output across all modules, **When** inspected by a security reviewer, **Then** no full PAN, national ID, PIN, OTP, or password value appears in any log line.
3. **Given** a PII data access request (right of access), **When** processed, **Then** only the minimum required data is returned and the access is audit-logged with actor, timestamp, and fields accessed.
4. **Given** the configured retention period for a PII record has elapsed, **When** the retention cleanup job runs, **Then** the data is deleted or irreversibly anonymized.

---

### User Story 5 — Brute-Force & Rate-Limit Hardening (Priority: P2)

As a **security administrator**, I want authentication endpoints to enforce attempt limits and rate limiting to apply across the entire API surface, so that credential-stuffing and denial-of-service attacks are automatically blocked.

*Covers: US-SEC-005 (API rate limiting), US-SEC-006 (Brute-force protection)*

**Why this priority**: Brute-force and rate-limit bypasses enable account takeover and service disruption; these controls protect every user-facing feature.

**Independent Test**: Simulate 6 rapid login failures for a single account and verify the account is locked; simulate a burst of requests exceeding the configured per-IP limit and verify HTTP 429 is returned with Retry-After.

**Acceptance Scenarios**:

1. **Given** a user account, **When** 5 consecutive failed authentication attempts occur within a 15-minute sliding window, **Then** the account is temporarily locked and further attempts are rejected until the lockout period expires.
2. **Given** any API endpoint, **When** request volume from a single IP exceeds the configured per-IP rate limit, **Then** HTTP 429 is returned with a `Retry-After` header.
3. **Given** a rate-limit or brute-force threshold configuration change, **When** the administrator saves it, **Then** the new threshold takes effect without a code deployment.
4. **Given** a locked account where the lockout period has expired, **When** the user attempts authentication with valid credentials, **Then** access is granted normally.

---

### User Story 6 — Security Audit Logging (Priority: P2)

As a **compliance officer** and **security administrator**, I want all security-relevant events (authentication, privilege escalation, configuration changes, PII access) to produce tamper-evident audit records, so that forensic investigation of any incident is possible.

*Covers: US-SEC-014 (Security audit logging)*

**Why this priority**: Audit logging is required by financial regulation; without complete coverage, no incident response or compliance evidence is possible. E23 established the audit framework; this story verifies completeness and adds security-specific event types not yet captured.

**Independent Test**: Trigger each security-relevant event type — failed login, permission denied, role assignment, config change, PII field access — and verify each produces an immutable audit record with the required fields.

**Acceptance Scenarios**:

1. **Given** a failed authentication attempt, **When** it occurs, **Then** an audit event is recorded with actor identity (or anonymous), timestamp, source IP, endpoint, and failure reason.
2. **Given** a privileged action (role assignment, permission change, configuration update, data export), **When** performed, **Then** an audit event records actor, action type, affected resource, before/after values, and timestamp.
3. **Given** a PII field access event, **When** it occurs, **Then** the audit record includes which fields were accessed, the accessing actor, declared purpose (where applicable), and timestamp.
4. **Given** existing audit records, **When** any application-level role attempts to modify or delete them, **Then** the operation is rejected; records are immutable through the application API.

---

### User Story 7 — Vulnerability Scanning & Pen Test Readiness (Priority: P3)

As a **security administrator** and **release manager**, I want automated vulnerability scanning to run on every deployment and a penetration test readiness checklist to exist, so that known vulnerabilities are caught before production release.

*Covers: US-SEC-015 (Vulnerability scanning), US-SEC-016 (Penetration testing)*

**Why this priority**: Continuous scanning and pen-test readiness validate that all the above controls are effective end-to-end; required by the release governance framework before production launch.

**Independent Test**: Trigger a CI/CD pipeline run and verify a vulnerability scan executes automatically; confirm the pen-test checklist covers all applicable OWASP Top 10 categories.

**Acceptance Scenarios**:

1. **Given** a code change is committed to a release branch, **When** the CI/CD pipeline runs, **Then** an automated vulnerability scan executes and fails the build if any HIGH or CRITICAL finding is unresolved.
2. **Given** a penetration test readiness checklist, **When** reviewed before a major release, **Then** it covers all OWASP Top 10 categories applicable to a financial services API.
3. **Given** a HIGH or CRITICAL finding from an automated scan, **When** triaged, **Then** a remediation task is created and tracked to closure before the affected release ships; suppression requires documented justification and is itself audit-logged.
4. **Given** a security findings dashboard in the admin portal, **When** a security administrator views it, **Then** current open findings by severity and status are visible. *Note: time-series trend tracking is descoped from this epic; deferred to a future analytics release.*

---

### Edge Cases

- What if a legitimate user triggers brute-force lockout accidentally? Unlock via verified OTP recovery path — no admin bypass without documented justification.
- How does at-rest field encryption handle key rotation without downtime? New writes use the new key; existing ciphertexts are re-encrypted in a background migration batch with zero downtime.
- What if a new PII field is added to a model without being flagged for masking? Masking must be enforced via a model-level annotation or decorator, not an ad-hoc case list, so unlabelled fields fail closed.
- How does the system behave if the secrets manager is unavailable at startup? Fail closed — the application MUST NOT start without its required secrets; degraded-security operation is prohibited.
- How are vulnerability scan false positives managed? Suppressed with a documented justification review; the suppression action is itself audit-logged.

## Requirements *(mandatory)*

### Functional Requirements

**Transport & HTTP Security**

- **FR-001**: The system MUST enforce TLS 1.2+ on all API and admin portal endpoints; plain-HTTP connections MUST be rejected or permanently redirected to HTTPS.
- **FR-002**: All HTTP responses MUST include `Strict-Transport-Security` (min-age ≥ 31 536 000 s), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, and a `Content-Security-Policy` header.
- **FR-003**: State-changing requests from browser-based admin portal clients MUST be protected against CSRF. **Implementation note**: the admin portal uses short-lived JWT bearer tokens (`Authorization: Bearer …`) via the `identity.auth` middleware; browsers cannot auto-attach bearer tokens cross-origin, making the API inherently CSRF-resistant without a separate token. FR-003 is satisfied by this bearer-token architecture. If any admin portal route migrates to cookie-based sessions, `SameSite=Strict` MUST be set on the session cookie. See `research.md` Decision 2.

**Injection Prevention & Input Validation**

- **FR-004**: All database queries MUST use parameterized statements or a query builder that prevents raw SQL interpolation of user input.
- **FR-005**: All user-supplied input MUST be validated against defined schemas at API entry points; invalid input MUST be rejected with a structured HTTP 422 response before reaching domain logic.
- **FR-006**: All user-supplied content rendered in any web interface MUST be output-encoded to prevent XSS.
- **FR-007**: An automated injection/XSS scanner MUST be integrated into the CI/CD pipeline; HIGH or CRITICAL findings MUST block the build.

**Credential & Secret Hygiene**

- **FR-008**: Passwords and PINs MUST be stored as one-way adaptive hash values (bcrypt or Argon2id with approved cost factors); plaintext MUST never be stored or logged.
- **FR-009**: All application secrets (database passwords, signing keys, third-party API keys) MUST be loaded from an approved external secrets store at runtime; secrets MUST NOT be committed to source control or embedded in container images.
- **FR-010**: Database columns containing sensitive payment or identity data that are designated for at-rest encryption MUST use an approved envelope-encryption scheme; encryption keys MUST be managed separately from the data they protect.

**Data Masking & PII Protection**

- **FR-011**: API responses containing account numbers, card numbers, or national identity numbers MUST mask all but the last 4 characters using a consistent masking character (e.g., `*`).
- **FR-012**: Application log output MUST never contain full PAN, national ID, PIN, OTP, or plaintext password values; masking MUST be applied at the logging layer.
- **FR-013**: PII access events (read of personal data fields beyond the immediate subject) MUST produce an audit record containing the accessing actor, timestamp, record identifier, and fields accessed.
- **FR-014**: Personal data MUST be deleted or irreversibly anonymized when the configured retention period for that data category expires; retention periods MUST be centrally configurable without code changes.

**Brute-Force & Rate Limiting**

- **FR-015**: Authentication endpoints MUST enforce a temporary lockout after 5 consecutive failed attempts within a configurable sliding window (default: 15 minutes); the lockout duration MUST be centrally configurable.
- **FR-016**: API rate limiting MUST apply to all public-facing endpoints with per-client and per-IP limits; both limits MUST be centrally configurable without code deployment.
- **FR-017**: Rate-limit and brute-force configuration changes MUST take effect within 60 seconds without requiring a service restart.

**Security Audit Logging**

- **FR-018**: Authentication events (success and failure), privilege-escalation actions, configuration changes, and PII data access MUST each produce an immutable audit record containing: actor identity, action type, affected resource, source IP, UTC timestamp, and outcome.
- **FR-019**: Audit records MUST NOT be modifiable or deletable through any application-level API or role; deletion requires an out-of-band administrative process with its own audit trail.

**Vulnerability Management**

- **FR-020**: An automated vulnerability scanner MUST execute on every CI/CD pipeline run against the deployed application and report findings categorized by severity (CRITICAL, HIGH, MEDIUM, LOW).
- **FR-021**: The CI/CD pipeline MUST block deployment if any HIGH or CRITICAL vulnerability finding is unresolved and un-suppressed.
- **FR-022**: A penetration-test coverage checklist addressing all applicable OWASP Top 10 categories for financial APIs MUST be maintained and reviewed before each major release.
- **FR-023**: An admin security dashboard MUST be accessible to users holding the `security.findings.read` permission, displaying current open vulnerability findings grouped by severity and status; trend-over-time is explicitly out of scope for this epic.

### Key Entities

- **SecurityEvent**: Immutable record of a security-relevant occurrence — type, actor identity, resource, source IP, UTC timestamp, outcome, structured metadata. Append-only.
- **SecretReference**: Pointer to a secret in the external secrets store — name, version, owning module, last-rotated-at. Does not contain the secret value itself.
- **VulnerabilityFinding**: Record from a scanner run — finding ID, severity, affected component, detection timestamp, status (open / suppressed / resolved), suppression justification (if suppressed), resolved-at.
- **BruteForceRecord**: Tracks consecutive failed attempt count and lockout-expiry per credential identity per endpoint; cleared on successful authentication.
- **PiiAccessLog**: Record of a PII field read beyond immediate subject access — accessing actor, record type, record identifier, fields accessed, declared purpose, timestamp. Subset of SecurityEvent.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of API and admin portal responses include all five required security headers (`HSTS`, `CSP`, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`), verified by automated header scanner in CI.
- **SC-002**: Zero HIGH or CRITICAL injection or XSS findings reported by the automated scanner on any release branch.
- **SC-003**: Zero plaintext passwords, PINs, OTPs, or secret values appear in any log line reviewed during QA; verified by automated log-scraping assertion.
- **SC-004**: Account numbers and national IDs returned by all API endpoints show only the last 4 characters; verified by API contract test suite.
- **SC-005**: Account lockout activates within 5 consecutive failed authentication attempts in a 15-minute window; verified by automated security test scenario.
- **SC-006**: API rate limiting returns HTTP 429 at or within the configured threshold; threshold changes take effect within 60 seconds of configuration save, without service restart.
- **SC-007**: 100% of security-relevant event types (login success/failure, role change, config change, PII access, admin action) produce audit records; verified by integration test coverage map.
- **SC-008**: Automated vulnerability scanner integrated in CI/CD; zero un-suppressed HIGH or CRITICAL findings present at the release gate.
- **SC-009**: OWASP Top 10 penetration test checklist exists and is reviewed as a release governance step; all applicable categories have test evidence or a documented, approved exception.

## Assumptions

- TLS termination occurs at the reverse proxy / load balancer tier; the application service does not manage TLS certificates directly.
- "At-rest encryption" refers to application-level field encryption (not full-disk encryption, which is managed by infrastructure); the approved scheme is AES-256-CBC with HMAC-SHA256 authentication, as implemented by Laravel's built-in `Encrypter` (used via the `'encrypted'` Eloquent cast). This provides authenticated encryption equivalent in security to AES-256-GCM for field-level storage. See `research.md` Decision 12.
- Password hashing with bcrypt is already implemented for user accounts (E01) and API client secrets (E25); this epic verifies completeness across all credential types and adds PIN hashing where not yet present.
- API gateway rate limiting is implemented in E25 (DualRateLimitMiddleware); this epic adds brute-force-specific authentication-endpoint lockout and verifies full endpoint coverage.
- Audit logging infrastructure is established in E23; this epic adds security-specific event categories not yet captured and verifies completeness against FR-018.
- The specific external secrets management solution (HashiCorp Vault, AWS Secrets Manager, etc.) is TBD at infrastructure level; this epic defines the integration contract, not the vendor.
- CSRF protection applies to the Next.js admin portal and any browser-facing form submissions; pure API clients (M2M, mobile apps) are exempt.
- PII retention periods and data categories requiring at-rest encryption are governed by the Platform Configuration service; the specific values are not hard-coded in this epic.
- Vulnerability scanning tooling selection is a CI/CD infrastructure concern (TBD); this epic defines the integration contract (scan-on-commit, fail on HIGH/CRITICAL) rather than the specific tool.
- US-SEC-016 (penetration testing) produces a readiness checklist and test evidence within this epic; the actual third-party pen test engagement is a separate release-gate process outside the codebase.
