# API Contract: Payment Quote

**Feature**: P04 — Purpose Validation, Quotes and Fee Snapshots
**Date**: 2026-10-21

All endpoints follow the project's REST/OpenAPI conventions:
- Base path: `/api/v1`
- Auth: Bearer token (identity module JWT)
- Timezone: UTC; all timestamps ISO-8601
- Error envelope: `{ "error": { "code": "...", "message": "..." } }`
- Idempotency: `Idempotency-Key` header required for state-changing endpoints

---

## 1. GET /payment/intents/{id}/quote

Retrieve the current (most recent active) quote for a payment intent.

### Authorization
- Consumer: must be the payer actor on the intent
- Admin: must have `payment.intents.read` permission

### Path Parameters
| Name | Type | Description |
|------|------|-------------|
| id | UUID v7 | Payment intent ID |

### Response 200 — OK
```json
{
  "data": {
    "quote_id": "01945e00-0000-7000-8000-000000000001",
    "payment_intent_id": "01945e00-0000-7000-8000-000000000002",
    "version": 1,
    "quote_status": "active",
    "base_amount_minor": 10000,
    "fee_amount_minor": 200,
    "total_amount_minor": 10200,
    "currency_code": "BWP",
    "provider_code": "wallet",
    "rounding_mode": "floor",
    "expires_at": "2026-10-21T10:05:00Z",
    "fee_snapshot": {
      "rule_id": "01945d00-0000-7000-8000-000000000001",
      "rule_name": "Standard Payment Fee 2%",
      "fee_type": "percentage",
      "rate_basis_points": 200,
      "flat_amount_minor": null,
      "minimum_fee_minor": 50,
      "maximum_fee_minor": 5000,
      "rounding_mode": "floor",
      "effective_date": "2026-01-01",
      "computed_fee_minor": 200,
      "min_fee_applied": false,
      "max_fee_applied": false,
      "computation_inputs": {
        "amount_minor": 10000,
        "currency_code": "BWP",
        "transaction_type": "payment",
        "subject_type": "customer"
      }
    }
  }
}
```

### Response 404 — Intent Not Found
```json
{
  "error": {
    "code": "PAYMENT_INTENT_NOT_FOUND",
    "message": "Payment intent not found."
  }
}
```

### Response 403 — Forbidden
```json
{
  "error": {
    "code": "FORBIDDEN",
    "message": "You do not have permission to view this quote."
  }
}
```

### Response 422 — No Active Quote
Returned when the intent exists but has no quote yet (e.g., just created and resolver failed before quote was persisted — should not normally occur).
```json
{
  "error": {
    "code": "NO_ACTIVE_QUOTE",
    "message": "This intent does not have an active quote."
  }
}
```

---

## 2. POST /payment/intents (extended response — includes quote)

The existing intent creation endpoint is extended to include the quote in its response.

**Change**: Response body gains a `quote` field alongside the existing `payment_intent_id`, `status`, `amount_minor`, etc.

### Response 201 — Created (extended)
```json
{
  "data": {
    "payment_intent_id": "01945e00-0000-7000-8000-000000000002",
    "status": "pending",
    "amount_minor": 10000,
    "currency_code": "BWP",
    "expires_at": "2026-10-21T10:30:00Z",
    "correlation_id": "01945e00-0000-7000-8000-000000000003",
    "quote": {
      "quote_id": "01945e00-0000-7000-8000-000000000001",
      "version": 1,
      "base_amount_minor": 10000,
      "fee_amount_minor": 200,
      "total_amount_minor": 10200,
      "currency_code": "BWP",
      "provider_code": "wallet",
      "expires_at": "2026-10-21T10:05:00Z",
      "quote_status": "active"
    }
  }
}
```

---

## 3. POST /payment/intents/{id}/attempts (stale-quote error response)

The existing attempt initiation endpoint gains one new error response:

### Response 409 — Quote Stale, Confirm Required
```json
{
  "error": {
    "code": "QUOTE_STALE_CONFIRM_REQUIRED",
    "message": "The quote has expired and the total has changed. Please review the new quote before proceeding.",
    "context": {
      "previous_total_amount_minor": 10200,
      "new_total_amount_minor": 10350,
      "new_quote": {
        "quote_id": "01945f00-0000-7000-8000-000000000004",
        "version": 2,
        "base_amount_minor": 10000,
        "fee_amount_minor": 350,
        "total_amount_minor": 10350,
        "currency_code": "BWP",
        "provider_code": "wallet",
        "expires_at": "2026-10-21T10:10:00Z",
        "quote_status": "active"
      }
    }
  }
}
```

---

## Error Codes (new in P04)

| Code | HTTP | Description |
|------|------|-------------|
| `PURPOSE_NOT_PAYABLE` | 422 | Purpose does not exist, is ineligible, ownership mismatch, or expired |
| `PURPOSE_NO_LONGER_PAYABLE` | 409 | Purpose was payable at intent creation but is no longer payable at attempt time |
| `QUOTE_STALE_CONFIRM_REQUIRED` | 409 | Quote expired and total changed; new quote in context |
| `NO_ACTIVE_QUOTE` | 422 | Intent has no active quote (internal/edge case) |
| `OBLIGATION_RESOLUTION_FAILED` | 503 | Purpose resolver raised unhandled exception during revalidation |

---

## OpenAPI additions (for `openapi/v1/payment.yaml`)

New components to add:
- `PaymentQuoteResponse` schema
- `FeeSnapshotObject` schema
- `QuoteStaleContext` schema
- New path: `GET /payment/intents/{id}/quote`
- `QUOTE_STALE_CONFIRM_REQUIRED` in `PaymentAttemptResponse` error codes
- `PURPOSE_NOT_PAYABLE`, `PURPOSE_NO_LONGER_PAYABLE` in `PaymentIntentResponse` error codes
- `quote` field in `PaymentIntentCreateResponse`
