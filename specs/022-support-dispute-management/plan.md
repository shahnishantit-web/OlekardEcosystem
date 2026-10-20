# Implementation Plan — E22 Support & Dispute Management

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — new `App\Modules\Support\` Laravel module; no stack deviations
- [x] No TBD value invented — no business TBDs present; evidence limits and config flags are configurable
- [x] Authorization remains server-side — all endpoints enforce `support.*` / `dispute.*` permission checks
- [x] Financial integrity preserved — dispute refund uses E07 `LedgerModuleContract::postEntry()` double-entry; atomic with status transition; idempotency key prevents duplicate posting
- [x] Security/audit requirements incorporated — all state transitions emit outbox-backed audit events; privileged operations (approve/reject/refund) write to platform audit log
- [x] UX/design-system requirements incorporated — UI screens for Customer app (raise dispute, view tickets) and Admin portal (manage tickets, approve/reject/refund) specified in stories
- [x] QA strategy incorporated — each story has independent test, acceptance scenarios, and quickstart validation

## Technical Context

- **Backend:** Laravel / PHP 8.4+
- **Module path:** `wallet-ecosystem/services/api/app/Modules/Support/`
- **Database:** PostgreSQL schema `support` (per-module convention)
- **Queue/cache:** Redis + Laravel Horizon (outbox dispatcher)
- **Web:** Next.js + TypeScript (Admin Portal — ticket and dispute management UI)
- **Mobile:** Flutter (Customer app — raise dispute, view ticket status; Agent app — raise support request)
- **API:** REST/OpenAPI — `openapi/v1/support.yaml`
- **Testing:** PHPUnit/Pest unit + integration, Laravel HTTP tests, PHPStan level-6
- **Observability:** structured logs, metrics, correlation IDs on all events

## Dependencies

| Epic | Dependency | Status |
|---|---|---|
| E04 | `support.create`, `support.assign`, `support.investigate`, `support.resolve`, `support.close`, `dispute.raise`, `dispute.approve`, `dispute.reject`, `dispute.refund`, `dispute.escalate` permissions | COMPLETE |
| E07 | `LedgerModuleContract::postEntry(entry_type='dispute_refund')` | COMPLETE |
| E11 | Transaction lookup for dispute eligibility (status, amount, currency) | COMPLETE |
| E17 | `NotificationModuleContract::dispatch()` for rejection and public note events | COMPLETE |
| E21 | `FeesModuleContract::reverseFee()` called on dispute refund for transactions with posted fees | COMPLETE |
| E01 | Bearer token auth for all endpoints | COMPLETE |

## Phase 0 — Research (COMPLETE)

See `research.md` for all decisions. Summary:

| Decision | Resolution |
|---|---|
| Module structure | Single `App\Modules\Support\` module for tickets + disputes |
| Ledger account refs | DR `liability:disputes:{currency}` / CR `asset:wallet:{reporterId}:{currency}` |
| Fee reversal | `FeesModuleContract::reverseFee()` inside refund DB transaction |
| File storage | `EvidenceStorageContract` / `LaravelDiskEvidenceStorage` with signed URLs (15-min TTL) |
| DB schema | `support` PostgreSQL schema; 4 tables + 2 sequences |
| Event outbox | `OutboxEventPublisher` within DB transaction for 6 integration events |
| Idempotency | `IdempotencyServiceInterface` on 4 mutating endpoints |
| Notifications | `NotificationModuleContract::dispatch()` post-transaction for rejection + public notes |
| Maker-checker | Config flag `support.dispute.approve.require_second_approval` via E28 |
| OpenAPI | `openapi/v1/support.yaml` |

## Phase 1 — Design (COMPLETE)

- `research.md` ✓
- `data-model.md` ✓
- `contracts/SupportModuleContract.php` ✓
- `contracts/support.yaml` ✓
- `quickstart.md` ✓

## Implementation Approach

### Step 1 — Schema and Migrations

1. `create_support_schema` — `CREATE SCHEMA IF NOT EXISTS support`
2. `create_support_tickets` — SupportTicket table with sequences
3. `create_ticket_notes` — TicketNote table
4. `create_disputes` — Dispute table with partial unique index
5. `create_dispute_evidence` — DisputeEvidence table with CHECK constraint
6. `seed_support_permissions` — 10 permissions seeded to identity schema; grant to admin, customer, agent roles as appropriate

### Step 2 — Domain Layer

1. Enums: `TicketStatus`, `TicketCategory`, `TicketPriority`, `DisputeStatus`, `DisputeReasonCode`, `EvidenceFileType`
2. Events: 14 domain events (see `data-model.md` — Events section)
3. Exceptions: 10 domain exceptions
4. Contracts: `SupportModuleContract`, `EvidenceStorageContract`

### Step 3 — Infrastructure: Models

1. `SupportTicketModel` — Eloquent model; `$connection = 'support'`; scopes: `forReporter()`, `forStatus()`
2. `TicketNoteModel` — relationship to SupportTicketModel; scope `publicOnly()`
3. `DisputeModel` — Eloquent model; partial unique index guard in service layer; status machine guards
4. `DisputeEvidenceModel` — relationship to DisputeModel; count scope for limit enforcement

### Step 4 — Application Services

Services in `Application/Services/`:

| Service | Responsibility |
|---|---|
| `SupportTicketService` | create, list, show — idempotency via `IdempotencyServiceInterface` |
| `SupportTicketNoteService` | add note; enforce `TICKET_IS_CLOSED` guard; dispatch E17 notification for public notes |
| `DisputeService` | raise dispute — idempotency; transaction eligibility check via E11; dispatch `DisputeRaisedEvent` |
| `DisputeEvidenceService` | attach evidence; enforce 5-file limit; validate type/size; dispatch `DisputeEvidenceAttachedEvent`; status transition to `evidence_submitted` |
| `DisputeInvestigationService` | start investigation; transition to `under_investigation`; lock reason/evidence |
| `DisputeResolutionService` | approve (with optional maker-checker) and reject; dispatch events; E17 notification on reject |
| `DisputeRefundService` | execute financial refund via E07 `postEntry`; call E21 `reverseFee`; UNIQUE constraint guard on `refund_idempotency_key`; dispatch `DisputeRefundedEvent` |

### Step 5 — Infrastructure: HTTP Layer

Controllers are thin — delegate to application services. Each controller:
- Validates via FormRequest
- Calls service
- Returns JsonResource response in standard envelope

### Step 6 — Event/Outbox Wiring

Each application service that performs a state change:
1. Opens DB transaction
2. Mutates model + saves
3. Calls `OutboxEventPublisher::publish()` with event DTO
4. Commits transaction

`OutboxDispatcherJob` processes pending outbox events asynchronously.

### Step 7 — Storage

Implement `LaravelDiskEvidenceStorage`:
- `store(string $disputeId, string $evidenceId, UploadedFile $file): string` → returns file key
- `generateSignedUrl(string $fileKey, int $ttlMinutes = 15): string`

Register `EvidenceStorageContract` → `LaravelDiskEvidenceStorage` in `SupportServiceProvider`.

### Step 8 — Service Provider and Routes

1. `SupportServiceProvider` — bind contracts, register DB connection `'support'`
2. Add routes to `routes/api.php` under `/v1` prefix with auth middleware

### Step 9 — Tests

| Test Type | Coverage |
|---|---|
| Unit | TicketStatus state machine, DisputeStatus state machine, evidence size/type validation, fee reversal idempotency |
| HTTP/Integration | All 13 story acceptance scenarios from spec |
| Financial | Dispute refund: balanced journal; duplicate idempotency key returns same result; ledger failure rolls back dispute status |
| Authorization | Each privileged endpoint returns 403 for unauthorized roles |
| Idempotency | All 4 idempotency-gated endpoints |

### Step 10 — OpenAPI and Documentation

Copy `contracts/support.yaml` to `openapi/v1/support.yaml`.

## Data / Financial Controls

- **Dispute refund atomicity:** `DisputeRefundService` wraps E07 `postEntry`, E21 `reverseFee`, and `DisputeModel::save()` in a single DB transaction. Failure at any point rolls back all.
- **Duplicate refund prevention:** UNIQUE constraint on `disputes.refund_idempotency_key` prevents double posting even under concurrent requests.
- **Partial refund guard:** `refund_amount_minor > original_transaction_amount` is rejected; partial refund requires `partial_refund_justification`.
- **Ledger account registration:** `liability:disputes:{currency}` account registered idempotently before first refund posting.
- **Fee reversal:** `FeesModuleContract::reverseFee()` is a no-op if no fee was posted for the transaction (safe unconditional call).

## Observability

- Correlation ID propagated from HTTP header to all events, logs, and ledger entries.
- Dispute refund: ledger journal ID stored on `disputes.refund_ledger_entry_id`.
- All state transitions logged at INFO level with ticket/dispute ID and actor ID.
- Privileged actions (approve, reject, refund) additionally logged to audit trail.

## Complexity Tracking

No architecture deviations. New module follows established `App\Modules\*` conventions. Any future deviation requires an ADR and constitution check update.
