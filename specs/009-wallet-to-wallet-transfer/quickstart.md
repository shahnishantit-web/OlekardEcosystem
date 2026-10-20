# Quickstart Validation Guide — E09 Wallet-to-Wallet Transfer

## Prerequisites

- Docker environment running (`wallet-ecosystem/`)
- Seeded customers: at minimum two customers with active wallets and KYC approved (E01–E05 seeds)
- Transaction PIN set for sender customer (E05 seed)
- Platform configuration seeds applied (see plan.md §Platform Configuration Seeds)
- Redis running and reachable
- `LedgerModuleContract` available (E07 complete)
- `WalletModuleContract::getPocketLedgerAccountRef()` available (E06/E08 complete)

---

## Scenario 1: Happy Path — P2P Transfer with Fee

**Goal**: Verify the full transfer lifecycle: lookup → initiate → confirm → completed, with correct balance changes and journal entry.

### Step 1: Authenticate as sender

```http
POST /api/v1/auth/login
{ "email": "sender@example.local", "password": "..." }
→ { "token": "SENDER_TOKEN" }
```

### Step 2: Look up recipient

```http
POST /api/v1/transfers/recipient-lookup
Authorization: Bearer SENDER_TOKEN

{ "phone_number": "+26771234567" }

Expected 200:
{
  "data": {
    "lookup_token": "01JXXXXXXX-uuid",
    "display_name": "Thabo N.",
    "currency_code": "BWP",
    "expires_at": "2026-09-18T12:05:00Z"
  }
}
```

### Step 3: Initiate transfer

```http
POST /api/v1/transfers
Authorization: Bearer SENDER_TOKEN
Idempotency-Key: test-init-001

{
  "lookup_token": "01JXXXXXXX-uuid",
  "wallet_id": "SENDER_WALLET_UUID",
  "gross_amount": 50000,
  "currency_code": "BWP"
}

Expected 201:
{
  "data": {
    "id": "TRANSFER_ID",
    "transfer_reference": "TXF-B3C7QXMPYZ",
    "status": "pending",
    "gross_amount": 50000,
    "fee_amount": 500,
    "net_amount": 49500,
    "currency_code": "BWP",
    "expires_at": "..."
  }
}
```

**Verify**: `transfer_transactions` row exists with `status = pending`.

### Step 4: Confirm with PIN

```http
POST /api/v1/transfers/TRANSFER_ID/confirm
Authorization: Bearer SENDER_TOKEN
Idempotency-Key: test-confirm-001

{ "transaction_pin": "1234" }

Expected 200:
{
  "data": {
    "status": "completed",
    "completed_at": "...",
    "receipt": {
      "receipt_reference": "UUID",
      "gross_amount": 50000,
      "fee_amount": 500,
      "net_amount": 49500
    }
  }
}
```

**Verify**:
- `transfer_transactions.status = completed`
- `transfer_transactions.ledger_journal_id IS NOT NULL`
- `transfer_receipts` row exists with matching amounts
- Sender's pocket balance decreased by 50000
- Recipient's pocket balance increased by 49500
- Platform fee account balance increased by 500
- Ledger journal has 3 balanced lines (sum debits = sum credits = 50000)

---

## Scenario 2: Idempotency on Initiation

**Goal**: Duplicate `Idempotency-Key` on `POST /transfers` returns the original pending transfer without creating a second row.

```http
POST /api/v1/transfers
Idempotency-Key: test-init-001   ← same key as Scenario 1

Expected 201 with the same TRANSFER_ID as Scenario 1.
No new row in transfer_transactions.
```

---

## Scenario 3: Idempotency on Confirmation

**Goal**: Duplicate `Idempotency-Key` on `POST /transfers/{id}/confirm` for an already-completed transfer returns the existing receipt.

```http
POST /api/v1/transfers/TRANSFER_ID/confirm
Idempotency-Key: test-confirm-001   ← same key as Step 4

Expected 200 with the same receipt. No second journal entry.
```

---

## Scenario 4: Insufficient Balance

**Goal**: Sender has less balance than the `gross_amount` requested.

1. Initiate a transfer where `gross_amount` > sender's current balance.

```
Expected 422:
{ "code": "INSUFFICIENT_BALANCE", "message": "..." }
```

No `TransferTransaction` row created.

---

## Scenario 5: FEE_MISMATCH

**Goal**: Fee rate changes between initiation and confirmation.

1. Initiate transfer (gets `fee_rate_bps = 100`, `fee_amount = 500`).
2. As an admin, update `transfer.fee_rate_bps.{tier}.BWP` to `200`.
3. Attempt confirmation.

```
Expected 409:
{
  "code": "FEE_MISMATCH",
  "details": {
    "new_fee_amount": 1000,
    "new_net_amount": 49000,
    "new_fee_rate_bps": 200
  }
}
Transfer status remains "pending".
```

---

## Scenario 6: Self-Transfer Prevention

**Goal**: Sender attempts to look up themselves.

```http
POST /api/v1/transfers/recipient-lookup
{ "phone_number": "+26771111111" }  ← sender's own phone

Expected 422:
{ "code": "SELF_TRANSFER_NOT_ALLOWED" }
```

---

## Scenario 7: Lookup Token Reuse

**Goal**: Lookup token can only be used once.

1. Perform recipient lookup → get `lookup_token`.
2. Initiate transfer with token → `pending` transfer created.
3. Attempt a second initiation with the same `lookup_token`.

```
Expected 422:
{ "code": "INVALID_LOOKUP_TOKEN" }
```

---

## Scenario 8: Cancellation

**Goal**: Sender cancels a pending transfer.

```http
POST /api/v1/transfers/TRANSFER_ID/cancel
Idempotency-Key: test-cancel-001

Expected 200:
{ "data": { "status": "cancelled", "cancelled_at": "..." } }
```

**Verify**: Balances unchanged. No ledger entry. `Transfer.Cancelled` event emitted.

---

## Scenario 9: Concurrent Confirmation Attempt (Concurrency Test)

**Goal**: Two concurrent confirm requests for the same transfer — exactly one succeeds.

1. Create one pending transfer.
2. Fire two simultaneous `POST /v1/transfers/{id}/confirm` requests.
3. Assert: exactly one `200 completed`, one `422 TRANSFER_NOT_MODIFIABLE`.
4. Assert: exactly one ledger journal entry, exactly one receipt.

---

## Scenario 10: Pending Transfer Expiry

**Goal**: A pending transfer that is not confirmed within TTL is expired by the command.

1. Create a pending transfer.
2. Fast-forward time or set `transfer.pending_ttl_minutes = 0` in test.
3. Run `php artisan transfer:expire-pending`.
4. Assert: `status = failed`, `failure_reason = SYSTEM_ERROR`.
5. Assert: `Transfer.Failed` event emitted. Balances unchanged.

---

## Scenario 11: Receipt Retrieval

**Goal**: Both receipt routes work for both sender and recipient.

```http
# By transaction ID
GET /api/v1/transfers/TRANSFER_ID/receipt
Authorization: SENDER_TOKEN or RECIPIENT_TOKEN
Expected 200 with receipt

# By receipt reference
GET /api/v1/transfers/receipts/RECEIPT_REFERENCE_UUID
Authorization: SENDER_TOKEN or RECIPIENT_TOKEN
Expected 200 with receipt

# Unauthorized third party
GET /api/v1/transfers/TRANSFER_ID/receipt
Authorization: THIRD_PARTY_TOKEN
Expected 404
```

---

## Scenario 12: Admin Access

**Goal**: Admin can view all transfers; cannot initiate or confirm.

```http
GET /api/v1/admin/transfers
Authorization: ADMIN_TOKEN
Expected 200 with all transfers, including ledger_journal_id and fee_rate_bps

POST /api/v1/transfers
Authorization: ADMIN_TOKEN
Expected 403 (admin does not have transfer.initiate)
```

---

## Run Commands

```bash
# Run all Transfer module tests
cd wallet-ecosystem/services/api
php artisan test --filter=Transfer

# Run concurrency tests only
php artisan test --filter=TransferConcurrency

# Expire pending transfers manually
php artisan transfer:expire-pending

# Tail feature logs
tail -f storage/logs/laravel.log | grep '"module":"transfer"'
```

## Expected Metrics After Scenario 1

| Metric | Expected |
|--------|----------|
| `transfer.initiated` | 1 (currency=BWP) |
| `transfer.completed` | 1 (currency=BWP, has_fee=true) |
| `transfer.fee.amount` | 500 |
| `transfer.confirmation.duration_ms` | < 500ms under normal load |
