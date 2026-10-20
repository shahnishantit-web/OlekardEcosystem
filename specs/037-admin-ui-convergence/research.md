# Research: Admin Panel UI/UX Layout Convergence

**Date**: 2026-10-12
**Branch**: `037-admin-ui-convergence`

No external unknowns require external research — all decisions can be resolved by reading the existing codebase and the `olekard_admin_wireframe.html` reference. Findings below document each resolved decision.

---

## R-01: Design Token Audit

**Decision**: Use `bg-ok-surface border border-neutral-700` as the universal card surface/border in `Card.tsx`.

**Rationale**: The dark-theme admin already uses `bg-ok-surface` and `border-neutral-700` consistently in `KpiCard`, `MetricChart`, `ActivityFeed`, `PendingTasksCard`, `DataTable`, and `FilterBar`. The `Card.tsx` component was the sole outlier using `border-neutral-200 bg-bg-primary` (light-theme values). `tokens.css` confirms `--ok-surface` maps to the dark surface color used throughout the admin.

**Alternatives considered**: Introduce a new CSS variable (`--color-card-border`) to decouple from `neutral-700` — rejected because it would require design system rebuild without designer review, and `neutral-700` is already the established admin card border.

---

## R-02: Button Height Strategy

**Decision**: Set `Button.tsx` default `minHeight` to `35px`; apply `min-h-[44px]` only on `sm:` breakpoint (mobile touch target compliance).

**Rationale**: The wireframe reference uses `height: 35px` for all control elements on desktop. The existing `minHeight: 44px` was added for WCAG 2.5.5 touch target size, which is correct for mobile but unnecessarily large on desktop. Using Tailwind responsive variant `min-h-[35px] sm:min-h-[44px]` satisfies both requirements.

**Note**: The `Button.tsx` `className` prop allows callers to override per-usage if needed. Core change is conservative.

**Alternatives considered**: Leave at 44px and accept the visual difference — rejected because it makes the admin feel "chunky" vs the wireframe's compact density standard.

---

## R-03: Shell Dimension Tokens

**Decision**: Update CSS variables in `globals.css`:
- `--sidebar-expanded`: `256px` → `252px`
- `--sidebar-collapsed`: `68px` → `70px`
- `--header-height`: `60px` → `66px`
- `--content-padding-x`: `24px` → `32px`

**Rationale**: Wireframe specifies these exact values. The deltas are small (4px, 2px, 6px, 8px) and produce no layout reflows beyond expected resizing. All values reference these CSS variables, not hard-coded numbers.

**PageContainer**: Change from `px-6 lg:px-8 py-6 max-w-screen-2xl` to `px-8 lg:px-9 py-8 max-w-[1500px]`. This produces ~32px on default breakpoint and ~36px on lg+, matching the wireframe spec.

---

## R-04: NAV_SECTIONS Expansion Strategy

**Decision**: Replace the current 8-section/14-item nav with a 3-section structure — Workspace, Operations, Management — covering all ~50 implemented routes.

**Section mapping** (derived from route inventory in spec):

**WORKSPACE**
- Dashboard (`/dashboard`)
- Financial Dashboard (`/financial-dashboard`)
- Risk Dashboard (`/risk-dashboard`)

**OPERATIONS**
- Users (`/admin-portal/users`)
- Devices (`/devices`)
- Sessions (`/sessions`)
- KYC Reviews (`/kyc`)
- Agents (`/agents`)
- Agent Float (`/float`)
- Wallets (`/admin-portal/wallets`)
- Transactions (`/admin-portal/transactions`)
- Reconciliation (`/reconciliation/batches`)
- Fee Rules (`/fees/rules`)

**MANAGEMENT**
- Audit Events (`/audit`)
- Compliance (`/compliance`)
- Reports (`/reports`)
- Security (`/security/dashboard`)
- Multi-Currency (`/multi-currency/currencies`)
- Configuration (`/configuration`)
- Integrations (`/integrations`)
- Dead Letter (`/operations/dead-letter`)
- Ops Center (`/ops`)
- QA Dashboard (`/qa`)
- Platform Config (`/platform/security-config`)

**Rationale**: The wireframe shows three groups (WORKSPACE / OPERATIONS / MANAGEMENT) matching this mental model. Sub-routes (e.g., `/reconciliation/batches/[id]`, `/agents/[id]`) are reachable from their parent list page — not direct nav items — to keep the sidebar compact.

**Collapsed sidebar tooltips**: The `title` attribute already provides browser-native tooltips. A simple ARIA `aria-label` (already present) + browser `title` satisfies the accessible tooltip requirement without a bespoke tooltip library.

---

## R-05: Dashboard Consolidation

**Decision**: Retire `admin-portal/dashboard` by converting it to a redirect to `/dashboard`. The canonical dashboard is `/dashboard` → `AdminDashboardPage`.

**Rationale**: Both pages display ecosystem-level KPIs. The portal dashboard has 8 cards in a 2×4 grid; the main dashboard has 6. Neither matches the wireframe's 4-card spec. Merging into one page with 4 primary KPIs is cleaner and eliminates the duplication of local `KpiCard` component in `portal-dashboard.tsx`.

**Retained KPIs** (most actionable for daily ops): Total Users, Active Wallets, Transactions Today, KYC Pending. Removed from primary grid: Active Agents, Open Disputes (moved to Quick Actions panel context or secondary cards below fold if needed).

**Period selector**: `MetricChart` will accept a `period: '7' | '30' | '90'` prop. It will attempt to filter `searchAdminTransactions` by date range. If the existing API supports `created_after`/`created_before` params, data will update. If not, a clear "Period data unavailable — showing last 24 hours" message is displayed. No fabricated data.

---

## R-06: ActivityFeed Repurpose

**Decision**: `ActivityFeed` is repurposed to query the audit events API (`GET /v1/audit/events?per_page=5`) instead of transactions. The existing `searchAdminTransactions` call is moved to the new `RecentTransactionsTable` component.

**Rationale**: The wireframe "Operational activity" panel shows system events (KYC approval, registration, agent flag) — not transaction amounts. Audit events map cleanly to this concept. If the audit API is unavailable, the panel shows "System events unavailable" explicitly.

**RecentTransactionsTable**: New wrapper around `DataTable` with status tab bar (All / Completed / Pending / Failed) + `FilterBar` search. Consumes `searchAdminTransactions` which is already used and tested.

---

## R-07: Undeclared Tailwind Class Fix

**Decision**: Replace undeclared classes in `UserManagementPage` with their declared equivalents:
- `border-border-subtle` → `border-neutral-700/50`
- `bg-surface-secondary` → `bg-ok-surface`
- `bg-surface-hover` → `hover:bg-neutral-700/20`

**Rationale**: These classes were used in the E19 admin portal implementation but were never added to `tailwind.config.ts`. They silently produce no styling. The replacement values are the established equivalents used in `DataTable.tsx` row styles.

**Broader sweep**: A grep of all admin source files for these three class names will be run before marking Stage E complete to catch any other occurrences.

---

## R-08: Dead CSS Removal

**Decision**: Remove the following class definitions from `globals.css`:
`.dashboard-shell`, `.dashboard-header`, `.dashboard-kicker`, `.summary-grid`, `.summary-card`, `.dashboard-panel`, `.status-pill`, `.empty-state`

**Rationale**: Confirmed by grep — none of these classes are referenced in any `.tsx`, `.ts`, or other `.css` file in the admin app. They were from a pre-AdminShell design iteration.

**Safe to remove**: Checked against the full file inventory. The `.sidebar-transition` and `@media (prefers-reduced-motion)` blocks immediately following these dead classes MUST be preserved.

---

## R-09: Quick Actions Panel Content

**Decision**: `QuickActionsPanel` will contain the following links (matching wireframe intent, mapped to real routes):

| Label | Route | Icon |
|-------|-------|------|
| Manage Customers | `/admin-portal/users` | Users |
| Review KYC Requests | `/kyc` | ClipboardList |
| Manage Agents | `/agents` | UserCheck |
| View Transactions | `/admin-portal/transactions` | ArrowLeftRight |
| Reconciliation | `/reconciliation/batches` | RefreshCw |
| Fee Rules | `/fees/rules` | Percent |

**Rationale**: These are the six highest-frequency routes for a daily operations admin (matching the wireframe's five items + one additional for reconciliation which is daily work). All six routes are real, implemented, and already in NAV_SECTIONS.

No permission filtering on client side — navigation is always shown; server enforces access.
