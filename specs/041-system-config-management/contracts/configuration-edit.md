# Contract: Configuration Edit (US-P1-017-001)

## Component: ConfigurationKeyDetailPage

**Location**: `src/modules/configuration/pages/configuration-key-detail.tsx`

**Props**: `{ configKey: string }`

**RBAC gates**:
- Requires `adminapp.config.read` to view the page (otherwise access-denied card)
- Requires `adminapp.config.write` for the edit form to be visible
- Edit form disabled (not hidden) when `keyDetail.pending_change_request_id !== null`

---

## UI States

| State | Trigger | Rendered Output |
|-------|---------|-----------------|
| Access denied | `!hasPermission('adminapp.config.read')` | `<Card><p role="alert">Access denied...</p></Card>` |
| Loading | `loading === true` | `<Loading />` |
| Error | `error !== null` | `<ErrorState message={error} />` |
| Loaded (read-only) | `!hasPermission('adminapp.config.write')` | Key info + current value + version history; no edit form |
| Loaded (pending change) | `keyDetail.pending_change_request_id !== null` | Edit form disabled; "Pending change" notice with link |
| Loaded (editable) | write permission + no pending change | Edit form active; diff preview panel hidden until value entered |
| Diff preview | `showDiff === true` | Side-by-side before/after `<pre>` blocks; Confirm Submit + Cancel buttons |
| Submitting | `submitting === true` | Confirm Submit button shows "Saving…" and is disabled |
| Submit success | after API returns | Inline success banner with link to change request (if pending_approval) or "Updated" |

---

## Edit Form Fields

| Field | Input Type | Validation |
|-------|-----------|------------|
| Proposed Value | `<textarea>` | Non-empty; valid JSON if `value_type = 'json'`; parseable number if `value_type = 'number'`; boolean string if `value_type = 'boolean'` |

Aria: `aria-label="Proposed value"` on the textarea. Label element with `htmlFor`.

---

## API Call

```
ConfigurationApiClient.submitChangeRequest({
  config_key:      keyDetail.config_key,
  proposed_value:  parsedValue,         // parsed from textarea
  idempotency_key: generateIdempotencyKey(),
})
```

On success: show success banner with `status` from returned `ChangeRequestItem`.

---

## Version History Table (enhanced)

Columns (in order): **Event** | **Value** | **Changed By** | **Approved By** | **Occurred At** | **Effective From**

- `approved_by`: show `v.approved_by.slice(0, 8) + '…'` if present, else `'—'`
- `effective_from`: show `new Date(v.effective_from).toLocaleDateString()` if present, else `'—'`
