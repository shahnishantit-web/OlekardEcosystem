# API Contracts — Users, KYC, Wallets & Transactions (US-ADMINAPP-004–015)

## Common Headers

| Header | Required | Description |
|--------|----------|-------------|
| `Authorization` | Yes | `Bearer <admin_jwt>` |
| `X-Correlation-ID` | Yes | UUID v4 per request |
| `Idempotency-Key` | Conditional | Required for all write endpoints (POST/PATCH) |

## Error Codes

| Code | HTTP | Meaning |
|------|------|---------|
| `ADMINAPP_AUTH_001` | 401 | Missing or invalid admin JWT |
| `ADMINAPP_AUTH_002` | 403 | Insufficient role/permission |
| `ADMINAPP_USER_001` | 404 | User not found |
| `ADMINAPP_KYC_001` | 404 | KYC submission not found |
| `ADMINAPP_KYC_002` | 422 | KYC submission not in `pending_review` state |
| `ADMINAPP_WALLET_001` | 404 | Wallet not found |
| `ADMINAPP_WALLET_002` | 422 | Wallet not in eligible state for this action |
| `ADMINAPP_TXN_001` | 404 | Transaction not found |
| `ADMINAPP_TXN_002` | 422 | Transaction not eligible for reversal (wrong type or already reversed) |
| `ADMINAPP_015` | 422 | Transaction type not eligible for admin reversal |
| `ADMINAPP_APPROVAL_001` | 202 | Action queued for maker-checker approval |
| `ADMINAPP_IDEM_001` | 409 | Duplicate idempotency key with different payload |

---

## Users (US-ADMINAPP-004/005/006)

### GET /v1/admin-app/users

**Permission**: `adminapp.users.read`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-004

Search/filter users. All params are optional.

**Query parameters**:

| Param | Type | Notes |
|-------|------|-------|
| `q` | string | Full-text search: name, phone, email |
| `status` | enum | `active`, `suspended`, `closed` |
| `kyc_status` | enum | `not_started`, `pending`, `approved`, `rejected` |
| `kyc_tier` | int | 1, 2, 3 |
| `per_page` | int | Default 25, max 100 |
| `page` | int | 1-based |

**Response 200**:

```json
{
  "data": [
    {
      "user_id": "01jbxyz...",
      "full_name": "Kgomotso Modise",
      "phone_number": "+267 *** *** 789",
      "email": null,
      "account_status": "active",
      "kyc_status": "approved",
      "kyc_tier": 2,
      "registered_at": "2026-09-01T09:00:00Z",
      "last_active_at": "2026-10-05T07:12:00Z",
      "wallet_count": 1
    }
  ],
  "meta": {
    "total": 15234,
    "per_page": 25,
    "current_page": 1,
    "last_page": 610
  }
}
```

### GET /v1/admin-app/users/{user_id}

**Permission**: `adminapp.users.read`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-004

Returns full `AdminUserSearchResult` for a single user, plus full (unmasked) phone/email for authorized admins.

**Response 200**: Same shape as list item but with unmasked `phone_number` and `email`.

### PATCH /v1/admin-app/users/{user_id}/status

**Permission**: `adminapp.users.write`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-005  
**Requires**: `Idempotency-Key` header

Suspend or reactivate a user account. Emits `AUDIT_EVENT_RECORDED`.

**Request**:

```json
{
  "action": "suspend",
  "reason": "Suspicious activity detected by fraud team"
}
```

`action`: `suspend` | `reactivate`

**Response 200**:

```json
{
  "data": {
    "user_id": "01jbxyz...",
    "account_status": "suspended",
    "updated_at": "2026-10-05T08:50:00Z"
  }
}
```

### GET /v1/admin-app/roles

**Permission**: `adminapp.roles.manage`  
**Roles**: Admin  
**US**: US-ADMINAPP-006

**Response 200**:

```json
{
  "data": [
    {
      "role_id": "01jbxyz...",
      "role_name": "Operations",
      "permission_slugs": ["adminapp.dashboard.operational", "adminapp.users.read"],
      "user_count": 5,
      "created_at": "2026-08-01T00:00:00Z"
    }
  ]
}
```

### PATCH /v1/admin-app/roles/{role_id}/permissions

**Permission**: `adminapp.roles.manage`  
**Roles**: Admin  
**US**: US-ADMINAPP-006  
**Requires**: `Idempotency-Key` header

**Request**:

```json
{
  "permission_slugs": ["adminapp.dashboard.operational", "adminapp.users.read", "adminapp.wallets.read"]
}
```

**Response 200**: Returns updated `AdminRoleEntry`.

---

## KYC (US-ADMINAPP-007/008/009)

### GET /v1/admin-app/kyc

**Permission**: `adminapp.kyc.review`  
**Roles**: Admin, Compliance  
**US**: US-ADMINAPP-007

**Query parameters**:

| Param | Type | Notes |
|-------|------|-------|
| `status` | enum | `pending_review`, `approved`, `rejected` (default: `pending_review`) |
| `tier` | int | 1, 2, 3 |
| `per_page` | int | Default 25 |
| `page` | int | |

**Response 200**:

```json
{
  "data": [
    {
      "kyc_submission_id": "01jbxyz...",
      "user_id": "01jbxyz...",
      "user_full_name": "Kgomotso Modise",
      "user_phone_number": "+267 74 123 789",
      "requested_tier": 2,
      "current_tier": 1,
      "status": "pending_review",
      "document_type": "national_id",
      "document_front_url": "https://storage.example.com/signed/...",
      "document_back_url": "https://storage.example.com/signed/...",
      "submitted_at": "2026-10-04T14:30:00Z",
      "reviewed_at": null,
      "reviewed_by": null,
      "rejection_reason": null
    }
  ],
  "meta": { "total": 23, "per_page": 25, "current_page": 1, "last_page": 1 }
}
```

### POST /v1/admin-app/kyc/{kyc_submission_id}/approve

**Permission**: `adminapp.kyc.approve`  
**Roles**: Admin, Compliance  
**US**: US-ADMINAPP-008  
**Maker-checker**: Required  
**Requires**: `Idempotency-Key` header

**Request**: Empty body (or `{}`)

**Response 202** (queued for approval):

```json
{
  "data": {
    "approval_id": "01jbxyz...",
    "action_type": "adminapp.kyc.approve",
    "status": "pending_approval",
    "expires_at": "2026-10-07T08:50:00Z"
  }
}
```

**Response 422** (wrong state):

```json
{
  "error": {
    "code": "ADMINAPP_KYC_002",
    "message": "KYC submission is not in pending_review state.",
    "correlation_id": "uuid"
  }
}
```

### POST /v1/admin-app/kyc/{kyc_submission_id}/reject

**Permission**: `adminapp.kyc.reject`  
**Roles**: Admin, Compliance  
**US**: US-ADMINAPP-009  
**Maker-checker**: Required  
**Requires**: `Idempotency-Key` header

**Request**:

```json
{
  "reason": "Document unclear — unable to verify identity"
}
```

**Response 202**: Same shape as approve with `action_type: "adminapp.kyc.reject"`.

---

## Wallets (US-ADMINAPP-010/011/012)

### GET /v1/admin-app/wallets

**Permission**: `adminapp.wallets.read`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-010

**Query parameters**:

| Param | Type | Notes |
|-------|------|-------|
| `q` | string | Wallet number or owner name/phone |
| `status` | enum | `active`, `frozen`, `closed` |
| `wallet_type` | enum | `customer`, `agent_float`, `system` |
| `per_page` | int | Default 25 |
| `page` | int | |

**Response 200**:

```json
{
  "data": [
    {
      "wallet_id": "01jbxyz...",
      "wallet_number": "OLK-000123456",
      "owner_user_id": "01jbxyz...",
      "owner_name": "Kgomotso Modise",
      "wallet_type": "customer",
      "status": "active",
      "balance_minor_units": 245000,
      "currency_code": "BWP",
      "created_at": "2026-09-01T09:00:00Z",
      "frozen_at": null,
      "frozen_by": null,
      "freeze_reason": null
    }
  ],
  "meta": { "total": 15234, "per_page": 25, "current_page": 1, "last_page": 610 }
}
```

### POST /v1/admin-app/wallets/{wallet_id}/freeze

**Permission**: `adminapp.wallets.freeze`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-011  
**Maker-checker**: Required  
**Requires**: `Idempotency-Key` header

**Request**:

```json
{
  "reason": "Suspected money laundering — under investigation"
}
```

**Response 202** (queued for approval):

```json
{
  "data": {
    "approval_id": "01jbxyz...",
    "action_type": "adminapp.wallet.freeze",
    "status": "pending_approval",
    "expires_at": "2026-10-07T08:50:00Z"
  }
}
```

### POST /v1/admin-app/wallets/{wallet_id}/unfreeze

**Permission**: `adminapp.wallets.unfreeze`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-012  
**Maker-checker**: Required  
**Requires**: `Idempotency-Key` header

**Request**:

```json
{
  "reason": "Investigation complete — no fraud found"
}
```

**Response 202**: Same shape with `action_type: "adminapp.wallet.unfreeze"`.

---

## Transactions (US-ADMINAPP-013/014/015)

### GET /v1/admin-app/transactions

**Permission**: `adminapp.transactions.read`  
**Roles**: Admin, Operations, Finance  
**US**: US-ADMINAPP-013

**Query parameters**:

| Param | Type | Notes |
|-------|------|-------|
| `q` | string | Reference number or user name/phone |
| `type` | enum | `wallet_transfer`, `agent_cash_in`, `agent_cash_out`, `funding`, `withdrawal`, `bill_payment` |
| `status` | enum | `pending`, `completed`, `failed`, `reversed` |
| `from_date` | ISO 8601 date | Inclusive |
| `to_date` | ISO 8601 date | Inclusive |
| `min_amount` | int | Minor units |
| `max_amount` | int | Minor units |
| `per_page` | int | Default 25 |
| `page` | int | |

**Response 200**:

```json
{
  "data": [
    {
      "transaction_id": "01jbxyz...",
      "reference_number": "TXN-20261005-001234",
      "type": "wallet_transfer",
      "status": "completed",
      "amount_minor_units": 50000,
      "currency_code": "BWP",
      "fee_minor_units": 100,
      "initiator_user_id": "01jbxyz...",
      "initiator_name": "Kgomotso Modise",
      "source_wallet_id": "01jbxyz...",
      "destination_wallet_id": "01jbxyz...",
      "created_at": "2026-10-05T07:00:00Z",
      "completed_at": "2026-10-05T07:00:01Z",
      "reversal_eligible": true,
      "reversal_id": null,
      "fraud_flags": [],
      "idempotency_key": "cli-transfer-abc123"
    }
  ],
  "meta": { "total": 8921, "per_page": 25, "current_page": 1, "last_page": 357 }
}
```

### GET /v1/admin-app/transactions/{transaction_id}

**Permission**: `adminapp.transactions.investigate`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-014

Returns full transaction detail including ledger entries (read-only via domain service). Emits `AUDIT_EVENT_RECORDED` (investigation logged).

**Response 200**: Same as list item shape, plus:

```json
{
  "data": {
    "...all AdminTransactionSearchResult fields...",
    "ledger_entries": [
      {
        "entry_id": "01jbxyz...",
        "ledger_account": "customer.kgomotso.debit",
        "amount_minor_units": 50100,
        "direction": "debit",
        "posted_at": "2026-10-05T07:00:01Z"
      }
    ],
    "fraud_assessment": {
      "risk_score": 12,
      "flags": [],
      "assessed_at": "2026-10-05T07:00:00Z"
    }
  }
}
```

### POST /v1/admin-app/transactions/{transaction_id}/reverse

**Permission**: `adminapp.transactions.reverse`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-015  
**Maker-checker**: Required  
**Requires**: `Idempotency-Key` header  
**Scope**: `wallet_transfer`, `agent_cash_in`, `agent_cash_out` only

**Request**:

```json
{
  "reason": "Customer dispute resolved in customer's favour"
}
```

**Response 202** (queued for approval):

```json
{
  "data": {
    "approval_id": "01jbxyz...",
    "action_type": "adminapp.transaction.reverse",
    "status": "pending_approval",
    "expires_at": "2026-10-07T08:50:00Z"
  }
}
```

**Response 422** (ineligible type):

```json
{
  "error": {
    "code": "ADMINAPP_015",
    "message": "Transaction type 'funding' is not eligible for admin reversal. Only wallet_transfer, agent_cash_in, and agent_cash_out transactions can be reversed.",
    "correlation_id": "uuid"
  }
}
```

**Response 422** (already reversed):

```json
{
  "error": {
    "code": "ADMINAPP_TXN_002",
    "message": "Transaction has already been reversed.",
    "correlation_id": "uuid"
  }
}
```
