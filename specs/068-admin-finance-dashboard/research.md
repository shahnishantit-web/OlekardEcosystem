# Research: P21 — Admin Finance Dashboard & Operations Screens

## Decision 1: Dashboard aggregation strategy

**Decision:** Add one thin backend summary endpoint (`GET /api/v1/admin/payment/dashboard-summary`) rather than aggregating multiple API calls on the frontend.

**Rationale:** The dashboard needs KPI tiles (volume by currency, attempt counts by status, fulfilment backlog count) that would require 3–4 separate API calls on the frontend. A single summary endpoint is more efficient, reduces client-side loading state complexity, and avoids partial-load inconsistency where one tile resolves before another. The endpoint is a read-only projection — no new domain logic required.

**Alternatives considered:** Frontend aggregation via React Query parallel queries — rejected because it produces multiple loading spinners and makes atomic freshness timestamps impossible.

---

## Decision 2: Intent timeline endpoint

**Decision:** Add a thin admin intent timeline endpoint (`GET /api/v1/admin/payment/intents/{id}/timeline`) that returns intent metadata, attempts array, pending-accounting flag, and fulfilment status — all in one response.

**Rationale:** The existing `GET /api/v1/admin/payment/history` returns a paginated list, not a drill-down view. The consumer-facing `GET /api/v1/payment/intents/{id}/status` is scoped to the owning user. A dedicated admin timeline endpoint combines data from the payment intent, payment attempts, and pending accounting tables — already within the same Payment module, so no cross-module boundary violation.

**Alternatives considered:** Multiple concurrent requests (intent detail + attempts list + pending accounting) — rejected as waterfall-prone and introduces partial failure states that are hard to present coherently.

---

## Decision 3: Currency separation implementation

**Decision:** All monetary totals displayed in the UI are formatted and grouped by currency code server-side and in UI components. The `CurrencyAmount` display component (already in design system from E35) always shows the currency symbol alongside the amount. Report tables show one row per currency — never a cross-currency sum row.

**Rationale:** Constitution Principle 4 (financial integrity) and spec FR-015 require that currency totals never be mixed. The backend already stores amounts in minor units with an explicit currency field.

**Alternatives considered:** Frontend-only grouping — acceptable but the backend summary endpoint will pre-group by currency to reduce client-side logic.

---

## Decision 4: Permission codes used by P21 UI

**Decision:** P21 uses the following existing permission codes (all already declared in the backend registry):

| Screen | Read Permission | Write Permission |
|--------|----------------|-----------------|
| Dashboard | `payment.history.read.platform` | — |
| Refund approval queue | `payment.refunds.approve` | `payment.refunds.approve` |
| Refund management | `payment.refunds.view` | `payment.refunds.manage` |
| Dispute cases | `payment.disputes.view` | `payment.disputes.manage` / `payment.disputes.finance_review` |
| Reconciliation exceptions | `payment.reconciliation.view` | `payment.reconciliation.manage` |
| Payout requests | `payment.payout.view` | `payment.payout.approve` |
| Reports / export | `payment.export.read.organisation` | — |
| Pending accounting | `payment.intents.admin` | — |

**Rationale:** All permission codes confirmed in routes/modules/payment.php route middleware declarations.

---

## Decision 5: "Safe status query" mechanism

**Decision:** The existing `POST /api/v1/admin/payment/refund-management/{id}/query-status` dispatches a `QueryRefundStatusJob` asynchronously (returns 202). For captured payment intents (not refunds), the dashboard "safe status query" will use a new read-only endpoint `POST /api/v1/admin/payment/intents/{id}/query-status` — same pattern, same 202 dispatch.

**Rationale:** Consistent with the established pattern from AdminRefundListController. The job queries the provider in read-only mode; the button label says "Query Provider Status" and shows the queued timestamp. The user must refresh to see the result.

**Alternatives considered:** Synchronous provider query — rejected (network call in HTTP handler violates latency constraints; provider APIs can be slow or unavailable).

---

## Decision 6: Export polling interval

**Decision:** The export status page polls `GET /api/v1/admin/payment/exports/{exportId}` every 5 seconds while status is `pending` or `processing`. When status is `downloadable`, polling stops and the download link is shown. Maximum polling duration: 5 minutes (60 polls). After timeout, show "export taking longer than expected — check back later" with the export ID for manual retrieval.

**Rationale:** `PaymentExportAdminController.status()` already returns `status`, `download_url`, and `expires_at`. 5-second polling is standard for async job UX. No WebSocket infrastructure is required.

---

## Decision 7: Stale data threshold

**Decision:** The dashboard freshness warning threshold defaults to 300 seconds (5 minutes) and reads from the `NEXT_PUBLIC_FINANCE_DASHBOARD_STALE_THRESHOLD_SECONDS` environment variable. The timestamp shown is the client-side `Date.now()` at the time of the last successful fetch.

**Rationale:** Spec assumption states this is configurable and not hard-coded (Constitution Principle 3). An env var is sufficient for a frontend config value — no need for a system configuration API call on every page load.

---

## Decision 8: Payout approve-reservation vs approve in UI

**Decision:** The payout approval screen uses `POST /payout/requests/{id}/approve-reservation` (requires `payment.payout.approve`), which is the correct approval gate. The stubs `/execute` and `/confirm-settlement` (501 responses) are NOT surfaced in any UI button — they are marked as BLOCKED in the spec and will show a status badge of "pending execution" only.

**Rationale:** The routes file explicitly marks execute and confirm-settlement as `BLOCKED pending AD-14/AD-10`. Surfacing a button that returns 501 would confuse operators. The UI simply shows the payout lifecycle status accurately.

---

## Constitution Check Summary

| Principle | Status | Notes |
|-----------|--------|-------|
| P4 Financial Integrity | PASS | Currency never mixed; no ledger mutations from UI; refund approval uses existing RefundService |
| P5 Authorization | PASS | All permissions server-enforced via route middleware; UI hides controls but backend is the gate |
| P6 Zero Trust | PASS | No secrets rendered; masked display only |
| P13 Observability | PASS | All approve/reject actions audited by RefundService and DisputeService |
| P8 API Contracts | PASS | All new endpoints follow `/v1` versioned prefix and existing response envelope |
