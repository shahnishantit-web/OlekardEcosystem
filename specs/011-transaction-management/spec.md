# E11 — Transaction Management

## Intent
This feature specification decomposes Epic E11 from the approved 538-story backlog into an independently executable Spec-Kit feature.

## Scope
Implement only the stories listed in this document. Preserve their IDs and business intent.

## Platform alignment
- Backend API: Laravel/PHP 8.4+
- Admin/Web: Next.js + TypeScript where UI applies
- Mobile: Flutter where UI applies
- DB: PostgreSQL
- Cache/Queue: Redis + Horizon
- API: REST/OpenAPI
- Design: shared token-driven design system

## Decision handling
- Do not invent TBD values.
- Configurable policies must be resolved through the centralized Admin configuration capability.
- Financial stories must use ledger/idempotency/concurrency controls where applicable.
- TBD-E11-001: Receipt share channels beyond email/SMS (WhatsApp, external URL) — deferred.
- TBD-E11-002: Partial refund eligibility business rules — deferred.
- TBD-E11-003: PDF receipt format and rendering library — deferred.

---

## Functional Requirements

### Core Transaction Lifecycle (US-TXN-001–005)

**FR-E11-001** The system MUST generate a UUID v7 identifier for each transaction at creation time.

**FR-E11-002** The system MUST generate a human-readable transaction reference using format
`TXN-{YYYYMMDD}-{8-char-uppercase-hex}` (e.g., `TXN-20260919-A3F7C812`), unique per platform.
On collision, retry with a new hex suffix (max 3 retries). Enforce uniqueness at the DB layer (UNIQUE index).

**FR-E11-003** Transactions MUST follow this state machine:
- `pending` → `processing` → `completed` (terminal)
- `pending` → `processing` → `failed` (terminal)
- `pending` → `cancelled` (terminal, only before processing begins)
- `completed` → `reversed` (terminal, only via approved + executed reversal)
- No status may move backward. Prohibited transitions MUST return `422 INVALID_STATUS_TRANSITION`.

**FR-E11-004** Every status change MUST append an immutable row to `TransactionStatusHistory` within the same
DB transaction. Fields: `previous_status`, `new_status`, `actor_id`, `actor_type`, `reason`, `occurred_at`.
No row in `TransactionStatusHistory` may be updated or deleted (enforced at model layer).

**FR-E11-005** The `Transaction` entity MUST carry the following core fields:
`id` (UUID v7), `reference` (human-readable TXN-*), `type` (enum, see FR-E11-006), `status`,
`wallet_id`, `customer_id`, `amount` (integer minor units), `currency_code`,
`direction` (`debit` | `credit`), `description`, `metadata` (JSONB),
`idempotency_key`, `correlation_id`, `ledger_journal_id` (nullable),
`created_at`, `updated_at`, `completed_at` (nullable), `failed_at` (nullable).

**FR-E11-006** Allowed `type` values (extensible, not hard-coded):
`funding`, `transfer`, `withdrawal`, `bill_payment`, `reversal`, `refund`, `fee`, `commission`.
Validation MUST read allowed types from platform config key `transaction.allowed_types`.
Unknown types MUST return `422 INVALID_TRANSACTION_TYPE`.

**FR-E11-007** All POST mutations that alter financial or reversal state MUST require an `Idempotency-Key` header.
Replay of an existing key within the configured TTL MUST return `200` with the original response body.
Outside TTL MUST return `409 IDEMPOTENCY_KEY_EXPIRED`.
Non-state-changing POSTs (e.g., `POST /v1/transactions/{id}/receipt/share`) are excluded from this requirement.

### Query & History (US-TXN-006–011)

**FR-E11-008** `GET /v1/transactions` MUST support query parameters:
`type`, `status`, `from_date` (ISO 8601 UTC), `to_date` (ISO 8601 UTC), `search` (matches `reference`
or `description`), `page`, `per_page` (default 20, max per config key `transaction.max_page_size`).
Results MUST be ordered `created_at DESC`. Multiple filters are AND-combined.
Responses MUST include `meta.total`, `meta.page`, `meta.pages`. `per_page` above max is clamped silently.

**FR-E11-009** `GET /v1/transactions` MUST be scoped to the authenticated actor's `customer_id`.
Admin actors with `transaction.admin` permission bypass ownership scope.

**FR-E11-010** `GET /v1/transactions/{id}/status-history` MUST return all `TransactionStatusHistory`
rows in ascending chronological order. Ownership scoping applies.

**FR-E11-011** `GET /v1/transactions/{id}` MUST return the full transaction record.
Cross-customer access MUST return `404 TRANSACTION_NOT_FOUND` (not 403) to prevent IDOR enumeration.

### Receipts (US-TXN-012–013)

**FR-E11-012** A `TransactionReceipt` record MUST be created for every transaction that reaches
`completed` status (event-driven, not inline in the API path). It MUST be immutable after initial insert:
any `save()` or `delete()` call on an existing receipt MUST throw `TransactionReceiptImmutableException`.
Fields: `id` (UUID v7), `transaction_id`, `amount`, `currency_code`, `type`, `channel`, `reference`,
`customer_id`, `created_at`.

**FR-E11-013** `GET /v1/transactions/{id}/receipt` returns the receipt (200) or 404 if the
transaction is not `completed`. MUST NOT expose internal system fields (wallet_id, ledger_journal_id).

**FR-E11-014** `POST /v1/transactions/{id}/receipt/share` MUST accept `channel` (enum: `email` | `sms`)
and `recipient`. Delivery is asynchronous via the Notifications module (E17 or equivalent stub).
Share action MUST be audited (`transaction.receipt.shared`).
If transaction is not `completed`: return `422 RECEIPT_NOT_AVAILABLE`.
TBD-E11-001: Additional channels (WhatsApp, external URL).

### Reversals (US-TXN-014–019)

**FR-E11-015** A reversal may only be requested for a transaction in `completed` status.
Request on any other status MUST return `422 TRANSACTION_NOT_REVERSIBLE`.

**FR-E11-016** The `Reversal` entity fields: `id` (UUID v7), `transaction_id`, `requested_by`,
`requested_at`, `status` (state machine: `pending_approval` → `approved` | `rejected`;
`approved` → `executed` | `failed`), `approved_by` (nullable), `approved_at` (nullable),
`rejected_by` (nullable), `rejected_reason` (nullable), `executed_at` (nullable),
`compensating_journal_id` (nullable), `idempotency_key`, `correlation_id`.
Only one `Reversal` may be in a non-terminal state per transaction at any time.

**FR-E11-017** `POST /v1/transactions/{id}/reversals` MUST require `Idempotency-Key`.
The system MUST use `SELECT FOR UPDATE` on the `Transaction` row to prevent concurrent duplicate requests
from each inserting a `Reversal` row. Concurrent duplicate requests MUST return the existing
`pending_approval` reversal (idempotent — not an error).

**FR-E11-018** Reversal approval (`POST /v1/transactions/{id}/reversals/{rid}/approve`)
MUST require `transaction.reversal.approve` permission. Where maker-checker is configured
(config key `transaction.reversal.require_maker_checker = true`), the approver MUST NOT be the same
actor who requested the reversal; violation MUST return `403 MAKER_CHECKER_VIOLATION`.

**FR-E11-019** Reversal execution MUST post a compensating double-entry journal via
`LedgerModuleContract::postEntry()` with `idempotency_key = 'reversal_execute:{reversal_id}'`.
The journal MUST balance (debits == credits). Any failure during journal posting MUST roll back the
entire execution — the reversal status MUST remain `approved` (not `executed`) on failure.
The transaction status transitions to `reversed` only AFTER the journal is confirmed.

**FR-E11-020** The reversal request window is configurable via config key
`transaction.reversal.max_age_days` (default: 30). Requests outside this window MUST return
`422 REVERSAL_WINDOW_EXPIRED`.

**FR-E11-021** All reversal state changes MUST be recorded in `ReversalAuditLog` with:
`action`, `actor_id`, `actor_type`, `reversal_id`, `transaction_id`, `occurred_at`, `metadata`.

### Refunds (US-TXN-018)

**FR-E11-022** A refund creates a new `Transaction` of type `refund` linked to the original via
`parent_transaction_id`. Refunds do NOT change the parent transaction's status. Refunds use the same
compensating ledger path as reversals.
TBD-E11-002: Business rules for partial refund eligibility.

### Financial Integrity Controls

**FR-E11-023** Idempotency enforcement for ALL mutations:

| Endpoint | Idempotency-Key | Server-side store key |
|----------|----------------|-----------------------|
| POST /v1/transactions | Required | `txn_create:{idempotency_key}` |
| POST /v1/transactions/{id}/reversals | Required | `reversal_request:{idempotency_key}` |
| POST /v1/transactions/{id}/reversals/{rid}/approve | Required | `reversal_approve:{rid}` |
| POST /v1/transactions/{id}/reversals/{rid}/execute | System-generated | `reversal_execute:{rid}` |
| POST /v1/transactions/{id}/refunds | Required | `refund_process:{idempotency_key}` |

**FR-E11-024** Concurrency protection:
- `POST /v1/transactions/{id}/reversals` MUST use `SELECT FOR UPDATE` on the `Transaction` row.
- `POST /v1/transactions/{id}/reversals/{rid}/execute` MUST use `SELECT FOR UPDATE` on the `Reversal` row.
- Test CC-E11-001: Two concurrent reversal requests for the same transaction → exactly one `Reversal` row;
  second caller receives the existing row.
- Test CC-E11-002: Two concurrent execute calls on the same reversal → exactly one compensating journal entry;
  second call is a no-op.

**FR-E11-025** Double-entry balance enforcement:
- Compensating journal lines for reversal MUST mirror the original transaction's journal lines in reverse.
- `LedgerModuleContract::postEntry()` enforces balance; the Transaction module MUST NOT bypass it.
- No call to `WalletPocket::increment()`, `WalletPocket::decrement()`, or raw `UPDATE ... SET balance =`
  may appear in this module. Balance changes flow exclusively through `LedgerModuleContract`.

**FR-E11-026** The unified transaction list (`GET /v1/transactions`) is served from a read projection
table populated by domain events from E08–E10. E11 does not own the source transaction tables of
other modules; it maintains a denormalized `transaction_projections` table for cross-module queries.

---

## Success Criteria

**SC-E11-001** Transaction creation is idempotent: replaying the same `Idempotency-Key` within
TTL returns HTTP 200 with the original transaction unchanged (no second row created).

**SC-E11-002** Concurrent reversal requests on the same transaction: exactly one `Reversal` row in
`pending_approval` status; the second request returns the existing record.

**SC-E11-003** Executed reversal journal balances: `SUM(debit_amounts) == SUM(credit_amounts)`.
Partial outcomes are not permitted.

**SC-E11-004** `TransactionStatusHistory` is append-only: no UPDATE or DELETE can be issued
against any row (enforced at model layer).

**SC-E11-005** IDOR prevention: an authenticated customer cannot retrieve another customer's
transaction, receipt, or reversal — all such requests return `404`.

**SC-E11-006** Transaction list pagination: `per_page` is bounded by `transaction.max_page_size`;
requests above the limit are silently clamped.

**SC-E11-007** Maker-checker enforcement: the actor who requests a reversal cannot approve the same
reversal when `transaction.reversal.require_maker_checker = true`; violation returns `403`.

---

## Epic Stories

### US-TXN-001 — Create transaction (P0)
**Actor:** Authorized system actor (source modules E08–E10 are the primary callers; direct API callers
require `transaction.create` permission)

**Acceptance Criteria:**
- Given valid actor, well-formed body, and unique `Idempotency-Key`: system creates a `Transaction`
  in `pending` status, generates UUID v7 `id` and `TXN-*` reference, returns `201` with full transaction resource.
- Given duplicate `Idempotency-Key` within TTL: returns `200` with original transaction (no second row created).
- Given missing `Idempotency-Key`: returns `400 MISSING_IDEMPOTENCY_KEY`.
- Given `wallet_id` not owned by actor: returns `404 WALLET_NOT_FOUND`.
- Given unauthenticated request: returns `401`.
- Given missing `transaction.create` permission: returns `403`.
- Given `type` not in `transaction.allowed_types`: returns `422 INVALID_TRANSACTION_TYPE`.
- Given `amount ≤ 0`: returns `422 INVALID_AMOUNT`.
- Creation is audited: `transaction.created` audit event emitted.

**API:** `POST /v1/transactions`

**DB Entities:** Transaction, TransactionStatusHistory

**Events:** `Transaction.Created`; `Transaction.StatusChanged`; `AUDIT_EVENT_RECORDED`

**Permissions:** `transaction.create`

**UI Screens:** No dedicated screen — consumed by source module (E08/E09/E10) flows.

**QA Scenarios:**
- Happy path: create returns 201 with UUID v7 id and TXN-* reference.
- Idempotency: same key returns 200 with original transaction.
- Unauthorized: 403. Unauthenticated: 401.
- Invalid type: 422. Zero amount: 422.
- Concurrency: two simultaneous requests with different idempotency keys both succeed; same key creates one row.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing.

---

### US-TXN-002 — Assign unique transaction ID (P0)
**Actor:** System (internal, no direct API endpoint)

**Acceptance Criteria:**
- UUID v7 `id` is assigned atomically at insert time by the application layer (not the DB default).
- No two transactions may share the same `id` (enforced by UNIQUE PRIMARY KEY constraint).
- This story is implemented as part of US-TXN-001; it is not a separate API call.

**API:** *(internal — implemented within US-TXN-001 creation path)*

**DB Entities:** Transaction

**Events:** *(emitted by US-TXN-001)*

**Permissions:** *(inherited from US-TXN-001)*

**UI Screens:** None.

**QA Scenarios:**
- Verify generated ID matches UUID v7 format (version bits = 7).
- Insert 1000 transactions concurrently; confirm zero duplicate IDs.

**Definition of Done:** UUID v7 assigned on every insert; uniqueness constraint in place.

---

### US-TXN-003 — Assign transaction reference (P0)
**Actor:** System (internal, no direct API endpoint)

**Acceptance Criteria:**
- `reference` is generated in `TXN-{YYYYMMDD}-{8-char-uppercase-hex}` format.
- Unique across all transactions (UNIQUE DB constraint).
- On collision: retry with a new hex suffix (max 3 retries); if all 3 collide, return `500 REFERENCE_GENERATION_FAILED`.
- This story is implemented as part of US-TXN-001; it is not a separate API call.

**API:** *(internal — implemented within US-TXN-001 creation path)*

**DB Entities:** Transaction

**Events:** *(emitted by US-TXN-001)*

**Permissions:** *(inherited from US-TXN-001)*

**UI Screens:** None.

**QA Scenarios:**
- Verify reference matches pattern `TXN-YYYYMMDD-[A-F0-9]{8}`.
- Verify DB UNIQUE constraint on `reference` column.

**Definition of Done:** Reference generated on every insert; format and uniqueness validated.

---

### US-TXN-004 — Track transaction status (P0)
**Actor:** Authenticated user / authorized system actor

**Acceptance Criteria:**
- `GET /v1/transactions/{id}` returns the `status` field reflecting the current state.
- `status` is one of: `pending`, `processing`, `completed`, `failed`, `cancelled`, `reversed`.
- A transition to a prohibited state returns `422 INVALID_STATUS_TRANSITION`.
- Every status change appends a row to `TransactionStatusHistory` (see US-TXN-005).

**API:** `GET /v1/transactions/{id}` (status is a field on the detail response)

**DB Entities:** Transaction, TransactionStatusHistory

**Events:** `Transaction.StatusChanged`; `AUDIT_EVENT_RECORDED`

**Permissions:** `transaction.read` (own); `transaction.admin` (any)

**UI Screens:** Transaction detail screen — status badge with label.

**QA Scenarios:**
- Retrieve transaction in each valid status; verify field value matches.
- Attempt invalid transition (e.g., `completed → pending`); verify 422 response.
- Verify each status change creates a history row.

**Definition of Done:** Status tracked correctly across all valid transitions; invalid transitions blocked.

---

### US-TXN-005 — Maintain transaction state history (P0)
**Actor:** Authenticated user / authorized system actor

**Acceptance Criteria:**
- Every status change appends an immutable row to `TransactionStatusHistory` in the same DB transaction.
- `GET /v1/transactions/{id}/status-history` returns rows in ascending `occurred_at` order.
- No row in `TransactionStatusHistory` can be updated or deleted (enforced at model layer).
- Actor who does not own the transaction receives `404`.

**API:** `GET /v1/transactions/{id}/status-history`

**DB Entities:** TransactionStatusHistory

**Events:** *(emitted as part of status change, owned by US-TXN-004)*

**Permissions:** `transaction.read` (own); `transaction.admin` (any)

**UI Screens:** Transaction detail → status timeline component.

**QA Scenarios:**
- Create transaction, progress through states; verify history rows accumulate in order.
- Attempt UPDATE/DELETE on history row via model; verify exception thrown.
- Cross-customer access returns 404.

**Definition of Done:** Append-only history enforced; ownership scoping correct.

---

### US-TXN-006 — View transaction history (P0)
**Actor:** Authenticated user

**Acceptance Criteria:**
- `GET /v1/transactions` (no filters) returns paginated list of authenticated actor's transactions, most recent first.
- Response includes `data[]` array and `meta.total`, `meta.page`, `meta.pages`.
- Cross-customer transactions are not returned.
- Unauthenticated: `401`. Missing `transaction.read` permission: `403`.

**API:** `GET /v1/transactions`

**DB Entities:** TransactionProjection (read model)

**Events:** None (read-only)

**Permissions:** `transaction.read` (own); `transaction.admin` (any)

**UI Screens:** Transaction history list screen — paginated list with summary rows.

**QA Scenarios:**
- Authenticated user with 50 transactions sees first 20 by default.
- `per_page=5` returns 5 results with correct pagination meta.
- Another customer's transactions are absent.

**Definition of Done:** Paginated list returns only authenticated actor's transactions.

---

### US-TXN-007 — Filter by transaction type (P0)
**Actor:** Authenticated user

**Acceptance Criteria:**
- `GET /v1/transactions?type=withdrawal` returns only withdrawal-type transactions.
- Unknown `type` value returns `422 INVALID_TRANSACTION_TYPE`.
- Multiple types (e.g., `?type=withdrawal&type=funding`) are OR-combined within the type filter.

**API:** `GET /v1/transactions?type={type}`

**DB Entities:** TransactionProjection

**Events:** None

**Permissions:** `transaction.read`

**UI Screens:** Transaction history list → type filter control.

**QA Scenarios:**
- Filter by `type=withdrawal`; verify only withdrawals returned.
- Filter by unknown type; verify 422.

**Definition of Done:** Type filter applied correctly; invalid type rejected.

---

### US-TXN-008 — Filter by date (P0)
**Actor:** Authenticated user

**Acceptance Criteria:**
- `GET /v1/transactions?from_date=2026-09-01&to_date=2026-09-30` returns transactions in the date range (inclusive, UTC).
- `from_date` after `to_date` returns `422 INVALID_DATE_RANGE`.
- Dates not in ISO 8601 format return `422 INVALID_DATE_FORMAT`.

**API:** `GET /v1/transactions?from_date={iso8601}&to_date={iso8601}`

**DB Entities:** TransactionProjection

**Events:** None

**Permissions:** `transaction.read`

**UI Screens:** Transaction history list → date range picker.

**QA Scenarios:**
- Filter by valid range; verify boundary dates included.
- `from_date > to_date`: 422.
- Non-ISO date string: 422.

**Definition of Done:** Date range filter applied; boundary and validation cases handled.

---

### US-TXN-009 — Filter by status (P0)
**Actor:** Authenticated user

**Acceptance Criteria:**
- `GET /v1/transactions?status=completed` returns only completed transactions.
- Unknown `status` value returns `422 INVALID_STATUS`.
- Combined with other filters (AND logic).

**API:** `GET /v1/transactions?status={status}`

**DB Entities:** TransactionProjection

**Events:** None

**Permissions:** `transaction.read`

**UI Screens:** Transaction history list → status filter control.

**QA Scenarios:**
- Filter by each valid status; verify only matching transactions returned.
- Unknown status: 422.

**Definition of Done:** Status filter applied; invalid status rejected.

---

### US-TXN-010 — Search transaction (P0)
**Actor:** Authenticated user

**Acceptance Criteria:**
- `GET /v1/transactions?search=TXN-20260919` returns transactions whose `reference` or `description`
  contains the search term (case-insensitive).
- Empty `search` parameter is ignored (returns all results subject to other filters).
- Search is scoped to authenticated actor's transactions (IDOR safe).

**API:** `GET /v1/transactions?search={term}`

**DB Entities:** TransactionProjection

**Events:** None

**Permissions:** `transaction.read`

**UI Screens:** Transaction history list → search input.

**QA Scenarios:**
- Search by partial reference; verify matching transactions returned.
- Search returns no results for another customer's reference.

**Definition of Done:** Search returns correct matches; ownership scoping enforced.

---

### US-TXN-011 — View transaction details (P0)
**Actor:** Authenticated user

**Acceptance Criteria:**
- `GET /v1/transactions/{id}` returns all transaction fields (excluding sensitive internal fields).
- Response includes `_links.status_history` and `_links.receipt` (present only if `completed`).
- If `id` does not exist or is owned by a different customer: `404 TRANSACTION_NOT_FOUND` (not 403).
- Internal fields excluded from response: wallet internal IDs, raw ledger journal ID.

**API:** `GET /v1/transactions/{id}`

**DB Entities:** Transaction (or TransactionProjection for denormalized reads)

**Events:** None

**Permissions:** `transaction.read` (own); `transaction.admin` (any)

**UI Screens:** Transaction detail screen — all fields, status badge, action buttons.

**QA Scenarios:**
- Retrieve own transaction: 200 with all expected fields.
- Retrieve another customer's transaction: 404.
- Non-existent ID: 404.
- Response does not include `wallet_id` or `ledger_journal_id` fields.

**Definition of Done:** Detail returns correct fields; IDOR protection enforced.

---

### US-TXN-012 — Download transaction receipt (P1)
**Actor:** Authenticated user

**Acceptance Criteria:**
- `GET /v1/transactions/{id}/receipt` returns a `TransactionReceipt` JSON resource.
- If transaction is not `completed`: `404 RECEIPT_NOT_AVAILABLE`.
- If actor does not own the transaction: `404`.
- Receipt fields in response: `id`, `reference`, `amount`, `currency_code`, `type`, `completed_at`.
- Response does NOT expose wallet internal IDs or ledger journal references.
- PDF download format: TBD-E11-003.

**API:** `GET /v1/transactions/{id}/receipt`

**DB Entities:** TransactionReceipt

**Events:** None

**Permissions:** `transaction.read` (own)

**UI Screens:** Transaction detail → "Download receipt" action; receipt view screen.

**QA Scenarios:**
- Completed transaction: 200 with receipt fields.
- Pending transaction: 404.
- Another customer's transaction: 404.
- Response does not include internal fields.

**Definition of Done:** Receipt returned for completed transactions; ownership scoping enforced; immutability verified.

---

### US-TXN-013 — Share transaction receipt (P1)
**Actor:** Authenticated user

**Acceptance Criteria:**
- `POST /v1/transactions/{id}/receipt/share` accepts `{ "channel": "email"|"sms", "recipient": "..." }`.
- Valid channels: `email`, `sms`. Others return `422 UNSUPPORTED_SHARE_CHANNEL`.
- Delivery is asynchronous; returns `202 Accepted`.
- Sharing is audited: `transaction.receipt.shared` event emitted.
- If transaction is not `completed`: `422 RECEIPT_NOT_AVAILABLE`.
- If actor does not own the transaction: `404`.

**API:** `POST /v1/transactions/{id}/receipt/share`

**DB Entities:** TransactionReceipt

**Events:** `Transaction.ReceiptShared`; `AUDIT_EVENT_RECORDED`

**Permissions:** `transaction.read` (own)

**UI Screens:** Transaction detail → "Share receipt" modal (channel + recipient inputs).

**QA Scenarios:**
- Share via `email` with valid recipient: 202 returned; audit event recorded.
- Invalid channel: 422.
- Non-completed transaction: 422.
- Another customer's transaction: 404.

**Definition of Done:** Share queued asynchronously; invalid channel and ownership validated; audit recorded.

---

### US-TXN-014 — Request transaction reversal (P0)
**Actor:** Authenticated actor with `transaction.reversal.request` permission

**Acceptance Criteria:**
- `POST /v1/transactions/{id}/reversals` requires `Idempotency-Key` header.
- Transaction must be `completed`; otherwise `422 TRANSACTION_NOT_REVERSIBLE`.
- Transaction must be within `transaction.reversal.max_age_days`; otherwise `422 REVERSAL_WINDOW_EXPIRED`.
- Concurrent duplicate requests: uses `SELECT FOR UPDATE`; only one `Reversal` row created; second
  returns existing row (idempotent, not an error).
- Response: `201` with `Reversal` resource in `pending_approval` status.
- Audited: `transaction.reversal.requested`.

**API:** `POST /v1/transactions/{id}/reversals`

**DB Entities:** Reversal, ReversalAuditLog

**Events:** `Transaction.ReversalRequested`; `AUDIT_EVENT_RECORDED`

**Permissions:** `transaction.reversal.request`

**UI Screens:** Transaction detail → "Request reversal" action (admin/agent screens).

**QA Scenarios:**
- Valid completed transaction: 201 with pending_approval reversal.
- Pending transaction: 422.
- Outside reversal window: 422.
- Two concurrent requests: exactly one reversal row; second call returns existing row.
- Missing `Idempotency-Key`: 400.

**Definition of Done:** Reversal request created; concurrency protection verified; audit recorded.

---

### US-TXN-015 — Approve reversal (P0)
**Actor:** Privileged actor with `transaction.reversal.approve` permission

**Acceptance Criteria:**
- `POST /v1/transactions/{id}/reversals/{rid}/approve` requires `transaction.reversal.approve` permission.
- If `transaction.reversal.require_maker_checker = true` and approver == requestor:
  `403 MAKER_CHECKER_VIOLATION`.
- Reversal must be in `pending_approval`; otherwise `422 REVERSAL_NOT_APPROVABLE`.
- Transitions reversal to `approved`. Does NOT execute — execution is a separate step (US-TXN-017).
- Audited: `transaction.reversal.approved`.

**API:** `POST /v1/transactions/{id}/reversals/{rid}/approve`

**DB Entities:** Reversal, ReversalAuditLog

**Events:** `Transaction.ReversalApproved`; `AUDIT_EVENT_RECORDED`

**Permissions:** `transaction.reversal.approve`

**UI Screens:** Reversal management screen → "Approve" action button.

**QA Scenarios:**
- Valid pending_approval reversal approved by different actor: 200.
- Maker-checker enabled + same actor: 403.
- Already approved reversal: 422.
- Missing permission: 403.

**Definition of Done:** Approval transitions status; maker-checker enforced; audit recorded.

---

### US-TXN-016 — Reject reversal (P0)
**Actor:** Privileged actor with `transaction.reversal.approve` permission

**Acceptance Criteria:**
- `POST /v1/transactions/{id}/reversals/{rid}/reject` accepts optional `reason`.
- Reversal must be in `pending_approval`; otherwise `422 REVERSAL_NOT_REJECTABLE`.
- Transitions to `rejected` (terminal). Transaction status unchanged.
- Audited: `transaction.reversal.rejected`.

**API:** `POST /v1/transactions/{id}/reversals/{rid}/reject`

**DB Entities:** Reversal, ReversalAuditLog

**Events:** `Transaction.ReversalRejected`; `AUDIT_EVENT_RECORDED`

**Permissions:** `transaction.reversal.approve`

**UI Screens:** Reversal management screen → "Reject" action (with reason field).

**QA Scenarios:**
- Valid pending_approval reversal rejected: 200; status → `rejected`.
- Already rejected reversal: 422.
- Missing permission: 403.
- Rejection does not change parent transaction status.

**Definition of Done:** Rejection transitions status to terminal; parent transaction unaffected; audit recorded.

---

### US-TXN-017 — Execute reversal through compensating ledger (P0)
**Actor:** Wallet / Ledger service (system-initiated or privileged actor)

**Acceptance Criteria:**
- `POST /v1/transactions/{id}/reversals/{rid}/execute` — system/privileged actor with
  `transaction.reversal.execute` permission.
- Reversal must be in `approved` status; otherwise `422 REVERSAL_NOT_EXECUTABLE`.
- Uses `SELECT FOR UPDATE` on `Reversal` row to prevent concurrent execution.
- Execution posts compensating journal via `LedgerModuleContract::postEntry()`
  with `idempotency_key = 'reversal_execute:{rid}'`.
- Journal must balance (debits == credits). Partial failure rolls back the entire execution;
  reversal remains `approved`.
- On success: transaction status → `reversed`; reversal status → `executed`.
- Idempotent: executing an already-`executed` reversal returns existing result.
- Audited: `transaction.reversal.executed`.

**API:** `POST /v1/transactions/{id}/reversals/{rid}/execute`

**DB Entities:** Reversal, Transaction, ReversalAuditLog

**Events:** `Transaction.ReversalExecuted`; `Transaction.StatusChanged`; `AUDIT_EVENT_RECORDED`

**Permissions:** `transaction.reversal.execute`

**UI Screens:** None (system-triggered or admin action).

**QA Scenarios:**
- Valid approved reversal executed: 200; transaction → `reversed`; compensating journal created.
- Two concurrent executions: exactly one journal entry; second call idempotent.
- Journal failure: reversal stays `approved`; transaction stays `completed`.
- Non-approved reversal: 422.

**Definition of Done:** Compensating ledger posted; concurrency protection verified; rollback on failure; audit recorded.

---

### US-TXN-018 — Process refund (P1)
**Actor:** Privileged actor with `transaction.refund.process` permission

**Acceptance Criteria:**
- `POST /v1/transactions/{id}/refunds` requires `Idempotency-Key` header.
- Creates a new `Transaction` of type `refund` linked to original via `parent_transaction_id`.
- Does NOT change the parent transaction's status.
- Full refund amount = parent `amount`. Partial refund eligibility: TBD-E11-002.
- Uses compensating ledger entry via `LedgerModuleContract::postEntry()`.
- Audited: `transaction.refund.processed`.

**API:** `POST /v1/transactions/{id}/refunds`

**DB Entities:** Transaction (new refund record), ReversalAuditLog (or RefundAuditLog)

**Events:** `Transaction.RefundProcessed`; `AUDIT_EVENT_RECORDED`

**Permissions:** `transaction.refund.process`

**UI Screens:** Transaction detail → "Process refund" action (admin screen).

**QA Scenarios:**
- Valid refund processed: 201 with new refund transaction linked to original.
- Parent transaction status unchanged after refund.
- Compensating journal created and balanced.
- Missing `Idempotency-Key`: 400.

**Definition of Done:** Refund transaction created; parent unaffected; ledger balanced; audit recorded.

---

### US-TXN-019 — Audit reversal (P0)
**Actor:** Authenticated actor with `transaction.reversal.audit` permission

**Acceptance Criteria:**
- `GET /v1/transactions/{id}/reversals/{rid}/audit` returns all `ReversalAuditLog` entries for the reversal.
- Each entry includes: `action`, `actor_id`, `actor_type`, `occurred_at`, `metadata`.
- Entries are immutable (append-only; no edit/delete).
- Requires `transaction.reversal.audit` permission.
- Non-existent reversal: `404`.

**API:** `GET /v1/transactions/{id}/reversals/{rid}/audit`

**DB Entities:** ReversalAuditLog

**Events:** None (read-only)

**Permissions:** `transaction.reversal.audit`

**UI Screens:** Reversal detail → audit timeline.

**QA Scenarios:**
- Full reversal lifecycle (request → approve → execute); verify all audit entries present in order.
- Attempt to update/delete an audit log entry via model; verify exception thrown.
- Missing permission: 403. Non-existent reversal: 404.

**Definition of Done:** Audit log returns all lifecycle events; immutability enforced; authorization checked.

---

## Epic-level Definition of Done
- All stories in this epic meet their story-specific Definition of Done.
- Cross-story dependencies are tested (reversal lifecycle end-to-end).
- API contracts and migrations are reviewed.
- Security and authorization checks pass (IDOR, maker-checker, permission gates).
- No call to `WalletPocket::increment/decrement` or raw balance SQL in this module.
- Concurrency tests CC-E11-001 and CC-E11-002 pass.
- PHPStan level 8 passes with 0 errors.
- OpenAPI docs complete for all endpoints.
- UI states and accessibility requirements pass where applicable.
- No unresolved Critical/High defect blocks completion.
