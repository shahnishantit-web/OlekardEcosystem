# Quickstart Validation Guide — E07 Wallet Ledger

This guide describes the runnable validation scenarios that prove E07 works end-to-end. Each scenario includes prerequisites, the commands to run, and the expected outcomes.

For API shapes, see [`contracts/api-contract.md`](contracts/api-contract.md).
For the internal service interface, see [`contracts/ledger-module-contract.php`](contracts/ledger-module-contract.php).
For entity definitions, see [`data-model.md`](data-model.md).

---

## Prerequisites

1. Docker stack running: `cd wallet-ecosystem && docker compose up -d`
2. Migrations run: `docker compose exec backend php artisan migrate`
3. Dev seeder run: `docker compose exec backend php artisan db:seed --class=DevelopmentSeeder`
4. `wallet.stub_ledger_balance` is NOT set in `phpunit.xml` (FR-E07-045; it must be absent or `false`)
5. Admin Bearer token available (seeded credential: `admin@olekard.local` / `Admin123!`)

---

## Scenario 1 — Post a Balanced Debit Journal (SC-E07-001, SC-E07-002)

**Goal**: Post a debit entry, verify the balance decreases, verify the outbox event was written.

**Setup** (or use an existing pocket `ledger_account_reference`):
```bash
# Register two accounts
POST /v1/ledger/accounts
  Idempotency-Key: ik-reg-001
  Body: { "ledger_account_reference": "ref-customer-001", "account_type": "liability",
          "currency_code": "BWP", "owner_reference": "<pocket-uuid>", "owner_type": "customer_pocket" }

POST /v1/ledger/accounts
  Idempotency-Key: ik-reg-002
  Body: { "ledger_account_reference": "ref-float-001", "account_type": "asset",
          "currency_code": "BWP", "owner_reference": "<float-uuid>", "owner_type": "system_float" }
```

**Seed credit so customer pocket has a positive balance**:
```bash
POST /v1/ledger/entries/credit
  Idempotency-Key: ik-seed-001
  Body: {
    "currency_code": "BWP", "correlation_id": "corr-seed",
    "lines": [
      { "ledger_account_reference": "ref-customer-001", "direction": "credit", "amount": 10000 },
      { "ledger_account_reference": "ref-float-001",    "direction": "debit",  "amount": 10000 }
    ]
  }
```

**Post the debit**:
```bash
POST /v1/ledger/entries/debit
  Idempotency-Key: ik-spend-001
  Body: {
    "currency_code": "BWP", "correlation_id": "corr-spend-001",
    "transaction_reference": "txn-001",
    "description": "Customer spend",
    "lines": [
      { "ledger_account_reference": "ref-customer-001", "direction": "debit",  "amount": 2000 },
      { "ledger_account_reference": "ref-float-001",    "direction": "credit", "amount": 2000 }
    ]
  }
```

**Expected outcomes**:
- HTTP 201; `data.journal_id` is a UUID v7
- `GET /v1/ledger/accounts/ref-customer-001/balance` → `balance: 8000`
- `platform.outbox_events` contains row with `event_type = Ledger.Entry.Posted`, `aggregate_id = <journal_id>`
- `platform.audit_events` contains row with `action = ledger.entry.posted`

---

## Scenario 2 — Idempotent Replay (SC-E07-003, CC-E07-003)

**Goal**: Submitting the same `Idempotency-Key` twice produces exactly one journal.

```bash
# First call (from Scenario 1 — already posted with ik-spend-001)
POST /v1/ledger/entries/debit
  Idempotency-Key: ik-spend-001
  Body: <identical to Scenario 1>
```

**Expected outcomes**:
- HTTP 200 (not 201)
- `data.journal_id` is the same UUID as the first call
- `SELECT COUNT(*) FROM ledger.journals WHERE idempotency_key = 'ik-spend-001'` → `1`
- Balance unchanged (still 8,000 thebe)

---

## Scenario 3 — Unbalanced Journal Rejected (FR-E07-009, US-LEDGER-007)

**Goal**: A journal where SUM(debits) ≠ SUM(credits) is rejected before any write.

```bash
POST /v1/ledger/entries/debit
  Idempotency-Key: ik-unbalanced-001
  Body: {
    "currency_code": "BWP", "correlation_id": "corr-test",
    "lines": [
      { "ledger_account_reference": "ref-customer-001", "direction": "debit",  "amount": 1000 },
      { "ledger_account_reference": "ref-float-001",    "direction": "credit", "amount": 999 }
    ]
  }
```

**Expected outcomes**:
- HTTP 422
- `error.code = "UNBALANCED_JOURNAL"`
- `SELECT COUNT(*) FROM ledger.journals WHERE idempotency_key = 'ik-unbalanced-001'` → `0`
- Outbox event NOT emitted

---

## Scenario 4 — JournalLine Immutability (SC-E07-004, SR-E07-005)

**Goal**: No code path can update or delete a posted journal line.

**Run the PHPUnit test** (validates model-level LogicException):
```bash
docker compose exec backend php artisan test --filter JournalLineImmutabilityTest
```

**Expected outcomes**:
- `JournalLineModel::save()` on an existing record throws `LogicException`
- `JournalLineModel::delete()` throws `LogicException` unconditionally
- Architecture test passes: `php artisan test --filter LedgerBoundaryTest`

---

## Scenario 5 — Reversal (SC-E07-005)

**Goal**: Reversing a journal creates a compensating entry; original is unchanged; net balance is restored.

**Using the journal from Scenario 1** (journal_id = `J1`; balance currently 8,000):

```bash
POST /v1/ledger/entries/{J1}/reverse
  Idempotency-Key: ik-rev-001
  Body: { "description": "Reversal", "correlation_id": "corr-rev-001" }
```

**Expected outcomes**:
- HTTP 201; new journal with `entry_type = reversal`, `reversed_journal_id = J1`
- `GET /v1/ledger/accounts/ref-customer-001/balance` → `10000` (restored to pre-spend balance)
- Original journal `J1` unchanged (no `reversed_journal_id` set on it; self-referential FK is on the reversal journal)
- `platform.audit_events` row with `action = ledger.entry.reversed`

**Attempt a second reversal** (should fail):
```bash
POST /v1/ledger/entries/{J1}/reverse
  Idempotency-Key: ik-rev-002
  Body: { "description": "Should fail", "correlation_id": "corr-rev-002" }
```

**Expected**: HTTP 422 `JOURNAL_ALREADY_REVERSED`

---

## Scenario 6 — Per-Currency Balance Check (FR-E07-036, §9.3)

**Goal**: `ledger:check-balance` verifies balance per currency; does not mix currencies.

```bash
docker compose exec backend php artisan ledger:check-balance
```

**Expected outcomes**:
- Exit code `0` on a clean ledger
- Structured log emitted for each currency summarizing debit/credit totals
- If an imbalance exists: `Ledger.Imbalance.Detected` event in outbox; exit code non-zero; CRITICAL log line

---

## Scenario 7 — Concurrency Test Suite (CC-E07-004, SC-E07-007)

**Goal**: 10 concurrent posts with the same idempotency key produce exactly one journal.

```bash
docker compose exec backend php artisan test tests/Concurrency/Modules/Ledger/
```

**Expected outcomes**:
- `ConcurrentIdempotentPostTest`: exactly 1 journal record; 9 callers receive idempotent replay
- `ConcurrentDistinctPostTest`: all 10 journals committed; balance reflects aggregate; `ledger:check-balance` passes
- `ConcurrentBalanceQueryTest`: no dirty reads observed

---

## Scenario 8 — E06 Integration: Real Balance via LedgerModuleContract (SC-E07-008)

**Goal**: E06's `WalletBalanceService` returns real ledger balances without `wallet.stub_ledger_balance = true`.

**Prerequisite**: E07 `LedgerServiceProvider` binding is live; `WALLET_STUB_LEDGER_BALANCE` env var absent.

```bash
docker compose exec backend php artisan test --filter WalletBalanceServiceTest
```

**Expected outcomes**:
- Test passes without `WALLET_STUB_LEDGER_BALANCE=true` in environment
- `GET /v1/wallets/{id}/pockets/{currency}` returns `ledger_balance` from real E07 `getBalance()` call

---

## Scenario 9 — Multi-Currency Independence (SC-E07-006)

**Goal**: All six currencies post without currency-specific code paths.

For each of `BWP`, `USD`, `EUR`, `ZAR`, `CNY`, `USDT`:
```bash
POST /v1/ledger/accounts  # Register two accounts in the currency
POST /v1/ledger/entries/credit  # Post a credit entry
GET /v1/ledger/accounts/{ref}/balance  # Verify correct balance
```

**Expected outcomes**: Each currency posts and balances independently; no shared-balance pollution across currencies; `ledger:check-balance` shows zero imbalance for all six currencies.

---

## Running the Full Test Suite

```bash
# Unit + Feature + Integration tests
docker compose exec backend php artisan test --filter Ledger

# Architecture boundary test
docker compose exec backend php artisan test tests/Architecture/Modules/LedgerBoundaryTest.php

# Concurrency tests
docker compose exec backend php artisan test tests/Concurrency/Modules/Ledger/

# Static analysis (PHPStan level 8)
docker compose exec backend ./vendor/bin/phpstan analyse app/Modules/Ledger
```

All of the above must pass with zero failures before E07 is marked RELEASE READY.
