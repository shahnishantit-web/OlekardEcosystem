# API Contracts: P06 — Intent, Attempt and Payment State Engine

**Date**: 2026-10-21
**Feature**: [spec.md](../spec.md)
**Data Model**: [data-model.md](../data-model.md)

All endpoints are under `/v1/` and require `Authorization: Bearer <token>`.
Financial mutations require `Idempotency-Key: <uuid>` header.

---

## 1. Create / Resume Payment Intent

```
POST /v1/payment/intents
```

**Headers**: `Idempotency-Key: <uuid>` (required)

**Request body**:
```json
{
  "purpose_type":      "string",
  "purpose_reference": "string",
  "organisation_id":   "uuid",
  "currency_code":     "string (ISO 4217)",
  "metadata":          "object (optional)"
}
```

**Success — 201 Created** (new intent or idempotent replay):
```json
{
  "data": {
    "id":             "uuid",
    "status":         "pending",
    "amount_minor":   12500,
    "currency_code":  "BWP",
    "expires_at":     "2026-10-21T10:30:00Z",
    "accounting_status":  "pending",
    "fulfilment_status":  "pending",
    "quote": {
      "quote_id":           "uuid",
      "version":            1,
      "base_amount_minor":  12500,
      "fee_amount_minor":   125,
      "total_amount_minor": 12625,
      "currency_code":      "BWP",
      "provider_code":      "wallet",
      "expires_at":         "2026-10-21T10:05:00Z"
    }
  }
}
```

**Resume behaviour**: If an active intent exists for the same `purpose_reference` + `organisation_id` (different Idempotency-Key), returns HTTP 201 with the existing intent (no duplicate created).

**Error codes**:
| Code | HTTP | Condition |
|------|------|-----------|
| `PURPOSE_NOT_PAYABLE` | 422 | Obligation expired, not owned by actor, or not in a payable state |
| `IDEMPOTENCY_CONFLICT` | 409 | Same key, different request body fingerprint |

---

## 2. Start a Payment Attempt

```
POST /v1/payment/intents/{intentId}/attempts
```

**Headers**: `Idempotency-Key: <uuid>` (required)

**Request body**:
```json
{
  "provider_code":     "string",
  "provider_account_id": "uuid (optional)",
  "wallet_id":         "uuid (optional)",
  "step_up_token":     "string (optional)"
}
```

**Success — 201 Created**:
```json
{
  "data": {
    "attempt_id":       "uuid",
    "operation_id":     "uuid",
    "status":           "submitting",
    "next_action_type": null,
    "next_action_data": null,
    "quote_id":         "uuid",
    "quote_version":    1
  }
}
```

After adapter completes, the attempt status updates asynchronously (or synchronously for wallet adapter). The caller must poll `GET .../attempts/{attemptId}` or await a webhook.

**Error codes**:
| Code | HTTP | Condition |
|------|------|-----------|
| `DUPLICATE_ATTEMPT` | 409 | A non-terminal attempt already exists for this intent + provider |
| `UNSAFE_PROVIDER_SWITCH` | 409 | Existing UNKNOWN attempt exists for a different provider |
| `INVALID_STATE_TRANSITION` | 422 | Intent is in a terminal state (expired, succeeded, etc.) |
| `PROVIDER_NOT_FOUND` | 422 | No adapter registered for requested provider_code |
| `CIRCUIT_OPEN` | 503 | Provider circuit breaker is open |
| `INSUFFICIENT_FUNDS` | 422 | Wallet balance insufficient (from adapter) |
| `STEP_UP_REQUIRED` | 403 | Step-up auth required for this operation amount |

---

## 3. Query Attempt Status

```
GET /v1/payment/intents/{intentId}/attempts/{attemptId}
```

**Authorization**: caller must own the intent (`payer_actor_id` match) OR have admin scope.

**Success — 200 OK**:
```json
{
  "data": {
    "attempt_id":                  "uuid",
    "operation_id":                "uuid",
    "status":                      "processing",
    "next_action_type":            "redirect",
    "next_action_data":            { "url": "https://..." },
    "provider_code":               "bank_abc",
    "quote_id":                    "uuid",
    "quote_version":               1,
    "initiated_at":                "2026-10-21T10:00:00Z",
    "timeout_at":                  "2026-10-21T10:15:00Z",
    "reconciliation_exception_id": null
  }
}
```

When `status` is `unknown`:
```json
{
  "data": {
    "attempt_id": "uuid",
    "status":     "unknown",
    "reconciliation_exception_id": "uuid"
  }
}
```

**Error codes**:
| Code | HTTP | Condition |
|------|------|-----------|
| `ATTEMPT_NOT_FOUND` | 404 | Attempt ID not found |
| `AUTHORIZATION_DENIED` | 403 | Caller does not own the intent |

---

## 4. Query Intent Status (with independent dimensions)

```
GET /v1/payment/intents/{intentId}/status
```

**Authorization**: caller must own the intent OR have admin scope.

**Success — 200 OK**:
```json
{
  "data": {
    "id":                  "uuid",
    "status":              "processing",
    "accounting_status":   "pending",
    "fulfilment_status":   "pending",
    "amount_minor":        12500,
    "currency_code":       "BWP",
    "expires_at":          "2026-10-21T10:30:00Z",
    "latest_attempt": {
      "attempt_id": "uuid",
      "status":     "processing"
    }
  }
}
```

`accounting_status` values: `pending` | `posted` | `void`
`fulfilment_status` values: `pending` | `fulfilled` | `not_required` | `failed`

---

## 5. Handle Outcome (Internal / Webhook Ingress)

```
POST /v1/internal/payment/intents/{intentId}/attempts/{attemptId}/outcome
```

This endpoint is called by:
- `PaymentAttemptTimeoutJob` (after provider status query)
- `PaymentCallbackProcessingJob` (from provider webhook/callback)

**Not exposed on public customer API.**

**Request body**:
```json
{
  "outcome":              "succeeded | failed | cancelled",
  "provider_reference_id": "string (optional)",
  "captured_amount_minor": 12625,
  "failure_reason":       "string (optional)",
  "correlation_id":       "uuid"
}
```

**Authoritative evidence checks** (in order):
1. If `attempt.status.isTerminal()` AND same outcome AND same `provider_reference_id` → idempotent return (200).
2. If `attempt.status` is `succeeded` AND outcome is `failed` → create `CONTRADICTORY_EVIDENCE` exception, reject transition (409).
3. If `attempt.status.isTerminal()` AND outcome differs → create `CONTRADICTORY_EVIDENCE` exception, reject (409).
4. If outcome is `succeeded` AND `captured_amount_minor > intent.amount_minor + tolerance` → proceed with `succeeded` transition AND create `EXCESS_CAPTURE` exception.
5. Otherwise → normal transition.

---

## New Error Codes (P06 additions to OpenAPI `ErrorCode` enum)

| Error Code | Description |
|------------|-------------|
| `UNSAFE_PROVIDER_SWITCH` | Different provider requested when existing UNKNOWN attempt may have charged |
| `DUPLICATE_ATTEMPT` | Non-terminal attempt already exists for this intent and provider |
| `CONTRADICTORY_EVIDENCE` | Outcome conflicts with already-confirmed terminal state |
| `EXCESS_CAPTURE` | Captured amount exceeds authorised amount plus configured tolerance |
| `ATTEMPT_NOT_FOUND` | Payment attempt record not found |

---

## State Transition Summary

### `PaymentAttemptStatus`

```
                   ┌─────────────────────────────────────────┐
                   │                                         │
   SUBMITTING ──────┤────► PROCESSING ──────────────────────┤
         │          │           │                            │
         │          │           ▼                            ▼
         │          │    AWAITING_ACTION ──────────────► UNKNOWN ──► SUCCEEDED
         │          │           │                            │        (terminal)
         │          │           ▼                            │
         └──────────┴──────► FAILED (terminal)               └──► FAILED (terminal)
                              CANCELLED (terminal)                  EXPIRED (terminal)
                              EXPIRED (terminal)
```

### `PaymentIntentStatus` (unchanged topology)

- `PENDING` → `PROCESSING` (on first attempt start)
- `PROCESSING` → `SUCCEEDED` | `FAILED` | `AWAITING_ACTION` | `REQUIRES_CONFIRMATION` | `CANCELLED`
- Intent MUST NOT move to terminal while any attempt is `UNKNOWN`
