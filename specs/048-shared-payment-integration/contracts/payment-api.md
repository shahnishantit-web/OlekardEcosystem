# REST API Contract: Payment Module (E48)

**Base path**: `/v1/payment`
**Auth**: Bearer token (all endpoints require authenticated actor)
**Idempotency**: `Idempotency-Key` header required on all mutating endpoints
**Content-Type**: `application/json`

---

## Endpoints

### GET /v1/payment/options

Retrieve available payment options for a given payment context. Server-side filtered by organisation, module, currency, amount, country, and provider status.

**Query Parameters**:

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `purpose_type` | string | Yes | e.g., `funeral_contribution`, `event_ticket` |
| `purpose_reference` | UUID | Yes | ID of the purpose entity |
| `currency` | string | Yes | ISO 4217 code |
| `amount` | integer | Yes | Minor currency units (advisory; server re-validates) |

**Response 200**:
```json
{
  "data": {
    "payment_context": {
      "purpose_type": "funeral_contribution",
      "purpose_reference": "01929f3b-...",
      "description": "Funeral Contribution — Moeng Family",
      "recipient_name": "Moeng Family Fund",
      "base_amount_minor": 10000,
      "currency_code": "BWP",
      "disclosed_fee_minor": 0,
      "total_amount_minor": 10000
    },
    "options": [
      {
        "provider_code": "olekard_wallet",
        "display_name": "OleKard Wallet",
        "is_default": true,
        "wallet_balance_minor": 50000,
        "has_sufficient_balance": true,
        "sort_order": 1
      },
      {
        "provider_code": "mock_bank",
        "display_name": "Development Mock Bank",
        "is_default": false,
        "checkout_flow": "redirect",
        "sort_order": 2
      }
    ]
  }
}
```

**Errors**: `400` (invalid params), `422` (purpose not found or not payable)

---

### POST /v1/payment/intents

Create a payment intent. Idempotent on `Idempotency-Key` + `payer_actor_id`.

**Request Headers**: `Idempotency-Key: <uuid>`

**Request Body**:
```json
{
  "purpose_type": "funeral_contribution",
  "purpose_reference": "01929f3b-...",
  "organisation_id": "01929f3a-...",
  "currency_code": "BWP",
  "provider_code": "olekard_wallet",
  "provider_account_id": "01929f3c-...",
  "metadata": {}
}
```

**Note**: `amount_minor` is NOT accepted from the client. It is resolved server-side via `BusinessPurposeResolverInterface`.

**Response 201**:
```json
{
  "data": {
    "payment_intent_id": "01929f3d-...",
    "payment_intent_reference": "PAY-20261018-ABCD",
    "status": "pending",
    "amount_minor": 10000,
    "currency_code": "BWP",
    "expires_at": "2026-10-18T12:45:00Z",
    "correlation_id": "01929f3e-..."
  }
}
```

**Errors**: `409` (duplicate active intent for same purpose), `422` (purpose not payable), `404` (provider not found or unavailable)

---

### POST /v1/payment/intents/{intentId}/attempts

Initiate a payment attempt for an existing intent.

**Request Headers**: `Idempotency-Key: <uuid>`

**Request Body**:
```json
{
  "provider_code": "olekard_wallet",
  "step_up_token": "optional-step-up-token"
}
```

**Response 200**:
```json
{
  "data": {
    "payment_attempt_id": "01929f3f-...",
    "status": "succeeded",
    "next_action": null,
    "payment_intent_status": "succeeded",
    "correlation_id": "01929f3e-..."
  }
}
```

For redirect flow:
```json
{
  "data": {
    "payment_attempt_id": "01929f40-...",
    "status": "awaiting_action",
    "next_action": {
      "type": "redirect",
      "redirect_url": "https://mock-bank.local/checkout?ref=...",
      "expires_at": "2026-10-18T12:30:00Z"
    },
    "payment_intent_status": "awaiting_action",
    "correlation_id": "01929f3e-..."
  }
}
```

**Errors**: `409` (active attempt already exists), `402` (insufficient wallet funds), `403` (step-up required), `422` (intent not in initiatable state), `503` (provider circuit open)

---

### GET /v1/payment/intents/{intentId}/status

Poll authoritative payment status. Called after redirect return — NEVER trust redirect params alone.

**Response 200**:
```json
{
  "data": {
    "payment_intent_id": "01929f3d-...",
    "reference": "PAY-20261018-ABCD",
    "status": "succeeded",
    "amount_minor": 10000,
    "currency_code": "BWP",
    "succeeded_at": "2026-10-18T12:20:05Z",
    "active_attempt": {
      "id": "01929f40-...",
      "provider_code": "mock_bank",
      "status": "succeeded"
    }
  }
}
```

**Errors**: `403` (not owned by actor), `404`

---

### POST /v1/payment/callbacks/{providerCode}

Receive provider webhook callback. Raw body must be preserved for HMAC verification.

**Note**: This endpoint is called by external providers, not mobile clients. It is unauthenticated but signature-verified.

**Request**: Raw body as sent by provider; `X-Signature` (or provider-specific header) included.

**Response 202**: Always returns 202 if the raw body was persisted, regardless of processing outcome.

```json
{ "received": true }
```

**Response 400**: Only if the request is structurally invalid (e.g., empty body).

---

### POST /v1/payment/intents/{intentId}/refunds

Request a refund for a succeeded payment intent.

**Request Headers**: `Idempotency-Key: <uuid>` (Bearer token of requesting staff user)

**Request Body**:
```json
{
  "amount_minor": 5000,
  "reason": "Customer cancelled within policy period"
}
```

**Response 201**:
```json
{
  "data": {
    "refund_id": "01929f41-...",
    "status": "requested",
    "amount_minor": 5000,
    "requires_approval": false,
    "correlation_id": "01929f3e-..."
  }
}
```

**Errors**: `402` (amount exceeds refundable balance), `409` (duplicate refund key), `422` (intent not refundable or provider lacks refund capability), `403` (insufficient permission)

---

## Admin Endpoints

### GET /v1/admin/payment/providers

List all payment providers. Requires `payment.providers.read`.

**Response 200**: Paginated list of providers with account summaries.

### POST /v1/admin/payment/providers

Register a new provider. Requires `payment.providers.manage`.

### PUT /v1/admin/payment/providers/{id}

Update provider configuration. High-risk fields (merchant_account_reference) trigger approval workflow. Requires `payment.providers.manage`.

### POST /v1/admin/payment/providers/{id}/suspend

Suspend a provider (stops new payments; existing in-flight continue). Requires `payment.providers.manage`.

### GET /v1/admin/payment/reconciliation/exceptions

List reconciliation exceptions. Requires `payment.reconciliation.view`.

### PATCH /v1/admin/payment/reconciliation/exceptions/{id}

Update exception status and notes. Requires `payment.reconciliation.manage`.

---

## Error Envelope

All errors follow the existing platform error envelope:

```json
{
  "error": {
    "code": "INSUFFICIENT_FUNDS",
    "message": "Wallet does not have sufficient funds for this payment",
    "correlation_id": "01929f3e-...",
    "details": {}
  }
}
```

**Error codes**:

| Code | HTTP | Meaning |
|------|------|---------|
| `INSUFFICIENT_FUNDS` | 402 | Wallet balance too low |
| `STEP_UP_REQUIRED` | 403 | Step-up authentication required |
| `DUPLICATE_ACTIVE_ATTEMPT` | 409 | Cannot create new attempt |
| `INTENT_NOT_INITIATABLE` | 422 | Intent in wrong state |
| `PROVIDER_UNAVAILABLE` | 503 | Circuit open or provider suspended |
| `REFUND_LIMIT_EXCEEDED` | 402 | Cumulative refund would exceed original |
| `CAPABILITY_UNSUPPORTED` | 422 | Provider does not support this operation |
| `PRODUCTION_BLOCKED` | 403 | Mock adapter attempted in production |
| `INTENT_EXPIRED` | 410 | Intent has passed expires_at |
