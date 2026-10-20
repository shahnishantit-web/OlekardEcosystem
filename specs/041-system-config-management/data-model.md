# Data Model: System Configuration Management (ADM-P1-017)

All entities are read from/written to the E28 backend. This document captures the TypeScript type additions and changes needed in the admin app.

---

## ConfigurationKeyItem (extended)

Location: `lib/api/configuration.ts`

```
ConfigurationKeyItem {
  config_key:          string
  domain:              ConfigurationDomain
  current_value:       unknown
  value_type:          string           // 'string' | 'number' | 'boolean' | 'json'
  status:              string
  description:         string | null
  updated_at:          string           // ISO 8601
  has_pending_change:  boolean          // ADD — was unsafely cast before
}
```

---

## ConfigurationKeyDetail (extended)

Location: `lib/api/configuration.ts`

```
ConfigurationKeyDetail extends ConfigurationKeyItem {
  pending_change_request_id: string | null
  requires_approval:         boolean       // ADD — drives success message after submit
}
```

---

## ConfigurationVersionItem (extended)

Location: `lib/api/configuration.ts`

```
ConfigurationVersionItem {
  id:                string
  config_key:        string
  domain:            ConfigurationDomain
  event_type:        VersionEventType
  value:             unknown
  actor_id:          string | null
  change_request_id: string | null
  notes:             string | null
  occurred_at:       string             // ISO 8601
  approved_by:       string | null      // ADD — UUID of approving admin; null for non-critical
  effective_from:    string | null      // ADD — ISO 8601; null if not yet effective
}
```

---

## ChangeRequestItem (unchanged)

```
ChangeRequestItem {
  id:               string
  config_key:       string
  domain:           ConfigurationDomain
  proposed_value:   unknown
  previous_value:   unknown
  maker_id:         string
  status:           ChangeRequestStatus
  rejection_reason: string | null
  submitted_at:     string
  resolved_at:      string | null
}
```

---

## ChangeRequestDetail (unchanged)

```
ChangeRequestDetail extends ChangeRequestItem {
  version_trail: ConfigurationVersionItem[]
}
```

---

## RBAC Permissions (new — added to ALL_PERMISSION_SLUGS)

| Slug | Label | Group |
|------|-------|-------|
| `adminapp.config.read`    | View Configuration     | Configuration |
| `adminapp.config.write`   | Submit Config Changes  | Configuration |
| `adminapp.config.approve` | Approve Config Changes | Configuration |

---

## State Transitions: Change Request

```
(new edit) → pending_approval → approved → activated
                              ↘ rejected
                              ↘ withdrawn (by maker)
                              ↘ failed (system error)
(non-critical edit) → activated directly (no pending_approval step)
```
