# Data Model: P21 — Admin Finance Dashboard & Operations Screens

This feature is a UI layer. It does not introduce new database tables. It consumes read models returned by existing backend APIs. The entities below describe the frontend data shapes as consumed and rendered.

---

## Frontend Read Models

### DashboardSummary
Returned by the new `GET /api/v1/admin/payment/dashboard-summary` endpoint.

| Field | Type | Description |
|-------|------|-------------|
| `volume_by_currency` | `CurrencyVolume[]` | Array of `{currency_code, total_minor, count}` — one entry per currency |
| `attempt_counts_by_status` | `Record<string, number>` | `{captured, fulfilled, failed, pending, cancelled}` |
| `fulfilment_backlog_count` | `number` | Count of captured intents with no fulfilment record |
| `fetched_at` | `string` (ISO 8601) | Server-side timestamp of when summary was computed |

### PaymentIntentSummary
Used in list views. Subset of full intent.

| Field | Type | Description |
|-------|------|-------------|
| `id` | `string` (UUID v7) | Intent identifier |
| `status` | `IntentStatus` | `created \| captured \| fulfilled \| failed \| cancelled` |
| `amount_minor` | `number` | Amount in minor units |
| `currency_code` | `string` | ISO 4217 (e.g. `BWP`) |
| `provider_code` | `string \| null` | Provider used for the last attempt |
| `purpose_type` | `string` | Module/purpose (e.g. `funeral_contribution`) |
| `organisation_id` | `string` | Owning organisation |
| `created_at` | `string` | ISO 8601 |
| `updated_at` | `string` | ISO 8601 |
| `has_pending_fulfilment` | `boolean` | True if captured but not yet fulfilled |

### PaymentIntentTimeline
Returned by the new `GET /api/v1/admin/payment/intents/{id}/timeline` endpoint.

| Field | Type | Description |
|-------|------|-------------|
| `intent` | `PaymentIntentSummary` | Core intent data |
| `attempts` | `PaymentAttemptRow[]` | Chronological array of attempt records |
| `fulfilment` | `FulfilmentStatus \| null` | Fulfilment dispatch state |
| `ledger_journals` | `LedgerJournalRef[]` | Journal summary references (no raw wallet IDs) |
| `pending_accounting` | `boolean` | True if in pending-accounting queue |

### PaymentAttemptRow

| Field | Type | Description |
|-------|------|-------------|
| `id` | `string` | Attempt UUID |
| `attempt_number` | `number` | Sequential attempt count |
| `status` | `string` | `submitted \| pending_provider \| captured \| failed \| cancelled` |
| `provider_code` | `string` | Which provider was called |
| `provider_reference` | `string \| null` | Provider's reference (masked to last 4 chars in display) |
| `submitted_at` | `string` | ISO 8601 |
| `resolved_at` | `string \| null` | ISO 8601 |
| `failure_reason` | `string \| null` | Human-readable failure description |

### LedgerJournalRef
Summarised ledger journal — never exposes raw wallet UUIDs in labels.

| Field | Type | Description |
|-------|------|-------------|
| `journal_id` | `string` | Journal UUID (for audit link only) |
| `posted_at` | `string` | ISO 8601 |
| `description` | `string` | Human-readable description (e.g. "Payment captured — BWP 150.00") |
| `lines` | `LedgerLine[]` | Debit/credit rows |
| `audit_link` | `string \| null` | Link to audit log entry |

### LedgerLine

| Field | Type | Description |
|-------|------|-------------|
| `type` | `'debit' \| 'credit'` | Entry type |
| `account_name` | `string` | Display name (e.g. "Customer Wallet", "Provider Settlement") — not raw ID |
| `amount_minor` | `number` | Amount in minor units |
| `currency_code` | `string` | ISO 4217 |

### RefundListItem
Used in refund management list (`GET /api/v1/admin/payment/refund-management`).

| Field | Type | Description |
|-------|------|-------------|
| `id` | `string` | Refund UUID |
| `payment_intent_id` | `string` | Link to originating intent |
| `amount_minor` | `number` | Amount in minor units |
| `status` | `RefundStatus` | `pending_approval \| approved \| rejected \| processing \| completed \| failed` |
| `approval_required` | `boolean` | Whether maker-checker applies |
| `requested_by` | `string` | Actor ID of requestor |
| `reason` | `string \| null` | Refund reason |
| `rejection_reason` | `string \| null` | Rejection reason if rejected |
| `requested_at` | `string` | ISO 8601 |
| `approved_at` | `string \| null` | ISO 8601 |
| `rejected_at` | `string \| null` | ISO 8601 |

### DisputeCaseSummary
Used in dispute list and detail views.

| Field | Type | Description |
|-------|------|-------------|
| `id` | `string` | Case UUID |
| `case_type` | `string` | Dispute category |
| `status` | `DisputeStatus` | `open \| on_hold \| under_review \| won \| lost \| closed_reversed` |
| `payment_intent_id` | `string \| null` | Linked payment intent |
| `payment_attempt_id` | `string \| null` | Linked attempt |
| `requires_finance_review` | `boolean` | Whether finance gate is pending |
| `deadline_at` | `string \| null` | Chargeback response deadline |
| `created_at` | `string` | ISO 8601 |

### ReconciliationException
Already defined in `lib/api/payment.ts`. Reproduced here for completeness.

| Field | Type | Description |
|-------|------|-------------|
| `id` | `string` | Exception UUID |
| `type` | `ReconciliationExceptionType` | `missing_callback \| unknown_outcome \| amount_mismatch \| duplicate_transaction \| orphan_provider \| failed_refund \| unmatched_ledger` |
| `status` | `ReconciliationExceptionStatus` | `open \| investigating \| escalated \| resolved` |
| `provider_code` | `string \| null` | Associated provider |
| `amount_minor` | `number \| null` | Discrepancy amount |
| `notes` | `string \| null` | Investigation notes |
| `created_at` | `string` | ISO 8601 |
| `updated_at` | `string \| null` | ISO 8601 |

### PayoutRequestSummary
Used in payout list and detail.

| Field | Type | Description |
|-------|------|-------------|
| `id` | `string` | Request UUID |
| `fund_reference_id` | `string` | Source fund reference |
| `purpose_type` | `string` | Domain purpose (e.g. `funeral_contribution`) |
| `organisation_id` | `string` | Owning organisation |
| `beneficiary_id` | `string` | Verified beneficiary |
| `currency_code` | `string` | ISO 4217 |
| `computed_payable_minor` | `number` | Computed payout amount in minor units |
| `status` | `PayoutRequestStatus` | `awaiting_approval \| reservation_pending \| approved \| rejected \| executing \| settled \| failed` |
| `created_by` | `string` | Actor ID |
| `created_at` | `string` | ISO 8601 |

---

## New Backend Endpoint Contracts (thin additions)

### GET /api/v1/admin/payment/dashboard-summary
**Permission:** `payment.history.read.platform`

Response:
```json
{
  "data": {
    "volume_by_currency": [
      {"currency_code": "BWP", "total_minor": 1500000, "count": 42},
      {"currency_code": "USD", "total_minor": 50000, "count": 3}
    ],
    "attempt_counts_by_status": {
      "captured": 38, "fulfilled": 31, "failed": 4, "pending": 7, "cancelled": 2
    },
    "fulfilment_backlog_count": 7,
    "fetched_at": "2026-10-26T12:00:00Z"
  }
}
```

### GET /api/v1/admin/payment/intents/{id}/timeline
**Permission:** `payment.intents.admin`

Response:
```json
{
  "data": {
    "intent": { /* PaymentIntentSummary */ },
    "attempts": [ /* PaymentAttemptRow[] */ ],
    "fulfilment": { "status": "dispatched", "dispatched_at": "..." } ,
    "ledger_journals": [ /* LedgerJournalRef[] */ ],
    "pending_accounting": false
  }
}
```

### POST /api/v1/admin/payment/intents/{id}/query-status
**Permission:** `payment.intents.admin`

Returns 202 with `{"message": "Status query dispatched."}`. No mutation of intent state.

---

## State Machines (UI rendering logic)

### Intent status → display badge colour
| Status | Colour |
|--------|--------|
| `created` | Gray |
| `captured` | Blue |
| `fulfilled` | Green |
| `failed` | Red |
| `cancelled` | Gray muted |

### Refund status → display badge colour
| Status | Colour |
|--------|--------|
| `pending_approval` | Yellow |
| `approved` | Blue |
| `processing` | Blue pulsing |
| `completed` | Green |
| `rejected` | Red |
| `failed` | Red |

### Payout status → display badge colour
| Status | Colour |
|--------|--------|
| `awaiting_approval` | Yellow |
| `approved` | Blue |
| `executing` | Blue |
| `settled` | Green |
| `rejected` | Red |
| `failed` | Red |
