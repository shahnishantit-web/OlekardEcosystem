# Feature Specification: P21 — Admin Finance Dashboard & Operations Screens

**Feature Branch**: `068-admin-finance-dashboard`

**Created**: 2026-10-26

**Status**: Draft

**Input**: Execute P21 — Admin finance dashboard and operations screens. Predecessors: P17 P18 P19 P20. Source: AD01 AD06–AD11; LC11–LC15.

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Finance Operations Dashboard (Priority: P1)

A finance operator opens the payments section of the admin panel and immediately sees a scoped overview: today's payment volume and counts by status, a live attempt timeline showing the last 50 intents, flagged fulfilment backlog items (captured but not fulfilled), and a safe "query provider status" action for individual intents. All monetary totals are currency-separated. The dashboard shows a freshness timestamp and a stale-data warning when the data is older than a configurable threshold.

**Why this priority**: This is the entry-point for all daily finance operations. Without it operators cannot triage issues before they escalate.

**Independent Test**: Can be fully tested by loading `/payments/finance` with a seeded dataset and verifying KPI tiles, timeline list, and backlog table render correctly with accurate totals — independent of approval workflows.

**Acceptance Scenarios**:

1. **Given** a finance operator with `payment.history.read.platform` permission, **When** they navigate to the finance dashboard, **Then** they see KPI tiles for today's volume (per currency), attempt counts by status (captured/fulfilled/failed/pending), and fulfilment backlog count.
2. **Given** the dashboard data was fetched more than 5 minutes ago (configurable), **When** the operator views the page, **Then** a stale-data banner is shown with a manual refresh action.
3. **Given** an intent in `captured` state with no fulfilment record, **When** it appears in the backlog, **Then** the operator can trigger a safe read-only "query provider status" action that dispatches an async job (returns 202); a "Status query dispatched" confirmation is shown immediately; the provider is called without mutating state; the operator must refresh the page to see the updated status once the job completes.
4. **Given** a finance operator without `payment.history.read.platform`, **When** they navigate to any finance screen, **Then** they see an access-denied message and cannot view any financial data.
5. **Given** the backend is unavailable, **When** the operator loads the dashboard, **Then** they see a graceful error state with a retry button; no partial data is shown.

---

### User Story 2 — Payment Investigation (End-to-End Drill-Down) (Priority: P1)

A finance operator investigates a stuck captured payment by navigating from the dashboard backlog to a payment detail view. The detail view shows the full attempt timeline (intent created → attempt submitted → provider response → ledger posted → fulfilment dispatched), linked ledger journal entries (displayed as debit/credit rows with amounts and account names — no raw IDs), the fulfilment status, and a "safe status query" action. Sensitive fields (callback secrets, raw provider references) are masked or absent. The operator can navigate from any journal entry to the broader audit trail.

**Why this priority**: The core acceptance requirement — "an authorised operator can investigate a stuck captured order end to end" — is the primary DoD for this package.

**Independent Test**: Navigating to `/payments/finance/intents/{id}` for a seeded intent with a full lifecycle delivers a complete drill-down view. No write actions required.

**Acceptance Scenarios**:

1. **Given** an intent with multiple attempts, **When** the operator opens the intent detail, **Then** they see a chronological timeline: intent created, each attempt (with provider code, status, timestamps), ledger acknowledgement, and fulfilment dispatch.
2. **Given** a ledger journal exists for the intent, **When** the operator views the intent detail, **Then** they see journal line items (debit/credit, account names, amount, currency) and a link to the full audit-log entry for that journal — no wallet IDs or raw UUIDs exposed in labels.
3. **Given** a masked credential field on the provider account, **When** the intent detail renders callback-related info, **Then** the raw secret reference is never shown — only a `****` mask or the key name.
4. **Given** an operator clicks "Query Provider Status" on a captured intent, **When** the action completes, **Then** the response is shown inline with the timestamp and a note that no state change was made; the action is idempotent and safe to repeat.
5. **Given** a support-role user (no `payment.intents.admin` permission), **When** they access the intent detail, **Then** they see read-only summary data but the journal link, status-query action, and provider reference fields are hidden.

---

### User Story 3 — Refund Approval & Verified Payout Operations (Priority: P2)

A finance approver reviews the pending refund and payout queues. Each item shows the original payment summary, requested amount, requestor, reason, and a link to the originating intent. The approver can approve or reject with a mandatory reason. Approved refunds post a compensating ledger entry via the existing refund engine; approved payouts advance the payout lifecycle. Support staff cannot see the approve/reject controls. All approvals are audited. Controlled replay uses the original operation ID and cannot invent a success outcome.

**Why this priority**: Core financial governance — "support cannot approve refund/payout" is an explicit acceptance criterion.

**Independent Test**: With a pending refund in the database, a user with `payment.refund.approve` can approve it and the ledger entry is created; a user without that permission sees the item but not the approve button.

**Acceptance Scenarios**:

1. **Given** a pending refund request, **When** an approver opens the refund queue, **Then** they see the original amount, currency, intent reference, requestor, and reason.
2. **Given** an approver clicks "Approve Refund", **When** the action is confirmed, **Then** a compensating ledger entry is created via the refund engine with the original operation ID preserved; the item moves to "approved" state.
3. **Given** a support user views the refund queue, **When** the page renders, **Then** no approve/reject buttons are rendered; only the status and summary are visible.
4. **Given** a payout in `pending_approval` state, **When** a payout approver approves it, **Then** the payout lifecycle advances to `approved` and a confirmation is shown with the updated payout state.
5. **Given** a refund is re-submitted with the same idempotency key (controlled replay), **When** the system processes it, **Then** the original result is returned without a duplicate ledger posting.
6. **Given** an approver rejects a refund, **When** they submit the rejection, **Then** a mandatory rejection reason is required and recorded in the audit log.

---

### User Story 4 — Disputes & Reconciliation Exceptions (Priority: P2)

A finance operator views the disputes list and reconciliation exceptions queue. Each exception shows type, provider code, amount, status (open/investigating/escalated/resolved), and linked case references. The operator can update the status and add investigation notes. Disputes show chargeback state, linked support case (if any), and the associated payment intent. The screens support filters by status, type, provider, and date range.

**Why this priority**: Required to fulfil "totals reconcile" acceptance criterion and "investigate a stuck captured order" operational requirement.

**Independent Test**: With seeded reconciliation exceptions, the exceptions list renders with correct filters, status badges, and allows status updates — independent of the approval queue.

**Acceptance Scenarios**:

1. **Given** reconciliation exceptions exist, **When** the operator opens the exceptions screen, **Then** they see a filterable table with type, provider code, amount, currency, status, and age.
2. **Given** an exception in `open` status, **When** the operator updates it to `investigating` with notes, **Then** the status changes and the notes are persisted and visible in the detail view.
3. **Given** a dispute with a linked support case, **When** the operator views the dispute detail, **Then** a link to the support case is shown (case ID, link); no case content is duplicated.
4. **Given** the operator filters exceptions by `type=amount_mismatch` and `status=open`, **When** the filter is applied, **Then** only matching exceptions are shown and the count updates.
5. **Given** an exception is resolved, **When** it appears in the list, **Then** it is visually distinct (e.g., muted row) and excluded from the default "open issues" view.

---

### User Story 5 — Financial Reports & Audit Evidence (Priority: P3)

A finance user generates a filtered report of payment activity for a selected date range, currency, provider, and module. The report shows totals by status, currency-separated breakdowns, and a freshness/cutoff timestamp indicating the data's authoritative point-in-time. Reports can be exported. Exports run asynchronously and a progress indicator is shown. Audit evidence (who approved, when, what changed) is accessible per item via a link to the audit log. Financial totals are kept distinct from domain-specific totals (e.g., funeral contribution counts are not mixed with transfer amounts).

**Why this priority**: Important for compliance and reconciliation but can be delivered after core investigation and approval workflows are live.

**Independent Test**: With seeded payment data, the reports screen renders a summary table for a date range with currency-separated totals, and the export button triggers an async job with progress feedback.

**Acceptance Scenarios**:

1. **Given** a finance user with reporting access, **When** they apply date-range and currency filters, **Then** the report shows payment volume, success rate, failed count, and total amounts — all currency-separated.
2. **Given** an export is triggered, **When** the user waits, **Then** an "export in progress" indicator is shown; when complete, a download link appears — no page reload required.
3. **Given** a report covers multiple currencies, **When** it renders, **Then** BWP and non-BWP totals are shown in separate rows and never summed together.
4. **Given** an approved refund appears in the report, **When** the operator clicks the audit icon, **Then** they are taken to the audit log entry showing actor, timestamp, and action — with no raw secrets or credentials visible.
5. **Given** a report freshness cutoff exists (e.g., settlement runs at midnight), **When** the report is rendered, **Then** the cutoff timestamp is shown and a note clarifies that data after the cutoff is not yet reconciled.

---

### Edge Cases

- What happens when an intent has zero attempts (created but never submitted)? — Show "no attempts" state inline on the timeline.
- How does the system handle a provider status query that times out? — The async job fails silently on the backend; the UI shows the dispatch confirmation as before. The operator can click the button again to re-dispatch. No timeout error is shown in the UI since the call is asynchronous.
- What if a refund approval fails at the ledger layer? — Show the server error message inline; the refund item reverts to pending; the operator can retry.
- What if the export backend returns an error mid-job? — Show an error state with retry; partial download must not be offered.
- What if two approvers attempt to approve the same refund concurrently? — The second approval receives a conflict response (409); a "already processed" message is shown.
- What happens when a reconciliation exception's linked case is deleted? — Show the case ID as plain text with a "case not found" label; no broken links.
- How are very large fulfilment backlogs handled? — Pagination with a max-page-size of 50; server-side sorted by age (oldest first).

## Requirements *(mandatory)*

### Functional Requirements

**Dashboard & Timeline**

- **FR-001**: The system MUST display a finance operations dashboard scoped to the authenticated operator's permissions, showing payment volume, attempt counts by status, and fulfilment backlog count — all currency-separated.
- **FR-002**: The dashboard MUST show a freshness timestamp and a stale-data warning when data age exceeds a configurable threshold (default 5 minutes).
- **FR-003**: The system MUST provide a filterable, paginated payment intent list showing intent ID, status, amount, currency, provider, created date, and fulfilment state.
- **FR-004**: The system MUST provide an intent detail view with a full chronological attempt timeline, linked ledger journal entries (shown as debit/credit line items), and fulfilment status.
- **FR-005**: The system MUST provide a safe "query provider status" action on captured intents that calls the provider in read-only mode, displays the response inline, and cannot mutate payment state.

**Refund & Payout Operations**

- **FR-006**: The system MUST display a refund approval queue visible only to users with `payment.refunds.approve` permission; approve and reject controls MUST NOT be rendered for any other role.
- **FR-007**: Approving a refund MUST post a compensating ledger entry via the shared refund engine using the original operation ID as the idempotency key.
- **FR-008**: Rejecting a refund MUST require a mandatory reason that is recorded in the audit log.
- **FR-009**: The system MUST display a payout approval queue; advancing a payout MUST use the existing payout lifecycle endpoint and MUST NOT invent a success outcome.
- **FR-010**: Controlled replay of a refund or payout action MUST use the original operation ID and MUST return the original result without creating duplicate ledger entries.

**Disputes & Reconciliation Exceptions**

- **FR-011**: The system MUST display the reconciliation exceptions list with filters for type, status, provider, and date range.
- **FR-012**: An operator MUST be able to update an exception's status and add investigation notes; changes MUST be audited.
- **FR-013**: The disputes list MUST show chargeback state, linked support case reference (read-only link), and associated intent ID.
- **FR-014**: Resolved exceptions MUST be visually distinct and excluded from the default "open issues" view.

**Reports & Audit Evidence**

- **FR-015**: The system MUST provide a filterable payment report with date range, currency, provider, and module filters; totals MUST be shown currency-separated and MUST NOT mix financial totals with domain-specific event counts.
- **FR-016**: Report exports MUST run asynchronously; an in-progress indicator MUST be shown and the download link MUST only appear when the export is complete.
- **FR-017**: Each reportable item MUST provide a link to its audit log entry showing actor, timestamp, action, and changed fields — without exposing secrets or credentials.
- **FR-018**: Reports MUST display a data freshness cutoff timestamp and a note clarifying what data falls outside the reconciled window.

**Authorization & Security**

- **FR-019**: All finance screens MUST enforce server-side permission checks; UI visibility is supplementary and MUST NOT be the sole access control.
- **FR-020**: Callback secrets, raw provider credentials, and internal secret references MUST NEVER be rendered in any finance screen — masked display (`****`) or key-name only is permitted.
- **FR-021**: The system MUST show an access-denied state (not an error) when a user navigates to a screen they lack permission to view.

**States & Error Handling**

- **FR-022**: Every list and detail view MUST implement: loading skeleton, empty state, error state with retry, and permission-denied state.
- **FR-023**: Conflict responses (409) from concurrent operations MUST be surfaced as user-readable messages explaining the conflict.
- **FR-024**: Export-in-progress state MUST prevent duplicate export submissions for the same report parameters.

### Key Entities

- **Payment Intent**: A customer's payment request — has status (created/captured/fulfilled/failed/cancelled), amount, currency, provider, multiple attempts.
- **Payment Attempt**: A single provider call for an intent — has status, provider response, timestamps, attempt number.
- **Ledger Journal Entry**: An immutable double-entry record — has debit/credit lines, account names, amount, currency, reference to intent.
- **Fulfilment Record**: Tracks whether a captured payment has been fulfilled — has status, dispatched time, completion time.
- **Refund Request**: A request to reverse a captured/fulfilled payment — has status (pending/approved/rejected/completed), amount, reason, requestor, original intent reference.
- **Payout Record**: A verified outbound payment to a beneficiary — has lifecycle status (pending_approval/approved/processing/settled/failed), amount, currency, provider.
- **Reconciliation Exception**: A mismatch or anomaly between platform records and provider data — has type, status, provider, amount, notes.
- **Dispute / Chargeback**: A challenged transaction — has chargeback state, linked support case ID, associated intent.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An authorised finance operator can navigate from the dashboard backlog to a complete intent drill-down (timeline + journal + fulfilment) in under 3 clicks.
- **SC-002**: All finance list screens load within 2 seconds for datasets up to 1,000 records.
- **SC-003**: Refund and payout approval actions complete with immediate visual confirmation; the queue item moves to the correct status without page reload.
- **SC-004**: Zero credentials, secrets, or raw provider references are visible in any finance screen under any user role.
- **SC-005**: Support-role users attempting to access approve/reject controls receive an access-denied response from the server (not just a hidden button).
- **SC-006**: Currency totals in reports are always separated — no mixed-currency aggregates; a BWP total is never added to a USD total.
- **SC-007**: 100% of approval/rejection actions are recorded in the audit log with actor identity, timestamp, and outcome before the response is returned to the UI.
- **SC-008**: Export completion is indicated to the user within 30 seconds of the export job finishing for reports up to 10,000 rows.

## Assumptions

- Backend APIs for payment intents, attempts, journals, refunds, payouts, disputes, reconciliation exceptions, and reports are delivered by P17–P20 (E64–E67) and the earlier payment architecture packages; this spec covers only the admin UI layer.
- The "query provider status" action is a read-only API endpoint already available from E55 (payment state engine); this spec does not add a new backend capability.
- Export jobs run on the backend via an existing job queue (Laravel Horizon); the frontend polls for completion using an export job status endpoint that is assumed available from E24 (reporting & analytics).
- Permission codes used (`payment.monitor`, `payment.investigate`, `payment.refund.approve`, `payment.payout.approve`) are assumed to be declared in the backend permissions registry; this spec does not define new backend permissions.
- All monetary amounts are stored and transmitted in minor units (integer pence/cents); display formatting (e.g., "BWP 150.00") is handled by existing formatting utilities.
- The admin application's design system (E35/E36/E37) and existing layout shell are available for reuse; no new design tokens are required.
- Data freshness threshold (default 5 minutes) is read from the system configuration service; it is not hard-coded.
- A support role exists in the RBAC system (E04) with view-only permissions on payment data; this spec assumes that role and its permissions are already seeded.
- The audit log link in reports points to the existing audit log viewer (E40) filtered by entity ID; no new audit viewer is built.
- Permission codes used in this spec correspond to the canonical codes confirmed in the backend route middleware (`routes/modules/payment.php`) and documented in `research.md` Decision 4. Where this spec and research.md differ, research.md Decision 4 is authoritative. Specifically: `payment.history.read.platform` (dashboard), `payment.intents.admin` (intent timeline/status-query), `payment.refunds.approve` (refund approval), `payment.payout.approve` (payout approval), `payment.disputes.view/manage` (disputes), `payment.reconciliation.view/manage` (exceptions), `payment.export.read.organisation` (reports).
