# E10 — Withdrawal / Cash-Out

**Feature**: `010-withdrawal-cash-out` | **Epic**: E10 | **Status**: Draft | **Created**: 2026-09-18

---

## 1. Problem Statement

Customers need a way to move money out of their OleKard wallet pocket to an external account — a mobile money number or bank account. Without withdrawal capability the wallet is a one-way deposit box: customers can receive money and make internal transfers (E09) but cannot access their balance outside the platform. E10 delivers the first money-out capability. It introduces the `WithdrawalTransaction` state machine, a `WalletHold` mechanism that reserves funds during the payout processing window, a `PayoutProviderAdapter` interface for external payout dispatch, and an immutable `WithdrawalReceipt` generated on completion. Every wallet debit MUST flow through the E07 double-entry ledger and MUST NOT directly mutate a balance field.

---

## 2. Scope

E10 covers exactly the 12 stories in the approved backlog:

| Story ID            | Title                          | Priority | Feature Group              |
|---------------------|--------------------------------|----------|----------------------------|
| US-WITHDRAW-001     | Initiate withdrawal            | P0       | Withdrawal Initiation       |
| US-WITHDRAW-002     | Select withdrawal channel      | P0       | Withdrawal Initiation       |
| US-WITHDRAW-003     | Enter withdrawal amount        | P0       | Withdrawal Initiation       |
| US-WITHDRAW-004     | Validate withdrawal limit      | P0       | Limit & Fee Validation      |
| US-WITHDRAW-005     | Calculate withdrawal fee       | P0       | Limit & Fee Validation      |
| US-WITHDRAW-006     | Perform risk check             | P0       | Risk Evaluation             |
| US-WITHDRAW-007     | Reserve funds                  | P0       | Fund Reservation            |
| US-WITHDRAW-008     | Send payout request            | P0       | Provider Processing         |
| US-WITHDRAW-009     | Process successful payout      | P0       | Provider Processing         |
| US-WITHDRAW-010     | Process failed payout          | P0       | Provider Processing         |
| US-WITHDRAW-011     | Release reserved funds         | P0       | Fund Reservation            |
| US-WITHDRAW-012     | Generate withdrawal receipt    | P1       | Receipt                     |

**Out of scope for E10**: wallet funding (E08), peer-to-peer transfers (E09), fee-schedule engine (E21), transaction history UI (E11), real risk scoring (E16), dispute/refund management (E20), agent cash-out on behalf of customer (separate backlog story not in this epic), provider adapter implementations (E1000).

---

## 3. Goals

1. Customers can withdraw funds from a wallet pocket to an external mobile money number or bank account via at least one confirmed channel (channel is abstracted behind a `PayoutProviderAdapter` interface; real bindings are TBD-E10-001).
2. Funds are reserved at the wallet layer the moment a withdrawal is confirmed, preventing concurrent spend of committed funds.
3. Every completed withdrawal debits the customer's wallet pocket exclusively through `LedgerModuleContract::postEntry()`; no direct balance mutation is permitted.
4. Every withdrawal attempt (success, failure, cancellation) produces an immutable audit trail.
5. Duplicate provider callbacks never result in a double debit.
6. Withdrawal limits per KYC tier and per channel are enforced server-side from centrally configurable values.
7. A risk evaluation hook gates each withdrawal; in E10 the hook always returns `approved`; E16/E18 binds the real risk engine.

---

## 4. Non-Goals

- This epic does not own specific provider adapter implementations; it defines the `PayoutProviderAdapter` interface only (see TBD-E10-001).
- Fee-schedule management is deferred to E21; E10 reads configurable fee rates from `platform.configuration_values` and defaults to zero until the schedule is populated.
- Agent cash-out on behalf of a customer is a separate backlog capability not in this epic.
- Dispute and refund management is in E20.
- Transaction history search and export is in E11.
- Real-time fraud-scoring is in E16; E10 defines the interface and a stub.
- Admin reconciliation dashboard UI is out of scope; E10 only emits events and stores payout records.

---

## 5. Actors

| Actor                | Description                                                                          |
|----------------------|--------------------------------------------------------------------------------------|
| Customer             | Authenticated end-user withdrawing from their own wallet pocket to an external account |
| Payout Provider      | External payment service (mobile money operator, bank); communicates via callback/webhook |
| Platform System      | The API itself, orchestrating state transitions, provider dispatch, and hold management |
| Admin                | Authorized back-office user who can view withdrawal history and force-fail stuck transactions |

---

## 6. User Stories

### US-WITHDRAW-001 — Initiate withdrawal (P0)

**As a** Customer,  
**I want to** start a withdrawal by specifying my target wallet, channel, amount, and payout destination,  
**so that** the system creates a tracked withdrawal transaction I can follow to completion.

**Acceptance Scenarios:**

- **(a) Happy path**: Customer submits `wallet_id`, `currency_code`, `gross_amount` (positive integer, minor units), `channel` (`mobile_money` or `bank_transfer`), `payout_destination` (channel-specific destination details), and a unique `Idempotency-Key`. System creates a `WithdrawalTransaction` in `pending` status and returns `201` with the transaction ID, calculated `fee_amount`, `net_payout_amount`, and current limits.
- **(b) Missing idempotency key**: Request without `Idempotency-Key` header → `400 MISSING_IDEMPOTENCY_KEY`.
- **(c) Idempotent replay**: Identical `Idempotency-Key` replayed → `200` with the original transaction; no new record created.
- **(d) Wallet not found**: Non-existent or non-owned `wallet_id` → `404 WALLET_NOT_FOUND`.
- **(e) KYC insufficient**: Customer's KYC tier does not permit the selected channel → `422 KYC_INSUFFICIENT`.
- **(f) Authorization gate**: Unauthenticated request → `401`; authenticated but missing `withdrawal.initiate` permission → `403`.
- **(g) Audit**: Initiation is recorded in `platform.audit_events` with action `withdrawal.transaction.initiated`.

---

### US-WITHDRAW-002 — Select withdrawal channel (P0)

**As a** Customer,  
**I want to** choose my preferred withdrawal channel (mobile money or bank transfer),  
**so that** the system routes my payout to the correct external destination.

**Acceptance Scenarios:**

- **(a) Channel on initiation**: `channel` is supplied during initiation (US-WITHDRAW-001). Valid values: `mobile_money`, `bank_transfer`.
- **(b) Invalid channel**: Unsupported channel string → `422 UNSUPPORTED_CHANNEL`.
- **(c) Unavailable channel for currency**: Channel not available for the wallet's currency → `422 CHANNEL_UNAVAILABLE`.
- **(d) Channel after confirmation**: Attempting to change channel on a non-`pending` transaction → `422 TRANSACTION_NOT_MODIFIABLE`.

---

### US-WITHDRAW-003 — Enter withdrawal amount (P0)

**As a** Customer,  
**I want to** specify the exact gross amount I want to withdraw,  
**so that** the system calculates my fee and shows me how much will be paid to my external account before I confirm.

**Acceptance Scenarios:**

- **(a) Valid amount**: Positive integer in minor units, within configured limits → accepted; `fee_amount` and `net_payout_amount` calculated and returned.
- **(b) Zero or negative**: Amount ≤ 0 → `422 INVALID_AMOUNT`.
- **(c) Non-integer**: Decimal or string → `422 INVALID_AMOUNT`.
- **(d) Limit breach**: Amount below minimum or above maximum configured for channel and KYC tier → `422 AMOUNT_OUTSIDE_LIMITS` with `min` and `max` in the error body.
- **(e) Insufficient balance**: Customer's wallet pocket `available_balance` < `gross_amount` → `422 INSUFFICIENT_BALANCE` with `available` in error body.

---

### US-WITHDRAW-004 — Validate withdrawal limit (P0)

**As the** Platform System,  
**I want to** enforce configurable per-channel, per-KYC-tier minimum, maximum, and daily limits before any funds are moved,  
**so that** regulatory and business withdrawal limits are respected at every withdrawal attempt.

**Acceptance Scenarios:**

- **(a) Within limits**: Amount within min/max for channel + tier → validation passes; transaction proceeds.
- **(b) Below minimum**: Amount < configured minimum → `422 AMOUNT_BELOW_MINIMUM`.
- **(c) Above maximum**: Amount > configured maximum → `422 AMOUNT_ABOVE_MAXIMUM`.
- **(d) Daily limit exceeded**: Customer's accumulated successful withdrawal today would exceed the daily limit → `422 DAILY_LIMIT_EXCEEDED` with `remaining` amount in error body.
- **(e) Limits configurable**: Limit values are read from `platform.configuration_values` (keys: `withdrawal.limit.{channel}.{tier}.min_amount`, `withdrawal.limit.{channel}.{tier}.max_amount`, `withdrawal.limit.{channel}.{tier}.daily_max`). Changes take effect for new transactions immediately.

---

### US-WITHDRAW-005 — Calculate withdrawal fee (P0)

**As a** Customer,  
**I want to** see the fee for my selected channel and amount before I confirm,  
**so that** I know my total cost and the exact amount that will be paid to my external account.

**Acceptance Scenarios:**

- **(a) Fee calculation**: System reads the fee rate for the selected channel and currency from `platform.configuration_values` (key: `withdrawal.fee.{channel}.{currency_code}`). Fee = `round(gross_amount × rate_bps / 10000)` in minor units (rate stored as basis points). `net_payout_amount = gross_amount − fee_amount`.
- **(b) Zero fee**: If no fee config key exists or rate = 0, fee = 0 and `net_payout_amount = gross_amount`.
- **(c) Fee presented before confirmation**: The fee and `net_payout_amount` are included in the initiation response so the customer sees them before confirming.
- **(d) Fee rate change between initiation and confirmation**: Fee is re-calculated at confirmation time using the current config value. If the recalculated fee differs from what was quoted, the confirmation endpoint returns `409 FEE_CHANGED` with the updated amounts; the `WithdrawalTransaction` remains in `pending` status.

---

### US-WITHDRAW-006 — Perform risk check (P0)

**As the** Platform System,  
**I want to** evaluate the risk profile of a withdrawal before funds are reserved,  
**so that** suspicious or high-risk withdrawals are blocked before irreversible payout dispatch.

**Acceptance Scenarios:**

- **(a) Risk approved**: Risk evaluation returns `approved` → withdrawal proceeds to fund reservation.
- **(b) Risk rejected**: Risk evaluation returns `rejected` → withdrawal transitions to `cancelled` with `failure_reason = risk_rejected`; `Withdrawal.Risk.Rejected` event emitted; customer receives `422 WITHDRAWAL_RISK_REJECTED`.
- **(c) Stub in E10**: The `WithdrawalRiskService::evaluate()` implementation in E10 always returns `approved`; the interface is defined and injected so E16/E18 can bind a real risk engine without changing calling code.
- **(d) Risk check timing**: Risk evaluation occurs at confirmation time, after fee re-validation and PIN verification, before `WalletHold` creation.
- **(e) Audit**: Risk rejection is recorded in `platform.audit_events` with action `withdrawal.risk.rejected`.

---

### US-WITHDRAW-007 — Reserve funds (P0)

**As the** Platform System,  
**I want to** create an atomic wallet hold on the customer's pocket for the gross withdrawal amount,  
**so that** the reserved funds cannot be spent on another transaction while the payout is in flight.

**Acceptance Scenarios:**

- **(a) Hold created at confirmation**: When the customer confirms with a valid PIN and all pre-checks pass, a `WalletHold` record is created for the `gross_amount`, reducing the pocket's `available_balance` by `gross_amount` while leaving the ledger balance unchanged.
- **(b) Insufficient balance at confirmation**: If `available_balance` < `gross_amount` at the moment of hold creation (race condition), the confirmation fails with `422 INSUFFICIENT_BALANCE`; the `WithdrawalTransaction` remains `pending`; no hold is created.
- **(c) Atomic balance check**: The balance check and hold creation MUST occur within a single DB transaction with a row-level lock (`SELECT ... FOR UPDATE`) on the wallet pocket row to prevent two concurrent withdrawals from over-committing the balance.
- **(d) Hold status lifecycle**: A `WalletHold` has status `active` → `released` on both payout success and failure/cancellation; `WalletModuleContract::releaseHold()` is called in both paths. Hold status and `WithdrawalTransaction` status transitions are always atomic.
- **(e) Hold TTL**: Each hold has an `expires_at` timestamp (`NOW() + withdrawal.hold_ttl_minutes`). The `withdrawal:expire-stale` command releases expired holds and transitions the associated transaction to `failed`.

---

### US-WITHDRAW-008 — Send payout request (P0)

**As the** Platform System,  
**I want to** dispatch the confirmed withdrawal to the selected payout provider and manage the transition on callback,  
**so that** the provider processes the payout and the customer's wallet is debited exactly when funds are confirmed sent.

**Acceptance Scenarios:**

- **(a) Payout dispatch**: After hold creation, the system calls `PayoutProviderAdapter::initiate()` for the selected channel; the adapter returns a `provider_reference_id`; the transaction is stored with that reference and status `processing`.
- **(b) Provider unavailable at dispatch**: Provider API returns a connectivity error → transaction remains `processing`; a retry job is enqueued. If retries exhausted or the `withdrawal.hold_ttl_minutes` TTL is reached, `withdrawal:expire-stale` transitions the transaction to `failed` and releases the hold.
- **(c) Provider synchronous rejection**: Provider rejects the payout immediately (e.g., destination account invalid) → transaction transitions to `failed`; hold released; `Withdrawal.Transaction.Failed` event emitted.
- **(d) Step-up threshold**: For amounts ≥ `auth.step_up_threshold` BWP (configurable), the customer must have completed step-up authentication before confirmation is accepted; otherwise `403 STEP_UP_REQUIRED`.

---

### US-WITHDRAW-009 — Process successful payout (P0)

**As the** Platform System,  
**I want to** credit the external account and debit the customer's wallet via the ledger upon receiving a confirmed success callback from the provider,  
**so that** the customer's available balance decreases accurately and immediately when their payout is confirmed sent.

**Acceptance Scenarios:**

- **(a) Successful payout processing**: Provider sends a success callback; the system posts a balanced debit journal via `LedgerModuleContract::postEntry()` (debit: customer wallet pocket `gross_amount`; credit: platform payout transit account `net_payout_amount`; credit: fee revenue account `fee_amount` if fee > 0); `WalletHold` is released via `WalletModuleContract::releaseHold()`; transaction transitions to `completed`; `Withdrawal.Transaction.Completed` event emitted.
- **(b) 2-line journal when fee = 0**: Debit customer pocket `gross_amount`; credit platform payout transit account `gross_amount`.
- **(c) 3-line journal when fee > 0**: Debit customer pocket `gross_amount`; credit platform payout transit account `net_payout_amount`; credit platform fee revenue account `fee_amount`.
- **(d) Ledger post failure**: `postEntry()` throws an exception → callback processing fails; transaction remains in its prior state; no partial state persisted; the error is surfaced to the retry job.
- **(e) Receipt generated on completion**: A `WithdrawalReceipt` record is persisted atomically in the same DB transaction as the `completed` transition.
- **(f) Notification**: `Withdrawal.Transaction.Completed` event is consumed downstream to notify the customer.

---

### US-WITHDRAW-010 — Process failed payout (P0)

**As a** Customer,  
**I want to** be informed immediately when my payout fails and have my funds restored,  
**so that** I know my money is safe and I can try again or choose a different channel.

**Acceptance Scenarios:**

- **(a) Failure callback**: Provider sends a failure callback → `WalletHold` is released; `available_balance` restored; transaction transitions to `failed`; `Withdrawal.Transaction.Failed` event emitted; no ledger entry posted.
- **(b) Balance restored**: `WalletModuleContract::getAvailableBalance(pocket_reference)` returns the pre-withdrawal value after hold release.
- **(c) Cancellation by customer**: Customer cancels a `pending` transaction via `POST /v1/withdrawals/{id}/cancel` → transaction transitions to `cancelled`; `Withdrawal.Transaction.Cancelled` event emitted. No hold exists at this point (hold is only created at confirmation).
- **(d) Cancellation gate**: Cancellation is only permitted on `pending` transactions. Attempting to cancel a `processing` or `completed` transaction → `422 TRANSACTION_NOT_CANCELLABLE`. A `processing` transaction can only be resolved via provider callback or system timeout.

---

### US-WITHDRAW-011 — Release reserved funds (P0)

**As the** Platform System,  
**I want to** release the `WalletHold` atomically when a withdrawal fails or is cancelled,  
**so that** the customer's available balance is immediately restored and no funds remain locked indefinitely.

**Acceptance Scenarios:**

- **(a) Release on failure**: When the transaction transitions to `failed` (provider failure callback or expiry command), the `WalletHold` record transitions from `active` to `released` and `released_at` is set. This is atomic with the `WithdrawalTransaction` status update.
- **(b) Release on cancellation**: Cancellation of a `pending` transaction does not have a hold to release (hold is only created at confirmation). The system confirms no `active` hold exists and transitions to `cancelled`.
- **(c) Release idempotency**: If the system processes a second failure callback for the same transaction, the hold release is a no-op (hold already `released`); the transaction status does not change again.
- **(d) Expiry-triggered release**: `withdrawal:expire-stale` transitions `processing` transactions past TTL to `failed` and releases their holds atomically. Each transition is its own DB transaction to prevent one stale record blocking others.
- **(e) No ledger entry on release**: Fund release is a wallet-layer operation (updating `WalletHold.status`); it does NOT post any ledger entry. The ledger is only modified on successful payout completion.

---

### US-WITHDRAW-012 — Generate withdrawal receipt (P1)

**As a** Customer,  
**I want** an immutable receipt confirming the completed withdrawal details,  
**so that** there is a verifiable, tamper-evident record of the transaction for my records.

**Acceptance Scenarios:**

- **(a) Receipt persisted on completion**: A `WithdrawalReceipt` record is written atomically with the `completed` transition. The receipt contains: receipt reference (UUID v7), `withdrawal_transaction_id`, `gross_amount`, `fee_amount`, `net_payout_amount`, `currency_code`, `channel`, `payout_destination_masked` (last 4 characters only), `provider_reference_id`, `customer_id`, `wallet_id`, `ledger_journal_id`, `completed_at`.
- **(b) Receipt returned**: The receipt reference and all receipt fields are included in the completion callback response and are queryable at `GET /v1/withdrawals/{id}/receipt`.
- **(c) Receipt immutable**: Attempts to update or delete a `WithdrawalReceipt` after initial insert throw a `WithdrawalReceiptImmutableException`. No receipt fields may be changed after creation.
- **(d) Receipt by reference**: Receipt is queryable by the owning customer via `GET /v1/withdrawals/receipts/{receipt_reference}`.
- **(e) No receipt on failure**: No `WithdrawalReceipt` is created for `failed` or `cancelled` transactions.

---

## 7. Functional Requirements

### 7.1 WithdrawalTransaction Entity

**FR-E10-001** — A `WithdrawalTransaction` SHALL have the following persisted attributes:

| Attribute                 | Type                  | Description                                                                  |
|---------------------------|-----------------------|------------------------------------------------------------------------------|
| `id`                      | UUID v7               | Primary key                                                                  |
| `wallet_id`               | UUID v7               | Source wallet (FK to `wallet.wallets`)                                       |
| `pocket_currency_code`    | CHAR(3)               | Currency of the source pocket (e.g., BWP)                                    |
| `customer_id`             | UUID v7               | Owning customer (FK to `platform.users`)                                     |
| `channel`                 | ENUM                  | See FR-E10-002                                                               |
| `status`                  | ENUM                  | See §7.2                                                                     |
| `gross_amount`            | BIGINT                | Amount the customer wishes to withdraw (minor units)                         |
| `fee_amount`              | BIGINT                | Fee charged in minor units (default 0)                                       |
| `net_payout_amount`       | BIGINT                | `gross_amount − fee_amount`; this is what reaches the external account       |
| `fee_rate_bps`            | INTEGER               | Fee rate at confirmation time (basis points); 0 means fee-free               |
| `idempotency_key`         | VARCHAR(128), UNIQUE  | Client-provided idempotency key for the initiation request                   |
| `payout_destination`      | JSONB                 | External destination details (channel-specific; stored encrypted at rest)    |
| `wallet_hold_id`          | UUID v7, nullable     | Hold ID returned by `WalletModuleContract::createHold()`; set at confirmation |
| `provider_reference_id`   | VARCHAR(255), nullable | External provider's payout transaction identifier                           |
| `risk_check_status`       | VARCHAR(32)           | `approved` or `rejected`; set at confirmation time                           |
| `failure_reason`          | VARCHAR(128), nullable | Machine-readable reason for `failed` or `cancelled` status                 |
| `ledger_journal_id`       | UUID v7, nullable     | E07 journal ID posted at `completed` transition                              |
| `transaction_reference`   | UUID v7, UNIQUE       | Cross-module stable reference (used in E11 transaction history)              |
| `initiated_by`            | UUID v7               | Actor who initiated the transaction                                          |
| `completed_at`            | TIMESTAMPTZ, nullable | Timestamp of `completed` transition                                          |
| `failed_at`               | TIMESTAMPTZ, nullable | Timestamp of `failed` or `cancelled` transition                             |
| `created_at`              | TIMESTAMPTZ           | Record creation timestamp                                                    |
| `updated_at`              | TIMESTAMPTZ           | Last modification timestamp                                                  |

**FR-E10-002** — `channel` ENUM values: `mobile_money`, `bank_transfer`.

---

### 7.2 WithdrawalTransaction Status State Machine

**FR-E10-003** — `WithdrawalTransaction.status` ENUM values and descriptions:

| Status        | Description                                                                      |
|---------------|----------------------------------------------------------------------------------|
| `pending`     | Created; customer has not yet confirmed with PIN                                  |
| `processing`  | Confirmed; hold created; payout dispatched to provider; awaiting callback         |
| `completed`   | Provider confirmed payout sent; ledger debited; receipt generated                |
| `failed`      | Provider declined or timed out; hold released; no ledger entry                   |
| `cancelled`   | Customer cancelled before confirmation; no hold created; no ledger entry         |

**FR-E10-004** — Permitted transitions:

- `pending` → `processing` (customer confirms with PIN; all checks pass; hold created; payout dispatched)
- `pending` → `cancelled` (customer cancels)
- `processing` → `completed` (provider success callback)
- `processing` → `failed` (provider failure callback or system timeout)

All other transitions are prohibited and MUST return `422 TRANSACTION_NOT_MODIFIABLE`.

---

### 7.3 Withdrawal Initiation

**FR-E10-005** — The withdrawal initiation endpoint (`POST /v1/withdrawals`) creates a `WithdrawalTransaction` in `pending` status. Required inputs: `wallet_id`, `currency_code`, `gross_amount`, `channel`, `payout_destination`. The response includes the transaction ID, calculated `fee_amount`, `net_payout_amount`, and applicable withdrawal limits for the customer's tier and channel.

**FR-E10-006** — The customer must own the target wallet. If `wallet_id` belongs to another customer and the caller is not an authorized admin, the request MUST return `404 WALLET_NOT_FOUND` (no enumeration).

**FR-E10-007** — On initiation, the system calculates `fee_amount` using the configured basis-point rate for the channel and currency (FR-E10-015). The calculated fee is returned with the pending transaction and is re-validated at confirmation time.

**FR-E10-008** — The system checks `available_balance ≥ gross_amount` at initiation. If the wallet has insufficient available balance, `422 INSUFFICIENT_BALANCE` is returned immediately; the record is NOT created.

**FR-E10-009** — A new initiation with an identical `idempotency_key` returns `200` with the previously created transaction; no new transaction is persisted.

---

### 7.4 Channel and Destination Validation

**FR-E10-010** — Supported `channel` values at launch: `mobile_money`, `bank_transfer`. An unsupported value returns `422 UNSUPPORTED_CHANNEL`.

**FR-E10-011** — If a channel is configured as unavailable for the wallet's currency (via `platform.configuration_values` key `withdrawal.channel.{channel}.available_currencies`), the request returns `422 CHANNEL_UNAVAILABLE`.

**FR-E10-012** — The `payout_destination` object is validated against a channel-specific schema:
- `mobile_money`: requires `phone_number` (E.164 format) and `operator_code` (string, configured per currency).
- `bank_transfer`: requires `account_number` (string), `bank_code` (string), and optionally `account_name` (string).
- Missing required destination fields → `422 INVALID_PAYOUT_DESTINATION`.

**FR-E10-013** — `payout_destination` is stored in the `WithdrawalTransaction` table encrypted at rest. It MUST NOT appear in domain event payloads, queue messages, or application logs. The receipt stores only a masked representation (`payout_destination_masked`): for mobile money, the last 4 digits of the phone number; for bank transfer, the last 4 digits of the account number.

---

### 7.5 Withdrawal Limit Enforcement

**FR-E10-014** — Withdrawal limits are read from `platform.configuration_values` at initiation and confirmation time. The config key format is:
- Per-transaction minimum: `withdrawal.limit.{channel}.{kyc_tier}.min_amount`
- Per-transaction maximum: `withdrawal.limit.{channel}.{kyc_tier}.max_amount`
- Daily maximum: `withdrawal.limit.{channel}.{kyc_tier}.daily_max`

**FR-E10-015-a** — The customer's KYC tier is resolved from the E05 KYC module (via `KycModuleContract`). Limits for an unresolved tier default to the most restrictive tier's limits.

**FR-E10-015-b** — Daily limit is calculated as the sum of `gross_amount` across all non-cancelled `WithdrawalTransaction` records for the customer in the current calendar day (UTC) with status in (`pending`, `processing`, `completed`). In-flight transactions (pending/processing) are included to prevent a race where two concurrent initiations both pass the limit check before either is persisted.

---

### 7.6 Fee Calculation

**FR-E10-016** — Fee formula: `fee_amount = round(gross_amount × rate_bps / 10000)` where `rate_bps` is the fee rate in basis points from config key `withdrawal.fee.{channel}.{currency_code}`. If the key is absent, `rate_bps = 0`.

**FR-E10-017** — `net_payout_amount = gross_amount − fee_amount`. This is the amount delivered to the customer's external account.

**FR-E10-018** — Fee rates are configurable per channel per currency by Admin via Platform Configuration. No fee rate is hard-coded in domain logic (TBD-E10-002 governs actual rate values).

---

### 7.7 Confirmation (PIN + Risk + Reserve)

**FR-E10-019** — The confirmation endpoint (`POST /v1/withdrawals/{id}/confirm`) executes the following checks in sequence, aborting on the first failure:

1. **Ownership**: Caller is the transaction's `customer_id`.
2. **Status**: Transaction is `pending`; else `422 TRANSACTION_NOT_MODIFIABLE`.
3. **Fee re-validation**: Recalculate fee; if `quoted_fee_amount ≠ recalculated fee_amount`, return `409 FEE_CHANGED` with updated amounts; transaction remains `pending`.
4. **Limit re-check**: Re-validate daily limit excluding the current transaction's own pending record.
5. **PIN verification**: Verify `transaction_pin` against the customer's stored hash via `IdentityModuleContract::verifyTransactionPin()`. Incorrect PIN → `422 INVALID_PIN`; transaction remains `pending` (does NOT transition to `failed`).
6. **Step-up check**: If `gross_amount` converted to BWP ≥ `auth.step_up_threshold`, a step-up authentication assurance level is required; else `403 STEP_UP_REQUIRED`.
7. **Risk check**: `WithdrawalRiskService::evaluate(transaction)` → if `rejected`, transition to `cancelled` with `failure_reason = risk_rejected`; emit `Withdrawal.Risk.Rejected`.
8. **Balance + Hold (atomic)**: Inside a DB transaction with `SELECT ... FOR UPDATE` on the pocket row: check `available_balance ≥ gross_amount`; create `WalletHold` (`active`, `held_amount = gross_amount`); decrement pocket `available_balance` by `gross_amount`.
9. **Dispatch payout**: Invoke `PayoutProviderAdapter::initiate()` (async queue job); store `provider_reference_id`.
10. **Transition**: Set status to `processing`; emit `Withdrawal.Transaction.Processing`.

**FR-E10-020** — The confirmation endpoint requires the `Idempotency-Key` header. If the transaction is already `processing` (replay of a prior successful confirmation), the endpoint returns `200` with the current transaction state; no second hold or dispatch occurs.

**FR-E10-021** — If step-up authentication is required and not present → `403 STEP_UP_REQUIRED`. The `WithdrawalTransaction` remains `pending`; no hold is created.

---

### 7.8 WalletHold Entity

**FR-E10-022** — The `WalletHold` entity represents a committed reservation on a wallet pocket. It is a wallet-layer construct owned by the **E06 Wallet Management module**, stored in `wallet.wallet_holds`, and accessed exclusively via `WalletModuleContract::createHold()` and `WalletModuleContract::releaseHold()`. E10 stores the returned hold identifier as `wallet_hold_id` on `WithdrawalTransaction`. A `WalletHold` DOES NOT create a ledger entry. Key fields relevant to E10:

| Field                   | Description                                                                          |
|-------------------------|--------------------------------------------------------------------------------------|
| `id`                    | UUID v7 returned by `createHold()`; stored as `WithdrawalTransaction.wallet_hold_id` |
| `amount`                | Gross withdrawal amount reserved (minor units)                                       |
| `status`                | `active` → `released` for both success and failure paths (via `releaseHold()`)      |
| `expires_at`            | `NOW() + withdrawal.hold_ttl_minutes`; used by the expiry command                   |
| `transaction_reference` | Cross-module reference linking to `WithdrawalTransaction.transaction_reference`     |

**FR-E10-023** — A `WalletHold` with `status = active` reduces `available_balance` but does NOT reduce `ledger_balance`. The two values diverge for the duration of the payout processing window.

---

### 7.9 Payout Provider Processing

**FR-E10-024** — The `PayoutProviderAdapter` interface abstracts all provider-specific logic. E10 defines this interface; specific implementations are TBD-E10-001. The interface MUST expose at minimum:
- `initiate(WithdrawalTransaction $tx): ProviderPayoutResult` — submit payout to provider
- `verifyCallback(array $payload, string $signature): CallbackResult` — validate and parse provider callback

**FR-E10-025** — When `PayoutProviderAdapter::initiate()` returns successfully, the `provider_reference_id` is stored on the `WithdrawalTransaction`.

**FR-E10-026** — Provider callbacks are received at `POST /v1/withdrawals/callbacks/{provider}` (public, but signature-verified). The payload is stored as a raw JSON field on `WithdrawalProviderCallback` before processing.

**FR-E10-027** — The callback handler verifies the provider's HMAC/signature before processing. Invalid signature → `400 INVALID_CALLBACK_SIGNATURE`; no state change.

---

### 7.10 Successful Payout Completion

**FR-E10-028** — On a verified success callback: the system verifies the callback amount matches `gross_amount` on the `WithdrawalTransaction`. Amount mismatch → transition to `failed`; `Withdrawal.Transaction.AmountMismatch` event emitted; hold released; alert raised.

**FR-E10-029** — On a verified success callback with matching amount, inside a single DB transaction:
1. Post a balanced debit journal via `LedgerModuleContract::postEntry()`:
   - **Fee = 0 (2-line journal)**: Debit customer pocket `gross_amount`; Credit platform payout transit account `gross_amount`.
   - **Fee > 0 (3-line journal)**: Debit customer pocket `gross_amount`; Credit platform payout transit account `net_payout_amount`; Credit platform fee revenue account `fee_amount`.
   - `idempotency_key` for the journal: `withdrawal_complete:{transaction_id}`.
   - Payout transit account reference: `platform.configuration_values` key `ledger.payout_transit.{currency_code}` (resolves TBD-E10-004).
   - Fee revenue account reference: `platform.configuration_values` key `ledger.fee_revenue.{currency_code}`.
2. Release `WalletHold` via `WalletModuleContract::releaseHold(wallet_hold_id)`; E06 transitions status to `released` and sets `released_at`.
3. Transition `WithdrawalTransaction` to `completed`; set `completed_at`.
4. Write `WithdrawalReceipt` (FR-E10-031).
5. Emit `Withdrawal.Transaction.Completed` via outbox.

**FR-E10-030** — `completed` transition MUST NOT proceed if `postEntry()` throws. The DB transaction is rolled back; the `WithdrawalTransaction` remains `processing`; the callback is retried.

---

### 7.11 Failed Payout and Hold Release

**FR-E10-031** — On a verified failure callback, inside a single DB transaction:
1. Transition `WalletHold` from `active` to `released`; set `released_at`; restore `available_balance` on the pocket.
2. Set `WithdrawalTransaction.status = failed`; set `failed_at`; store `failure_reason` from callback.
3. Emit `Withdrawal.Transaction.Failed` via outbox.

**FR-E10-032** — No ledger entry is posted on failure. `LedgerModuleContract::getBalance()` for the customer's pocket is unchanged from the pre-withdrawal value.

---

### 7.12 Idempotency

**FR-E10-033** — All state-changing endpoints (`POST /v1/withdrawals`, `/confirm`, `/cancel`) MUST require the `Idempotency-Key` header. Requests without it → `400 MISSING_IDEMPOTENCY_KEY`.

**FR-E10-034** — Idempotency is stored using the platform `IdempotencyService` (key-space: `withdrawal:{idempotency_key}`). Replayed requests return the original response body and appropriate HTTP status (`200` for replays of `201` requests).

**FR-E10-035** — Callback processing is idempotent: the `provider_reference_id` is stored in `platform.idempotency_store` on first successful processing. A duplicate callback with the same key returns `200` immediately without re-processing.

**FR-E10-036** — Journal idempotency key for the completion journal: `withdrawal_complete:{transaction_id}` (server-generated, not client-supplied). This prevents any double-debit even if the callback is replayed.

---

### 7.13 Transaction Expiry

**FR-E10-037** — A scheduled Artisan command `withdrawal:expire-stale` MUST be registered and run on a configurable schedule (default: every 5 minutes). It identifies `WithdrawalTransaction` records with `status = processing` and `created_at < NOW() − withdrawal.hold_ttl_minutes` (default 60 minutes) and for each:
1. Releases the associated `WalletHold` atomically.
2. Transitions the transaction to `failed` with `failure_reason = processing_timeout`.
3. Emits `Withdrawal.Transaction.Expired`.

**FR-E10-038** — The expiry command MUST be idempotent: re-running when no stale records exist is a no-op. Each transition is performed in its own DB transaction; a failure on one record does not block processing of remaining records.

**FR-E10-039** — Config key governing expiry: `withdrawal.hold_ttl_minutes` (default 60). Changes take effect for the next command invocation.

---

### 7.14 WithdrawalReceipt Entity

**FR-E10-040** — A `WithdrawalReceipt` is written atomically with the `completed` transition. It is immutable after initial insert; any attempt to `save()`, `update()`, or `delete()` an existing record MUST throw `WithdrawalReceiptImmutableException`. Attributes:

| Attribute                   | Type                | Description                                                     |
|-----------------------------|---------------------|-----------------------------------------------------------------|
| `id`                        | UUID v7             | Primary key                                                     |
| `receipt_reference`         | UUID v7, UNIQUE     | Human-facing receipt identifier                                 |
| `withdrawal_transaction_id` | UUID v7 FK          | Owning transaction                                              |
| `gross_amount`              | BIGINT              | Amount the customer withdrew                                    |
| `fee_amount`                | BIGINT              | Fee charged                                                     |
| `net_payout_amount`         | BIGINT              | Amount delivered to external account                            |
| `currency_code`             | CHAR(3)             | Currency                                                        |
| `channel`                   | VARCHAR(32)         | Withdrawal channel                                              |
| `payout_destination_masked` | VARCHAR(255)        | Last 4 chars of destination (phone or account); no PII         |
| `provider_reference_id`     | VARCHAR(255)        | External provider's reference                                   |
| `customer_id`               | UUID v7             | Owning customer                                                 |
| `wallet_id`                 | UUID v7             | Source wallet                                                   |
| `ledger_journal_id`         | UUID v7             | E07 journal posted at completion                                |
| `completed_at`              | TIMESTAMPTZ         | Completion timestamp                                            |

---

### 7.15 Domain Events

**FR-E10-041** — The following events are emitted via `OutboxEventPublisher` inside the same DB transaction as the state change:

| Event Name                                 | Trigger                                          |
|--------------------------------------------|--------------------------------------------------|
| `Withdrawal.Transaction.Initiated`         | `WithdrawalTransaction` created (`pending`)      |
| `Withdrawal.Transaction.Processing`        | Confirmation successful; hold created; dispatched |
| `Withdrawal.Transaction.Completed`         | Wallet debited; status → `completed`             |
| `Withdrawal.Transaction.Failed`            | Provider declined or timeout; hold released       |
| `Withdrawal.Transaction.Cancelled`         | Customer cancelled                               |
| `Withdrawal.Transaction.Expired`           | Expiry command transitions `processing` to `failed` |
| `Withdrawal.Transaction.AmountMismatch`    | Callback amount ≠ transaction amount             |
| `Withdrawal.Risk.Rejected`                 | Risk check returned `rejected`                   |
| `Withdrawal.Transaction.LateCallback`      | Success callback received for an already-`failed` transaction — anomaly alert, no debit |

**FR-E10-042** — Event payloads MUST NOT contain PII (no `payout_destination`, phone number, or account number). Payloads include: `withdrawal_transaction_id`, `wallet_id`, `currency_code`, `gross_amount`, `status`, `occurred_at`, `correlation_id`.

---

### 7.16 Audit Requirements

**FR-E10-043** — The following actions MUST be recorded in `platform.audit_events`:

| Action                                | Trigger                                             |
|---------------------------------------|-----------------------------------------------------|
| `withdrawal.transaction.initiated`    | `WithdrawalTransaction` created                     |
| `withdrawal.transaction.confirmed`    | Customer confirms with PIN                          |
| `withdrawal.transaction.completed`    | Wallet debited; receipt generated                   |
| `withdrawal.transaction.failed`       | Transaction failed                                  |
| `withdrawal.transaction.cancelled`    | Transaction cancelled                               |
| `withdrawal.transaction.expired`      | Expiry command transitions transaction              |
| `withdrawal.pin_failed`               | Incorrect PIN at confirmation (transaction stays `pending`) |
| `withdrawal.risk.rejected`            | Risk check returned `rejected`                      |
| `withdrawal.admin.force_failed`       | Admin force-fails a stuck transaction               |

---

### 7.17 Observability & Metrics

**FR-E10-044** — E10 MUST emit structured metrics via `MetricsCollectorInterface` injected into the relevant service classes:

| Metric name                                    | Type      | Labels                              | Emitted when                                     |
|------------------------------------------------|-----------|-------------------------------------|--------------------------------------------------|
| `withdrawal.transactions.initiated`            | Counter   | `channel`                           | Transaction created (`pending`)                  |
| `withdrawal.transactions.completed`            | Counter   | `channel`                           | Status → `completed`                             |
| `withdrawal.transactions.failed`               | Counter   | `channel`, `reason`                 | Status → `failed`                                |
| `withdrawal.transactions.cancelled`            | Counter   | `channel`                           | Status → `cancelled`                             |
| `withdrawal.transactions.expired`              | Counter   | —                                   | Expiry command transitions record                |
| `withdrawal.callbacks.processing.duration`     | Histogram | `provider`, `outcome`               | Provider callback fully processed                |
| `withdrawal.holds.created`                     | Counter   | `channel`                           | `WalletHold` created at confirmation             |
| `withdrawal.holds.released`                    | Counter   | `reason` (failure \| expiry)        | `WalletHold` released without completion         |
| `withdrawal.risk.checks`                       | Counter   | `result` (approved \| rejected)     | Risk evaluation completes                        |

**FR-E10-045** — Metrics are emitted after the DB transaction commits. Metric emission failure MUST NOT cause the business operation to fail or roll back.

---

## 8. Data Model

### 8.1 Schema

All withdrawal tables reside in the `withdrawal` schema. Models use `connection = platform`, `table = withdrawal.{table}`.

### 8.2 Entities

```
withdrawal.withdrawal_transactions
  id                       UUID v7 PK
  wallet_id                UUID FK → wallet.wallets.id
  pocket_currency_code     CHAR(3) NOT NULL
  customer_id              UUID FK → platform.users.id
  channel                  VARCHAR(32) NOT NULL  -- mobile_money | bank_transfer
  status                   VARCHAR(32) NOT NULL  -- pending | processing | completed | failed | cancelled
  gross_amount             BIGINT NOT NULL
  fee_amount               BIGINT NOT NULL DEFAULT 0
  net_payout_amount        BIGINT NOT NULL
  fee_rate_bps             INTEGER NOT NULL DEFAULT 0
  idempotency_key          VARCHAR(128) UNIQUE NOT NULL
  payout_destination       JSONB NOT NULL          -- encrypted at rest; never in events/logs
  provider_reference_id    VARCHAR(255) nullable
  risk_check_status        VARCHAR(32) NOT NULL DEFAULT 'approved'
  failure_reason           VARCHAR(128) nullable
  ledger_journal_id        UUID nullable
  transaction_reference    UUID NOT NULL UNIQUE
  initiated_by             UUID NOT NULL
  completed_at             TIMESTAMPTZ nullable
  failed_at                TIMESTAMPTZ nullable
  created_at               TIMESTAMPTZ NOT NULL
  updated_at               TIMESTAMPTZ NOT NULL

-- wallet.wallet_holds: E06-owned, not E10 — E10 accesses holds exclusively via
--   WalletModuleContract::createHold() / releaseHold()
-- E10 stores the returned hold id as withdrawal_transactions.wallet_hold_id (UUID NULLABLE)

withdrawal.withdrawal_receipts
  id                         UUID v7 PK
  receipt_reference          UUID NOT NULL UNIQUE
  withdrawal_transaction_id  UUID FK → withdrawal_transactions.id
  gross_amount               BIGINT NOT NULL
  fee_amount                 BIGINT NOT NULL DEFAULT 0
  net_payout_amount          BIGINT NOT NULL
  currency_code              CHAR(3) NOT NULL
  channel                    VARCHAR(32) NOT NULL
  payout_destination_masked  VARCHAR(255) NOT NULL
  provider_reference_id      VARCHAR(255) nullable
  customer_id                UUID NOT NULL
  wallet_id                  UUID NOT NULL
  ledger_journal_id          UUID NOT NULL
  completed_at               TIMESTAMPTZ NOT NULL

withdrawal.withdrawal_provider_callbacks
  id                         UUID v7 PK
  withdrawal_transaction_id  UUID FK → withdrawal_transactions.id
  provider                   VARCHAR(64) NOT NULL
  provider_reference_id      VARCHAR(255) NOT NULL
  status                     VARCHAR(32) NOT NULL  -- success | failure | unknown
  raw_payload                JSONB NOT NULL
  signature_valid            BOOLEAN NOT NULL
  received_at                TIMESTAMPTZ NOT NULL
  processed_at               TIMESTAMPTZ nullable
```

### 8.3 Key Indexes

- `withdrawal_transactions(idempotency_key)` — UNIQUE (race guard)
- `withdrawal_transactions(customer_id, status, created_at)` — daily limit query
- `withdrawal_transactions(provider_reference_id)` — callback lookup
- `withdrawal_transactions(transaction_reference)` — UNIQUE
- `wallet.wallet_holds(status, expires_at)` — expiry command query (partial index on `active`; index lives in E06 wallet schema, not created by E10 migration)
- `withdrawal_receipts(receipt_reference)` — UNIQUE
- `withdrawal_provider_callbacks(provider_reference_id)` — idempotency check

---

## 9. Withdrawal Flows

### 9.1 Standard Withdrawal Flow

```
Customer → POST /v1/withdrawals
              ↳ BalanceCheck (available_balance ≥ gross_amount)
              ↳ LimitCheck (per-transaction min/max)
              ↳ FeeCalculation
              ↳ WithdrawalTransaction: pending
              ↳ Withdrawal.Transaction.Initiated emitted

        → POST /v1/withdrawals/{id}/confirm (transaction_pin, quoted_fee_amount)
              ↳ FeeReValidation (409 FEE_CHANGED if rate changed)
              ↳ DailyLimitReCheck
              ↳ PIN verification (422 INVALID_PIN if wrong; stays pending)
              ↳ Step-up check (403 STEP_UP_REQUIRED if threshold met)
              ↳ WithdrawalRiskService::evaluate() (422 if rejected; transitions to cancelled)
              ↳ DB transaction (SELECT FOR UPDATE on pocket):
                    WalletHold created (active, gross_amount, expires_at)
                    pocket.available_balance -= gross_amount
              ↳ PayoutProviderAdapter::initiate() → provider_reference_id (async job)
              ↳ WithdrawalTransaction: processing
              ↳ Withdrawal.Transaction.Processing emitted

Provider → POST /v1/withdrawals/callbacks/{provider}
              ↳ SignatureVerification (400 if invalid)
              ↳ AmountVerification
              ↳ IdempotencyCheck
              ↳ [Success path]:
                    DB transaction:
                      LedgerModuleContract::postEntry() (debit pocket, credit transit, credit fee)
                      WalletHold: active → released (via releaseHold())
                      WithdrawalTransaction: processing → completed
                      WithdrawalReceipt created
                    Withdrawal.Transaction.Completed emitted
              ↳ [Failure path]:
                    DB transaction:
                      WalletHold: active → released
                      pocket.available_balance += gross_amount
                      WithdrawalTransaction: processing → failed
                    Withdrawal.Transaction.Failed emitted
```

### 9.2 Cancellation Flow

```
Customer → POST /v1/withdrawals/{id}/cancel  (only from pending)
              ↳ Ownership + status check
              ↳ WithdrawalTransaction: pending → cancelled
              ↳ Withdrawal.Transaction.Cancelled emitted
              ↳ No WalletHold exists; no ledger entry
```

### 9.3 Expiry Flow

```
Scheduler → withdrawal:expire-stale (every 5 min)
              ↳ Query: status=processing AND created_at < NOW() − hold_ttl_minutes
              ↳ For each stale transaction:
                    DB transaction:
                      WalletHold: active → released
                      pocket.available_balance += held_amount
                      WithdrawalTransaction: processing → failed (failure_reason=processing_timeout)
                    Withdrawal.Transaction.Expired emitted
```

---

## 10. Security Requirements

**SR-E10-001** — Provider callback endpoints (`POST /v1/withdrawals/callbacks/{provider}`) MUST validate the provider's HMAC or signature before executing any business logic. Invalid signature MUST return `400` and MUST NOT transition any transaction state.

**SR-E10-002** — Provider HMAC secrets and API credentials MUST be stored in the externally managed secrets store; they MUST NOT appear in environment files committed to the repository.

**SR-E10-003** — Withdrawal limit enforcement (per-transaction minimum/maximum and daily maximum) MUST be enforced server-side. Client-provided amounts are untrusted inputs.

**SR-E10-004** — IDOR protection: a customer may only read/cancel their own `WithdrawalTransaction`. A request for another customer's transaction MUST return `404 WITHDRAWAL_NOT_FOUND` (not `403`) to prevent enumeration.

**SR-E10-005** — Transaction PINs MUST be verified against a bcrypt or Argon2 hash. The plaintext PIN MUST NOT be logged or persisted anywhere (audit events, queue payloads, error messages, or database fields).

**SR-E10-006** — Step-up authentication is required for withdrawals where `gross_amount` converted to BWP meets or exceeds `auth.step_up_threshold`. The threshold MUST NOT be hard-coded; it is read from Platform Configuration at runtime.

**SR-E10-007** — `payout_destination` (containing phone number or bank account details) MUST NOT appear in domain event payloads, queue messages, application logs, or external-facing API responses. Only `payout_destination_masked` (last 4 characters) is exposed.

**SR-E10-008** — All withdrawal mutations MUST record entries in `platform.audit_events`. Admin endpoints require at minimum the permissions listed in §13.

**SR-E10-009** — The `PayoutProviderAdapter::verifyCallback()` MUST be called before any state mutation on a provider callback. No callback is processed on signature verification failure.

**SR-E10-010** — `payout_destination` in the database MUST be encrypted at rest using the platform's encryption key management service. The field is decrypted only when passed to `PayoutProviderAdapter::initiate()`.

---

## 11. Concurrency Requirements

**CC-E10-001** — Concurrent withdrawals from the same wallet: the balance check and `WalletHold` creation MUST occur within a single DB transaction with a row-level lock (`SELECT ... FOR UPDATE`) on the wallet pocket row. This prevents two simultaneous confirmations from both passing the balance check and creating holds that collectively exceed the available balance.

**CC-E10-002** — Customer daily limit enforcement: the daily limit query MUST be executed inside the same DB transaction as the new transaction insertion, preventing a race where two concurrent initiations both pass the limit check before either is persisted.

**CC-E10-003** — Concurrent duplicate provider callbacks for the same `provider_reference_id` MUST result in exactly one processing (one ledger debit, one hold transition). The `IdempotencyService` UNIQUE constraint on the callback key is the first guard; the `WithdrawalTransaction.status` state machine (only `processing` → `completed` permitted) is the second.

**CC-E10-004** — Hold release idempotency: if the failure path or expiry command is triggered twice for the same transaction (e.g., callback and expiry command both run), the second invocation MUST detect that the hold is already `released` and skip the balance restore. No double-return of funds.

---

## 12. API Endpoints

| Method | Path                                          | Auth           | Permission                  | Summary                                        |
|--------|-----------------------------------------------|----------------|-----------------------------|------------------------------------------------|
| POST   | `/v1/withdrawals`                             | Bearer token   | `withdrawal.initiate`       | Create a withdrawal transaction (US-WITHDRAW-001–003) |
| GET    | `/v1/withdrawals`                             | Bearer token   | `withdrawal.read`           | List customer's withdrawal transactions        |
| GET    | `/v1/withdrawals/{id}`                        | Bearer token   | `withdrawal.read`           | Get transaction status                         |
| POST   | `/v1/withdrawals/{id}/confirm`                | Bearer token   | `withdrawal.initiate`       | Confirm with PIN; create hold; dispatch payout (US-WITHDRAW-007–008) |
| POST   | `/v1/withdrawals/{id}/cancel`                 | Bearer token   | `withdrawal.initiate`       | Cancel a pending transaction (US-WITHDRAW-010) |
| POST   | `/v1/withdrawals/callbacks/{provider}`        | HMAC / none    | System only                 | Provider payout callback (US-WITHDRAW-009–011) |
| GET    | `/v1/withdrawals/{id}/receipt`                | Bearer token   | `withdrawal.read`           | Get receipt by transaction ID (US-WITHDRAW-012)|
| GET    | `/v1/withdrawals/receipts/{receipt_reference}`| Bearer token   | `withdrawal.read`           | Get receipt by receipt_reference               |
| GET    | `/v1/admin/withdrawals`                       | Bearer token   | `withdrawal.admin`          | Admin list all withdrawals (paginated)         |
| GET    | `/v1/admin/withdrawals/{id}`                  | Bearer token   | `withdrawal.admin`          | Admin get withdrawal by ID                     |
| POST   | `/v1/admin/withdrawals/{id}/force-fail`       | Bearer token   | `withdrawal.admin`          | Admin force-fail a stuck `processing` transaction |

All endpoints use:
- Response envelope: `{ "data": {...}, "meta": {...} }` (mutations) or `{ "error": { "code": "...", "message": "..." } }` (errors)
- UTC ISO-8601 timestamps
- UUID v7 identifiers
- `Idempotency-Key` header required for all `POST` mutations except system callbacks

---

## 13. Permissions

| Permission             | Granted To                 | Description                                       |
|------------------------|----------------------------|---------------------------------------------------|
| `withdrawal.initiate`  | Customer                   | Create, confirm, and cancel withdrawal transactions |
| `withdrawal.read`      | Customer, Admin            | Read own or all withdrawal transactions/receipts  |
| `withdrawal.admin`     | Admin                      | Full admin access to all withdrawal records       |

---

## 14. Integration Boundaries

| Upstream Module           | Contract Used                              | E10 Usage                                                              |
|---------------------------|--------------------------------------------|------------------------------------------------------------------------|
| E07 Wallet Ledger         | `LedgerModuleContract`                     | `postEntry()` to debit wallet pocket on completion; balance query      |
| E06 Wallet Management     | `WalletModuleContract`                     | Available-balance read; pocket ledger account reference; hold management |
| E05 KYC                   | `KycModuleContract`                        | Customer KYC tier for limit enforcement                                |
| E04 Authorization         | `AuthorizationModuleContract` / Gates      | `withdrawal.*` permission checks                                       |
| E03 Device & Session      | Session middleware                         | Step-up authentication signal                                          |
| E01 Authentication        | JWT / Auth middleware                      | Bearer token validation                                                |
| Platform Foundation       | `IdempotencyService`                       | Idempotency key storage for callbacks and mutations                    |
| Platform Foundation       | `OutboxEventPublisher`                     | Reliable event emission inside DB transactions                         |
| Platform Foundation       | `AuditService`                             | `platform.audit_events` writes                                         |
| Platform Configuration    | Config service / `configuration_values`    | Fee rates, withdrawal limits, hold TTL, step-up threshold              |
| E1000 Provider Adapters   | `PayoutProviderAdapter` (defined in E10)   | Provider-specific payout dispatch and callback verification            |
| E16 Risk Engine           | `WithdrawalRiskService` (stubbed in E10)   | Risk evaluation hook; E16 binds real engine                            |

**Advance Gate constraints**:
- TBD-E10-004 (payout transit account ledger reference keys) MUST be resolved before E10 completion journal posting is functional.
- `WalletModuleContract::getAvailableBalance()` and `WalletModuleContract::createHold()` must be available (confirmed or implemented) before E10 hold creation can be exercised.

---

## 15. Success Criteria

**SC-E10-001** — A customer completes the withdrawal flow end-to-end: from `POST /v1/withdrawals` through PIN confirmation and provider callback to `completed`; `LedgerModuleContract::getBalance()` on the wallet pocket reflects the debited `gross_amount`; `available_balance` and `ledger_balance` are equal post-completion.

**SC-E10-002** — A duplicate provider success callback (identical `provider_reference_id`) received within the idempotency TTL results in `200` on the second call and no second ledger posting; `getBalance()` confirms the wallet was debited exactly once.

**SC-E10-003** — A failed payout: provider callback failure → `WalletHold` released; customer's `available_balance` restored to pre-withdrawal value; no `WithdrawalReceipt` created; `Withdrawal.Transaction.Failed` event emitted.

**SC-E10-004** — Concurrent confirmation requests for the same transaction (identical `withdrawal_id`, two simultaneous `POST /confirm`): only one creates a hold; the second returns the idempotent `200` response; exactly one `WalletHold` record and exactly one payout dispatch.

**SC-E10-005** — Concurrent withdrawals draining the same wallet: if the customer has only enough available balance for one, exactly one succeeds (`processing`) and the other returns `422 INSUFFICIENT_BALANCE`.

**SC-E10-006** — Wrong PIN at confirmation: `422 INVALID_PIN` returned; transaction remains `pending`; no hold created; PIN value absent from all audit events and logs.

**SC-E10-007** — Fee rate change between initiation and confirmation: `409 FEE_CHANGED` returned with updated fee; transaction remains `pending`; no hold created.

**SC-E10-008** — A stale `processing` transaction (past `hold_ttl_minutes`) is expired by `withdrawal:expire-stale`; the hold is released atomically; `available_balance` restored; `Withdrawal.Transaction.Expired` event emitted.

**SC-E10-009** — Withdrawal limit enforcement: above-maximum returns `422 AMOUNT_ABOVE_MAXIMUM`; below-minimum returns `422 AMOUNT_BELOW_MINIMUM`; daily-limit-exceeded returns `422 DAILY_LIMIT_EXCEEDED`.

**SC-E10-010** — All withdrawal mutations produce corresponding entries in `platform.audit_events`. PIN value is absent from all audit event records.

**SC-E10-011** — Provider HMAC verification: a callback with an invalid or missing signature returns `400 INVALID_CALLBACK_SIGNATURE`; no state change occurs.

**SC-E10-012** — `WithdrawalReceipt` is immutable after creation: any attempt to `save()`, `update()`, or `delete()` a persisted receipt throws `WithdrawalReceiptImmutableException`.

---

## 16. Edge Cases

**EC-E10-01** — Provider sends a success callback after the transaction has already been `completed` (second delivery): idempotency guard detects duplicate `provider_reference_id`; callback acknowledged with `200`; wallet is NOT debited again; hold already `released`.

**EC-E10-02** — Provider sends a success callback after the transaction status is `failed` (late success from a provider that retried): `Withdrawal.Transaction.LateCallback` alert event emitted; transaction is NOT automatically updated; admin is notified for manual review. Automatic re-crediting from a `failed` state is prohibited.

**EC-E10-03** — Provider callback amount differs from the `gross_amount` on the `WithdrawalTransaction`: transaction transitions to `failed`; hold released; `Withdrawal.Transaction.AmountMismatch` event emitted; no ledger entry.

**EC-E10-04** — Customer attempts to cancel a `processing` transaction: `422 TRANSACTION_NOT_CANCELLABLE`; the hold remains `active`; the transaction can only resolve via provider callback or expiry.

**EC-E10-05** — Customer's wallet pocket is suspended at the moment of hold creation: `WalletModuleContract::createHold()` returns an error; confirmation fails with `422 WALLET_SUSPENDED`; transaction remains `pending`; no hold created.

**EC-E10-06** — Two instances of the expiry command run simultaneously for the same stale transaction: only one succeeds in updating the hold and transaction status (DB-level locking on the hold row); the second is a no-op.

**EC-E10-07** — Customer has no wallet pocket in the requested `currency_code`: `422 POCKET_NOT_FOUND`; no transaction created.

**EC-E10-08** — `PayoutProviderAdapter` binding is missing from the IoC container (E1000 not wired): the withdrawal service throws a container resolution exception at boot time; the application does not start; this is caught at deployment, not at runtime.

**EC-E10-09** — Customer's available balance equals exactly the gross amount requested: withdrawal succeeds (`≥` check, not `>`).

**EC-E10-10** — The `LedgerModuleContract::postEntry()` call at completion succeeds but the outbox event write fails: the DB transaction rolls back; the `WithdrawalTransaction` reverts to `processing`; the hold reverts to `active`; the callback is retried — ensuring the success callback is eventually processed exactly once.

---

## 17. TBDs

| ID           | Description                                                                                                   | Gate                              |
|--------------|---------------------------------------------------------------------------------------------------------------|-----------------------------------|
| TBD-E10-001  | Payout provider selection: specific mobile money operators and bank integration partners not yet selected. E10 defines `PayoutProviderAdapter` interface and a `MockPayoutProviderAdapter` for testing; real adapters implemented when providers selected. | Before E10 production deployment |
| TBD-E10-002  | Withdrawal fee rates: basis-point rates per channel and currency are not yet defined. E10 reads from config; defaults to 0 until set by the business. | Before customer-facing go-live |
| TBD-E10-003  | Withdrawal limit schedule: specific amounts for min/max/daily per KYC tier per channel are not yet defined. Implementation reads from config; defaults to permissive limits (0 min, 1 000 000 max, no daily cap) until the schedule is loaded. | Before customer-facing go-live |
| TBD-E10-004  | Payout transit account reference: the config keys `ledger.payout_transit.{currency_code}` (analogous to TBD-E08-004 for the float account) must be populated with actual ledger account references before E10 completion journal posting is functional. | Before E10 implementation of ledger posting |
| TBD-E10-005  | Risk check thresholds: the criteria under which `WithdrawalRiskService::evaluate()` would reject a withdrawal are not defined in E10. E10's stub always returns `approved`. Real criteria are the responsibility of E16. | Before E16 integration |

---

## 18. Assumptions

1. The `LedgerModuleContract` (E07) is bound in the IoC container before any E10 financial operation executes.
2. The customer's wallet pocket ledger account is registered (via `LedgerModuleContract::registerAccount()`) when the wallet pocket is created in E06.
3. `WalletModuleContract` exposes `getAvailableBalance(pocket_reference)`, `createHold(pocket_reference, amount, expires_at)`, and `releaseHold(hold_id)` methods (no `consumeHold`; both success and failure paths call `releaseHold()`); if they are not yet implemented, E10 can stub them at the contract boundary.
4. The `platform.configuration_values` table is accessible and seeded with at minimum the config key structure (values may default to 0/unlimited).
5. All monetary amounts are stored and processed in the smallest currency unit. No floating-point arithmetic is used.
6. The `OutboxEventPublisher` and `IdempotencyService` from Platform Foundation are available and correctly wired.
7. Provider adapter implementations are injected via the IoC container; E10 domain code never instantiates adapters directly.
8. The `WalletHold` table is part of the `wallet` schema (E06-owned). E10 accesses holds exclusively via `WalletModuleContract::createHold()` and `WalletModuleContract::releaseHold()` — no direct cross-schema table access. E10 stores the returned hold ID as `WithdrawalTransaction.wallet_hold_id`. (Revised per research.md Decision 1.)

---

## 19. Epic-Level Definition of Done

- [ ] All 12 stories meet their story-specific acceptance scenarios.
- [ ] `WithdrawalTransaction` state machine is fully tested: all permitted transitions have a happy-path test; all prohibited transitions return `422`.
- [ ] `WalletHold` lifecycle is fully tested: creation (`active`), release on success completion (`releaseHold()` in success callback path), and release on failure/expiry.
- [ ] Provider callback idempotency is tested: duplicate success callback → exactly one ledger debit; exactly one `WalletHold` consumption.
- [ ] Concurrent balance-check + hold-creation is tested: two simultaneous confirmations → only one creates a hold when balance is sufficient for one.
- [ ] Daily limit race condition is tested: two simultaneous initiations → second fails with `422 DAILY_LIMIT_EXCEEDED` when the first would breach the limit.
- [ ] `withdrawal:expire-stale` command transitions stale `processing` transactions to `failed`; releases holds; `Withdrawal.Transaction.Expired` emitted; command is idempotent.
- [ ] All 12 success criteria (SC-E10-001 through SC-E10-012) are covered by at least one automated test.
- [ ] All 9 metrics (FR-E10-044) are emitted by the relevant service classes.
- [ ] PHPStan level 8 passes over `app/Modules/Withdrawal/`.
- [ ] All withdrawal mutations produce entries in `platform.audit_events`. PIN value absent from all audit records.
- [ ] All events use `OutboxEventPublisher` inside the same DB transaction as the triggering state change.
- [ ] `Idempotency-Key` is enforced on all mutation endpoints; tests confirm `400` without it.
- [ ] Withdrawal limit enforcement tests: below-minimum, above-maximum, daily-limit-exceeded each return the correct error code.
- [ ] Step-up authentication gate tested: amount at/above threshold rejects without step-up; below threshold proceeds.
- [ ] Fee mismatch at confirmation (`409 FEE_CHANGED`) is tested.
- [ ] IDOR test confirms customer cannot read or cancel another customer's transaction.
- [ ] `WithdrawalReceipt` immutability tested: `save()`, `update()`, `delete()` all throw after initial insert.
- [ ] No direct wallet balance mutation; all debits flow through `LedgerModuleContract::postEntry()`.
- [ ] **Advance Gate**: TBD-E10-004 (payout transit account reference config keys) resolved before ledger posting implementation begins.
- [ ] Security gate: provider callback with invalid HMAC returns `400` without state change.
- [ ] OpenAPI documentation updated for all E10 endpoints.
- [ ] No unresolved CRITICAL or HIGH defects at release.

---

## 20. Clarifications

### Session 2026-09-18

- Q: Should the risk check run at initiation or at confirmation time? → A: At confirmation time (after fee re-validation and PIN verification, before hold creation). This prevents funds from being reserved for a transaction that will be risk-rejected, and is consistent with the E09 pattern of performing all validations at the final commitment step.
- Q: Does fund reservation (WalletHold) create a ledger entry, or is it a wallet-layer construct only? → A: Wallet-layer only. The `WalletHold` reduces `available_balance` but does not post a ledger entry. The actual ledger debit occurs only on payout success (completion). This matches the industry-standard pre-authorization pattern and keeps the ledger as the single source of truth for settled balances.
- Q: What is the permitted cancellation window — can the customer cancel a `processing` transaction? → A: No. Cancellation is only permitted on `pending` transactions (before PIN confirmation and hold creation). Once the transaction is `processing`, the payout is in flight and cannot be recalled by the customer; resolution is via provider callback or system timeout/expiry.
