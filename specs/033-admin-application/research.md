# Research — E33 Admin Application

## Decision 1: Backend aggregation controller pattern

**Decision:** Create `Modules/AdminApp/` in the Laravel modular monolith. Controllers call existing domain services via direct PHP method calls (not HTTP). One controller per domain area.

**Rationale:** Domain services are already available in the same process. Direct method calls avoid HTTP overhead, retry complexity, and cascading auth. Extraction-ready per Constitution Principle 7 — the module interface is explicit and can be promoted to an HTTP boundary later without changing consumer code.

**Alternatives considered:**
- HTTP proxy/gateway (rejected: unnecessary network hop within monolith, adds failure modes, requires internal service auth)
- Extending existing module controllers (rejected: violates single responsibility, blurs domain boundaries across modules)
- Shared domain queries via AdminApp (rejected: leaks domain internals, bypasses domain service invariants)

---

## Decision 2: Dashboard cache strategy

**Decision:** Redis-backed pre-aggregated JSON, refreshed by a scheduled Artisan command (`adminapp:refresh-dashboard-cache`) every 5 minutes. Each dashboard payload includes `cached_at` (ISO 8601). Frontend polls via React Query `refetchInterval: 300_000`. Redis TTL 300s as fallback.

**Rationale:** Aggregate dashboard queries span multiple modules (Wallet, Transaction, KYC, Fraud, Reconciliation). Running them on every page load is expensive and inconsistent with the spec's near-real-time requirement. This pattern matches E24 (Reporting & Analytics) report generation.

**Alternatives considered:**
- Uncached per-request aggregation (rejected: expensive N-module queries, spec says near-real-time is sufficient)
- WebSocket/SSE push (explicitly excluded by spec clarification)
- Event-driven cache invalidation (deferred: would reduce max staleness below 5 min, but adds event coupling — revisit if business needs evolve)

---

## Decision 3: Maker-checker integration

**Decision:** Reuse `MakerCheckerWorkflow` from E28 (Configuration Management). Register 6 new action types. Maker submits → receives `202 Accepted` + `approval_id`. Checker uses existing `/v1/admin/configuration/approvals` workflow. On approval, the workflow calls the domain service method.

**Rationale:** E28 already delivers a tested, audited, two-person integrity system. Reusing it is cheaper, maintains UX consistency, and satisfies Constitution Principle 4 (financial integrity) and Principle 13 (observability) without new infrastructure.

**Alternatives considered:**
- Inline two-step confirm in the same HTTP session (rejected: no second-person requirement, no audit trail)
- New dedicated approval service for AdminApp (rejected: over-engineering, duplicates E28 capability)
- Synchronous maker-checker within same request (rejected: violates the security model — maker and checker cannot be the same person/session)

---

## Decision 4: Frontend role enforcement

**Decision:** Two layers: (1) Next.js middleware redirects unauthorized roles attempting to access restricted pages; (2) each restricted server component checks the permission slug from the auth token before rendering. Permission slugs are included in the admin token payload (E04 pattern).

**Rationale:** Defense in depth. Middleware prevents navigation to wrong pages (UX). Server-side per-page check is the authoritative security gate (backend also enforces). This matches the existing `requiredPermission` pattern already present in `src/lib/navigation/nav-items.ts`.

**Alternatives considered:**
- Client-side only checks (rejected: easily bypassed, not a security control)
- Backend-only enforcement (rejected: poor UX — users see the page skeleton, then get a 403)
- Separate role-management service call on each page load (rejected: latency; token payload is sufficient for read access checks)

---

## Decision 5: New pages vs. enhancing existing pages

**Decision:** Enhance existing pages where the domain is already covered (wallet search → add freeze/unfreeze; transaction search → add reversal; operational dashboard → add 5-min refresh). Create new pages only for genuinely absent sections (KYC queue, agent management, float management, financial/risk dashboards).

**Finding from codebase exploration:** The admin portal at `wallet-ecosystem/apps/admin/` has 43 existing pages covering users, wallets, transactions, reconciliation, reports, audit, configuration, devices, sessions, and security. The gaps requiring new pages are:
- `app/(admin)/kyc/` — no KYC review UI exists
- `app/(admin)/agents/` — no agent management UI exists  
- `app/(admin)/float/` — no float management UI exists
- `app/(admin)/financial-dashboard/` — only a general admin-portal dashboard exists; dedicated financial metrics page needed
- `app/(admin)/risk-dashboard/` — security section has findings but no aggregated risk dashboard

**Rationale:** Reduces duplication; preserves existing user workflows; minimizes regression surface.

---

## Decision 6: Transaction reversal eligibility enforcement

**Decision:** AdminApp `TransactionController::reverse()` checks transaction type before forwarding to the domain service. Eligible: `wallet_transfer` (E09) and `agent_cash_in`/`agent_cash_out` (E08/E10). All other types return `422 ADMINAPP_015` with the reason "Transaction type not eligible for admin reversal."

**Rationale:** Spec clarification (Q5) explicitly restricts reversal to internal transactions. Bank-settled transactions (funding deposits, withdrawals) require external bank processes. Enforcing at the AdminApp layer gives a clear user-facing error before attempting the domain service call.

**Alternatives considered:**
- Letting the domain service enforce eligibility (possible, but the domain services may not uniformly reject admin-initiated reversals — AdminApp owns the business rule about which types an admin can reverse)
- No restriction (rejected: could allow admins to attempt reversals of bank-settled transactions, causing partial-state errors)
