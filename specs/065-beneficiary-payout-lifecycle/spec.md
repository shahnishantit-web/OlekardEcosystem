# Feature Specification: P18 — Beneficiary Verification and Payout Lifecycle

**Feature Branch**: `065-beneficiary-payout-lifecycle`

**Created**: 2026-10-25

**Status**: Draft — PARTIALLY BLOCKED

**Input**: Execute P18 — Beneficiary verification and payout lifecycle. Mode: Implement.

**Predecessors (all COMPLETE/RELEASE READY)**:
- P03 — Tenant Isolation and Payment Permissions
- P08 — Ledger Acknowledgement and Fulfilment Handoff
- P15 — Shared Refund Engine
- P16 — Reversals Disputes and Chargebacks
- P17 — Reconciliation Clearing and Provider Settlement

**Source references**:
- FD10 — Payout financial design (`OleKard_Shared_Payment_Architecture.docx v1.1` — NOT in repository)
- LC14 — Disbursement ledger contract (referenced by architecture; definition AWAITING confirmation)
- AD-10 — Custody account type (ADR-E48-003: AWAITING_DOCUMENT — blocks ledger posting)

---

## Blocking Decisions (Must Resolve Before Full Implementation)

### BLOCKER-1: AD-14 — Payout Contract Ownership (ADR-E48-003)

**Status**: UNRESOLVED (Decision owner: Architect)

**Impact**: US3 — Payout Execution via Adapter cannot be implemented until the architect chooses one of:
- Option A: Add `initiatePayout()` to the existing `PaymentAdapterInterface`
- Option B: Create a separate `PayoutAdapterInterface` within the Payment module
- Option C: Create a new `App\Modules\Payout` module with its own contract

US1, US2 (beneficiary verification and reservation) are NOT blocked and can be implemented independently.

### BLOCKER-2: AD-10 — Custody Account Type (ADR-E48-003)

**Status**: AWAITING_DOCUMENT (Decision owner: Finance Lead + Legal)

**Impact**: US4 — Ledger Disbursement and Settlement Reconciliation cannot post a complete double-entry journal without knowing which ledger account type holds collected funds between collection and disbursement. The debit side of the disbursement entry is the custody account; without knowing this account type, the journal is incomplete.

**Assumption**: The custody account is created and managed by the Wallet module; its ledger account reference is accessed via `WalletModuleContract`. This assumption is recorded as PENDING until AD-10 is resolved.

### BLOCKER-3: LC14 — Disbursement Ledger Contract

**Status**: AWAITING confirmation

**Impact**: The ledger contract for payout disbursements is referenced as LC14 in the source document. Until this is defined, the credit side of the disbursement entry (beneficiary or organisation account) cannot be confirmed. US4 records this as an open decision in its implementation plan.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Beneficiary Verification and Payable Balance (Priority: P1)

A finance admin or automated system trigger initiates a payout for a fund that has completed its business cycle (e.g., a funeral fund has closed, a raffle has been drawn). Before any disbursement can proceed, the system verifies the intended destination beneficiary account is active and verified, confirms the requesting domain has granted the entitlement to disburse, and computes the cleared payable balance: the net amount available after deducting confirmed refunds, open dispute holds, and any amounts from previously executed or pending payouts against the same fund.

**Why this priority**: Foundation for all downstream steps. Without a verified destination and an authoritative payable balance, any reservation or execution attempt is unsafe. This story is unblocked and can be delivered first.

**Independent Test**: Can be fully tested by requesting a payout balance enquiry for a fund with known settled amounts, known refunds, and known disputes — and verifying the returned payable balance matches the computed net figure.

**Acceptance Scenarios**:

1. **Given** a fund with 10,000 settled, 500 refunded, 200 under dispute hold, and 1,000 already paid out, **When** a payout balance enquiry is requested, **Then** the cleared payable balance returned is 8,300 (10,000 − 500 − 200 − 1,000).

2. **Given** a beneficiary account that is in an unverified or suspended state, **When** a payout initiation is requested for that beneficiary, **Then** the system rejects the request with a clear error indicating the destination is not verified.

3. **Given** a domain module that has not confirmed the business cycle is complete (entitlement not granted), **When** a payout initiation is requested, **Then** the system rejects the request with a domain entitlement error.

4. **Given** a request where the caller-supplied amount exceeds the cleared payable balance, **When** payout initiation is attempted, **Then** the system rejects the request; the server-derived balance is authoritative and caller amounts are not accepted.

5. **Given** concurrent payout balance enquiries for the same fund, **When** enquiries are made simultaneously, **Then** each receives a consistent, point-in-time snapshot; no enquiry sees uncommitted in-flight state.

---

### User Story 2 — Funds Reservation with Maker/Checker Separation (Priority: P1)

Once the payable balance is confirmed, the finance admin (maker) creates a reservation that locks the requested payout amount against the fund's custody balance. A separate admin (approver/checker) must independently approve the reservation before execution can proceed. If the maker modifies the amount or destination after the approver has approved, the existing approval is automatically invalidated and a fresh approval cycle is required. The reservation remains in place until the payout either succeeds or is explicitly released.

**Why this priority**: Prevents concurrent payouts from overspending (the reservation uses pessimistic locking) and provides financial control separation to mitigate fraud and error risk. This story is unblocked.

**Independent Test**: Can be fully tested by creating a reservation as maker, confirming a different admin approves, attempting self-approval (must fail), changing the amount after approval (must invalidate approval), and verifying that two simultaneous reservations for the same fund cannot exceed the available balance.

**Acceptance Scenarios**:

1. **Given** a fund with cleared payable balance of 8,300, **When** a maker creates a reservation for 8,300, **Then** the reservation is created and the remaining available balance for new reservations is 0.

2. **Given** a reservation awaiting approval, **When** the maker attempts to self-approve, **Then** the system rejects with a self-approval error (HTTP 403).

3. **Given** an approved reservation, **When** the maker changes the payout amount or destination, **Then** the existing approval is automatically invalidated, the reservation status reverts to awaiting_approval, and an audit record is written documenting the change.

4. **Given** two concurrent requests to reserve the same fund's available balance in full, **When** both requests are submitted simultaneously, **Then** exactly one succeeds and the other is rejected with an insufficient-balance error (no double-reservation).

5. **Given** a reservation that has been rejected by the approver, **When** the maker views the reservation, **Then** the rejection reason is visible and the reservation status is rejected (the amount is released back to the available balance).

6. **Given** a reservation that is approved, **When** no execution action is taken within the configured expiry window, **Then** the reservation expires, the amount is released, and an audit record is created.

---

### User Story 3 — Payout Execution via Adapter with Stable Operation IDs (Priority: P2)

**BLOCKED**: Depends on resolution of BLOCKER-1 (AD-14 — Payout Contract Ownership).

After a reservation is approved, the system submits the payout to the payout adapter using a stable operation ID derived from the reservation record. The operation ID is deterministic and persists across retries so that the provider can deduplicate duplicate submissions. If the adapter returns a pending or unknown status, the reservation is NOT released — it remains locked until a terminal status (succeeded or failed) is confirmed. If the adapter returns a definitive failure, the reservation enters a manual review state awaiting operator intervention.

**Why this priority**: Execution is the core financial action but depends on adapter design (AD-14). Delivering US1+US2 independently provides immediate business value (reservation control) while adapter design is finalised.

**Independent Test**: Can be fully tested against a development mock payout adapter (explicitly labelled as mock) once AD-14 is resolved. Tests must verify: stable operation ID computation, no duplicate submission on retry, reservation retention on pending result, and reservation release only on confirmed terminal success.

**Acceptance Scenarios**:

1. **Given** an approved reservation, **When** the system submits the payout to the adapter, **Then** the submission uses a stable operation ID that does not change across retries.

2. **Given** the adapter returns a `pending` or `unknown` status, **When** the system processes the response, **Then** the reservation remains active and locked; the payout status is `pending_settlement`; a retry is scheduled.

3. **Given** the adapter returns `succeeded` on a retry after an earlier `pending` response, **Then** the reservation is released, the payout status advances to `awaiting_ledger_confirmation`, and a settlement confirmation event is dispatched.

4. **Given** a duplicate submission with the same operation ID (e.g., due to a network retry), **When** the adapter receives the duplicate, **Then** the adapter deduplicates it and returns the same result as the original; no second disbursement occurs.

5. **Given** the adapter returns a definitive `failed` terminal status, **When** the system processes the response, **Then** the reservation enters `manual_review`; the amount is NOT released automatically; an alert is raised for operator intervention.

---

### User Story 4 — Settlement Confirmation and Ledger Disbursement (Priority: P2)

**BLOCKED**: Depends on resolution of BLOCKER-2 (AD-10) and BLOCKER-3 (LC14) for the full double-entry journal.

After the adapter confirms a successful payout, the system must reconcile the confirmation against provider evidence before posting the disbursement journal entry. The ledger entry debits the custody account and credits the beneficiary/organisation account using the payout's operation ID as the idempotency key. Only after the ledger entry is durably posted is the payout marked as `settled`. The system also returns the current aggregate payout and domain statuses.

**Why this priority**: The ledger posting requires resolved accounting decisions (AD-10, LC14) and therefore cannot be finalised until those blockers are cleared.

**Independent Test**: Can be tested end-to-end only after AD-10 and LC14 are resolved and the custody and beneficiary ledger accounts are confirmed. A partial test (verifying payout is not marked settled until ledger entry is confirmed) can be written against a stub ledger.

**Acceptance Scenarios**:

1. **Given** an adapter confirmation with provider evidence, **When** the evidence is reconciled and the ledger entry is posted, **Then** the payout status advances to `settled` and the aggregate fund status reflects the disbursement.

2. **Given** a `LedgerModuleContract::postEntry()` failure, **When** the system attempts to post the disbursement journal, **Then** the payout remains in `awaiting_ledger_confirmation` and the operation is retried; the payout is never marked settled without a durable ledger entry.

3. **Given** a duplicate confirmation delivery (provider sends evidence twice), **When** the system processes the second delivery, **Then** `LedgerModuleContract::entryExists()` returns true; no second journal entry is posted; the response is idempotent.

4. **Given** a settled payout, **When** the domain status query is made, **Then** the system returns: payout status (settled), ledger confirmation status (confirmed), provider settlement evidence reference, and aggregate fund disbursement summary.

5. **Given** provider evidence that does not match the expected payout amount, **When** reconciliation runs, **Then** a reconciliation discrepancy exception is raised and the payout is NOT marked settled; the discrepancy requires operator review.

---

### Edge Cases

- What happens when the same payout operation ID is submitted to the adapter more than once (network retry)?
  → The adapter must receive the same stable operation ID and deduplicate. The system does not issue a new operation ID on retry.

- What happens when the fund is closed or cancelled after a reservation is created but before approval?
  → The reservation must be released and the payout request rejected with a domain-entitlement withdrawal error.

- What happens when a beneficiary account becomes unverified between reservation creation and execution?
  → The system must re-verify the beneficiary at execution time; if no longer verified, execution is blocked and the reservation enters manual review.

- What happens when the custody balance changes (new refund arrives) between reservation creation and execution?
  → The reservation holds the amount immutably. The available balance for NEW reservations decreases; the existing approved reservation is unaffected. No retroactive adjustment to an already-approved reservation.

- What happens when the payout adapter is unavailable (timeout / service down)?
  → The submission is retried up to the configured attempt limit with exponential back-off. The reservation is NOT released during retries. If all attempts are exhausted, the payout enters `manual_review`.

- What happens when the ledger service is unavailable at the time of posting?
  → The disbursement entry posting is retried via a durable outbox or queue. The payout status remains `awaiting_ledger_confirmation` until the entry is durably posted. No alternative balance record is created.

---

## Requirements *(mandatory)*

### Functional Requirements

**Beneficiary Verification (US1)**

- **FR-001**: The system MUST verify that the payout destination beneficiary account is in an active, verified state before allowing payout initiation.
- **FR-002**: The system MUST enforce domain entitlement — a payout may only be initiated when the requesting domain module has confirmed the business lifecycle is complete and the fund is authorised for disbursement.
- **FR-003**: The system MUST compute cleared payable balance server-side as: `max(0, settled_credited_amount − total_confirmed_refunds − open_dispute_holds − total_prior_payout_amounts)`. Caller-supplied amounts are not accepted as the balance basis.
- **FR-004**: Cleared payable balance computation MUST exclude amounts held under open, under-review, or awaiting-evidence dispute cases.
- **FR-005**: The system MUST reject any payout request where the requested amount exceeds the server-derived cleared payable balance at the time of reservation.

**Funds Reservation and Maker/Checker (US2)**

- **FR-006**: Reservation creation MUST use pessimistic row-level locking (SELECT FOR UPDATE) against the custody balance record to prevent concurrent overspend.
- **FR-007**: A single reservation MUST NOT exceed the cleared payable balance at the time of reservation.
- **FR-008**: The maker (actor who creates the reservation) and the approver (actor who approves it) MUST be different system actors; self-approval MUST be rejected with an authorisation error.
- **FR-009**: Any modification to the reservation amount or destination after an approval has been granted MUST automatically invalidate the approval and set the reservation to `awaiting_approval` status; an audit record MUST be written.
- **FR-010**: Reservations MUST have a configurable expiry window; an expired reservation MUST be released automatically and an audit record written.
- **FR-011**: All reservation state transitions (created, approved, invalidated, rejected, expired, released) MUST produce a durable audit entry with actor, timestamp, and reason.

**Payout Execution (US3 — BLOCKED pending AD-14)**

- **FR-012**: The payout adapter MUST be invoked through a separately supported interface (contract design pending AD-14 resolution); no inline bank API calls are permitted.
- **FR-013**: Each payout execution MUST use a stable operation ID derived deterministically from the reservation ID; the operation ID MUST NOT change across retries.
- **FR-014**: If the adapter returns a pending or unknown status, the reservation MUST be retained and the payout status set to `pending_settlement`; the system MUST schedule a status query retry.
- **FR-015**: If the adapter returns a definitive terminal failure, the reservation MUST enter `manual_review` state; the amount MUST NOT be released automatically.
- **FR-016**: Duplicate submissions with the same operation ID MUST be idempotent at the adapter boundary; the system MUST NOT attempt a second disbursement for the same operation.

**Settlement Confirmation and Ledger Disbursement (US4 — BLOCKED pending AD-10, LC14)**

- **FR-017**: Payout disbursement MUST be recorded via `LedgerModuleContract::postEntry()` as a balanced double-entry journal; direct mutation of any balance or custody account record is prohibited.
- **FR-018**: The ledger journal entry MUST use the payout operation ID as the idempotency key; `LedgerModuleContract::entryExists()` MUST be checked before posting to ensure idempotency.
- **FR-019**: The payout MUST NOT be marked `settled` until `LedgerModuleContract::entryExists()` confirms the durable journal entry exists.
- **FR-020**: Provider evidence MUST be reconciled against the payout record before advancing status; a provider amount discrepancy MUST raise a reconciliation exception and block settlement.
- **FR-021**: The system MUST return aggregate domain status including: payout status, ledger confirmation status, provider reference, and fund-level disbursement summary.

**Financial Integrity (cross-cutting)**

- **FR-022**: No historical ledger entry may be modified; corrections require a compensating `postEntry()`.
- **FR-023**: All state-changing payout APIs MUST require an `Idempotency-Key` header; duplicate keys MUST return the existing result.
- **FR-024**: Tenant context and money amounts MUST be derived server-side; consumer-supplied values are rejected.
- **FR-025**: Payout execution and ledger posting are BLOCKED until AD-14 and AD-10 are resolved respectively; unblocked scope (FR-001–FR-011) may be implemented immediately.

### Key Entities

- **PayoutRequest**: Lifecycle record for a single disbursement request. Tracks status from initiation through reservation, execution, and settlement. Carries the stable operation ID.
- **PayoutReservation**: The hold placed against the custody balance. Carries amount, destination, maker/approver actors, approval status, and expiry timestamp.
- **PayoutBeneficiary**: The verified destination account. Records verification status, account type, and the last verification timestamp.
- **PayoutAttempt**: A single execution attempt via the payout adapter. Records adapter reference, operation ID, status returned, and evidence reference.
- **PayoutSettlementRecord**: Reconciliation of provider evidence against a PayoutAttempt. Carries expected amount, provider-confirmed amount, reconciliation status, and any discrepancy exception reference.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Concurrent payout reservations for the same fund cannot result in total reserved amounts exceeding the cleared payable balance — verified by a concurrency test that submits simultaneous reservation requests.
- **SC-002**: A payout with an adapter-returned pending or unknown status retains its reservation until a terminal status (succeeded or failed) is confirmed — verified by a test that holds the adapter in pending state and confirms the reservation remains locked.
- **SC-003**: Any change to the reservation amount or destination after approval produces a written audit record within the same database transaction as the invalidation — verified by checking audit table entries after a modification.
- **SC-004**: Duplicate payout submissions with the same operation ID do not result in duplicate disbursements — verified by submitting the same operation ID twice and confirming a single ledger entry exists.
- **SC-005**: All payout state transitions are audited with actor, timestamp, and reason — verified by exercising each state transition and confirming the audit log contains an entry per transition.
- **SC-006**: A payout is never marked settled without a durable ledger entry — verified by simulating a ledger service failure and confirming the payout status remains `awaiting_ledger_confirmation`.

---

## Assumptions

- **AD-14 must be resolved** before the payout adapter interface and execution layer (US3) can be implemented. The spec records all three options (A, B, C) but does not choose; that is the architect's decision.
- **AD-10 must be resolved** before the full double-entry ledger posting (US4) can be implemented. The debit side of the disbursement entry is the custody account; without its type and reference, the journal is incomplete.
- **LC14 (disbursement ledger contract)** is referenced but not confirmed. It is assumed to extend or complement `LedgerModuleContract` for disbursement-specific journal entries. Until confirmed, US4 implementation uses `LedgerModuleContract::postEntry()` directly as the stub.
- The **custody balance** lives in the Wallet module and is accessible via `WalletModuleContract`. Payout amounts are deducted from the wallet custody pocket, not from any payment module balance store.
- **No bank payout APIs are fabricated**. US3 references a "payout adapter" that will be implemented once AD-14 is resolved and real bank payout API contracts are available. Until then, a development mock adapter (explicitly labelled `[DEV MOCK]`) may be used for testing US1–US2.
- **E25 owns common external ingress/connector infrastructure**. P18 does not duplicate this — the payout adapter plugs into the existing adapter infrastructure established by E25.
- **Incoming payment capability does not imply payout support**. A provider that can process incoming payments (P12, P13, P14) may not support outgoing payouts. Payout capability is declared separately via `ProviderCapabilityEnum::Payout`.
- **P18 delivers US1 and US2 as unblocked scope**. US3 and US4 are BLOCKED pending architectural decisions and are implemented as stubs with documented blocking conditions.
- **FD10** (payout financial design) is referenced but the source document `OleKard_Shared_Payment_Architecture.docx v1.1` is not in the repository. Decisions derived from FD10 are recorded in ADR-E48-003 as AWAITING_DOCUMENT and are not assumed or inferred here.
