# API Contract — E08 Wallet Funding

All endpoints under `/v1/funding/`.
Base conventions: UUID v7 identifiers, UTC ISO-8601 timestamps, `{ "data": {...} }` success envelope, `{ "error": { "code": "...", "message": "..." } }` error envelope. Financial mutations require `Idempotency-Key` header.

---

## Endpoints

### 1. POST `/v1/funding/transactions`
Initiate a wallet funding transaction. See `initiate-funding-request.php` for full shape.

**Auth**: Bearer token | **Permission**: `funding.initiate`
**Idempotency-Key**: Required

**Request body**: `wallet_id`, `currency_code`, `amount`, `funding_method`, `correlation_id`

**Responses**: `201` (created) · `200` (idempotent replay) · `400` · `401` · `403` · `404` · `422`

---

### 2. GET `/v1/funding/transactions/{id}`
Get the status of a funding transaction.

**Auth**: Bearer token | **Permission**: `funding.read`

**Response 200**:
```json
{
  "data": {
    "id": "uuid",
    "status": "processing",
    "amount": 10000,
    "fee_amount": 50,
    "net_amount": 9950,
    "currency_code": "BWP",
    "funding_method": "mobile_money",
    "transaction_reference": "uuid",
    "bank_reference_code": null,
    "bank_reference_expires_at": null,
    "ledger_journal_id": null,
    "completed_at": null,
    "created_at": "2026-09-18T12:00:00Z"
  }
}
```

**Responses**: `200` · `401` · `403` · `404`

---

### 3. GET `/v1/funding/transactions`
List the authenticated customer's funding transactions (paginated, descending by `created_at`).

**Auth**: Bearer token | **Permission**: `funding.read`

**Query parameters**: `status` (filter), `page` (default 1), `per_page` (default 20, max 100)

**Response 200**:
```json
{
  "data": [ { "...": "transaction objects" } ],
  "meta": { "total": 42, "page": 1, "per_page": 20, "pages": 3 }
}
```

---

### 4. POST `/v1/funding/transactions/{id}/confirm`
Confirm a `pending` transaction — submits to provider (non-bank) or generates bank reference.

**Auth**: Bearer token | **Permission**: `funding.initiate`
**Idempotency-Key**: Required

**Request body**:
```json
{
  "quoted_fee_amount": 50,
  "correlation_id": "uuid"
}
```

**Response 201** (non-bank): Transaction in `processing` status; `provider_reference_id` set.
**Response 201** (bank transfer): Transaction in `awaiting_match`; `bank_reference_code` and `bank_reference_expires_at` included:
```json
{
  "data": {
    "id": "uuid",
    "status": "awaiting_match",
    "bank_reference_code": "OLK-20260918-A7BK3FPQ",
    "bank_reference_expires_at": "2026-09-21T12:00:00Z",
    "banking_instructions": {
      "account_name": "OleKard Platform (BWP)",
      "account_number": "1234567890",
      "branch_code": "000123",
      "reference": "OLK-20260918-A7BK3FPQ"
    }
  }
}
```

**Responses**: `201` · `200` (idempotent) · `400` · `401` · `403` · `422 FEE_CHANGED` · `422 TRANSACTION_NOT_MODIFIABLE`

---

### 5. POST `/v1/funding/transactions/{id}/cancel`
Cancel a `pending` or `awaiting_match` transaction.

**Auth**: Bearer token | **Permission**: `funding.initiate`
**Idempotency-Key**: Required

**Request body**: `{ "correlation_id": "uuid" }`

**Response 200**:
```json
{ "data": { "id": "uuid", "status": "cancelled" } }
```

**Responses**: `200` · `401` · `403` · `404` · `422 TRANSACTION_NOT_CANCELLABLE`

---

### 6. POST `/v1/funding/callbacks/{provider}`
Receive a provider payment callback. **Public endpoint** (no Bearer auth); HMAC signature verified inline.

**Auth**: HMAC/signature verification (SR-E08-001) | **Permission**: None (system callback)
**No Idempotency-Key header** (idempotency via `provider_reference_id`)

**Path parameter**: `provider` — provider identifier string (e.g., `mock`, `mpesa`, `stripe`)

**Request body** (provider-specific; stored as `raw_payload`):
```json
{
  "provider_transaction_id": "MOCK-uuid",
  "status": "success",
  "amount": 10000,
  "currency": "BWP",
  "reference": "...provider-specific..."
}
```

**Response 200** (success processed or known duplicate): Empty body or `{ "acknowledged": true }`
**Response 400**: `INVALID_CALLBACK_SIGNATURE` — no state change
**Response 500**: Processing error — provider SHOULD retry

---

### 7. POST `/v1/funding/bank/notifications`
Receive a bank payment deposit notification. **System-to-system** endpoint (API key auth).

**Auth**: API key in `X-Bank-Api-Key` header | **Permission**: None (system)
**No Idempotency-Key** (idempotency via `bank_notification_id`)

**Request body**:
```json
{
  "bank_notification_id": "BANK-TXN-001",
  "amount": 10000,
  "currency_code": "BWP",
  "reference": "OLK-20260918-A7BK3FPQ",
  "narration": "Payment for OLK-20260918-A7BK3FPQ",
  "received_at": "2026-09-18T14:30:00Z"
}
```

**Response 200** (matched and credited, or known duplicate): `{ "matched": true, "funding_transaction_id": "uuid" }`
**Response 200** (unmatched stored): `{ "matched": false, "unmatched_deposit_id": "uuid" }`
**Response 401**: Invalid API key

---

### 8. POST `/v1/funding/agent-transactions`
Agent cash-in: debit agent float, credit customer wallet. See `agent-cashin-request.php` for full shape.

**Auth**: Bearer token | **Permission**: `funding.agent-cashin`
**Idempotency-Key**: Required

**Request body**: `customer_identifier`, `amount`, `currency_code`, `transaction_pin`, `correlation_id`

**Responses**: `201` (completed synchronously) · `200` (idempotent) · `400` · `401` · `403` · `404` · `422`

---

### 9. GET `/v1/funding/agent-transactions/{id}`
Get an agent-initiated cash-in transaction.

**Auth**: Bearer token | **Permission**: `funding.agent-cashin`

**Response 200**: Same shape as GET `/v1/funding/transactions/{id}` plus `agent_id`, receipt fields.

---

### 10. GET `/v1/funding/receipts/{receipt_reference}`
Retrieve a funding receipt by its receipt reference UUID.

**Auth**: Bearer token | **Permission**: `funding.read`

**Response 200**:
```json
{
  "data": {
    "receipt_reference": "uuid",
    "funding_transaction_id": "uuid",
    "amount": 10000,
    "fee_amount": 0,
    "net_amount": 10000,
    "currency_code": "BWP",
    "funding_method": "agent",
    "agent_id": "uuid",
    "customer_id": "uuid",
    "wallet_id": "uuid",
    "ledger_journal_id": "uuid",
    "completed_at": "2026-09-18T12:05:00Z"
  }
}
```

**Responses**: `200` · `401` · `403` · `404 RECEIPT_NOT_FOUND`

---

### 11. GET `/v1/funding/admin/unmatched-deposits`
List unmatched bank deposits for admin review.

**Auth**: Bearer token | **Permission**: `funding.unmatched.review`

**Query parameters**: `resolution_status` (filter: `pending`|`credited`|`returned`), `page`, `per_page`

**Response 200**: Paginated list of unmatched deposit records with all fields.

---

### 12. PATCH `/v1/funding/admin/unmatched-deposits/{id}`
Update resolution status for an unmatched deposit (admin manual action).

**Auth**: Bearer token | **Permission**: `funding.unmatched.review`

**Request body**:
```json
{
  "resolution_status": "credited",
  "resolution_notes": "Manually credited to customer X (verified by phone).",
  "correlation_id": "uuid"
}
```

**Response 200**: Updated unmatched deposit record.

---

## Error Code Reference

| Code | HTTP | Meaning |
|------|------|---------|
| `MISSING_IDEMPOTENCY_KEY` | 400 | `Idempotency-Key` header absent |
| `VALIDATION_ERROR` | 400 | Request body failed validation |
| `UNAUTHENTICATED` | 401 | No valid bearer token |
| `FORBIDDEN` | 403 | Missing required permission |
| `STEP_UP_REQUIRED` | 403 | Step-up authentication required for this amount |
| `WALLET_NOT_FOUND` | 404 | Wallet not found or IDOR (always 404, never 403) |
| `CUSTOMER_NOT_FOUND` | 404 | Customer identifier not found |
| `RECEIPT_NOT_FOUND` | 404 | Receipt reference not found |
| `JOURNAL_NOT_FOUND` | 404 | (from E07) Ledger journal not found |
| `KYC_INSUFFICIENT` | 422 | Customer KYC tier too low for this method |
| `AMOUNT_BELOW_MINIMUM` | 422 | Amount below configured minimum |
| `AMOUNT_ABOVE_MAXIMUM` | 422 | Amount above configured maximum |
| `AMOUNT_OUTSIDE_LIMITS` | 422 | Amount outside per-transaction limits |
| `DAILY_LIMIT_EXCEEDED` | 422 | Daily funding limit reached |
| `FEE_CHANGED` | 422 | Fee recalculated at confirmation differs from quoted fee |
| `TRANSACTION_NOT_MODIFIABLE` | 422 | Invalid state transition requested |
| `TRANSACTION_NOT_CANCELLABLE` | 422 | Transaction is in a non-cancellable state |
| `UNSUPPORTED_FUNDING_METHOD` | 422 | Unknown `funding_method` value |
| `FUNDING_METHOD_UNAVAILABLE` | 422 | Method not available for this currency/tier |
| `INSUFFICIENT_FLOAT` | 422 | Agent float balance insufficient |
| `INVALID_PIN` | 422 | Agent transaction PIN incorrect |
| `WALLET_SUSPENDED` | 422 | Customer wallet is suspended |
| `INVALID_CALLBACK_SIGNATURE` | 400 | Provider callback HMAC invalid |
