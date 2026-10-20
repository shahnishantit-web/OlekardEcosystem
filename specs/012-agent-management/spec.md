# Feature Specification: E12 — Agent Management

**Feature Branch**: `012-agent-management`

**Created**: 2026-09-19

**Status**: Draft

**Input**: User description: "E12 agent management"

## Overview

This epic implements the full lifecycle of wallet ecosystem agents — from multi-step onboarding and admin approval through active operations where agents register customers, initiate KYC, and view permitted customer data. Agents are the primary human channel for customer acquisition and cash-in/cash-out in the ecosystem.

**Scope boundaries:**
- E12 owns: agent registration, agent lifecycle (approve/reject/suspend/reactivate), agent-customer relationship management, permitted customer data views
- **Out of scope for E12**: agent float wallet management (E13), agent commissions (E14), cash-in/cash-out transaction execution (E08/E10), agent mobile app UI (E32), fraud/risk scoring (E18)

---

## User Scenarios & Testing

### User Story 1 — Agent Submits Onboarding Application (Priority: P0)

An individual or business wishing to become an agent initiates an application by providing their basic intent. This creates an `AgentApplication` record in `draft` status and returns a reference for subsequent steps.

**Why this priority**: The entire agent lifecycle depends on a completed application. No agent can be approved without one.

**Independent Test**: Submit a minimal application; verify an `AgentApplication` row in `draft` status is created with a UUID v7 ID and correlation ID returned.

**Acceptance Scenarios**:

1. **Given** an authenticated user with `agent.application.create` permission, **When** they POST to `/v1/agent-applications` with a valid `agent_type` (individual or business), **Then** an application in `draft` status is created and `201` returned with the application ID and status.
2. **Given** an unauthenticated request, **When** POST is sent to `/v1/agent-applications`, **Then** `401 Unauthorized` is returned with no application created.
3. **Given** a duplicate submission using the same `Idempotency-Key`, **When** POST is sent again, **Then** the original application is returned with `200` (idempotent).
4. **Given** a missing required `agent_type` field, **When** POST is sent, **Then** `422` with `VALIDATION_ERROR` is returned and no application is created.

---

### User Story 2 — Agent Captures Personal Information (Priority: P0)

The applicant fills in their personal details (name, national ID, date of birth, contact information, address) on a draft or in-progress application.

**Why this priority**: Personal information is required for KYC verification and agent identity establishment.

**Independent Test**: Update personal information on a `draft` application; verify all fields are persisted and the application moves to `in_progress` if all required sections are filled.

**Acceptance Scenarios**:

1. **Given** a `draft` or `in_progress` AgentApplication owned by the authenticated actor, **When** PATCH is sent to `/v1/agent-applications/{id}/personal-information` with valid fields, **Then** personal fields are persisted and `200` returned.
2. **Given** an application owned by a different agent, **When** PATCH is attempted, **Then** `403 Forbidden` is returned.
3. **Given** an application in `submitted` or terminal status, **When** PATCH is attempted, **Then** `422 APPLICATION_NOT_EDITABLE` is returned.
4. **Given** an invalid national ID format, **When** PATCH is sent, **Then** `422 VALIDATION_ERROR` is returned with field-level details.
5. **Given** valid personal information, **When** submitted, **Then** an audit event `agent.application.personal_info_updated` is emitted with actor and timestamp.

---

### User Story 3 — Agent Captures Business Information (Priority: P0)

For business-type agents, additional business details (trading name, registration number, physical address, tax number) are captured.

**Why this priority**: Required for business KYC/compliance checks. Individual agents may skip or provide minimal business fields.

**Independent Test**: Provide business information on a business-type application; verify fields saved; for individual agent type, verify business fields are optional.

**Acceptance Scenarios**:

1. **Given** a `draft` or `in_progress` application with `agent_type = business`, **When** PATCH is sent to `/v1/agent-applications/{id}/business-information` with a valid registration number and trading name, **Then** fields are persisted and `200` returned.
2. **Given** `agent_type = individual`, **When** business information is submitted with only optional fields, **Then** `200` is returned with partial data accepted.
3. **Given** a duplicate trading name that is already registered by an active agent, **When** submitted, **Then** `422 DUPLICATE_BUSINESS_NAME` is returned.

---

### User Story 4 — Agent Uploads Supporting Documents (Priority: P0)

The applicant uploads identity documents (national ID scan, proof of address, business registration certificate where applicable) required for verification.

**Why this priority**: Documents are mandatory for compliance and KYC approval.

**Independent Test**: Upload a valid national ID document; verify a `AgentDocument` record is created with document type, storage reference, and `pending_review` status.

**Acceptance Scenarios**:

1. **Given** a `draft` or `in_progress` application, **When** POST is sent to `/v1/agent-applications/{id}/documents` with a valid document type and file, **Then** the document is stored, a record created, and `201` returned with the document ID.
2. **Given** an unsupported document format (e.g., `.exe`), **When** POST is sent, **Then** `422 INVALID_DOCUMENT_FORMAT` is returned.
3. **Given** a document exceeding the configured size limit, **When** POST is sent, **Then** `422 DOCUMENT_TOO_LARGE` is returned.
4. **Given** the same document type uploaded twice for the same application, **When** POST is sent, **Then** the new document supersedes the previous one (or returns the existing if idempotency key matches).
5. **Given** valid documents uploaded, **When** stored, **Then** `agent.application.document_uploaded` audit event is emitted.
6. **Given** a document that fails the platform virus scan, **When** POST is sent to `/v1/agent-applications/{id}/documents`, **Then** the document is quarantined (not persisted), `422 DOCUMENT_VIRUS_DETECTED` is returned, and an `agent.application.document_rejected` audit event is emitted with the document ID, rejection reason `VIRUS_SCAN_FAILED`, and system actor.

---

### User Story 5 — Agent KYC Verification (Priority: P0)

Once all required sections are complete, the applicant (or admin) triggers the KYC verification step. The system submits the application to the configured KYC provider (TBD) and moves the application to `kyc_in_progress`.

**Why this priority**: KYC must pass before an application can be approved.

**Independent Test**: Trigger KYC on a complete application; verify status changes to `kyc_in_progress`; simulate a KYC provider callback with pass/fail; verify status transitions correctly.

**Acceptance Scenarios**:

1. **Given** an application with all required sections complete, **When** POST is sent to `/v1/agent-applications/{id}/kyc`, **Then** KYC is initiated, status moves to `kyc_in_progress`, and `202 Accepted` is returned.
2. **Given** an incomplete application (missing personal info or documents), **When** KYC is triggered, **Then** `422 APPLICATION_INCOMPLETE` is returned with missing sections listed.
3. **Given** the KYC provider returns a pass result (via callback), **When** processed, **Then** application moves to `kyc_passed` and an audit event is emitted.
4. **Given** the KYC provider returns a fail result, **When** processed, **Then** application moves to `kyc_failed` with the failure reason recorded.
5. **Given** the KYC provider is unavailable, **When** timeout occurs, **Then** status remains `kyc_in_progress` and a retry is scheduled per configured retry policy.

---

### User Story 6 — Admin Approves Agent Application (Priority: P0)

An authorized admin reviews a `kyc_passed` application and approves it. Approval creates an active `Agent` record, provisions the agent's wallet (via E08/E13), and grants the agent role.

**Why this priority**: Approval is the gateway to agents becoming operational.

**Independent Test**: Approve a `kyc_passed` application; verify: Agent record created with `active` status, AgentApplication moves to `approved`, audit log has `agent.approved` event, agent role granted.

**Acceptance Scenarios**:

1. **Given** a `kyc_passed` or `under_review` application, **When** an admin with `agent.approve` permission POSTs to `/v1/agent-applications/{id}/approve` with a valid `Idempotency-Key`, **Then** the agent is approved, Agent record is created with `active` status, and `200` is returned.
2. **Given** `require_maker_checker = true`, **When** the same admin who initiated the application attempts approval, **Then** `422 MAKER_CHECKER_VIOLATION` is returned.
3. **Given** an application not in `kyc_passed` status, **When** approval is attempted, **Then** `422 APPLICATION_NOT_APPROVABLE` is returned.
4. **Given** a duplicate approval using the same `Idempotency-Key`, **When** POST is sent again, **Then** the existing agent record is returned idempotently (`200`).
5. **Given** a successful approval, **When** processed, **Then** `agent.approved` audit event is emitted and a notification is triggered (E17).

---

### User Story 7 — Admin Rejects Agent Application (Priority: P0)

An authorized admin reviews an application and rejects it with a mandatory reason. The application moves to `rejected` terminal status.

**Why this priority**: Rejection closes the application and prevents unauthorized agent operation.

**Independent Test**: Reject a `kyc_passed` or `under_review` application with a reason; verify application status = `rejected`, rejection reason saved, audit event emitted.

**Acceptance Scenarios**:

1. **Given** an application in `kyc_passed` or `under_review` status, **When** admin POSTs to `/v1/agent-applications/{id}/reject` with a reason, **Then** application moves to `rejected` and `200` returned.
2. **Given** missing rejection reason, **When** POST is sent, **Then** `422 VALIDATION_ERROR` is returned.
3. **Given** an already-rejected or approved application, **When** rejection is attempted, **Then** `422 APPLICATION_IN_TERMINAL_STATUS` is returned.
4. **Given** a rejection, **When** processed, **Then** `agent.rejected` audit event is emitted with reason and actor ID.

---

### User Story 8 — Admin Suspends Active Agent (Priority: P0)

An authorized admin suspends an active agent, preventing them from performing operations. The suspension records a reason and is auditable. Maker-checker enforcement is configurable.

**Why this priority**: Suspension is a critical risk and compliance control.

**Independent Test**: Suspend an `active` agent with a reason; verify Agent status = `suspended`, existing customer registrations remain linked, and audit log records the suspension.

**Acceptance Scenarios**:

1. **Given** an `active` agent, **When** admin with `agent.suspend` permission POSTs to `/v1/agents/{id}/suspend` with a reason, **Then** agent status changes to `suspended` and `200` returned.
2. **Given** a suspended or rejected agent, **When** suspension is attempted again, **Then** `422 AGENT_NOT_ACTIVE` is returned.
3. **Given** `require_maker_checker = true`, **When** the same admin who activated the account attempts suspension, **Then** `422 MAKER_CHECKER_VIOLATION` is returned.
4. **Given** a suspension, **When** processed, **Then** `agent.suspended` audit event is emitted and the agent's sessions are invalidated.

---

### User Story 9 — Admin Reactivates Suspended Agent (Priority: P1)

An authorized admin reactivates a `suspended` agent, restoring operational access.

**Why this priority**: P1 since suspension is necessary before reactivation; reactivation is less frequent and the ecosystem can function without it for an initial release.

**Independent Test**: Reactivate a `suspended` agent; verify Agent status = `active`, audit event emitted.

**Acceptance Scenarios**:

1. **Given** a `suspended` agent, **When** admin with `agent.reactivate` permission POSTs to `/v1/agents/{id}/reactivate`, **Then** agent status changes to `active` and `200` returned.
2. **Given** an already-active or rejected agent, **When** reactivation is attempted, **Then** `422 AGENT_NOT_SUSPENDED` is returned.
3. **Given** a successful reactivation, **When** processed, **Then** `agent.reactivated` audit event is emitted.
4. **Given** a duplicate `Idempotency-Key` for reactivation, **When** POST is sent again, **Then** the existing Agent in `active` status is returned (`200`) with no additional `AgentStatusHistory` entry created.

---

### User Story 10 — Agent Searches for a Customer (Priority: P0)

An active agent searches for an existing customer by phone, national ID, or name to verify customer existence before initiating a transaction or registering a new profile.

**Why this priority**: Search is the prerequisite for all agent-customer operations.

**Independent Test**: Search by phone number; verify matching customer records returned; verify no PII beyond name/masked ID/phone is exposed to the agent.

**Acceptance Scenarios**:

1. **Given** an active agent with `agent.customer.search` permission, **When** GET is sent to `/v1/agents/{id}/customers?search={phone}`, **Then** matching customers are returned with limited PII (name, masked ID, account status).
2. **Given** a suspended agent, **When** search is attempted, **Then** `403 AGENT_SUSPENDED` is returned.
3. **Given** a search string with fewer than 3 characters, **When** GET is sent, **Then** `422 SEARCH_TOO_SHORT` is returned.
4. **Given** no matching customers, **When** search completes, **Then** an empty result set is returned (`200` with empty `data[]`).
5. **Given** a search, **When** performed, **Then** `agent.customer.searched` audit event is emitted with the search query (sanitized, no full PII).

---

### User Story 11 — Agent Registers a New Customer (Priority: P0)

An active agent registers a new customer on the platform, creating a user account linked to the agent's ID. The agent becomes the `registration_agent_id` on the customer profile.

**Why this priority**: Customer registration is the primary acquisition mechanism for agent channels.

**Independent Test**: Register a new customer with valid phone and name; verify Customer (User) record created, AgentCustomerRelationship created, customer account in `pending_kyc` status.

**Acceptance Scenarios**:

1. **Given** an active agent with `agent.customer.register` permission, **When** POST is sent to `/v1/agents/{id}/customers` with valid phone, name, and ID type, **Then** a new customer is created and linked to the agent, with `201` returned.
2. **Given** a phone number already registered to another customer, **When** POST is sent, **Then** `422 PHONE_ALREADY_REGISTERED` is returned.
3. **Given** a suspended agent, **When** POST is sent, **Then** `403 AGENT_SUSPENDED` is returned.
4. **Given** valid registration data, **When** processed, **Then** `agent.customer.registered` audit event is emitted with agent ID, customer ID, and correlation ID.
5. **Given** the same `Idempotency-Key` with identical data, **When** POST is sent again, **Then** the existing customer record is returned (`200`).

---

### User Story 12 — Agent Initiates Customer KYC (Priority: P0)

An active agent initiates the KYC verification process for a registered customer, collecting and submitting identity documents on the customer's behalf.

**Why this priority**: Customers cannot perform financial transactions until KYC is complete.

**Independent Test**: Initiate KYC for a `pending_kyc` customer linked to the agent; verify KYC submission event emitted and customer KYC status transitions to `kyc_in_progress`.

**Acceptance Scenarios**:

1. **Given** an active agent and a customer in `pending_kyc` status linked to that agent, **When** POST is sent to `/v1/agents/{id}/customers/{cid}/kyc`, **Then** the KYC process is initiated and `202 Accepted` returned.
2. **Given** a customer not linked to the requesting agent, **When** POST is sent, **Then** `403 UNAUTHORIZED_CUSTOMER_ACCESS` is returned.
3. **Given** a customer already in `kyc_verified` status, **When** KYC initiation is attempted, **Then** `422 KYC_ALREADY_COMPLETED` is returned.
4. **Given** a valid KYC initiation, **When** processed, **Then** `agent.customer.kyc_initiated` audit event is emitted.

---

### User Story 13 — Agent Views Customer Wallet Status (Priority: P0)

An active agent views the wallet status (balance, status, account tier) of a linked customer to assist with cash-in or cash-out decisions.

**Why this priority**: Wallet status is essential for agents to confirm customer eligibility before transactions.

**Independent Test**: View wallet status for a linked customer; verify response includes status (active/frozen), tier, and masked balance range (no exact balance exposed unless within permitted disclosure rules); verify access denied for unlinked customer.

**Acceptance Scenarios**:

1. **Given** an active agent and a linked customer, **When** GET is sent to `/v1/agents/{id}/customers/{cid}/wallet`, **Then** wallet status, tier, and permitted balance summary are returned.
2. **Given** a customer not linked to the agent, **When** GET is sent, **Then** `403 UNAUTHORIZED_CUSTOMER_ACCESS` is returned.
3. **Given** a suspended agent, **When** GET is sent, **Then** `403 AGENT_SUSPENDED` is returned.
4. **Given** the customer's wallet is frozen, **When** status is returned, **Then** `is_frozen: true` is included in the response.
5. **Given** a valid access, **When** performed, **Then** `agent.customer.wallet_viewed` audit event is emitted.

---

### User Story 14 — Agent Views Permitted Customer Transactions (Priority: P0)

An active agent views the recent transaction history of a linked customer. Only transactions performed through the agent channel are returned, not the customer's full history.

**Why this priority**: Agents must be able to verify recent operations for dispute resolution and customer assistance.

**Independent Test**: View transactions for a linked customer; verify only agent-channel transactions (source = agent ID) are returned; verify full history is not exposed.

**Acceptance Scenarios**:

1. **Given** an active agent and a linked customer, **When** GET is sent to `/v1/agents/{id}/customers/{cid}/transactions`, **Then** the customer's agent-channel transactions are returned, paginated.
2. **Given** a customer not linked to the requesting agent, **When** GET is sent, **Then** `403 UNAUTHORIZED_CUSTOMER_ACCESS` is returned.
3. **Given** valid access, **When** performed, **Then** `agent.customer.transactions_viewed` audit event is emitted.

---

### User Story 15 — Unauthorized Customer Data Access Is Prevented (Priority: P0)

Agents must never access customer data for customers they are not linked to. All agent-customer data endpoints enforce the link check server-side and return consistent `403` responses without leaking whether the resource exists.

**Why this priority**: Foundational data protection requirement affecting all agent-customer operations.

**Independent Test**: Authenticated agent attempts to access data for a customer linked to a different agent; verify `403 UNAUTHORIZED_CUSTOMER_ACCESS` is always returned, never `404` (which would reveal existence).

**Acceptance Scenarios**:

1. **Given** an active agent, **When** any data endpoint is called with a `cid` not linked to this agent, **Then** `403 UNAUTHORIZED_CUSTOMER_ACCESS` is returned, never `404`.
2. **Given** a suspended agent, **When** any customer data endpoint is accessed, **Then** `403 AGENT_SUSPENDED` is returned.
3. **Given** valid access is granted, **When** sensitive data is returned, **Then** only permitted PII fields are included (no national ID numbers in full, no transaction PIN, no password hashes).
4. **Given** any unauthorized access attempt, **When** it occurs, **Then** a `security.unauthorized_access_attempt` audit event is emitted with agent ID and target resource.

---

### Edge Cases

- What happens when an agent's KYC check expires after approval? (Status remains active; re-verification is E18/E23 scope.)
- What happens when a customer registered by a suspended agent tries to transact? (Customer status is independent; only agent operations are blocked.)
- What happens if the KYC provider fails during agent application KYC? (Application stays in `kyc_in_progress`; retry policy applies; admin can manually trigger retry.)
- What happens when two admins concurrently try to approve the same application? (Only one succeeds; second gets `422 APPLICATION_NOT_APPROVABLE`.)
- What happens when an agent uploads a document that fails virus scan? (Document is quarantined; `agent.application.document_rejected` event emitted; applicant notified.)
- What happens when the agent has no linked customers and searches? (Empty result returned, not an error.)

---

## Requirements

### Functional Requirements

- **FR-E12-001**: System MUST allow an authenticated actor with `agent.application.create` permission to create an AgentApplication in `draft` status.
- **FR-E12-002**: System MUST require `Idempotency-Key` for all state-changing agent application and agent lifecycle operations.
- **FR-E12-003**: System MUST validate all required fields and formats before persisting agent personal information (name, national ID, DOB, phone, address).
- **FR-E12-004**: System MUST validate business registration number and trading name for `agent_type = business` applications.
- **FR-E12-005**: System MUST enforce configured document size and format limits for document uploads; supported formats are configurable.
- **FR-E12-006**: System MUST prevent a user from editing an AgentApplication that is in `submitted`, `kyc_in_progress`, `kyc_passed`, `kyc_failed`, `under_review`, `approved`, or `rejected` status.
- **FR-E12-007**: System MUST only trigger agent KYC when all required application sections (personal info, documents) are complete.
- **FR-E12-008**: System MUST integrate with the configured KYC provider (TBD) via the established provider-adapter pattern (E1000); if KYC provider is TBD, the KYC step MUST be skippable via admin override with audit record.
- **FR-E12-009**: System MUST enforce maker-checker for agent approval and suspension when `agent.approval.require_maker_checker` is `true` (configurable, default `true`).
- **FR-E12-010**: System MUST create an Agent record with `active` status only upon successful admin approval; no Agent record is created before approval.
- **FR-E12-011**: System MUST grant the agent identity role upon approval (integration with E04 roles/permissions).
- **FR-E12-012**: System MUST record the reason for rejection and suspension; reason is mandatory for both operations.
- **FR-E12-013**: System MUST enforce the agent status state machine: `draft → in_progress → submitted → kyc_in_progress → kyc_passed/kyc_failed → under_review → approved/rejected` (an application MAY skip `under_review` and transition directly from `kyc_passed` to `approved` when configured for auto-approval; `under_review` is the state in which an admin is actively reviewing a `kyc_passed` or `kyc_failed` application before making a final decision); Agent entity: `active → suspended → active`. The `submitted` status is system-implicit: the KYC trigger (POST `/v1/agent-applications/{id}/kyc`) atomically advances any editable status (`draft` or `in_progress`) to `submitted` immediately before moving to `kyc_in_progress` (completeness validation must pass first); applications already in `submitted` status transition directly to `kyc_in_progress`. No separate "submit application" API endpoint exists.
- **FR-E12-014**: System MUST return `403 AGENT_SUSPENDED` for any operational request (search, register customer, view data) from a suspended agent.
- **FR-E12-015**: System MUST enforce that agents can only access customer data for customers explicitly linked to them via AgentCustomerRelationship.
- **FR-E12-016**: System MUST return `403 UNAUTHORIZED_CUSTOMER_ACCESS` (never `404`) when an agent attempts to access unlinked customer data.
- **FR-E12-017**: System MUST create an AgentCustomerRelationship record when an agent registers a new customer, recording `registration_agent_id`.
- **FR-E12-018**: System MUST prevent duplicate customer registration for the same phone number; existing record should be returned or `422` raised.
- **FR-E12-019**: System MUST only return agent-channel transactions (sourced through the requesting agent) on the customer transaction view endpoint.
- **FR-E12-020**: System MUST emit structured audit events for all agent lifecycle transitions and customer access operations, including actor, timestamp, correlation ID, and old/new status.
- **FR-E12-021**: System MUST invalidate agent sessions upon suspension; E12 achieves this by emitting the `agent.suspended` event, which E03 Device & Session Management MUST consume asynchronously to revoke all active sessions for the agent's user ID.
- **FR-E12-022**: System MUST not expose full national ID numbers, PINs, or passwords in any agent-facing customer data response.
- **FR-E12-023**: System MUST emit a `security.unauthorized_access_attempt` event whenever an agent attempts unauthorized customer access.
- **FR-E12-024**: System MUST scan every uploaded agent document through the platform-configured virus scanner before persisting it; documents failing the scan MUST be quarantined (not stored), and an `agent.application.document_rejected` audit event MUST be emitted with the document ID, rejection reason (`VIRUS_SCAN_FAILED`), and system actor.

### Key Entities

- **AgentApplication**: Multi-step onboarding record. Fields: `id` (UUID v7), `user_id` (FK), `agent_type` (individual/business), `status` (application state machine), `personal_info` (JSONB), `business_info` (JSONB), `kyc_provider_reference`, `kyc_result`, `rejection_reason`, `submitted_at`, `approved_at`, `rejected_at`, `last_updated_by` (nullable UUID, FK → identity.users.id, tracks last actor for maker-checker enforcement), `correlation_id`, `idempotency_key`, `created_at`, `updated_at`.
- **AgentDocument**: Uploaded KYC/compliance documents. Fields: `id`, `application_id` (FK), `document_type` (national_id/proof_of_address/business_registration), `storage_reference`, `mime_type`, `size_bytes`, `status` (pending_review/accepted/rejected/quarantined), `uploaded_at`. Note: `quarantined` documents have no `storage_reference` (file was not persisted).
- **Agent**: Active agent profile created on approval. Fields: `id` (UUID v7), `application_id` (FK UNIQUE), `user_id` (FK), `agent_type`, `status` (active/suspended), `approved_by`, `approved_at`, `suspended_by`, `suspension_reason`, `reactivated_by`, `created_at`, `updated_at`.
- **AgentCustomerRelationship**: Tracks which customers are linked to which agent. Fields: `id`, `agent_id` (FK), `customer_id` (FK), `registered_by` (agent_id), `registered_at`. Unique index: `(agent_id, customer_id)`.
- **AgentStatusHistory**: Immutable audit log for agent status changes. Fields: `id`, `agent_id` (FK), `previous_status`, `new_status`, `actor_id`, `actor_type`, `reason`, `occurred_at`.

---

## Success Criteria

### Measurable Outcomes

- **SC-E12-001**: An agent onboarding application can be completed (all steps: create, personal info, business info, documents, KYC trigger) in under 10 minutes by an agent using the mobile app.
- **SC-E12-002**: Admin approval or rejection of a fully completed application takes under 2 minutes end-to-end.
- **SC-E12-003**: Agent-customer search returns results in under 2 seconds for phone or name queries.
- **SC-E12-004**: 100% of unauthorized customer data access attempts are blocked by server-side authorization checks and produce an audit event.
- **SC-E12-005**: Agent suspension takes effect within 1 second of admin action, including session invalidation.
- **SC-E12-006**: All state-changing operations are idempotent — duplicate requests with the same `Idempotency-Key` return the same result without side effects.
- **SC-E12-007**: All agent lifecycle events are auditable with actor, timestamp, correlation ID, and status transition within 100ms of the state change.

---

## Assumptions

- Agent identity accounts exist in the E01/E04 identity system — the agent's `user_id` links to an existing identity user.
- The KYC provider for agent verification is TBD; the implementation uses the provider-adapter pattern from E1000 and provides a configurable bypass (`agent.kyc.provider = stub`) for development/testing.
- Document storage uses the platform's configured file storage service (S3-compatible); storage references are opaque strings in this epic.
- Maker-checker requirement for approval and suspension is runtime-configurable (default `true`); the specific admin roles with approval authority are defined by E04 role configuration.
- Agent wallet provisioning (float wallet, ledger account) is triggered as a side-effect of approval via an event consumed by E13 (Agent Float); E12 emits the `agent.approved` event and does not directly interact with E07 Ledger.
- The customer registration performed by an agent (US-AGENT-011) creates a minimal user record; full customer profile completion and wallet opening are covered by E06 and subsequent customer-facing flows.
- The "permitted balance view" in US-AGENT-013 returns wallet status (active/frozen/suspended) and tier, but not the exact balance. Exact balance disclosure rules are TBD (deferred to E18/E23).
- Notifications triggered by agent lifecycle events (approval, rejection) are dispatched via E17 Notifications; E12 emits events only.
- Session invalidation upon suspension is implemented via E03 Device & Session Management by consuming the `agent.suspended` event.
- The `under_review` status is a system-implicit state: the application transitions to `under_review` automatically when an admin first opens it for review (no explicit API action required). Admins then approve or reject from `under_review`. The transition from `kyc_passed` directly to `approved` is only valid when `agent.approval.auto_approve = true` (configurable, default `false`).
