# API Contracts — Compliance (US-ADMINAPP-021/022)

## Common Headers

| Header | Required | Description |
|--------|----------|-------------|
| `Authorization` | Yes | `Bearer <admin_jwt>` |
| `X-Correlation-ID` | Yes | UUID v4 per request |

## Error Codes

| Code | HTTP | Meaning |
|------|------|---------|
| `ADMINAPP_AUTH_001` | 401 | Missing or invalid admin JWT |
| `ADMINAPP_AUTH_002` | 403 | Insufficient role/permission |
| `ADMINAPP_AUDIT_001` | 400 | Invalid date range (max 90 days) |
| `ADMINAPP_REPORT_001` | 404 | Report not found |
| `ADMINAPP_REPORT_002` | 422 | Report still generating — check status |

---

## Audit Log (US-ADMINAPP-021)

### GET /v1/admin-app/audit

**Permission**: `adminapp.audit.read`  
**Roles**: Admin, Compliance  
**US**: US-ADMINAPP-021

Browse the immutable audit event log from E23 (AuditService). Read-only. Date range max 90 days per request.

**Query parameters**:

| Param | Type | Notes |
|-------|------|-------|
| `event_type` | string | Filter by event type (e.g., `WALLET_FROZEN`, `KYC_APPROVED`) |
| `actor_id` | UUID | Filter by admin who performed the action |
| `subject_id` | UUID | Filter by affected entity |
| `subject_type` | enum | `user`, `wallet`, `transaction`, `agent` |
| `from_date` | ISO 8601 datetime | Inclusive — required if `to_date` set |
| `to_date` | ISO 8601 datetime | Inclusive — max 90 days from `from_date` |
| `per_page` | int | Default 50, max 200 |
| `page` | int | |

**Response 200**:

```json
{
  "data": [
    {
      "event_id": "01jbxyz...",
      "event_type": "WALLET_FROZEN",
      "actor_id": "01jbxyz...",
      "actor_name": "Admin User",
      "subject_id": "01jbxyz...",
      "subject_type": "wallet",
      "description": "Wallet OLK-000123456 frozen — reason: Suspected money laundering",
      "metadata": {
        "wallet_number": "OLK-000123456",
        "freeze_reason": "Suspected money laundering — under investigation",
        "approval_id": "01jbxyz..."
      },
      "occurred_at": "2026-10-05T09:00:00Z",
      "correlation_id": "550e8400-e29b-41d4-a716-446655440000"
    }
  ],
  "meta": {
    "total": 4512,
    "per_page": 50,
    "current_page": 1,
    "last_page": 91
  }
}
```

**Response 400** (date range exceeds 90 days):

```json
{
  "error": {
    "code": "ADMINAPP_AUDIT_001",
    "message": "Date range must not exceed 90 days. Requested range: 120 days.",
    "correlation_id": "uuid"
  }
}
```

---

## Reports (US-ADMINAPP-022)

### GET /v1/admin-app/reports

**Permission**: `adminapp.reports.read`  
**Roles**: Admin, Finance, Compliance  
**US**: US-ADMINAPP-022

List generated reports available for download.

**Query parameters**:

| Param | Type | Notes |
|-------|------|-------|
| `report_type` | string | `transaction_summary`, `kyc_volume`, `agent_performance`, `settlement_summary`, `fraud_events` |
| `status` | enum | `pending`, `generating`, `ready`, `failed` |
| `per_page` | int | Default 25 |
| `page` | int | |

**Response 200**:

```json
{
  "data": [
    {
      "report_id": "01jbxyz...",
      "report_type": "transaction_summary",
      "status": "ready",
      "period_start": "2026-10-01",
      "period_end": "2026-10-04",
      "requested_by": "Finance User",
      "requested_at": "2026-10-05T08:00:00Z",
      "ready_at": "2026-10-05T08:03:15Z",
      "download_url": "https://storage.example.com/signed/reports/...",
      "format": "xlsx"
    }
  ],
  "meta": { "total": 42, "per_page": 25, "current_page": 1, "last_page": 2 }
}
```

### POST /v1/admin-app/reports

**Permission**: `adminapp.reports.read`  
**Roles**: Admin, Finance, Compliance  
**US**: US-ADMINAPP-022  
**Requires**: `Idempotency-Key` header

Request a new report generation. Delegates to E24 (Reporting & Analytics) job infrastructure. Returns immediately with `report_id`; poll for `status: "ready"`.

**Request**:

```json
{
  "report_type": "transaction_summary",
  "period_start": "2026-10-01",
  "period_end": "2026-10-04",
  "format": "xlsx"
}
```

`report_type`: `transaction_summary` | `kyc_volume` | `agent_performance` | `settlement_summary` | `fraud_events`  
`format`: `csv` | `pdf` | `xlsx`

**Response 202**:

```json
{
  "data": {
    "report_id": "01jbxyz...",
    "status": "pending",
    "estimated_ready_in_seconds": 180
  }
}
```

### GET /v1/admin-app/reports/{report_id}

**Permission**: `adminapp.reports.read`  
**Roles**: Admin, Finance, Compliance  
**US**: US-ADMINAPP-022

Poll for report status. When `status: "ready"`, `download_url` contains a signed URL (1-hour TTL).

**Response 200**:

```json
{
  "data": {
    "report_id": "01jbxyz...",
    "report_type": "transaction_summary",
    "status": "ready",
    "period_start": "2026-10-01",
    "period_end": "2026-10-04",
    "requested_by": "Finance User",
    "requested_at": "2026-10-05T08:00:00Z",
    "ready_at": "2026-10-05T08:03:15Z",
    "download_url": "https://storage.example.com/signed/reports/01jbxyz...",
    "format": "xlsx"
  }
}
```

**Response 422** (still generating):

```json
{
  "error": {
    "code": "ADMINAPP_REPORT_002",
    "message": "Report is still generating. Current status: generating. Retry in 60 seconds.",
    "correlation_id": "uuid"
  }
}
```

**Response 404** (not found):

```json
{
  "error": {
    "code": "ADMINAPP_REPORT_001",
    "message": "Report not found.",
    "correlation_id": "uuid"
  }
}
```
