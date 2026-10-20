# Data Model: ADM-P1-016 — Audit Log & Compliance

## AuditEvent (list item)

| Field | Type | Notes |
|-------|------|-------|
| id | string (UUID v7) | Immutable identifier |
| event_category | string | Category bucket (authentication, wallet, etc.) |
| event_type | string | Specific event code (e.g., `wallet.freeze.applied`) |
| actor_id | string \| null | UUID of acting user; null for system events |
| actor_type | string | `admin`, `customer`, `agent`, `system` |
| entity_type | string \| null | Type of affected entity (`wallet`, `user`, etc.) |
| entity_id | string \| null | UUID of affected entity |
| action | string | Imperative verb phrase |
| outcome | `success` \| `failure` \| `denied` | Result of the action |
| ip_address | string \| null | Source IP; null for system-initiated events |
| correlation_id | string | Cross-service trace ID |
| occurred_at | string (ISO 8601) | Immutable timestamp |

## AuditEventDetail (extends AuditEvent)

| Field | Type | Notes |
|-------|------|-------|
| before_state | object \| null | Entity state before action |
| after_state | object \| null | Entity state after action |
| metadata | object | Additional contextual data |
| integrity_verified | boolean | Hash verification result |

## AuditEventListResponse

```typescript
{
  data: AuditEvent[];
  meta: {
    next_cursor: string | null;
    per_page: number;
    has_more: boolean;
  }
}
```

## Filter Parameters (GET /v1/admin-app/audit)

| Param | Type | Description |
|-------|------|-------------|
| event_type | string | Filter by event_type value |
| actor_id | string | Filter by actor_id (exact or prefix match) |
| from | string (ISO 8601) | Date-range start |
| to | string (ISO 8601) | Date-range end |
| entity_type | string | Filter by entity_type value |
| cursor | string | Pagination cursor |
| per_page | number | Default 20 |

---

## ComplianceDashboard

```typescript
{
  regulatory_checklist: ChecklistItem[];
  open_items: OpenComplianceItem[];
  retention_deadlines: RetentionDeadline[];
}
```

### ChecklistItem

| Field | Type | Notes |
|-------|------|-------|
| label | string | Human-readable requirement name |
| status | `ok` \| `warning` \| `critical` | Computed health status |
| detail | string \| null | Supporting context |

### OpenComplianceItem

| Field | Type | Notes |
|-------|------|-------|
| id | string | Item identifier |
| description | string | What needs attention |
| due_date | string \| null | ISO 8601 date |
| urgency | `low` \| `medium` \| `high` | Urgency classification |
| category | string | Compliance domain |

### RetentionDeadline

| Field | Type | Notes |
|-------|------|-------|
| policy_id | string | FK to RetentionPolicy |
| data_category | string | Category name |
| deadline_at | string | ISO 8601 date |
| urgency | `low` \| `medium` \| `high` | Based on days until deadline |

---

## RetentionPolicy

| Field | Type | Notes |
|-------|------|-------|
| id | string (UUID v7) | Immutable identifier |
| data_category | string | Category key (e.g., `audit.authentication`) |
| minimum_retention_days | number | Minimum days data must be kept |
| post_retention_action | `archive` \| `purge` | What happens after min period |
| effective_from | string | ISO 8601 date — when policy became active |
| enforcement_hold_until | string \| null | If set, policy is in grace period; edits disabled |
| configured_by_actor_id | string | Admin UUID who last configured |
| updated_at | string | ISO 8601 timestamp |

### State Transitions

```
[created] → [active]
[active] → [grace_period] (when enforcement_hold_until is set)
[grace_period] → [active] (when enforcement_hold_until passes)
```

Edit is only allowed in `[active]` state. When `enforcement_hold_until` is in the future, the row is in `[grace_period]` and Edit is disabled.

## Validation Rules

- `minimum_retention_days` ≥ 1 (frontend validation)
- `post_retention_action` must be `archive` or `purge`
- Date range for audit filters ≤ 90 days (frontend validation)
