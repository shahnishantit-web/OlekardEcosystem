# API Contracts — E10 Withdrawal / Cash-Out

**Feature**: `010-withdrawal-cash-out` | **Created**: 2026-09-18

All endpoints are under `/api/v1`. Requests and responses use JSON. All timestamps are UTC ISO-8601. All IDs are UUID v7. `Idempotency-Key` header (UUID v7 recommended) is required for all `POST` mutation endpoints except system callbacks.

---

## Common Structures

### Error Envelope
```json
{
  "error": {
    "code": "WITHDRAWAL_NOT_FOUND",
    "message": "The withdrawal transaction was not found.",
    "details": {}
  }
}
```

### Success Envelope
```json
{
  "data": { ... },
  "meta": { "correlation_id": "..." }
}
```

### WithdrawalTransaction Object
```json
{
  "id": "01930000-0000-7000-0000-000000000001",
  "wallet_id": "01930000-0000-7000-0000-000000000002",
  "pocket_currency_code": "BWP",
  "customer_id": "01930000-0000-7000-0000-000000000003",
  "channel": "mobile_money",
  "status": "pending",
  "gross_amount": 50000,
  "fee_amount": 0,
  "net_payout_amount": 50000,
  "fee_rate_bps": 0,
  "payout_destination_masked": "****7890",
  "provider_reference_id": null,
  "failure_reason": null,
  "wallet_hold_id": null,
  "ledger_journal_id": null,
  "transaction_reference": "01930000-0000-7000-0000-000000000004",
  "completed_at": null,
  "failed_at": null,
  "created_at": "2026-09-18T10:00:00Z",
  "updated_at": "2026-09-18T10:00:00Z"
}
```

### WithdrawalReceipt Object
```json
{
  "id": "01930000-0000-7000-0000-000000000010",
  "receipt_reference": "01930000-0000-7000-0000-000000000011",
  "withdrawal_transaction_id": "01930000-0000-7000-0000-000000000001",
  "gross_amount": 50000,
  "fee_amount": 750,
  "net_payout_amount": 49250,
  "currency_code": "BWP",
  "channel": "mobile_money",
  "payout_destination_masked": "****7890",
  "provider_reference_id": "ext-payout-abc123",
  "customer_id": "01930000-0000-7000-0000-000000000003",
  "wallet_id": "01930000-0000-7000-0000-000000000002",
  "ledger_journal_id": "01930000-0000-7000-0000-000000000020",
  "completed_at": "2026-09-18T10:05:00Z"
}
```

---

## Endpoints

### POST /api/v1/withdrawals

**Summary**: Initiate a withdrawal (US-WITHDRAW-001, 002, 003).

**Auth**: Bearer token | **Permission**: `withdrawal.initiate`

**Headers**:
```
Authorization: Bearer {token}
Idempotency-Key: {uuid}
Content-Type: application/json
```

**Request Body**:
```json
{
  "wallet_id": "01930000-0000-7000-0000-000000000002",
  "currency_code": "BWP",
  "channel": "mobile_money",
  "gross_amount": 50000,
  "payout_destination": {
    "phone_number": "+26771234567",
    "operator_code": "mascom"
  }
}
```

For `bank_transfer` channel:
```json
{
  "wallet_id": "...",
  "currency_code": "BWP",
  "channel": "bank_transfer",
  "gross_amount": 50000,
  "payout_destination": {
    "account_number": "1234567890",
    "bank_code": "BARCBWGX",
    "account_name": "John Doe"
  }
}
```

**Response 201 — Created**:
```json
{
  "data": {
    "id": "...",
    "status": "pending",
    "channel": "mobile_money",
    "gross_amount": 50000,
    "fee_amount": 0,
    "net_payout_amount": 50000,
    "fee_rate_bps": 0,
    "payout_destination_masked": "****4567",
    "limits": {
      "min_amount": 100,
      "max_amount": 1000000,
      "daily_remaining": 5000000
    },
    "created_at": "...",
    "updated_at": "..."
  }
}
```

**Error Responses**:
| Status | Code                          | Condition                                  |
|--------|-------------------------------|-------------------------------------------|
| 400    | `MISSING_IDEMPOTENCY_KEY`     | `Idempotency-Key` header absent            |
| 401    | —                             | Not authenticated                          |
| 403    | —                             | Missing `withdrawal.initiate` permission   |
| 404    | `WALLET_NOT_FOUND`            | wallet_id doesn't exist or not owned       |
| 422    | `INVALID_AMOUNT`              | gross_amount ≤ 0 or non-integer            |
| 422    | `UNSUPPORTED_CHANNEL`         | channel value not in allowed list          |
| 422    | `CHANNEL_UNAVAILABLE`         | channel not available for currency         |
| 422    | `AMOUNT_BELOW_MINIMUM`        | gross_amount < configured min              |
| 422    | `AMOUNT_ABOVE_MAXIMUM`        | gross_amount > configured max              |
| 422    | `DAILY_LIMIT_EXCEEDED`        | daily limit would be exceeded              |
| 422    | `INSUFFICIENT_BALANCE`        | available_balance < gross_amount           |
| 422    | `KYC_INSUFFICIENT`            | KYC tier doesn't permit this channel       |
| 422    | `INVALID_PAYOUT_DESTINATION`  | Required destination fields missing/invalid|

---

### GET /api/v1/withdrawals

**Summary**: List customer's withdrawal transactions (paginated).

**Auth**: Bearer token | **Permission**: `withdrawal.read`

**Query Parameters**:
- `status` — filter by status (optional)
- `channel` — filter by channel (optional)
- `page` — page number (default 1)
- `per_page` — results per page (default 20, max 100)

**Response 200**:
```json
{
  "data": [
    { /* WithdrawalTransaction */ },
    ...
  ],
  "meta": {
    "total": 45,
    "page": 1,
    "per_page": 20,
    "pages": 3
  }
}
```

---

### GET /api/v1/withdrawals/{id}

**Summary**: Get a single withdrawal transaction by ID.

**Auth**: Bearer token | **Permission**: `withdrawal.read`

**Response 200**: `{ "data": { /* WithdrawalTransaction */ } }`

**Errors**:
| Status | Code                     | Condition                                    |
|--------|--------------------------|----------------------------------------------|
| 404    | `WITHDRAWAL_NOT_FOUND`   | Not found or not owned by caller             |

---

### POST /api/v1/withdrawals/{id}/confirm

**Summary**: Confirm withdrawal with transaction PIN; create hold; dispatch payout (US-WITHDRAW-007, 008).

**Auth**: Bearer token | **Permission**: `withdrawal.initiate`

**Headers**:
```
Authorization: Bearer {token}
Idempotency-Key: {uuid}
Content-Type: application/json
```

**Request Body**:
```json
{
  "transaction_pin": "123456",
  "quoted_fee_amount": 0
}
```

**Response 200 — Confirmed** (transaction now `processing`):
```json
{
  "data": {
    "id": "...",
    "status": "processing",
    "gross_amount": 50000,
    "fee_amount": 0,
    "net_payout_amount": 50000,
    "wallet_hold_id": "...",
    "updated_at": "..."
  }
}
```

**Error Responses**:
| Status | Code                        | Condition                                     |
|--------|-----------------------------|-----------------------------------------------|
| 400    | `MISSING_IDEMPOTENCY_KEY`   | `Idempotency-Key` header absent               |
| 403    | `STEP_UP_REQUIRED`          | Amount ≥ step-up threshold; MFA not satisfied |
| 404    | `WITHDRAWAL_NOT_FOUND`      | Not found or not owned                        |
| 409    | `FEE_CHANGED`               | Fee rate changed since initiation             |
| 422    | `TRANSACTION_NOT_MODIFIABLE`| Status is not `pending`                       |
| 422    | `DAILY_LIMIT_EXCEEDED`      | Daily limit would be exceeded (re-check)      |
| 422    | `INVALID_PIN`               | PIN verification failed                       |
| 422    | `INSUFFICIENT_BALANCE`      | Race condition: balance changed since initiation |
| 422    | `WALLET_SUSPENDED`          | Wallet pocket is suspended                    |
| 422    | `WITHDRAWAL_RISK_REJECTED`  | Risk check returned rejected                  |

**409 FEE_CHANGED Body**:
```json
{
  "error": {
    "code": "FEE_CHANGED",
    "message": "The withdrawal fee has changed. Please review and confirm again.",
    "details": {
      "updated_fee_amount": 750,
      "updated_net_payout_amount": 49250
    }
  }
}
```

---

### POST /api/v1/withdrawals/{id}/cancel

**Summary**: Cancel a `pending` withdrawal (US-WITHDRAW-010).

**Auth**: Bearer token | **Permission**: `withdrawal.initiate`

**Headers**:
```
Authorization: Bearer {token}
Idempotency-Key: {uuid}
Content-Type: application/json
```

**Request Body**: `{}` (empty)

**Response 200**:
```json
{
  "data": {
    "id": "...",
    "status": "cancelled",
    "updated_at": "..."
  }
}
```

**Errors**:
| Status | Code                        | Condition                                   |
|--------|-----------------------------|---------------------------------------------|
| 404    | `WITHDRAWAL_NOT_FOUND`      | Not found or not owned                      |
| 422    | `TRANSACTION_NOT_CANCELLABLE` | Status is not `pending`                   |

---

### POST /api/v1/withdrawals/callbacks/{provider}

**Summary**: Provider payout callback (US-WITHDRAW-009, 010, 011). System-only endpoint.

**Auth**: HMAC/signature (provider-specific; no Bearer token)

**Path Parameter**: `provider` — provider identifier (e.g., `mock`, `mpesa`)

**Request Body**: Provider-specific JSON payload

**Response 200** — always returned if signature valid (idempotent):
```json
{ "status": "acknowledged" }
```

**Response 400**:
```json
{ "error": { "code": "INVALID_CALLBACK_SIGNATURE", "message": "..." } }
```

---

### GET /api/v1/withdrawals/{id}/receipt

**Summary**: Get receipt for a completed withdrawal by transaction ID (US-WITHDRAW-012).

**Auth**: Bearer token | **Permission**: `withdrawal.read`

**Response 200**: `{ "data": { /* WithdrawalReceipt */ } }`

**Errors**:
| Status | Code                   | Condition                                      |
|--------|------------------------|------------------------------------------------|
| 404    | `WITHDRAWAL_NOT_FOUND` | Transaction not found or not owned             |
| 404    | `RECEIPT_NOT_FOUND`    | Transaction found but no receipt yet (not completed) |

---

### GET /api/v1/withdrawals/receipts/{receipt_reference}

**Summary**: Get receipt by receipt_reference (US-WITHDRAW-012).

**Auth**: Bearer token | **Permission**: `withdrawal.read`

**Response 200**: `{ "data": { /* WithdrawalReceipt */ } }`

**Errors**:
| Status | Code                   | Condition                         |
|--------|------------------------|-----------------------------------|
| 404    | `RECEIPT_NOT_FOUND`    | Receipt not found or not owned    |

---

### GET /api/v1/admin/withdrawals

**Summary**: Admin list all withdrawal transactions (paginated).

**Auth**: Bearer token | **Permission**: `withdrawal.admin`

**Query Parameters**: `status`, `channel`, `customer_id`, `from_date`, `to_date`, `page`, `per_page`

**Response 200**: Paginated list of WithdrawalTransaction objects (no ownership filter).

---

### GET /api/v1/admin/withdrawals/{id}

**Summary**: Admin get any withdrawal by ID.

**Auth**: Bearer token | **Permission**: `withdrawal.admin`

**Response 200**: `{ "data": { /* WithdrawalTransaction */ } }`

**Errors**: 404 `WITHDRAWAL_NOT_FOUND` if not found.

---

### POST /api/v1/admin/withdrawals/{id}/force-fail

**Summary**: Admin force-fail a stuck `processing` transaction; releases hold.

**Auth**: Bearer token | **Permission**: `withdrawal.admin`

**Request Body**: `{}` (empty, or optional `reason` string)

**Response 200**:
```json
{
  "data": {
    "id": "...",
    "status": "failed",
    "failure_reason": "admin_force_failed",
    "updated_at": "..."
  }
}
```

**Errors**:
| Status | Code                           | Condition                                      |
|--------|--------------------------------|------------------------------------------------|
| 404    | `WITHDRAWAL_NOT_FOUND`         | Not found                                      |
| 422    | `WITHDRAWAL_ALREADY_TERMINAL`  | Already in completed/failed/cancelled state    |

---

## Route Registration Note

Routes in `routes/modules/withdrawal.php`:
```php
// Static prefix routes MUST be registered before dynamic /{id}
Route::get('/receipts/{receiptReference}', [WithdrawalReceiptController::class, 'showByReference']);
Route::get('/{id}', [WithdrawalController::class, 'show']);
Route::get('/{id}/receipt', [WithdrawalReceiptController::class, 'show']);
```

---

## Artisan Command Contract

```
php artisan withdrawal:expire-stale [--dry-run]
```

- Finds `WithdrawalTransaction` where `status = processing` AND `created_at < NOW() - hold_ttl_minutes`
- For each: releases hold, transitions to `failed` (failure_reason = processing_timeout), emits `Withdrawal.Transaction.Expired`
- `--dry-run`: reports count without making changes
- Exit code 0 on success
- Idempotent: safe to run any number of times
