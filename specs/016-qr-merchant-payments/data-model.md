# Data Model — E16 QR & Merchant Payments

**Schema**: `qr` (PostgreSQL schema-per-module, isolated per OKI-ARCH-2026-003)

---

## Entities

### qr.qr_codes

Stores both static (persistent) and dynamic (one-time, expiring) QR codes. Each row holds the signed payload and the full lifecycle state.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK | UUID v7 |
| merchant_id | UUID | NOT NULL, INDEX | Cross-module ref to merchant; validated via MerchantReadServiceInterface |
| type | ENUM('static','dynamic') | NOT NULL | |
| label | VARCHAR(100) | NULL | Merchant-supplied display label (e.g., "Counter 1") — not included in signed payload |
| amount | DECIMAL(15,2) | NULL | Pre-set amount for dynamic QRs; NULL for static |
| currency | CHAR(3) | NOT NULL DEFAULT 'BWP' | |
| merchant_ref | VARCHAR(255) | NULL | Optional merchant reference, included in signed payload |
| nonce | UUID | NOT NULL UNIQUE | Guarantees each QR payload is unique |
| payload | TEXT | NOT NULL | Base64URL-encoded signed QR string (scannable) |
| status | ENUM('active','reserved','used','expired','inactive') | NOT NULL DEFAULT 'active' | |
| reserved_by_customer_id | UUID | NULL | Customer holding the current reservation |
| reserved_until | TIMESTAMPTZ | NULL | When the current reservation expires |
| expires_at | TIMESTAMPTZ | NULL | Dynamic QR expiry; NULL for static |
| created_at | TIMESTAMPTZ | NOT NULL | |
| updated_at | TIMESTAMPTZ | NOT NULL | |

**Indexes**:
- `(merchant_id, status)` — list a merchant's QR codes by status
- `(status, reserved_until)` — reservation cleanup job filter
- `(status, expires_at)` — expiry enforcement filter
- `UNIQUE (nonce)` — payload uniqueness guarantee

**Constraints**:
- No uniqueness constraint on `(merchant_id, type, status)` — a merchant may have unlimited active static QRs (FR-E16-015)
- `amount` is NULL iff `type = 'static'`
- `expires_at` is NOT NULL iff `type = 'dynamic'`
- `reserved_by_customer_id` and `reserved_until` are both NULL or both NOT NULL

**State machine**:
```
active ──── confirmation attempt ────► reserved
reserved ── payment fails/abandons ──► active
reserved ── reservation TTL expires ─► active  (also cleaned up by background job)
reserved ── payment completes ────────► used        (dynamic only)
active ──── expires_at passes ────────► expired     (dynamic only)
active ──── merchant deactivates ──────► inactive
```

---

### qr.qr_payment_transactions

Records every QR payment attempt. Created in `initiated` status before any money moves, capturing the customer's confirmed intent.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK | UUID v7 |
| idempotency_key | VARCHAR(255) | NOT NULL UNIQUE | Prevents duplicate payments (FR-E16-006) |
| correlation_id | UUID | NOT NULL, INDEX | Request tracing |
| qr_code_id | UUID | NOT NULL, FK → qr.qr_codes | |
| customer_wallet_id | UUID | NOT NULL, INDEX | Cross-module ref to wallet |
| merchant_wallet_id | UUID | NOT NULL | Cross-module ref to wallet |
| amount | DECIMAL(15,2) | NOT NULL | Principal paid to merchant |
| fee | DECIMAL(15,2) | NOT NULL | Platform fee |
| currency | CHAR(3) | NOT NULL | |
| status | ENUM('initiated','completed','failed') | NOT NULL DEFAULT 'initiated' | |
| journal_id | UUID | NULL | Set on successful ledger journal post |
| failure_reason | VARCHAR(255) | NULL | Machine-readable failure code |
| step_up_verified | BOOLEAN | NOT NULL DEFAULT false | Whether step-up auth was required and verified |
| created_at | TIMESTAMPTZ | NOT NULL | |
| updated_at | TIMESTAMPTZ | NOT NULL | |

**Indexes**:
- `UNIQUE (idempotency_key)` — idempotency enforcement
- `(customer_wallet_id, status)` — customer payment history
- `(qr_code_id)` — payments per QR code
- `(correlation_id)` — trace lookup

---

### qr.qr_payment_receipts

Immutable record generated on payment completion. Never modified after creation (FR-E16-010). Merchant display name is denormalized at write time so receipts remain accurate even if the merchant later changes their name.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| id | UUID | PK | UUID v7 |
| receipt_ref | VARCHAR(50) | NOT NULL UNIQUE | Human-readable reference (format: `QRPMT-YYYYMMDD-XXXXX`) |
| qr_payment_transaction_id | UUID | NOT NULL UNIQUE, FK → qr.qr_payment_transactions | One receipt per completed transaction |
| merchant_display_name | VARCHAR(255) | NOT NULL | Snapshot of merchant name at payment time |
| merchant_ref | VARCHAR(255) | NULL | Optional merchant reference from the QR code |
| amount | DECIMAL(15,2) | NOT NULL | Principal |
| fee | DECIMAL(15,2) | NOT NULL | Platform fee |
| total_debited | DECIMAL(15,2) | NOT NULL | amount + fee (= customer's wallet debit) |
| currency | CHAR(3) | NOT NULL | |
| issued_at | TIMESTAMPTZ | NOT NULL | |
| created_at | TIMESTAMPTZ | NOT NULL | |

**Constraints**:
- No UPDATE or DELETE permitted (enforced at model/service layer; no `fillable` columns after creation)
- `total_debited = amount + fee` (application-enforced; also checked by reconciliation)

---

## Ledger Journal Structure

Each QR payment posts a **triple-leg balanced journal** to the ledger (E07):

| Leg | Account | Direction | Amount |
|-----|---------|-----------|--------|
| 1 | Customer wallet | Debit | principal + fee |
| 2 | Merchant wallet | Credit | principal |
| 3 | Platform fee wallet | Credit | fee |

**Invariant**: Sum of debits = Sum of credits (enforced by LedgerService).

---

## Cross-Module References

| Field | In table | References | Access pattern |
|-------|----------|------------|----------------|
| merchant_id | qr_codes | Merchant module | Read via `MerchantReadServiceInterface` |
| customer_wallet_id | qr_payment_transactions | wallet.wallets | Read/balance check via `WalletServiceInterface` |
| merchant_wallet_id | qr_payment_transactions | wallet.wallets | Status check via `WalletServiceInterface` |
| journal_id | qr_payment_transactions | ledger.journals | Written by `LedgerServiceInterface` |

All cross-module reads use service interfaces — no direct cross-schema SQL joins from the QR module.

---

## Configuration Keys

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `qr.signing_key` | string | (required) | HMAC-SHA256 signing secret; externally managed |
| `qr.dynamic_expiry_seconds` | int | 300 | Dynamic QR TTL (5 minutes) |
| `qr.reservation_ttl_seconds` | int | 120 | How long a `reserved` QR is held (2 minutes) |
| `auth.step_up_threshold` | int | 10000 | BWP threshold above which step-up is required |
| `auth.step_up_threshold_currency` | string | BWP | Currency for threshold comparison |
| `platform.fee_wallet_id` | UUID | (required) | Platform fee collection wallet |
| `qr.payment.fee_type` | string | flat | `flat` or `percent` |
| `qr.payment.fee_value` | decimal | 0.00 | Fee amount or rate |
