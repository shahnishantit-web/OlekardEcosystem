# Contract: Compliance Dashboard API

## GET /v1/admin-app/compliance/dashboard

Returns an aggregate compliance health dashboard.

**Required Permission**: `adminapp.compliance.read`

### Response 200

```json
{
  "data": {
    "regulatory_checklist": [
      {
        "label": "KYC Tier-1 Completion",
        "status": "ok",
        "detail": "98.2% of active users have completed Tier-1 KYC"
      },
      {
        "label": "AML Screening Coverage",
        "status": "warning",
        "detail": "3 accounts pending re-screening"
      },
      {
        "label": "Sanctions List Sync",
        "status": "critical",
        "detail": "Last sync was 8 days ago (threshold: 7 days)"
      }
    ],
    "open_items": [
      {
        "id": "item-001",
        "description": "3 KYC documents expired — re-verification required",
        "due_date": "2026-10-15",
        "urgency": "high",
        "category": "kyc"
      },
      {
        "id": "item-002",
        "description": "AML policy review due",
        "due_date": "2026-10-21",
        "urgency": "medium",
        "category": "aml"
      }
    ],
    "retention_deadlines": [
      {
        "policy_id": "pol-001",
        "data_category": "audit.authentication",
        "deadline_at": "2026-10-20",
        "urgency": "high"
      },
      {
        "policy_id": "pol-002",
        "data_category": "transaction.records",
        "deadline_at": "2026-11-05",
        "urgency": "low"
      }
    ]
  }
}
```

### Urgency Mapping

| urgency | Badge variant | Meaning |
|---------|--------------|---------|
| `low` / `ok` | `success` (green) | On track |
| `medium` / `warning` | `warning` (amber) | Attention needed ≤ 7 days |
| `high` / `critical` / `overdue` | `error` (red) | Overdue or imminent |

### Response 403

```json
{ "message": "Forbidden" }
```
