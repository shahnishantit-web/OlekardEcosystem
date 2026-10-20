# Feature Specification: E14 — Agent Commission

**Feature Branch**: `014-agent-commission`

**Created**: 2026-09-23

**Status**: Draft

## Overview

Agents in the OleKard ecosystem earn commissions for the cash-in and cash-out transactions they facilitate on behalf of customers. This feature introduces the full commission lifecycle: admin-configurable commission rules (flat, percentage, and tier-based), automatic calculation and ledger recording when qualifying transactions complete, accumulation of pending commissions, admin-controlled payout approval, and crediting the agent's float wallet when payouts are executed.

## Clarifications

### Session 2026-09-23

- Q: Can agents configure their own commission rates or are all rules admin-managed? → A: Admin-only — commission rules are exclusively defined and managed by authorized admins; agents cannot create or modify their own commission rates.
- Q: Where is commission payout credited — the agent's float wallet, a dedicated commission wallet, or a separate account? → A: Agent's float wallet — commission payouts credit the agent's existing float wallet (from E13), increasing their available liquidity.
- Q: Does commission apply only to float-mediated cash-in/cash-out (E13) or also to future transaction types (service payments, QR payments)? → A: Float transactions in scope — this epic covers commissions earned from cash-in and cash-out operations facilitated through the agent float module (E13); extension points for future transaction types are not in scope.
- Q: What does "agent category" mean in the context of commission rule scoping, and where does it come from? → A: E12 agent tier — `agent_category` on a CommissionRule is a nullable FK reference to the agent's tier as defined in E12 (e.g., `agent_tier_id`); no new entity is required. A rule with a non-null `agent_category` applies only to agents of that tier; a rule with `agent_category = null` applies globally to all agents.
- Q: How is commission payout execution triggered — automatically on approval, or as a separate explicit admin step? → A: Automatic on approval — payout execution is triggered immediately and synchronously as part of the single approval API call; there is no separate execute endpoint. The `CommissionPayout` record is created, execution is attempted immediately: on success it transitions `approved → completed`; if the agent is suspended it stays `deferred`; on ledger failure it transitions to `failed` for retry.
- Q: When a qualifying transaction is reversed but its commission is still `pending`, should the commission be cancelled directly or should a compensating ledger entry still be posted? → A: Compensating entry always — regardless of payout status (`pending` or `paid`), a commission reversal MUST post a compensating journal (`debit commission_payable:{agentId}` / `credit commission_expense:{currency}`) and transition the commission to `reversed`. For `pending` commissions this restores the `commission_payable` credit posted at calculation time; for `paid` commissions it additionally requires debiting the agent's float wallet.
- Q: Can an operations admin cancel an approved (or deferred) CommissionPayout before it completes? → A: Deferred only — admins can cancel a `CommissionPayout` in `deferred` status; cancellation transitions the payout to `cancelled` and reverts all linked commissions from `approved` back to `pending` with no ledger impact. `completed` and `failed` payouts cannot be cancelled.
- Q: Should the commission report (US9) support CSV export as part of this epic, or is downloadable export deferred to E24 Reporting & Analytics? → A: Deferred to E24 — the commission report in this epic provides paginated API access only; CSV/PDF export is out of scope and belongs to E24 Reporting & Analytics which owns the platform-wide export infrastructure.

## Actors

- **Agent** — Earns commissions through facilitated cash-in and cash-out transactions; views earned and pending commission balances
- **Operations Admin** — Configures commission rules, approves commission payout batches, views system-wide commission state
- **Platform (System)** — Automatically calculates and records commissions when qualifying transactions complete; executes payouts post-approval

## User Scenarios & Testing

### User Story 1 — Commission Rule Configuration (Priority: P0)

An operations admin defines how agents earn commissions for facilitated transactions. Rules specify the commission basis (flat amount or percentage of transaction value), the transaction types they apply to (cash-in, cash-out, or both), an effective date range, and optionally an agent tier (from E12) that scopes the rule to agents of that tier. A rule with no tier scope applies globally to all agents. Without at least one active rule, no commissions are calculated.

**Why this priority**: Blocking prerequisite — the system cannot calculate any commission without at least one configured rule.

**Independent Test**: Admin creates a flat BWP 2.00 commission rule for cash-in transactions; the rule is retrievable and active; the system applies it when the next cash-in event triggers commission calculation.

**Acceptance Scenarios**:

1. **Given** no existing commission rule for cash-in, **When** an admin POSTs a flat BWP 2.00 rule effective immediately, **Then** the rule is saved with status `active`, a `CommissionRuleCreated` audit event is emitted, and the rule is returned with a unique rule ID.
2. **Given** an existing active rule, **When** an admin PATCHes the rate, **Then** the old rule is transitioned to `superseded` status, a new active rule version is created, and the change is audit-logged with before/after state.
3. **Given** a rule with a `valid_to` date in the past, **When** a transaction triggers commission calculation, **Then** the expired rule is not applied; if no current rule covers the transaction type, no commission is recorded.
4. **Given** an unauthorized actor (non-admin), **When** they attempt to create or modify a commission rule, **Then** the request is rejected with `UNAUTHORIZED`.
5. **Given** a zero-rate or negative-rate submission, **When** submitted, **Then** the request is rejected with `INVALID_COMMISSION_RATE`.

---

### User Story 2 — Transaction Commission Calculation (Priority: P0)

When an agent completes a cash-in or cash-out transaction through the agent float module (E13), the platform automatically resolves the highest-priority applicable commission rule and calculates the commission amount. The commission is recorded as a `pending` entry associated with the originating transaction ID, the agent, and the applied rule.

**Why this priority**: Core revenue mechanism — commissions must be calculated at transaction time so each commission has a traceable, immutable link to its originating event.

**Independent Test**: Agent completes a BWP 500 cash-in; the platform resolves the active flat-rate rule (BWP 2.00) and creates a `Commission` record with `status = pending`, `amount = 200` minor units, and the cash-in transaction ID as reference.

**Acceptance Scenarios**:

1. **Given** an active flat-rate cash-in rule of BWP 2.00, **When** a BWP 500 cash-in completes, **Then** a `Commission` record is created with `amount = 200` (minor units), `status = pending`, `transaction_type = cash_in`, linked to the transaction ID and agent ID; a `CommissionCalculated` event is emitted.
2. **Given** an active percentage-based rule of 0.4% for cash-out, **When** a BWP 1,000 cash-out completes, **Then** the commission amount is BWP 4.00 (400 minor units).
3. **Given** no active rule matching the transaction type, **When** the transaction completes, **Then** no commission record is created; the originating transaction is unaffected.
4. **Given** a commission calculation triggered by the same transaction ID a second time (at-least-once event delivery), **When** replayed, **Then** only one commission record exists — no duplicate is created.
5. **Given** a transaction for a suspended agent, **When** commission calculation is triggered, **Then** no commission is recorded.

---

### User Story 3 — Tier-Based Commission Calculation (Priority: P1)

Agents who exceed monthly transaction volume thresholds earn a higher commission rate. The platform evaluates the agent's cumulative transaction volume for the current calendar month and applies the highest-applicable tier rate. Tier promotion is automatic and re-evaluated on each qualifying transaction.

**Why this priority**: Incentive mechanism for high-volume agents; not required for MVP but needed before go-live to drive agent network growth.

**Independent Test**: An agent's cumulative monthly cash-in/cash-out volume crosses BWP 50,000; the commission for that transaction applies the Tier 2 rate (BWP 3.00) rather than the base Tier 1 rate (BWP 2.00).

**Acceptance Scenarios**:

1. **Given** a Tier 1 rule (rate: BWP 2.00, applicable below BWP 50,000 monthly volume) and a Tier 2 rule (rate: BWP 3.00, applicable at or above BWP 50,000 monthly volume), **When** the agent's cumulative monthly volume crosses BWP 50,000, **Then** subsequent transactions that calendar month use the Tier 2 rate.
2. **Given** the first day of a new calendar month, **When** the monthly volume counter resets to zero, **Then** the agent returns to the lowest applicable tier for that month.
3. **Given** an agent with no tier-based rule (only a flat-rate rule), **When** commission is calculated, **Then** the flat-rate rule applies regardless of transaction volume.
4. **Given** two tier rules with overlapping boundaries, **When** the agent qualifies for both, **Then** the higher rate applies (agent-favorable resolution).

---

### User Story 4 — Commission Ledger Recording (Priority: P0)

Every calculated commission is posted as a balanced double-entry ledger journal via the E07 LedgerService: debiting a platform commission expense account and crediting the agent's commission payable account. This creates an immutable, auditable financial record of the platform's obligation to the agent. The journal is posted at the time the commission is calculated — not when it is paid out.

**Why this priority**: Financial integrity requirement — commissions are a real financial obligation; they must appear in the double-entry ledger immediately to prevent balance discrepancies and to satisfy the constitution's financial integrity principle.

**Independent Test**: A BWP 2.00 commission is calculated; a balanced double-entry journal is posted — `debit commission_expense:{currency}` / `credit commission_payable:{agentId}` — and the journal ID is stored on the `Commission` record.

**Acceptance Scenarios**:

1. **Given** a commission amount of BWP 2.00 is calculated, **When** the commission record is created, **Then** a double-entry journal is posted with `debit commission_expense:{currency}` / `credit commission_payable:{agentId}`; the returned journal ID is stored on the Commission record; `commission_expense` debits equal `commission_payable` credits.
2. **Given** the platform commission expense account is not configured (TBD-E14-001), **When** commission recording is attempted, **Then** the commission record is not persisted, a `CommissionLedgerPostFailed` event is emitted, and the originating transaction is unaffected.
3. **Given** a `pending` commission whose originating transaction is subsequently reversed, **When** the reversal event is received, **Then** a compensating journal is posted (`debit commission_payable:{agentId}` / `credit commission_expense:{currency}`) and the commission transitions to `reversed`; the agent's float wallet is not affected (no money was paid out).
4. **Given** a `paid` commission whose originating transaction is subsequently reversed, **When** the reversal event is received, **Then** a compensating journal is posted (`debit commission_payable:{agentId}` / `credit commission_expense:{currency}`) AND a float wallet debit journal is posted (`debit agent_float:{walletId}` / `credit commission_payable:{agentId}`); the commission transitions to `reversed`; a `CommissionReversed` event is emitted.

---

### User Story 5 — View Earned Commission (Priority: P0)

An agent or admin can view a paginated, date-filtered list of commission records that have been paid out (status `paid`). Each record shows the associated transaction, commission amount, the rule that was applied, and the payout batch reference. This gives agents a transparent record of income received.

**Why this priority**: Agent trust and accountability — agents must be able to verify their commission income matches their transaction activity; this is a zero-friction read operation.

**Independent Test**: After 2 commission payouts, GET returns 2 records with `status = paid`, correct amounts, and non-null payout batch references.

**Acceptance Scenarios**:

1. **Given** an agent with paid commissions, **When** an agent or admin GETs earned commissions, **Then** a paginated list is returned with `id`, `transaction_id`, `transaction_type`, `amount`, `currency_code`, `rule_id`, `payout_id`, `paid_at` for each record.
2. **Given** a date range filter on `paid_at`, **When** applied, **Then** only commissions paid within the specified range are returned.
3. **Given** a user who is neither the owning agent nor an admin, **When** they request earned commission history, **Then** the request is rejected with `UNAUTHORIZED`.

---

### User Story 6 — View Pending Commission (Priority: P0)

An agent or admin can view commissions that have been calculated but not yet paid out (status `pending` or `approved`). The response includes both individual records and an aggregate pending total in minor currency units.

**Why this priority**: Operational visibility — agents need to see accrued but unrealized income; admins need this view to prepare and reconcile payout batches.

**Independent Test**: After 3 qualifying transactions, GET returns 3 pending commission records with a `pending_total` matching the sum of the 3 amounts.

**Acceptance Scenarios**:

1. **Given** an agent with 3 pending commissions, **When** an agent or admin GETs pending commissions, **Then** a paginated list is returned with each commission's details and a `pending_total` aggregate in minor units.
2. **Given** a commission that transitions to `paid` status, **When** the pending list is re-fetched, **Then** the paid commission no longer appears.
3. **Given** an unauthorized user, **When** they request pending commissions, **Then** the request is rejected with `UNAUTHORIZED`.

---

### User Story 7 — Approve and Execute Commission Payout (Priority: P0)

An operations admin selects pending commissions for an agent and approves a payout batch. Approval and execution happen atomically in a single API call: the platform records the approval decision, immediately attempts to credit the agent's float wallet, and transitions commissions to `paid` on success. If the agent is suspended at approval time, the payout is created in `deferred` status and execution is deferred until the agent is reactivated.

**Why this priority**: Financial control gate and revenue delivery — commissions must be reviewed and explicitly approved before money moves, and execution should follow immediately to minimise the window between approval and payment.

**Independent Test**: Admin approves a payout of 3 pending commissions totalling BWP 6.00; the single API call returns with the payout in `completed` status, the agent's float wallet has increased by 600 minor units, and all 3 commissions show `paid`.

**Acceptance Scenarios**:

1. **Given** 3 pending commissions for an active agent, **When** an admin POSTs a payout approval with those commission IDs, **Then** a `CommissionPayout` record is created, execution runs immediately, the agent's float wallet is credited, all 3 commissions transition to `paid`, the payout transitions to `completed`, a `CommissionPayoutApproved` event and a `CommissionPaid` event are emitted, and the action is audit-logged.
2. **Given** a commission already in `approved`, `paid`, or `reversed` status, **When** included in a new payout approval request, **Then** the request is rejected with `COMMISSION_ALREADY_PROCESSED`.
3. **Given** an unauthorized actor (non-admin), **When** they attempt to approve a payout, **Then** the request is rejected with `UNAUTHORIZED`.
4. **Given** a duplicate `Idempotency-Key` approval request, **When** replayed, **Then** the original `CommissionPayout` response is returned without re-processing or double-crediting.

---

### User Story 8 — Deferred and Failed Payout Recovery (Priority: P0)

When payout execution cannot complete at approval time — because the agent is suspended or a ledger failure occurs — the platform records a `deferred` or `failed` status and auto-retries when the blocking condition clears. A suspended agent whose payout was deferred automatically receives their commission when they are reactivated. A `failed` payout (ledger error) can be retried by re-submitting the approval request with the same `Idempotency-Key`.

**Why this priority**: Revenue reliability — agents must eventually receive approved commissions even when transient failures occur at execution time.

**Independent Test**: Admin approves a payout for a suspended agent; the response returns `status = deferred`; when the agent is reactivated via E12, the payout executes automatically and the agent's float wallet is credited.

**Acceptance Scenarios**:

1. **Given** a payout approval where the target agent is suspended, **When** the admin submits the approval, **Then** a `CommissionPayout` record is created with `status = deferred`, no wallet credit occurs, and a `CommissionPayoutDeferred` event is emitted.
2. **Given** a `deferred` payout for an agent who is subsequently reactivated, **When** the agent reactivation event (E12) is received, **Then** the deferred payout executes automatically: the agent's float wallet is credited, all linked commissions transition to `paid`, the payout transitions to `completed`, and `CommissionPaid` is emitted.
3. **Given** a ledger failure during payout execution at approval time, **When** the journal posting fails, **Then** no wallet credit occurs, the `CommissionPayout` transitions to `failed`, commissions remain `pending`, and a `CommissionPayoutFailed` event is emitted.
4. **Given** a `failed` payout, **When** the admin re-submits the approval request with the same `Idempotency-Key`, **Then** the payout is retried and, if the ledger is now available, completes successfully.
5. **Given** a duplicate `Idempotency-Key` for a `completed` payout, **When** replayed, **Then** the original completed result is returned without double-crediting the agent.
6. **Given** a `CommissionPayout` in `deferred` status, **When** an admin DELETEs/cancels the payout, **Then** the payout transitions to `cancelled`, all linked commissions revert to `pending`, a `CommissionPayoutCancelled` event is emitted, the action is audit-logged, and no ledger entries are posted.
7. **Given** an admin attempts to cancel a `completed` or `failed` payout, **When** the cancel request is submitted, **Then** the request is rejected with `PAYOUT_CANCELLATION_NOT_ALLOWED`.

---

### User Story 9 — Commission Report (Priority: P1)

An agent or admin can retrieve a paginated, date-filtered, status-filtered report of all commission records for a specific agent, including totals by status and transaction type. This supports reconciliation and dispute resolution via the API. CSV/PDF export is out of scope for this epic and is deferred to E24 Reporting & Analytics.

**Why this priority**: Operational transparency — full commission history is needed for accounting and agent self-service reconciliation; this is read-only with no financial side effects.

**Independent Test**: After 3 pending, 2 paid, and 1 reversed commission, the report returns all 6 records with correct `totals_by_status` and `totals_by_type` aggregates.

**Acceptance Scenarios**:

1. **Given** an agent with mixed-status commissions, **When** an agent or admin GETs the commission report, **Then** a paginated list of all records is returned along with `totals_by_status` (sum per status) and `totals_by_type` (sum per transaction type).
2. **Given** date range filters on `created_at`, **When** applied, **Then** only commissions created within the range are returned.
3. **Given** a `status` filter (e.g., `pending`), **When** applied, **Then** only commissions with that status are returned.
4. **Given** a `transaction_type` filter (e.g., `cash_in`), **When** applied, **Then** only commissions from cash-in transactions are returned.
5. **Given** an agent with 10,000 commission records, **When** the report is queried without filters, **Then** pagination is applied and the response includes page metadata.
6. **Given** an unauthorized user, **When** they request the commission report, **Then** the request is rejected with `UNAUTHORIZED`.

---

### Edge Cases

- What happens when the platform commission expense account is not configured (TBD-E14-001)? Commission ledger posting fails with `COMMISSION_EXPENSE_ACCOUNT_NOT_CONFIGURED`; the commission record is not persisted; the originating transaction is unaffected.
- What happens if a qualifying transaction is reversed? Regardless of whether the commission is `pending` or `paid`, a compensating ledger entry is always posted (`debit commission_payable:{agentId}` / `credit commission_expense:{currency}`) and the commission transitions to `reversed`. For `pending` commissions this restores the `commission_payable` credit posted at calculation time with no further wallet impact. For `paid` commissions the compensating entry is additionally followed by a debit of the agent's float wallet for the commission amount already received. Partial transaction reversals are out of scope.
- What happens if multiple commission rules match the same transaction? Tier-scoped rules (non-null `agent_tier_id`) take precedence over global rules (null `agent_tier_id`); among same-specificity rules, the most recently created active rule applies.
- What happens if a commission calculation event is replayed due to at-least-once delivery? The `transaction_id` + `transaction_type` combination acts as an idempotency key; no duplicate commission is created.
- What happens at the monthly tier volume reset on the first day of the month? The monthly volume counter resets; the first transaction of the new month always starts tier evaluation from zero.
- What happens when an approved payout has its target agent suspended before execution completes? Payout is created in `deferred` status; execution resumes automatically when the agent is reactivated via E12. If the deferred payout is no longer wanted, an admin may cancel it, reverting commissions to `pending`.
- What happens if an admin cancels a `deferred` payout but some linked commissions have since been individually reversed? The cancellation reverts only the non-reversed commissions to `pending`; `reversed` commissions remain `reversed` and are excluded from the reversion.

## Requirements

### Functional Requirements

- **FR-E14-001**: Authorized admins MUST be able to create, update, and deactivate commission rules specifying: rule type (`flat` or `percentage`), rate, applicable transaction types (`cash_in`, `cash_out`, or `both`), effective date range, and optionally an agent tier scope (nullable FK to E12 agent tier; null = applies to all agents).
- **FR-E14-002**: Commission rule creation and modification MUST be audit-logged with actor ID, timestamp, and before/after state.
- **FR-E14-003**: The system MUST automatically calculate commission for each qualifying cash-in and cash-out transaction using the highest-priority active rule matching the transaction type and the agent's tier: tier-scoped rules take precedence over global (null-tier) rules; among same-specificity rules, the most recently created active rule applies.
- **FR-E14-004**: Commission calculation MUST be idempotent per triggering transaction ID — replaying the same transaction event MUST NOT create a duplicate commission record.
- **FR-E14-005**: The system MUST support tier-based commission rules where higher rates apply once the agent's cumulative monthly transaction volume crosses configurable thresholds.
- **FR-E14-006**: Every calculated commission MUST be posted as a balanced double-entry ledger journal via the E07 LedgerService: `debit commission_expense:{currency}` / `credit commission_payable:{agentId}`.
- **FR-E14-007**: A ledger posting failure MUST NOT affect the originating transaction; the commission record MUST NOT be persisted if ledger posting fails.
- **FR-E14-008**: Agents and authorized admins MUST be able to view earned (paid) commissions in a paginated, date-filtered list.
- **FR-E14-009**: Agents and authorized admins MUST be able to view pending and approved commissions with an aggregate total.
- **FR-E14-010**: Authorized admins MUST be able to approve a batch of pending commissions for payout via a single API call that atomically creates a `CommissionPayout` record and immediately attempts execution: crediting the agent's float wallet via E07 LedgerService (`debit commission_payable:{agentId}` / `credit agent_float:{walletId}`) and transitioning all linked commissions to `paid` and the payout to `completed` on success.
- **FR-E14-011**: The payout approval+execution API call MUST be idempotent per `Idempotency-Key` — replaying the same request MUST return the original result without double-crediting the agent.
- **FR-E14-012**: When the agent is suspended at approval time, the `CommissionPayout` MUST be created with `status = deferred` and execution MUST resume automatically when the agent is reactivated (triggered by the E12 `AgentReactivatedEvent`).
- **FR-E14-013**: When a ledger failure prevents payout execution, the `CommissionPayout` MUST transition to `failed`; commissions MUST remain `pending`; the admin MUST be able to retry by resubmitting the same request with the same `Idempotency-Key`.
- **FR-E14-014**: When a qualifying transaction is reversed, the system MUST post a compensating ledger entry (`debit commission_payable:{agentId}` / `credit commission_expense:{currency}`) and transition the commission to `reversed` — regardless of whether the commission is `pending` or `paid`. For `paid` commissions the system MUST additionally debit the agent's float wallet for the commission amount already received (`debit agent_float:{walletId}` / `credit commission_payable:{agentId}`). The reversal endpoint MUST require an `Idempotency-Key` request header; replaying the same key MUST return the original result without posting duplicate compensating journals or re-debiting the agent's float wallet.
- **FR-E14-015**: Commission payout execution MUST be deferred when the target agent is suspended; the deferred payout MUST resume automatically when the agent is reactivated (triggered by the E12 `AgentReactivatedEvent`).
- **FR-E14-015a**: Authorized admins MUST be able to cancel a `CommissionPayout` in `deferred` status; cancellation MUST transition the payout to `cancelled`, revert all linked commissions from their current status back to `pending`, emit a `CommissionPayoutCancelled` event, and be audit-logged. `completed` and `failed` payouts MUST NOT be cancellable.
- **FR-E14-016**: Agents and authorized admins MUST be able to retrieve a paginated, date-filtered, status-filtered, and type-filtered commission report with totals by status and transaction type. CSV/PDF export is out of scope for this epic (deferred to E24).
- **FR-E14-017**: All commission-related integration events MUST be published to the outbox after durable state changes: `CommissionCalculated`, `CommissionPaid`, `CommissionPayoutApproved`, `CommissionPayoutDeferred`, `CommissionPayoutFailed`, `CommissionPayoutCancelled`, `CommissionReversed`.
- **FR-E14-018**: Commission rules MUST be exclusively admin-managed; agents have no endpoint to create, modify, or deactivate rules.

### Key Entities

- **CommissionRule**: Defines the commission structure for a transaction type. Key attributes: rule ID, rule type (`flat` / `percentage`), rate (minor units for flat; basis points for percentage), applicable transaction types (`cash_in`, `cash_out`, `both`), `agent_tier_id` (nullable FK → E12 agent tier; null = global rule applicable to all agents), status (`active`, `superseded`, `expired`), `valid_from`, `valid_to` (nullable), created-by, created-at, updated-at.
- **Commission**: Records a single commission earned from a qualifying transaction. Key attributes: commission ID, agent ID, transaction ID (from E13), transaction type, amount (minor units), currency code, rule ID, status (`pending`, `approved`, `paid`, `reversed`), payout ID (nullable until paid), journal ID (E07 ledger reference), idempotency key, created-at, updated-at.
- **CommissionPayout**: Records an admin-approved batch payout. Key attributes: payout ID, agent ID, total amount (minor units), currency code, status (`approved`, `completed`, `deferred`, `failed`, `cancelled`), approved-by, approved-at, executed-at (nullable), cancelled-by (nullable), cancelled-at (nullable), journal ID (payout ledger reference, nullable until executed), idempotency key, created-at, updated-at. State machine: `approved → completed` (success) | `approved → deferred` (agent suspended) | `approved → failed` (ledger error) | `deferred → completed` (auto on agent reactivation) | `deferred → cancelled` (admin action) | `failed → completed` (retry succeeds).

## Success Criteria

### Measurable Outcomes

- **SC-E14-001**: Commission calculation completes within one event-processing cycle (target: under 5 seconds) after the qualifying transaction event is emitted, with no measurable impact on transaction latency.
- **SC-E14-002**: Zero duplicate commission records exist after concurrent replay of any qualifying transaction event — idempotency enforced with 100% reliability.
- **SC-E14-003**: Payout execution (approval through wallet credit confirmation) completes end-to-end in under 10 seconds under normal load.
- **SC-E14-004**: Commission report for an agent with up to 10,000 records returns within 500ms for paginated queries, enforced by a composite index on `(agent_id, created_at DESC)`.
- **SC-E14-005**: All commission records are fully traceable — each record has a non-null `transaction_id`, `rule_id`, and `journal_id`; zero orphaned commission records exist.
- **SC-E14-006**: Commission ledger entries balance 100% — total `commission_expense` debits equal total `commission_payable` credits across all agents at any point in time.

## Assumptions

- Commission applies to cash-in and cash-out operations facilitated through the agent float module (E13); commissions for future transaction types (service payments, QR payments, transfers) are out of scope for this epic.
- Commission payouts credit the agent's existing float wallet (from E13); no separate commission wallet is introduced.
- Commission rules are exclusively admin-managed; the platform's default commission rates are a TBD-E14-002 operational decision and must not be hard-coded.
- The platform-side commission expense ledger account reference (debited when commission is calculated) is a TBD-E14-001 operational configuration read from the Platform Configuration service; the system throws `COMMISSION_EXPENSE_ACCOUNT_NOT_CONFIGURED` if absent.
- Commissions are calculated asynchronously when `FloatCashIn` / `FloatCashOut` integration events are received from the E13 outbox; commission calculation does not block or slow the originating float transaction.
- Monthly volume for tier evaluation uses calendar-month UTC boundaries; volume resets to zero on the first day of each UTC month.
- A single commission rule applies per transaction; tie-breaking: tier-scoped rules (non-null `agent_tier_id`) take precedence over global rules (null `agent_tier_id`); among same-specificity rules, the most recently created active rule applies.
- Payout execution is triggered automatically and synchronously as part of the single approval API call; there is no separate execute endpoint. The `approved` status is a transient internal state used for rollback/retry tracing; from the caller's perspective, a successful approval returns `completed`.
- Commission reversal is triggered by an explicit admin API endpoint (`POST /v1/commissions/{id}/reverse`), not by consuming transaction reversal events from E09/E11. An admin must manually initiate reversal for both `pending` and `paid` commissions; event-driven reversal from E09/E11 is a future extension point. Partial transaction reversals are out of scope.
- Initial deployment supports BWP only; multi-currency commission is out of scope for this epic.
- Commission report provides paginated API access only; downloadable export (CSV, PDF) is deferred to E24 Reporting & Analytics.
- Dependencies: E07 LedgerService (double-entry posting), E12 Agent Management (agent status validation), E13 Agent Float (qualifying transaction events and payout target wallet).
