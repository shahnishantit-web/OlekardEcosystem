# Data Model — E19 Admin Portal

E19 introduces 2 new entities in the `admin` schema. All other entities used by admin endpoints (User, Wallet, Transaction, etc.) are owned by their respective domain modules.

---

## Entity 1: AdminAction

**Purpose:** Immutable audit log of every state-changing action taken by an admin user through the Admin Portal. Append-only — no UPDATE or DELETE after insert.

**Table:** `admin.admin_actions`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | UUID | PK, DEFAULT gen_random_uuid() | UUID v7 |
| `action_type` | VARCHAR(50) | NOT NULL | See `AdminActionType` enum |
| `actor_id` | UUID | NOT NULL | Admin user who performed the action |
| `subject_type` | VARCHAR(30) | NOT NULL | `user` \| `wallet` \| `transaction` \| `session` |
| `subject_id` | UUID | NOT NULL | ID of the affected entity |
| `metadata` | JSONB | NOT NULL DEFAULT '{}' | Action-specific payload (reason, old/new values, etc.) |
| `reason` | TEXT | NULLABLE | Human-readable justification (required for financial actions) |
| `ip_address` | INET | NULLABLE | Admin's request IP |
| `user_agent` | TEXT | NULLABLE | Admin's browser user agent |
| `correlation_id` | VARCHAR(128) | NULLABLE | Propagated request correlation ID |
| `performed_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | Timestamp of action |

**Indexes:**
- `(actor_id, performed_at DESC)`
- `(subject_type, subject_id, performed_at DESC)`
- `(action_type, performed_at DESC)`

**Model constraints:**
- `booted()` override throws `\RuntimeException` on `updating` and `deleting` events (append-only)
- `action_type` cast to `AdminActionType` enum

**`AdminActionType` enum cases:**
`suspend_user`, `reactivate_user`, `reset_auth`, `terminate_session`, `freeze_wallet`, `unfreeze_wallet`, `wallet_adjustment_created`, `wallet_adjustment_approved`, `wallet_adjustment_rejected`, `transaction_reversed`, `transaction_refunded`, `transactions_exported`

---

## Entity 2: AdminWalletAdjustment

**Purpose:** Tracks maker-checker controlled wallet balance adjustments. The maker creates the record; the checker approves or rejects. Only after approval does the service post a double-entry journal via E07 LedgerService.

**Table:** `admin.admin_wallet_adjustments`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | UUID | PK, DEFAULT gen_random_uuid() | UUID v7 |
| `idempotency_key` | VARCHAR(128) | NOT NULL UNIQUE | Client-supplied idempotency key |
| `wallet_id` | UUID | NOT NULL | Target wallet (FK: wallets.id conceptually; no hard FK across schemas) |
| `adjustment_type` | VARCHAR(10) | NOT NULL, CHECK IN ('credit','debit') | Direction of adjustment |
| `amount` | NUMERIC(18,4) | NOT NULL, CHECK > 0 | Adjustment amount (BCMATH precision) |
| `currency` | CHAR(3) | NOT NULL | ISO 4217 currency code |
| `reason` | TEXT | NOT NULL | Mandatory business justification |
| `status` | VARCHAR(20) | NOT NULL DEFAULT 'pending_approval' | See `AdjustmentStatus` enum |
| `maker_id` | UUID | NOT NULL | Admin user who created the adjustment |
| `checker_id` | UUID | NULLABLE | Admin user who approved/rejected (NULL until reviewed) |
| `approved_at` | TIMESTAMPTZ | NULLABLE | Set when checker approves |
| `rejected_at` | TIMESTAMPTZ | NULLABLE | Set when checker rejects |
| `rejection_reason` | TEXT | NULLABLE | Required when rejected |
| `posted_at` | TIMESTAMPTZ | NULLABLE | Set when journal entry confirmed in E07 |
| `ledger_transaction_id` | UUID | NULLABLE | E07 journal transaction ID after posting |
| `created_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |
| `updated_at` | TIMESTAMPTZ | NOT NULL DEFAULT NOW() | |

**Constraints:**
- `CONSTRAINT maker_checker_differ CHECK (checker_id IS NULL OR maker_id != checker_id)` — enforces maker ≠ checker at DB level
- `CONSTRAINT adjustment_status CHECK (status IN ('pending_approval','approved','rejected','posted'))`
- UNIQUE on `idempotency_key`

**Indexes:**
- `(wallet_id, status, created_at DESC)`
- `(maker_id, created_at DESC)`
- `(status, created_at DESC)` — for pending-approval queue

**State machine:**

```
pending_approval ──approve()──► approved ──postJournal()──► posted
pending_approval ──reject()───► rejected
```

- `approve()`: checker_id set; approved_at set; status → approved; triggers LedgerService::postJournal()
- `postJournal() success`: posted_at set; ledger_transaction_id set; status → posted
- `reject()`: checker_id set; rejected_at set; rejection_reason set; status → rejected
- Terminal states: `posted`, `rejected` — no further transitions allowed

**`AdjustmentStatus` enum cases:** `pending_approval`, `approved`, `rejected`, `posted`

---

## Entities Consumed (owned by other modules)

| Entity | Owner Module | Used In Admin Endpoint |
|--------|-------------|----------------------|
| `User` / `UserProfile` | E01 / E02 | US-ADMIN-009–013: search, view, suspend, reactivate, reset-auth |
| `DeviceSession` | E03 | US-ADMIN-014: view and terminate user sessions |
| `Wallet` | E06 | US-ADMIN-015–018: search, view, freeze, unfreeze |
| `LedgerEntry` | E07 | US-ADMIN-019: view wallet ledger |
| `Transaction` | E11 | US-ADMIN-022–027: search, view, reverse, refund, export |
| `AccountHold` | E18 | US-ADMIN-017–018: delegated to AccountHoldService |
| `RiskAlert` | E18 | US-ADMIN-008: risk alerts in dashboard |

These entities are accessed exclusively through their module's published contract interfaces — the Admin module never queries their tables directly.
