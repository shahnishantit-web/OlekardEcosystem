# API Contract — E06 Wallet Management

All endpoints follow platform conventions (constitution Principle 8):
- Base URL: `/v1/wallets`
- Authentication: `Authorization: Bearer <token>` (required on all endpoints)
- Correlation: `X-Correlation-ID: <uuid>` (request and response header)
- Idempotency: `Idempotency-Key: <client-uuid>` (required on all mutation endpoints)
- Response envelope: `{ "success": bool, "data": {...}|null, "error": {...}|null, "correlation_id": "..." }`
- Timestamps: ISO 8601 UTC (e.g., `2026-09-16T14:30:00Z`)
- IDs: UUID v7 strings
- Amounts: integer minor units + separate `precision` field

---

## Endpoints

### POST /v1/wallets
Create a wallet manually (Admin use; auto-creation via kyc.case.approved is primary path).

**Permission**: `wallet.create`
**Idempotency-Key**: Required

**Request Body**:
```json
{
  "user_id": "01928e7b-0000-7000-0000-000000000001"
}
```

**Response 201**:
```json
{
  "success": true,
  "data": {
    "id": "01928e7b-0000-7000-0000-000000000010",
    "wallet_reference": "WLT-20260918-AB3D7F2K",
    "owner_id": "01928e7b-0000-7000-0000-000000000001",
    "status": "pending",
    "created_at": "2026-09-18T09:00:00Z",
    "updated_at": "2026-09-18T09:00:00Z"
  },
  "error": null,
  "correlation_id": "corr-001"
}
```

**Error Responses**:
- `409 WALLET_ALREADY_EXISTS` — user already has an active wallet
- `422` — validation failure (missing user_id)
- `401` / `403` — auth/permission failure

---

### GET /v1/wallets/my
Get the authenticated user's wallet.

**Permission**: `wallet.read`

**Response 200**:
```json
{
  "success": true,
  "data": {
    "id": "01928e7b-0000-7000-0000-000000000010",
    "wallet_reference": "WLT-20260918-AB3D7F2K",
    "owner_id": "01928e7b-0000-7000-0000-000000000001",
    "status": "active",
    "pockets": [
      { "id": "...", "currency_code": "BWP", "status": "active" }
    ],
    "created_at": "2026-09-18T09:00:00Z",
    "updated_at": "2026-09-18T09:00:00Z"
  },
  "error": null,
  "correlation_id": "corr-002"
}
```

---

### GET /v1/wallets/{id}
Get wallet by ID. Customers may only access their own wallet (returns 404 for others — IDOR prevention).

**Permission**: `wallet.read` (own) / `wallet.read.any` (Admin)

**Response 200**: Same shape as GET /v1/wallets/my

**Error Responses**:
- `404 WALLET_NOT_FOUND` — not found or not owned by requester

---

### PATCH /v1/wallets/{id}/status
Change wallet status (Admin only; enforces valid state transitions).

**Permission**: `wallet.status.manage`
**Idempotency-Key**: Required

**Request Body**:
```json
{
  "status": "active",
  "reason": "KYC re-verification passed"
}
```

**Response 200**: Updated wallet object

**Error Responses**:
- `422 INVALID_WALLET_TRANSITION` — invalid state transition
- `422 WALLET_NOT_EDITABLE` — wallet in non-editable state

---

### POST /v1/wallets/{id}/freeze
Freeze an active wallet.

**Permission**: `wallet.freeze`
**Idempotency-Key**: Required

**Request Body**:
```json
{
  "reason": "Compliance investigation — ticket CMP-1234"
}
```

**Response 200**: Updated wallet object with `status: "frozen"`

**Error Responses**:
- `422 INVALID_WALLET_TRANSITION` — wallet not in freezable state (e.g., already frozen with different key, or closed)

---

### POST /v1/wallets/{id}/unfreeze
Unfreeze a frozen wallet.

**Permission**: `wallet.unfreeze`
**Idempotency-Key**: Required

**Request Body**:
```json
{
  "reason": "Investigation closed — no action required"
}
```

**Response 200**: Updated wallet object with `status: "active"`

**Error Responses**:
- `422 INVALID_WALLET_TRANSITION` — wallet not frozen

---

### POST /v1/wallets/{id}/close
Close a wallet permanently.

**Permission**: `wallet.close`
**Idempotency-Key**: Required

**Request Body**:
```json
{
  "reason": "Customer requested account closure"
}
```

**Response 200**: Updated wallet object with `status: "closed"`

**Error Responses**:
- `422 WALLET_HAS_BALANCE` — one or more pockets have non-zero balance
- `422 INVALID_WALLET_TRANSITION` — wallet already in terminal/non-closable state

---

### GET /v1/wallets/{id}/limits
View configured limits for all pockets.

**Permission**: `wallet.read`

**Response 200**:
```json
{
  "success": true,
  "data": {
    "limits": [
      {
        "currency_code": "BWP",
        "limit_type": "daily_transaction_amount",
        "limit_value": null,
        "is_tbd": true,
        "effective_from": "2026-09-18T09:00:00Z"
      }
    ]
  },
  "error": null,
  "correlation_id": "corr-005"
}
```

---

### PUT /v1/wallets/{id}/limits
Configure limits (Admin only).

**Permission**: `wallet.limits.manage`
**Idempotency-Key**: Required

**Request Body**:
```json
{
  "limits": [
    {
      "currency_code": "BWP",
      "limit_type": "daily_transaction_amount",
      "limit_value": 500000
    }
  ]
}
```

**Response 200**: Updated limits array

**Error Responses**:
- `422 WALLET_NOT_EDITABLE` — wallet is frozen or closed

---

### GET /v1/wallets/{id}/pockets
List all currency pockets on the wallet.

**Permission**: `wallet.read`

**Response 200**:
```json
{
  "success": true,
  "data": {
    "pockets": [
      {
        "id": "...",
        "currency_code": "BWP",
        "status": "active",
        "created_at": "2026-09-18T09:00:00Z"
      }
    ]
  },
  "error": null,
  "correlation_id": "corr-006"
}
```

---

### POST /v1/wallets/{id}/pockets
Add a new currency pocket.

**Permission**: `wallet.pocket.create`
**Idempotency-Key**: Required

**Request Body**:
```json
{
  "currency_code": "USD"
}
```

**Response 201**: New pocket object

**Error Responses**:
- `409 POCKET_ALREADY_EXISTS` — pocket for this currency already exists
- `422 UNSUPPORTED_CURRENCY` — currency code not in supported list
- `422 CURRENCY_NOT_AVAILABLE` — currency is disabled

---

### GET /v1/wallets/{id}/pockets/{code}/balance
Get balance for a specific currency pocket.

**Permission**: `wallet.balance.read`

**Response 200**:
```json
{
  "success": true,
  "data": {
    "wallet_id": "...",
    "currency_code": "BWP",
    "precision": 2,
    "ledger_balance": 50000,
    "held_amount": 5000,
    "available_balance": 45000,
    "pending_balance": 0,
    "wallet_status": "active",
    "as_of_timestamp": "2026-09-18T10:30:00Z"
  },
  "error": null,
  "correlation_id": "corr-007"
}
```

**Notes**:
- `pending_balance` is `0` until E11 is implemented (FR-E06-020)
- `as_of_timestamp` is the UTC moment this snapshot was computed (RFC 3339)
- Read is permitted even on frozen wallets; response includes `wallet_status`

**Error Responses**:
- `404 WALLET_NOT_FOUND` — wallet not found, pocket not found, or IDOR
- `503` — E07 LedgerServiceContract unavailable (pre-E07 environment)

---

### GET /v1/wallets/{id}/status-history
Get immutable wallet status transition history.

**Permission**: `wallet.read`
**Pagination**: cursor-based, ascending `occurred_at`

**Query Parameters**:
- `cursor` (optional): opaque cursor from previous response
- `limit` (optional): page size, default 20, max 100

**Response 200**:
```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": "...",
        "from_status": null,
        "to_status": "pending",
        "reason": null,
        "actor_id": "system",
        "occurred_at": "2026-09-18T09:00:00Z"
      }
    ],
    "pagination": {
      "next_cursor": "eyJvY2N1cnJlZF9hdCI6...",
      "has_more": false
    }
  },
  "error": null,
  "correlation_id": "corr-008"
}
```

---

## Error Response Shape

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "WALLET_FROZEN",
    "message": "The wallet is frozen and cannot participate in transactions.",
    "details": {}
  },
  "correlation_id": "corr-009"
}
```

---

## Domain Events (Outbox)

All events are published to `platform.outbox_events` within the same database transaction as the state change (FR-E06-037). Event names use dot-separated PascalCase aggregate.action format.

| Event Name (outbox) | Trigger | Key Payload Fields |
|---|---|---|
| `Wallet.Created` | Wallet provisioned | `wallet_id`, `owner_id`, `correlation_id` |
| `Wallet.Activated` | Status → active | `wallet_id`, `owner_id`, `actor_id` |
| `Wallet.Pocket.Added` | New pocket created | `wallet_id`, `pocket_id`, `currency_code` |
| `Wallet.Status.Changed` | Any status transition | `wallet_id`, `from_status`, `to_status`, `actor_id`, `reason` |
| `Wallet.Frozen` | Status → frozen | `wallet_id`, `actor_id`, `reason` |
| `Wallet.Unfrozen` | Frozen → active | `wallet_id`, `actor_id`, `reason` |
| `Wallet.Closed` | Status → closed | `wallet_id`, `actor_id`, `reason` |
| `Wallet.Limits.Configured` | Limits updated | `wallet_id`, `actor_id`, `changes` (array) |
| `Wallet.Hold.Expired` | Hold expired by job | `hold_id`, `pocket_id`, `amount`, `expired_at` |

Event names use PascalCase dot notation (e.g., `Wallet.Created`) per the platform convention established by E05 (`Kyc.Case.Approved`). Audit event `action` field values in `platform.audit_events` use lowercase dot notation (e.g., `wallet.created`) — these are a separate concern.

Event payloads MUST NOT include PII beyond `owner_id` (UUID) and wallet identifiers (SR-E06-011).
