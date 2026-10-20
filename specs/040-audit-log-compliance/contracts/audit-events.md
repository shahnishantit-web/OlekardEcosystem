# Contract: Audit Events API

## GET /v1/admin-app/audit

Lists audit events, reverse-chronological order.

**Required Permission**: `adminapp.audit.read`

### Query Parameters

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| event_type | string | No | Filter by event type code |
| actor_id | string | No | Filter by actor ID |
| from | string | No | ISO 8601 datetime (range start) |
| to | string | No | ISO 8601 datetime (range end) |
| entity_type | string | No | Filter by entity type |
| cursor | string | No | Pagination cursor |
| per_page | number | No | Default 20, max 100 |

### Response 200

```json
{
  "data": [
    {
      "id": "018f1234-...",
      "event_category": "wallet",
      "event_type": "wallet.freeze.applied",
      "actor_id": "018e0000-...",
      "actor_type": "admin",
      "entity_type": "wallet",
      "entity_id": "018d0000-...",
      "action": "freeze wallet",
      "outcome": "success",
      "ip_address": "10.0.0.1",
      "correlation_id": "corr-abc123",
      "occurred_at": "2026-10-14T08:00:00Z"
    }
  ],
  "meta": {
    "next_cursor": "eyJpZCI6Ii4uLiJ9",
    "per_page": 20,
    "has_more": true
  }
}
```

### Response 403

```json
{ "message": "Forbidden" }
```

---

## GET /v1/admin-app/audit/{id}

Returns full detail for a single audit event.

**Required Permission**: `adminapp.audit.read`

### Response 200

```json
{
  "data": {
    "id": "018f1234-...",
    "event_type": "wallet.freeze.applied",
    "before_state": { "status": "active" },
    "after_state": { "status": "frozen" },
    "metadata": { "reason": "suspicious activity" },
    "integrity_verified": true,
    "... (all list fields)"
  }
}
```
