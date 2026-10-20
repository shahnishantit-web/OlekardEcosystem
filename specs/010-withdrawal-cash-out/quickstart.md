# Quickstart Validation Guide — E10 Withdrawal / Cash-Out

**Feature**: `010-withdrawal-cash-out` | **Created**: 2026-09-18

This guide describes runnable end-to-end scenarios for validating E10. For data model details see [`data-model.md`](data-model.md). For API contracts see [`contracts/api.md`](contracts/api.md).

---

## Prerequisites

1. Docker stack running (`docker compose up -d`)
2. Migrations applied (`php artisan migrate`)
3. Configuration seeded (`php artisan db:seed --class=WithdrawalConfigurationStubsSeeder`)
4. Customer with active wallet + BWP pocket (use E06 seeded test customer)
5. Customer's wallet has sufficient available balance (seed via E08 or direct DB insert)
6. Customer has transaction PIN set (use E09 test fixtures)
7. Laravel Horizon running (`php artisan horizon`)

---

## Scenario 1 — Happy Path: Mobile Money Withdrawal (no fee)

### Goal
Verify the full flow from initiation through provider callback to completion with zero fee.

### Steps

**1. Initiate withdrawal**
```http
POST /api/v1/withdrawals
Authorization: Bearer {customer_token}
Idempotency-Key: {uuid}
Content-Type: application/json

{
  "wallet_id": "{customer_wallet_id}",
  "currency_code": "BWP",
  "channel": "mobile_money",
  "gross_amount": 50000,
  "payout_destination": {
    "phone_number": "+26771234567",
    "operator_code": "mascom"
  }
}
```
**Expected**: `201`, `status = pending`, `fee_amount = 0`, `net_payout_amount = 50000`

**2. Confirm withdrawal**
```http
POST /api/v1/withdrawals/{id}/confirm
Authorization: Bearer {customer_token}
Idempotency-Key: {uuid}
Content-Type: application/json

{ "transaction_pin": "password", "quoted_fee_amount": 0 }
```
**Expected**: `200`, `status = processing`, `wallet_hold_id` set

**3. Verify hold was created**
```sql
SELECT * FROM wallet.wallet_holds WHERE transaction_reference = '{transaction_reference}';
-- status should be 'active', amount = 50000
```

**4. Verify available balance reduced**
```http
GET /api/v1/wallets/{wallet_id}/pockets/BWP
-- available_balance should be original - 50000
```

**5. Simulate provider success callback (MockPayoutProviderAdapter)**
```http
POST /api/v1/withdrawals/callbacks/mock
Content-Type: application/json
X-Signature: mock-valid-sig

{
  "provider_reference_id": "mock-payout-{withdrawal_id}",
  "status": "success",
  "amount": 50000,
  "currency": "BWP"
}
```
**Expected**: `200 { "status": "acknowledged" }`

**6. Verify completion**
```http
GET /api/v1/withdrawals/{id}
-- status should be 'completed', ledger_journal_id set, completed_at set
```

**7. Verify ledger debit**
```sql
SELECT * FROM ledger.journal_lines WHERE journal_id = '{ledger_journal_id}';
-- Should have 2 lines: debit customer pocket 50000, credit payout transit 50000
-- SUM(debit) = SUM(credit) = 50000
```

**8. Verify hold released**
```sql
SELECT status FROM wallet.wallet_holds WHERE id = '{wallet_hold_id}';
-- status = 'released'
```

**9. Verify receipt**
```http
GET /api/v1/withdrawals/{id}/receipt
-- receipt_reference, gross_amount 50000, fee_amount 0, net_payout_amount 50000
```

---

## Scenario 2 — Fee Deducted (non-zero fee rate)

### Goal
Verify 3-line balanced journal when `withdrawal.fee.mobile_money.BWP` > 0.

### Setup
```sql
UPDATE platform.configuration_values
SET value = '150'
WHERE key = 'withdrawal.fee.mobile_money.BWP';
```

### Steps
1. Initiate withdrawal for `gross_amount = 100000`
   - **Expected**: `fee_amount = 150`, `net_payout_amount = 99850`
2. Confirm withdrawal (provide `quoted_fee_amount: 150`)
3. Simulate success callback
4. Verify 3-line journal:
   ```sql
   SELECT direction, amount FROM ledger.journal_lines WHERE journal_id = '{journal_id}';
   -- debit customer pocket 100000
   -- credit payout transit 99850
   -- credit fee revenue 150
   -- SUM(debit) = SUM(credit) = 100000
   ```

### Cleanup
```sql
UPDATE platform.configuration_values SET value = '0' WHERE key = 'withdrawal.fee.mobile_money.BWP';
```

---

## Scenario 3 — Wrong PIN (stays pending)

### Goal
Verify that incorrect PIN does not fail the transaction and that no hold is created.

### Steps
1. Initiate withdrawal
2. Confirm with wrong PIN: `{ "transaction_pin": "wrongpin", "quoted_fee_amount": 0 }`
   - **Expected**: `422 INVALID_PIN`
3. Verify transaction is still `pending`:
   ```http
   GET /api/v1/withdrawals/{id}
   -- status = 'pending'
   ```
4. Verify no hold was created:
   ```sql
   SELECT COUNT(*) FROM wallet.wallet_holds WHERE transaction_reference = '{ref}';
   -- COUNT = 0
   ```

---

## Scenario 4 — Fee Changed Between Initiation and Confirmation

### Goal
Verify `409 FEE_CHANGED` when fee rate changes after initiation.

### Steps
1. Initiate withdrawal — note `fee_amount = 0`
2. Change fee rate:
   ```sql
   UPDATE platform.configuration_values SET value = '200' WHERE key = 'withdrawal.fee.mobile_money.BWP';
   ```
3. Confirm with `quoted_fee_amount: 0`
   - **Expected**: `409 FEE_CHANGED` with `updated_fee_amount` in details
4. Verify transaction still `pending`

### Cleanup
```sql
UPDATE platform.configuration_values SET value = '0' WHERE key = 'withdrawal.fee.mobile_money.BWP';
```

---

## Scenario 5 — Insufficient Balance at Confirmation (Race Condition)

### Goal
Verify balance is re-checked at confirmation time (not just at initiation).

### Steps
1. Initiate withdrawal for full available balance (e.g., 100000)
2. Between initiation and confirmation, drain the balance by another means (direct DB update or concurrent withdrawal)
3. Confirm the original withdrawal
   - **Expected**: `422 INSUFFICIENT_BALANCE`
4. Verify no hold was created; transaction remains `pending`

---

## Scenario 6 — Failed Payout (Hold Released)

### Goal
Verify that a provider failure callback releases the hold and restores available balance.

### Steps
1. Initiate + confirm withdrawal (hold created, status = `processing`)
2. Note customer's available balance (should be original - gross_amount)
3. Send failure callback:
   ```http
   POST /api/v1/withdrawals/callbacks/mock
   { "provider_reference_id": "mock-payout-{id}", "status": "failure", "amount": 50000, "currency": "BWP" }
   ```
   **Expected**: `200 acknowledged`
4. Verify:
   - `status = failed`
   - No `ledger_journal_id` (no debit posted)
   - Available balance restored to original value
   - `wallet.wallet_holds.status = released`
5. Verify no receipt:
   ```sql
   SELECT COUNT(*) FROM withdrawal.withdrawal_receipts WHERE withdrawal_transaction_id = '{id}';
   -- COUNT = 0
   ```

---

## Scenario 7 — Cancellation (Pending Only)

### Goal
Verify cancellation before confirmation; verify `processing` can't be cancelled.

### Steps

**Cancel pending:**
1. Initiate withdrawal
2. Cancel: `POST /api/v1/withdrawals/{id}/cancel`
   - **Expected**: `200 status = cancelled`
3. Verify no hold (confirm was never called)

**Attempt cancel processing:**
1. Initiate + confirm withdrawal (hold created)
2. Cancel: `POST /api/v1/withdrawals/{id}/cancel`
   - **Expected**: `422 TRANSACTION_NOT_CANCELLABLE`
3. Verify status still `processing`

---

## Scenario 8 — Expiry Command

### Goal
Verify `withdrawal:expire-stale` releases stuck holds and transitions to failed.

### Setup
```sql
-- Force a processing transaction to be old
UPDATE withdrawal.withdrawal_transactions
SET created_at = created_at - INTERVAL '2 hours'
WHERE id = '{processing_transaction_id}';
```

### Steps
1. Run expiry command: `php artisan withdrawal:expire-stale`
2. Verify:
   - `status = failed`, `failure_reason = processing_timeout`
   - `wallet.wallet_holds.status = released`
   - No ledger entry
   - Audit event `withdrawal.transaction.expired` recorded

**Dry-run test**:
```
php artisan withdrawal:expire-stale --dry-run
```
Should report count without changing any records.

---

## Scenario 9 — Idempotent Callbacks

### Goal
Verify duplicate provider success callback does not double-debit.

### Steps
1. Complete a full withdrawal (success callback already processed)
2. Send the same success callback again:
   - **Expected**: `200 acknowledged`
3. Verify: exactly one journal entry (idempotency_key `withdrawal_complete:{id}` has exactly one match):
   ```sql
   SELECT COUNT(*) FROM ledger.journals WHERE idempotency_key = 'withdrawal_complete:{id}';
   -- COUNT = 1
   ```
4. Verify: customer balance debited exactly once

---

## Scenario 10 — Admin Force-Fail

### Goal
Verify admin can force-fail a stuck `processing` transaction.

### Steps
1. Initiate + confirm withdrawal (status = `processing`)
2. Force-fail as admin:
   ```http
   POST /api/v1/admin/withdrawals/{id}/force-fail
   Authorization: Bearer {admin_token}
   ```
   **Expected**: `200 status = failed, failure_reason = admin_force_failed`
3. Verify hold released, audit event recorded:
   ```sql
   SELECT action FROM platform.audit_events WHERE subject_id = '{id}' AND action = 'withdrawal.admin.force_failed';
   ```

---

## Scenario 11 — Concurrent Withdrawals Draining Wallet

### Goal
Verify only one concurrent confirmation succeeds when balance is sufficient for one withdrawal.

### Setup
Customer has exactly 50000 BWP available balance. Two withdrawal transactions initiated for 50000 each.

### Steps
1. Initiate withdrawal A (50000)
2. Initiate withdrawal B (50000) — both succeed (daily limit check at initiation counts both pending)
3. Confirm A and B simultaneously (simulate via sequential close-together requests)
4. One confirmation should succeed (hold created); the other should return `422 INSUFFICIENT_BALANCE`
5. Verify exactly one `wallet.wallet_holds` row with `status = active` for this customer's pocket

---

## Running Tests

After Docker is running:
```
cd wallet-ecosystem/services/api
php artisan test --filter=Withdrawal
php artisan test --filter=ExpireStaleWithdrawals
php artisan test --filter=WithdrawalJournal
php artisan test --filter=WithdrawalReceipt
```

For concurrency tests:
```
php artisan test --filter=WithdrawalConcurrency
```
