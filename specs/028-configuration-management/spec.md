# Feature Specification: E28 — Configuration Management

**Feature Branch**: `028-configuration-management`

**Created**: 2026-10-02

**Status**: Draft

**Input**: User description: "E28 configuration-management"

## User Scenarios & Testing *(mandatory)*

<!--
  E28 is the administration surface for platform configuration.
  The platform configuration service backend exists from earlier epics; E28 adds:
  — typed domain-specific management (financial, operational, geographic policies)
  — immutable version history for every change
  — maker-checker approval workflow for critical/financial changes
  — admin portal UI for each configuration domain

  Original story IDs preserved in parentheses for traceability.
-->

### User Story 1 — Financial Policy Administration (Priority: P1)

As an **operations administrator**, I want to view and update financial policy configurations — including per-operation transaction limits, wallet balance limits, fee structures, and commission structures — through a secure admin interface, so that the platform can respond to business or regulatory requirements without a code deployment.

*Covers: US-CONFIG-001 (transaction limits), US-CONFIG-002 (wallet limits), US-CONFIG-003 (fees), US-CONFIG-004 (commission)*

**Why this priority**: Financial policy values (limits, fees, commissions) directly determine customer experience and regulatory compliance; they must be adjustable without engineering involvement, but changes must be controlled and audited because incorrect values can cause financial harm.

**Independent Test**: Update a transaction limit to a new value; verify the change enters a pending-approval state; have a second administrator approve it; confirm the new limit is active within 60 seconds and that a version record exists capturing the previous and new values with both actors' identities.

**Acceptance Scenarios**:

1. **Given** an administrator with `configuration.write` permission, **When** they submit a change to the maximum single-transaction limit for a given operation type, **Then** the change is saved as a pending change request with the submitter's identity and submitted_at timestamp, and the live configuration is not yet changed.
2. **Given** a pending financial policy change request, **When** a different administrator with `configuration.approve` permission reviews and approves it, **Then** the new value becomes active within 60 seconds, and an immutable version record is created with both the maker and checker identities.
3. **Given** a pending financial policy change request, **When** the same administrator who submitted it attempts to approve it, **Then** the approval is rejected with a clear message indicating the checker must be a different person.
4. **Given** a completed (approved or rejected) financial policy change request, **When** the history for that configuration key is viewed, **Then** the record shows previous value, new value, submitter identity, reviewer identity, decision, and effective date.
5. **Given** an administrator with only `configuration.read` permission, **When** they attempt to submit a change to any financial policy, **Then** the request is rejected with HTTP 403 before any change is recorded.

---

### User Story 2 — Operational Policy Administration (Priority: P1)

As an **operations administrator**, I want to view and update operational policy configurations — KYC verification rules, risk evaluation rules, and notification templates — so that the platform can adapt its compliance posture and customer communication without a code deployment.

*Covers: US-CONFIG-005 (KYC rules), US-CONFIG-006 (risk rules), US-CONFIG-007 (notification templates)*

**Why this priority**: KYC rules and risk thresholds have direct regulatory and fraud-prevention impact; notification templates affect every customer-facing communication. Both must be configurable and audited.

**Independent Test**: Update a KYC document-requirement rule; have a checker approve it; verify the KYC service reads the new rule within 60 seconds. Update a notification template; verify the updated template text appears in the next triggered notification.

**Acceptance Scenarios**:

1. **Given** an administrator with `configuration.write` permission, **When** they submit a change to a KYC verification rule (e.g., raising the re-verification threshold), **Then** the change enters the maker-checker workflow and does not take effect until approved by a different administrator.
2. **Given** a pending KYC rule change, **When** a risk event occurs between submission and approval, **Then** the platform continues to use the currently active (pre-change) rule until the approval is processed.
3. **Given** an administrator with `configuration.write` permission, **When** they submit an update to a notification template with a missing required variable placeholder, **Then** the submission is rejected at input time with a descriptive validation error before entering the approval queue.
4. **Given** a risk rule change approved by a checker, **When** the risk evaluation service is queried for the next transaction, **Then** it applies the updated scoring weights without a service restart.
5. **Given** a history query for a notification template, **When** filtered by date range, **Then** only version records within that range are returned, ordered newest-first.

---

### User Story 3 — Geographic Configuration Administration (Priority: P2)

As an **operations administrator**, I want to manage the list of supported currencies and supported countries — activating, deactivating, or modifying their properties — so that the platform can expand to new markets or restrict operations in specific regions without engineering involvement.

*Covers: US-CONFIG-008 (supported currencies), US-CONFIG-009 (supported countries)*

**Why this priority**: Geographic configuration is lower-risk than financial policy but still requires controlled change management; expanding to a new currency or country without approval could introduce compliance or operational issues.

**Independent Test**: Activate a new currency; have a checker approve it; verify it appears in the list of supported currencies returned to customers within 60 seconds.

**Acceptance Scenarios**:

1. **Given** an administrator with `configuration.write` permission, **When** they activate a new currency (providing ISO code, precision, display format), **Then** the change enters the maker-checker workflow and the currency is not yet visible to customers.
2. **Given** an approved currency activation, **When** a customer attempts to open a wallet in that currency, **Then** the wallet creation succeeds using the newly active currency configuration.
3. **Given** an administrator disabling a supported country, **When** the change is approved, **Then** new onboarding for customers from that country is blocked, but existing customers from that country are not retroactively affected.
4. **Given** an administrator submitting a duplicate currency code (one already active), **When** the submission is validated, **Then** it is rejected at submission time with a clear error — no duplicate currency records are created.
5. **Given** a deactivated currency, **When** an administrator queries the supported currencies list with `include_inactive=true`, **Then** the deactivated currency appears with its deactivated_at timestamp and the identity of the actor who deactivated it.

---

### User Story 4 — Configuration Version History (Priority: P1)

As an **operations administrator** and **compliance officer**, I want to view a complete, immutable history of every configuration change — including the value before and after, who made the change, who approved it, and when it took effect — so that I can audit any configuration at any point in time and demonstrate regulatory compliance.

*Covers: US-CONFIG-010 (maintain configuration version history)*

**Why this priority**: Regulators require demonstrable evidence of who changed what and when for all financial policy parameters; immutable version history is a compliance prerequisite.

**Independent Test**: Make a sequence of 3 changes to the same configuration key; retrieve its full history; verify all 3 versions appear in order with correct previous/new values, maker, checker, and effective timestamps.

**Acceptance Scenarios**:

1. **Given** any configuration change that is approved and activated, **When** the version history for that configuration key is queried, **Then** an immutable record exists with: key name, domain, previous value, new value, maker identity, checker identity, decision (approved), and effective_at timestamp.
2. **Given** a configuration change request that is rejected by the checker, **When** the version history is queried, **Then** a record exists with: key name, domain, proposed value, previous value unchanged, maker identity, checker identity, decision (rejected), rejection reason, and decided_at timestamp.
3. **Given** a version history record that was created more than 7 years ago, **When** a retention cleanup runs, **Then** the record is NOT purged — configuration version records are retained indefinitely for regulatory auditability.
4. **Given** a query for the version history of a configuration key, **When** there are more than 50 historical versions, **Then** the response is paginated and all versions are retrievable through successive pages.
5. **Given** an administrator with only `configuration.read` permission, **When** they query the version history, **Then** they receive the full history without being able to submit changes or approvals.

---

### User Story 5 — Maker-Checker Approval Workflow (Priority: P1)

As a **platform operator**, I want every critical configuration change to require explicit approval by a second authorized person before activation, and I want both the submission and approval to be immediately visible and audited, so that no single operator can unilaterally change financial policies and any approval action is fully attributable.

*Covers: US-CONFIG-011 (require approval for critical configuration changes)*

**Why this priority**: The maker-checker principle is a mandatory internal control for financial systems; a single point of approval failure exposes the platform to fraud, regulatory sanction, and financial loss.

**Independent Test**: Force a concurrent dual-approval race: two checkers attempt to approve the same pending request simultaneously; verify exactly one approval succeeds and the other receives a conflict response; verify the configuration activates exactly once.

**Acceptance Scenarios**:

1. **Given** a pending configuration change request, **When** two checkers simultaneously attempt to approve it, **Then** exactly one approval succeeds and activates the configuration; the second attempt receives a conflict response indicating the request has already been processed.
2. **Given** an administrator with the `configuration.approve` permission but who is the same person as the maker, **When** they attempt to approve their own change request, **Then** the approval is rejected with a message clearly stating self-approval is prohibited.
3. **Given** a pending change request that has been waiting for approval beyond the configured escalation threshold (default: 24 hours), **When** the escalation check runs, **Then** an alert-level audit event is emitted identifying the pending request and the time elapsed.
4. **Given** a maker who submitted a pending change request, **When** they withdraw the request before any checker action, **Then** the request status is set to withdrawn, the live configuration is unchanged, and a version record is created documenting the withdrawal.
5. **Given** a configuration change request in any state (pending, approved, rejected, withdrawn), **When** any actor queries the approval status, **Then** the response includes the current state, the maker's identity, the checker's identity (if any action was taken), the timestamp of the latest state transition, and a full decision trail.
6. **Given** an administrator with `configuration.approve` permission, **When** they reject a pending change request, **Then** the maker is notified (via platform notification), the change request status is set to rejected, and the live configuration remains unchanged.

---

### Edge Cases

- What if a configuration change is approved but the platform is mid-restart at the moment of activation? The activation must be idempotent — the approval record must be checked at startup and any approved-but-not-yet-active changes applied on service boot.
- What if the checker who approved a change has their permissions revoked between approval and activation? Once approved and recorded, the activation proceeds; permission revocation is prospective, not retroactive.
- What if a configuration key schema changes (e.g., a new required field is added) while a pending change using the old schema is awaiting approval? The change request is validated against the current schema at activation time; if it fails validation, the request is moved to a failed state and the maker is notified.
- What if a pending change request for a configuration key already exists when a second administrator tries to submit another change to the same key? The second submission must be blocked until the first request is resolved (approved, rejected, or withdrawn) — only one pending change per key is allowed at a time.
- What if the platform has no administrators with `configuration.approve` permission available (e.g., everyone is on leave)? The system should alert (audit event + notification to super-admin) when a change request exceeds the escalation threshold without a checker action.
- What if a configuration domain has never been changed and has no version history? Queries for its history must return an empty list with a 200 response, not an error.

## Requirements *(mandatory)*

### Functional Requirements

**Configuration Domain Management**

- **FR-001**: The platform MUST expose administrative read and write access for each of the following configuration domains: financial policy (transaction limits, wallet limits, fee structures, commission structures), operational policy (KYC verification rules, risk evaluation rules, notification templates), and geographic configuration (supported currencies, supported countries).
- **FR-002**: All configuration domain reads MUST be accessible to any administrator holding `configuration.read` permission; writes MUST require `configuration.write` permission.
- **FR-003**: Configuration values MUST be validated against domain-specific schemas at submission time; invalid values MUST be rejected before the change request is created, with a descriptive validation error.
- **FR-004**: Configuration values MUST NOT be hard-coded; all domain-specific values MUST be retrieved from the configuration service at runtime.

**Maker-Checker Approval Workflow**

- **FR-005**: Every configuration change — for all domains defined in this epic — MUST enter a maker-checker approval workflow upon submission; the submitted change MUST NOT take effect until a different authorized administrator approves it.
- **FR-006**: The checker MUST hold `configuration.approve` permission; the checker MUST NOT be the same individual as the maker; any attempt by the maker to approve their own change request MUST be rejected with HTTP 403.
- **FR-007**: Only one pending change request per configuration key is permitted at any time; submitting a second change for a key with an existing pending request MUST be rejected with HTTP 409 until the first request is resolved.
- **FR-008**: A maker MUST be able to withdraw their own pending change request before any checker action; withdrawal sets the request to withdrawn status without affecting the live configuration.
- **FR-009**: If two checkers simultaneously attempt to approve the same change request, exactly one approval MUST succeed; the second attempt MUST receive HTTP 409 Conflict.
- **FR-010**: A pending change request that remains unapproved beyond a configurable escalation threshold (default: 24 hours) MUST trigger an alert-level audit event.

**Configuration Activation**

- **FR-011**: An approved configuration change MUST become active within 60 seconds of approval without requiring a service restart.
- **FR-012**: Activation MUST be idempotent; if the platform restarts between approval and activation, the activation MUST be applied on boot without double-applying.
- **FR-013**: An approved change MUST be re-validated against the current domain schema at activation time; if validation fails at activation, the change is moved to a failed state and the maker is notified via platform notification.

**Configuration Version History**

- **FR-014**: Every state transition for a configuration change request — submitted, approved, rejected, withdrawn, activated, failed — MUST create an immutable version record that cannot be modified or deleted after creation.
- **FR-015**: Each version record MUST contain: version ID, configuration key, domain, previous value, proposed/new value, maker identity, checker identity (if any), decision, decision reason (for rejections/withdrawals), effective_at, and created_at.
- **FR-016**: Configuration version records MUST be retained indefinitely; no scheduled cleanup job may purge these records.
- **FR-017**: The version history for any configuration key MUST be queryable by domain, date range, maker identity, and checker identity; results MUST be paginated with at most 50 records per page by default.

**Audit & Security**

- **FR-018**: Every read and write action on any configuration domain MUST be audit-logged with: actor identity, action type, configuration key, domain, correlation ID, and timestamp.
- **FR-019**: All configuration write and approve actions MUST require an active authenticated admin session; unauthenticated requests MUST be rejected with HTTP 401.

### Key Entities

- **ConfigurationKey**: A named, typed configuration parameter within a domain — key name, domain (financial_policy / operational_policy / geographic), data type, validation schema, display name, description, current active value, last_modified_at.
- **ConfigurationChangeRequest**: A pending change to a configuration key — request ID, configuration key reference, proposed value, maker identity, submitted_at, status (pending_approval / approved / rejected / withdrawn / failed), rejection reason (for rejected/withdrawn).
- **ConfigurationVersion**: An immutable record of a state transition — version ID, change request reference, configuration key, domain, previous value, proposed value, maker identity, checker identity, decision, decision reason, effective_at, created_at. Append-only; never mutated after creation.
- **ConfigurationApproval**: The explicit decision record by a checker — approval ID, change request reference, checker identity, decision (approved / rejected), reason, decided_at.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of configuration change requests across all domains in this epic pass through the maker-checker workflow before activation; zero configuration changes take effect without a checker's explicit approval from a different person.
- **SC-002**: Approved configuration changes become active within 60 seconds of approval in all cases; verified by automated timing assertion.
- **SC-003**: 100% of configuration change state transitions (submitted, approved, rejected, withdrawn, activated, failed) produce an immutable version record; zero transitions are unrecorded.
- **SC-004**: The complete version history for any configuration key with up to 100 records is returned in under 2 seconds.
- **SC-005**: 100% of invalid configuration submissions (wrong data type, out of permitted range, schema violation, missing required field) are rejected at submission time with a descriptive error; zero invalid values enter the pending approval queue.
- **SC-006**: Zero concurrent dual-approval races succeed: in all concurrency tests, exactly one approval activates the configuration and the second receives a 409 Conflict response.
- **SC-007**: All configuration read and write operations are audit-logged; a compliance officer can reconstruct the complete change history for any configuration key at any point in time.

## Assumptions

- The Platform Configuration service backend (`ConfigurationService`) is already operational from earlier epics (Platform Foundation, E27); E28 adds the admin management surface, domain-specific namespaces, approval workflow, and version history — not a new underlying key-value store.
- The role and permission system from E04 is in place; the new permissions `configuration.read`, `configuration.write`, and `configuration.approve` are seeded as part of this epic.
- Configuration changes that affect actively running financial operations (e.g., reducing a transaction limit mid-day) take effect for future transactions only; in-progress transactions at the moment of activation are not retroactively affected.
- Notification template changes require maker-checker approval for consistency with the rest of this epic, even though they are not strictly financial; this provides a uniform governance model.
- The `configuration.approve` permission is a distinct permission from `configuration.write`; all checkers must hold both `configuration.read` and `configuration.approve`; makers need `configuration.write`.
- Geographic configuration changes (currencies, countries) that disable an existing supported option do not retroactively affect existing customer wallets or in-flight transactions; the restriction applies to future operations only.
- Configuration domain schemas (the allowed fields and validation rules for each domain) are defined in code by the development team and are not themselves user-configurable through this epic.
