# Research — E19 Admin Portal

## Unknown 1: Dashboard Aggregation Strategy

**Decision:** Cache-aside pattern with 5-minute Redis TTL for all dashboard KPIs; real-time fallback capped at 2s.

**Rationale:** Dashboard stats (user counts, wallet totals, transaction volumes) are acceptable at 5-minute granularity for operational monitoring. Live DB aggregation across millions of wallet/transaction rows at every request would exceed the 500ms p99 SLA. Cache-aside (read cache → miss → query DB → write cache) is simpler than write-through and avoids cache stampede via a per-key lock (Redis SET NX with 10s TTL for the lock).

**Alternatives considered:**
- Materialized views: would require scheduled refresh and adds migration complexity; deferred to E24 (Reporting & Analytics).
- Real-time aggregation on every request: does not meet latency SLA at production data volumes.
- Pre-computed daily snapshots: insufficient freshness for operational dashboard; appropriate for E24.

---

## Unknown 2: Admin Module Boundary

**Decision:** Thin `/v1/admin/` wrapper layer that delegates to domain service contracts. Admin module owns only `AdminAction` (audit log) and `AdminWalletAdjustment` (maker-checker state machine).

**Rationale:** Re-implementing domain logic in the Admin module would create duplication and divergence risk. Instead, `AdminUserService` calls `UserService` (E01), `WalletAdminService` calls `WalletModuleContract` (E06) and `AccountHoldService` (E18), and `TransactionAdminService` calls `TransactionModuleContract` (E11) and `LedgerService` (E07). This preserves domain ownership and module extraction-readiness (constitution P7).

**Alternatives considered:**
- Direct DB queries from Admin module: violates domain boundary; admin module would need to know schema internals of other modules.
- GraphQL federation: not in approved stack; would require ADR.

---

## Unknown 3: Controlled Wallet Adjustment — Journal Strategy

**Decision:** Admin adjustment posts two ledger entries via `E07 LedgerService::postJournal()`:
- **Credit adjustment**: DR `admin.suspense` / CR `wallet.{walletId}.available`
- **Debit adjustment**: DR `wallet.{walletId}.available` / CR `admin.suspense`

The `admin.suspense` account is a platform-level GL account seeded during E07 setup. The journal `entry_type` is `admin_adjustment`. The `AdminWalletAdjustment.ledger_transaction_id` is set after successful posting.

**Rationale:** Using E07 LedgerService preserves double-entry invariant (constitution P4). The admin suspense account makes the offsetting credit/debit explicit and auditable. No direct wallet balance column mutation.

**Alternatives considered:**
- Direct wallet balance update: violates constitution P4 (financial integrity must use immutable ledger).
- Separate admin ledger module: premature extraction; E07 already provides the contract.

---

## Unknown 4: Transaction Reversal vs. Refund Distinction

**Decision:**
- **Reversal** (US-ADMIN-025): Posts mirror-image compensating ledger entries for the original transaction via `E07 LedgerService::postJournal()` with `entry_type = 'admin_reversal'`. Original transaction status → `reversed`. Applicable only to settled transactions where both parties are internal wallets.
- **Refund** (US-ADMIN-026): Posts a new credit entry to the customer wallet (`entry_type = 'admin_refund'`). Used when the original funding source (e.g. bank transfer, agent cash-in) cannot be unwound — only the customer wallet is credited. Requires `admin.write` + `admin.refund` permission.

**Rationale:** A reversal fully undoes the original journal. A refund is a goodwill credit that does not presuppose the original funding can be returned. The distinction matches industry practice for payment operations.

**Alternatives considered:**
- Single "refund" action for both: loses the accounting distinction; auditors require separate action types.
- Automated reversal via E08/E09/E10 reversal flows: those modules handle their own settlement; admin reversal is an override for exceptions.

---

## Unknown 5: Transaction Export Format and Scale

**Decision:**
- ≤ 10,000 rows: synchronous CSV response (`Content-Type: text/csv`); streamed to browser.
- > 10,000 rows: async `ExportTransactionsJob` dispatched to `admin` queue; admin receives a signed S3/local-disk download URL valid for 24h (or configured retention).

Export format: CSV only for E19 scope. XLSX support deferred to E24 (Reporting & Analytics).

**Rationale:** Synchronous streaming is simplest for small exports and avoids polling complexity. For large exports, holding an HTTP connection open for minutes is unreliable across proxies. Async + signed URL is the standard pattern for bulk data export. S3/local-disk is already available via Laravel's `Storage` facade.

**Alternatives considered:**
- Always async: adds polling complexity for small exports where synchronous is fine.
- Server-Sent Events (SSE) progress streaming: not in approved stack for admin portal; adds complexity.
- XLSX only: CSV is universally compatible and requires no additional library dependencies.
