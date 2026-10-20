# Feature Specification: ADM-P1-014 — Fee Rules Management

**Feature Branch**: `038-fee-rules-management`

**Created**: 2026-10-14

**Status**: Draft

## Overview

Completes the Fee Rules Management section of the OleKard Admin Portal. The create-rule page and list page exist but are missing: fee rule detail view (with edit support), deactivation capability, and fee-impact simulation. This epic wires those gaps to the live E21 backend APIs.

## Actors

- **Operations Admin** — Views, creates, updates, deactivates fee rules; simulates fee impact before making changes
- **Platform** — Enforces RBAC; records audit events; applies active rules to live transactions

## User Scenarios & Testing

### User Story 1 — Admin creates a fee rule (US-P1-014-001, Priority: P1)

An operations admin navigates to the fee rules section, clicks "Create Rule", fills in the rule form (transaction type, fee type, rate or fixed amount, currency, optional bounds, effective dates), and submits. The rule is saved with `status = active` and appears immediately in the list. The admin is protected from creating a duplicate active global rule for the same transaction type and currency.

**Why this priority**: Creating rules is the prerequisite gate — no deactivation or simulation is meaningful without at least one rule existing. The create form already exists; this story closes the remaining UX gaps (validation feedback, 409 conflict handling, navigation to detail after creation).

**Independent Test**: Navigate to `/fees/rules/create`, fill all required fields (transaction_type = transfer, fee_type = percentage, rate = 1.5, currency = BWP, subject_type = global, valid_from = today), submit — the form posts to `POST /v1/fees/rules` with an Idempotency-Key header, the API returns 201, and the admin is redirected to the new rule's detail page at `/fees/rules/{id}`.

**Acceptance Scenarios**:

1. **Given** no active global transfer BWP rule exists, **When** I fill and submit the create form, **Then** the backend returns 201 with the new rule, I am redirected to `/fees/rules/{id}`, and a success toast confirms "Fee rule created."
2. **Given** an active global transfer BWP rule already exists, **When** I submit a second active global transfer BWP rule, **Then** the form shows a non-dismissable error banner: "A conflicting active fee rule already exists for this transaction type and currency" (HTTP 409 mapped to user-facing message).
3. **Given** I submit with `rate = 0` on a percentage rule, **Then** inline validation blocks submission: "Rate must be greater than 0."
4. **Given** I submit with `min_fee_minor > max_fee_minor`, **Then** inline validation blocks submission: "Minimum fee cannot exceed maximum fee."
5. **Given** I submit with `subject_type = agent` but no `subject_id`, **Then** inline validation blocks submission: "Agent ID is required."
6. **Given** an unauthenticated user, **When** they attempt to access the create page, **Then** they are redirected to `/login`.
7. **Given** a user without `fees.rules.create` permission, **When** they load the create page, **Then** they see a 403 error page.

---

### User Story 2 — Admin activates/deactivates a fee rule (US-P1-014-002, Priority: P1)

An operations admin navigates to a fee rule detail page. The page shows all rule attributes, the current status badge, and a prominent "Deactivate" button for active rules. Clicking "Deactivate" shows a confirmation dialog with a warning about the financial impact. On confirmation the rule status transitions to `deactivated` and the button changes to a disabled "Deactivated" state. An audit event `FeeRuleDeactivated` is recorded on the backend.

**Why this priority**: Fee rule lifecycle management (deactivating outdated rules before creating replacements) is a core operations workflow. Without deactivation, stale rules continue applying fees to live transactions.

**Independent Test**: Open an active fee rule detail page, click "Deactivate", confirm in the dialog — the API calls `DELETE /v1/fees/rules/{id}`, returns 204, the status badge updates to "Deactivated", the Deactivate button is replaced with a disabled "Deactivated" indicator, and the fee rules list reflects the new status on next load.

**Acceptance Scenarios**:

1. **Given** I am on the detail page of an active rule, **When** I click "Deactivate" and confirm, **Then** `DELETE /v1/fees/rules/{id}` is called, the status badge updates to "Deactivated", and a success toast fires.
2. **Given** I am on the detail page of a deactivated rule, **Then** no "Deactivate" button is shown; the status badge shows "Deactivated" and a read-only note explains "This rule has been deactivated and is no longer applied to transactions."
3. **Given** I am on the detail page of an expired rule (valid_to in the past), **Then** the status badge shows "Expired" and no deactivation action is available (rule is already inactive).
4. **Given** a user without `fees.rules.delete` permission, **When** they view a rule detail page, **Then** the "Deactivate" button is not rendered.
5. **Given** the deactivation API call fails with a network error, **Then** the dialog closes, an error toast shows "Failed to deactivate rule. Please try again.", and the rule status does not change in the UI.

---

### User Story 3 — Admin simulates fee impact (US-P1-014-003, Priority: P2)

From the fee rule detail page, an admin can open a "Simulate" panel. They enter a hypothetical transaction amount and optionally specify a subject type. The panel calls the fee calculation API and displays the calculated fee amount, the net amount after fee deduction, and whether any min/max bounds were applied. No transaction is created — this is a pure read-only preview used to verify a rule's behaviour before it goes live.

**Why this priority**: P2 after create and deactivate because simulation is a verification tool. Operations can configure rules without it, but simulation prevents misconfiguration errors on financial-impact rules.

**Independent Test**: On the detail page of a 0.5% transfer rule with `min_fee_minor = 100`, enter amount BWP 100 (10000 minor units), click "Calculate" — the panel calls `POST /v1/fees/calculate` with `{transaction_type, amount_minor: 10000, currency: BWP, subject_type: global}`, returns `fee_amount_minor: 100, min_fee_applied: true`, and the panel shows "Fee: BWP 1.00 (minimum fee applied), Net amount: BWP 99.00."

**Acceptance Scenarios**:

1. **Given** an active 0.5% transfer rule (no bounds), **When** I simulate BWP 1,000, **Then** the panel shows "Fee: BWP 5.00, Net amount: BWP 995.00" with `min_fee_applied: false, max_fee_applied: false`.
2. **Given** a 0.5% rule with `min_fee_minor = 100`, **When** I simulate BWP 100, **Then** the panel shows "Fee: BWP 1.00 (minimum fee applied), Net amount: BWP 99.00."
3. **Given** a 0.5% rule with `max_fee_minor = 500`, **When** I simulate BWP 200,000, **Then** the panel shows "Fee: BWP 5.00 (maximum fee applied), Net amount: BWP 1,995.00."
4. **Given** no active rule for the selected transaction type, **Then** the panel shows "No fee rule found — this transaction type currently incurs no fee."
5. **Given** I enter a non-numeric amount, **Then** inline validation blocks the API call: "Enter a valid amount."
6. **Given** a deactivated rule's detail page, **When** I open the Simulate panel, **Then** the panel uses the rule's `transaction_type` and `currency` as defaults but makes clear it simulates against the **currently active** rule (or shows "no active rule" if none exists).

---

### Edge Cases

- What happens when the fee rules list is empty? Show an empty state with a "Create your first fee rule" call to action.
- What happens if a rule is expired (valid_to in the past)? Status badge shows "Expired"; no activate/deactivate controls are shown.
- What happens if the simulation API returns a 422? Show the API error message in the simulation panel without a toast.
- What happens if `amount_minor` in simulation exceeds the maximum integer value? Validate client-side with a reasonable cap (e.g., 999,999,999 minor units).
- What happens when the create form is submitted with the same Idempotency-Key (browser back + resubmit)? A new UUID is generated per submit attempt; the previous idempotent result may be replayed — the UI handles both 201 (new) and 200 (replayed) by navigating to the returned rule ID.

## Requirements

### Functional Requirements

- **FR-001**: The fee rules list MUST display all rules with columns: Transaction Type, Fee Type, Fee Amount/Rate, Subject, Status, Valid From; rows MUST be clickable to navigate to the rule detail page.
- **FR-002**: The create rule form MUST send `POST /v1/fees/rules` with an `Idempotency-Key` header and redirect to the created rule's detail page on success.
- **FR-003**: The system MUST display server-side validation errors (422) inline on the relevant form field.
- **FR-004**: The system MUST display a user-readable error for 409 Conflict (duplicate active rule) without showing raw API error codes.
- **FR-005**: The fee rule detail page MUST display all rule attributes: ID, transaction type, fee type, rate/amount, currency, subject scope, status, valid range, min/max bounds, created by, created at, updated at.
- **FR-006**: The detail page MUST show a "Deactivate" button only for rules with `status = active` and only for users with `fees.rules.delete` permission.
- **FR-007**: Deactivation MUST require an explicit confirmation step (modal/dialog) before calling the API.
- **FR-008**: The simulation panel MUST call `POST /v1/fees/calculate` and display: calculated fee (human-readable), net amount, fee type applied, whether min/max bounds were applied.
- **FR-009**: The simulation panel MUST be read-only and MUST NOT create any transaction or ledger record.
- **FR-010**: All fee rules pages MUST enforce RBAC client-side (hide buttons for missing permissions) and rely on server-side enforcement for the actual API calls.
- **FR-011**: The fee rules list MUST support filtering by transaction type and status (already partially implemented).
- **FR-012**: The fee rules list MUST link each row to the corresponding detail page (`/fees/rules/{id}`).

### Key Entities

- **FeeRule**: A configurable rule that determines the fee charged for a transaction type. Attributes: id (UUID v7), transaction_type, fee_type (fixed|percentage), rate (percentage rules), amount_minor (fixed rules), min_fee_minor, max_fee_minor, currency, subject_type (global|customer|agent), subject_id (nullable), valid_from, valid_to (nullable), status (active|expired|deactivated|superseded), created_by, created_at, updated_at.
- **FeeCalculationResult**: A transient, read-only computation result — not persisted. Contains: fee_amount_minor, fee_type, rule_id (which rule was applied), has_applicable_rule, min_fee_applied, max_fee_applied.

## Success Criteria

### Measurable Outcomes

- **SC-001**: An admin can create a new fee rule, view its detail, and deactivate it in under 60 seconds from the fee rules list.
- **SC-002**: The simulation panel returns a fee calculation result within 2 seconds of clicking "Calculate" on a normal network connection.
- **SC-003**: All form validation errors are visible without scrolling on standard desktop viewport (1280px wide).
- **SC-004**: 100% of user actions that modify fee rules (create, deactivate) are reflected in the UI state without requiring a full page reload.
- **SC-005**: Zero raw API error codes (e.g., `FEE_RULE_CONFLICT`, `INVALID_FEE_RATE`) are exposed to the end user — all errors are mapped to human-readable messages.
- **SC-006**: All interactive controls (Create, Deactivate, Simulate) are keyboard-accessible and have appropriate ARIA labels.

## Dependencies

- **E21 backend (COMPLETE/RELEASE READY)**: `GET/POST /v1/fees/rules`, `GET/PATCH/DELETE /v1/fees/rules/{id}`, `POST /v1/fees/calculate`
- **ADM-FOUND-001 (EXISTS)**: AdminShell, PageContainer, PageHeader, Card, Badge, Button, Table, Loading, ErrorState components
- **Existing code (EXISTS)**: `fee-rules-list.tsx` (list page), `fee-rule-create.tsx` (create form), `app/(admin)/fees/rules/page.tsx`, `app/(admin)/fees/rules/create/page.tsx`

## Assumptions

- The backend uses `/v1/fees/rules` (not `/v1/admin/fees/rules`) — confirmed by the existing `fee-rule-create.tsx` which already calls `/v1/fees/rules`.
- Deactivation uses `DELETE /v1/fees/rules/{id}` (E21 contract), not a separate `/activate` endpoint as suggested by the catalogue planning note.
- There is no "re-activate" endpoint — a deactivated rule cannot be re-activated; a new rule must be created instead.
- Fee simulation uses `POST /v1/fees/calculate` (E21 contract), not `/v1/fees/simulate` as in the catalogue planning note.
- The admin catalogue's mention of "approval workflow" for fee rules is out of scope for this epic; E21 does not implement maker-checker for fee rules.
- Tiered fee type mentioned in the catalogue is not in the E21 contract or backend implementation; only `fixed` and `percentage` are supported.
