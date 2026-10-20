# Research — E22 Support & Dispute Management

## Decision 1: Module Namespace and Structure

**Decision:** Single Laravel module `App\Modules\Support\` under `wallet-ecosystem/services/api/app/Modules/Support/` covering both support tickets and disputes. No existing support or dispute module exists in the codebase — E22 introduces it from scratch.

**Rationale:** Disputes have a mandatory linkage to SupportTicket (tickets are the wrapper; a dispute is a specialised ticket subtype). Keeping them in one module avoids cross-module references to non-domain-contract data at the cost of a slightly larger module. Splitting into two modules (`Support\` and `Disputes\`) would be appropriate only if the two domains grow independently; they share DB schema, events, permissions, and the evidence upload flow.

**Alternatives considered:** Separate `Support\` and `Disputes\` modules — rejected because disputes always reference tickets; splitting creates a dependency cycle at the domain-model level.

**File path:** `wallet-ecosystem/services/api/app/Modules/Support/`

---

## Decision 2: Ledger Integration for Dispute Refund

**Decision:** Dispute refund posts a balanced double-entry journal via `LedgerModuleContract::postEntry()` following the same pattern used by E21 `FeeLedgerService`.

Journal lines for dispute refund:
```
DR  liability:disputes:{currency}    — platform dispute reserve (decreases)
CR  asset:wallet:{reporterId}:{currency}  — reporter's wallet pocket (increases)
```

**Entry type:** `'dispute_refund'` — a new `JournalEntryType` string constant. The `postEntry()` `entry_type` field is a free string; no migration is needed for the enum (the Ledger module uses a string column, not a DB CHECK constraint as confirmed in E21 research).

**Account registration:** Before first posting to `liability:disputes:{currency}`, call `LedgerModuleContract::registerAccount()` with `accountType = 'liability'`, `ownerType = 'system_suspense'` if the account is not already registered (idempotent). The reporter's wallet pocket ledger reference (`asset:wallet:{reporterId}:{currency}`) is already registered by E06 Wallet Management.

**Idempotency key derivation:** `Uuid::uuid5(Uuid::NAMESPACE_URL, $disputeId . ':refund')` — stored on `disputes.refund_idempotency_key` for duplicate detection.

**Rationale:** Mirrors the fee reversal pattern precisely. `liability:disputes:{currency}` is a platform-level liability account representing money the platform has committed to return; it is funded from the platform's operating accounts as part of the dispute settlement approval process.

---

## Decision 3: Fee Reversal on Dispute Refund

**Decision:** `FeesModuleContract::reverseFee()` is called inside the same DB transaction as the dispute refund if the original disputed transaction has a `Fee` record in the `fees` table. The call is guarded by `FeesModuleContract::recordFee()` idempotency — if no fee was recorded for the transaction, `reverseFee()` is a documented no-op.

**Signature:**
```php
$this->fees->reverseFee(
    transactionId:   $disputedTransactionId,
    debitAccountRef: 'revenue:fees:' . $currency,
    creditAccountRef: 'asset:wallet:' . $reporterId . ':' . $currency,
    idempotencyKey:  Uuid::uuid5(Uuid::NAMESPACE_URL, $disputedTransactionId . ':fee:reversal')->toString(),
    correlationId:   $correlationId,
);
```

**Rationale:** Consistent with E21 plan. `reverseFee()` is idempotent (no-op if already reversed), so it is safe to call unconditionally on disputed transactions.

---

## Decision 4: Evidence File Storage

**Decision:** Module-specific contract `EvidenceStorageContract` with Laravel disk backend — mirrors `KycDocumentStorageContract` pattern. Files are stored under a `support-evidence/` prefix. Access is via time-limited signed URLs (15-minute TTL) only; direct file keys are never returned to clients.

**Accepted types:** JPEG, PNG, WebP, PDF — enforced by `DisputeEvidenceUploadRequest` MIME validation.

**Max size:** 10 MB (10,485,760 bytes) — enforced by `DisputeEvidenceUploadRequest` `max:10240` rule (Laravel rule is in KB).

**Storage key format:** `support-evidence/{disputeId}/{evidenceId}.{extension}`

**Rationale:** Re-uses proven per-module storage contract pattern; signed URLs ensure evidence is not publicly accessible.

---

## Decision 5: Database Schema

**Decision:** PostgreSQL schema `support` (following the per-module schema convention). Tables: `support.support_tickets`, `support.ticket_notes`, `support.disputes`, `support.dispute_evidence`.

**Schema ownership:** `SupportServiceProvider` runs the `CREATE SCHEMA IF NOT EXISTS support` migration as the first migration.

---

## Decision 6: Event Outbox Pattern

**Decision:** All integration events (`SupportTicketCreated`, `DisputeRaised`, `DisputeApproved`, `DisputeRejected`, `DisputeRefunded`, `DisputeEscalated`) use `OutboxEventPublisher` (from `App\Modules\Platform\Application\Services\OutboxEventPublisher`) inserted within the same DB transaction as state change. The outbox publisher adds a row to `platform.outbox_events`; the `OutboxDispatcherJob` delivers it to the event bus asynchronously.

**Rationale:** Follows Constitution Principle 9 (event reliability); identical to E21 Fees, E18 Risk, E09 Transfer patterns.

---

## Decision 7: Idempotency

**Decision:** Use `IdempotencyServiceInterface` (from `App\Modules\Platform\`) for the following mutating endpoints:
- `POST /v1/support/tickets`
- `POST /v1/disputes`
- `POST /v1/disputes/{id}/evidence`
- `POST /v1/disputes/{id}/refund`

State-transition PATCHes (`assign`, `status`, `reason`, `investigate`, `approve`, `reject`, `escalate`) are idempotency-safe by state-machine guard — repeated calls to the same terminal state return the current record unchanged.

---

## Decision 8: Notification Integration

**Decision:** `NotificationModuleContract::dispatch(string $recipientId, string $channel, array $payload): void` is called after:
1. Dispute rejected — notifies reporter via push/SMS
2. Public ticket note added — notifies reporter via push

Calls are made outside the DB transaction (fire-and-forget via queued event listener) to avoid blocking the response.

---

## Decision 9: Maker-Checker for Dispute Approve/Refund

**Decision:** Maker-checker is implemented as a platform-level configurable workflow (E28 Configuration Management will own the `support.dispute.approve.require_second_approval` flag). For E22, the initial implementation checks a boolean config value. If enabled, the first approval transitions to `pending_confirmation`; a dedicated `POST /v1/disputes/{id}/confirm-approval` endpoint allows the second approver (must be a different actor) to finalize. If disabled, the first approval goes directly to `approved`.

**Rationale:** Constitution Principle 3 requires configurable approval workflows.

---

## Decision 10: OpenAPI Specification

**Decision:** New file `openapi/v1/support.yaml` covering all 13 story endpoints. Split into `support_tickets` and `disputes` tag groups.
