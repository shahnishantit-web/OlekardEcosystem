# Quickstart Validation Guide: E13 — Agent Float Management
**Date**: 2026-09-21

This guide describes runnable end-to-end validation scenarios that prove E13 features work correctly. See [data-model.md](data-model.md) for schema details and [contracts/api.yaml](contracts/api.yaml) for request/response shapes.

---

## Prerequisites

1. Running API service (`php artisan serve` or Docker)
2. Database migrations run including E13 migrations
3. An approved agent exists (E12 complete) — note its `agent_id`
4. An agent-linked customer exists with an active wallet — note `customer_id`
5. Admin account with float permissions — use seeded admin `admin@olekard.local` / `Admin123!`
6. Platform config key `ledger.agent_float_funding_account.BWP` seeded with a valid ledger account reference (dev/test seed required for TBD-E13-001)

Run migrations:
```bash
php artisan migrate
```

Seed test data:
```bash
php artisan db:seed --class=AgentFloatDevSeeder
```

---

## Scenario 1 — Create float wallet (US-FLOAT-001)

**Goal**: Admin creates a float wallet for an approved agent.

```bash
POST /v1/agents/{agentId}/float-wallet
Authorization: Bearer {admin_token}
Idempotency-Key: {uuid}
Content-Type: application/json

{ "currency_code": "BWP" }
```

**Expected**: `201 Created` with `data.status = "active"` and `data.balance = 0`.

**Replay test** (same Idempotency-Key):
```bash
POST /v1/agents/{agentId}/float-wallet   # same key
```
**Expected**: `200 OK` with the same wallet — no duplicate created.

**Conflict test**:
```bash
POST /v1/agents/{agentId}/float-wallet   # different key, same agent
```
**Expected**: `409 Conflict`, error code `FLOAT_WALLET_ALREADY_EXISTS`.

---

## Scenario 2 — Fund float wallet (US-FLOAT-003)

**Goal**: Admin funds agent float with BWP 5,000.

```bash
POST /v1/agents/{agentId}/float-wallet/fund
Authorization: Bearer {admin_token}
Idempotency-Key: {uuid}
Content-Type: application/json

{ "amount": 500000, "currency_code": "BWP" }
```

**Expected**: `201 Created`, float transaction with `type = "funding"`, `direction = "debit"`.

**Verify balance**:
```bash
GET /v1/agents/{agentId}/float-wallet
```
**Expected**: `balance = 500000`, `low_float_alert = false`.

**Double-posting test** (same key):
```bash
POST /v1/agents/{agentId}/float-wallet/fund   # same key, amount=500000
```
**Expected**: `200 OK` — balance unchanged (idempotent; no double credit).

---

## Scenario 3 — Cash-in (US-FLOAT-004)

**Goal**: Agent performs BWP 200 cash-in for a linked customer.

```bash
POST /v1/agents/{agentId}/customers/{customerId}/cash-in
Authorization: Bearer {agent_token}
Idempotency-Key: {uuid}
Content-Type: application/json

{ "amount": 20000, "currency_code": "BWP" }
```

**Expected**: `201 Created`, float transaction `type = "cash_in"`, `direction = "credit"` (float decreases).

**Verify agent float**:
```bash
GET /v1/agents/{agentId}/float-wallet
```
**Expected**: `balance = 480000` (was 500000 − 20000).

**Verify customer wallet** (via E06 wallet endpoint):
**Expected**: customer balance increased by 20000.

**Insufficient float test** (agent with BWP 1 balance, BWP 200 cash-in):
**Expected**: `422`, error code `INSUFFICIENT_FLOAT`.

---

## Scenario 4 — Cash-out (US-FLOAT-005)

**Goal**: Agent performs BWP 100 cash-out for a linked customer (customer withdraws cash).

```bash
POST /v1/agents/{agentId}/customers/{customerId}/cash-out
Authorization: Bearer {agent_token}
Idempotency-Key: {uuid}
Content-Type: application/json

{ "amount": 10000, "currency_code": "BWP" }
```

**Expected**: `201 Created`, `type = "cash_out"`, `direction = "debit"` (float increases).

**Verify agent float**:
**Expected**: balance increased by 10000.

**Insufficient customer balance test**:
**Expected**: `422`, error code `INSUFFICIENT_CUSTOMER_BALANCE`.

---

## Scenario 5 — Overdraft prevention (US-FLOAT-006)

**Goal**: Confirm float cannot go below zero under concurrency.

**Setup**: Agent with exactly BWP 200 float.

**Concurrent test** (run simultaneously, e.g. using test helpers or `parallel` flag):
```
Cash-in #1: amount = 15000 (BWP 150)
Cash-in #2: amount = 15000 (BWP 150)
```

**Expected**:
- Exactly one succeeds (`201`), one fails (`422 INSUFFICIENT_FLOAT`)
- Final float balance = BWP 50 (not negative)

**Exact depletion test**: Cash-in for exactly BWP 200 → succeeds, balance = 0.
**Post-depletion test**: Cash-in for BWP 1 when balance = 0 → `422 INSUFFICIENT_FLOAT`.

---

## Scenario 6 — Float limit enforcement (US-FLOAT-007)

**Goal**: Admin sets limits; limits are enforced.

**Set max balance limit**:
```bash
PATCH /v1/agents/{agentId}/float-wallet/limits
Authorization: Bearer {admin_token}
Content-Type: application/json

{ "max_balance": 5000000 }
```

**Expected**: `200 OK` with updated limits.

**Enforce max balance**: Fund request that would push balance above BWP 50,000.
**Expected**: `422 FLOAT_LIMIT_EXCEEDED`.

**Set per-transaction limit**:
```bash
PATCH /v1/agents/{agentId}/float-wallet/limits
{ "per_transaction_limit": 100000 }
```

**Enforce per-tx**: Cash-in for BWP 1,100 (above BWP 1,000 per-tx limit).
**Expected**: `422 TRANSACTION_LIMIT_EXCEEDED`.

---

## Scenario 7 — Low-float alert (US-FLOAT-008)

**Goal**: `FloatLowAlert` event fires when balance drops below threshold; not repeated without recovery.

**Set low-float threshold**:
```bash
PATCH /v1/agents/{agentId}/float-wallet/limits
{ "low_float_threshold": 50000 }
```

**Deplete to threshold**: Perform cash-in transactions until float drops from BWP 5,000 to BWP 499.

**Verify alert emitted**: Check outbox (`platform.outbox_events`) for event `agent_float.float_low_alert` with `agent_id`.

**No duplicate alert**: Perform another cash-in (float drops further from BWP 499 to BWP 400).
**Expected**: Only one `agent_float.float_low_alert` event in outbox for this threshold crossing.

**Recovery**: Fund float back above threshold.
**Verify**: `low_float_alert = false` on balance GET; next depletion fires a new alert.

---

## Scenario 8 — Float transaction history (US-FLOAT-009)

**Goal**: Paginated, filterable history returns correct records.

```bash
GET /v1/agents/{agentId}/float-wallet/transactions?per_page=10&from=2026-09-21
Authorization: Bearer {agent_token}
```

**Expected**: List of transactions with correct `type`, `amount`, `direction`, `reference`, `created_at`.

**Date filter test**: Request with `from=tomorrow` → empty `data` array.

**Type filter test**: `?type=cash_in` → only cash-in records returned.

**Pagination**: `?per_page=2&page=1` → 2 records, `meta.total` = actual count, `meta.pages` correct.

---

## Scenario 9 — Agent lifecycle mirror (FR-E13-021)

**Goal**: Suspending agent auto-suspends float wallet; reactivation restores it.

**Setup**: Agent with active float wallet.

**Suspend agent** (E12 endpoint):
```bash
POST /v1/agents/{agentId}/suspend
```

**Verify float wallet**:
```bash
GET /v1/agents/{agentId}/float-wallet
```
**Expected**: `status = "suspended"`.

**Verify operations blocked**: Cash-in attempt → `422 AGENT_SUSPENDED`.

**Reactivate agent**:
```bash
POST /v1/agents/{agentId}/reactivate
```

**Verify float wallet**:
**Expected**: `status = "active"`, cash-in permitted again.

---

## Running the full test suite

```bash
# Unit tests only
php artisan test --filter=AgentFloat --no-coverage

# Feature tests
php artisan test tests/Feature/Modules/AgentFloat --no-coverage

# Concurrency tests (requires configured test DB)
php artisan test tests/Concurrency/Modules/AgentFloat --no-coverage
```

All tests must pass with 0 failures before marking E13 COMPLETE.
