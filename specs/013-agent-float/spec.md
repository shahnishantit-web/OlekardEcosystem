# Feature Specification: E13 — Agent Float Management

**Feature Branch**: `013-agent-float`

**Created**: 2026-09-21

**Status**: Draft

## Overview

Agents in the OleKard wallet ecosystem act as human ATMs: they hold pre-funded digital liquidity ("float") to facilitate cash-in and cash-out transactions for customers. When a customer deposits cash with an agent, the agent's float decreases and the customer's wallet is credited. When a customer withdraws cash from an agent, the agent's float increases and the customer's wallet is debited.

This feature introduces full float lifecycle management: provisioning a float wallet, funding it, enforcing balance and limit controls, and providing visibility into float positions and transaction history.

## Clarifications

### Session 2026-09-21

- Q: Does the agent float wallet support a hold mechanism that reserves amounts for in-flight transactions, creating a gap between settled balance and available balance? → A: No holds — float balance is always fully settled; available balance equals balance; no hold entity is needed.
- Q: When an agent is suspended via E12, should the float wallet be suspended automatically or require a separate admin action? → A: Automatic mirror — suspending an agent via E12 automatically suspends the float wallet; no separate float-wallet suspend endpoint is needed.
- Q: Should admin float funding require step-up MFA when the amount exceeds the platform's configured step-up threshold? → A: No — step-up MFA applies to customer-facing financial operations only; admin float funding is a privileged back-office operation not subject to the customer step-up threshold.
- Q: Can an agent self-configure their own low-float alert threshold, or is this threshold always set and controlled exclusively by administrators? → A: Admin-only — only administrators can set or change an agent's low-float threshold; no agent-facing threshold configuration endpoint is in scope for this epic.

## Actors

- **Agent** — Registered, approved agent (via E12) who holds and operates float
- **Operations Admin** — Authorized admin user who funds agent float wallets and configures limits
- **Platform (System)** — Enforces float balance validation and overdraft prevention automatically

## User Scenarios & Testing

### User Story 1 — Agent Float Wallet Provisioning (Priority: P0)

An operations admin creates a dedicated float wallet for an approved agent. Without a float wallet, an agent cannot perform float-based cash-in/cash-out transactions. The wallet tracks the agent's digital liquidity position and is distinct from any personal customer wallet the agent may hold.

**Why this priority**: Blocking prerequisite for all other float operations; no float transactions are possible without a float wallet.

**Independent Test**: Admin creates a float wallet for an approved agent; the wallet appears in the agent's profile with zero balance and status `active`.

**Acceptance Scenarios**:

1. **Given** an approved agent exists with no float wallet, **When** an admin POSTs to create a float wallet for that agent, **Then** the wallet is created with zero balance, status `active`, and a unique wallet reference is returned.
2. **Given** an agent already has a float wallet, **When** an admin attempts to create another, **Then** the request is rejected with `FLOAT_WALLET_ALREADY_EXISTS`.
3. **Given** an unapproved or suspended agent, **When** an admin attempts to create a float wallet, **Then** the request is rejected with `AGENT_NOT_ELIGIBLE`.
4. **Given** a duplicate `Idempotency-Key`, **When** the same create-wallet request is replayed, **Then** the original wallet is returned (idempotent response).

---

### User Story 2 — Float Wallet Funding (Priority: P0)

An operations admin credits an agent's float wallet with a specified amount, increasing the agent's available liquidity. This models the real-world process where an agent buys float (either in cash or via bank transfer) and the platform records the credit to their digital float position.

**Why this priority**: Agents cannot perform any cash transactions without funded float; this is the primary mechanism to provision liquidity.

**Independent Test**: Admin funds an agent's float wallet by BWP 5,000; the float wallet balance increases by exactly BWP 5,000 and a ledger entry is created.

**Acceptance Scenarios**:

1. **Given** an active agent float wallet, **When** an admin POSTs a fund request for BWP 5,000, **Then** the float balance increases by 5,000, a double-entry ledger journal is posted, and a `FloatFunded` event is emitted.
2. **Given** a fund request exceeding the configured per-agent maximum float limit, **When** the admin submits the request, **Then** the request is rejected with `FLOAT_LIMIT_EXCEEDED`.
3. **Given** a zero-amount or negative fund request, **When** submitted, **Then** the request is rejected with `INVALID_AMOUNT`.
4. **Given** a duplicate `Idempotency-Key` funding request, **When** replayed, **Then** the original funding result is returned without double-crediting the wallet.
5. **Given** concurrent funding requests with the same `Idempotency-Key`, **When** both arrive simultaneously, **Then** only one succeeds; the other receives the cached response.

---

### User Story 3 — Float Balance and Status View (Priority: P0)

An agent or admin can view the current float balance, available liquidity, and low-float status for an agent's float wallet. This gives agents real-time visibility into how much float they have left to serve customers.

**Why this priority**: Agents must know their float position at all times to manage operations; this is a zero-friction read operation with no financial side effects.

**Independent Test**: After funding a float wallet with BWP 5,000, a GET request returns `balance=5000`, `status=active`, `low_float_alert=false`.

**Acceptance Scenarios**:

1. **Given** a funded float wallet, **When** an agent or admin GETs the float balance, **Then** the response includes current balance, currency, and low-float status. Float wallets have no hold mechanism; the reported balance is always the fully settled balance.
2. **Given** a float wallet below the configured low-float threshold, **When** balance is viewed, **Then** `low_float_alert=true` is returned.
3. **Given** an agent who is not the owner of the float wallet, **When** they attempt to view the balance, **Then** the request is rejected with `UNAUTHORIZED`.

---

### User Story 4 — Cash-In Using Agent Float (Priority: P0)

An agent facilitates a cash-in transaction: a customer deposits physical cash with the agent. The agent's float wallet is debited (the agent "uses" float to back the customer's deposit) and the customer's wallet is credited with the equivalent amount. The float decrease represents the agent's obligation to return digital funds to the platform when they deposit collected cash.

**Why this priority**: Core float operation — cash-in is the primary use of float and the main service agents provide to customers.

**Independent Test**: Agent with BWP 1,000 float performs a BWP 200 cash-in for a linked customer; agent float balance drops to BWP 800 and customer wallet balance increases by BWP 200.

**Acceptance Scenarios**:

1. **Given** an agent with BWP 1,000 float and a linked customer with an active wallet, **When** the agent POSTs a BWP 200 cash-in request, **Then** the agent's float decreases by 200, the customer's wallet increases by 200, a balanced double-entry journal is posted, and `FloatCashIn` event is emitted.
2. **Given** an agent with insufficient float (BWP 100) attempting a BWP 200 cash-in, **When** the request is submitted, **Then** the request is rejected with `INSUFFICIENT_FLOAT`.
3. **Given** a suspended agent, **When** they attempt a cash-in, **Then** the request is rejected with `AGENT_SUSPENDED`.
4. **Given** a customer whose wallet is frozen, **When** a cash-in is attempted, **Then** the request is rejected with `CUSTOMER_WALLET_FROZEN`.
5. **Given** a duplicate `Idempotency-Key`, **When** the cash-in request is replayed, **Then** the original result is returned without double-crediting the customer.
6. **Given** two concurrent cash-in requests from the same agent that would together exhaust float, **When** both arrive simultaneously, **Then** only one succeeds; the other receives `INSUFFICIENT_FLOAT`.

---

### User Story 5 — Cash-Out Using Agent Float (Priority: P0)

An agent facilitates a cash-out transaction: a customer withdraws physical cash from the agent. The customer's wallet is debited and the agent's float wallet is credited (the agent receives digital funds in exchange for the physical cash they are handing to the customer). This replenishes the agent's float.

**Why this priority**: Core float operation — cash-out is the reverse of cash-in and the second primary service agents provide.

**Independent Test**: Agent with BWP 500 float performs a BWP 100 cash-out for a linked customer with BWP 300 wallet balance; agent float rises to BWP 600 and customer wallet drops to BWP 200.

**Acceptance Scenarios**:

1. **Given** an agent with an active float wallet and a linked customer with BWP 300 wallet balance, **When** the agent POSTs a BWP 100 cash-out request, **Then** the customer's wallet decreases by 100, the agent's float increases by 100, a balanced double-entry journal is posted, and `FloatCashOut` event is emitted.
2. **Given** a customer with insufficient wallet balance, **When** a cash-out is attempted for more than the customer has, **Then** the request is rejected with `INSUFFICIENT_CUSTOMER_BALANCE`.
3. **Given** a suspended agent, **When** they attempt a cash-out, **Then** the request is rejected with `AGENT_SUSPENDED`.
4. **Given** a duplicate `Idempotency-Key`, **When** the cash-out request is replayed, **Then** the original result is returned without double-debiting the customer.
5. **Given** two concurrent cash-out requests against the same customer wallet that together exceed the customer's balance, **When** both arrive simultaneously, **Then** only one succeeds; the other receives `INSUFFICIENT_CUSTOMER_BALANCE`.

---

### User Story 6 — Float Overdraft Prevention (Priority: P0)

The platform enforces a hard stop preventing agent float from going below zero. Any operation that would result in a negative float balance is rejected atomically, regardless of concurrent requests. This ensures the system maintains financial integrity.

**Why this priority**: Financial integrity requirement; without overdraft prevention the platform's double-entry books cannot balance.

**Independent Test**: Send concurrent cash-in requests totalling more than the agent's float; all but the last should succeed; the final one returns `INSUFFICIENT_FLOAT`.

**Acceptance Scenarios**:

1. **Given** an agent with exactly BWP 200 float, **When** a BWP 200 cash-in is submitted, **Then** it succeeds and float goes to exactly zero (not negative).
2. **Given** an agent with exactly BWP 200 float, **When** a BWP 201 cash-in is submitted, **Then** it is rejected with `INSUFFICIENT_FLOAT`.
3. **Given** two concurrent BWP 150 cash-in requests against an agent with BWP 200 float, **When** both arrive simultaneously, **Then** exactly one succeeds and one fails with `INSUFFICIENT_FLOAT`; the final float balance is exactly BWP 50.

---

### User Story 7 — Float Limit Configuration (Priority: P1)

An operations admin configures float limits for an agent: a maximum float ceiling (how much an agent may hold at once) and optionally per-transaction and daily limits. Limits prevent over-exposure and enforce risk controls appropriate to each agent's tier.

**Why this priority**: Risk control; without limits an agent could hold an unbounded amount of float, creating concentration risk for the platform.

**Independent Test**: Admin sets a BWP 50,000 maximum float for an agent; a subsequent fund request for BWP 60,000 is rejected with `FLOAT_LIMIT_EXCEEDED`.

**Acceptance Scenarios**:

1. **Given** no existing limit for an agent, **When** an admin PATCHes the float limits for that agent, **Then** the limits are saved, an audit event is emitted, and subsequent operations enforce the new limits.
2. **Given** a configured maximum float of BWP 50,000, **When** a fund request would push the balance above this limit, **Then** the request is rejected with `FLOAT_LIMIT_EXCEEDED`.
3. **Given** a configured per-transaction limit, **When** a single cash-in exceeds this limit, **Then** the request is rejected with `TRANSACTION_LIMIT_EXCEEDED`.
4. **Given** a configured daily limit, **When** the agent's total float transactions for the day exceed this threshold, **Then** further transactions are rejected with `DAILY_LIMIT_EXCEEDED`.
5. **Given** an unauthorized user (not admin), **When** they attempt to modify float limits, **Then** the request is rejected with `UNAUTHORIZED`.

---

### User Story 8 — Low-Float Monitoring and Alerts (Priority: P1)

The platform monitors agent float balances and automatically emits an alert event when a float wallet falls below a configured minimum threshold. Agents and admins can subscribe to these notifications to ensure float is replenished before the agent runs out of liquidity and cannot serve customers.

**Why this priority**: Operational continuity; without alerts agents may run out of float mid-day, disrupting customer service.

**Independent Test**: Agent float falls below configured threshold of BWP 500; system emits a `FloatLowAlert` event within one processing cycle; no manual trigger required.

**Acceptance Scenarios**:

1. **Given** a configured low-float threshold of BWP 500, **When** a cash-in transaction reduces the float balance to BWP 499, **Then** a `FloatLowAlert` event is emitted after the transaction completes.
2. **Given** a float wallet that has already triggered a low-float alert, **When** float rises above the threshold (e.g., due to a cash-out or refunding), **Then** no duplicate alert is emitted.
3. **Given** a float balance of BWP 0 after overdraft prevention, **When** queried, **Then** the balance view returns `balance=0, status=active`; subsequent cash-in attempts are rejected with `INSUFFICIENT_FLOAT`.
4. **Given** an admin-configured low-float threshold of zero (monitoring disabled), **When** float drops low, **Then** no alert event is emitted.

---

### User Story 9 — Agent Float Transaction Report (Priority: P1)

An agent or admin can retrieve a paginated, date-filtered history of all float transactions (funding, cash-in, cash-out) for a specific agent's float wallet. This provides full traceability of float movements for audit, reconciliation, and agent self-service purposes.

**Why this priority**: Operational transparency; agents and operations teams need to reconcile float movements for accounting and dispute resolution.

**Independent Test**: After 3 float transactions (1 funding, 1 cash-in, 1 cash-out), a GET request to the float transactions endpoint returns all 3 records with correct amounts, directions, and timestamps.

**Acceptance Scenarios**:

1. **Given** an agent with multiple float transactions, **When** an agent or admin GETs the float transaction history, **Then** a paginated list of transactions is returned with `id`, `type`, `amount`, `direction`, `reference`, `status`, and `timestamp` for each entry.
2. **Given** date range filter parameters, **When** applied to the transaction history query, **Then** only transactions within the specified range are returned.
3. **Given** an unauthorized user (not the owning agent or an admin), **When** they request float transaction history, **Then** the request is rejected with `UNAUTHORIZED`.
4. **Given** a large number of float transactions, **When** the report is queried without date filters, **Then** pagination is applied and the response includes page metadata.

---

### Edge Cases

- What happens when the platform liquidity account has insufficient funds to cover an admin-initiated float funding? (TBD — float funding ledger debit account is a TBD-E13-001 platform design decision; spec defers to configurable ledger account)
- What happens when an agent is suspended mid-cash-in (between validation and posting)? Transactional atomicity must be maintained.
- What happens if a float wallet's currency differs from the customer wallet's currency? Cross-currency float operations are out of scope for this epic.
- Float wallet lifecycle mirrors agent lifecycle: an agent suspension (E12) automatically suspends the float wallet; permanent agent account closure sets the float wallet to `closed`. There is no scenario where a float wallet is closed but the agent is active.
- What happens during a system restart/crash between float debit and customer credit in a cash-in? Idempotency keys and outbox events ensure at-least-once delivery and deduplication.

## Requirements

### Functional Requirements

- **FR-E13-001**: The system MUST allow authorized admins to create exactly one float wallet per approved agent.
- **FR-E13-002**: The system MUST prevent creation of a float wallet for unapproved or suspended agents.
- **FR-E13-003**: Float wallet creation MUST be idempotent per `Idempotency-Key`.
- **FR-E13-004**: The system MUST allow authorized admins to credit (fund) an agent's float wallet.
- **FR-E13-005**: Float funding MUST be posted as a balanced double-entry ledger journal.
- **FR-E13-006**: Float funding MUST be idempotent per `Idempotency-Key`.
- **FR-E13-007**: Float funding MUST be rejected when the resulting balance would exceed the agent's configured maximum float limit.
- **FR-E13-008**: Agents and authorized admins MUST be able to view the current float balance (fully settled, no holds mechanism) and low-float status.
- **FR-E13-009**: The system MUST support agent-initiated cash-in operations that debit the agent float and credit the customer wallet atomically.
- **FR-E13-010**: Cash-in operations MUST be idempotent per `Idempotency-Key`.
- **FR-E13-011**: Cash-in operations MUST be rejected when the agent has insufficient float.
- **FR-E13-012**: The system MUST support agent-initiated cash-out operations that credit the agent float and debit the customer wallet atomically.
- **FR-E13-013**: Cash-out operations MUST be idempotent per `Idempotency-Key`.
- **FR-E13-014**: Cash-out operations MUST be rejected when the customer has insufficient wallet balance.
- **FR-E13-015**: The system MUST prevent float from going below zero under any concurrency conditions (overdraft prevention with row-level locking).
- **FR-E13-016**: All float balance debits and credits MUST be posted via the existing double-entry ledger (E07 LedgerService).
- **FR-E13-017**: Authorized admins MUST be able to configure per-agent float limits: maximum holding limit, per-transaction limit, daily volume limit, and low-float alert threshold. These controls are exclusively admin-managed; agents have no endpoint to modify their own limits or threshold.
- **FR-E13-018**: Float limit configuration changes MUST be audit-logged.
- **FR-E13-019**: The system MUST emit a `FloatLowAlert` event when a float balance falls below a configurable threshold.
- **FR-E13-020**: The `FloatLowAlert` event MUST NOT be emitted repeatedly for the same low-float condition without a recovery in between.
- **FR-E13-021**: Float operations (cash-in, cash-out, funding) MUST be rejected when the agent is suspended or their float wallet status is not `active`. Suspending an agent via E12 MUST automatically set the float wallet status to `suspended`; reactivating the agent MUST restore the float wallet to `active`.
- **FR-E13-022**: Agents and authorized admins MUST be able to retrieve a paginated, date-filtered float transaction history.
- **FR-E13-023**: Each float transaction in the history MUST include: transaction ID, type, amount, currency, direction, reference, timestamp, and related party (customer ID or admin ID).
- **FR-E13-024**: Cash-in and cash-out operations MUST be available only to the owning agent for their own float wallet and customers.
- **FR-E13-025**: All financial mutations (fund, cash-in, cash-out) MUST emit integration events to the outbox.
- **FR-E13-026**: Admin float funding does NOT require step-up MFA authentication. The platform step-up threshold (GAP-027) applies to customer-facing financial operations only; admin funding is a privileged back-office action covered by standard admin authentication.

### Key Entities

- **AgentFloatWallet**: Represents the agent's float liquidity account. Key attributes: wallet ID, agent ID, currency, balance (fully settled; no hold mechanism), status (active/suspended/closed — mirrors agent status; suspended when agent is suspended, closed when agent is permanently closed; no independent lifecycle endpoint), created-at, updated-at.
- **FloatTransaction**: Records each float movement. Key attributes: transaction ID, wallet ID, type (funding/cash_in/cash_out), amount, currency, direction (debit/credit), related party (customer ID or funding admin ID), reference, idempotency key, status, created-at.
- **AgentFloatLimit**: Per-agent configurable limits, exclusively managed by authorized admins. Key attributes: limit ID, agent ID, maximum_balance, per_transaction_limit, daily_volume_limit, low_float_threshold (admin-set; no agent self-service), effective-from, created-by, updated-at.

## Success Criteria

### Measurable Outcomes

- **SC-E13-001**: Cash-in and cash-out operations complete end-to-end in under 3 seconds under normal load.
- **SC-E13-002**: Zero float overdraft events occur under concurrent load — the system enforces the constraint with 100% reliability in concurrency tests.
- **SC-E13-003**: Duplicate `Idempotency-Key` replays return the original result in under 500ms without side effects.
- **SC-E13-004**: Float transaction history returns up to 100 records in under 200ms for agents with up to 10,000 float transactions, enforced by the composite index on `(agent_id, created_at DESC)`.
- **SC-E13-005**: Low-float alerts are emitted within one event-processing cycle (target: under 5 seconds) after the triggering transaction completes.
- **SC-E13-006**: All float transactions are fully traceable from initiation through ledger posting to outbox event, with no orphaned records.

## Assumptions

- An agent must be in `approved` status (from E12) before a float wallet can be created.
- The platform uses a single float currency per agent (BWP for initial deployment); cross-currency float is out of scope.
- Float wallets have no hold mechanism; the reported balance is always the fully settled (real-time) balance. Concurrency control is enforced via row-level locking at the point of debit, not via pre-reservation.
- Float wallets use the existing `wallet.wallets` table with a `wallet_type = 'agent_float'` discriminator; no new wallet storage table is needed.
- Float operations (cash-in/cash-out) use the existing LedgerService (E07) for double-entry posting; the float wallet uses the existing ledger account model.
- The platform-side liquidity account (debited when float is funded) is a TBD-E13-001 operational configuration; the spec does not prescribe the counterparty account but mandates that a balanced journal entry is posted.
- Default float limits (maximum balance, per-transaction, daily volume, low-float threshold) are centrally configurable and not hard-coded; initial defaults are a TBD-E13-002 operational decision.
- Cash-in/cash-out in this epic refers to float-mediated transactions only; bank-connected funding (E08) is a separate flow.
- Agents view their own float; admins can view and manage any agent's float within their authorization scope.
- Float wallet lifecycle is entirely derived from agent lifecycle (E12): agent suspension → float wallet suspended; agent reactivation → float wallet active; agent permanent closure → float wallet closed. No separate float-wallet suspend/reactivate endpoints are required.
- Admin float funding is not subject to the platform's step-up MFA threshold (GAP-027). Step-up MFA applies to customer self-service financial operations; admin operations are covered by standard privileged-session authentication.
- The `FloatLowAlert` event triggers a notification (E17 Notifications will subscribe and deliver the alert to the agent); this spec only mandates the event emission.
- Float limits and the low-float alert threshold are exclusively admin-managed. Agents have no self-service endpoint to adjust their own limits or threshold. Agent self-service threshold configuration is explicitly out of scope and may be added in a future epic.
