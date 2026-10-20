# Research — E20 Reconciliation & Settlement

All technical unknowns resolved. Business TBDs (provider name, bank partner) deliberately left unresolved per constitution P2.

---

## 1. Provider file import format

**Decision:** Implement a `ProviderFileAdapterInterface` with a `CsvProviderFileAdapter` as the default. The adapter parses raw uploaded file bytes into a normalized array of `ProviderTransactionDto`. The concrete adapter is bound via config key `reconciliation.provider_file_adapter` (default: `CsvProviderFileAdapter`).

**Rationale:** "Provider selection remains TBD" (spec Decision handling). The adapter isolates all provider-specific parsing behind an interface, making it trivial to add MT940, ISO 20022, or proprietary formats later without touching domain logic.

**Alternatives considered:** Fixed CSV parser (too rigid); hardcoded format constants (violates P2 TBD governance).

---

## 2. API/webhook receive mechanism

**Decision:** `POST /v1/reconciliation/batches/api` accepts a JSON payload containing an array of normalized provider transactions. Request authentication uses HMAC-SHA256 signature verification via a `ProviderWebhookAuthenticatorInterface` (default: `HmacWebhookAuthenticator`) that reads the `X-Provider-Signature` header and validates against a config-stored secret (`reconciliation.webhook_secret`). This endpoint is exempt from the standard `identity.auth` middleware and uses its own `reconciliation.webhook_auth` middleware instead.

**Rationale:** HMAC signing is the industry standard for webhook authentication (Stripe, Twilio, etc.). The interface allows swapping to OAuth2 or mTLS without changing domain logic.

**Alternatives considered:** API key in header (simpler but less secure); OAuth2 (more complex, unnecessary for provider-to-provider).

---

## 3. Matching algorithm

**Decision:** Three-pass matching executed by `ReconciliationMatchingService::matchBatch()`:

1. **Pass 1 — Exact match**: `provider_reference` == internal `transaction_projections.reference` AND `amount` AND `currency_code` match → status = `matched`, `match_confidence = 100`. Auto-reconciled; no human review.
2. **Pass 2 — Fuzzy match**: `amount` AND `currency_code` match AND `transaction_date` within ±1 business day → status = `matched`, `match_confidence = 50–99`. Flagged for optional human review (surfaced in Admin UI but not blocked).
3. **Pass 3 — Unmatched**: No candidates → status = `unmatched` → exception created automatically.

Matching is executed as an async job (`ProcessReconciliationBatchJob` on `reconciliation` queue, tries=3, timeout=300s).

**Rationale:** Industry standard for financial reconciliation. Exact match handles >95% of happy-path cases; fuzzy match handles legitimate timing differences (e.g., end-of-day cut-off discrepancies); unmatched becomes an auditable exception requiring human resolution.

**Alternatives considered:** Single-pass exact only (rigid; fails on timing differences); ML-based similarity scoring (overengineered for this scope; interpretability concerns for financial audit).

---

## 4. Settlement execution mechanism

**Decision:** `SettlementAdapterInterface` with a `NullSettlementAdapter` default that marks the settlement as `executed` in the DB without making any external call. A production adapter (e.g., `BankTransferSettlementAdapter`) is injected via config key `reconciliation.settlement_adapter`. The adapter interface has one method: `execute(SettlementBatch $batch): string` returning an external reference ID.

**Rationale:** Provider/bank partner is TBD. The null adapter enables the full state machine to be implemented, tested, and deployed immediately. Production adapter slots in with zero domain-layer changes.

**Alternatives considered:** Direct bank API integration (violates TBD governance); mocking in production (not acceptable for financial operations).

---

## 5. Reconciliation batch lifecycle

**Decision:**

```
draft → processing → matched → completed
                  → failed
```

- `draft`: Batch record created; file uploaded; items not yet parsed.
- `processing`: `ProcessReconciliationBatchJob` has picked up the batch; items being parsed and matched.
- `matched`: All items have been evaluated (some may be `unmatched` — this refers to batch processing being complete, not 100% item match rate).
- `completed`: All exceptions resolved; batch closed.
- `failed`: Job threw an unrecoverable exception; batch locked for investigation.

Idempotency: UNIQUE constraint on `(source_type, source_reference, batch_date)`. Duplicate import returns the existing batch record.

---

## 6. Settlement batch lifecycle

**Decision:**

```
draft → pending_approval → approved → executing → executed
                        → rejected
                                   → failed
```

- `draft`: Settlement calculated; amount determined; not yet submitted for approval.
- `pending_approval`: Maker submitted for checker approval.
- `approved`: Checker approved; ready for execution.
- `rejected`: Checker rejected; terminal.
- `executing`: `ExecuteSettlementJob` dispatched.
- `executed`: Adapter returned success; ledger entry posted via E07.
- `failed`: Adapter or ledger call failed; compensating action required.

Maker-checker: DB CHECK `maker_id != checker_id`; service-layer 422 guard; same pattern as E19 `AdminWalletAdjustmentService`.

Idempotency: UNIQUE constraint on `idempotency_key`. State-changing API endpoints require `Idempotency-Key` header (constitution P8).

---

## 7. Cross-module dependencies

| Module | Contract | Method(s) Used | Story |
|--------|----------|----------------|-------|
| E07 Ledger | `LedgerModuleContract::postEntry()` | Double-entry journal for settlement execution | US-RECON-016 |
| E11 Transaction | Direct DB query on `transaction.transaction_projections` | Match lookup by reference (same cross-module read pattern as E19 Admin) | US-RECON-003, 009 |
| E18 Risk | `AuditServiceInterface` | Audit all reconciliation and settlement actions | All |
| E04 Permissions | RBAC via `identity.auth` middleware | `reconciliation.read`, `reconciliation.write`, `reconciliation.approve_settlement`, `reconciliation.execute_settlement` | All |

**Note on E11 cross-module query:** `TransactionModuleContract` provides only `generateReference()` and `status()`. Matching requires searching by reference, amount, date range — a query the contract does not expose. Following E19's precedent, the reconciliation module queries `transaction.transaction_projections` directly via the DB facade (cross-schema read, read-only, no financial mutations).

---

## 8. Observability

**Metrics** (StatsD/Prometheus):

| Metric | Labels | Description |
|--------|--------|-------------|
| `reconciliation.batch.processed` | `source_type`, `status` | Batch lifecycle events |
| `reconciliation.item.matched` | `match_confidence_tier` | Item match outcomes (exact/fuzzy/unmatched) |
| `reconciliation.exception.created` | `exception_type` | Exception creation rate |
| `reconciliation.exception.resolved` | `resolution_type` | Exception resolution outcomes |
| `reconciliation.settlement.action` | `action_type` | Settlement lifecycle events |
| `reconciliation.settlement.amount` | `currency_code` | Net settlement amounts processed |

**Queues:**
- `reconciliation` queue for `ProcessReconciliationBatchJob` and `ExecuteSettlementJob` (dedicated queue to avoid starving transactional queues; same pattern as E19 `admin` queue).

---

## 9. File storage

**Decision:** Provider files uploaded via `POST /v1/reconciliation/batches/file` are stored to `Storage::disk(config('filesystems.reconciliation_imports', 'local'))->put("reconciliation/{$batchId}/{$filename}", ...)` before async processing. This allows the job to re-read the file on retry without requiring the HTTP request to remain open.

**Rationale:** Same pattern as E19 `ExportTransactionsJob`. Configurable disk allows switching to S3 in production.
