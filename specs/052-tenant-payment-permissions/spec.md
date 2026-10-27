# Feature Specification: P03 — Tenant Isolation and Payment Permissions

**Feature Branch**: `052-tenant-payment-permissions`

**Created**: 2026-10-21

**Status**: Draft

**Work Package**: P03 (per `OleKard_Claude_SpecKit_Managed_Prompts.docx` v1.0)

**Predecessor**: P02 (`specs/051-payment-schema-api-contracts/spec.md`) — COMPLETE/RELEASE READY ✅

**Mode**: Implement

**Responsible role**: Security and backend engineers

**Source references**: FD01; admin permission matrix; section 9

> **Source document availability**: `OleKard_Shared_Payment_Architecture.docx v1.1` is NOT in the repository. FD01 (identity/org structure), the admin permission matrix, and section 9 cannot be fully verified. Decisions derived from those sections are recorded as AWAITING_DOCUMENT. P03 proceeds on repository evidence (E04 RBAC model, E48 payment routes, existing `identity.require-permission` middleware, and constitution P5 GAP-027 resolved threshold).

**Boundary**: No new identity credentials, no new org/tenant model changes. Reuse existing E04 RBAC infrastructure. No finance state changes — authorization only.

---

## Overview

P03 ensures the Payment module's authorization model is complete, consistently enforced, and independently verifiable. E48 introduced routes that reference `identity.require-permission:*` middleware but several gaps remain:

1. **Permission catalogue gap**: Payment permissions are declared in route middleware and OpenAPI annotations but are not yet seeded into the `identity.permissions` table. The RBAC resolver cannot confirm a permission exists until it is in that table.
2. **Maker-checker gap**: `payment.config.approve_high_risk` is enforced, but there is no `payment.config.draft` permission, no formal maker-checker guard, and no enforcement that the drafter and approver are different actors.
3. **Refund approval gap**: `payment.refunds.approve` is referenced in payment.yaml but there is no route, controller method, or self-approval prevention for the approval step.
4. **Org-scope gap**: `PaymentOrganisationScopeMiddleware` covers `{intentId}` routes but refund routes, reconciliation admin routes, and callback-account mapping have no cross-tenant enforcement.
5. **Step-up gap**: `StepUpRequiredException` exists in the domain but is never raised in the refund or credential rotation flows.
6. **Payout permission gap**: `payment.payouts.view` and `payment.payouts.manage` are needed by P18 but are not yet defined.

P03 closes all six gaps without changing the financial state machine, wallet balances, or ledger.

---

## User Scenarios & Testing

### User Story 1 — Seed Payment Permission Catalogue (Priority: P1)

An administrator logs into the Admin Portal and navigates to the Roles & Permissions section. All payment-domain permissions appear in the catalogue and can be assigned to roles. A platform engineer running the seeder sees all payment.* permissions created idempotently.

**Why this priority**: Without seeded permission records, the RBAC resolver cannot confirm that `payment.intents.manage` or any other payment permission is valid. Every downstream permission check is at risk of silently passing or failing depending on the Gate fallback.

**Independent Test**: After running the seeder, the `identity.permissions` table contains all 14 payment.* permissions (FR-01). Assigning `payment.intents.manage` to the ADMIN role and querying the permission catalogue via `GET /v1/admin/identity/permissions` returns it in the list.

**Acceptance Scenarios**:

1. **Given** the seeder has not been run, **When** the RBAC resolver queries for `payment.intents.manage`, **Then** the permission record is absent from `identity.permissions`.
2. **Given** the seeder is run, **When** the RBAC resolver queries for `payment.intents.manage`, **Then** the permission record exists with the correct name and group.
3. **Given** the seeder is run a second time, **When** the seeder completes, **Then** no duplicate permission records are created (idempotent upsert).
4. **Given** a member of the ADMIN role has `payment.providers.manage` assigned, **When** they call `POST /v1/admin/payment/providers`, **Then** the request is authorized and returns 201.
5. **Given** a member of the CUSTOMER role does not have `payment.providers.manage`, **When** they call `POST /v1/admin/payment/providers`, **Then** the request is rejected with 403.

---

### User Story 2 — Enforce Tenant/Org-Scope Isolation on All Payment Routes (Priority: P1)

A customer makes a payment on behalf of Organisation A. Another customer authenticated under Organisation B cannot read, cancel, or refund that payment by guessing or substituting the intent ID.

**Why this priority**: Cross-tenant data leakage is a critical security defect. The org-scope middleware exists for intent routes but has not been applied to refund routes or admin reconciliation routes. A single unchecked `{intentId}` parameter in a refund request is sufficient for an ID-substitution attack.

**Independent Test**: An authenticated customer from Organisation B submits a refund request against an intent belonging to Organisation A. The system rejects the request with 403 and does not reveal whether the intent exists (information hiding).

**Acceptance Scenarios**:

1. **Given** actor from Org B attempts `POST /v1/payment/intents/{intentId}/refunds` with an intentId belonging to Org A, **When** the request is received, **Then** the system returns 403 and does not indicate whether the intent exists.
2. **Given** actor from Org A attempts `GET /v1/payment/intents/{id}/status` for their own intent, **When** the request is received, **Then** the system returns 200 with intent status.
3. **Given** an admin with `payment.reconciliation.view` queries reconciliation exceptions, **When** they filter by `organisation_id`, **Then** results are scoped to the organisation they are authorized to view (system-level admins see all; org-scoped admins see only their org).
4. **Given** a provider callback arrives at `/v1/payment/callbacks/{providerCode}` with an unregistered or inactive providerCode, **When** the callback handler receives it, **Then** the callback is rejected with 404 and logged; no financial processing occurs.
5. **Given** a queued job (PaymentIntentExpiredJob) processes an intent, **When** the job runs, **Then** the intent's organisation_id is verified before any state change is applied.

---

### User Story 3 — Maker-Checker Policy for Payment Provider Configuration (Priority: P1)

A payment operations engineer wants to update a provider's `merchant_account_reference` (a high-risk field). They submit a change draft using `payment.config.draft`. A second operations engineer with `payment.config.approve_high_risk` reviews and approves the draft. The first engineer cannot approve their own draft.

**Why this priority**: Provider configuration changes (routing, merchant accounts, settlement destinations) directly affect financial flows. A single operator who can both draft and approve a configuration change has unchecked power over which bank account receives funds.

**Independent Test**: Engineer A submits a high-risk config change (creates a draft). Engineer A then attempts to approve their own draft. The system rejects the self-approval attempt with 403 and a SELF_APPROVAL_NOT_ALLOWED error code. Engineer B (different actor) with `payment.config.approve_high_risk` can approve the same draft successfully.

**Acceptance Scenarios**:

1. **Given** Engineer A holds `payment.config.draft`, **When** they submit a provider configuration change for `merchant_account_reference`, **Then** the change is recorded as a pending draft and the provider configuration is NOT immediately applied.
2. **Given** Engineer A submitted a draft, **When** Engineer A attempts to approve it, **Then** the system returns 403 with code `SELF_APPROVAL_NOT_ALLOWED`.
3. **Given** Engineer B holds `payment.config.approve_high_risk` and did NOT create the draft, **When** Engineer B approves the draft, **Then** the provider configuration is updated, an audit record is written, and Engineer A and Engineer B are both recorded.
4. **Given** a provider credential reference is rotated, **When** the rotation is submitted, **Then** it is subject to the same maker-checker policy; the credential reference is never returned in any response.
5. **Given** an operator's session has been revoked since they submitted a draft, **When** the system processes their draft approval request, **Then** the request is rejected with 401 (session revocation is enforced by E03).

---

### User Story 4 — Refund Approval Gate (Priority: P2)

A customer service agent requests a refund for a payment above the approval threshold. The refund enters a `pending_approval` state. A second agent with `payment.refunds.approve` reviews and approves it. The requesting agent cannot approve their own refund.

**Why this priority**: Refunds above a threshold represent a significant financial disbursement. Requiring a second approver reduces the risk of fraudulent or erroneous refunds being processed by a single actor.

**Independent Test**: Agent A requests a refund of BWP 500 (above the refund approval threshold of BWP 200). The refund enters `pending_approval`. Agent A attempts to approve their own refund. The system rejects with 403 and SELF_APPROVAL_NOT_ALLOWED. Agent B (with `payment.refunds.approve`) approves it, and the refund transitions to `approved`.

**Acceptance Scenarios**:

1. **Given** a refund amount is below the approval threshold, **When** an actor with `payment.refunds.request` submits the refund, **Then** the refund is created with status `pending_provider` (no approval gate needed for small refunds).
2. **Given** a refund amount exceeds the approval threshold, **When** an actor with `payment.refunds.request` submits the refund, **Then** the refund is created with status `pending_approval`.
3. **Given** a refund is in `pending_approval`, **When** the requester attempts to approve it, **Then** the system returns 403 with code `SELF_APPROVAL_NOT_ALLOWED`.
4. **Given** a refund is in `pending_approval`, **When** an actor with `payment.refunds.approve` (who did not request it) approves it, **Then** the refund transitions to `approved` and the approval actor is recorded.
5. **Given** a refund is in `pending_approval`, **When** an actor with `payment.refunds.approve` rejects it, **Then** the refund transitions to `rejected` and the reason is recorded.

---

### User Story 5 — Step-Up MFA Enforcement on Privileged Operations (Priority: P2)

An operations engineer triggers a provider credential rotation or a high-value refund approval. The system detects the operation is above the step-up threshold and requires the actor to re-authenticate with MFA before the operation proceeds.

**Why this priority**: Constitution P5 (GAP-027) mandates step-up for privileged financial operations. The `StepUpRequiredException` exists in the domain but is not raised in refund or credential rotation flows. Without enforcement, high-value operations proceed without additional authentication.

**Independent Test**: An actor with a valid session but no step-up token attempts to approve a refund of BWP 10,001 (above the configured `auth.step_up_threshold = 10000 BWP`). The system returns 403 with code `STEP_UP_REQUIRED`. After completing step-up, the same request succeeds.

**Acceptance Scenarios**:

1. **Given** a refund approval request is above the step-up threshold, **When** the actor has not completed step-up in their current session, **Then** the system returns 403 with code `STEP_UP_REQUIRED` and a `next_action` of type `wallet_step_up`.
2. **Given** a refund approval request is above the step-up threshold, **When** the actor has completed step-up in their current session, **Then** the request proceeds normally.
3. **Given** a credential rotation request is submitted, **When** the actor has not completed step-up, **Then** the system returns 403 with code `STEP_UP_REQUIRED`.
4. **Given** `auth.step_up_threshold` is reconfigured to BWP 5000 via the Platform Configuration service, **When** a BWP 6000 refund approval is submitted, **Then** step-up is required (threshold is read at runtime, not hard-coded).

---

### User Story 6 — Payout Permission Definitions and Sensitive Field Redaction (Priority: P3)

A compliance officer can query payout records (P18 feature) without seeing raw bank account credentials. An actor without `payment.payouts.view` is rejected with 403. Provider configuration responses never include API keys, shared secrets, or raw credential values.

**Why this priority**: P18 (payout) is a future work package that will depend on `payment.payouts.view` and `payment.payouts.manage` being defined and seeded before P18 implementation begins. Sensitive field redaction is a defensive baseline that should be validated now before more endpoints are added.

**Independent Test**: A `GET /v1/admin/payment/providers` response is inspected — no field contains a value matching a known API key or secret pattern. Calling `GET /v1/admin/payment/providers` without `payment.providers.read` returns 403, not 401 or 500.

**Acceptance Scenarios**:

1. **Given** `payment.payouts.view` and `payment.payouts.manage` are seeded in `identity.permissions`, **When** they are assigned to the ADMIN role, **Then** a role holder can query them via the permission management API.
2. **Given** a provider has a `merchant_account_reference` set, **When** `GET /v1/admin/payment/providers/{id}` is called, **Then** the response contains a sanitized reference but never the raw credential value.
3. **Given** an actor without `payment.providers.read` calls `GET /v1/admin/payment/providers`, **When** the request is received, **Then** the system returns 403 (not 401, not 500).
4. **Given** an AGENT actor (no admin role) calls `GET /v1/admin/payment/providers`, **When** the request is received, **Then** the system returns 403.

---

### Edge Cases

- What happens when an intent was created under Organisation A but that organisation is subsequently deactivated — are refund requests against it still processed?
- How does org-scope enforcement interact with system-level admin actors who have no `organisation_id` (platform-level operations)?
- What happens if a draft config change references a provider that is suspended between draft creation and approval?
- Can a maker-checker draft be withdrawn by the drafter? If so, is that also subject to audit?
- What happens when the step-up threshold is changed while a refund approval is in-flight (pending_approval state)?

---

## Requirements

### Functional Requirements

- **FR-01**: The system MUST seed all payment domain permissions into the identity permission catalogue as an idempotent database seeder. The catalogue MUST include: `payment.intents.read`, `payment.intents.manage`, `payment.refunds.request`, `payment.refunds.approve`, `payment.providers.read`, `payment.providers.manage`, `payment.config.draft`, `payment.config.approve_high_risk`, `payment.reconciliation.view`, `payment.reconciliation.manage`, `payment.payouts.view`, `payment.payouts.manage`, `payment.callbacks.receive`, `payment.exports.view` (14 total).

- **FR-02**: All payment routes MUST enforce their required permission via the `identity.require-permission` middleware. No payment route may be reached without permission enforcement. The `payment.config.draft` permission MUST be added to the high-risk provider update route to gate the draft submission.

- **FR-03**: All payment routes that reference a resource by ID (intent, refund, reconciliation exception) MUST enforce organisation-scope isolation server-side. The authenticated actor's `organisation_id` MUST be compared against the resource's `organisation_id`. A mismatch MUST return 403 without revealing resource existence (information hiding).

- **FR-04**: Provider configuration changes to high-risk fields MUST follow a maker-checker workflow. The drafter and approver MUST be different actors. If the drafter's `actor_id` matches the approver's `actor_id`, the system MUST return 403 with code `SELF_APPROVAL_NOT_ALLOWED`.

- **FR-05**: Refund requests above the refund approval threshold MUST be created with status `pending_approval`. The requester and approver MUST be different actors. The same self-approval prevention as FR-04 MUST apply.

- **FR-06**: Refund approvals and provider credential rotations MUST trigger a step-up check using the runtime-configurable `auth.step_up_threshold`. If the actor's session does not have a valid step-up token, the system MUST return 403 with code `STEP_UP_REQUIRED`. The threshold MUST be read from the Platform Configuration service, not hard-coded.

- **FR-07**: The payment callback handler MUST verify the `{providerCode}` path parameter against the `payment_providers` table. If the provider code is not found or the provider is not in an active state, the callback MUST be rejected with 404 and logged. No financial processing MAY begin without a verified active provider.

- **FR-08**: Provider API credentials, shared secrets, and raw webhook signing keys MUST never appear in any API response body, log entry, or audit record. Only sanitized references (opaque identifiers) are permitted in responses.

- **FR-09**: All authorization state changes (permission grants, role assignments, config draft submissions, config approvals, refund approvals, step-up challenges) MUST be recorded as audit entries with actor_id, timestamp, action, resource_id, and outcome.

- **FR-10**: Negative tests MUST cover: (a) ID substitution across organisations, (b) cross-tenant export/job access, (c) revoked session membership attempting a payment operation, (d) self-approval of a config change or refund, (e) callback with an invalid/inactive providerCode.

### Blocked Functional Requirements (AWAITING_DOCUMENT: DEC-07)

- **FR-B01**: Exact role-to-permission matrix specifying which standard roles (ADMIN, AGENT, CUSTOMER) receive which payment permissions by default — derivable from FD01 and the admin permission matrix. Assumption applied: ADMIN role receives all 14 permissions; AGENT role receives `payment.intents.read`, `payment.intents.manage`, `payment.refunds.request`; CUSTOMER role receives `payment.intents.read`, `payment.intents.manage`. This assumption may be overridden when FD01 is available.

- **FR-B02**: Step-up threshold amounts for payment operations other than the global `auth.step_up_threshold` (e.g., separate thresholds for refunds vs. credential rotation, or per-organisation overrides) — derivable from section 9. Assumption applied: the global `auth.step_up_threshold = 10000 BWP` (GAP-027 resolved) applies to all step-up triggers in P03. Per-operation overrides are deferred to when section 9 is available.

### Key Entities

- **PaymentPermission**: A named permission record in `identity.permissions` scoped to the `payment` module group. 14 records total.
- **ConfigChangeDraft**: A pending high-risk provider configuration change submitted by one actor (drafter) awaiting approval by a different actor (approver). Stored in a `payment_config_change_drafts` table.
- **RefundApproval**: The approval record for a refund in `pending_approval` state. Records approver actor_id, decision (approved/rejected), timestamp, and reason.
- **StepUpContext**: The session-level step-up token indicating the actor has completed MFA for the current operation. Resolved via the existing Identity/Session module contracts.

---

## Success Criteria

### Measurable Outcomes

- **SC-01**: After running the P03 seeder, all 14 payment.* permissions exist in `identity.permissions` (verifiable via SELECT query or permission management API).
- **SC-02**: Negative test suite (FR-10): all 5 negative test scenarios (ID substitution, cross-tenant access, revoked session, self-approval, invalid callback provider) pass with 403/401/404 as appropriate.
- **SC-03**: Zero provider credential values appear in any API response body captured during a full integration test run.
- **SC-04**: A maker-checker round-trip (draft → attempt self-approval → 403 → approve by second actor → success) completes successfully in the integration test suite.
- **SC-05**: A step-up round-trip (high-value operation → STEP_UP_REQUIRED → complete step-up → success) completes with the correct HTTP status codes.
- **SC-06**: PHPStan level 8 reports 0 errors across new P03 policy/seeder files.
- **SC-07**: All new routes are documented in OpenAPI with `x-required-permission` annotations and `security: [{BearerAuth: []}]`.

---

## Assumptions

- The E04 RBAC infrastructure (`identity.require-permission` middleware, `PermissionResolverService`, `Permission` model) is operational and available. P03 adds records to it without changing its schema.
- The refund approval threshold for `pending_approval` is configurable via the Platform Configuration service. The default seed value is BWP 200 (assumption; override when FD01/section 9 is available).
- `auth.step_up_threshold = 10000 BWP` applies to both refund approvals and credential rotation in the absence of FD01 section 9 per-operation overrides. This is the GAP-027 resolved value from the constitution (P5).
- System-level admin actors (with no `organisation_id`) bypass org-scope isolation checks and can access all organisations' payment data. Org-scoped admin actors (with `organisation_id` set) are restricted to their organisation.
- A `payment_config_change_drafts` table is introduced to store high-risk field change drafts pending approval. This table is in the `payment.*` schema.
- The refund approval flow stores the approver's actor_id in the existing `payment_refunds` table (new column `approved_by`, nullable UUID). No separate approvals table is needed for refunds.
- `payment.callbacks.receive` is a system-level permission, not a user-facing permission. It is seeded but not assignable to user roles (used by the platform to verify only the callback handler route can be reached by the background processing path).
- FR-B01 role-to-permission matrix assumption: ADMIN=all 14, AGENT=intents.read+intents.manage+refunds.request, CUSTOMER=intents.read+intents.manage. This assumption MUST be overridden when FD01 is available.
