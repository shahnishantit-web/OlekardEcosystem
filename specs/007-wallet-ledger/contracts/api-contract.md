# API Contract — E07 Wallet Ledger

All endpoints follow platform conventions (constitution Principle 8):
- Base URL: `/v1/ledger`
- Authentication: `Authorization: Bearer <token>` (required on all endpoints)
- Correlation: `X-Correlation-ID: <uuid>` (request and response header)
- Idempotency: `Idempotency-Key: <client-uuid>` (required on all mutation endpoints)
- Response envelope: `{ "success": bool, "data": {...}|null, "error": {...}|null, "correlation_id": "..." }`
- Timestamps: ISO 8601 UTC (e.g., `2026-09-17T14:30:00Z`)
- IDs: UUID v7 strings
- Amounts: integer minor units (thebe for BWP, cents for USD, etc.)

---

## Endpoints

### POST /v1/ledger/accounts

Register a ledger account explicitly before its first posting.

**Permission**: `ledger.account.register`
**Idempotency-Key**: Required

**Request Body**:
```json
{
  "ledger_account_reference": "01928e7b-0000-7000-0000-000000000001",
  "account_type": "liability",
  "currency_code": "BWP",
  "owner_reference": "01928e7b-0000-7000-0000-000000000002",
  "owner_type": "customer_pocket"
}
```

**Response 201** (new registration):
```json
{
  "success": true,
  "data": {
    "id": "01928e7b-0000-7000-0000-000000000010",
    "ledger_account_reference": "01928e7b-0000-7000-0000-000000000001",
    "account_type": "liability",
    "normal_balance": "credit",
    "currency_code": "BWP",
    "owner_reference": "01928e7b-0000-7000-0000-000000000002",
    "owner_type": "customer_pocket",
    "status": "active",
    "created_at": "2026-09-17T09:00:00Z"
  },
  "error": null,
  "correlation_id": "corr-001"
}
```

**Response 200** (idempotent replay — account already registered with same parameters): same body as 201.

**Error Responses**:
- `422 MISSING_IDEMPOTENCY_KEY` — `Idempotency-Key` header absent
- `422` validation failure — missing required field
- `409` — account already registered with conflicting `account_type` or `currency_code`
- `401` / `403 INSUFFICIENT_PERMISSIONS` — auth/permission failure

---

### POST /v1/ledger/entries/debit

Post a debit journal entry.

**Permission**: `ledger.post`
**Idempotency-Key**: Required

**Request Body**:
```json
{
  "transaction_reference": "01928e7b-0000-7000-0000-000000000099",
  "currency_code": "BWP",
  "correlation_id": "corr-001",
  "description": "Customer spend",
  "lines": [
    { "ledger_account_reference": "cust-pocket-ref", "direction": "debit",  "amount": 5000 },
    { "ledger_account_reference": "merchant-ref",    "direction": "credit", "amount": 5000 }
  ]
}
```

**Response 201** (new journal):
```json
{
  "success": true,
  "data": {
    "journal_id": "01928e7b-0000-7000-0000-000000000011",
    "entry_type": "debit",
    "currency_code": "BWP",
    "status": "posted",
    "transaction_reference": "01928e7b-0000-7000-0000-000000000099",
    "posted_at": "2026-09-17T09:00:00Z"
  },
  "error": null,
  "correlation_id": "corr-001"
}
```

**Response 200** (idempotent replay): same body with the original `journal_id`.

**Error Responses**:
- `422 UNBALANCED_JOURNAL` — SUM(debits) ≠ SUM(credits)
- `422 CURRENCY_MISMATCH` — mixed currencies in lines
- `422 INVALID_AMOUNT` — zero or negative amount
- `422 ACCOUNT_CLOSED` — referenced account is closed
- `422 MISSING_IDEMPOTENCY_KEY` — header absent
- `404 ACCOUNT_NOT_FOUND` — unknown `ledger_account_reference` (only if not auto-registered; see FR-E07-003)

---

### POST /v1/ledger/entries/credit

Post a credit journal entry. Same shape as `/entries/debit` with `entry_type = credit`.

**Permission**: `ledger.post` | **Idempotency-Key**: Required

Request/Response shape is identical to `POST /v1/ledger/entries/debit` with `"entry_type": "credit"` in the response.

---

### POST /v1/ledger/entries/fee

Post a fee journal entry. `entry_type = fee`.

**Permission**: `ledger.post` | **Idempotency-Key**: Required

Same shape as `/entries/debit`. Fee account references are resolved by the caller from `platform.configuration_values`; the ledger does not validate which accounts appear on which side.

---

### POST /v1/ledger/entries/revenue

Post a revenue journal entry. `entry_type = revenue`.

**Permission**: `ledger.post` | **Idempotency-Key**: Required

Same shape as `/entries/debit`.

---

### POST /v1/ledger/entries/commission

Post a commission journal entry. `entry_type = commission`.

**Permission**: `ledger.post` | **Idempotency-Key**: Required

Same shape as `/entries/debit`.

---

### POST /v1/ledger/entries/{id}/reverse

Reverse a posted journal by creating a compensating journal with swapped debit/credit lines.

**Permission**: `ledger.entry.reverse`
**Idempotency-Key**: Required
**Path param**: `id` — UUID v7 of the journal to reverse

**Request Body**:
```json
{
  "description": "Reversal of erroneous fee posting",
  "correlation_id": "corr-rev-001"
}
```

**Response 201** (new reversal journal):
```json
{
  "success": true,
  "data": {
    "journal_id": "01928e7b-0000-7000-0000-000000000020",
    "entry_type": "reversal",
    "reversed_journal_id": "01928e7b-0000-7000-0000-000000000011",
    "currency_code": "BWP",
    "status": "posted",
    "posted_at": "2026-09-17T09:15:00Z"
  },
  "error": null,
  "correlation_id": "corr-rev-001"
}
```

**Error Responses**:
- `404 JOURNAL_NOT_FOUND` — journal ID not found
- `422 JOURNAL_ALREADY_REVERSED` — journal has already been reversed
- `422 MISSING_IDEMPOTENCY_KEY` — header absent
- `403 INSUFFICIENT_PERMISSIONS` — caller lacks `ledger.entry.reverse`

---

### GET /v1/ledger/accounts/{reference}/balance

Get the computed balance for a ledger account.

**Permission**: `ledger.read` (own account) or `ledger.read.any` (any account)
**Idempotency-Key**: Not required

**Path param**: `reference` — the `ledger_account_reference` UUID v7

**Response 200**:
```json
{
  "success": true,
  "data": {
    "ledger_account_reference": "01928e7b-0000-7000-0000-000000000001",
    "currency_code": "BWP",
    "balance": 8000,
    "normal_balance": "credit",
    "computed_at": "2026-09-17T09:00:00Z"
  },
  "error": null,
  "correlation_id": "corr-001"
}
```

**Error Responses**:
- `404 ACCOUNT_NOT_FOUND` — unknown reference
- `403` — IDOR (returned as 404 without `ledger.read.any`)

---

### GET /v1/ledger/accounts/{reference}/entries

Paginated journal line history for an account.

**Permission**: `ledger.read`
**Query params**: `page` (default 1), `per_page` (default 20, max 100)

**Response 200**:
```json
{
  "success": true,
  "data": [
    {
      "journal_id": "01928e7b-0000-7000-0000-000000000011",
      "entry_type": "debit",
      "direction": "debit",
      "amount": 5000,
      "currency_code": "BWP",
      "transaction_reference": "01928e7b-0000-7000-0000-000000000099",
      "posted_at": "2026-09-17T09:00:00Z"
    }
  ],
  "meta": { "total": 42, "page": 1, "per_page": 20, "pages": 3 },
  "error": null,
  "correlation_id": "corr-001"
}
```

---

### GET /v1/ledger/journals/{id}

Get a specific journal with all its lines.

**Permission**: `ledger.read`
**Path param**: `id` — UUID v7 journal ID

**Response 200**:
```json
{
  "success": true,
  "data": {
    "id": "01928e7b-0000-7000-0000-000000000011",
    "entry_type": "debit",
    "currency_code": "BWP",
    "status": "posted",
    "transaction_reference": "01928e7b-0000-7000-0000-000000000099",
    "reversed_journal_id": null,
    "correlation_id": "corr-001",
    "description": "Customer spend",
    "posted_at": "2026-09-17T09:00:00Z",
    "created_by": "01928e7b-0000-7000-0000-000000000005",
    "lines": [
      { "id": "...", "direction": "debit",  "amount": 5000, "currency_code": "BWP", "ledger_account_reference": "cust-pocket-ref" },
      { "id": "...", "direction": "credit", "amount": 5000, "currency_code": "BWP", "ledger_account_reference": "merchant-ref" }
    ]
  },
  "error": null,
  "correlation_id": "corr-001"
}
```

**Error Responses**:
- `404 JOURNAL_NOT_FOUND`

---

### GET /v1/ledger/journals

Query journals by `transaction_reference`.

**Permission**: `ledger.read`
**Query params**: `transaction_reference` (required), `page`, `per_page`

**Response 200**: Same paginated envelope as `/accounts/{reference}/entries` but returning journal-level objects with lines embedded.

---

## Error Code Reference

| Code | HTTP Status | Description |
|---|---|---|
| `UNBALANCED_JOURNAL` | 422 | SUM(debits) ≠ SUM(credits) in the submitted journal |
| `CURRENCY_MISMATCH` | 422 | Journal lines contain mixed currencies |
| `INVALID_AMOUNT` | 422 | Amount is zero, negative, or non-integer |
| `ACCOUNT_NOT_FOUND` | 404 | `ledger_account_reference` not found |
| `ACCOUNT_CLOSED` | 422 | Account is closed; no further postings accepted |
| `JOURNAL_NOT_FOUND` | 404 | Journal ID not found |
| `JOURNAL_ALREADY_REVERSED` | 422 | Journal has already been reversed |
| `MISSING_IDEMPOTENCY_KEY` | 422 | `Idempotency-Key` header absent on a mutation endpoint |
| `LEDGER_MODULE_UNAVAILABLE` | 503 | Ledger service is temporarily unavailable |
| `INSUFFICIENT_PERMISSIONS` | 403 | Actor lacks required ledger permission |

---

## Pagination

All list endpoints accept:
- `page` (integer, default `1`)
- `per_page` (integer, default `20`, max `100`)

Response `meta` object:
```json
{ "total": 100, "page": 1, "per_page": 20, "pages": 5 }
```
