# E08 — Wallet Funding

**Feature**: `008-wallet-funding` | **Epic**: E08 | **Status**: Draft | **Created**: 2026-09-18

---

## 1. Problem Statement

Customers and agents need a way to add money to wallets in the OleKard ecosystem. Without funding, wallets have zero balance and the entire downstream money-movement chain (transfers, payments, withdrawals) cannot be exercised. E08 delivers the first money-in capability: it introduces the `FundingTransaction` state machine, integrates with external payment providers via an abstract adapter interface, handles bank-transfer reference matching, and enables agent-assisted cash-in for customers who lack direct digital payment access. Every wallet credit MUST flow through the E07 double-entry ledger rather than mutating balances directly.

---

## 2. Scope

E08 covers exactly the 19 stories in the approved backlog:

| Story ID       | Title                               | Priority | Feature Group             |
|----------------|-------------------------------------|----------|---------------------------|
| US-FUND-001    | Initiate wallet funding             | P0       | Funding Initiation        |
| US-FUND-002    | Select funding method               | P0       | Funding Initiation        |
| US-FUND-003    | Enter funding amount                | P0       | Funding Initiation        |
| US-FUND-004    | Validate funding limits             | P0       | Funding Initiation        |
| US-FUND-005    | Calculate funding fee               | P0       | Funding Initiation        |
| US-FUND-006    | Confirm funding                     | P0       | Funding Initiation        |
| US-FUND-007    | Process funding payment             | P0       | Provider Processing       |
| US-FUND-008    | Credit wallet after confirmed payment | P0     | Provider Processing       |
| US-FUND-009    | Handle failed funding               | P0       | Provider Processing       |
| US-FUND-010    | Handle duplicate provider callback  | P0       | Provider Processing       |
| US-FUND-011    | Generate funding reference          | P1       | Bank Matching             |
| US-FUND-012    | Match incoming bank payment         | P1       | Bank Matching             |
| US-FUND-013    | Auto-reconcile bank deposit         | P1       | Bank Matching             |
| US-FUND-014    | Handle unmatched deposit            | P1       | Bank Matching             |
| US-FUND-015    | Agent cash-in customer wallet       | P0       | Agent Cash-In             |
| US-FUND-016    | Verify customer transaction         | P0       | Agent Cash-In             |
| US-FUND-017    | Debit agent float                   | P0       | Agent Cash-In             |
| US-FUND-018    | Credit customer wallet              | P0       | Agent Cash-In             |
| US-FUND-019    | Generate customer receipt           | P0       | Agent Cash-In             |

**Out of scope for E08**: withdrawal/cash-out (E09), peer transfers (E10), fee-schedule engine (E21), transaction history UI (E11), risk scoring (E16), dispute management (E20).

---

## 3. Goals

1. Customers can add funds to a wallet pocket from at least one confirmed payment method (method selection is TBD-E08-001; the provider integration is abstracted behind a `FundingProviderAdapter` interface).
2. Agents can perform cash-in on behalf of customers by debiting the agent's float account and crediting the customer's wallet pocket atomically.
3. Every successful funding credits the wallet exclusively through `LedgerModuleContract::postEntry()`; no direct balance mutation is permitted.
4. Every funding attempt (success, failure, cancellation) produces an immutable audit trail.
5. Duplicate provider callbacks never result in a double credit.
6. Funding limits per KYC tier and per funding method are enforced server-side from centrally configurable values.

---

## 4. Non-Goals

- This epic does not own specific provider adapter implementations; it defines the `FundingProviderAdapter` interface only (see TBD-E08-005).
- Fee-schedule management is deferred to E21; E08 reads configurable fee rates from `platform.configuration_values` and defaults to zero until the schedule is populated.
- Wallet-to-wallet transfers are in E10.
- Cash-out / withdrawal from wallet is in E09.
- Dispute and refund management is in E20.
- Transaction history search and export is in E11.
- Real-time fraud-scoring gates are in E16 (E08 does not call a risk engine).
- Admin reconciliation dashboard UI is out of scope; E08 only emits events and stores unmatched deposit records.

---

## 5. Actors

| Actor               | Description                                                                          |
|---------------------|--------------------------------------------------------------------------------------|
| Customer            | Authenticated end-user funding their own wallet via digital payment or agent assistance |
| Agent               | Authenticated OleKard agent performing cash-in on behalf of a customer              |
| Payment Provider    | External payment service (mobile money, bank, card); communicates via callback/webhook |
| Bank System         | Financial institution transmitting incoming deposit notifications for bank-transfer funding |
| Admin               | Authorized back-office user who reviews and resolves unmatched bank deposits         |
| Platform System     | The API itself, acting as orchestrator for callback processing and async state transitions |

---

## 6. User Stories

### US-FUND-001 — Initiate wallet funding (P0)

**As a** Customer,  
**I want to** start a funding request by specifying my target wallet, currency, amount, and preferred payment method,  
**so that** the system creates a tracked funding transaction I can follow to completion.

**Acceptance Scenarios:**

- **(a) Happy path**: Customer submits `wallet_id`, `currency_code`, `amount` (positive integer, minor units), `funding_method`, and a unique `Idempotency-Key`. System creates a `FundingTransaction` in `pending` status and returns `201` with the transaction ID and status.
- **(b) Missing idempotency key**: Request without `Idempotency-Key` header → `400 MISSING_IDEMPOTENCY_KEY`.
- **(c) Idempotent replay**: Identical `Idempotency-Key` replayed → `200` with the original transaction ID; no new record created.
- **(d) Wallet not found**: Non-existent or non-owned `wallet_id` → `404 WALLET_NOT_FOUND`.
- **(e) KYC insufficient**: Customer's KYC tier does not permit the selected funding method → `422 KYC_INSUFFICIENT`.
- **(f) Authorization gate**: Unauthenticated request → `401`; authenticated but missing `funding.initiate` permission → `403`.
- **(g) Audit**: Initiation is recorded in `platform.audit_events` with action `funding.transaction.initiated`.

---

### US-FUND-002 — Select funding method (P0)

**As a** Customer,  
**I want to** choose my preferred funding method (mobile money, bank transfer, card),  
**so that** the system routes my payment to the correct provider.

**Acceptance Scenarios:**

- **(a) Method selection on initiation**: `funding_method` is supplied during initiation (US-FUND-001) or on a dedicated endpoint that updates a `pending` transaction. Valid values: `mobile_money`, `bank_transfer`, `card`, `agent` (agent is only settable by an Agent actor).
- **(b) Invalid method**: Unsupported method string → `422 UNSUPPORTED_FUNDING_METHOD`.
- **(c) Unavailable method for currency**: Method not available for the wallet's currency → `422 FUNDING_METHOD_UNAVAILABLE`.
- **(d) Method after confirmation**: Attempting to change method on a non-`pending` transaction → `422 TRANSACTION_NOT_MODIFIABLE`.

---

### US-FUND-003 — Enter funding amount (P0)

**As a** Customer,  
**I want to** specify the exact amount I want to deposit,  
**so that** the transaction reflects what I intend to pay.

**Acceptance Scenarios:**

- **(a) Valid amount**: Amount is a positive integer in minor units, within configured limits → accepted as part of initiation or update.
- **(b) Zero or negative**: Amount ≤ 0 → `422 INVALID_AMOUNT`.
- **(c) Non-integer**: Decimal or string amount → `422 INVALID_AMOUNT`.
- **(d) Limit breach**: Amount below minimum or above maximum configured for the method and KYC tier → `422 AMOUNT_OUTSIDE_LIMITS` with `min` and `max` in the error body.

---

### US-FUND-004 — Validate funding limits (P0)

**As the** Platform System,  
**I want to** enforce configurable per-method, per-KYC-tier minimum, maximum, and daily limits before any payment is submitted,  
**so that** regulatory and business funding limits are respected at every funding attempt.

**Acceptance Scenarios:**

- **(a) Within limits**: Amount within min/max for method + tier → validation passes; transaction proceeds.
- **(b) Below minimum**: Amount < configured minimum → `422 AMOUNT_BELOW_MINIMUM`.
- **(c) Above maximum**: Amount > configured maximum → `422 AMOUNT_ABOVE_MAXIMUM`.
- **(d) Daily limit exceeded**: Customer's accumulated successful funding today would exceed the daily limit → `422 DAILY_LIMIT_EXCEEDED` with `remaining` amount in error body.
- **(e) Limits configurable**: Limit values are read from `platform.configuration_values` (keys: `funding.limit.{method}.{tier}.min`, `funding.limit.{method}.{tier}.max`, `funding.limit.{method}.{tier}.daily`). Changing config values takes effect for new transactions immediately.

---

### US-FUND-005 — Calculate funding fee (P0)

**As a** Customer,  
**I want to** see the fee for my selected method and amount before I confirm,  
**so that** I know my total cost and the net amount that will be credited.

**Acceptance Scenarios:**

- **(a) Fee calculation**: System reads the fee rate for the selected method and currency from `platform.configuration_values` (key: `funding.fee.{method}.{currency_code}`). Fee = `round(amount × rate / 10000)` in minor units (rate stored as basis points). Net credited = `amount − fee`.
- **(b) Zero fee**: If no fee config key exists or rate = 0, fee = 0 and net = amount.
- **(c) Fee presented before confirmation**: The fee and net amount are included in the response for the initiation/quote endpoint so the customer sees them before confirming.
- **(d) Fee rate change between quote and confirmation**: Fee is re-calculated at confirmation time using the current config value. If the recalculated fee differs from what was quoted, the confirmation endpoint returns `422 FEE_CHANGED` with the updated amounts.

---

### US-FUND-006 — Confirm funding (P0)

**As a** Customer,  
**I want to** explicitly confirm the funding details (amount, fee, net, method) before money moves,  
**so that** I cannot be charged without a deliberate approval action.

**Acceptance Scenarios:**

- **(a) Successful confirmation**: Customer confirms a `pending` transaction with correct `quoted_fee` → transaction transitions to `processing` (non-bank methods) or `awaiting_match` (bank transfer); `201` returned.
- **(b) Fee mismatch on confirm**: `quoted_fee` in the confirmation request differs from the current calculated fee → `422 FEE_CHANGED`; transaction remains `pending`.
- **(c) Duplicate confirmation**: Same `Idempotency-Key` replayed → `200` with existing transaction state; no double-submission.
- **(d) Non-pending transaction**: Attempting to confirm a transaction that is not `pending` → `422 TRANSACTION_NOT_MODIFIABLE`.
- **(e) Authorization**: Only the customer who owns the transaction may confirm it.

---

### US-FUND-007 — Process funding payment (P0)

**As the** Platform System,  
**I want to** submit the confirmed funding request to the selected payment provider and manage the transition to a terminal state on callback,  
**so that** the provider processes the payment and the wallet is credited exactly when funds arrive.

**Acceptance Scenarios:**

- **(a) Submission to provider**: After confirmation, the system calls the `FundingProviderAdapter::initiate()` for the selected method; the adapter returns a `provider_reference_id`; the transaction is stored with that reference and status `processing`.
- **(b) Provider unavailable**: Provider API returns a connectivity error → transaction remains `processing`; a retry job is enqueued; error is logged and alerted. If retries are exhausted or the `funding.processing.timeout_minutes` TTL is reached, `funding:expire-stale` transitions the transaction to `failed` and emits `Funding.Transaction.Expired`.
- **(c) Provider rejection at initiation**: Provider synchronously rejects the request (e.g., invalid credentials, account blocked) → transaction transitions to `failed`; `Funding.Transaction.Failed` event emitted.
- **(d) Step-up threshold**: For amounts ≥ `auth.step_up_threshold` BWP (configurable), the customer must have completed step-up authentication before confirmation is accepted; otherwise `403 STEP_UP_REQUIRED`.

---

### US-FUND-008 — Credit wallet after confirmed payment (P0)

**As the** Platform System,  
**I want to** credit the customer's wallet pocket via the ledger upon receiving a confirmed success callback from the provider,  
**so that** the customer's available balance increases immediately and accurately when their payment is confirmed.

**Acceptance Scenarios:**

- **(a) Successful credit**: Provider sends a success callback; the system posts a balanced credit journal via `LedgerModuleContract::postEntry()` (debit: platform float account; credit: customer wallet pocket); transaction transitions to `completed`; `Funding.Transaction.Completed` event emitted.
- **(b) Ledger post failure**: `postEntry()` throws an exception → callback processing fails; the transaction remains in its prior state; the error is surfaced to the retry job; no partial state is persisted.
- **(c) Receipt generated on credit**: A `FundingReceipt` record is persisted with the completed transaction's details (reference, amount, fee, net, timestamp, provider reference).
- **(d) Notification**: `Funding.Transaction.Completed` event is consumed downstream (by notification service) to notify the customer.

---

### US-FUND-009 — Handle failed funding (P0)

**As a** Customer,  
**I want to** be informed when my funding attempt fails,  
**so that** I know my wallet was not credited and I can try again or use a different method.

**Acceptance Scenarios:**

- **(a) Failure callback**: Provider sends a failure callback → transaction transitions to `failed`; `Funding.Transaction.Failed` event emitted; no ledger entry posted.
- **(b) Wallet unchanged**: `LedgerModuleContract::getBalance()` on the customer's pocket returns the same value as before the failed transaction.
- **(c) Cancellation by customer**: Customer cancels a `pending` transaction via `POST /v1/funding/transactions/{id}/cancel` → transaction transitions to `cancelled`; `Funding.Transaction.Cancelled` event emitted.
- **(d) Cancellation from `awaiting_match`**: Customer may cancel a bank-transfer transaction in `awaiting_match` status (no payment has been made yet); cancellation invalidates the `bank_reference_code`; `Funding.Transaction.Cancelled` is emitted. This prevents daily-limit lock-up for unredeemed bank references.
- **(e) Cancellation gate**: Cancellation is only permitted on `pending` and `awaiting_match` transactions; attempting to cancel a `processing` or `completed` transaction → `422 TRANSACTION_NOT_CANCELLABLE`.

---

### US-FUND-010 — Handle duplicate provider callback (P0)

**As the** Platform System,  
**I want to** detect and discard duplicate provider callbacks for the same funding transaction,  
**so that** a provider retransmission never results in a double credit to the customer's wallet.

**Acceptance Scenarios:**

- **(a) First success callback processed**: System processes the callback, posts the ledger credit, transitions the transaction to `completed`.
- **(b) Duplicate success callback**: Same `provider_reference_id` arrives again → system detects it via `IdempotencyService` (keyed on `provider_reference_id`); returns HTTP `200` to the provider; no second ledger entry posted; no second event emitted.
- **(c) Duplicate failure callback**: Same failure callback received twice → treated idempotently; no state change on second occurrence.
- **(d) Concurrent duplicate callbacks**: Two identical callbacks arrive simultaneously → exactly one is processed; race is resolved by the `IdempotencyService` UNIQUE constraint at the DB level.

---

### US-FUND-011 — Generate funding reference (P1)

**As a** Customer choosing bank transfer,  
**I want to** receive a unique funding reference code with my banking instructions,  
**so that** my incoming bank payment can be automatically matched to my funding request.

**Acceptance Scenarios:**

- **(a) Reference generated at confirmation**: When a bank-transfer transaction is confirmed, the system generates a unique, human-readable alphanumeric reference (e.g., `OLK-YYYYMMDD-XXXXXXXX`); the reference is persisted on the `FundingTransaction` and returned in the response.
- **(b) Reference uniqueness**: The reference is unique across all open and recent funding transactions.
- **(c) Reference expiry**: The reference is valid for a configurable number of days (key: `funding.bank_reference.expiry_days`); expired references are not matched.
- **(d) Banking instructions**: The response includes the bank account name, account number, branch code, and the reference code for the customer to use in their payment.

---

### US-FUND-012 — Match incoming bank payment (P1)

**As the** Platform System,  
**I want to** match an incoming bank payment notification to an open funding transaction by reference code, amount, and currency,  
**so that** bank-funded wallets are credited automatically without manual intervention.

**Acceptance Scenarios:**

- **(a) Exact match**: Incoming payment has a reference matching an open `awaiting_match` transaction, the same currency and the same amount → match confirmed; triggers reconciliation.
- **(b) Reference not found**: Reference code not found in open transactions → payment stored as an `UnmatchedDeposit`.
- **(c) Amount mismatch**: Reference found but amount differs → payment stored as an `UnmatchedDeposit`; the associated transaction is NOT credited.
- **(d) Expired reference**: Reference exists but has passed its expiry → stored as `UnmatchedDeposit`; transaction transitions to `failed`.
- **(e) Idempotent matching**: Same bank payment notification received twice (same bank transaction ID) → processed once.

---

### US-FUND-013 — Auto-reconcile bank deposit (P1)

**As the** Platform System,  
**I want to** automatically complete a funding transaction when a matching bank deposit is confirmed,  
**so that** the customer's wallet is credited end-to-end without requiring manual processing for matched payments.

**Acceptance Scenarios:**

- **(a) Auto-credit on match**: After a successful match (US-FUND-012), the system immediately posts the credit journal via `LedgerModuleContract::postEntry()` and transitions the transaction to `completed`.
- **(b) Same double-entry structure**: The credit journal for bank transfer funding has the same structure as a provider callback credit: debit platform float account; credit customer wallet pocket.
- **(c) Reconciliation record**: A `BankReconciliation` record is created linking the bank payment notification ID, the `FundingTransaction` ID, the matched amount, and the reconciliation timestamp.
- **(d) Outbox event**: `Funding.BankDeposit.Reconciled` event is emitted.

---

### US-FUND-014 — Handle unmatched deposit (P1)

**As an** Admin,  
**I want to** be alerted when an incoming bank deposit cannot be matched to any open funding transaction,  
**so that** I can investigate and manually credit the correct customer or return the funds.

**Acceptance Scenarios:**

- **(a) Unmatched stored**: Unmatched deposit is persisted in the `unmatched_deposits` table with all available payment details.
- **(b) Event emitted**: `Funding.UnmatchedDeposit.Received` event is emitted immediately; no wallet is credited.
- **(c) Admin review endpoint**: Admin with `funding.unmatched.review` permission can query and update unmatched deposits via `GET /v1/funding/admin/unmatched-deposits` and `PATCH /v1/funding/admin/unmatched-deposits/{id}`.
- **(d) Manual resolution**: Admin can mark an unmatched deposit as resolved (credits a specific customer) or returned (funds returned to sender).
- **(e) No automatic crediting**: Unmatched deposits are never automatically credited; all credits require explicit matching or admin action.

---

### US-FUND-015 — Agent cash-in customer wallet (P0)

**As an** Agent,  
**I want to** deposit cash on behalf of a customer to their wallet,  
**so that** customers without digital payment access can still fund their wallets through me.

**Acceptance Scenarios:**

- **(a) Happy path**: Agent submits `customer_identifier` (phone or customer ID), `amount`, `currency_code`, and `Idempotency-Key` → system verifies customer eligibility → agent confirms with transaction PIN → system posts double-entry → receipt returned.
- **(b) Customer not found**: Unknown `customer_identifier` → `404 CUSTOMER_NOT_FOUND`.
- **(c) Missing idempotency key**: `400 MISSING_IDEMPOTENCY_KEY`.
- **(d) Idempotent replay**: Same `Idempotency-Key` → `200` with original receipt; no double posting.
- **(e) Authorization**: Agent must have both `funding.initiate` and `funding.agent-cashin` permissions.
- **(f) Audit**: Recorded in `platform.audit_events` with action `funding.agent-cashin.completed`.

---

### US-FUND-016 — Verify customer transaction (P0)

**As the** Platform System,  
**I want to** verify the customer's KYC status, account standing, and funding eligibility before the agent proceeds,  
**so that** only eligible customers receive cash-in deposits.

**Acceptance Scenarios:**

- **(a) Eligible customer**: Customer has sufficient KYC tier for agent cash-in and their wallet is active → verification passes; agent proceeds.
- **(b) KYC insufficient**: Customer's tier is below the minimum required for agent cash-in → `422 KYC_INSUFFICIENT`.
- **(c) Wallet suspended**: Customer's target wallet is suspended → `422 WALLET_SUSPENDED`.
- **(d) Limit breach**: Cash-in amount exceeds customer's remaining daily or per-transaction limit → `422 AMOUNT_OUTSIDE_LIMITS`.
- **(e) Customer lookup response**: Verification response includes masked customer name, wallet currency, and account status (never PII beyond what is needed for agent to confirm identity).

---

### US-FUND-017 — Debit agent float (P0)

**As the** Platform System,  
**I want to** debit the agent's float ledger account as part of a cash-in operation,  
**so that** the agent's float balance is accurately reduced by the cash they hand over to the customer.

**Acceptance Scenarios:**

- **(a) Float debited**: Agent float account ledger balance decreases by `amount` after successful posting.
- **(b) Insufficient float**: Agent's float balance is less than `amount` → `422 INSUFFICIENT_FLOAT`; no ledger entry posted.
- **(c) Balance check atomic**: Float balance check and posting MUST be performed atomically (select-for-update on the ledger account, or rely on the ledger service's optimistic concurrency guard).

---

### US-FUND-018 — Credit customer wallet (P0)

**As the** Platform System,  
**I want to** credit the customer's wallet pocket atomically with the agent float debit,  
**so that** the double-entry journal balances and the customer's available balance increases by exactly the cash-in amount.

**Acceptance Scenarios:**

- **(a) Atomic double-entry**: Agent float debit and customer pocket credit are posted in the same balanced journal via `LedgerModuleContract::postEntry()`; they succeed or fail together.
- **(b) No partial credits**: If the ledger posting fails after the debit side but before the credit side, the entire transaction rolls back; neither balance changes.
- **(c) Journal structure**: Debit line: agent float ledger account; Credit line: customer wallet pocket ledger account; both lines in the same journal with `entry_type = debit` (matching debit/credit convention in E07).
- **(d) Balance reflects credit**: `LedgerModuleContract::getBalance(customer_pocket_reference)` immediately reflects the credited amount after a successful cash-in.

---

### US-FUND-019 — Generate customer receipt (P0)

**As an** Agent and Customer,  
**I want** a receipt confirming the cash-in details,  
**so that** there is a verifiable, tamper-evident record for both parties.

**Acceptance Scenarios:**

- **(a) Receipt persisted**: A `FundingReceipt` record is written with: receipt reference (UUID v7), `funding_transaction_id`, `amount`, `fee` (0 for cash-in), `net_amount`, `currency_code`, `agent_id`, `customer_id`, `wallet_id`, `ledger_journal_id`, `created_at`.
- **(b) Receipt returned**: The receipt reference and all receipt fields are included in the cash-in response.
- **(c) Receipt idempotent**: If the cash-in is replayed (same idempotency key), the same receipt reference is returned; no duplicate receipt record created.
- **(d) Receipt queryable**: Receipt is queryable by the customer and agent via `GET /v1/funding/receipts/{receipt_reference}`.

---

## 7. Functional Requirements

### 7.1 FundingTransaction Entity

**FR-E08-001** — A `FundingTransaction` SHALL have the following persisted attributes:

| Attribute                  | Type                  | Description                                                                  |
|----------------------------|-----------------------|------------------------------------------------------------------------------|
| `id`                       | UUID v7               | Primary key                                                                  |
| `wallet_id`                | UUID v7               | Target wallet (FK to `wallet.wallets`)                                       |
| `pocket_currency_code`     | CHAR(3)               | Currency of the target pocket (e.g., BWP)                                    |
| `customer_id`              | UUID v7               | Owning customer (FK to `platform.users`)                                     |
| `agent_id`                 | UUID v7, nullable     | Initiating agent for cash-in; NULL for digital methods                        |
| `funding_method`           | ENUM                  | `mobile_money`, `bank_transfer`, `card`, `agent`                             |
| `status`                   | ENUM                  | See §7.2                                                                      |
| `amount`                   | BIGINT                | Gross funding amount in minor currency units                                  |
| `fee_amount`               | BIGINT                | Fee charged in minor units (default 0; TBD-E08-002)                          |
| `net_amount`               | BIGINT                | `amount − fee_amount`; this is what is credited to the pocket                |
| `idempotency_key`          | VARCHAR(128), UNIQUE  | Client-provided idempotency key for the initiation request                   |
| `provider_reference_id`    | VARCHAR(255), nullable | External provider's transaction/reference identifier                         |
| `bank_reference_code`      | VARCHAR(64), nullable  | Human-readable reference for bank transfer matching (US-FUND-011)            |
| `bank_reference_expires_at`| TIMESTAMPTZ, nullable | Expiry of the bank reference                                                  |
| `ledger_journal_id`        | UUID v7, nullable     | The E07 journal ID posted when status transitions to `completed`             |
| `transaction_reference`    | UUID v7               | Cross-module stable reference (used in E11 transaction history)              |
| `initiated_by`             | UUID v7               | Actor who initiated the transaction                                           |
| `completed_at`             | TIMESTAMPTZ, nullable | Timestamp of `completed` transition                                           |
| `failed_at`                | TIMESTAMPTZ, nullable | Timestamp of `failed` or `cancelled` transition                              |
| `created_at`               | TIMESTAMPTZ           | Record creation timestamp                                                     |
| `updated_at`               | TIMESTAMPTZ           | Last modification timestamp                                                   |

**FR-E08-002** — `funding_method` ENUM values: `mobile_money`, `bank_transfer`, `card`, `agent`.

---

### 7.2 FundingTransaction Status State Machine

**FR-E08-003** — `FundingTransaction.status` ENUM values and transitions:

| Status            | Description                                                               |
|-------------------|---------------------------------------------------------------------------|
| `pending`         | Created; not yet confirmed by customer                                    |
| `processing`      | Confirmed; submitted to non-bank payment provider; awaiting callback      |
| `awaiting_match`  | Confirmed; bank reference generated; awaiting incoming bank payment       |
| `completed`       | Provider/bank confirmed receipt; wallet pocket credited via ledger        |
| `failed`          | Provider declined, timed out, or reference expired; no ledger entry       |
| `cancelled`       | Customer cancelled before `processing`/`awaiting_match`; no ledger entry  |

**FR-E08-004** — Permitted transitions:

- `pending` → `processing` (customer confirms; non-bank method)
- `pending` → `awaiting_match` (customer confirms; bank transfer method)
- `pending` → `cancelled` (customer cancels)
- `processing` → `completed` (success callback)
- `processing` → `failed` (failure callback or system timeout)
- `awaiting_match` → `completed` (bank payment matched and reconciled)
- `awaiting_match` → `failed` (reference expired without matching deposit, or expiry command runs)
- `awaiting_match` → `cancelled` (customer self-cancels before making the bank payment)
- `processing` → `failed` (provider timeout — expiry command transitions after configurable TTL)

All other transitions are prohibited and MUST return `422 TRANSACTION_NOT_MODIFIABLE`.

---

### 7.3 Funding Initiation

**FR-E08-005** — The funding initiation endpoint (`POST /v1/funding/transactions`) creates a `FundingTransaction` in `pending` status. Required inputs: `wallet_id`, `currency_code`, `amount`, `funding_method`. Optional: `idempotency_key` in header (required for mutations). The response includes the transaction ID, calculated `fee_amount`, `net_amount`, and current funding limits for the selected method and tier.

**FR-E08-006** — The customer must own the target wallet. If `wallet_id` belongs to another customer and the caller is not an authorized admin, the request MUST return `404 WALLET_NOT_FOUND` (no enumeration).

**FR-E08-007** — On initiation, the system calculates `fee_amount` using the configured basis-point rate for the method and currency (FR-E08-020). The calculated fee is returned with the pending transaction and is re-validated at confirmation time.

**FR-E08-008** — A new initiation with an identical `idempotency_key` returns `200` with the previously created transaction; no new transaction is persisted.

---

### 7.4 Funding Limit Enforcement

**FR-E08-009** — Amount limits are read from `platform.configuration_values` at initiation and confirmation time. The config key format is:
- Per-transaction minimum: `funding.limit.{method}.{kyc_tier}.min_amount`
- Per-transaction maximum: `funding.limit.{method}.{kyc_tier}.max_amount`
- Daily maximum: `funding.limit.{method}.{kyc_tier}.daily_max`

**FR-E08-010** — The customer's KYC tier is resolved from the E05 KYC module (via `KycModuleContract` or the user record). Limits for an unresolved or unknown tier default to the most restrictive tier's limits.

**FR-E08-011** — Daily limit is calculated as the sum of `gross_amount` across all non-terminal `FundingTransaction` records for the customer in the current calendar day (UTC) with status in (`pending`, `processing`, `awaiting_match`, `completed`), excluding `agent` method from daily limit counts if the config key `funding.limit.agent.{tier}.daily_max` is absent. In-flight transactions (pending/processing/awaiting_match) are included to prevent the CC-E08-003 race condition where two concurrent initiations both pass the limit check before either is persisted.

---

### 7.5 Fee Calculation

**FR-E08-012** — Fee formula: `fee_amount = round(amount × rate_bps / 10000)` where `rate_bps` is the fee rate in basis points from config key `funding.fee.{method}.{currency_code}`. If the key is absent, `rate_bps = 0`.

**FR-E08-013** — `net_amount = amount − fee_amount`. The `net_amount` is the amount credited to the customer's wallet pocket.

**FR-E08-014** — Fee rates are configurable per method per currency by Admin via the Platform Configuration capability. No fee rate is hard-coded in domain logic (TBD-E08-002 governs actual rate values).

---

### 7.6 Confirmation

**FR-E08-015** — The confirmation endpoint (`POST /v1/funding/transactions/{id}/confirm`) requires `quoted_fee_amount` in the request body. The system recalculates the fee at confirmation time. If `quoted_fee_amount ≠ recalculated fee_amount`, the request is rejected with `402 FEE_CHANGED` and the current `fee_amount` and `net_amount` are returned. HTTP 402 is used (rather than 422) because the caller must re-accept the updated fee before proceeding — this is a payment-term change, not a validation error. The `FundingTransaction` remains in `pending` status.

**FR-E08-016** — On confirmation, for non-bank methods: the transaction transitions to `processing` and `FundingProviderAdapter::initiate()` is invoked asynchronously (via a queued job).

**FR-E08-017** — On confirmation, for `bank_transfer`: the transaction transitions to `awaiting_match`; a unique `bank_reference_code` is generated and stored; banking instructions are returned to the customer.

**FR-E08-018** — For agent cash-in, the customer's explicit confirmation is not required; the agent's confirmation (with transaction PIN) acts as the confirmation event.

---

### 7.7 Provider Processing

**FR-E08-019** — The `FundingProviderAdapter` interface abstracts all provider-specific logic. E08 defines this interface; specific implementations are TBD-E08-005. The interface MUST expose at minimum: `initiate(FundingTransaction $tx): ProviderResult` and `verifyCallback(array $payload, string $signature): CallbackResult`.

**FR-E08-020** — When `FundingProviderAdapter::initiate()` returns successfully, the `provider_reference_id` is stored on the `FundingTransaction`.

**FR-E08-021** — Provider callbacks are received at `POST /v1/funding/callbacks/{provider}` (public, but signature-verified). The payload is stored as a raw JSON field on `FundingProviderCallback` before processing.

**FR-E08-022** — The callback handler verifies the provider's HMAC/signature before processing. Invalid signature → `400 INVALID_CALLBACK_SIGNATURE`; no state change.

**FR-E08-023** — On a verified success callback: the system checks the callback amount matches `amount` on the `FundingTransaction`. Amount mismatch → transition to `failed`; `Funding.Transaction.AmountMismatch` event emitted; alert raised.

**FR-E08-024** — On a verified success callback with matching amount: a balanced credit journal is posted via `LedgerModuleContract::postEntry()` inside a DB transaction that also writes the outbox event. Structure: debit `platform.float.{currency_code}` account (from `platform.configuration_values` key `ledger.float_account.{currency_code}`, which resolves TBD-E07-001); credit the customer's wallet pocket ledger account reference. Transaction transitions to `completed` only after the journal is persisted.

**FR-E08-025** — Callback processing is idempotent: the `provider_reference_id` is stored in the `platform.idempotency_store` (IdempotencyService) on first successful processing. A duplicate callback with the same key returns `200` immediately without re-processing.

---

### 7.8 Bank Matching & Reconciliation

**FR-E08-026** — Bank reference codes MUST be unique, human-readable, and of the format `OLK-{6-CHAR-ALPHANUM}` (e.g., `OLK-A3B9XZ`). The 6-character suffix is randomly generated from an unambiguous alphabet (`ABCDEFGHJKLMNPQRSTUVWXYZ23456789`, omitting I, O, 1, 0 to avoid transcription errors), with up to 5 retry attempts on collision enforced by a UNIQUE database constraint.

**FR-E08-027** — Bank deposit notification delivery is **push-only**: the bank (or its integration middleware) calls `POST /v1/funding/bank/notifications`; E08 does not poll any bank API. The endpoint is authenticated by a system-to-system API key credential (not a customer or agent token) and validates a request signature before processing. Payload includes: bank transaction ID, amount, currency, reference code (from payment narration/reference field), received_at. E08 defines no polling job; if a specific bank requires polling, that is the responsibility of the provider adapter layer (TBD-E08-001 / E1000).

**FR-E08-028** — Matching logic: the system queries `FundingTransaction` where `bank_reference_code = {reference}` AND `status = awaiting_match` AND `bank_reference_expires_at > NOW()` AND `amount = {incoming_amount}` AND `pocket_currency_code = {incoming_currency}`. Exact match on all four fields required. A cancelled transaction (`status = cancelled`) MUST NOT match even if its reference code appears in the bank payment narration; such a payment is stored as an `UnmatchedDeposit`.

**FR-E08-029** — On successful match: the matched `FundingTransaction` transitions to `completed` via the same credit-posting path as FR-E08-024. A `BankReconciliation` record is persisted linking the bank notification ID to the transaction.

**FR-E08-030** — Unmatched deposits (no matching transaction) are persisted in `funding.unmatched_deposits` with all raw payment details. `Funding.UnmatchedDeposit.Received` event is emitted. No wallet is credited.

---

### 7.9 Agent Cash-In

**FR-E08-050** — Agent cash-in MUST have `fee_amount = 0` enforced at the domain layer regardless of any fee configuration value for the `agent` method. `net_amount = amount` for all agent cash-in transactions. The journal is always a two-line balanced entry; no fee/revenue leg is added. Any future commission structure for agents is the responsibility of E21 and must not be implemented in E08.

**FR-E08-031** — The agent cash-in endpoint (`POST /v1/funding/agent-transactions`) requires: `customer_id` (UUID), `wallet_id` (UUID), `amount`, `currency_code`, `Idempotency-Key`. The agent supplies the explicit customer and wallet identifiers (resolved from the OleKard app customer lookup flow before this call is made); phone-number resolution is out of scope for E08.

**FR-E08-032** — The system verifies that `wallet_id` belongs to `customer_id` and that a pocket for `currency_code` exists on that wallet. If the wallet is not found or the currency does not match → `422 WALLET_NOT_FOUND`.

**FR-E08-033** — Before posting, the system verifies: (a) customer KYC tier permits agent cash-in; (b) customer wallet is active; (c) amount is within the customer's per-transaction and daily limits; (d) agent float account balance ≥ `amount`. Any failure aborts the cash-in with a specific error code.

**FR-E08-034** — Agent float balance check and journal posting MUST be performed within the same `DB::connection('platform')->transaction()` block, preventing a race where two concurrent agent cash-ins drain the float below zero.

**FR-E08-035** — The journal for agent cash-in is: `entry_type = debit`; line 1 (debit): agent float ledger account reference; line 2 (credit): customer wallet pocket ledger account reference. The journal MUST balance (`Σ debits = Σ credits`).

**FR-E08-036** — Agent transaction PIN verification: before the agent cash-in is posted, the caller must supply the agent's `transaction_pin` (hashed and verified server-side). Incorrect PIN → `422 INVALID_PIN`. Step-up applies if the amount ≥ `auth.step_up_threshold` BWP (FR-E08-037).

**FR-E08-037** — Step-up authentication: if the cash-in `amount` converted to BWP is ≥ `auth.step_up_threshold` (default 10 000 BWP), a higher authentication assurance level (step-up MFA) is required per constitution Principle 5. The threshold is configurable; never hard-coded.

**FR-E08-038** — Agent cash-in creates a `FundingReceipt` record (see §7.12) and returns it in the response.

---

### 7.10 Idempotency

**FR-E08-039** — All state-changing endpoints (`POST /v1/funding/transactions`, `/confirm`, `/cancel`, `POST /v1/funding/agent-transactions`) MUST require the `Idempotency-Key` header. Requests without it → `400 MISSING_IDEMPOTENCY_KEY`.

**FR-E08-040** — Idempotency is stored using the platform `IdempotencyService` (key-space: `funding:{idempotency_key}`). Replayed requests return the original response body and appropriate HTTP status (`200` for replays of `201` requests).

**FR-E08-041** — Idempotency key TTL is `funding.idempotency.ttl_seconds` from Platform Configuration (default: 86 400 s = 24 h).

---

### 7.11 Transaction Expiry

**FR-E08-047** — A scheduled Artisan command `funding:expire-stale` MUST be registered and run on a configurable schedule (default: every 5 minutes). It processes two expiry cases in a single pass:

1. **Processing timeout**: `FundingTransaction` records with `status = processing` and `created_at < NOW() − funding.processing.timeout_minutes` (default 60 minutes) are transitioned to `failed` with `failure_reason = processing_timeout`. A `Funding.Transaction.Expired` event is emitted for each.

2. **Bank reference expiry**: `FundingTransaction` records with `status = awaiting_match` and `bank_reference_expires_at < NOW()` are transitioned to `failed` with `failure_reason = bank_reference_expired`. A `Funding.Transaction.Expired` event is emitted for each.

**FR-E08-048** — The expiry command MUST be idempotent: re-running it when no stale records exist is a no-op. Each transition is performed in its own DB transaction; a failure on one record does not block processing of remaining records.

**FR-E08-049** — Config keys governing expiry:
- `funding.processing.timeout_minutes` — minutes before a `processing` transaction is expired (default 60)
- `funding.bank_reference.expiry_days` — days before an `awaiting_match` transaction's bank reference expires (referenced in US-FUND-011)

---

### 7.12 FundingReceipt Entity

**FR-E08-042** — A `FundingReceipt` is written atomically with the `completed` transition. Attributes: `id` (UUID v7), `funding_transaction_id`, `receipt_reference` (UUID v7, human-facing), `amount`, `fee_amount`, `net_amount`, `currency_code`, `funding_method`, `agent_id` (nullable), `customer_id`, `wallet_id`, `ledger_journal_id`, `completed_at`.

**FR-E08-043** — Receipts are queryable by the receipt's owner (customer or agent who initiated) via `GET /v1/funding/receipts/{receipt_reference}`.

---

### 7.13 Domain Events

**FR-E08-044** — The following events are emitted via `OutboxEventPublisher` inside the same DB transaction as the state change:

| Event Name                          | Trigger                                      |
|-------------------------------------|----------------------------------------------|
| `Funding.Transaction.Initiated`     | FundingTransaction created (`pending`)       |
| `Funding.Transaction.Confirmed`     | FundingTransaction confirmed by customer     |
| `Funding.Transaction.Completed`     | Wallet credited; status → `completed`        |
| `Funding.Transaction.Failed`        | Provider declined or timeout                 |
| `Funding.Transaction.Cancelled`     | Customer cancelled                           |
| `Funding.Transaction.AmountMismatch`| Callback amount ≠ transaction amount         |
| `Funding.AgentCashIn.Completed`     | Agent cash-in posted atomically              |
| `Funding.BankDeposit.Reconciled`    | Bank payment matched and wallet credited     |
| `Funding.UnmatchedDeposit.Received` | Incoming bank deposit not matched            |
| `Funding.Transaction.Expired`       | Expiry command transitions `processing` or `awaiting_match` to `failed` |
| `Funding.Transaction.LateCallback`  | Success callback received for a transaction already in `failed` status — anomaly alert, no wallet credit (EC-E08-02) |

**FR-E08-045** — Event payloads MUST NOT contain PII (no customer name, phone, or account numbers). Payloads include: `funding_transaction_id`, `wallet_id`, `currency_code`, `amount`, `status`, `occurred_at`, `correlation_id`.

---

### 7.14 Audit Requirements

**FR-E08-046** — The following actions MUST be recorded in `platform.audit_events`:

| Action                          | Trigger                                        |
|---------------------------------|------------------------------------------------|
| `funding.transaction.initiated` | FundingTransaction created                     |
| `funding.transaction.confirmed` | Customer confirms                              |
| `funding.transaction.completed` | Wallet credited                                |
| `funding.transaction.failed`    | Transaction failed                             |
| `funding.transaction.cancelled` | Transaction cancelled                          |
| `funding.agent-cashin.completed`| Agent cash-in posted                           |
| `funding.unmatched-deposit.received` | Unmatched bank deposit stored            |
| `funding.unmatched-deposit.resolved` | Admin manually resolves unmatched deposit|

---

### 7.15 Observability & Metrics

**FR-E08-051** — E08 MUST emit structured metrics via `MetricsCollectorInterface` (the same interface used in E07), injected into the relevant service classes. The following metrics are required at minimum:

| Metric name                                  | Type      | Labels                              | Emitted when                                  |
|----------------------------------------------|-----------|-------------------------------------|-----------------------------------------------|
| `funding.transactions.initiated`             | Counter   | `method`                            | `FundingTransaction` created (`pending`)      |
| `funding.transactions.completed`             | Counter   | `method`                            | Status transitions to `completed`             |
| `funding.transactions.failed`                | Counter   | `method`, `reason`                  | Status transitions to `failed`                |
| `funding.transactions.cancelled`             | Counter   | `method`                            | Status transitions to `cancelled`             |
| `funding.transactions.expired`               | Counter   | `type` (processing_timeout \| bank_reference_expired) | `funding:expire-stale` transitions a record   |
| `funding.callbacks.processing.duration`      | Histogram | `provider`, `outcome` (success \| failure \| duplicate) | Provider callback fully processed             |
| `funding.agent_cashin.posted`                | Counter   | —                                   | Agent cash-in journal posted                  |
| `funding.bank.deposits.matched`              | Counter   | —                                   | Bank payment successfully matched             |
| `funding.bank.deposits.unmatched`            | Counter   | —                                   | Bank payment stored as unmatched              |

**FR-E08-052** — Metrics are emitted inside the same service method that performs the state change, after the DB transaction commits (or in the same call sequence). Metric emission failure MUST NOT cause the business operation to fail or roll back.

---

## 8. Data Model

### 8.1 Schema

All funding tables reside in the `funding` schema (DB migrations target the `ledger` connection; models use `connection = platform`, `table = funding.{table}`).

### 8.2 Entities

```
funding.funding_transactions
  id                     UUID v7 PK
  wallet_id              UUID FK → wallet.wallets.id
  customer_id            UUID FK → platform.users.id
  agent_id               UUID nullable FK → platform.users.id
  funding_method         VARCHAR(32)  -- mobile_money | bank_transfer | card | agent
  status                 VARCHAR(32)  -- pending | processing | awaiting_match | completed | failed | cancelled
  currency_code          CHAR(3)
  amount                 BIGINT NOT NULL
  fee_amount             BIGINT NOT NULL DEFAULT 0
  net_amount             BIGINT NOT NULL
  idempotency_key        VARCHAR(128) UNIQUE NOT NULL
  provider_reference_id  VARCHAR(255) nullable
  bank_reference_code    VARCHAR(64) nullable UNIQUE
  bank_reference_expires_at TIMESTAMPTZ nullable
  ledger_journal_id      UUID nullable
  transaction_reference  UUID NOT NULL UNIQUE
  initiated_by           UUID NOT NULL
  completed_at           TIMESTAMPTZ nullable
  failed_at              TIMESTAMPTZ nullable
  created_at             TIMESTAMPTZ NOT NULL
  updated_at             TIMESTAMPTZ NOT NULL

funding.funding_provider_callbacks
  id                     UUID v7 PK
  funding_transaction_id UUID FK → funding_transactions.id
  provider               VARCHAR(64)
  provider_reference_id  VARCHAR(255)
  status                 VARCHAR(32)  -- success | failure | unknown
  raw_payload            JSONB
  signature_valid        BOOLEAN
  received_at            TIMESTAMPTZ NOT NULL
  processed_at           TIMESTAMPTZ nullable

funding.funding_receipts
  id                     UUID v7 PK
  receipt_reference      UUID NOT NULL UNIQUE
  funding_transaction_id UUID FK → funding_transactions.id
  amount                 BIGINT NOT NULL
  fee_amount             BIGINT NOT NULL DEFAULT 0
  net_amount             BIGINT NOT NULL
  currency_code          CHAR(3) NOT NULL
  funding_method         VARCHAR(32) NOT NULL
  agent_id               UUID nullable
  customer_id            UUID NOT NULL
  wallet_id              UUID NOT NULL
  ledger_journal_id      UUID NOT NULL
  completed_at           TIMESTAMPTZ NOT NULL

funding.bank_reconciliations
  id                     UUID v7 PK
  funding_transaction_id UUID FK → funding_transactions.id
  bank_notification_id   VARCHAR(255) NOT NULL UNIQUE
  matched_amount         BIGINT NOT NULL
  currency_code          CHAR(3) NOT NULL
  bank_reference_code    VARCHAR(64) NOT NULL
  reconciled_at          TIMESTAMPTZ NOT NULL

funding.unmatched_deposits
  id                     UUID v7 PK
  bank_notification_id   VARCHAR(255) NOT NULL UNIQUE
  amount                 BIGINT NOT NULL
  currency_code          CHAR(3) NOT NULL
  narration              TEXT nullable
  received_at            TIMESTAMPTZ NOT NULL
  resolution_status      VARCHAR(32) DEFAULT 'pending'  -- pending | credited | returned
  resolved_by            UUID nullable
  resolved_at            TIMESTAMPTZ nullable
  raw_payload            JSONB
```

### 8.3 Key Indexes

- `funding_transactions(idempotency_key)` — UNIQUE (race guard)
- `funding_transactions(bank_reference_code)` — UNIQUE (match lookup)
- `funding_transactions(customer_id, status, created_at)` — daily limit query
- `funding_transactions(provider_reference_id)` — callback lookup
- `funding_receipts(receipt_reference)` — UNIQUE
- `bank_reconciliations(bank_notification_id)` — UNIQUE (idempotent match)
- `unmatched_deposits(bank_notification_id)` — UNIQUE

---

## 9. Funding Flows

### 9.1 Digital Funding Flow (mobile money / card)

```
Customer → POST /v1/funding/transactions         → FundingTransaction: pending
        → POST /v1/funding/transactions/{id}/confirm
              ↳ FeeValidation
              ↳ LimitCheck
              ↳ FundingProviderAdapter::initiate()
              ↳ FundingTransaction: processing
Provider → POST /v1/funding/callbacks/{provider}
              ↳ SignatureVerification
              ↳ AmountVerification
              ↳ IdempotencyCheck
              ↳ LedgerModuleContract::postEntry() (debit float, credit pocket)
              ↳ FundingTransaction: completed
              ↳ FundingReceipt created
              ↳ Funding.Transaction.Completed emitted
```

### 9.2 Bank Transfer Funding Flow

```
Customer → POST /v1/funding/transactions (method=bank_transfer)
              ↳ FundingTransaction: pending
        → POST /v1/funding/transactions/{id}/confirm
              ↳ FundingTransaction: awaiting_match
              ↳ bank_reference_code generated
              ↳ Banking instructions returned
(Customer pays via bank)
Bank System → POST /v1/funding/bank/notifications
              ↳ MatchingService::match()
              ↳ On match: LedgerModuleContract::postEntry()
              ↳ FundingTransaction: completed
              ↳ BankReconciliation created
              ↳ Funding.BankDeposit.Reconciled emitted
              ↳ On no match: UnmatchedDeposit stored
              ↳ Funding.UnmatchedDeposit.Received emitted
```

### 9.3 Agent Cash-In Flow

```
Agent → POST /v1/funding/agent-transactions
              ↳ CustomerVerificationService::verify()
              ↳ AgentFloatCheck (balance ≥ amount)
              ↳ TransactionPINVerification
              ↳ StepUpCheck (if amount ≥ threshold)
              ↳ LedgerModuleContract::postEntry()
                  debit: agent float account
                  credit: customer pocket account
              ↳ FundingTransaction: completed (directly)
              ↳ FundingReceipt created
              ↳ Funding.AgentCashIn.Completed emitted
```

---

## 10. Security Requirements

**SR-E08-001** — Provider callback endpoints (`POST /v1/funding/callbacks/{provider}`) MUST validate the provider's HMAC or signature before executing any business logic. Invalid signature MUST return `400` and MUST NOT transition any transaction state.

**SR-E08-002** — Provider HMAC secrets and API credentials MUST be stored in the externally managed secrets store; they MUST NOT appear in environment files committed to the repository.

**SR-E08-003** — Funding limit enforcement (per-transaction minimum/maximum and daily maximum) MUST be enforced server-side. Client-provided amounts are untrusted inputs.

**SR-E08-004** — IDOR protection: a customer may only read/cancel their own `FundingTransaction`. A request for another customer's transaction MUST return `404 WALLET_NOT_FOUND` (not `403`) to prevent enumeration.

**SR-E08-005** — Agent transaction PINs MUST be verified as a bcrypt or Argon2 hash; the plaintext PIN MUST NOT be logged or persisted.

**SR-E08-006** — Step-up authentication is required for agent cash-in (and digital funding confirmation) where the amount converted to BWP meets or exceeds `auth.step_up_threshold`. The threshold MUST NOT be hard-coded; it is read from Platform Configuration at runtime.

**SR-E08-007** — The bank notification endpoint (`POST /v1/funding/bank/notifications`) MUST be authenticated with a system-to-system credential (e.g., API key or mutual TLS). It MUST NOT be accessible with a customer or agent token.

**SR-E08-008** — PII (customer name, phone, account number) MUST NOT appear in domain event payloads, queue messages, or application logs. Structured logs may include `funding_transaction_id`, `wallet_id`, and status.

**SR-E08-009** — The `FundingProviderAdapter::verifyCallback()` MUST be called before any state mutation on a provider callback. No callback is processed on signature verification failure.

**SR-E08-010** — All admin endpoints (`/v1/funding/admin/*`) MUST record actions in `platform.audit_events`. Each endpoint requires at minimum the granular permission listed in §12 (e.g., `funding.unmatched.review` for unmatched-deposit endpoints); `funding.admin` grants full umbrella access across all funding admin endpoints.

---

## 11. Concurrency Requirements

**CC-E08-001** — Concurrent duplicate provider callbacks for the same `provider_reference_id` MUST result in exactly one wallet credit. The `IdempotencyService` UNIQUE constraint on the callback key is the first guard; the `FundingTransaction.status` state machine is the second.

**CC-E08-002** — Agent cash-in: the agent float balance check and ledger post MUST occur within a single DB transaction with a row-level lock (`SELECT ... FOR UPDATE`) on the agent's `ledger_account` row to prevent concurrent cash-ins from over-drafting the float.

**CC-E08-003** — Customer daily limit enforcement: the daily limit query (`SUM(net_amount) WHERE status = completed AND date = today`) MUST be executed inside the same DB transaction as the new transaction insertion, preventing a race where two concurrent initiations both pass the limit check before either is persisted.

**CC-E08-004** — Bank payment matching: the match query and the subsequent `completed` transition MUST be executed within a single DB transaction, with the matched `FundingTransaction` row locked (`SELECT ... FOR UPDATE`) to prevent a concurrent bank notification from matching the same transaction twice.

---

## 12. API Endpoints

| Method | Path                                          | Auth           | Permission               | Summary                                     |
|--------|-----------------------------------------------|----------------|--------------------------|---------------------------------------------|
| POST   | `/v1/funding/transactions`                    | Bearer token   | `funding.initiate`       | Create a funding transaction (US-FUND-001)  |
| GET    | `/v1/funding/transactions/{id}`               | Bearer token   | `funding.read`           | Get transaction status                      |
| POST   | `/v1/funding/transactions/{id}/confirm`       | Bearer token   | `funding.initiate`       | Confirm and submit to provider (US-FUND-006)|
| POST   | `/v1/funding/transactions/{id}/cancel`        | Bearer token   | `funding.initiate`       | Cancel a pending transaction (US-FUND-009)  |
| GET    | `/v1/funding/transactions`                    | Bearer token   | `funding.read`           | List customer's transactions (paginated)    |
| POST   | `/v1/funding/callbacks/{provider}`            | HMAC / none    | System only              | Provider callback (US-FUND-007 / 008 / 009) |
| POST   | `/v1/funding/bank/notifications`              | System API key | System only              | Bank payment notification (US-FUND-012)     |
| POST   | `/v1/funding/agent-transactions`              | Bearer token   | `funding.agent-cashin`   | Agent cash-in (US-FUND-015–019)             |
| GET    | `/v1/funding/agent-transactions/{id}`         | Bearer token   | `funding.agent-cashin`   | Get agent transaction details               |
| GET    | `/v1/funding/transactions/{id}/receipt`       | Bearer token   | `funding.read`           | Retrieve receipt by transaction ID          |
| GET    | `/v1/funding/receipts/{receipt_reference}`    | Bearer token   | `funding.read`           | Retrieve receipt by receipt_reference (FR-E08-043) |
| GET    | `/v1/funding/admin/unmatched-deposits`        | Bearer token   | `funding.unmatched.review` | List unmatched deposits (US-FUND-014)     |
| PATCH  | `/v1/funding/admin/unmatched-deposits/{id}`   | Bearer token   | `funding.unmatched.review` | Resolve unmatched deposit                 |

All endpoints use:
- Response envelope: `{ "data": {...}, "meta": {...} }` (mutations) or `{ "error": { "code": "...", "message": "..." } }` (errors)
- UTC ISO-8601 timestamps
- UUID v7 identifiers
- `Idempotency-Key` header required for all `POST` mutations except system callbacks

---

## 13. Permissions

| Permission                   | Granted To                         | Description                                      |
|------------------------------|------------------------------------|--------------------------------------------------|
| `funding.initiate`           | Customer, Customer Service Agent   | Create and confirm funding transactions          |
| `funding.read`               | Customer, Agent, Admin             | Read own or assigned transactions/receipts       |
| `funding.agent-cashin`       | Agent                              | Perform agent cash-in operations                 |
| `funding.unmatched.review`   | Admin, Finance Operations          | View and resolve unmatched bank deposits         |
| `funding.admin`              | Admin                              | Full admin access to all funding records         |

---

## 14. Integration Boundaries

| Upstream Module           | Contract Used                              | E08 Usage                                              |
|---------------------------|--------------------------------------------|--------------------------------------------------------|
| E07 Wallet Ledger         | `LedgerModuleContract`                     | `postEntry()` to credit wallet pocket; `getBalance()` for float check |
| E06 Wallet Management     | `WalletModuleContract` (to be confirmed)   | Resolve wallet pocket ledger account reference         |
| E05 KYC                   | `KycModuleContract` (or user KYC attribute)| Resolve customer KYC tier for limit enforcement        |
| E04 Authorization         | `AuthorizationModuleContract` / Gates      | `funding.*` permission checks                          |
| E03 Device & Session      | Session middleware                         | Step-up authentication signal                          |
| E01 Authentication        | JWT / Auth middleware                      | Bearer token validation                                |
| Platform Foundation       | `IdempotencyService`                       | Idempotency key storage for callbacks and mutations     |
| Platform Foundation       | `OutboxEventPublisher`                     | Reliable event emission inside DB transactions         |
| Platform Foundation       | `AuditService`                             | `platform.audit_events` writes                         |
| Platform Configuration    | Config service / `configuration_values`    | Fee rates, funding limits, step-up threshold           |
| E1000 Provider Adapters   | `FundingProviderAdapter` (defined in E08)  | Provider-specific initiation and callback handling     |

**Advance Gate constraints** (from E07 DoD):
- **TBD-E07-001** (system float account ledger reference keys in `platform.configuration_values`) MUST be resolved before E08 implementation of credit posting begins.
- **TBD-E07-002** (customer wallet pocket `account_type` value) MUST be confirmed before E08 can register wallet pockets as ledger accounts.

---

## 15. Success Criteria

**SC-E08-001** — A customer completes the digital funding flow end-to-end: from `POST /v1/funding/transactions` through provider callback to `completed`; `LedgerModuleContract::getBalance()` on the wallet pocket reflects the credited `net_amount`.

**SC-E08-002** — An agent completes a cash-in in a single request; the agent's float balance decreases by `amount` and the customer's pocket balance increases by `amount` (fee = 0 for agent cash-in); a receipt with all required fields is returned.

**SC-E08-003** — A duplicate provider success callback (identical `provider_reference_id`) received within the idempotency TTL results in `200` on the second call and no second ledger posting; `getBalance()` confirms the wallet was credited exactly once.

**SC-E08-004** — Concurrent agent cash-in requests to the same agent float account (same agent, different customers) do not over-draft the float; only requests where float ≥ amount at the point of the DB lock succeed.

**SC-E08-005** — A failed funding attempt (provider decline) leaves the customer's wallet balance unchanged; no `FundingReceipt` is created; `Funding.Transaction.Failed` event is emitted.

**SC-E08-006** — A bank transfer funding is matched end-to-end: reference generated → customer pays → bank notification received → matched → wallet credited → `BankReconciliation` record present.

**SC-E08-007** — An unmatched bank deposit triggers `Funding.UnmatchedDeposit.Received` event, is stored in `unmatched_deposits`, and no wallet is credited.

**SC-E08-008** — All funding transactions (initiation, completion, failure, cancellation, agent cash-in) produce corresponding entries in `platform.audit_events`.

**SC-E08-009** — Funding limit enforcement: an amount above the configured maximum for the customer's KYC tier and method returns `422 AMOUNT_ABOVE_MAXIMUM`; amount below minimum returns `422 AMOUNT_BELOW_MINIMUM`; daily limit exceeded returns `422 DAILY_LIMIT_EXCEEDED`.

**SC-E08-010** — A pending transaction can be cancelled; the status transitions to `cancelled`; the wallet balance is unchanged; `Funding.Transaction.Cancelled` event is emitted.

**SC-E08-011** — The fee calculation is consistent: the fee returned at initiation matches the fee calculated at confirmation when the config rate has not changed; if the rate changes between quote and confirmation, `422 FEE_CHANGED` is returned with the updated fee.

**SC-E08-012** — Provider HMAC verification: a callback with an invalid or missing signature returns `400 INVALID_CALLBACK_SIGNATURE`; no state change occurs; the event is logged for security alerting.

---

## 16. Edge Cases

**EC-E08-01** — Provider sends a success callback after the transaction has already been `completed` (second delivery): the callback is acknowledged with `200`; the wallet is NOT credited again; the `FundingProviderCallback` record captures the duplicate with `processed_at` already set.

**EC-E08-02** — Provider sends a success callback after the transaction status is `failed` (provider retried a previously declined transaction and succeeded on retry): the system treats this as an anomaly — the transaction is NOT automatically re-credited; a `Funding.Transaction.LateCallback` alert event is emitted; an admin is notified for manual review. Automatic crediting of a failed transaction is prohibited because the original failure may already have been communicated to the customer.

**EC-E08-03** — Provider callback amount differs from the amount on the `FundingTransaction`: the transaction transitions to `failed` with reason `AMOUNT_MISMATCH`; `Funding.Transaction.AmountMismatch` event is emitted; the wallet is NOT credited.

**EC-E08-04** — The target wallet pocket is suspended at the time of credit posting: `postEntry()` returns an error; the funding transaction transitions to `failed`; no ledger entry is persisted; the provider is notified (or the callback response signals a failure to retry).

**EC-E08-05** — Agent float balance is exactly equal to the cash-in amount: the cash-in proceeds successfully (≥, not >).

**EC-E08-06** — Customer cancels a `processing` transaction (already submitted to provider): cancellation is rejected with `422 TRANSACTION_NOT_CANCELLABLE`; the transaction may still complete or fail based on the provider's response.

**EC-E08-07** — Bank reference expires (`bank_reference_expires_at < NOW()`) before the customer pays: the `funding:expire-stale` command transitions the `awaiting_match` transaction to `failed` with `failure_reason = bank_reference_expired`; `Funding.Transaction.Expired` is emitted; any bank payment received subsequently for that reference is stored as an `UnmatchedDeposit`.

**EC-E08-08** — Two concurrent bank payment notifications for the same bank transaction ID arrive simultaneously: the `UNIQUE` constraint on `bank_reconciliations.bank_notification_id` ensures exactly one is processed; the second receives a DB constraint error and is retried safely (idempotent).

**EC-E08-09** — A customer with no active wallet in the requested currency attempts to fund: `422 WALLET_NOT_FOUND`; the customer is directed to create a wallet in E06.

**EC-E08-10** — The `LedgerModuleContract` binding is not available (module not registered): the funding service throws a container resolution exception at boot time; the application does not start; this is caught at deployment, not at runtime.

---

## 17. TBDs

| ID             | Description                                                                                             | Gate                    |
|----------------|---------------------------------------------------------------------------------------------------------|-------------------------|
| TBD-E08-001    | Provider selection: specific mobile money operator(s), bank integration, and card processor have not been selected. E08 defines `FundingProviderAdapter` interface and a `MockFundingProviderAdapter` for testing; real adapters are implemented when providers are selected. | Before E08 production deployment |
| TBD-E08-002    | Funding fee rates: basis-point rates per method and currency are not yet defined. E08 reads from config; the config values default to 0 until set by the business. Fee structure (flat, percentage, or hybrid) is TBD; current formula supports percentage only. | Before customer-facing go-live |
| TBD-E08-003    | Funding limit schedule: specific BWP amounts for min/max/daily per KYC tier per method are not yet defined. Implementation reads from config; defaults to permissive limits (0 min, 1 000 000 max, no daily cap) until the schedule is loaded. | Before customer-facing go-live |
| TBD-E08-004    | System float account references: TBD-E07-001 from E07 must be resolved; specifically the config keys `ledger.float_account.{currency_code}` must be populated with actual ledger account references before E08 credit posting is functional. | Before E08 implementation of credit posting |
| TBD-E08-005    | Provider adapter scope: whether `FundingProviderAdapter` implementations are part of E08 or live in the separate `specs/1000-provider-adapter-foundation/` spec. Default: E08 defines the interface and a test mock; `specs/1000-provider-adapter-foundation/` owns real implementations. | Before E08 planning |

---

## 18. Assumptions

1. The `LedgerModuleContract` (E07) is bound in the IoC container before E08 executes any financial operation.
2. The customer's wallet pocket ledger account is registered (via `LedgerModuleContract::registerAccount()`) when the wallet pocket is created in E06.
3. The agent's float ledger account is registered and funded through a separate administrative funding process (outside E08 scope).
4. The `platform.configuration_values` table is accessible and seeded with at minimum the config key structure (values may default to 0/unlimited).
5. All monetary amounts are stored and processed in the smallest currency unit (e.g., pence for GBP, thebe for BWP). No floating-point arithmetic is used.
6. The `OutboxEventPublisher` and `IdempotencyService` from Platform Foundation are available and correctly wired by the time E08 services run.
7. Provider adapter implementations will be injected via the IoC container; E08 domain code never instantiates adapters directly.

---

## 19. Epic-Level Definition of Done

- [ ] All 19 stories meet their story-specific acceptance scenarios.
- [ ] `FundingTransaction` state machine is fully tested: all permitted transitions have a happy-path test; all prohibited transitions return `422`.
- [ ] Provider callback idempotency is tested: duplicate success callback → exactly one ledger entry.
- [ ] Concurrent agent cash-in is tested: two simultaneous requests against the same agent float → exactly one succeeds when float is only sufficient for one.
- [ ] Bank matching is tested end-to-end: reference generation → notification receipt → match → credit.
- [ ] Unmatched deposit flow is tested: no matching reference → `UnmatchedDeposit` stored → event emitted → no wallet credit.
- [ ] All 12 success criteria (SC-E08-001 through SC-E08-012) are covered by at least one automated test.
- [ ] `funding:expire-stale` command transitions stale `processing` (timeout) and stale `awaiting_match` (reference expiry) transactions to `failed`; idempotency verified; `Funding.Transaction.Expired` emitted.
- [ ] All 9 metrics (FR-E08-051) are emitted by the relevant service classes; `MetricsCollectorInterface` is injected and stubbed in unit tests.
- [ ] PHPStan level 8 passes over `app/Modules/Funding/`.
- [ ] All funding mutations produce entries in `platform.audit_events`.
- [ ] All events use `OutboxEventPublisher` inside the same DB transaction as the triggering state change.
- [ ] `Idempotency-Key` is enforced on all mutation endpoints; tests confirm `400` without it.
- [ ] Funding limit enforcement is tested: below-minimum, above-maximum, and daily-limit-exceeded scenarios each return the correct `422` error code.
- [ ] Step-up authentication gate is tested: amount at and above `auth.step_up_threshold` rejects without step-up; amount below threshold proceeds without step-up.
- [ ] Fee mismatch at confirmation (`FEE_CHANGED`) is tested.
- [ ] No direct wallet balance mutation; all credits flow through `LedgerModuleContract::postEntry()`.
- [ ] **E08 Advance Gate**: TBD-E07-001 (float account reference config keys) resolved before credit-posting implementation begins.
- [ ] **E08 Advance Gate**: TBD-E07-002 (customer wallet pocket `account_type`) confirmed before agent cash-in implementation begins.
- [ ] Security gates: provider callback with invalid HMAC returns `400` without state change; IDOR test confirms customer cannot access another's transaction.
- [ ] OpenAPI documentation updated for all E08 endpoints.
- [ ] No unresolved CRITICAL or HIGH defects at release.

---

## 20. Clarifications

### Session 2026-09-18

- Q: What mechanism transitions a `processing` transaction to `failed` when a provider callback never arrives — is a configurable timeout job required? → A: Option A — `funding:expire-stale` scheduled command handles both `processing` timeout and `awaiting_match` reference expiry; TTL configurable via `funding.processing.timeout_minutes` and `funding.bank_reference.expiry_days`.
- Q: Should a customer be able to cancel a `bank_transfer` transaction while it is in `awaiting_match` status — after they have received the bank reference but before they have made the payment? → A: Option A — `awaiting_match` → `cancelled` permitted via `POST /v1/funding/transactions/{id}/cancel`; bank reference invalidated; `Funding.Transaction.Cancelled` emitted; cancelled reference never matched by bank notification handler.
- Q: Should agent cash-in support a configurable fee (deducted from the credited amount), or should it always be fee-free? → A: Option A — `fee_amount = 0` enforced at domain layer for `funding_method = agent`; journal is always two-line; agent commission deferred to E21.
- Q: Should E08 emit structured application metrics following the E07 pattern? → A: Option A — emit 9 counters/histograms via `MetricsCollectorInterface`; injected into `FundingPostingService`, callback handler, expiry command, and bank matching service.
- Q: Does the bank payment notification endpoint receive push webhooks that the bank sends to OleKard, or must E08 implement a polling job? → A: Option A — push webhooks only; `POST /v1/funding/bank/notifications` is the receiving endpoint; no polling job in E08; polling is a provider-adapter concern (E1000) if a specific bank requires it.
