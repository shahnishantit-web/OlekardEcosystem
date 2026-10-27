# Contract: P03 Permission Enforcement

**Date**: 2026-10-21

---

## Route Permission Matrix

All payment routes enforce permissions via `identity.require-permission` route middleware. This is the ONLY enforcement path — `$this->authorize()` in controllers will be removed (see research.md Decision 1).

| Route | Method | Permission Required | Notes |
|-------|--------|--------------------|-|
| `/v1/payment/options` | GET | `payment.intents.read` | Mobile consumer |
| `/v1/payment/intents` | POST | `payment.intents.manage` | + Idempotency-Key |
| `/v1/payment/intents/{id}/status` | GET | `payment.intents.read` | + Org-scope check |
| `/v1/payment/intents/{intentId}/attempts` | POST | `payment.intents.manage` | + Idempotency-Key + Org-scope |
| `/v1/payment/intents/{intentId}/refunds` | POST | `payment.refunds.request` | + Idempotency-Key + Org-scope |
| `/v1/payment/callbacks/{providerCode}` | POST | None (external provider) | + Active provider validation |
| `/v1/admin/payment/providers` | GET | `payment.providers.read` | |
| `/v1/admin/payment/providers/{id}` | GET | `payment.providers.read` | |
| `/v1/admin/payment/providers` | POST | `payment.providers.manage` | + Idempotency-Key |
| `/v1/admin/payment/providers/{id}` | PUT | `payment.config.draft` | Creates a draft; high-risk fields require approval |
| `/v1/admin/payment/providers/{id}/config-changes` | POST | `payment.config.draft` | **NEW** — explicit draft creation |
| `/v1/admin/payment/providers/{id}/config-changes/{draftId}/approve` | POST | `payment.config.approve_high_risk` | **NEW** — approver ≠ drafter + step-up |
| `/v1/admin/payment/providers/{id}/config-changes/{draftId}/reject` | POST | `payment.config.approve_high_risk` | **NEW** — approver ≠ drafter |
| `/v1/admin/payment/providers/{id}/suspend` | POST | `payment.providers.manage` | |
| `/v1/admin/payment/providers/{providerId}/accounts/{accountId}/rotate-credential` | POST | `payment.config.approve_high_risk` | + step-up always required |
| `/v1/admin/payment/refunds` | GET | `payment.refunds.approve` | **NEW** — list pending_approval refunds |
| `/v1/admin/payment/refunds/{id}/approve` | POST | `payment.refunds.approve` | **NEW** — approver ≠ requester + step-up if above threshold |
| `/v1/admin/payment/refunds/{id}/reject` | POST | `payment.refunds.approve` | **NEW** — approver ≠ requester |
| `/v1/admin/payment/reconciliation/exceptions` | GET | `payment.reconciliation.view` | + Org-scope |
| `/v1/admin/payment/reconciliation/exceptions/{id}` | PATCH | `payment.reconciliation.manage` | + Org-scope |

---

## New Endpoint Contracts

### POST `/v1/admin/payment/providers/{id}/config-changes`

Creates a high-risk configuration change draft. The change is NOT applied until approved by a different actor.

**Required permission**: `payment.config.draft`

**Request headers**:
- `Authorization: Bearer {token}` — required
- `Idempotency-Key: {uuid}` — required

**Request body**:
```json
{
  "change_type": "high_risk_update",
  "proposed_changes": {
    "merchant_account_reference": "new-reference-opaque-id",
    "settlement_destination": "destination-ref-opaque-id"
  },
  "notes": "Updating merchant account for new banking partner"
}
```

**Response 201** (draft created):
```json
{
  "data": {
    "id": "uuid-v7",
    "provider_id": "uuid",
    "change_type": "high_risk_update",
    "status": "pending_approval",
    "drafted_by_actor_id": "uuid",
    "created_at": "2026-10-21T10:00:00Z"
  }
}
```

**Response 409** (duplicate idempotency key): `{"error": {"code": "DUPLICATE_REQUEST", ...}}`

**Response 422** (validation failure): `{"error": {"code": "VALIDATION_ERROR", ...}}`

---

### POST `/v1/admin/payment/providers/{id}/config-changes/{draftId}/approve`

Approves a pending configuration change. The approver MUST NOT be the drafter. Step-up MFA required.

**Required permission**: `payment.config.approve_high_risk`

**Request headers**:
- `Authorization: Bearer {token}` — required
- `X-Step-Up-Token: {token}` — required (step-up is always required for credential operations)

**Request body**:
```json
{
  "notes": "Verified with banking partner — approved"
}
```

**Response 200** (approved):
```json
{
  "data": {
    "id": "uuid-v7",
    "status": "approved",
    "reviewed_by_actor_id": "uuid",
    "reviewed_at": "2026-10-21T11:00:00Z"
  }
}
```

**Response 403** — `SELF_APPROVAL_NOT_ALLOWED`: `{"error": {"code": "SELF_APPROVAL_NOT_ALLOWED", "message": "The drafter cannot approve their own change."}}`

**Response 403** — `STEP_UP_REQUIRED`: `{"error": {"code": "STEP_UP_REQUIRED", "message": "Step-up authentication required.", "next_action": {"type": "wallet_step_up", ...}}}`

**Response 409** — `DRAFT_NOT_PENDING`: `{"error": {"code": "DRAFT_NOT_PENDING", "message": "Draft is not in pending_approval state."}}`

---

### POST `/v1/admin/payment/providers/{id}/config-changes/{draftId}/reject`

Rejects a pending configuration change draft.

**Required permission**: `payment.config.approve_high_risk`

**Request body**:
```json
{
  "reason": "Incorrect destination reference — needs re-verification"
}
```

**Response 200**: `{"data": {"id": "...", "status": "rejected", "reviewed_by_actor_id": "..."}}`

**Response 403** — `SELF_APPROVAL_NOT_ALLOWED`: same as approve endpoint.

---

### GET `/v1/admin/payment/refunds`

Lists refunds with filtering. The `pending_approval` status filter allows approval-queue workflows.

**Required permission**: `payment.refunds.approve`

**Query parameters**:
- `status` (optional): `pending_approval`, `approved`, `rejected`, `pending_provider`, etc.
- `page`, `per_page` (optional, default 20)

**Response 200**: Paginated list with `meta` and `links` per `PaginatedMeta`/`PaginatedLinks` schemas.

---

### POST `/v1/admin/payment/refunds/{id}/approve`

Approves a refund in `pending_approval` state.

**Required permission**: `payment.refunds.approve`

**Step-up**: Required if `amount_minor > auth.step_up_threshold` (read from ConfigurationService at request time).

**Request headers**: `X-Step-Up-Token` (conditional — required when amount exceeds threshold)

**Response 200** (approved):
```json
{
  "data": {
    "id": "uuid",
    "status": "pending_provider",
    "approved_by_actor_id": "uuid",
    "approved_at": "2026-10-21T11:00:00Z"
  }
}
```

**Response 403** — `SELF_APPROVAL_NOT_ALLOWED`
**Response 403** — `STEP_UP_REQUIRED`
**Response 409** — `REFUND_NOT_PENDING_APPROVAL`

---

### POST `/v1/admin/payment/refunds/{id}/reject`

Rejects a refund in `pending_approval` state.

**Required permission**: `payment.refunds.approve`

**Request body**: `{"reason": "Duplicate refund request — original already refunded"}`

**Response 200**: `{"data": {"id": "...", "status": "rejected", "rejected_by_actor_id": "..."}}`

---

## Org-Scope Enforcement Contract

All routes that reference a payment resource by ID MUST enforce org-scope isolation:

| Route parameter | Org-scope resolved from |
|-----------------|------------------------|
| `{intentId}` | `payment_intents.organisation_id` (existing middleware) |
| `{intentId}` on refund routes | Same — PaymentOrganisationScopeMiddleware covers these |
| `{id}` on reconciliation routes | `reconciliation_exceptions.organisation_id` (new check in controller) |
| `{id}` on admin refund routes | `payment_refunds → payment_intents.organisation_id` (resolved via join) |

**System-level admin actors** (no `organisation_id` in their session) bypass org-scope checks and can access all organisations' data. This is the existing behavior inherited from E04.

---

## Callback Provider Validation Contract

`PaymentCallbackController::store()` MUST perform a synchronous database lookup before dispatching the processing job:

1. Query `payment.payment_providers` WHERE `code = {providerCode}` AND `is_enabled = true`
2. If no row found → return 404 + log `payment.callback.unknown_provider` (hashed source IP, providerCode)
3. If found → dispatch `PaymentCallbackProcessingJob` as before

This check is a guard clause only. No financial state changes occur on the validation path.

---

## Self-Approval Error Response

All self-approval attempts MUST return HTTP 403 with the following envelope:

```json
{
  "error": {
    "code": "SELF_APPROVAL_NOT_ALLOWED",
    "message": "The requester of a change cannot also approve it.",
    "trace_id": "correlation-id-uuid"
  }
}
```
