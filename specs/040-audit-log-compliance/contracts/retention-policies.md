# Contract: Retention Policies API

## GET /v1/admin-app/compliance/retention-policies

Lists all configured data retention policies.

**Required Permission**: `adminapp.compliance.read` (read) or `adminapp.compliance.config` (read+write)

### Response 200

```json
{
  "data": [
    {
      "id": "pol-001",
      "data_category": "audit.authentication",
      "minimum_retention_days": 365,
      "post_retention_action": "archive",
      "effective_from": "2026-01-01",
      "enforcement_hold_until": null,
      "configured_by_actor_id": "018e0000-...",
      "updated_at": "2026-10-01T10:00:00Z"
    }
  ]
}
```

---

## PATCH /v1/admin-app/compliance/retention-policies/{id}

Updates a data retention policy. The server records the change in the audit log.

**Required Permission**: `adminapp.compliance.config`

**Note**: Cannot be called if `enforcement_hold_until` is in the future (server returns 409).

### Request Body

```json
{
  "minimum_retention_days": 730,
  "post_retention_action": "purge"
}
```

### Response 200

```json
{
  "data": {
    "id": "pol-001",
    "data_category": "audit.authentication",
    "minimum_retention_days": 730,
    "post_retention_action": "purge",
    "effective_from": "2026-10-14",
    "enforcement_hold_until": null,
    "configured_by_actor_id": "018e0000-...",
    "updated_at": "2026-10-14T09:00:00Z"
  }
}
```

### Response 409 (Hold active)

```json
{
  "message": "Policy is in grace period until 2026-11-01. No changes allowed."
}
```

### Response 403

```json
{ "message": "Forbidden" }
```
