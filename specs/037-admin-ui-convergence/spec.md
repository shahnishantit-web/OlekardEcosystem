# Feature Specification: Admin Panel UI/UX Layout Convergence

**Feature Branch**: `037-admin-ui-convergence`

**Created**: 2026-10-12

**Status**: COMPLETE/RELEASE READY

**Input**: User description: "Admin Panel UI/UX Layout Convergence — complete audit-driven layout reconciliation of the OleKard Admin Next.js app against the olekard_admin_wireframe.html reference."

---

## Overview

The OleKard Admin Panel currently presents an inconsistent visual and structural experience across its ~60 implemented routes. Layout tokens, component surface styles, navigation coverage, and page composition vary between modules. This feature converges all existing Admin pages to a single coherent enterprise shell — derived from the `olekard_admin_wireframe.html` reference — without replacing any functional business logic, API integrations, authentication, or RBAC rules.

This is a **layout convergence refactor**, not a greenfield rewrite. All business functionality, financial calculations, existing API contracts, and security rules are preserved unchanged.

---

## Audit Findings

### A. Current Page Inventory

| Route | Page Component | Uses PageContainer | Shell |
|-------|---------------|-------------------|-------|
| `/dashboard` | `AdminDashboardPage` | No (inline `px-6 lg:px-8`) | AdminShell ✓ |
| `/admin-portal/dashboard` | `AdminPortalDashboardPage` | No (`px-6 py-8 max-w-6xl`) | AdminShell ✓ |
| `/admin-portal/users` | `UserManagementPage` | Yes | AdminShell ✓ |
| `/admin-portal/users/[id]` | `UserDetailPage` | (inferred) | AdminShell ✓ |
| `/admin-portal/wallets` | `WalletManagementPage` | (inferred) | AdminShell ✓ |
| `/admin-portal/transactions` | `TransactionManagementPage` | (inferred) | AdminShell ✓ |
| `/devices` | `DeviceListPage` | (inferred) | AdminShell ✓ |
| `/sessions` | `SessionsPage` | (inferred) | AdminShell ✓ |
| `/kyc`, `/kyc/[id]` | KYC pages | (inferred) | AdminShell ✓ |
| `/agents`, `/agents/[id]` | Agent pages | (inferred) | AdminShell ✓ |
| `/float` | `FloatManagementPage` | (inferred) | AdminShell ✓ |
| `/financial-dashboard` | `FinancialDashboardPage` | Yes | AdminShell ✓ |
| `/risk-dashboard` | `RiskDashboardPage` | (inferred) | AdminShell ✓ |
| `/reconciliation/batches` | `BatchListPage` | (inferred) | AdminShell ✓ |
| `/reconciliation/batches/[id]` | `BatchDetailPage` | (inferred) | AdminShell ✓ |
| `/reconciliation/settlements` | `SettlementListPage` | (inferred) | AdminShell ✓ |
| `/fees/rules` | `FeeRulesListPage` | (inferred) | AdminShell ✓ |
| `/fees/rules/create` | `FeeRuleCreatePage` | (inferred) | AdminShell ✓ |
| `/audit` | `AuditEventsPage` | Yes | AdminShell ✓ |
| `/compliance` | `ComplianceStatusPage` | (inferred) | AdminShell ✓ |
| `/compliance/retention` | `RetentionPoliciesPage` | (inferred) | AdminShell ✓ |
| `/reports`, `/reports/[id]`, `/reports/schedules` | Report pages | (inferred) | AdminShell ✓ |
| `/reports/reconciliation`, `/reports/settlement` | Report pages | (inferred) | AdminShell ✓ |
| `/security/dashboard` | `SecurityDashboardPage` | (inferred) | AdminShell ✓ |
| `/security/findings` | `FindingsPage` | (inferred) | AdminShell ✓ |
| `/integrations`, `/integrations/[id]` | Integration pages | (inferred) | AdminShell ✓ |
| `/integrations/providers`, `/integrations/webhooks` | Integration pages | (inferred) | AdminShell ✓ |
| `/operations/dead-letter` | `DeadLetterPage` | (inferred) | AdminShell ✓ |
| `/ops/*` (10 sub-routes) | Ops pages | (inferred) | AdminShell ✓ |
| `/configuration`, `/configuration/approvals` | Config pages | (inferred) | AdminShell ✓ |
| `/configuration/approvals/[id]`, `/configuration/[domain]/[key]` | Config pages | (inferred) | AdminShell ✓ |
| `/multi-currency/currencies`, `/multi-currency/fx-rates` | Multi-currency pages | (inferred) | AdminShell ✓ |
| `/qa`, `/qa/runs/[id]` | QA pages | (inferred) | AdminShell ✓ |
| `/platform/security-config` | `SecurityConfigPage` | (inferred) | AdminShell ✓ |

### B. Identified Visual and Structural Defects

**B1 — Shell Token Mismatch (Critical)**
- `Card.tsx` uses `border-neutral-200 bg-bg-primary` (light-theme values) while all other surfaces use `border-neutral-700 bg-ok-surface` (dark theme). This creates cards that render with mismatched borders across the admin panel.
- `Button.tsx` enforces `minHeight: 44px` (mobile-touch target) — oversize for the desktop-first admin panel which calls for ~35px control height.

**B2 — Dual Dashboard Problem (Critical)**
- Two active dashboard pages exist: `/dashboard` and `/admin-portal/dashboard`, each with a different layout, different KPI count, and duplicated local `KpiCard` component in `portal-dashboard.tsx`. This creates a split entry-point with inconsistent UX.

**B3 — Dashboard Layout Deviation (Critical)**
- Current `/dashboard` shows 6 KPI cards (2/3/4 col grid) instead of the reference's 4 KPI cards.
- Chart section uses `lg:grid-cols-3` with 2+1 split; reference requires `1.7fr / 0.85fr`.
- Bottom section (transaction table + quick actions) is absent — `ActivityFeed` displays a feed list instead of a table with status tabs.
- No reporting period selector (Last 7 / 30 / 90 days).
- No Quick Actions panel.
- No page eyebrow label ("OPERATIONS / DASHBOARD").

**B4 — Navigation Gaps (High)**
- `NAV_SECTIONS` covers only 14 routes. Missing from navigation: `/kyc`, `/agents`, `/float`, `/financial-dashboard`, `/risk-dashboard`, `/ops/*` (10 routes), `/multi-currency/*`, `/configuration/*`, `/reports/reconciliation`, `/reports/settlement`.
- Nav groups don't match reference: current has 8 sections; reference groups into Workspace, Operations, Management.

**B5 — Content Container Inconsistency (High)**
- `AdminDashboardPage` and `AdminPortalDashboardPage` bypass `PageContainer` and apply their own padding/max-width directly on `<main>`.
- `UserManagementPage` wraps content in `PageContainer` but uses undeclared Tailwind classes (`border-border-subtle`, `bg-surface-secondary`, `bg-surface-hover`) that do not exist in `tailwind.config.ts` — these classes silently produce no styling.

**B6 — Legacy CSS Pollution (Medium)**
- `globals.css` contains dead class definitions: `.dashboard-shell`, `.dashboard-header`, `.dashboard-kicker`, `.summary-grid`, `.summary-card`, `.dashboard-panel`, `.status-pill`, `.empty-state`. These were from a prior design iteration and are no longer referenced by any component.

**B7 — Shell Dimension Drift (Medium)**
- Reference specifies sidebar 252px expanded / 70px collapsed, header 66px, content gutter 32–36px, max-width 1500px.
- Current: sidebar 256px / 68px, header 60px, content gutter 24px/32px, max-width `screen-2xl` (1536px).

**B8 — Collapsed Sidebar Tooltip Gaps (Low)**
- `AdminSidebar` sets `title={item.label}` on nav items (browser tooltip only). Reference requires accessible tooltip popups at collapsed state.

**B9 — MetricChart Period Lock (Medium)**
- `MetricChart` is hard-coded to "Last 24 hours" with no period selector. Reference requires Last 7 / 30 / 90 day switching connected to real data queries.

**B10 — ActivityFeed Type Mismatch (Medium)**
- `ActivityFeed` queries transaction data and displays a transaction list. The wireframe's "Operational activity" panel is meant to surface system events (KYC approvals, registrations, agent onboarding). The bottom section should contain the transaction table with status tabs.

### C. Duplicated Shells/Styles/Components

- `portal-dashboard.tsx` defines a local `KpiCard` function that duplicates `components/dashboard/KpiCard.tsx`
- Raw `<table>` implementations in `UserManagementPage` duplicate the shared `DataTable` component
- Some pages define inline filter forms; `FilterBar` component exists but is not consistently applied

### D. Proposed Canonical Component Architecture

```
components/
  layout/
    AdminShell.tsx        ← keep, adjust CSS tokens (header 66px)
    AdminSidebar.tsx      ← keep, expand NAV_SECTIONS, add tooltip behaviour
    AdminHeader.tsx       ← keep, minor padding/height fix
    Breadcrumbs.tsx       ← keep as-is
    PageContainer.tsx     ← update gutter (32px/36px) and max-width (1500px)
    PageHeader.tsx        ← keep as-is
  ui/
    Card.tsx              ← fix border/surface tokens (dark theme)
    Button.tsx            ← fix min-height (35px desktop, keep 44px on mobile via @media)
    DataTable.tsx         ← keep, ensure all list pages use it
    FilterBar.tsx         ← keep, ensure consistent usage
    Badge.tsx, StatusBadge.tsx, Toast.tsx, Dialog.tsx, Sheet.tsx — keep
  dashboard/
    KpiCard.tsx           ← keep (canonical), remove duplicate in portal-dashboard
    MetricChart.tsx       ← add period selector prop (7/30/90 days)
    ActivityFeed.tsx      ← repurpose to system events feed (or rename + new component)
    PendingTasksCard.tsx  ← keep
    QuickActionsPanel.tsx ← NEW: navigates to real permission-eligible routes
    RecentTransactionsTable.tsx ← NEW: replaces ActivityFeed in bottom section
```

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Consistent Dashboard Entry Point (Priority: P1)

An Admin user opens the application and lands on a single, canonical dashboard that presents 4 KPI cards (Total Users, Active Wallets, Transactions Today, KYC Pending), a transaction trend chart with period selector, an operational activity panel, a recent transactions table with status tabs, and a quick actions panel — all within the correct AdminShell layout.

**Why this priority**: The dashboard is the primary landing page and the most visible proof of layout convergence. A correct dashboard entry validates the shell, tokens, navigation, and data integration all at once.

**Independent Test**: Navigate to `/dashboard` as an authenticated admin. Verify 4 KPI cards render in a single row on 1440px viewport, chart with period selector is visible, bottom section shows transaction table + quick actions side by side at 1.7fr/0.85fr ratio.

**Acceptance Scenarios**:

1. **Given** an authenticated admin at 1440×900, **When** the dashboard loads, **Then** exactly 4 KPI cards appear in a single 4-column row, all showing real data or loading skeletons (no fabricated numbers).
2. **Given** the dashboard, **When** the period selector changes from "Last 7 days" to "Last 30 days", **Then** the chart data refreshes or clearly shows an unavailable state — it does not display stale data labeled as the new period.
3. **Given** the dashboard, **When** the "Completed" tab in Recent Transactions is clicked, **Then** only completed transactions are shown in the table.
4. **Given** the dashboard at 390×844 (mobile), **When** the sidebar icon is tapped, **Then** a drawer slides in with full nav labels, and Escape closes it without page scroll.
5. **Given** the dashboard, **When** a Quick Actions link is clicked, **Then** the user navigates to the corresponding real route (no mock pages).

---

### User Story 2 — Coherent Shell Across All Modules (Priority: P1)

An Admin user navigates from Dashboard to Users, then to KYC Review, then to Fee Rules — experiencing a consistent sidebar, header, breadcrumb, page gutter, and card/table appearance on every page.

**Why this priority**: Without shell consistency, every page visit undermines trust and professionalism. This story validates the end-to-end migration of all existing routes.

**Independent Test**: Click through the sidebar navigation and confirm: active state highlighted, breadcrumbs update, content width is consistent, no horizontal scroll on desktop.

**Acceptance Scenarios**:

1. **Given** any implemented Admin route, **When** the page loads, **Then** the sidebar renders with the current route highlighted with a left-border active indicator and no duplicate navigation menus appear.
2. **Given** any page, **When** viewed at 1280×800, **Then** content renders within the 1500px max-width constraint with 32–36px side gutters and no clipped labels.
3. **Given** any list page (Users, Transactions, KYC, Agents, etc.), **When** the page loads, **Then** the `DataTable` component is used with stable headers, skeleton rows on load, empty state, and permission-aware action buttons.
4. **Given** any detail/form page, **When** the page loads, **Then** `PageContainer` wraps the content and `PageHeader` provides the title + action bar.

---

### User Story 3 — Complete Sidebar Navigation (Priority: P2)

An Admin user can reach all 50+ implemented routes directly from the sidebar without bookmarking or manual URL entry.

**Why this priority**: Missing nav items force users to remember URLs and break the workflow of the admin panel.

**Independent Test**: Compare `NAV_SECTIONS` entries against the route inventory and confirm all implemented routes are reachable from the sidebar grouped under Workspace / Operations / Management.

**Acceptance Scenarios**:

1. **Given** a collapsed sidebar, **When** hovering over a nav icon, **Then** a tooltip showing the route label appears within 200ms.
2. **Given** the sidebar, **When** viewing the Operations group, **Then** KYC Reviews, Agents, Float, Financial Dashboard, Risk Dashboard, Ops sub-pages are all reachable.
3. **Given** the sidebar, **When** viewing the Management group, **Then** Configuration, Multi-Currency, Reports, Reconciliation, Fees are all present.

---

### User Story 4 — Responsive and Accessible Layout (Priority: P2)

An Admin user on a tablet (768px) or mobile (390px) can perform read/approve workflows without horizontal scroll, clipped buttons, or inaccessible interactive elements.

**Why this priority**: WCAG 2.2 AA compliance and mobile-read/approve are constitutional requirements (Principle 11).

**Independent Test**: Resize the browser to 390×844 and navigate a list page. Confirm no horizontal page overflow, table scrolls within its container, drawer nav works, focus rings are visible.

**Acceptance Scenarios**:

1. **Given** a 390px viewport, **When** the sidebar drawer is open, **Then** a semi-transparent overlay covers the main content and pressing Escape closes the drawer.
2. **Given** any interactive element (button, link, input), **When** focused via keyboard Tab, **Then** a visible focus ring appears meeting WCAG 2.2 AA contrast requirements.
3. **Given** a data table, **When** viewed at 390px, **Then** the table scrolls horizontally within its container and the page itself does not overflow.

---

### User Story 5 — Unified Design Token Application (Priority: P3)

All rendered cards, buttons, tables, and form controls use the dark-theme token set consistently — `bg-ok-surface`, `border-neutral-700`, `text-text-primary/secondary` — with no light-theme artifacts or undeclared CSS class names.

**Why this priority**: Token coherence affects every component on every page. Fixing it once eliminates the patchwork appearance.

**Independent Test**: Inspect `Card.tsx`, `Button.tsx`, and `UserManagementPage` in devtools and confirm no `border-neutral-200`, `border-border-subtle`, `bg-surface-secondary`, or `bg-surface-hover` class names are applied.

**Acceptance Scenarios**:

1. **Given** any card surface, **When** rendered, **Then** the border is `neutral-700` and the background is `ok-surface` (dark theme consistent).
2. **Given** `UserManagementPage`, **When** rendered, **Then** no invisible/undefined CSS classes are applied to table rows or filter controls.
3. **Given** `globals.css`, **When** inspected, **Then** no `.dashboard-shell`, `.summary-grid`, or `.summary-card` dead class definitions remain.

---

### Edge Cases

- What happens when a KPI API call fails? Cards must show "Failed to load" with a retry button without collapsing the card grid.
- How does the dashboard behave when all 4 KPI APIs are loading simultaneously? Skeleton cards must maintain the 4-column grid layout.
- What happens if a nav item's route requires a permission the current admin doesn't hold? The item must still navigate (server enforces authorization); no client-side item removal that could hide routes.
- What happens when `MetricChart` is queried for "Last 90 days" but insufficient data exists? Chart renders available data or shows an explicit "Insufficient data for this period" state — not a blank chart.
- How does `PageContainer` behave on ultra-wide screens (>1500px)? Content must be centred with a max-width cap and not stretch edge-to-edge.
- What happens when a user collapses and re-expands the sidebar? The persisted collapse state (sessionStorage) must be restored on reload; CSS transition must respect `prefers-reduced-motion`.

---

## Requirements *(mandatory)*

### Functional Requirements

#### Stage A — Design Token Standardization

- **FR-A01**: `Card.tsx` MUST use `bg-ok-surface border border-neutral-700` as its default surface/border tokens, replacing `bg-bg-primary border-neutral-200`.
- **FR-A02**: `Button.tsx` MUST use `min-height: 35px` for the admin desktop context; mobile touch target (44px) is applied only at `<640px` viewport via responsive CSS.
- **FR-A03**: All undeclared Tailwind class names (`border-border-subtle`, `bg-surface-secondary`, `bg-surface-hover`) MUST be replaced with equivalent declared tokens or removed.
- **FR-A04**: `globals.css` MUST have dead legacy classes (`.dashboard-shell`, `.dashboard-header`, `.dashboard-kicker`, `.summary-grid`, `.summary-card`, `.dashboard-panel`, `.status-pill`, `.empty-state`) removed.

#### Stage B — AdminShell Standardization

- **FR-B01**: `--sidebar-expanded` MUST be set to `252px`; `--sidebar-collapsed` to `70px`; `--header-height` to `66px`; `--content-padding-x` to `32px` (36px on `lg`).
- **FR-B02**: `PageContainer` MUST apply `px-8 lg:px-9 py-8 max-w-[1500px] mx-auto w-full` (or equivalent producing 32/36px gutters and 1500px max-width).
- **FR-B03**: `AdminSidebar` collapsed mode MUST render a tooltip (using `title` + ARIA) for each nav item showing the route label, accessible to keyboard users.
- **FR-B04**: `AdminSidebar` MUST add a user account area at the bottom (avatar + name + role) matching the wireframe `side-bottom` section.

#### Stage C — Navigation Expansion

- **FR-C01**: `NAV_SECTIONS` MUST include all implemented routes grouped into three sections: **Workspace** (Dashboard, Financial Dashboard, Risk Dashboard), **Operations** (Users, Devices, Sessions, KYC Reviews, Agents, Float, Wallets, Transactions, Reconciliation, Fee Rules), **Management** (Audit Events, Compliance, Reports, Security Dashboard, Vulnerability Findings, Multi-Currency, Configuration, Integrations, Operations/Dead Letter, Ops sub-pages, QA, Platform).
- **FR-C02**: Each nav item MUST specify an icon from `lucide-react` that semantically relates to the module.
- **FR-C03**: The active route indicator MUST use a left-border style (`border-l-2 border-ok-primary`) that is visible on both expanded and collapsed states.

#### Stage D — Dashboard Convergence

- **FR-D01**: The canonical dashboard route MUST be `/dashboard`. The `/admin-portal/dashboard` route MUST redirect to or defer to the canonical dashboard, or be removed if it creates duplication.
- **FR-D02**: The dashboard MUST display exactly 4 primary KPI cards in a single 4-column row at ≥1024px: Total Users, Active Wallets, Transactions Today, KYC Pending.
- **FR-D03**: The dashboard MUST have a page eyebrow label ("OPERATIONS / DASHBOARD"), a `<h1>` title ("Business overview"), and a subtitle.
- **FR-D04**: The dashboard MUST include a reporting period selector (Last 7 days / Last 30 days / Last 90 days) that triggers data refresh on the chart. If the API does not support period filtering, the selector MUST be shown with an explicit "Period filter not yet available" state rather than silently displaying stale data.
- **FR-D05**: The chart + operational activity section MUST use a `grid-template-columns: minmax(0, 1.7fr) minmax(270px, 0.85fr)` layout, stacking below 950px.
- **FR-D06**: The bottom section MUST use the same `1.7fr / 0.85fr` grid: left panel is a Recent Transactions table with status tabs (All / Completed / Pending / Failed) and search; right panel is a Quick Actions panel.
- **FR-D07**: The Quick Actions panel MUST contain links only to real, implemented, permission-eligible routes. No placeholder buttons.
- **FR-D08**: `ActivityFeed` (or a renamed equivalent) in the right panel of the chart row MUST surface operational system events (KYC approvals, new registrations, agent onboarding flags) — NOT transaction data, which belongs in the bottom table.
- **FR-D09**: The local `KpiCard` function in `portal-dashboard.tsx` MUST be removed; the shared `components/dashboard/KpiCard` MUST be used everywhere.

#### Stage E — Page Migration

- **FR-E01**: Every route under `app/(admin)/` MUST render within `PageContainer` (or an equivalent container that applies the standardized gutter and max-width) unless it is a deliberate full-bleed page with documented justification.
- **FR-E02**: Every list/management page MUST use `DataTable` component instead of raw `<table>` markup, unless the page contains a specialized table with documented requirements.
- **FR-E03**: `PageHeader` MUST be used on every non-dashboard page to provide consistent title + description + actions layout.

#### Stage F — Quality Gates

- **FR-F01**: TypeScript compilation (`tsc --noEmit`) MUST pass with 0 errors after all changes.
- **FR-F02**: ESLint MUST pass with 0 new warnings or errors introduced.
- **FR-F03**: All existing component unit tests MUST continue to pass.
- **FR-F04**: No existing authenticated workflows, RBAC checks, API call signatures, or financial calculation logic may be altered.

---

### Key Entities

- **AdminShell**: The persistent application frame — sidebar + header + scrollable content area. Single instance per authenticated session.
- **AdminSidebar**: Left navigation with grouped items, collapse/expand state, active route indicator, and bottom user section.
- **PageContainer**: Standardized content wrapper applied to every admin page, enforcing gutter and max-width.
- **KpiCard**: Reusable metric card with label, value, trend, loading skeleton, and error state. Canonical location: `components/dashboard/KpiCard.tsx`.
- **MetricChart**: Area chart of transaction volume. Must accept a `period` prop (`7|30|90`) and query the appropriate data range.
- **RecentTransactionsTable**: New component wrapping `DataTable` with transaction-specific status tabs and search, using existing `searchAdminTransactions` API.
- **QuickActionsPanel**: New component listing navigation shortcuts to high-frequency admin routes, permission-aware, no fabricated actions.
- **NAV_SECTIONS**: Navigation configuration array consumed by `AdminSidebar`. Must be the single source of truth for all navigable routes.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: At 1440×900, the dashboard renders in a single coherent layout with 4 KPI cards, chart+activity row, and table+quick-actions row — no floating isolated elements or inconsistent widths.
- **SC-002**: At 390×844, no horizontal page scroll occurs on any route; the mobile drawer nav opens, shows all labels, and closes on Escape.
- **SC-003**: All sidebar nav items for implemented routes are reachable with a single click; no implemented route requires direct URL entry to access.
- **SC-004**: Zero undeclared Tailwind class names appear in any admin component (verified by TypeScript/build or grep check for `border-border-subtle`, `bg-surface-secondary`, `bg-surface-hover`).
- **SC-005**: Zero dead CSS class definitions remain in `globals.css` (`.dashboard-shell`, `.summary-grid`, etc.).
- **SC-006**: The admin panel renders with a visually consistent dark theme — no light-theme card borders visible against dark surfaces.
- **SC-007**: All 4 KPI cards on the dashboard continue to load real data from their respective API endpoints; no regression in data display.
- **SC-008**: TypeScript build produces 0 new errors; ESLint produces 0 new rule violations.
- **SC-009**: WCAG 2.2 AA: all interactive elements have visible focus rings; all text meets contrast ratio requirements against their background.
- **SC-010**: The reporting period selector on the dashboard either updates the chart with real data or clearly communicates an unavailable state — never silently misrepresents data.

---

## Assumptions

- The existing dark-theme token set (`ok-primary`, `ok-surface`, `neutral-700`, etc.) from `packages/design-system/build/css/tokens.css` is the canonical token source; no new tokens are introduced without updating the design system package.
- The `/admin-portal/dashboard` route will be retired (redirect to `/dashboard`) rather than maintained as a separate product; both pages currently show ecosystem-level data with no unique data on the portal page.
- The `searchAdminTransactions` API already returns the fields needed for the Recent Transactions table (type, status, amount, currency, reference, created_at); no backend schema changes are needed.
- Operational system events (KYC approvals, registrations, agent onboarding) for the Activity Feed are available via the existing audit events API (`/v1/audit/events`); if not, the component will clearly show "System events unavailable" rather than falling back to transaction data.
- The `PendingTasksCard` component remains on the dashboard as the right panel in the chart row (alongside `MetricChart`) since it provides actionable KYC/dispute counts relevant to the operational activity concept.
- The `/admin-portal/dashboard` → `/dashboard` consolidation does not break any existing deep-linked bookmarks used in production — a redirect is sufficient mitigation.
- Button height change (44px → 35px) does not affect the mobile Customer or Agent applications, which have their own button implementations in Flutter and a separate Next.js web app.
- All 50+ routes under `app/(admin)/` already render within `AdminShell` via the `app/(admin)/layout.tsx` layout file — only content container standardization is needed, not shell re-wrapping.
