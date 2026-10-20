# E09 — Wallet-to-Wallet Transfer

## Problem Statement

Customers need to send money directly to other OleKard wallet holders. This is the core peer-to-peer (P2P) money movement capability: a customer selects a recipient by phone number or wallet number, enters an amount, reviews the fee, authenticates with their transaction PIN, and the system atomically debits the sender and credits the recipient — all within a single synchronous operation. Without this capability the wallet ecosystem cannot fulfil its primary value proposition as a mobile money platform.

---

## Scope

E09 covers customer-initiated wallet-to-wallet P2P transfers. In scope:

- Recipient lookup and validation (by phone number or wallet number)
- Transfer initiation with fee quotation
- Per-transaction and daily sending limits enforcement
- Balance sufficiency validation
- Risk evaluation hook (stub implementation; extensible for E18)
- Transaction PIN authentication at confirmation, including step-up for large amounts
- Atomic double-entry journal posting (debit sender pocket, credit recipient pocket, credit platform fee account when fee > 0)
- Transfer completion and receipt generation
- Transfer cancellation (pending transfers only)
- Pending transfer expiry (Artisan command)
- Failure handling with explicit reason codes
- Idempotency at both initiation and confirmation
- Domain event emission for notification delivery (E17)
- Audit of all financial mutations
- Structured metrics and observability

Out of scope for E09 (future epics):

- Agent-to-customer or enterprise-to-customer transfers (E12/E25)
- Cross-currency transfers (E29)
- Notification delivery (E17 consumes the domain events emitted here)
- Fraud/AML scoring beyond basic velocity limits (E18)
- Reversal or dispute resolution (E22)
- Bulk/batch transfer (E25)

---

## Goals

1. A customer can complete a P2P transfer end-to-end in a single session.
2. Sender's wallet is debited and recipient's wallet is credited atomically — no partial outcomes.
3. Every financial mutation is idempotent, audited, and observable.
4. Concurrent transfers from the same sender wallet cannot produce an overdraft.

---

## Non-Goals

- This feature does not deliver notifications to senders or recipients. It emits domain events that E17 will consume.
- This feature does not perform AML/fraud scoring. A risk-evaluation hook is stubbed to always pass; E18 will override it.
- This feature does not support cross-currency conversion.

---

## Actors

| Actor | Description |
|-------|-------------|
| **Sender** | An authenticated customer who initiates and confirms a P2P transfer from their own wallet |
| **Recipient** | A registered OleKard customer who receives the credited amount |
| **Platform Ledger** | The internal Ledger module (E07) that records double-entry journal entries |
| **Admin** | A platform operator who can view all transfers for support and reconciliation |

---

## User Stories

### US-TRANSFER-001 — Initiate transfer (P0)

**As a** customer,
**I want to** look up a recipient by phone number or wallet number and initiate a transfer with a stated amount,
**so that** I can send money to another OleKard wallet holder and see the fee and net outcome before committing.

**Acceptance Criteria:**

- Given I provide a valid recipient identifier (phone number or wallet number), the system returns a sanitized recipient summary (display name, wallet currency) without exposing full PII.
- Given I submit a transfer initiation with a valid recipient, supported currency, and positive gross amount, a `TransferTransaction` is created in `pending` status and the fee quote is returned.
- Given the recipient identifier does not resolve to an active customer or active wallet, the system returns a `RECIPIENT_NOT_FOUND` or `RECIPIENT_WALLET_INACTIVE` error.
- Given the sender and recipient resolve to the same customer, the system rejects with `SELF_TRANSFER_NOT_ALLOWED`.
- Given the sender's wallet and recipient's wallet are in different currencies, the system rejects with `CURRENCY_MISMATCH`.
- Given the requested gross amount is below the configured minimum transfer amount, the system rejects with `AMOUNT_BELOW_MINIMUM`.
- Given the requested gross amount exceeds the configured per-transaction maximum, the system rejects with `AMOUNT_ABOVE_MAXIMUM`.
- Given the sender's daily transferred amount would exceed the configured daily sending limit after this transfer, the system rejects with `DAILY_LIMIT_EXCEEDED`.
- Given the sender's current wallet balance is less than `gross_amount`, the system rejects with `INSUFFICIENT_BALANCE`.
- Given a duplicate `Idempotency-Key` header for initiation, the system returns the original `TransferTransaction` without creating a new one.

---

### US-TRANSFER-002 — Confirm and authenticate transfer (P0)

**As a** customer with a pending transfer,
**I want to** confirm the transfer by entering my transaction PIN,
**so that** the money moves from my wallet to the recipient's wallet immediately and I receive confirmation.

**Acceptance Criteria:**

- Given I submit a correct transaction PIN for a `pending` transfer I own, the system:
  - Re-validates balance at confirmation time (balance must still be ≥ `gross_amount`).
  - Re-validates the current fee rate matches the quoted fee (returns `FEE_MISMATCH` if changed).
  - Re-runs limit checks (returns `DAILY_LIMIT_EXCEEDED` if limit now exhausted).
  - Atomically posts the double-entry journal via `LedgerModuleContract::postEntry()`.
  - Transitions the transfer to `completed` status.
  - Creates an immutable `TransferReceipt` record.
  - Emits `Transfer.Completed` domain event.
- Given I submit an incorrect transaction PIN, the system returns `INVALID_PIN` and the transfer remains `pending`.
- Given the transfer amount meets or exceeds the step-up authentication threshold (configured in `auth.step_up_threshold`), the system enforces enhanced PIN verification per E04/E05 rules.
- Given the fee rate has changed since initiation, the system returns `409 FEE_MISMATCH` with the new fee quote; no money moves; the transfer remains `pending`.
- Given a duplicate `Idempotency-Key` for confirmation of an already-`completed` transfer, the system returns a `200` with the existing receipt (idempotent replay).
- Given a confirmation attempt on a `failed` or `cancelled` transfer, the system returns `422 TRANSFER_NOT_MODIFIABLE`.

---

### US-TRANSFER-003 — Cancel pending transfer (P0)

**As a** customer with a pending transfer I have not yet confirmed,
**I want to** cancel it,
**so that** no money leaves my wallet.

**Acceptance Criteria:**

- Given a `pending` transfer I own, I can cancel it and it transitions to `cancelled` status.
- Given a `completed`, `failed`, or `cancelled` transfer, cancel returns `422 TRANSFER_NOT_CANCELLABLE`.
- The cancelled transfer emits a `Transfer.Cancelled` domain event.

---

### US-TRANSFER-004 — View transfer history and receipt (P1)

**As a** customer,
**I want to** view my past transfers and retrieve receipts,
**so that** I can keep records of money I have sent and received.

**Acceptance Criteria:**

- I can list all transfers where I am sender or recipient, paginated.
- I can retrieve a specific transfer by ID (only if I am sender or recipient).
- I can retrieve a receipt by transaction ID (`GET /v1/transfers/{id}/receipt`).
- I can retrieve a receipt by its `receipt_reference` (`GET /v1/transfers/receipts/{receipt_reference}`).
- Receipts are only accessible to the sender or recipient of that transfer.
- Receipts contain: transfer reference, sender display name, recipient display name, gross amount, fee amount, net amount, currency, completed timestamp.

---

## Functional Requirements

### Core Entities

**FR-E09-001** — The system SHALL maintain a `transfer_transactions` table with these fields (all amounts in minor integer units):

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID v7 | PK |
| `transfer_reference` | string | Human-readable, `TXF-{10-CHAR-ALPHANUM}`, globally unique; unambiguous character set (no I/O/1/0) |
| `sender_customer_id` | UUID | FK → customers |
| `sender_wallet_id` | UUID | FK → wallets |
| `sender_pocket_ledger_ref` | string | Ledger account reference for sender's pocket, resolved at initiation from `WalletModuleContract` |
| `recipient_customer_id` | UUID | FK → customers |
| `recipient_wallet_id` | UUID | FK → wallets |
| `recipient_pocket_ledger_ref` | string | Ledger account reference for recipient's pocket, resolved at initiation |
| `currency_code` | string(3) | ISO 4217; sender and recipient pockets must share this currency |
| `gross_amount` | integer | Amount in minor units that the sender intends to debit from their pocket (inclusive of fee) |
| `fee_amount` | integer | Fee in minor units; `0` when fee rate is zero |
| `net_amount` | integer | `gross_amount − fee_amount`; amount credited to recipient |
| `fee_rate_bps` | integer | Basis-points rate locked at initiation; used for FEE_MISMATCH re-validation |
| `status` | enum | See FR-E09-002 |
| `failure_reason` | enum | Nullable; set on transition to `failed` |
| `ledger_journal_id` | string | Nullable; set on successful journal post |
| `idempotency_key` | string | Nullable; deduplicated within `transfer:` namespace |
| `correlation_id` | UUID | Set at initiation |
| `completed_at` | timestamp | Nullable; set when status → `completed` |
| `cancelled_at` | timestamp | Nullable; set when status → `cancelled` |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**FR-E09-002** — `TransferStatus` enum: `pending`, `completed`, `failed`, `cancelled`.

**FR-E09-003** — `TransferFailureReason` enum: `INSUFFICIENT_BALANCE`, `DAILY_LIMIT_EXCEEDED`, `RISK_REJECTED`, `RECIPIENT_WALLET_SUSPENDED`, `SYSTEM_ERROR`.

**FR-E09-004** — Valid status transitions:

```
pending  →  completed   (on successful PIN + journal post)
pending  →  failed      (on balance failure, risk rejection, system error during confirmation, or TTL expiry)
pending  →  cancelled   (on explicit customer cancellation)
```

No other transitions are permitted. Attempting an invalid transition MUST throw a `TransferNotModifiableException`.

**FR-E09-005** — The system SHALL maintain a `transfer_receipts` table:

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID v7 | PK |
| `transfer_transaction_id` | UUID | FK → transfer_transactions; UNIQUE |
| `receipt_reference` | UUID v7 | Human-facing lookup key; UNIQUE |
| `sender_customer_id` | UUID | |
| `recipient_customer_id` | UUID | |
| `currency_code` | string(3) | |
| `gross_amount` | integer | |
| `fee_amount` | integer | |
| `net_amount` | integer | |
| `issued_at` | timestamp | |

Receipts are immutable after insert. The Eloquent model MUST override `save()` after first insert and `delete()` to throw `TransferReceiptImmutableException`.

---

### Recipient Lookup

**FR-E09-006** — `POST /v1/transfers/recipient-lookup` accepts either `phone_number` (E.164 format) or `wallet_number` (string), but not both. The response MUST NOT expose the recipient's full phone number, email address, date of birth, or internal UUID in the same response to prevent customer enumeration.

**FR-E09-007** — The response for a successful recipient lookup SHALL return:
- `wallet_id` (UUID) — used in subsequent initiation
- `recipient_customer_id` (UUID) — used in subsequent initiation
- `display_name` — masked or full name per `transfer.recipient_display_privacy` platform configuration [TBD-E09-005]
- `currency_code` — the pocket's currency
- `lookup_token` — a server-generated opaque UUID stored in Redis (TTL: 300 seconds, single-use) binding the resolved recipient identity to a subsequent initiation call; prevents IDOR substitution of arbitrary recipient IDs

**FR-E09-008** — If the phone number resolves to no active OleKard customer, or the wallet number does not exist, return `404 RECIPIENT_NOT_FOUND`.

**FR-E09-009** — If the resolved recipient's wallet is not in `active` status (e.g., suspended, frozen), return `422 RECIPIENT_WALLET_INACTIVE`.

**FR-E09-010** — If the resolved recipient is the caller themselves (same `customer_id`), return `422 SELF_TRANSFER_NOT_ALLOWED`.

---

### Transfer Initiation

**FR-E09-011** — `POST /v1/transfers` creates a `TransferTransaction` in `pending` status. Required fields:

| Field | Description |
|-------|-------------|
| `lookup_token` | Token from the prior recipient-lookup response |
| `wallet_id` | Sender's source wallet UUID; must be owned by the authenticated customer |
| `gross_amount` | Integer (minor units); must be positive |
| `currency_code` | Must match sender's pocket currency and recipient's pocket currency |
| `Idempotency-Key` | Required header |

**FR-E09-012** — The sender's active pocket is identified by the intersection of `wallet_id` + `currency_code`. If no active pocket in that currency exists on the sender's wallet, return `422 WALLET_POCKET_NOT_FOUND`. The resolved pocket's ledger account reference is stored as `sender_pocket_ledger_ref`. Similarly, the recipient's pocket ledger reference is resolved from the recipient's wallet_id + currency_code (bound in the `lookup_token`) and stored as `recipient_pocket_ledger_ref`. If the recipient's pocket is unavailable, return `422 RECIPIENT_WALLET_INACTIVE`.

**FR-E09-013** — The system MUST verify that `wallet_id` is owned by the authenticated customer. Return `403 FORBIDDEN` on ownership mismatch.

**FR-E09-014** — The `lookup_token` MUST be verified against the Redis store. If absent, expired, or already consumed, return `422 INVALID_LOOKUP_TOKEN`. A `lookup_token` is invalidated (deleted from Redis) after one successful initiation.

**FR-E09-015** — Fee calculation: `fee_amount = intval(round(gross_amount × fee_rate_bps / 10_000))` using half-away-from-zero rounding (PHP `round()` default), where `fee_rate_bps` is the platform-configured rate for the sender's KYC tier and currency. The resolved `fee_rate_bps` is persisted on the `TransferTransaction` for FEE_MISMATCH re-validation at confirmation. `net_amount = gross_amount − fee_amount`.

**FR-E09-016** — Fee rates are configurable per KYC tier and currency via the platform configuration service (keys: `transfer.fee_rate_bps.{tier}.{currency_code}`). If no configured rate is found, the effective rate is `0` (no fee charged). [TBD-E09-001]

**FR-E09-017** — The initiation response SHALL include: `transfer_id`, `transfer_reference`, `status`, `gross_amount`, `fee_amount`, `net_amount`, `currency_code`, `recipient_display_name`, and `expires_at` (UTC timestamp = `created_at` + `transfer.pending_ttl_minutes`; see FR-E09-046).

---

### Limit and Balance Validation

**FR-E09-018** — `gross_amount` MUST be ≥ the configured minimum transfer amount for the sender's currency (`transfer.min_amount.{currency_code}`). Return `422 AMOUNT_BELOW_MINIMUM` if violated. [TBD-E09-002]

**FR-E09-019** — `gross_amount` MUST be ≤ the configured per-transaction maximum for the sender's KYC tier and currency (`transfer.max_amount.{tier}.{currency_code}`). Return `422 AMOUNT_ABOVE_MAXIMUM` if violated. [TBD-E09-002]

**FR-E09-020** — The daily sending limit is calculated by summing `gross_amount` across all `TransferTransaction` rows for the sender where `status IN ('pending', 'completed')` and `DATE(created_at) = CURRENT_DATE`. Transfers in `cancelled` or `failed` status are excluded from this sum (cancelled transfers free up daily limit headroom). If adding the new `gross_amount` would exceed the configured daily limit (`transfer.daily_limit.{tier}.{currency_code}`), return `422 DAILY_LIMIT_EXCEEDED`. [TBD-E09-002]

**FR-E09-021** — The sender's current wallet pocket balance MUST be ≥ `gross_amount` at initiation. Return `422 INSUFFICIENT_BALANCE` if not. This is a soft pre-check via `LedgerModuleContract::getBalance()`; the authoritative balance check occurs atomically at confirmation (CC-E09-001).

---

### Risk Evaluation

**FR-E09-022** — Before creating the `pending` transfer record, the system SHALL invoke the risk evaluation hook (`TransferRiskService::evaluate(context)`). In E09, this service is a stub that always returns `approved`. E18 will override this binding via the service container without modifying E09 code. The `TransferRiskService` interface is defined in E09.

**FR-E09-023** — If the risk evaluation returns `rejected`, the transfer MUST NOT be created and the system returns `422 RISK_REJECTED`. A `Transfer.RiskRejected` event MUST be emitted with `risk_reason` from the evaluation result.

---

### Transfer Confirmation and PIN

**FR-E09-024** — `POST /v1/transfers/{id}/confirm` accepts:

| Field | Description |
|-------|-------------|
| `transaction_pin` | Customer's transaction PIN (never stored; verified via `IdentityModuleContract::verifyTransactionPin()`) |
| `Idempotency-Key` | Required header |

**FR-E09-025** — Before processing confirmation, the system MUST verify that the transfer belongs to the authenticated sender and is in `pending` status. Return `404` if not found or not owned; `422 TRANSFER_NOT_MODIFIABLE` if not `pending`.

**FR-E09-026** — The confirmation endpoint MUST re-validate at confirmation time:
1. Current fee rate for the sender's tier equals `fee_rate_bps` stored on the transaction. If changed, return `409 FEE_MISMATCH` with `{ "new_fee_amount": ..., "new_net_amount": ..., "new_fee_rate_bps": ... }`. The transfer remains `pending`.
2. Sender's daily limit is still not exceeded, using the same calculation as FR-E09-020 but excluding this transfer's own `pending` record (atomic check per CC-E09-002).
3. Sender's balance ≥ `gross_amount` — authoritative check inside the journal transaction (CC-E09-001).

**FR-E09-027** — PIN verification is delegated to `IdentityModuleContract::verifyTransactionPin(customerId, pin)`. If verification fails, return `422 INVALID_PIN`. PIN verification failure MUST NOT transition the transfer to `failed`; it remains `pending`. Repeated failures are rate-limited per customer per E04 rules.

**FR-E09-028** — If `gross_amount ≥ auth.step_up_threshold` (configured in `platform_configuration_values` with `auth.step_up_threshold_currency` for unit alignment; seeded default: 10,000 minor units per constitution GAP-027), the system MUST require step-up authentication. In E09, this is enforced by passing `require_step_up: true` to the Identity module boundary. This extends the `IdentityModuleContract::verifyTransactionPin()` interface to `verifyTransactionPin(customerId, pin, requireStepUp: bool)`; plan.md MUST update the `IdentityModuleContract` accordingly. The contract consumer validates the customer's current step-up session state.

**FR-E09-029** — Incorrect PIN attempts: the `IdentityModuleContract` returns a failure result. The system returns `422 INVALID_PIN`. The transfer ID and attempt count (but never the PIN value) MUST be included in the audit log. After the configured maximum attempts (`auth.pin_max_attempts`), the customer's transaction PIN is locked per E04 rules.

---

### Atomic Journal Posting

**FR-E09-030** — On successful PIN verification and all re-validations passing, the system SHALL post a balanced double-entry journal via `LedgerModuleContract::postEntry()` within a single database transaction that also updates the `TransferTransaction` status.

**FR-E09-031** — When `fee_amount > 0`, the journal has three balanced lines:

| Line | Side | Account | Amount |
|------|------|---------|--------|
| 1 | DEBIT | Sender's pocket ledger account (`sender_pocket_ledger_ref`) | `gross_amount` |
| 2 | CREDIT | Recipient's pocket ledger account (`recipient_pocket_ledger_ref`) | `net_amount` |
| 3 | CREDIT | Platform fee account (`transfer.fee_account.{currency_code}`) | `fee_amount` |

Total debits = Total credits = `gross_amount`. [TBD-E09-003]

**FR-E09-032** — When `fee_amount = 0`, the journal has two balanced lines:

| Line | Side | Account | Amount |
|------|------|---------|--------|
| 1 | DEBIT | Sender's pocket ledger account | `gross_amount` |
| 2 | CREDIT | Recipient's pocket ledger account | `gross_amount` |

**FR-E09-033** — If `postEntry()` throws or the database transaction rolls back for any reason, the `TransferTransaction` status MUST NOT be set to `completed`. The system transitions to `failed` with reason `SYSTEM_ERROR`, emits `Transfer.Failed`, and returns `500` or the appropriate mapped error response.

**FR-E09-034** — The `ledger_journal_id` returned by `postEntry()` MUST be persisted on the `TransferTransaction` record before the HTTP response is sent.

**FR-E09-035** — `completed_at` MUST be set to the UTC timestamp of the successful journal post and persisted atomically within the same DB transaction.

---

### Transfer Receipt

**FR-E09-036** — On transition to `completed`, the system SHALL create a `TransferReceipt` record atomically within the same database transaction as the journal post and status update.

**FR-E09-037** — The receipt `issued_at` field MUST equal the `completed_at` timestamp of the parent transfer.

**FR-E09-038** — `GET /v1/transfers/{id}/receipt` returns the receipt for the given transfer ID. The caller MUST be the sender or recipient. Returns `404` if the transfer has no receipt (i.e., not yet `completed` or was `failed`/`cancelled`).

**FR-E09-039** — `GET /v1/transfers/receipts/{receipt_reference}` returns the receipt by its opaque reference. The caller MUST be the sender or recipient of the corresponding transfer. Returns `404` if not found or not authorized.

---

### Transfer Queries

**FR-E09-040** — `GET /v1/transfers` returns a paginated list of transfers where the authenticated customer is the sender OR recipient, ordered by `created_at DESC`. Default page size: 20; maximum: 100. Supports filters: `status`, `from_date`, `to_date`.

**FR-E09-041** — `GET /v1/transfers/{id}` returns the full transfer record if the authenticated customer is the sender or recipient. Returns `404` for unknown or unauthorized.

**FR-E09-042** — Both list and detail responses include: `id`, `transfer_reference`, `status`, `failure_reason`, `gross_amount`, `fee_amount`, `net_amount`, `currency_code`, `sender_display_name`, `recipient_display_name`, `created_at`, `completed_at`.

---

### Cancellation

**FR-E09-043** — `POST /v1/transfers/{id}/cancel` transitions the transfer from `pending` → `cancelled`. The caller MUST be the sender.

**FR-E09-044** — If the transfer is in any state other than `pending`, return `422 TRANSFER_NOT_CANCELLABLE`.

**FR-E09-045** — Cancellation sets `cancelled_at` to the current UTC timestamp and emits `Transfer.Cancelled`. No ledger entries are created for cancellation (no money has moved at this point).

---

### Pending Transfer Expiry

**FR-E09-046** — `pending` transfers that have not been confirmed or cancelled within the configurable TTL (`transfer.pending_ttl_minutes`, default: 10 minutes [TBD-E09-004]) SHALL be transitioned to `failed` with reason `SYSTEM_ERROR` by an Artisan console command `transfer:expire-pending`. This command is scheduled to run every minute via the Laravel scheduler.

**FR-E09-047** — The `transfer:expire-pending` command MUST emit `Transfer.Failed` for each expired transfer. The transition is performed in batches. The command MUST be idempotent: re-running it on an already-failed transfer has no effect.

---

### Domain Events

**FR-E09-048** — The system SHALL emit the following integration events (all implement `IntegrationEventInterface`, no PII in payloads):

| Event Name | Trigger | Key Payload Fields |
|------------|---------|-------------------|
| `Transfer.Initiated` | `pending` transfer created | `transferId`, `senderWalletId`, `recipientWalletId`, `currencyCode`, `grossAmount`, `feeAmount`, `netAmount`, `occurredAt`, `correlationId` |
| `Transfer.Completed` | Journal posted, status → `completed` | `transferId`, `senderWalletId`, `recipientWalletId`, `currencyCode`, `grossAmount`, `feeAmount`, `netAmount`, `receiptReference`, `occurredAt`, `correlationId` |
| `Transfer.Failed` | Status → `failed` | `transferId`, `senderWalletId`, `currencyCode`, `grossAmount`, `failureReason`, `occurredAt`, `correlationId` |
| `Transfer.Cancelled` | Status → `cancelled` | `transferId`, `senderWalletId`, `currencyCode`, `grossAmount`, `occurredAt`, `correlationId` |
| `Transfer.RiskRejected` | Risk evaluation returns `rejected` | `transferId`, `senderWalletId`, `currencyCode`, `grossAmount`, `riskReason`, `occurredAt`, `correlationId` |

**FR-E09-049** — All events MUST be emitted only after the triggering state change has been durably persisted (outbox/reliable-event pattern per Principle 9).

---

### Audit

**FR-E09-050** — The following actions MUST be recorded in the platform audit log: transfer initiation, confirmation attempt (success or failure), PIN verification failure, cancellation, expiry, and any admin read. Each audit entry includes: actor ID, action, transfer ID, timestamp, correlation ID, and outcome.

**FR-E09-051** — Transaction PIN values MUST NEVER appear in logs, audit records, or event payloads.

---

### Metrics and Observability

**FR-E09-052** — The system SHALL emit metrics:
- `transfer.initiated` (counter, tags: currency)
- `transfer.completed` (counter, tags: currency, has_fee)
- `transfer.failed` (counter, tags: currency, failure_reason)
- `transfer.cancelled` (counter, tags: currency)
- `transfer.expired` (counter, tags: currency)
- `transfer.confirmation.duration_ms` (histogram)
- `transfer.journal.post.duration_ms` (histogram)
- `transfer.fee.amount` (histogram, tags: currency)

---

### Admin Endpoints

**FR-E09-053** — `GET /v1/admin/transfers` (requires `transfer.admin` permission) returns all transfers, paginated and filterable by: `status`, `sender_customer_id`, `recipient_customer_id`, `currency_code`, `from_date`, `to_date`.

**FR-E09-054** — `GET /v1/admin/transfers/{id}` (requires `transfer.admin` permission) returns the full transfer record including `ledger_journal_id`, `correlation_id`, and `fee_rate_bps`.

---

## Security Requirements

**SR-E09-001** — Transaction PIN is required for all transfer confirmations. The PIN is verified via `IdentityModuleContract::verifyTransactionPin()` and MUST NOT be stored, logged, or included in any event payload (FR-E09-051).

**SR-E09-002** — Step-up authentication is enforced for transfers at or above the platform-configured `auth.step_up_threshold` (constitution GAP-027). The threshold MUST NOT be hard-coded (FR-E09-028).

**SR-E09-003** — The sender can only initiate a transfer from a wallet they own. Server-side ownership validation is mandatory for every initiation and confirmation (FR-E09-013). Returns `403 FORBIDDEN` on any mismatch.

**SR-E09-004** — The `lookup_token` mechanism prevents IDOR: a customer cannot substitute an arbitrary `recipient_customer_id` or `recipient_wallet_id` in the initiation payload. The token is a server-generated opaque UUID stored in Redis with a 300-second TTL and is single-use (deleted on first successful consumption).

**SR-E09-005** — Recipient lookup MUST NOT reveal full PII in its response. Display name format is governed by the `transfer.recipient_display_privacy` platform configuration value (FR-E09-007, TBD-E09-005).

**SR-E09-006** — The confirmation endpoint is rate-limited per sender customer. At most `auth.pin_max_attempts` incorrect PIN attempts within a rolling window result in account-level PIN lock (E04). The failure counter is stored in Redis.

**SR-E09-007** — The recipient lookup endpoint is rate-limited per authenticated customer to prevent phone-number/wallet enumeration attacks. Rate: configurable via `transfer.lookup_rate_limit.per_minute` (platform configuration).

**SR-E09-008** — All state-changing transfer endpoints MUST require `Idempotency-Key` header. Return `422 MISSING_IDEMPOTENCY_KEY` if absent.

**SR-E09-009** — Self-transfer (sender == recipient customer) MUST be rejected at the lookup endpoint (FR-E09-010) and re-validated at initiation. Return `422 SELF_TRANSFER_NOT_ALLOWED`.

**SR-E09-010** — All transfer mutations and privileged admin reads are recorded in the platform audit log (FR-E09-050).

---

## Concurrency Requirements

**CC-E09-001 (Balance Race Guard)** — The authoritative balance check and the ledger debit MUST execute inside a single database transaction. The `LedgerModuleContract::postEntry()` implementation MUST acquire a row-level lock on the sender's ledger account before evaluating the available balance and writing the debit line. If the balance check fails inside this transaction, the entire DB transaction rolls back and the transfer transitions to `failed` with `INSUFFICIENT_BALANCE`. **Test**: two concurrent confirmation requests for the same `transfer_id` — exactly one succeeds, one gets `422 TRANSFER_NOT_MODIFIABLE`.

**CC-E09-002 (Daily Limit Race Guard)** — The daily-limit sum query and the new transfer record insertion MUST execute inside the same database transaction. The daily sum query MUST use a locking read (e.g., `SELECT ... FOR UPDATE` on the sender's `transfer_transactions` rows for the current date) to prevent two concurrent initiations both passing the limit check simultaneously. **Test**: two concurrent transfers that would individually pass but together exceed the daily limit — exactly one is accepted.

**CC-E09-003 (Duplicate Confirmation Guard)** — The status transition `pending → completed` MUST be guarded by a `WHERE status = 'pending'` conditional update. If two concurrent confirmation requests both pass PIN verification, only the one whose `UPDATE` affects a row wins; the other receives `422 TRANSFER_NOT_MODIFIABLE`. **Test**: two concurrent HTTP calls to `POST /v1/transfers/{id}/confirm` — exactly one journal entry and one receipt created.

**CC-E09-004 (Idempotency Key Uniqueness)** — The `idempotency_key` column MUST have a partial UNIQUE index (`WHERE idempotency_key IS NOT NULL`). A duplicate key on insert MUST return the existing record without creating a second `TransferTransaction`.

**CC-E09-005 (Journal Atomicity)** — The journal post (`postEntry()`), status update to `completed`, `completed_at` assignment, and `TransferReceipt` creation MUST all succeed or all roll back together within a single DB transaction. There MUST be no observable state where `status = 'completed'` and `ledger_journal_id IS NULL`, or where a receipt exists for a non-`completed` transfer.

---

## API Endpoints

| Method | Path | Auth | Permission | Description |
|--------|------|------|------------|-------------|
| `POST` | `/v1/transfers/recipient-lookup` | Bearer | `transfer.initiate` | Look up and validate recipient; returns `lookup_token` |
| `POST` | `/v1/transfers` | Bearer | `transfer.initiate` | Initiate a pending transfer (requires `lookup_token`) |
| `POST` | `/v1/transfers/{id}/confirm` | Bearer | `transfer.initiate` | Confirm with PIN; synchronously completes or fails |
| `POST` | `/v1/transfers/{id}/cancel` | Bearer | `transfer.initiate` | Cancel a pending transfer |
| `GET` | `/v1/transfers` | Bearer | `transfer.read` | List own transfers (sender or recipient) |
| `GET` | `/v1/transfers/{id}` | Bearer | `transfer.read` | Get transfer detail |
| `GET` | `/v1/transfers/{id}/receipt` | Bearer | `transfer.read` | Get receipt by transaction ID |
| `GET` | `/v1/transfers/receipts/{receipt_reference}` | Bearer | `transfer.read` | Get receipt by reference |
| `GET` | `/v1/admin/transfers` | Bearer | `transfer.admin` | Admin: list all transfers |
| `GET` | `/v1/admin/transfers/{id}` | Bearer | `transfer.admin` | Admin: get transfer detail |

**Route ordering note**: In the Laravel route file, `POST /v1/transfers/recipient-lookup` and `GET /v1/transfers/receipts/{receipt_reference}` MUST be defined before the `{id}` wildcard routes. All `{id}` routes MUST have a `->whereUuid('id')` constraint to prevent path conflicts.

All mutating endpoints (`POST`) MUST accept and honor the `Idempotency-Key` header. All endpoints return responses under a `data` envelope. Errors use the standard platform error envelope with `code`, `message`, and optional `details`.

---

## Permissions

| Permission Slug | Role(s) | Description |
|-----------------|---------|-------------|
| `transfer.initiate` | Customer | Initiate, confirm, and cancel own transfers; perform recipient lookups |
| `transfer.read` | Customer | Read own transfer history and receipts |
| `transfer.admin` | Admin | Read all transfers and receipts across all customers |

Permissions are seeded in the database via a dedicated seeder. Admin role does not have `transfer.initiate` by default.

---

## Integration Boundaries

| Dependency | Contract / Interface | Purpose |
|------------|---------------------|---------|
| E05 KYC | `IdentityModuleContract::getCustomerKycTier(customerId)` | Resolve KYC tier for limit and fee-rate lookup |
| E06 Wallet | `WalletModuleContract::getPocketLedgerAccountRef(walletId, currencyCode)` | Resolve sender/recipient pocket ledger account reference |
| E06 Wallet | `WalletModuleContract::getWalletOwnerId(walletId)` | Validate sender wallet ownership |
| E07 Ledger | `LedgerModuleContract::postEntry(journalLines[])` | Post double-entry journal; returns `journal_id` |
| E07 Ledger | `LedgerModuleContract::getBalance(ledgerAccountRef)` | Soft pre-check of sender balance at initiation |
| E04/E05 Identity | `IdentityModuleContract::verifyTransactionPin(customerId, pin, requireStepUp: bool)` | PIN verification with optional step-up enforcement; `requireStepUp` is new in E09 |
| E18 Risk (stub) | `TransferRiskService::evaluate(context): RiskResult` | Risk evaluation hook; E09 provides stub always returning `approved`; E18 overrides binding |
| E17 Notifications | Domain events | E17 consumes `Transfer.Completed`, `Transfer.Failed`, `Transfer.Cancelled` for push/SMS/email |
| Platform Config | `PlatformConfigurationService::get(key)` | Fee rates, transfer limits, step-up threshold, pending TTL, lookup rate limit |

---

## Key Entities

| Entity | Description |
|--------|-------------|
| `TransferTransaction` | Core record tracking lifecycle from `pending` through terminal state |
| `TransferReceipt` | Immutable proof-of-completion; created atomically with journal post |
| `LookupToken` | Redis-backed, server-generated UUID token (TTL 300s, single-use) binding a resolved recipient to an initiation call |
| `TransferRiskService` | Interface for risk evaluation; E09 stub always approves; E18 overrides container binding |

---

## Success Criteria

**SC-E09-001** — A customer can complete a P2P transfer end-to-end: recipient lookup → initiate → confirm with PIN → `completed` status, with sender balance decreased by `gross_amount` and recipient balance increased by `net_amount`.

**SC-E09-002** — The double-entry journal always balances: `sum(debit lines) = sum(credit lines)` for every completed transfer. No transfer can produce an unbalanced journal entry.

**SC-E09-003** — Two concurrent confirmation requests for the same `transfer_id` result in exactly one journal entry and exactly one `TransferReceipt`. The second confirmation returns `422 TRANSFER_NOT_MODIFIABLE`.

**SC-E09-004** — Two concurrent transfer initiations from the same sender that would together exceed the daily limit result in exactly one being accepted; the other receives `422 DAILY_LIMIT_EXCEEDED`.

**SC-E09-005** — A failed or cancelled transfer leaves both sender and recipient wallet balances unchanged, and no journal entry or receipt is created.

**SC-E09-006** — A duplicate `Idempotency-Key` for initiation returns the original `TransferTransaction` without creating a second record.

**SC-E09-007** — A duplicate `Idempotency-Key` for confirmation of an already-completed transfer returns `200` with the existing receipt.

**SC-E09-008** — All transfer mutations (initiation, confirmation, cancellation, failure, expiry) produce audit log entries with actor ID, timestamp, and outcome.

**SC-E09-009** — Recipient lookup by phone number does not expose the recipient's full phone number, national ID, email address, or internal UUID in the response.

---

## Edge Cases

**EC-E09-01 (Self-Transfer)** — The sender submits a recipient lookup using their own phone number or wallet number. Returns `422 SELF_TRANSFER_NOT_ALLOWED`. If somehow bypassed to initiation, re-validation at initiation also rejects.

**EC-E09-02 (Zero Fee, Exact Balance)** — Sender's balance equals `gross_amount` and `fee_amount = 0`. Transfer succeeds: sender balance → zero, recipient credited `gross_amount`. Example: gross=500, fee=0, net=500, balance=500 → debit 500, credit 500. ✓

**EC-E09-03 (Non-Zero Fee, Exact Balance)** — Sender's balance equals `gross_amount` and `fee_amount > 0`. Transfer succeeds because the full debit is `gross_amount` (fee is carved from within, not added on top). Example: gross=500, fee=5, net=495, balance=500 → debit 500, credit recipient 495, credit fee account 5. ✓

**EC-E09-04 (Recipient Wallet Suspended at Confirmation Time)** — The recipient's wallet was active at initiation but suspended before confirmation. `LedgerModuleContract::postEntry()` must validate the credit-side account is active and reject. Transfer transitions to `failed` with reason `RECIPIENT_WALLET_SUSPENDED`.

**EC-E09-05 (Fee Rate Change Between Initiation and Confirmation)** — Platform administrator changes fee rate between quote and confirmation. Confirmation endpoint detects `current_rate_bps ≠ fee_rate_bps` on record and returns `409 FEE_MISMATCH` with updated amounts. Transfer remains `pending`.

**EC-E09-06 (Cancel Already Confirmed)** — Customer attempts to cancel a `completed` transfer. Returns `422 TRANSFER_NOT_CANCELLABLE`.

**EC-E09-07 (Recipient Deactivated Between Lookup and Confirmation)** — Recipient's account deactivated after `lookup_token` issued. Re-validation at confirmation detects inactive wallet and fails the transfer with `RECIPIENT_WALLET_SUSPENDED`.

**EC-E09-08 (Currency Mismatch)** — Sender's source pocket is in BWP, recipient's pocket is in USD. System detects mismatch at initiation via resolved ledger refs and returns `422 CURRENCY_MISMATCH`. Cross-currency is E29 scope.

**EC-E09-09 (Pending Transfer Expired)** — Customer creates a pending transfer but does not confirm within TTL. Expiry command transitions to `failed`, emits `Transfer.Failed`. Subsequent confirmation attempt returns `422 TRANSFER_NOT_MODIFIABLE`.

**EC-E09-10 (Lookup Token Reuse)** — Customer attempts to use a `lookup_token` that was already consumed by a prior initiation. Redis key no longer exists. Returns `422 INVALID_LOOKUP_TOKEN`.

---

## TBDs

| ID | Area | Description |
|----|------|-------------|
| TBD-E09-001 | Fee rates | Actual `transfer.fee_rate_bps.{tier}.{currency_code}` values — governance decision |
| TBD-E09-002 | Transfer limits | Actual values for `transfer.min_amount`, `transfer.max_amount`, `transfer.daily_limit` by tier and currency — governance decision |
| TBD-E09-003 | Fee account reference | `transfer.fee_account.{currency_code}` ledger account reference — depends on chart of accounts finalization (E07/E20) |
| TBD-E09-004 | Pending TTL | Default value for `transfer.pending_ttl_minutes` — operational decision |
| TBD-E09-005 | Recipient display privacy | Format of `display_name` in lookup response (full name vs. masked) — privacy/compliance decision |

All TBDs MUST be implemented as configurable platform configuration values. No TBD value MAY be hard-coded.

---

## Assumptions

1. Wallet-to-wallet transfer in E09 is same-currency only. Cross-currency conversion is E29 scope.
2. E09 covers Customer-to-Customer P2P only. Agent-to-customer transfers are deferred to E12/E25.
3. `LedgerModuleContract::postEntry()` (E07) enforces debit-side balance atomicity. E09 relies on this contract.
4. E07's ledger accounts for customer pockets (`customer_pocket` account type) are resolvable via `WalletModuleContract::getPocketLedgerAccountRef()` (established in E06/E08).
5. Fee is calculated as basis points of `gross_amount`. The recipient receives `net_amount = gross_amount − fee_amount`. Fee comes from within the gross amount, not on top of it.
6. `Transfer.Completed` and related events are consumed by E17 for notification delivery. E09 emits them reliably but does not dispatch push notifications or SMS itself.
7. The `TransferRiskService` stub always approves in E09. E18 overrides the service container binding without modifying E09 code.
8. `lookup_token` values are stored in Redis with a 300-second TTL. Redis is required; if unavailable, the lookup endpoint fails closed (503).
9. `auth.step_up_threshold` is seeded as `10000` (minor units) per constitution GAP-027 resolution.
10. Rounding uses half-away-from-zero (PHP `round()` default) for fee calculation. Consistent with E08.

---

## Epic-Level Definition of Done

- All 19 source user stories from the approved 538-story backlog are implemented and passing acceptance criteria.
- Double-entry journal balances for every completed transfer: `sum(debits) = sum(credits)`.
- No transfer can overdraft the sender wallet (CC-E09-001 concurrency test passes).
- No duplicate journal entries for a single transfer (CC-E09-003 concurrency test passes).
- No duplicate daily-limit bypass under concurrent load (CC-E09-002 concurrency test passes).
- All API endpoints documented in OpenAPI; all errors use the platform standard error envelope.
- Permissions seeded and verified: `transfer.initiate`, `transfer.read`, `transfer.admin`.
- `TransferReceipt` is immutable after insert: create-after-insert and delete throw exceptions.
- All domain events emitted and verified in integration tests.
- All audit log entries verified in audit integration test.
- Metrics instrumented and emitted in feature tests.
- `IdentityModuleContract::verifyTransactionPin()` updated to accept `requireStepUp: bool`.
- No unresolved CRITICAL or HIGH defects.
- `specs/009-wallet-to-wallet-transfer/spec.md`, `plan.md`, and `tasks.md` updated to reflect final implementation.

## Clarifications

### Session 2026-09-18

- Q: Which epic number and scope — E09 Wallet-to-Wallet Transfer or E10 Withdrawal? → A: E09 — Wallet-to-Wallet Transfer (confirmed by user)
- Q: Fee model — gross/net (E08-aligned: fee deducted from gross, recipient gets less) or net/gross (M-Pesa-aligned: sender pays extra)? → A: Gross/net model, consistent with E08. Fee from within gross_amount. (Analysis-aligned decision 2026-09-18)
- Q: Is FR numbering gap acceptable or must it be sequential? → A: Fixed to sequential 001–054 as of 2026-09-18 spec revision.
- Q: Does cancellation free up daily limit headroom? → A: Yes — cancelled and failed transfers are excluded from the daily sum (FR-E09-020).
