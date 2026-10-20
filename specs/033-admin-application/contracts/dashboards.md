# API Contracts — Dashboards (US-ADMINAPP-001/002/003)

## Common Headers

| Header | Required | Description |
|--------|----------|-------------|
| `Authorization` | Yes | `Bearer <admin_jwt>` |
| `X-Correlation-ID` | Yes | UUID v4 per request |
| `Accept` | No | `application/json` (default) |

## Error Envelope

```json
{
  "error": {
    "code": "ADMINAPP_001",
    "message": "Human-readable description",
    "correlation_id": "uuid"
  }
}
```

## Error Codes

| Code | HTTP | Meaning |
|------|------|---------|
| `ADMINAPP_AUTH_001` | 401 | Missing or invalid admin JWT |
| `ADMINAPP_AUTH_002` | 403 | Insufficient role/permission for endpoint |
| `ADMINAPP_DASH_001` | 503 | Dashboard cache unavailable (Redis down) |

---

## GET /v1/admin-app/dashboard/operational

**Permission**: `adminapp.dashboard.operational`  
**Roles**: Admin, Operations, Finance, Compliance  
**US**: US-ADMINAPP-001

Returns pre-aggregated operational metrics from the Redis cache. The cache is refreshed every 5 minutes by the `adminapp:refresh-dashboard-cache` Artisan command.

### Response 200

```json
{
  "data": {
    "cached_at": "2026-10-05T08:45:00Z",
    "total_users": 15234,
    "active_users_today": 3108,
    "new_registrations_today": 47,
    "pending_kyc_count": 23,
    "approved_kyc_today": 15,
    "total_transactions_today": 8921,
    "transaction_volume_today_minor_units": 450000000,
    "transaction_currency": "BWP",
    "active_agents": 312,
    "pending_agent_approvals": 4,
    "open_disputes": 7,
    "open_fraud_alerts": 2
  }
}
```

### Response 503 — Cache unavailable

```json
{
  "error": {
    "code": "ADMINAPP_DASH_001",
    "message": "Dashboard cache is temporarily unavailable. Retry in 30 seconds.",
    "correlation_id": "uuid"
  }
}
```

---

## GET /v1/admin-app/dashboard/financial

**Permission**: `adminapp.dashboard.financial`  
**Roles**: Admin, Finance  
**US**: US-ADMINAPP-002

Returns pre-aggregated financial metrics from the Redis cache.

### Response 200

```json
{
  "data": {
    "cached_at": "2026-10-05T08:45:00Z",
    "total_wallet_balance_minor_units": 980000000,
    "balance_currency": "BWP",
    "total_funding_today_minor_units": 120000000,
    "total_withdrawals_today_minor_units": 85000000,
    "total_transfers_today_minor_units": 245000000,
    "total_fees_collected_today_minor_units": 4500000,
    "total_commissions_paid_today_minor_units": 1200000,
    "total_float_outstanding_minor_units": 35000000,
    "pending_settlements_count": 3,
    "pending_settlements_amount_minor_units": 67000000
  }
}
```

---

## GET /v1/admin-app/dashboard/risk

**Permission**: `adminapp.dashboard.risk`  
**Roles**: Admin, Compliance  
**US**: US-ADMINAPP-003

Returns pre-aggregated risk and compliance metrics from the Redis cache.

### Response 200

```json
{
  "data": {
    "cached_at": "2026-10-05T08:45:00Z",
    "active_fraud_alerts": 2,
    "high_risk_transactions_today": 5,
    "aml_flags_today": 1,
    "blocked_users_count": 8,
    "frozen_wallets_count": 3,
    "failed_kyc_attempts_today": 12,
    "suspicious_ip_count_today": 4,
    "open_support_disputes": 7,
    "velocity_breach_alerts_today": 1
  }
}
```
