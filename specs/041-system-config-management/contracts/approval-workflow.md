# Contract: Approval Workflow (US-P1-017-002)

## Component: ConfigurationApprovalDetailPage

**Location**: `src/modules/configuration/pages/configuration-approval-detail.tsx`

**Props**: `{ changeRequestId: string }`

**RBAC gates**:
- Requires `adminapp.config.approve` (otherwise access-denied card)

---

## Maker/Checker Guard

```typescript
const { user } = useAuth();
const isOwnChange = user?.id === request?.maker_id;
```

- When `isOwnChange === true`: Approve button is `disabled`; `<p role="alert">` notice: "You cannot approve your own change request."
- Withdraw button remains enabled when `isOwnChange === true` (maker may withdraw).
- Reject button: disabled when `isOwnChange === true` (only the checker may reject).

---

## Action Buttons

| Button | Enabled when | API Call |
|--------|--------------|----------|
| Approve | `isPending && !isOwnChange && hasPermission('adminapp.config.approve')` | `approveChangeRequest(id, { idempotency_key })` |
| Reject | `isPending && !isOwnChange && hasPermission('adminapp.config.approve')` | Opens reject dialog → `rejectChangeRequest(id, { reason, idempotency_key })` |
| Withdraw | `isPending && isOwnChange` | `withdrawChangeRequest(id, { idempotency_key })` |

After any action: redirect to `/configuration/approvals`.

---

## Reject Dialog

- `role="dialog" aria-modal="true" aria-labelledby="reject-dialog-title"`
- Textarea `id="reject-reason"` with `required aria-required="true"`
- "Confirm Rejection" button disabled when `rejectReason.trim() === ''`

---

## Component: ConfigurationApprovalsPage

**RBAC**: Requires `adminapp.config.approve` (access-denied card otherwise).

**API Call**: `listChangeRequests({ status: 'pending_approval', per_page: 50 })`

Columns: **Config Key** | **Domain** | **Maker** | **Submitted** | **Age**
