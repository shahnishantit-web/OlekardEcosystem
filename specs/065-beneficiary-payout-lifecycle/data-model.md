# Data Model: P18 — Beneficiary Verification and Payout Lifecycle

**Schema**: `payment` (existing Payment module schema)

**Migration sequence starts at**: `2026_10_25_000019` (after P17's `000018`)

---

## Entity: PayoutBeneficiary

Represents a verified destination account for fund disbursement.

**Table**: `payment.payout_beneficiaries`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `uuid` (v7) | NO | Primary key |
| `organisation_id` | `uuid` | NO | Owning organisation |
| `account_type` | `varchar(50)` | NO | Enum: `bank_account`, `mobile_money`, `wallet` |
| `account_number` | `text` | NO | Destination account number — encrypted at rest |
| `bank_code` | `varchar(50)` | YES | Bank code for `bank_account` type |
| `bank_name` | `varchar(200)` | YES | Human-readable bank name |
| `account_holder_name` | `varchar(200)` | NO | Legal name of the account holder |
| `currency_code` | `char(3)` | NO | ISO-4217 |
| `status` | `varchar(30)` | NO | Enum: `pending_verification`, `verified`, `suspended`, `rejected`; DEFAULT `pending_verification` |
| `verified_at` | `timestamptz` | YES | When verification was last confirmed |
| `verification_reference` | `varchar(200)` | YES | External reference from KYC/verification service |
| `created_by` | `uuid` | NO | Actor who registered the beneficiary |
| `created_at` | `timestamptz` | NO | |
| `updated_at` | `timestamptz` | NO | |

**Indexes**: `(organisation_id)`, `(status)`, `(account_type)`, `(created_at)`

**State Transitions** (`PayoutBeneficiaryStatus` enum):
```
pending_verification → verified
                     → rejected
verified → suspended
suspended → verified (re-verified)
```

**Security**: `account_number` uses Laravel's built-in encrypted cast (`$casts = ['account_number' => 'encrypted']`). Plaintext never persisted or logged.

---

## Entity: PayoutRequest

Lifecycle record for a single disbursement request, from initiation through settlement.

**Table**: `payment.payout_requests`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `uuid` (v7) | NO | Primary key |
| `idempotency_key` | `varchar(200)` | NO | Caller-provided idempotency key — UNIQUE INDEX |
| `fund_reference_id` | `uuid` | NO | The fund/purpose being disbursed (maps to domain module record) |
| `purpose_type` | `varchar(100)` | NO | Domain module purpose type (e.g. `funeral_fund`, `raffle_prize`) |
| `organisation_id` | `uuid` | NO | Owning organisation |
| `beneficiary_id` | `uuid` | NO | FK → `payout_beneficiaries.id` |
| `currency_code` | `char(3)` | NO | |
| `requested_amount_minor` | `bigint` | NO | Amount requested by caller (informational; not authoritative) |
| `computed_payable_minor` | `bigint` | NO | Server-derived payable balance at time of request (authoritative) |
| `status` | `varchar(30)` | NO | Enum (see below); DEFAULT `pending_verification` |
| `operation_id` | `char(64)` | YES | Stable SHA-256 payout operation ID (set on first reservation) |
| `provider_code` | `varchar(50)` | YES | Payout adapter provider (set on execution — BLOCKED AD-14) |
| `ledger_entry_id` | `varchar(200)` | YES | From `LedgerModuleContract::postEntry()` (set on settlement — BLOCKED AD-10) |
| `created_by` | `uuid` | NO | Actor who initiated |
| `settled_at` | `timestamptz` | YES | When the payout was confirmed settled |
| `created_at` | `timestamptz` | NO | |
| `updated_at` | `timestamptz` | NO | |

**Indexes**: UNIQUE `(idempotency_key)`, `(fund_reference_id)`, `(organisation_id)`, `(beneficiary_id)`, `(status)`, `(created_at)`

**State Transitions** (`PayoutRequestStatus` enum):
```
pending_verification → awaiting_approval     (beneficiary verified + balance computed)
awaiting_approval → reserved                 (reservation created by maker)
reserved → approved                          (checker approves)
reserved → cancelled                         (maker cancels before approval)
approved → executing                         (execution submitted — BLOCKED AD-14)
approved → cancelled                         (admin cancels approved reservation)
executing → pending_settlement               (adapter returned pending/unknown)
executing → settled                          (adapter returned succeeded + ledger posted — BLOCKED AD-10)
executing → failed                           (adapter terminal failure)
executing → manual_review                    (all retries exhausted)
pending_settlement → settled                 (provider evidence confirmed + ledger posted)
pending_settlement → manual_review           (evidence timeout exceeded)
failed → awaiting_approval                   (admin retries from scratch)
manual_review → awaiting_approval            (operator resolves and resets)
```

---

## Entity: PayoutReservation

The hold placed against the custody balance, with maker/checker approval tracking.

**Table**: `payment.payout_reservations`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `uuid` (v7) | NO | Primary key |
| `payout_request_id` | `uuid` | NO | FK → `payout_requests.id` — UNIQUE (one active reservation per request) |
| `reserved_amount_minor` | `bigint` | NO | Amount reserved against the custody balance |
| `currency_code` | `char(3)` | NO | |
| `custody_wallet_id` | `uuid` | NO | Wallet/pocket that holds the custody funds |
| `status` | `varchar(30)` | NO | Enum: `awaiting_approval`, `approved`, `invalidated`, `rejected`, `expired`, `released` |
| `maker_id` | `uuid` | NO | Actor who created the reservation |
| `approver_id` | `uuid` | YES | Actor who approved (must differ from `maker_id`) |
| `approved_at` | `timestamptz` | YES | When approval was granted |
| `rejection_reason` | `text` | YES | Populated on rejection |
| `expires_at` | `timestamptz` | NO | Auto-expiry timestamp (configurable default: 48 hours) |
| `amount_hash` | `char(64)` | NO | SHA-256 of `"{reserved_amount_minor}\|{beneficiary_id}"` at approval time |
| `created_at` | `timestamptz` | NO | |
| `updated_at` | `timestamptz` | NO | |

**Indexes**: UNIQUE `(payout_request_id)`, `(maker_id)`, `(approver_id)`, `(status)`, `(expires_at)`

**Constraint**: `approver_id != maker_id` — enforced at service layer (not DB constraint); violation throws `PayoutSelfApprovalException`.

**Invalidation trigger**: When `payout_request.beneficiary_id` or `reserved_amount_minor` changes after `status = 'approved'`, `amount_hash` is recomputed and compared to stored value. If different → status reset to `awaiting_approval`, `approver_id` and `approved_at` cleared, audit record written.

---

## Entity: PayoutAttempt

**[STUB — BLOCKED AD-14]** — empty stub table created by migration; service layer not implemented until AD-14 is resolved.

Records a single execution attempt via the payout adapter.

**Table**: `payment.payout_attempts`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `uuid` (v7) | NO | Primary key |
| `payout_request_id` | `uuid` | NO | FK → `payout_requests.id` |
| `operation_id` | `char(64)` | NO | Stable operation ID (same across retries) |
| `provider_code` | `varchar(50)` | NO | Payout adapter provider |
| `provider_reference` | `varchar(200)` | YES | Provider's own reference (set after submission) |
| `status` | `varchar(30)` | NO | Enum: `submitted`, `pending`, `succeeded`, `failed`, `unknown` |
| `request_payload_hash` | `char(64)` | NO | SHA-256 of submission payload (for audit) |
| `response_code` | `varchar(50)` | YES | Provider response code |
| `response_message` | `text` | YES | Provider response message |
| `query_attempts` | `integer` | NO | Count of status query retries; DEFAULT 0 |
| `submitted_at` | `timestamptz` | NO | |
| `completed_at` | `timestamptz` | YES | Set on terminal status |
| `created_at` | `timestamptz` | NO | |
| `updated_at` | `timestamptz` | NO | |

**Indexes**: `(payout_request_id)`, UNIQUE `(operation_id)`, `(status)`, `(submitted_at)`

---

## Entity: PayoutSettlementRecord

**[STUB — BLOCKED AD-10, LC14]** — empty stub table created by migration; service layer not implemented until AD-10/LC14 are resolved.

Reconciliation of provider evidence against a payout attempt.

**Table**: `payment.payout_settlement_records`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `id` | `uuid` (v7) | NO | Primary key |
| `payout_attempt_id` | `uuid` | NO | FK → `payout_attempts.id` — UNIQUE |
| `expected_amount_minor` | `bigint` | NO | Amount from the payout reservation |
| `provider_confirmed_amount_minor` | `bigint` | YES | Amount the provider confirms was disbursed |
| `currency_code` | `char(3)` | NO | |
| `reconciliation_status` | `varchar(30)` | NO | Enum: `pending`, `matched`, `discrepancy`, `exception_raised`; DEFAULT `pending` |
| `discrepancy_amount_minor` | `bigint` | YES | `abs(expected - confirmed)` if discrepancy |
| `exception_id` | `uuid` | YES | FK → `reconciliation_exceptions.id` (if exception raised) |
| `ledger_entry_id` | `varchar(200)` | YES | From `LedgerModuleContract::postEntry()` |
| `provider_evidence_reference` | `varchar(200)` | YES | Provider's evidence/confirmation reference |
| `evidence_received_at` | `timestamptz` | YES | |
| `created_at` | `timestamptz` | NO | |
| `updated_at` | `timestamptz` | NO | |

**Indexes**: UNIQUE `(payout_attempt_id)`, `(reconciliation_status)`, `(exception_id)`

---

## Enum Definitions

### PayoutRequestStatus
`pending_verification | awaiting_approval | reserved | approved | executing | pending_settlement | settled | failed | manual_review | cancelled`

### PayoutReservationStatus
`awaiting_approval | approved | invalidated | rejected | expired | released`

### PayoutBeneficiaryStatus
`pending_verification | verified | suspended | rejected`

### PayoutAttemptStatus
`submitted | pending | succeeded | failed | unknown`

### PayoutSettlementReconciliationStatus
`pending | matched | discrepancy | exception_raised`

### ReconciliationExceptionType (EXTENDED — 2 new values)
- `payout_amount_discrepancy` — provider confirms different amount than submitted
- `payout_missing_evidence` — payout attempt timed out with no provider evidence

---

## Value Objects

### PayableBalance
Not persisted — computed on demand by `PayoutBalanceService`.

| Field | Type | Description |
|-------|------|-------------|
| `fund_reference_id` | `string` (UUID) | Fund being queried |
| `currency_code` | `string` | |
| `custody_balance_minor` | `int` | Raw custody pocket balance from WalletModuleContract |
| `confirmed_refunds_minor` | `int` | Deducted confirmed refunds |
| `open_dispute_holds_minor` | `int` | Deducted open dispute holds |
| `existing_reservations_minor` | `int` | Deducted existing active reservations |
| `payable_minor` | `int` | `max(0, custody - refunds - holds - reservations)` |
| `computed_at` | `Carbon` | Point-in-time snapshot |

### PayoutOperationId
Not persisted directly — stored as `payout_requests.operation_id`.

`PayoutOperationId::fromReservation(string $reservationId): string`
= `bin2hex(hash('sha256', "payout:{$reservationId}", true))` → 64-char hex

---

## Entity Relationships

```
PayoutBeneficiary ──< PayoutRequest ──1:1── PayoutReservation
                                    ──< PayoutAttempt ──1:1── PayoutSettlementRecord
                                                                  └── exception_id → ReconciliationException
```

---

## Migration Order

1. `2026_10_25_000019_create_payout_beneficiaries_table`
2. `2026_10_25_000020_create_payout_requests_table` (FK → beneficiaries)
3. `2026_10_25_000021_create_payout_reservations_table` (FK → requests)
4. `2026_10_25_000022_create_payout_attempts_table` (FK → requests) — **STUB**
5. `2026_10_25_000023_create_payout_settlement_records_table` (FK → attempts, reconciliation_exceptions) — **STUB** — also ALTER `reconciliation_exceptions` CHECK constraint to add `payout_amount_discrepancy` and `payout_missing_evidence`

---

## Permissions (new — to add in permission seeder)

| Permission | Scope | Description |
|---|---|---|
| `payment.payout.view` | Admin | View payout requests and beneficiaries |
| `payment.payout.initiate` | Admin | Initiate a payout request (maker) |
| `payment.payout.approve` | Admin | Approve a payout reservation (checker — cannot be same as maker) |
| `payment.payout.execute` | Admin | Trigger payout execution (BLOCKED AD-14) |
| `payment.payout.manage_beneficiaries` | Admin | Register and verify beneficiaries |
