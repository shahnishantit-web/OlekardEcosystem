# Backend API Endpoints: P22 — Shared Flutter Checkout

**Date**: 2026-10-26 | **Source**: P06 (payment state engine) — COMPLETE/RELEASE READY

The shared package consumes the following backend endpoints from the Payment module. No new backend endpoints are required for P22 — this is a mobile client extraction.

---

## Endpoints Consumed

### GET /api/v1/payment/options

Retrieve available payment options for the current context.

**Query Parameters**:
| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `purpose_type` | string | Yes | e.g., `bill_payment`, `transfer`, `top_up` |
| `purpose_reference` | string | Yes | Server-issued payable reference |
| `currency_code` | string | Yes | ISO 4217 code |
| `estimated_amount_minor` | integer | Yes | Filtering hint; not the charge amount |

**Response** `200 OK`:
```json
{
  "data": [
    {
      "provider_code": "olekard_wallet",
      "display_name": "OleKard Wallet",
      "is_wallet": true,
      "wallet_balance_minor": 50000,
      "has_sufficient_balance": true,
      "fee_disclosure": {
        "service_fee_minor": 0,
        "provider_fee_minor": 0,
        "total_fee_minor": 0,
        "total_payable_minor": 10000
      },
      "supported_next_action": "wallet_pin",
      "restrictions": []
    },
    {
      "provider_code": "fnb_redirect",
      "display_name": "First National Bank",
      "is_wallet": false,
      "fee_disclosure": {
        "service_fee_minor": 100,
        "provider_fee_minor": 50,
        "total_fee_minor": 150,
        "total_payable_minor": 10150
      },
      "supported_next_action": "redirect",
      "restrictions": []
    }
  ]
}
```

---

### POST /api/v1/payment/intents

Create a payment intent. Amount is server-resolved from the purpose reference.

**Headers**:
- `Idempotency-Key: <uuid>` — required; reused on retry

**Request Body**:
```json
{
  "purpose_type": "bill_payment",
  "purpose_reference": "bp-ref-abc123",
  "currency_code": "BWP"
}
```

**Response** `201 Created`:
```json
{
  "data": {
    "id": "01922a4b-...",
    "purpose_type": "bill_payment",
    "purpose_reference": "bp-ref-abc123",
    "organisation_id": "...",
    "payer_actor_id": "...",
    "currency_code": "BWP",
    "amount_minor": 10000,
    "status": "pending",
    "idempotency_key": "...",
    "correlation_id": "...",
    "created_at": "2026-10-26T10:00:00Z",
    "expires_at": "2026-10-26T10:30:00Z"
  }
}
```

---

### POST /api/v1/payment/intents/{intentId}/attempts

Initiate a payment attempt for an intent.

**Headers**:
- `Idempotency-Key: <uuid>` — required; reused on retry for same attempt

**Request Body**:
```json
{
  "provider_code": "fnb_redirect",
  "step_up_token": "..."  // present only for wallet_pin next action
}
```

**Response** `200 OK`**:
```json
{
  "data": {
    "attempt_id": "...",
    "next_action_type": "redirect",
    "next_action_data": {
      "redirect_url": "https://fnb.co.bw/pay?ref=...",
      "allowed_return_schemes": ["olekard", "wallet-customer"],
      "expires_at": "2026-10-26T10:15:00Z"
    }
  }
}
```

**next_action_type values**:
| Value | next_action_data fields |
|-------|------------------------|
| `wallet_pin` | (none — synchronous) |
| `redirect` | `redirect_url`, `allowed_return_schemes[]`, `expires_at?` |
| `sdk_launch` | `sdk_id`, `sdk_params{}` |
| `app_switch` | `deep_link_url`, `fallback_url?` |
| `provider_unavailable` | `reason` |

**Error responses**:
- `409 Conflict` with `STEP_UP_REQUIRED` — wallet_pin step-up not provided
- `422 Unprocessable` with `INSUFFICIENT_FUNDS` — wallet balance below required
- `503 Service Unavailable` with `PROVIDER_UNAVAILABLE` — provider down

---

### GET /api/v1/payment/intents/{intentId}/status

Poll for current intent status. Read-only; no idempotency key required.

**Response** `200 OK`:
```json
{
  "data": {
    "status": "processing",
    "updated_at": "2026-10-26T10:01:00Z"
  }
}
```

**Status values**: `pending`, `processing`, `awaiting_action`, `requires_confirmation`, `fulfilling`, `succeeded`, `failed`, `cancelled`, `expired`, `refunding`, `partially_refunded`, `refunded`

---

## Return Route Validation

The `allowed_return_schemes` list from the `redirect` next action is used as the client-side allowlist. On app resume, the `PaymentCheckoutSheet` checks the incoming route scheme/path against this list before triggering post-redirect polling.

**Important**: A scheme match is a signal to begin polling — it is NOT payment confirmation. The authoritative outcome comes from the `/status` endpoint.

---

## Step-Up Authentication (reference)

Step-up is performed by the consuming app's `StepUpService` implementation, not by the shared package. The shared package only receives the resulting token and forwards it in the `step_up_token` field of the attempt request.

Step-up endpoints (consumed by each app, not by shared package):
- `POST /v1/auth/step-up/initiate`
- `POST /v1/auth/step-up/verify`
