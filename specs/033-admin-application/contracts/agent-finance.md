# API Contracts — Agents & Finance (US-ADMINAPP-016–020)

## Common Headers

| Header | Required | Description |
|--------|----------|-------------|
| `Authorization` | Yes | `Bearer <admin_jwt>` |
| `X-Correlation-ID` | Yes | UUID v4 per request |
| `Idempotency-Key` | Conditional | Required for all write endpoints |

## Error Codes

| Code | HTTP | Meaning |
|------|------|---------|
| `ADMINAPP_AUTH_001` | 401 | Missing or invalid admin JWT |
| `ADMINAPP_AUTH_002` | 403 | Insufficient role/permission |
| `ADMINAPP_AGENT_001` | 404 | Agent not found |
| `ADMINAPP_AGENT_002` | 422 | Agent not in `pending_approval` state |
| `ADMINAPP_FLOAT_001` | 422 | Float adjustment would exceed limit |
| `ADMINAPP_RECON_001` | 404 | Reconciliation batch not found |
| `ADMINAPP_SETTLE_001` | 404 | Settlement record not found |

---

## Agents (US-ADMINAPP-016/017/018)

### GET /v1/admin-app/agents

**Permission**: `adminapp.agents.manage`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-017

**Query parameters**:

| Param | Type | Notes |
|-------|------|-------|
| `q` | string | Name, phone, business name |
| `status` | enum | `pending_approval`, `active`, `suspended`, `terminated` |
| `per_page` | int | Default 25, max 100 |
| `page` | int | |

**Response 200**:

```json
{
  "data": [
    {
      "agent_id": "01jbxyz...",
      "user_id": "01jbxyz...",
      "full_name": "Thabo Sithole",
      "phone_number": "+267 74 555 001",
      "business_name": "Sithole General Store",
      "agent_status": "active",
      "approved_at": "2026-09-10T10:00:00Z",
      "approved_by": "Admin User",
      "float_balance_minor_units": 500000,
      "float_currency": "BWP",
      "float_limit_minor_units": 5000000,
      "commission_rate": 0.015,
      "total_cash_in_today_minor_units": 120000,
      "total_cash_out_today_minor_units": 85000,
      "location": "Gaborone, Plot 12345"
    }
  ],
  "meta": { "total": 312, "per_page": 25, "current_page": 1, "last_page": 13 }
}
```

### GET /v1/admin-app/agents/{agent_id}

**Permission**: `adminapp.agents.manage`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-017

**Response 200**: Full `AdminAgentProfile` for single agent.

### POST /v1/admin-app/agents/{agent_id}/approve

**Permission**: `adminapp.agents.approve`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-016  
**Maker-checker**: Required  
**Requires**: `Idempotency-Key` header

**Request**: Empty body (or `{}`)

**Response 202** (queued for approval):

```json
{
  "data": {
    "approval_id": "01jbxyz...",
    "action_type": "adminapp.agent.approve",
    "status": "pending_approval",
    "expires_at": "2026-10-07T09:00:00Z"
  }
}
```

**Response 422** (not in pending state):

```json
{
  "error": {
    "code": "ADMINAPP_AGENT_002",
    "message": "Agent is not in pending_approval state.",
    "correlation_id": "uuid"
  }
}
```

### PATCH /v1/admin-app/agents/{agent_id}/status

**Permission**: `adminapp.agents.manage`  
**Roles**: Admin, Operations  
**US**: US-ADMINAPP-017  
**Requires**: `Idempotency-Key` header

Suspend or reactivate an agent. Emits `AUDIT_EVENT_RECORDED`.

**Request**:

```json
{
  "action": "suspend",
  "reason": "Repeated float shortfall"
}
```

`action`: `suspend` | `reactivate`

**Response 200**:

```json
{
  "data": {
    "agent_id": "01jbxyz...",
    "agent_status": "suspended",
    "updated_at": "2026-10-05T09:00:00Z"
  }
}
```

---

## Float Management (US-ADMINAPP-018)

### GET /v1/admin-app/float

**Permission**: `adminapp.float.manage`  
**Roles**: Admin, Finance  
**US**: US-ADMINAPP-018

List agent float balances. Supports same filtering as agents list.

**Response 200**:

```json
{
  "data": [
    {
      "agent_id": "01jbxyz...",
      "full_name": "Thabo Sithole",
      "float_balance_minor_units": 500000,
      "float_currency": "BWP",
      "float_limit_minor_units": 5000000,
      "utilization_pct": 10.0,
      "last_top_up_at": "2026-10-04T14:00:00Z"
    }
  ],
  "meta": { "total": 312, "per_page": 25, "current_page": 1, "last_page": 13 }
}
```

### POST /v1/admin-app/float/{agent_id}/adjust

**Permission**: `adminapp.float.manage`  
**Roles**: Admin, Finance  
**US**: US-ADMINAPP-018  
**Requires**: `Idempotency-Key` header

Adjust (top-up or deduct) agent float. Delegates to `AgentFloatService` (E13). Emits `AUDIT_EVENT_RECORDED`.

**Request**:

```json
{
  "direction": "credit",
  "amount_minor_units": 200000,
  "reason": "Float top-up authorized by Operations Manager"
}
```

`direction`: `credit` (top-up) | `debit` (deduct)

**Response 200**:

```json
{
  "data": {
    "agent_id": "01jbxyz...",
    "previous_balance_minor_units": 500000,
    "adjustment_minor_units": 200000,
    "new_balance_minor_units": 700000,
    "direction": "credit",
    "adjusted_at": "2026-10-05T09:05:00Z"
  }
}
```

**Response 422** (would exceed limit):

```json
{
  "error": {
    "code": "ADMINAPP_FLOAT_001",
    "message": "Float adjustment of 200000 would exceed the agent's float limit of 5000000. Current balance: 4900000.",
    "correlation_id": "uuid"
  }
}
```

---

## Reconciliation (US-ADMINAPP-019)

### GET /v1/admin-app/reconciliation

**Permission**: `adminapp.reconciliation.read`  
**Roles**: Admin, Finance  
**US**: US-ADMINAPP-019

**Query parameters**:

| Param | Type | Notes |
|-------|------|-------|
| `status` | enum | `pending`, `in_progress`, `completed`, `failed` |
| `from_date` | ISO 8601 date | Filter by period_start |
| `to_date` | ISO 8601 date | Filter by period_end |
| `per_page` | int | Default 25 |
| `page` | int | |

**Response 200**:

```json
{
  "data": [
    {
      "batch_id": "01jbxyz...",
      "period_start": "2026-10-04",
      "period_end": "2026-10-04",
      "status": "completed",
      "total_transactions": 8921,
      "matched_count": 8918,
      "unmatched_count": 3,
      "discrepancy_amount_minor_units": 1500,
      "currency_code": "BWP",
      "run_at": "2026-10-05T02:00:00Z",
      "completed_at": "2026-10-05T02:15:00Z"
    }
  ],
  "meta": { "total": 30, "per_page": 25, "current_page": 1, "last_page": 2 }
}
```

### GET /v1/admin-app/reconciliation/{batch_id}

**Permission**: `adminapp.reconciliation.read`  
**Roles**: Admin, Finance  
**US**: US-ADMINAPP-019

Returns full batch detail including unmatched transaction IDs.

**Response 200**:

```json
{
  "data": {
    "...all ReconciliationSummary fields...",
    "unmatched_transaction_ids": ["01jbxyz...", "01jbxyz...", "01jbxyz..."]
  }
}
```

---

## Settlement (US-ADMINAPP-020)

### GET /v1/admin-app/settlement

**Permission**: `adminapp.settlement.read`  
**Roles**: Admin, Finance  
**US**: US-ADMINAPP-020

**Query parameters**:

| Param | Type | Notes |
|-------|------|-------|
| `status` | enum | `pending`, `processing`, `completed`, `failed` |
| `from_date` | ISO 8601 date | |
| `to_date` | ISO 8601 date | |
| `per_page` | int | Default 25 |
| `page` | int | |

**Response 200**:

```json
{
  "data": [
    {
      "settlement_id": "01jbxyz...",
      "settlement_date": "2026-10-04",
      "status": "completed",
      "total_amount_minor_units": 85000000,
      "currency_code": "BWP",
      "net_position_minor_units": 12500000,
      "provider": "First National Bank Botswana",
      "reference": "FNB-SETTLE-20261004-001"
    }
  ],
  "meta": { "total": 30, "per_page": 25, "current_page": 1, "last_page": 2 }
}
```

### GET /v1/admin-app/settlement/{settlement_id}

**Permission**: `adminapp.settlement.read`  
**Roles**: Admin, Finance  
**US**: US-ADMINAPP-020

**Response 200**: Full `SettlementSummary` for a single settlement record.
