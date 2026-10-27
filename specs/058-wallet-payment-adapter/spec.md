# Feature Specification: P09 — OleKard Wallet Payment Adapter

**Feature Branch**: `058-wallet-payment-adapter`

**Created**: 2026-10-21

**Status**: Implementation Ready

**Work Package**: P09 (Shared Payment Architecture delivery register)

**Authority**: Constitution Principles 1–15; ADR-E48-001; ADR-E48-002; `docs/architecture/payment-contract-register.md`

**Required Predecessors**: P06 (COMPLETE), P07 (COMPLETE), P08 (COMPLETE)

**Source References**: FD05 (functional design — wallet debit flow); LC04 (lifecycle flow — payment attempt to capture); `payment-contract-register.md` section 4; ADR-E48-002.

> **Source document note**: `OleKard_Shared_Payment_Architecture.docx v1.1` is not in the repository (DEC-07). FD05 and LC04 requirement codes are recorded here as AWDOC and derived from the existing `payment-contract-register.md`, ADR-E48-002, completed P01–P08 implementation, and the delivery register gap analysis in `specs/049-payment-delivery-register/spec.md`.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Complete Wallet Validation Gate (Priority: P1)

A customer initiates a wallet payment. Before any funds are reserved, the system validates that the customer's wallet is active, the wallet belongs to the stated payer, the wallet has a pocket for the requested currency, the pocket balance meets or exceeds the payment amount, and the amount does not exceed the configured per-transaction spending limit. If any validation fails, a specific, actionable error is returned to the caller — no hold is placed and no ledger entry is created.

**Why this priority**: Ownership, currency, balance, and limit checks are the core financial safety gate. Without these, incorrect or oversized debits can occur. The pocket-existence check and transaction-limit check are currently absent from the adapter; this story fills those gaps.

**Independent Test**: Independently testable by creating an adapter stub and submitting `initiatePayment` requests with each failing condition (wrong owner, missing pocket, insufficient balance, limit exceeded) and one passing condition — verifying the correct exception type is thrown for each failure and a hold is placed only for the success case.

**Acceptance Scenarios**:

1. **Given** a wallet with an active BWP pocket and sufficient balance, **when** `initiatePayment` is called with the correct owner actor ID and amount below the transaction limit, **then** a hold is placed and the hold ID is returned as the reservation reference.
2. **Given** a wallet owned by actor A, **when** `initiatePayment` is called with actor B as the payer, **then** an ownership error is returned and no hold is placed.
3. **Given** a wallet with no BWP pocket, **when** `initiatePayment` is called with BWP as the currency, **then** a currency/pocket-not-found error is returned and no hold is placed.
4. **Given** a wallet with a balance of 50 BWP, **when** `initiatePayment` is called for 100 BWP, **then** an insufficient-funds error is returned and no hold is placed.
5. **Given** a configured per-transaction limit of 5,000 BWP, **when** `initiatePayment` is called for 6,000 BWP, **then** a transaction-limit-exceeded error is returned and no hold is placed.
6. **Given** an inactive wallet, **when** `initiatePayment` is called, **then** a wallet-not-active error is returned and no hold is placed.

---

### User Story 2 — Debit Idempotency and Concurrent Spend Safety (Priority: P1)

A payment attempt is submitted twice with the same identifier — either because the caller retried after a timeout or because two concurrent requests raced to initiate payment from the same wallet. In both cases, the wallet balance must never go negative, and no more than one hold must be placed per unique payment attempt.

**Why this priority**: Double-debiting a customer's wallet is a critical financial integrity violation. This story ensures that `createHold` idempotency (same `transactionReference` returns the same hold) is exercised and tested under concurrent conditions, not merely asserted architecturally.

**Independent Test**: Independently testable with a unit test for idempotency (call `initiatePayment` twice with the same attempt ID; expect the wallet module's `createHold` to be called once and the second call to return the same hold ID). For concurrency, a feature test with two simultaneous DB-level hold attempts for the same pocket verifies that the available balance never drops below zero.

**Acceptance Scenarios**:

1. **Given** a succeeded payment attempt, **when** the caller retries `initiatePayment` with the same `paymentAttemptId`, **then** the original hold ID is returned and `createHold` is not called a second time on the wallet module.
2. **Given** two simultaneous `initiatePayment` calls for the same wallet pocket with the same attempt ID, **then** exactly one hold is created and the wallet's available balance reflects only one deduction.
3. **Given** two simultaneous `initiatePayment` calls for the same wallet pocket each with a distinct attempt ID but together exceeding the available balance, **then** one succeeds and one fails with insufficient-funds; the wallet balance is not negative after both calls complete.

---

### User Story 3 — Crash-After-Hold Recovery (Priority: P1)

A hold is placed on the wallet but the payment system crashes before it records the `wallet_hold_id` on the payment attempt. On restart, the payment attempt is in `initiated` status with no hold reference. The system must be able to resolve this state — either by finding the hold via the stable operation ID (same `transactionReference`) or by releasing an expired hold and restarting cleanly.

**Why this priority**: This is the hardest crash window for wallet payments: money is reserved but the system has no record of the reservation. Leaving it unresolved creates either a permanent balance lock (hold never released) or a missed revenue risk (payment succeeds without accounting).

**Independent Test**: Independently testable by simulating: place a hold via `createHold`, skip writing `wallet_hold_id` to the attempt, then call `initiatePayment` again with the same `paymentAttemptId` — verify the idempotency of `createHold` returns the same hold ID and no new hold is placed.

**Acceptance Scenarios**:

1. **Given** a payment attempt in `initiated` state with `wallet_hold_id = null` (crash after hold was placed), **when** `initiatePayment` is retried with the same `paymentAttemptId`, **then** `createHold` is called with the same `transactionReference` and the wallet module's idempotency returns the existing hold ID without a second deduction.
2. **Given** a hold that has passed its `expires_at` timestamp, **when** the orchestration layer attempts to capture it (P08 `postLedgerAcknowledgement`), **then** the orchestration layer detects the expiry and transitions the payment attempt to an error state pending a clean retry.
   *(Orchestration/P08 scope — not an adapter responsibility. The adapter's only obligation is that it does not itself place a new hold without a new `initiatePayment` call.)*
3. **Given** the repair job (`RepairPendingAccountingJob`) running against a `pending_ledger` intent, **when** the intent's `wallet_hold_id` is still valid, **then** ledger acknowledgement proceeds using the existing hold — no new hold is placed.
   *(P07 scope — this job is implemented and tested in `specs/056-durable-idempotency-recovery`. P09's contribution is confirming that the adapter's `createHold` idempotency means the job can safely retry `initiatePayment` — tested in T010.)*

---

### User Story 4 — Wallet Refund Contract Completeness (Priority: P2)

An operator approves a refund for a succeeded wallet payment. The hold was already captured at the time the payment completed (P08 `LedgerPosted`), so there is no hold to release. The adapter's `initiateRefund` implementation confirms the refund intent and returns a succeeded status; the orchestration layer separately posts a compensating credit journal entry via `LedgerModuleContract`. No historical journal entries are modified.

**Why this priority**: The refund path for wallet payments needs to be unambiguous: the adapter does NOT call `releaseHold()` for refunds (the hold no longer exists post-capture); the orchestration layer owns the compensating journal posting. The current implementation is incomplete on this boundary.

**Independent Test**: Independently testable by calling `initiateRefund` on a wallet payment after its hold has been released (as happens post-capture); verify `releaseHold` is NOT called by the adapter and the result status is `succeeded`.

**Acceptance Scenarios**:

1. **Given** a succeeded wallet payment (hold captured), **when** `initiateRefund` is called, **then** the adapter returns `PaymentRefundResult::Succeeded` without calling `WalletModuleContract::releaseHold()`.
2. **Given** the same refund called twice with the same `refundId`, **then** both calls return `Succeeded` (idempotent); the compensating journal posted by the orchestration layer uses the `refundId` as its idempotency key and is posted exactly once.
3. **Given** a partial refund for an amount less than the original payment, **then** the adapter returns the partial amount confirmed; the compensating journal posted by orchestration reflects only the partial amount.

---

### User Story 5 — PIN and Step-Up Authentication Gate (Priority: P2)

A payment above the configured spending threshold requires a step-up authentication token. The token must be bound to the paying actor's session. Below the threshold, no step-up is required and the payment proceeds with normal session authentication.

**Why this priority**: Step-up is already implemented in the adapter. This story ensures the threshold is configurable (not hard-coded), the token binding to both actor and session is tested, and the threshold currency is respected. Completing this validation and testing it explicitly closes the residual P09 gap on PIN/step-up verification.

**Independent Test**: Independently testable by submitting payments just below, at, and above the threshold with and without a valid step-up token.

**Acceptance Scenarios**:

1. **Given** a threshold of 10,000 BWP, **when** a payment for 9,999 BWP is initiated without a step-up token, **then** the payment proceeds to the balance check.
2. **Given** a threshold of 10,000 BWP, **when** a payment for 10,000 BWP is initiated without a step-up token, **then** `StepUpRequiredException` is thrown with the intent ID.
3. **Given** a payment above the threshold with a valid step-up token bound to the correct actor and session, **then** the payment proceeds.
4. **Given** a payment above the threshold with a step-up token issued for a different actor or session, **then** `StepUpRequiredException` is thrown and no hold is placed.
5. **Given** the threshold changed from 10,000 BWP to 5,000 BWP in configuration, **when** a payment for 7,500 BWP is initiated, **then** the new threshold is respected (step-up required).

---

### Edge Cases

- What happens when `walletId` is null in `PaymentInitiateRequest`? The adapter must throw `InvalidArgumentException` immediately, before any wallet module calls.
- What happens when the wallet module's `createHold()` throws an exception other than `InsufficientBalanceException` or `WalletNotTransactableException`? The exception must propagate to the orchestration layer without hold orphaning.
- What happens when a hold expires before capture? The payment attempt must be identified as expired and the hold released; a fresh attempt may be initiated if the intent has not expired.
- What happens when both `availableBalance()` (optimistic) shows sufficient funds but `createHold()` (authoritative SELECT FOR UPDATE) fails with insufficient balance? The adapter must not retry or place a partial hold; it must throw `InsufficientFundsException`.
- What happens when the step-up verifier service is unavailable? The payment must fail with a recoverable error (not crash silently), and no hold must be placed.
- What happens when `pocketExists()` returns `true` but `createHold()` reports the pocket does not exist? Treat as an unexpected error; propagate without hold orphaning.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The adapter MUST validate that the target wallet is in an active state (`WalletModuleContract::isActive()`) before placing any hold.
- **FR-002**: The adapter MUST validate that the wallet's currency pocket exists (`WalletModuleContract::pocketExists()`) for the requested currency before placing any hold.
- **FR-003**: The adapter MUST validate that the wallet's owner actor matches the stated payer actor (`WalletModuleContract::ownerActorId()`) before placing any hold.
- **FR-004**: The adapter MUST perform an optimistic balance pre-check (`WalletModuleContract::availableBalance()`) and throw `InsufficientFundsException` if available balance is below the requested amount.
- **FR-005**: The adapter MUST check the per-transaction spending limit from configuration (`payment.wallet_transaction_limit_{currencyCode}`, defaulting to no limit if absent) and throw `TransactionLimitExceededException` if the amount exceeds the limit.
- **FR-006**: The adapter MUST use `paymentAttemptId` as the `transactionReference` for `WalletModuleContract::createHold()`, ensuring idempotency: the same attempt ID always maps to the same hold.
- **FR-007**: `WalletModuleContract::createHold()` MUST be the authoritative balance check. The adapter MUST NOT substitute the pre-check result for the hold's definitive balance enforcement.
- **FR-008**: The adapter MUST require a step-up authentication token for payments at or above the configured threshold (`auth.step_up_threshold`). The token MUST be validated against both the paying actor ID and the session ID.
- **FR-009**: The adapter MUST NOT post ledger journal entries. Ledger posting is exclusively the orchestration layer's responsibility (P08 `postLedgerAcknowledgement`).
- **FR-010**: `initiateRefund()` MUST NOT call `WalletModuleContract::releaseHold()`. Hold release for refunds is the orchestration layer's responsibility. The adapter confirms the refund intent and returns `Succeeded`.
- **FR-011**: `cancelPayment()` MUST delegate hold release to the orchestration layer. The adapter assumes the hold has already been released before `cancelPayment()` is called and returns `cancelled: true`.
- **FR-012**: The adapter MUST support partial refunds: `amountMinor` in `PaymentRefundRequest` may be less than the original captured amount.
- **FR-013**: All financial operations in the adapter MUST be idempotent: calling `initiatePayment`, `initiateRefund`, or `cancelPayment` twice with the same identifiers MUST produce the same observable outcome as calling once.
- **FR-014**: The adapter MUST NOT directly modify wallet balance fields. All balance changes MUST occur through `WalletModuleContract` operations.
- **FR-015**: The adapter MUST audit every hold placement (`payment.wallet.hold_placed`) and every refund confirmation (`payment.wallet.refund_initiated`) via `AuditServiceInterface`.
- **FR-016**: The adapter MUST NOT initiate a capture against an expired hold. Hold expiry detection at capture time is the **orchestration layer's responsibility** (P08 `postLedgerAcknowledgement`). Adding an expiry-check method to `WalletModuleContract` requires a separate ADR (ADR-E48-002 lock). If `WalletModuleContract::createHold()` is called with a `transactionReference` whose prior hold has expired, the wallet domain is expected to either create a new hold or throw — the adapter propagates that result to the orchestration layer without additional wrapping.

### Key Entities

- **WalletPaymentAdapter**: The single implementation of `PaymentAdapterInterface` for wallet payments. Lives in `App\Modules\Payment\Infrastructure\Adapters`. Delegtes exclusively to `WalletModuleContract` and `AuditServiceInterface`.
- **PaymentInitiateRequest**: Value object carrying `paymentIntentId`, `paymentAttemptId`, `walletId`, `amountMinor`, `currencyCode`, `payerActorId`, `correlationId`, `idempotencyKey`, `stepUpToken?`, `sessionId?`, `operationId?`.
- **PaymentAttemptResult**: Value object returned by `initiatePayment` carrying `status`, `walletHoldId`, `feeMinor`, and optionally `ledgerJournalId`.
- **Hold**: A time-bounded reservation on a wallet pocket created by `WalletModuleContract::createHold()`. Identified by a UUID hold ID. Idempotent per `transactionReference`.
- **TransactionLimit**: A configurable per-currency ceiling on the maximum amount of a single wallet payment. Stored as `payment.wallet_transaction_limit_{currencyCode}` in Platform Configuration.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-01**: Every call to `initiatePayment` with invalid ownership, inactive wallet, missing pocket, insufficient balance, or exceeded limit returns a specific typed exception within 500 ms without side effects on the wallet.
- **SC-02**: Retrying `initiatePayment` with the same `paymentAttemptId` after a crash returns the original hold ID without placing a second reservation. Zero duplicate holds exist in the wallet domain for the same attempt.
- **SC-03**: Under concurrent load — two simultaneous `initiatePayment` calls for the same wallet pocket where combined amounts exceed available balance — the wallet balance does not go negative and at most one hold is placed.
- **SC-04**: `initiateRefund` completes without calling `releaseHold` and returns `Succeeded` for both first-call and idempotent second-call with the same `refundId`.
- **SC-05**: Step-up authentication is enforced for all payments at or above the configured threshold. Payments below the threshold proceed without a step-up token. Threshold changes take effect on the next payment without a restart.
- **SC-06**: All adapter financial operations (hold, refund confirmation) produce an audit record. No payment operation completes without an audit trail.
- **SC-07**: PHPStan level 8 reports zero errors in the Payment module after all P09 changes.
- **SC-08**: All new and modified unit tests pass without a running Docker environment. Feature tests requiring the wallet database pass in Docker.

---

## Assumptions

- `WalletModuleContract` is stable (ADR-E48-002 lock). P09 MUST NOT add new methods to this interface. If a new method is needed, it requires a separate ADR.
- The hold-idempotency guarantee (`createHold` with the same `transactionReference` returns the same hold ID) is provided by the Wallet domain and does not need to be reimplemented in the adapter.
- Transaction limits are per-currency values stored in Platform Configuration, not per-actor or per-organisation, unless a future ADR specifies otherwise.
- `initiateRefund()` is called only after the hold has been captured (converted to a debit via P08 ledger acknowledgement). There is no hold to release at refund time. The orchestration layer posts the compensating journal entry.
- The step-up threshold is configured as `auth.step_up_threshold` (integer minor units) with `auth.step_up_threshold_currency`. Constitution Principle 5 confirms the approved default seed is 10,000 BWP.
- `walletId` is resolved and provided in `PaymentInitiateRequest` by the orchestration layer before the adapter is called. The adapter does not resolve wallet ID from actor ID — that is orchestration's responsibility.
- Partial holds and partial captures are not in P09 scope. The hold amount always equals the full payment intent amount.
- `FD05` and `LC04` requirement codes from the architecture document are recorded as AWDOC. This spec is derived from the contract register and completed predecessor evidence. If the architecture document becomes available, this spec must be re-reviewed against FD05 and LC04.
- No new wallet ledger, automatic FX, or external-style checkout is introduced. Scope is strictly the internal wallet debit adapter.
- When `WalletModuleContract::createHold()` is retried with a `transactionReference` whose previous hold has expired, the wallet domain either creates a new hold or throws an exception. Either outcome is acceptable — the adapter propagates it to the orchestration layer. P09 does not implement custom expiry-inspection logic.
- Community domain and bank adapters (P12–P14) are out of scope.

---

## Prerequisite Evidence

| Predecessor | Status | Key Evidence |
|-------------|--------|-------------|
| P06 — Intent, Attempt and Payment State Engine | COMPLETE/RELEASE READY | `specs/055-payment-state-engine/tasks.md` T001–T041 ✓ |
| P07 — Durable Idempotency, Concurrency and Recovery | COMPLETE/RELEASE READY | `specs/056-durable-idempotency-recovery/tasks.md` T001–T027 ✓ |
| P08 — Ledger Acknowledgement and Fulfilment Handoff | COMPLETE/RELEASE READY | `specs/057-ledger-ack-fulfilment/tasks.md` T001–T025 ✓; T026–T028 Docker-gated |
| ADR-E48-002 — Wallet Payment Adapter Delegation | Accepted | `docs/architecture/decisions/ADR-E48-002-wallet-payment-adapter-delegation.md` |
| Payment Contract Register | Published | `docs/architecture/payment-contract-register.md` — sections 2 (PaymentAdapterInterface), 4 (WalletModuleContract) |

---

## Out of Scope

- Ledger posting (owned by P08 `postLedgerAcknowledgement`)
- Bank adapter implementation (P12–P14, externally blocked)
- Payout/disbursement (P18, missing)
- Community domain payment journeys (P24–P28, blocked)
- New wallet module methods (requires ADR)
- Automatic FX conversion
- External-style checkout
- Flutter UI for wallet payment (P22)
- Expired hold expiry detection at capture time (P08/orchestration scope; requires wallet domain changes gated by a future ADR on `WalletModuleContract`)
