# Feature Specification: P20 — Admin Provider Configuration and Policy Screens

**Feature Branch**: `067-admin-provider-config-screens`

**Created**: 2026-10-26

**Status**: Draft

**Work Package**: P20 of the Shared Payment Integration Platform (E48)

**Required Predecessors**:
- P03 (`specs/052-tenant-payment-permissions/`) — COMPLETE/RELEASE READY ✅ (payment permissions seeded, maker-checker framework, org-scope middleware)
- P05 (`specs/054-provider-option-eligibility/`) — COMPLETE/RELEASE READY ✅ (provider registry, eligibility pipeline, config versioning, adapter allowlist)
- P10 (`specs/059-e25-payment-callback-ingress/`) — COMPLETE/PARTIAL ✅ (adapter contracts and callback ingress framework; live activation blocked by external gate)

**Mode**: Implement

**Responsible role**: Next.js admin engineer

**Source references**: AD02 AD03 AD04 AD05 AD12; LC01

**Verified source**: `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx`  
SHA256: `B5D07562EE2497C726AABEFE18782ABFFC094A39CED8D0CF3E16AA0F2CFF7837`

**Boundary**:
- Screens live inside the existing admin Next.js shell (`wallet-ecosystem/apps/admin`)
- No competing admin-app/admin-portal project
- No executable adapter upload UI
- No new backend domain logic (P03/P05/P10 own that); P20 owns the **admin screens** that call existing APIs
- `callback_url` is always null — E25 owns external callback ingress; P20 must not expose an input for it

---

## Context

P03 seeded payment permissions and implemented maker-checker authorization. P05 created the `payment_providers` and `payment_provider_accounts` tables, the eligibility pipeline, the adapter allowlist, and the `PaymentConfigChangeDraftModel` infrastructure for version-locked approval. P10 delivered adapter contracts and the E25 callback framework.

What is missing is an **admin UI** that lets platform and organisation operators:

1. Browse registered providers and their accounts
2. Edit provider account configuration (capabilities, limits, fees, secret references, module availability, display order) through a safe draft/approve cycle
3. Observe and drive the provider account lifecycle (activate, suspend, restore)
4. Review the version history of configuration changes
5. Do all of this in a way that masks secrets, prevents test/production confusion, and enforces server-side authorization

P20 delivers those screens. All state changes go to the existing backend APIs. The UI adds no new business rules — it enforces existing ones through validation feedback and flow gating.

---

## User Scenarios & Testing

### User Story 1 — Browse Provider Registry (Priority: P1)

A platform administrator navigates to the **Provider Registry** section of the Admin Portal and sees a list of all registered payment providers. For each provider they can see its name, status, number of active accounts, and the adapter identifier. Clicking a provider reveals its accounts, each with its current lifecycle status (draft / pending_approval / active / suspended / archived). The administrator can filter the list by status and adapter type.

**Why this priority**: Without a readable registry the operator cannot audit which providers are live or triage eligibility failures. This is the entry point for all other P20 stories.

**Independent Test**: Navigate to the Provider Registry with a user that has `payment.providers.read`. Assert the page loads a list of providers from the API. Assert that each card shows status, adapter, and account count. Assert that a user without `payment.providers.read` sees a 403/unauthorised state instead of the list.

**Acceptance Scenarios**:

1. **Given** a platform admin with `payment.providers.read`, **When** they visit the Provider Registry, **Then** all registered providers are listed with name, adapter label, lifecycle status badge, and active account count.
2. **Given** a provider with no active accounts, **When** the admin views its row, **Then** an "empty" state is shown with a prompt to configure accounts.
3. **Given** a user without `payment.providers.read`, **When** they navigate to the Provider Registry, **Then** they see a 403 / access-denied page, not a blank list.
4. **Given** the backend API is temporarily unavailable, **When** the page loads, **Then** an outage state is displayed with a retry action; partial stale data is not silently shown.
5. **Given** the admin filters by `status = suspended`, **When** the filter is applied, **Then** only suspended-status providers and accounts are shown.

---

### User Story 2 — Configure Provider Account (Draft and Validate) (Priority: P1)

A platform configuration operator opens a provider account detail screen and edits the account's configuration: capability flags (e.g., supports_refunds, supports_payouts), transaction limits (min/max), fee overrides, secret credential references, module availability toggles, and display order position. When they save, the system stores a **draft** of the changes. The operator can preview the diff between the current live configuration and the draft before submitting for approval.

**Why this priority**: This is the core write path. Without it no configuration changes can reach the provider registry at all. P1 because the UI is useless in read-only mode.

**Independent Test**: Edit a provider account's `max_amount_minor`, save as draft, then view the preview. Assert the preview shows old value vs. new value. Assert the account's live configuration is unchanged. Assert submitting the draft with an invalid adapter identifier is rejected with a validation error (adapter not in allowlist).

**Acceptance Scenarios**:

1. **Given** an operator with `payment.providers.manage`, **When** they edit the `max_amount_minor` field and save draft, **Then** the UI shows a "Draft saved" confirmation; the live record is unchanged until approved.
2. **Given** the operator submits a draft with `adapter_identifier` not in the approved allowlist, **When** they save, **Then** the form shows a validation error "Adapter not in approved list" before any API call.
3. **Given** an operator edits a field that previously had an approval pending, **When** they save a new draft, **Then** the prior pending approval is invalidated (server returns 409 conflict); the UI shows "Previous approval invalidated — submit for re-approval."
4. **Given** the secret credential reference field, **When** the account is loaded, **Then** the field shows a masked value (e.g., `••••••••`) and a "Change reference" button, never the raw secret.
5. **Given** an operator tries to set `callback_url`, **When** they access the form, **Then** the field does not exist (not rendered); no API call to set `callback_url` is ever made from this screen.
6. **Given** an environment badge shows `TEST`, **When** the operator views a production account, **Then** the environment badge shows `PRODUCTION` with distinct visual treatment, preventing test/prod confusion.

---

### User Story 3 — Maker-Checker Approval Flow (Priority: P1)

After saving a draft, the configuration operator submits it for approval. A second authorised user (the **approver**, who is different from the drafter) receives an approval queue entry. The approver can review the diff, approve or deny the change, and provide a comment. On approval the new configuration becomes live and the provider account's `config_version` increments. A denied change is recorded with the reason; the drafter is notified.

**Why this priority**: Sensitive provider configuration changes (fee overrides, credential references, limit changes) require a two-person control as mandated by the payment governance model. P1 because without approval the draft config never activates.

**Independent Test**: As operator A draft-and-submit a limit change. Log in as operator B (approver). Assert the approval queue shows the pending change. Approve it. Assert the live account now reflects the new limit and `config_version` has incremented. Assert operator A cannot approve their own change.

**Acceptance Scenarios**:

1. **Given** a submitted draft, **When** the approver views the approval queue, **Then** the pending item shows the diff, the drafter's name, and the submission timestamp.
2. **Given** the approver approves the change, **When** the approval is processed, **Then** the provider account's live configuration is updated, `config_version` increments, and the draft transitions to `approved`.
3. **Given** the drafter tries to approve their own draft, **When** they click Approve, **Then** the system returns an error "Self-approval is not permitted"; the draft remains pending.
4. **Given** the approver denies the change with a reason, **When** the denial is processed, **Then** the draft transitions to `denied`; the drafter can see the denial reason on the draft history.
5. **Given** a second editor modifies the same account after a draft was submitted, **When** the approver attempts to approve the first draft, **Then** the system returns a version conflict; the approver sees "Config changed since submission — re-review required."

---

### User Story 4 — Activate, Suspend, and Restore Provider Accounts (Priority: P1)

A platform operator can transition a provider account through its lifecycle: from `active` to `suspended` (blocking new attempts while preserving in-flight resolution), and from `suspended` back to `active`. Each transition requires a reason and logs an audit entry. A suspended account's in-flight payments continue to resolve normally; only new option discovery excludes the suspended account.

**Why this priority**: Operators need this for incident response — quickly disabling a misbehaving provider without losing in-flight funds. P1 because it is a safety control.

**Independent Test**: Suspend an active account with a reason. Verify the account status badge changes to `suspended`. Call the payment options API and assert the suspended account is absent. Call a lifecycle-resolution endpoint for an in-flight attempt on the suspended account and assert it succeeds.

**Acceptance Scenarios**:

1. **Given** an active provider account, **When** an operator with `payment.providers.manage` clicks Suspend and provides a reason, **Then** the account transitions to `suspended` and the status badge updates immediately.
2. **Given** a suspended provider account, **When** option discovery runs for any intent, **Then** the suspended account is absent from the available options list.
3. **Given** a suspended provider account with an in-flight attempt in `processing` state, **When** the provider posts a completion callback, **Then** the attempt resolution succeeds normally (suspension does not block resolution).
4. **Given** a suspended account, **When** an operator with `payment.providers.manage` clicks Restore and provides a reason, **Then** the account returns to `active` state.
5. **Given** a suspension action, **When** it is complete, **Then** an audit record is written with operator ID, timestamp, reason, and the previous/new status values.

---

### User Story 5 — Module Availability and Display Order (Priority: P2)

A platform operator can configure which payment modules (e.g., wallet top-up, bill payment, P2P transfer) are globally enabled or disabled, and set the display order in which provider options are presented in the mobile app. Changes go through the same draft/approve cycle as account configuration.

**Why this priority**: P2 because it is a policy-layer concern; the underlying providers still work with default ordering if this screen is absent. It can ship after the core account config (US1–US4) is live.

**Independent Test**: Disable a module globally via the Module Availability screen. Call `GET /v1/payment/options` with a purpose type belonging to the disabled module. Assert the endpoint returns no options for that module.

**Acceptance Scenarios**:

1. **Given** an operator disables a payment module, **When** a consumer calls the payment options endpoint for that module's purpose type, **Then** no provider options are returned.
2. **Given** an operator sets display order position 1 for Provider A and position 2 for Provider B, **When** the payment options list is rendered in the mobile app, **Then** Provider A appears first.
3. **Given** a module availability change is saved as draft, **When** the same two-step approval flow is applied, **Then** the change only takes effect after approval.

---

### User Story 6 — Version History and Audit Trail (Priority: P2)

An operator can view the full version history of a provider account's configuration — each approved change, the drafter, the approver, the timestamp, and the specific fields changed. They can compare any two historical versions side by side.

**Why this priority**: P2 — critical for compliance and incident investigation but does not block the core config workflow.

**Independent Test**: After three approved configuration changes, navigate to the version history tab. Assert three entries are shown with correct timestamps, actor names, and diffs. Assert comparing v1 and v3 shows a combined diff.

**Acceptance Scenarios**:

1. **Given** a provider account with three approved changes, **When** an operator opens the Version History tab, **Then** three entries are shown in reverse-chronological order, each with approver, drafter, timestamp, and a one-line change summary.
2. **Given** the operator selects two versions to compare, **When** the comparison renders, **Then** added fields are highlighted green, removed/changed fields are highlighted red.
3. **Given** a denied draft, **When** the operator views the history, **Then** denied drafts appear in the timeline with "Denied by [name]" and the denial reason, not as applied changes.

---

### Edge Cases

- **Concurrent edits**: Two operators edit the same account simultaneously. The second to submit for approval sees a version conflict error; their draft is preserved for revision.
- **Empty allowlist**: If the platform adapter allowlist is empty, the account configuration form disables the adapter dropdown and shows "No adapters registered — contact platform support."
- **Partial API failure**: If the lifecycle transition API succeeds but the UI fails to reload, the stale status badge auto-refreshes on next load; no user action is required.
- **Missing permission**: An operator with `payment.providers.read` but not `payment.providers.manage` sees the configuration form in read-only mode; all edit controls are visually disabled.
- **Sandbox/production account**: A provider account tagged `environment = sandbox` shows a persistent "SANDBOX" badge on all screens; the activation action shows a confirmation dialog warning that real traffic will not route to sandbox accounts.
- **Approval queue empty**: The approval queue shows an explicit empty state ("No changes awaiting approval") rather than a blank table.

---

## Requirements

### Functional Requirements

- **FR-001**: The Provider Registry screen MUST display all providers and their accounts; it MUST be accessible only to users with `payment.providers.read` or `payment.providers.manage`.
- **FR-002**: Provider account configuration fields MUST include: capability flags, transaction limits (min/max), fee override references, secret credential references, module availability, display order, and adapter identifier.
- **FR-003**: Secret credential reference fields MUST display a masked value (`••••••••`) and a "Change reference" button; the raw secret value MUST NOT be rendered or returned to the browser at any time.
- **FR-004**: The `adapter_identifier` field MUST be restricted to the server-provided adapter allowlist; free-text entry is not permitted.
- **FR-005**: A `callback_url` input MUST NOT appear anywhere in P20 screens; E25 owns callback ingress.
- **FR-006**: All configuration changes MUST go through a draft → submit → approve/deny workflow before taking effect; direct save-to-live is not permitted.
- **FR-007**: The drafter and approver MUST be different authenticated users; self-approval MUST be blocked at both client and server level.
- **FR-008**: Editing a provider account after a draft has been submitted for approval MUST invalidate the pending approval; the UI MUST surface the invalidation clearly.
- **FR-009**: Approval of a configuration change MUST increment the account's `config_version`; the new version MUST be visible on the account detail screen.
- **FR-010**: Lifecycle transitions (activate, suspend, restore, archive) MUST require a reason field and MUST write an audit record with actor, timestamp, reason, and old/new status.
- **FR-011**: A suspended provider account MUST be absent from payment option discovery; in-flight attempts on a suspended account MUST continue to resolve normally.
- **FR-012**: The account detail screen MUST display an environment badge (`SANDBOX` / `TEST` / `PRODUCTION`) with visually distinct treatment for each environment; the badge is derived from the account's `environment` field, not from a URL.
- **FR-013**: The version history screen MUST show every approved configuration change with drafter, approver, timestamp, and field-level diff; denied drafts MUST appear in timeline with denial reason.
- **FR-014**: Module availability and display order configuration MUST follow the same draft/approve cycle as account configuration (FR-006).
- **FR-015**: Loading, empty, error/outage, approval-conflict, and access-denied states MUST each have a distinct UI representation; blank screens are not acceptable.
- **FR-016**: All state-changing actions (save draft, submit for approval, approve, deny, activate, suspend) MUST be guarded by server-side permission checks; the UI gates are a convenience only.
- **FR-017**: The approval queue MUST be filterable by drafter, provider, account, and submission date; it MUST show the change diff without requiring navigation to the account screen.

### Key Entities

- **PaymentProvider**: A registered payment adapter with name, adapter identifier, status, and capability declarations.
- **PaymentProviderAccount**: A configured instance of a provider with limits, fees, secret references, module availability, display order, environment tag, lifecycle status, and `config_version`.
- **ProviderConfigDraft**: A pending set of changes to a provider account, recording the drafter, the config diff, the submission timestamp, and the current approval state (draft / pending_approval / approved / denied / superseded).
- **ProviderLifecycleEvent**: An immutable audit record of a lifecycle transition (activate, suspend, restore, archive) with actor, reason, and timestamps.
- **PaymentModuleConfig**: Platform-level availability and display order for each payment module/purpose type.

---

## Success Criteria

### Measurable Outcomes

- **SC-001**: A platform operator can complete a full provider account configuration change (draft → submit → approve → live) in under 5 minutes using the admin screens.
- **SC-002**: Secret credential references are never returned to the browser; verified by network inspection showing only masked values in all API responses accessed from P20 screens.
- **SC-003**: Self-approval is rejected 100% of the time; verified by attempting self-approval in automated tests and asserting a 403/422 response in all cases.
- **SC-004**: Suspension of a provider account prevents new option discovery results for that account within 1 request after the suspension is applied.
- **SC-005**: In-flight attempt resolution succeeds on suspended accounts; verified by integration test asserting 0 resolution failures caused by suspension status.
- **SC-006**: All P20 screens meet WCAG 2.1 AA contrast and keyboard-navigation requirements; verified by accessibility audit.
- **SC-007**: TypeScript type-checking and linting produce 0 errors on all P20 source files.
- **SC-008**: The approval workflow correctly invalidates a pending approval when the underlying config is edited; verified by an automated test asserting the 409 conflict response and UI error state.

---

## Assumptions

- Backend APIs for provider account CRUD, lifecycle transitions, draft/approval management, and version history are delivered by P03/P05/P10; P20 calls these APIs and does not introduce new backend routes.
- The existing admin shell (`wallet-ecosystem/apps/admin`), design tokens, and shared form components (including `SecretReferenceField`, `LifecyclePanel`, `BankStatusBadge` from P12 screens) are reused; P20 extends them for provider-specific use cases.
- The adapter allowlist is fetched from `GET /v1/admin/payment/adapters` (delivered by P05/P10); the UI populates the adapter dropdown from this endpoint.
- `payment.providers.read` grants read-only access to the registry; `payment.providers.manage` grants draft/submit/lifecycle access; `payment.config.approve_high_risk` grants approval access — all three permissions were seeded by P03.
- Module availability configuration maps to the `PaymentModuleConfig` entity in the backend; if this entity is not yet persisted (TBD from P05), a temporary local `payment_module_config` table or platform config entry is used and documented as provisional.
- The `environment` field on `PaymentProviderAccount` distinguishes sandbox, test, and production accounts; this field is read-only from the UI.
- Denied drafts remain visible in the timeline for audit purposes but are never resubmitted; a denied change must be re-drafted from scratch.
- P20 follows the existing Next.js App Router conventions used throughout `wallet-ecosystem/apps/admin`; no new routing strategy is introduced.
