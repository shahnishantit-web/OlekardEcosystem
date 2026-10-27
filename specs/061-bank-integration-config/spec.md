# Feature Specification: P12 — Dynamic Bank Integration Configuration and Certification

**Feature Branch**: `061-bank-integration-config`

**Created**: 2026-10-24

**Status**: Draft

**Input**: P12 — Dynamic Bank Integration Configuration and Certification. Predecessors: P10, P11.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Bank and Account Registration (Priority: P1)

An authorised platform administrator registers a new bank and one or more integration accounts. Once an account is in an approved state it becomes eligible for selection when initiating payments.

**Why this priority**: Nothing else in P12 is possible until a bank and at least one account exist in the system. This story provides the foundational data model that all other stories depend on.

**Independent Test**: Can be fully tested by adding a bank, adding an integration account, completing the approval lifecycle to Active, and verifying the account appears in eligibility queries — without any real-bank connectivity.

**Acceptance Scenarios**:

1. **Given** no bank exists for code "FNBZ", **When** a Platform Configuration Admin submits Add Bank with code "FNBZ", display name "First National Bank", supported country "BW", **Then** the bank is created in Draft status and appears in the admin bank list.
2. **Given** bank "FNBZ" exists, **When** an admin adds an integration account with environment "sandbox", adapter profile "http-redirect-v1", and merchant reference "TEST-MERCHANT-001", **Then** the account is created in Draft status linked to that bank.
3. **Given** a bank in Draft status, **When** an admin edits its display metadata, **Then** changes are saved and a new metadata version is recorded; existing accounts are not affected.
4. **Given** an integration account in Active status, **When** another admin submits an edit to API operation configuration, **Then** a new Draft version is created rather than modifying the live configuration.
5. **Given** an Active integration account with an in-flight payment attempt, **When** an admin suspends the account, **Then** no new payment intents can be initiated through that account, but existing attempts continue to resolution.

---

### User Story 2 — API Operation Configuration (Priority: P1)

An authorised administrator configures the typed API operation mappings for a bank integration account — covering initiate, status query, cancel, refund, callback, and reconciliation operations — using validated field definitions.

**Why this priority**: Without this, a bank account cannot be connected to any real payment flow. This is the core technical configuration that enables dynamic bank adapters.

**Independent Test**: Can be fully tested using the mock-bank adapter profile: configure operations, save, validate, and verify that the system would dispatch requests with correct field mappings — without a live bank connection.

**Acceptance Scenarios**:

1. **Given** a Draft integration account, **When** an admin configures an "initiate payment" operation with base URL, endpoint path, HTTP method POST, content type JSON, auth profile "bearer-token", request field mappings, and response field mappings, **Then** the configuration is saved and validated against the adapter profile schema.
2. **Given** a configured initiate operation, **When** the system resolves a provider for a payment attempt, **Then** the persisted configuration version and adapter profile version are recorded against the attempt.
3. **Given** a configured status-query operation, **When** an unknown provider status value is received, **Then** the attempt remains unresolved; the system does not default to success.
4. **Given** an admin enters a non-HTTPS base URL in the operation configuration, **Then** the system rejects it with a validation error.
5. **Given** an admin tries to store an executable expression or script in a field mapping, **Then** the system rejects it with a validation error.
6. **Given** payout capability, **When** it is referenced in payment operation config, **Then** the system rejects it; payout must be declared and controlled separately.

---

### User Story 3 — Configuration Lifecycle and Approval (Priority: P1)

Bank integration account configurations move through a controlled lifecycle: Draft → Validated → Sandbox Testing → Evidence Review → Approved → Active. Rejected or change-requested outcomes are recorded. Separate bank verification, configuration approval, technical test status, and production activation.

**Why this priority**: Financial infrastructure changes must be independently reviewed and approved. A configuration that has not been approved must never become the active selection for live payment attempts.

**Independent Test**: Can be fully tested without a real bank by driving a Mock Bank configuration through each lifecycle state using admin APIs, verifying state transitions, and confirming that only Approved configurations become Active.

**Acceptance Scenarios**:

1. **Given** a Draft account configuration, **When** an admin submits it for validation, **Then** the system checks required fields and moves it to Validated if complete, or returns validation errors.
2. **Given** a Validated configuration, **When** sandbox testing is initiated, **Then** status moves to Sandbox Testing and a test run record is created.
3. **Given** a configuration in Sandbox Testing, **When** an admin uploads certification evidence and submits for review, **Then** status moves to Evidence Review.
4. **Given** a configuration in Evidence Review, **When** a second authorised approver approves it, **Then** status moves to Approved; the approver identity and timestamp are recorded.
5. **Given** an Approved configuration, **When** an admin activates it, **Then** status moves to Active and the account becomes eligible for payment selection.
6. **Given** an Approved configuration, **When** a reviewer requests changes, **Then** the configuration returns to Draft with a recorded rejection reason; the previous Active configuration (if any) remains unaffected.
7. **Given** an Active configuration, **When** an admin retires it and there are no outstanding obligations, **Then** status moves to Retired and the account is excluded from all future payment selections.
8. **Given** an Active configuration, **When** a configuration change that affects API credentials or mappings is saved, **Then** a new Draft version is created; the original Active version is unchanged.

---

### User Story 4 — Secure Credential and Secret Management (Priority: P1)

Credentials are stored by reference to the approved secrets manager. Browser responses, mobile payloads, logs, and exports never contain raw credential values. Credential rotation follows a controlled policy that does not disrupt in-flight operations.

**Why this priority**: Financial API credentials are a critical security asset. Exposure or uncontrolled rotation can directly enable fraud or break live payment flows.

**Independent Test**: Can be tested by adding a credential reference, verifying it is masked in all API responses, rotating the reference, and verifying that both in-flight and subsequent attempts resolve correctly.

**Acceptance Scenarios**:

1. **Given** an admin stores a credential for an integration account, **When** the account detail is retrieved via any API endpoint, **Then** the response contains only a masked reference, never the raw credential value.
2. **Given** an Active account with in-flight payment attempts, **When** a credential is rotated to a new secret reference, **Then** the policy records that new attempts use the new reference; attempts that were committed before rotation continue to resolve under their recorded reference context.
3. **Given** a connectivity test is run from the admin panel, **Then** the test does not initiate a real payment transaction or expose credentials in its response payload.
4. **Given** an admin with Payment Configuration role edits an Active account credential, **Then** the edit creates a Draft and requires approval before becoming active; the audit log records the initiating actor.

---

### User Story 5 — Sandbox Testing and Certification Evidence (Priority: P2)

The system supports controlled sandbox connectivity tests and retains certification evidence for each bank configuration. Certification is not inferred from test results alone.

**Why this priority**: Regulatory and operational compliance requires evidence of certification. Without this story, production enablement has no formal gate.

**Independent Test**: Can be tested by running sandbox test scenarios against the Mock Bank adapter, recording evidence, and verifying that the certification evidence record is stored and retrievable; that passing a connectivity test alone does not move the configuration to Approved.

**Acceptance Scenarios**:

1. **Given** a configuration in Sandbox Testing status, **When** an admin triggers a connectivity test, **Then** the system executes a status-query operation (not an initiate), records pass/fail, and does not mark the configuration as Approved.
2. **Given** a successful sandbox test, **When** an admin uploads provider documentation and test evidence files, **Then** the evidence is linked to the configuration version.
3. **Given** a bank with no uploaded certification evidence, **When** an admin attempts to move the configuration to Approved, **Then** the system blocks the transition with a required-evidence error.
4. **Given** two banks using the same adapter profile (e.g. "http-redirect-v1"), **When** each is configured and tested, **Then** consuming domain code (payment initiation) requires no change; only configuration data differs.

---

### User Story 6 — Consumer Payment Options (Priority: P2)

The customer mobile application retrieves eligible payment options from the backend. Options reflect the server-side selection rules: organisation, currency, amount, purpose, and country eligibility. No hard-coded bank list exists in the mobile app.

**Why this priority**: The mobile experience depends on backend-driven option discovery. Without this, the app cannot present newly-approved banks to customers without a mobile release.

**Independent Test**: Can be fully tested by creating an Active bank account, calling the eligible payment options endpoint with matching parameters, and verifying the approved bank label and fee disclosure appear; then disabling the account and confirming it no longer appears.

**Acceptance Scenarios**:

1. **Given** an Active integration account for currency "BWP" and purpose "merchant_payment", **When** the mobile app requests payment options for a BWP merchant payment, **Then** the response includes the bank's approved display label and applicable fees; raw credentials are absent.
2. **Given** a suspended integration account, **When** the mobile app requests payment options, **Then** that option is excluded from the response.
3. **Given** a redirect payment flow, **When** the customer is returned from the provider, **Then** the app queries the authoritative backend status and does not treat the redirect result as financial confirmation.
4. **Given** a newly activated bank option, **When** the mobile app retrieves options, **Then** the new option appears without a mobile app release.

---

### User Story 7 — Disable, Rotation, and Safe Recovery (Priority: P2)

Disabling a bank account blocks new payment attempts while preserving authorised callbacks, refunds, queries, and reconciliation for existing transactions. Credential rotation and retirement follow controlled resolution policies.

**Why this priority**: Operational incidents may require immediate disablement. Without safe disablement, stopping a bank also risks abandoning in-flight transactions.

**Independent Test**: Can be tested by disabling an Active account after creating a payment attempt, confirming new attempts are rejected, and confirming existing attempt callbacks and refunds continue to process.

**Acceptance Scenarios**:

1. **Given** an Active account with a committed payment attempt, **When** an admin disables the account, **Then** new payment intents are rejected with a provider-unavailable error; existing attempts continue to receive callbacks and process refunds.
2. **Given** a Retired account, **When** a callback arrives for a historically committed attempt, **Then** the callback is accepted and processed under the original configuration context.
3. **Given** an account targeted for retirement with outstanding refund obligations, **When** an admin attempts to retire it, **Then** the system blocks retirement until obligations are resolved or an approved resolution route is recorded.

---

### User Story 8 — Admin Panel — Payments Configuration Area (Priority: P2)

The admin portal surfaces bank list, detail, add/edit, account configuration, operation mapping editor, secret references, lifecycle controls, certification evidence, and version history — with full state coverage: loading, empty, denied, pending-approval, and error.

**Why this priority**: Without the admin UI, all lifecycle management requires direct API calls, which is impractical for operations teams.

**Independent Test**: Can be tested by navigating each screen with an authorised admin user, verifying all required states appear, and completing the Add Bank → Configure → Activate flow without errors.

**Acceptance Scenarios**:

1. **Given** a user with Payment Configuration role, **When** they navigate to the Payments Configuration area, **Then** they see a list of banks with status badges.
2. **Given** an Active configuration, **When** an admin clicks Edit on an API operation field, **Then** a draft is created and the editor shows the current values pre-populated.
3. **Given** a configuration pending approval, **When** a second approver opens the detail page, **Then** an Approve/Reject action is available; a first-approver cannot approve their own submission.
4. **Given** a user without Payment Configuration role, **When** they attempt to access any configuration management screen, **Then** they receive a denied/403 response.

---

### Edge Cases

- What happens when a bank's adapter profile version is retired but an existing Active account still references it? The account continues to operate under the recorded version; upgrades require a new Draft with renewed approval.
- How does the system handle a provider returning an undocumented status code? The attempt remains in an unresolved/exception state; operations is alerted; the system never silently treats unknown statuses as success.
- What happens when two concurrent admins submit conflicting edits to the same Draft? The second submit returns a conflict error; the first edit must be resolved before a second can proceed.
- What happens when credential rotation fails mid-operation (e.g. secrets manager is unavailable)? The existing credential reference remains active; rotation is not partially committed; the failure is logged and alerted.
- What if a mobile app is offline when a bank is disabled? The next online request for payment options receives the updated list; there is no client-side cache of bank enablement status.
- Can a bank be hard-deleted? No. Banks and configurations are soft-deleted; historical payment attempts retain their recorded configuration context permanently.

---

## Requirements *(mandatory)*

### Functional Requirements

**Bank and Account Model**

- **FR-001**: The system MUST maintain Bank records with: stable UUID, unique bank code, display name, description, logo reference, supported operating countries, organisation/platform ownership, and lifecycle status.
- **FR-002**: The system MUST maintain Integration Account records linked to a Bank, containing: merchant account reference, organisation scope, environment (sandbox/production), adapter profile identifier and version, secret references (by key name, never raw value), supported currencies and payment methods, and approval/lifecycle status.
- **FR-003**: A bank MAY have multiple integration accounts across different environments, organisations, and adapter profile versions.
- **FR-004**: The system MUST maintain Consumer Payment Option records that link an Active integration account to a display label, payment method, display order, and availability restrictions.

**Integration Profiles**

- **FR-005**: The system MUST maintain a registry of adapter profiles, each with: unique identifier, version, supported auth mechanisms, supported API formats, signing and callback verification methods, supported payment capabilities, and compatible configuration schema.
- **FR-006**: Adding a bank that matches an existing supported adapter profile MUST NOT require changes to consuming domain code.
- **FR-007**: A bank requiring a new authentication protocol, signing algorithm, or payment workflow MUST be implemented as a new reviewed adapter profile; the system MUST NOT allow arbitrary code or scripts to be injected through configuration.

**API Operation Configuration**

- **FR-008**: For each supported operation (initiate, status-query, cancel, full refund, partial refund, callback, reconciliation), the system MUST support configuring: base URL (HTTPS only), endpoint path, HTTP method, content type, non-secret headers, auth profile reference, secret key references, request field mappings (typed), response field mappings (typed), provider transaction reference mapping, idempotency reference mapping, amount and currency representation, success/pending/failure status mappings, timeout, retry policy, callback verification profile, and API version.
- **FR-009**: Payout capability MUST be declared and controlled separately from payment operation configuration.
- **FR-010**: Unknown provider status values MUST NOT default to payment success; they MUST remain unresolved or enter an exception queue.
- **FR-011**: The system MUST reject configuration with non-HTTPS base URLs, executable expressions, uploaded code, or unrestricted signing algorithm identifiers.
- **FR-012**: Request and response field mappings MUST use typed, validated rules; free-text script injection MUST be blocked.

**Configuration Security**

- **FR-013**: Access to bank configuration management MUST be controlled by scoped RBAC (Payment Configuration role); production-activation approval MUST require a second authorised actor distinct from the submitter.
- **FR-014**: All credential values MUST be stored by reference to the approved secrets manager; raw credential values MUST NOT appear in any browser response, mobile payload, log output, audit export, or test result.
- **FR-015**: Configurable operation endpoints MUST be protected against SSRF: only approved HTTPS hosts on allowed ports; access to internal service endpoints and cloud metadata endpoints MUST be blocked.
- **FR-016**: All configuration changes, approvals, activations, credential references, and lifecycle transitions MUST be written to the audit log with actor identity and timestamp.

**Configuration Lifecycle**

- **FR-017**: The configuration lifecycle MUST enforce the states: Draft → Validated → Sandbox Testing → Evidence Review → Approved → Active, with Suspended and Retired terminal-or-recovery states, and Rejected/Changes-Requested recorded outcomes.
- **FR-018**: Bank verification, configuration approval, technical test status, provider certification evidence, and production activation MUST be tracked as separate, independent records.
- **FR-019**: Editing an Active configuration MUST create a new Draft version; the existing Active configuration MUST remain unchanged until the new version is explicitly activated.
- **FR-020**: Configuration changes that affect API credentials, mappings, or security settings MUST require retesting and renewed approval before activation.
- **FR-021**: Bank certification MUST NOT be inferred from a successful connectivity test or mock adapter test alone; explicit evidence submission and independent approval are required.

**Runtime Execution**

- **FR-022**: The system MUST resolve the eligible bank integration account server-side using organisation, module, currency, amount, purpose, and eligibility rules; the client MUST NOT select the provider.
- **FR-023**: Each payment attempt MUST record the resolved bank account ID, configuration version, adapter profile version, and financial operation reference at the time of initiation; this context MUST be immutable after commitment.
- **FR-024**: Credential rotation MUST follow a controlled resolution policy: in-flight attempts retain their recorded credential reference context; rotation does not break outstanding operations.
- **FR-025**: The system MUST maintain financial payment status, accounting status, business fulfilment status, refund status, settlement status, and payout status as separate authoritative states.
- **FR-026**: The system MUST NOT automatically retry through a different bank when the previous attempt may have charged the payer.

**Disable and Retirement**

- **FR-027**: Disabling a bank integration account MUST block new payment intents while preserving all authorised callbacks, status queries, refunds, and reconciliation for existing attempts.
- **FR-028**: Retiring a configuration MUST be blocked by the system if outstanding financial obligations exist and no approved resolution route has been recorded.

**Consumer Mobile Options**

- **FR-029**: A backend endpoint MUST return eligible payment options for a given organisation, currency, amount, and purpose context; the response MUST include display label, applicable fees, and supported next action; it MUST NOT include credentials.
- **FR-030**: New Active bank options MUST appear in the consumer payment options response without a mobile app release.

**Admin Panel**

- **FR-031**: The admin panel MUST expose screens for: bank list, bank detail, add/edit bank metadata, integration account configuration, adapter profile selection, API operation configuration, request/response mapping editor, secret-reference management, capability flags, currency/method restrictions, organisation availability, sandbox testing controls, certification evidence upload, approval/activation, disable/retire controls, configuration version history, and connection health.
- **FR-032**: The admin panel MUST provide full state coverage: loading, empty, denied, conflicting-edit, pending-approval, and failure/error states for all configuration screens.

### Key Entities

- **Bank**: Stable internal entity representing a financial institution — code, display name, countries, ownership, status.
- **IntegrationProfile**: Reviewed adapter implementation with versioned schema — auth mechanisms, capabilities, compatible configuration schema.
- **BankIntegrationAccount**: Links a Bank to a merchant account reference, environment, profile, secrets, currencies, methods, organisation scope, and lifecycle status. Extends `payment_provider_accounts`.
- **ApiOperationConfig**: Typed configuration for a single API operation (initiate, query, cancel, refund, callback, reconciliation) within an account — URLs, headers, mappings, auth profile, timeouts.
- **ConfigurationVersion**: Immutable snapshot of an account's complete API operation configuration at a point in time; referenced by payment attempts.
- **CertificationEvidence**: Documents, test run records, and approval decisions linked to a configuration version.
- **ConsumerPaymentOption**: Display record linking an Active account to a label, method, sort order, and availability rules for the mobile app.
- **SecretReference**: Key name and masked hint pointing to a secret in the approved secrets manager; never stores raw values.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An authorised administrator can add a new bank and complete the full configuration lifecycle to Active in under 30 minutes using the admin panel without engineering assistance.
- **SC-002**: Two banks using the same adapter profile can be configured and made Active without any change to consuming payment domain code (zero code changes required).
- **SC-003**: Disabling a bank account takes effect within 5 seconds; in-flight payment attempts initiated before disablement continue to resolve without interruption.
- **SC-004**: No credential value appears in any API response, log line, or test output across all automated test scenarios.
- **SC-005**: An unknown provider status value results in the payment attempt remaining unresolved in 100% of test cases; it never automatically advances to success.
- **SC-006**: Consumer payment options response reflects a newly-activated bank within one request cycle; no mobile app release is required.
- **SC-007**: Sandbox connectivity tests complete within 10 seconds and produce a recorded pass/fail result without initiating a real payment transaction.
- **SC-008**: All configuration changes, approvals, and lifecycle transitions are retrievable in the audit log within 1 second of occurrence.
- **SC-009**: Configuration endpoint SSRF protection rejects 100% of requests targeting non-HTTPS URLs, internal IP ranges, and cloud metadata endpoints in automated security tests.
- **SC-010**: A second-approver requirement is enforced: 100% of production activation attempts by the original submitter are rejected by the system.

---

## Assumptions

- The existing `payment_providers` and `payment_provider_accounts` tables (from P02) are the implementation target for Bank and BankIntegrationAccount respectively; P12 extends these with additional columns and a lifecycle state machine rather than replacing them.
- The "adapter profile" concept maps to the existing `adapter_identifier` + `adapter_version` fields; P12 adds a profile registry table that formally documents the schema each adapter requires.
- The approved secrets manager is the existing platform secrets service (established in E26 Security Hardening); P12 stores credential references (key names) only.
- SSRF protection is implemented at the application layer (allowlist-based URL validation) consistent with E26; no new network-layer controls are introduced in P12.
- Payout operations are explicitly out of scope for P12 configuration; they are tracked separately in a future payout management package.
- A real external bank certification is not a P12 deliverable; the framework and evidence storage are delivered; actual bank certifications are done through the framework when real bank access and documentation are available.
- The Flutter mobile app payment options screen uses the existing payment initiation flow (P06/P09); P12 adds the options discovery endpoint only.
- Organisation-scoped RBAC uses the existing E04 roles/permissions system; P12 adds a new `payment.configuration` permission scope.
- The admin panel extension builds on the existing E33 Admin Application (Next.js); P12 adds pages under the existing Payments section.
- Two-person approval is enforced via the existing admin authentication context; the system checks that the approver ID differs from the submitter ID.
