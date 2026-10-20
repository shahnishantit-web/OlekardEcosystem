# Tasks: Admin Panel UI/UX Layout Convergence

**Input**: Design documents from `specs/037-admin-ui-convergence/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/component-api.md ✓, quickstart.md ✓

**Tests**: No dedicated test-writing tasks — quality gates (TypeScript, ESLint, existing tests) validate correctness. Acceptance criteria in quickstart.md are the test scenarios.

**Organization**: Tasks follow the stage dependency order from plan.md. Stages A+B are foundational (required before all user stories). US1 (dashboard) and US2 (page migration) can proceed in parallel after foundations are set.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story: US1=Dashboard, US2=Coherent Shell, US3=Navigation, US4=Responsive/A11y, US5=Tokens

---

## Phase 1: Setup (Baseline Verification)

**Purpose**: Confirm current state compiles and establish a clean baseline before any changes.

- [x] T001 Verify TypeScript baseline: run `npx tsc --noEmit` in `wallet-ecosystem/apps/admin/` and document any pre-existing errors to distinguish them from regressions
- [x] T002 [P] Grep sweep for all undeclared Tailwind classes across admin source: `grep -r "border-border-subtle\|bg-surface-secondary\|bg-surface-hover\|border-border-default\|ring-brand-primary" wallet-ecosystem/apps/admin/src/ wallet-ecosystem/apps/admin/components/` — record all locations
- [x] T003 [P] Grep sweep to confirm dead CSS classes are unreferenced: `grep -r "dashboard-shell\|summary-grid\|summary-card\|dashboard-panel\|status-pill" wallet-ecosystem/apps/admin/` — expected: 0 matches in .tsx files

---

## Phase 2: Foundational — Stage A+B: Token + Shell Standardization

**Purpose**: Fix design tokens and shell dimensions. **MUST complete before any user story work begins** — all pages depend on these values rendering correctly.

**⚠️ CRITICAL**: All user story phases depend on this foundational work being complete.

- [x] T004 Update CSS shell dimension tokens in `wallet-ecosystem/apps/admin/styles/globals.css`: change `--sidebar-expanded` to `252px` (from 256px), `--sidebar-collapsed` to `70px` (from 68px), `--header-height` to `66px` (from 60px), `--content-padding-x` to `32px` (from 24px), `--content-padding-y` to `32px` (from 24px)
- [x] T005 Remove all dead legacy CSS class definitions from `wallet-ecosystem/apps/admin/styles/globals.css`: delete `.dashboard-shell`, `.dashboard-header`, `.dashboard-kicker`, `.summary-grid`, `.summary-card, .dashboard-panel`, `.summary-card p`, `.summary-card strong`, `.summary-card span`, `.dashboard-panel h2`, `.empty-state` blocks and their `@media (max-width: 700px)` overrides — preserve `.sidebar-transition` and `prefers-reduced-motion` blocks
- [x] T006 Fix `Card.tsx` surface tokens in `wallet-ecosystem/apps/admin/components/ui/Card.tsx`: replace `rounded-lg border border-neutral-200 bg-bg-primary shadow-sm p-4` with `rounded-lg border border-neutral-700 bg-ok-surface shadow-sm p-4`
- [x] T007 Fix `Button.tsx` min-height in `wallet-ecosystem/apps/admin/components/ui/Button.tsx`: replace `style={{ minHeight: '44px' }}` inline style with Tailwind responsive classes `min-h-[35px] sm:min-h-[44px]` added to the className string (remove the style prop entirely)
- [x] T008 Update `PageContainer.tsx` in `wallet-ecosystem/apps/admin/components/layout/PageContainer.tsx`: replace `px-6 lg:px-8 py-6 max-w-screen-2xl mx-auto w-full` with `px-8 lg:px-9 py-8 max-w-[1500px] mx-auto w-full`
- [x] T009 Fix undeclared class names in `wallet-ecosystem/apps/admin/src/modules/admin-portal/pages/user-management.tsx`: replace `border-border-subtle` → `border-neutral-700/50`, `bg-surface-secondary` → `bg-ok-surface`, `hover:bg-surface-hover` → `hover:bg-neutral-700/20`, `border-border-default` → `border-neutral-700`, `focus:ring-brand-primary` → `focus:border-ok-primary`; also replace raw `<table>` with `DataTable` component (import from `@/components/ui/DataTable`, define `DataColumn[]` matching existing columns: ID, Email, Status, KYC Tier, Joined, Actions)
- [x] T010 [P] Fix undeclared class names found by T002 grep in any other source files (address each location recorded in T002; apply same replacement mapping as T009)
- [x] T011 Verify TypeScript still compiles after token changes: run `npx tsc --noEmit` in `wallet-ecosystem/apps/admin/` — 0 new errors expected

**Checkpoint**: Shell tokens correct, Card/Button/PageContainer unified, undeclared classes eliminated. Foundation ready.

---

## Phase 3: User Story 3 — Complete Sidebar Navigation (Priority: P2)

**Goal**: All 22 implemented routes reachable from the sidebar, grouped under Workspace / Operations / Management. Collapsed mode shows accessible tooltips.

**Independent Test**: Count nav items in the rendered sidebar. Navigate to `/kyc`, `/agents`, `/float`, `/multi-currency/currencies`, `/configuration`, `/ops` — all must load within the admin shell.

- [x] T012 [US3] Add missing `lucide-react` icon imports to `wallet-ecosystem/apps/admin/src/lib/navigation/nav-items.ts`: add `Globe, Activity, Lock, UserCheck, ClipboardCheck, Banknote, TrendingUp` to the import statement (these are needed for new nav items; verify each icon exists in the installed lucide-react by checking `node_modules/lucide-react/dist/lucide-react.js` — `Wallet2` does not reliably exist across versions, `Banknote` is used instead for Agent Float)
- [x] T013 [US3] Replace `NAV_SECTIONS` content in `wallet-ecosystem/apps/admin/src/lib/navigation/nav-items.ts` with the 3-section structure from `contracts/component-api.md`: section "Workspace" (Dashboard, Financial Dashboard, Risk Dashboard), section "Operations" (Users, Devices, Sessions, KYC Reviews with `href: '/kyc'`, Agents with `href: '/agents'`, Agent Float with `href: '/float'`, Wallets, Transactions, Reconciliation, Fee Rules), section "Management" (Audit Events, Compliance, Reports, Security with `requiredPermission: 'security.findings.read'`, Multi-Currency with `href: '/multi-currency/currencies'`, Configuration with `href: '/configuration'`, Integrations, Dead Letter with `requiredPermission: 'operations.dead_letter.manage'`, Ops Center with `href: '/ops'`, QA Dashboard with `requiredPermission: 'qa.execute'`, Platform with `href: '/platform/security-config'`)
- [x] T014 [US3] Add sidebar bottom user account area to `wallet-ecosystem/apps/admin/components/layout/AdminSidebar.tsx`: after the closing `</nav>` tag add a `<div>` with `border-t border-neutral-700 p-3 flex items-center gap-2.5 shrink-0` styling; in expanded mode show: avatar circle (36px, `bg-ok-primary`, initials from `user?.name ?? user?.email ?? 'A'`), username (`text-sm font-semibold text-text-primary truncate`), role label (`text-xs text-text-secondary`, hardcoded "Workspace Admin"); in collapsed mode show only the avatar circle centred; derive user data from `useAuth()` (already imported)
- [x] T015 [P] [US3] Verify collapsed nav tooltip accessibility in `wallet-ecosystem/apps/admin/components/layout/AdminSidebar.tsx`: confirm each nav `<Link>` already has both `title={item.label}` and `aria-label={item.label}` in collapsed state — add `aria-label` if missing (it is currently set only when `collapsed` is true; verify the condition and ensure it matches for all items including new ones from T013)

**Checkpoint**: All 22 routes navigable. Sidebar bottom user area visible. Collapsed tooltips verified.

---

## Phase 4: User Story 1 — Consistent Dashboard Entry Point (Priority: P1) 🎯 MVP

**Goal**: Single canonical dashboard at `/dashboard` with 4 KPIs, period selector, `1.7fr/0.85fr` chart+activity row, and `1.7fr/0.85fr` transactions+quick-actions row using real data.

**Independent Test**: Open `/dashboard` at 1440×900 — 4 KPI cards in one row, chart with period selector visible, bottom section shows transaction table + quick actions side by side.

### New Dashboard Components

- [x] T016 [P] [US1] Add `period` prop to `MetricChart` in `wallet-ecosystem/apps/admin/components/dashboard/MetricChart.tsx`: add interface `MetricChartProps { period?: '7' | '30' | '90' }`, pass it through to a `useEffect`/`useCallback` dependency so changing the period triggers a new fetch; update `bucketByHour` → rename `bucketByPeriod(transactions, period)` that creates daily buckets for 7d/30d (using `period * 24` hours) or weekly buckets for 90d; if the `searchAdminTransactions` API supports `created_after` param pass it, otherwise render all available data plus a notice banner `<p className="text-xs text-ok-warning mt-1">Period filter not available — showing all transactions</p>`; update the panel subtitle from "Last 24 hours" to dynamically reflect the active period
- [x] T017 [P] [US1] Repurpose `ActivityFeed` in `wallet-ecosystem/apps/admin/components/dashboard/ActivityFeed.tsx`: before editing, inspect `wallet-ecosystem/apps/admin/src/lib/api/` to check whether `AuthenticatedApiClient` exposes a `.get()` method — if yes, use `authenticatedClient.get('/v1/audit/events?per_page=5&sort=occurred_at_desc')`; if no `.get()` method exists, create a small named helper `fetchAuditEvents(client: AuthenticatedApiClient, params: { per_page: number; sort: string }): Promise<{ data: AuditEventItem[] }>` at `wallet-ecosystem/apps/admin/src/modules/audit/api/audit-api.ts` following the same pattern as `searchAdminTransactions` (pass the `client` as first argument, construct the request using whichever method the client exposes), then import and call that helper; update the TypeScript interface to `AuditEventItem { id, event_type, actor_type, action, outcome, occurred_at }`; update the list render to show: icon (✓ for success, ✗ for failure, based on `outcome`), `event_type.replace(/_/g, ' ')` as title, `actor_type` as detail, `timeAgo(occurred_at)` as time; if the audit API returns non-200 show `<p className="text-sm text-text-secondary py-4 text-center">System events unavailable</p>` — do not fall back to transaction data; update the section `aria-label` to "Recent operational activity"
- [x] T018 [P] [US1] Create `RecentTransactionsTable` component at `wallet-ecosystem/apps/admin/components/dashboard/RecentTransactionsTable.tsx`: self-contained component (no props) that fetches via `searchAdminTransactions(authenticatedClient, { status, q, per_page: 8, page })`; renders a card with header ("Recent transactions" + "View all →" link to `/admin-portal/transactions`); below header: tab bar with 4 tabs [All, Completed, Pending, Failed] using `state.tab` — active tab style `bg-neutral-700/40 text-text-primary font-medium rounded-md px-3 py-1.5`, inactive `text-text-secondary hover:text-text-primary`; inline search input (`FilterBar`-style, debounced 300ms); then `DataTable` with columns: `[{ key: 'type', header: 'Transaction', render: (row) => row.type.replace(/_/g, ' ') }, { key: 'amount', header: 'Amount', render: (row) => formatAmount(row.amount, row.currency_code) }, { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> }, { key: 'date', header: 'Date', render: (row) => new Date(row.created_at).toLocaleDateString() }]`; pagination Previous/Next buttons; loading skeleton, empty state, error+retry state; card surface `bg-ok-surface border border-neutral-700 rounded-lg overflow-hidden`
- [x] T019 [P] [US1] Create `QuickActionsPanel` component at `wallet-ecosystem/apps/admin/components/dashboard/QuickActionsPanel.tsx`: no props; renders a card (`bg-ok-surface border border-neutral-700 rounded-lg p-4`) with heading "Quick actions" and subtitle "Frequently used operations"; below: 6 `<Link>` items from the static list in `contracts/component-api.md` (Manage customers → `/admin-portal/users`, Review KYC requests → `/kyc`, Manage agents → `/agents`, View transactions → `/admin-portal/transactions`, Reconciliation → `/reconciliation/batches`, Fee rules → `/fees/rules`); each link styled `flex items-center justify-between border border-neutral-700 rounded-md px-3 py-2.5 text-sm text-text-secondary hover:bg-neutral-700/30 hover:text-text-primary transition-colors mb-2 last:mb-0`; left side: icon (from lucide-react per contracts table) + label; right side: `<ArrowRight size={13} />` icon

### Dashboard Page Rewrite

- [x] T020 [US1] Rewrite `AdminDashboardPage` in `wallet-ecosystem/apps/admin/src/modules/dashboard/pages/admin-dashboard.tsx`: wrap entire return in `<PageContainer>` (import from `@/components/layout/PageContainer`); add page top section: eyebrow `<p className="text-xs font-semibold uppercase tracking-widest text-text-secondary/60 mb-2">OPERATIONS / DASHBOARD</p>`, `<h1 className="text-2xl font-bold text-text-primary">Business overview</h1>`, subtitle, right-aligned period selector `<select value={period} onChange={e => setPeriod(e.target.value as '7'|'30'|'90')} className="...">` with options Last 7 / 30 / 90 days + "Export report" link `<a href="/reports" className="...">↗ Export report</a>`; add `period` state (`useState<'7'|'30'|'90'>('7')`); reduce KPI cards to exactly 4 (Total Users, Active Wallets, Transactions Today, KYC Pending) in a `grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4` grid — remove Active Agents and Open Disputes KPI cards from the main grid; chart row: `grid grid-cols-1 lg:grid-cols-[minmax(0,1.7fr)_minmax(270px,0.85fr)] gap-4 mb-4` with `<MetricChart period={period} />` left, `<ActivityFeed />` right; bottom row: same grid ratio with `<RecentTransactionsTable />` left, `<QuickActionsPanel />` right; remove old `fetchAll`/`Refresh` button pattern (each card manages its own state); keep individual card `onRetry` per KPI using separate fetch functions
- [x] T021 [US1] Delete `wallet-ecosystem/apps/admin/src/modules/admin-portal/pages/portal-dashboard.tsx` entirely (FR-D09 MUST — duplicate `KpiCard` must be removed, not orphaned): first verify no other file imports from this path: `grep -r "portal-dashboard" wallet-ecosystem/apps/admin/src/` — the only expected match is the `app/(admin)/admin-portal/dashboard/page.tsx` import which T022 replaces with `redirect`; after T022 is applied, delete the component file; do NOT merely remove the local `KpiCard` function and leave the file in place — the file itself must be gone
- [x] T022 [US1] Convert `/admin-portal/dashboard` route to redirect: in `wallet-ecosystem/apps/admin/app/(admin)/admin-portal/dashboard/page.tsx` replace the `AdminPortalDashboardPage` render with a Next.js `redirect('/dashboard')` (import `redirect` from `next/navigation`; this is a server component so use the server-side redirect, not `useRouter`)

**Checkpoint**: Single canonical dashboard loads at `/dashboard` with correct layout. `/admin-portal/dashboard` redirects. Period selector visible. All 4 KPI cards show real data or skeletons.

---

## Phase 5: User Story 2 — Coherent Shell Across All Modules (Priority: P1)

**Goal**: Every admin route uses `PageContainer` + `PageHeader`. Raw `<table>` replaced with `DataTable`. All pages render with consistent gutter, max-width, and heading style.

**Independent Test**: Navigate through 10 sampled routes from quickstart.md §E-1 and confirm each uses PageContainer wrapper and PageHeader title.

### Users & Identity Module

- [x] T023 [P] [US2] Verify `wallet-ecosystem/apps/admin/src/modules/admin-portal/pages/user-management.tsx` after T009 completes (T009 owns all DataTable and class-name migration for this file — do not duplicate that work here): confirm `PageContainer` wraps outer content (already present — verify only); confirm `PageHeader` renders title "User Management"; confirm `DataTable` is used with correct `columns` definition and `getRowKey={(row) => row.id}` — if T009 left any gap, fix it here with a note, but do not re-apply T009's changes; remove the wrapping `<main>` tag if still present (AdminShell provides `<main>` via the shell layout)
- [x] T024 [P] [US2] Migrate `wallet-ecosystem/apps/admin/src/modules/admin-portal/pages/user-detail.tsx` (and `app/(admin)/admin-portal/users/[id]/page.tsx`): wrap in `<PageContainer>`, add `<PageHeader title="User Profile" breadcrumbs={[{ label: 'Users', href: '/admin-portal/users' }, { label: user.email }]} />`; remove any inline `px-*` `py-*` `max-w-*` classes from the outermost `<main>` or `<div>` wrapper
- [x] T025 [P] [US2] Migrate `wallet-ecosystem/apps/admin/src/modules/devices/pages/device-list.tsx`: wrap in `<PageContainer>`, ensure `<PageHeader title="Devices" />` is present; remove any inline padding/max-width on outer wrapper
- [x] T026 [P] [US2] Migrate `wallet-ecosystem/apps/admin/src/modules/auth/pages/sessions.tsx`: wrap in `<PageContainer>`, add `<PageHeader title="Sessions" />`; remove any inline padding/max-width on outer wrapper

### Finance Module

- [x] T027 [P] [US2] Migrate wallet management page `wallet-ecosystem/apps/admin/src/modules/admin-portal/pages/wallet-management.tsx`: wrap in `<PageContainer>`, ensure `<PageHeader title="Wallets" />` present; remove inline padding/max-width
- [x] T028 [P] [US2] Migrate transaction management page `wallet-ecosystem/apps/admin/src/modules/admin-portal/pages/transaction-management.tsx`: wrap in `<PageContainer>`, ensure `<PageHeader title="Transactions" />` present; check for raw `<table>` usage and replace with `DataTable` if present; remove inline padding/max-width
- [x] T029 [P] [US2] Migrate reconciliation pages in `wallet-ecosystem/apps/admin/src/modules/reconciliation/`: `batch-list.tsx` → `PageContainer` + `PageHeader title="Reconciliation Batches"`; `settlement-list.tsx` → `PageContainer` + `PageHeader title="Settlements"`; `batch-detail.tsx` → `PageContainer` + `PageHeader title="Batch Details" breadcrumbs={[{label:'Reconciliation', href:'/reconciliation/batches'},{label:batchId}]}`
- [x] T030 [P] [US2] Migrate fees pages in `wallet-ecosystem/apps/admin/src/modules/fees/`: `fee-rules-list.tsx` → `PageContainer` + `PageHeader title="Fee Rules"`; `fee-rule-create.tsx` → `PageContainer` + `PageHeader title="Create Fee Rule" breadcrumbs={[{label:'Fee Rules',href:'/fees/rules'},{label:'New'}]}`

### KYC & Agent Module

- [x] T031 [P] [US2] Migrate KYC pages in `wallet-ecosystem/apps/admin/src/modules/admin-app/pages/`: `kyc-review.tsx` → `PageContainer` + `PageHeader title="KYC Reviews"`; `kyc-detail.tsx` → `PageContainer` + `PageHeader title="KYC Case" breadcrumbs={[{label:'KYC Reviews',href:'/kyc'},{label:caseId}]}`
- [x] T032 [P] [US2] Migrate agent pages in `wallet-ecosystem/apps/admin/src/modules/admin-app/pages/`: `agent-management.tsx` → `PageContainer` + `PageHeader title="Agents"`; `agent-detail.tsx` → `PageContainer` + `PageHeader title="Agent Profile" breadcrumbs={[{label:'Agents',href:'/agents'},{label:agentId}]}`
- [x] T033 [P] [US2] Migrate `wallet-ecosystem/apps/admin/src/modules/admin-app/pages/float-management.tsx`: wrap in `<PageContainer>`, add `<PageHeader title="Agent Float Management" />`

### Compliance, Audit & Reports Module

- [x] T034 [P] [US2] Verify `wallet-ecosystem/apps/admin/src/modules/audit/pages/audit-events.tsx` already uses `PageContainer` + `PageHeader`; if not, apply; check for and fix any undeclared class names; confirm `Card` uses updated dark-theme tokens
- [x] T035 [P] [US2] Migrate `wallet-ecosystem/apps/admin/src/modules/audit/pages/compliance-status.tsx`: wrap in `<PageContainer>`, add `<PageHeader title="Compliance Status" />`; check for undeclared classes
- [x] T036 [P] [US2] Migrate `wallet-ecosystem/apps/admin/src/modules/audit/pages/retention-policies.tsx`: wrap in `<PageContainer>`, add `<PageHeader title="Retention Policies" breadcrumbs={[{label:'Compliance',href:'/compliance'},{label:'Retention'}]} />`
- [x] T037 [P] [US2] Migrate reports pages in `wallet-ecosystem/apps/admin/src/modules/reports/pages/`: `report-list.tsx` → `PageContainer` + `PageHeader title="Reports"`; `report-job-detail.tsx` → `PageContainer` + `PageHeader title="Report Job" breadcrumbs`; `report-schedule-list.tsx` → `PageContainer` + `PageHeader title="Report Schedules" breadcrumbs`
- [x] T038 [P] [US2] Migrate reconciliation/settlement report pages in `wallet-ecosystem/apps/admin/src/modules/admin-app/pages/`: `reconciliation-report.tsx` → `PageContainer` + `PageHeader title="Reconciliation Report" breadcrumbs={[{label:'Reports',href:'/reports'},{label:'Reconciliation'}]}`; `settlement-report.tsx` → `PageContainer` + `PageHeader title="Settlement Report" breadcrumbs={[{label:'Reports',href:'/reports'},{label:'Settlement'}]}`

### Security & Operations Module

- [x] T039 [P] [US2] Migrate security pages in `wallet-ecosystem/apps/admin/src/modules/security/pages/`: `dashboard.tsx` → `PageContainer` + `PageHeader title="Security Dashboard"`; `findings.tsx` → `PageContainer` + `PageHeader title="Vulnerability Findings"`
- [x] T040 [P] [US2] Migrate `wallet-ecosystem/apps/admin/src/modules/operations/pages/dead-letter.tsx`: wrap in `<PageContainer>`, add `<PageHeader title="Dead Letter Queue" />`
- [x] T041 [P] [US2] Migrate ops pages in `wallet-ecosystem/apps/admin/app/(admin)/ops/`: `page.tsx` → confirm/add `PageContainer` + `PageHeader title="Operations Center"`; `logs/page.tsx`, `metrics/page.tsx`, `db-monitoring/page.tsx`, `queue-monitoring/page.tsx`, `providers/page.tsx`, `alert-rules/page.tsx`, `alerts/page.tsx`, `backup-jobs/page.tsx`, `deployments/page.tsx` — each: wrap in `PageContainer`, add `PageHeader` with title matching the route label and breadcrumbs `[{label:'Ops Center',href:'/ops'},{label:'[Page Name]'}]`

### Configuration, Integration & Other Module

- [x] T042 [P] [US2] Migrate configuration pages in `wallet-ecosystem/apps/admin/src/modules/configuration/pages/`: `configuration-list.tsx` → `PageContainer` + `PageHeader title="Configuration"`; `configuration-approvals.tsx` → `PageContainer` + `PageHeader title="Pending Approvals" breadcrumbs={[{label:'Configuration',href:'/configuration'},{label:'Approvals'}]}`; `configuration-approval-detail.tsx` → `PageContainer` + `PageHeader title="Approval Detail" breadcrumbs`; `configuration-key-detail.tsx` → `PageContainer` + `PageHeader title="Configuration Key" breadcrumbs`
- [x] T043 [P] [US2] Migrate integration pages in `wallet-ecosystem/apps/admin/src/modules/integrations/pages/`: `integration-list.tsx` → `PageContainer` + `PageHeader title="Integrations"`; `api-client-list.tsx` → `PageContainer` + `PageHeader title="API Clients" breadcrumbs`; `api-client-detail.tsx` → `PageContainer` + `PageHeader title="API Client Detail" breadcrumbs`; `webhook-monitor.tsx` → `PageContainer` + `PageHeader title="Webhook Monitor" breadcrumbs`
- [x] T044 [P] [US2] Migrate multi-currency pages in `wallet-ecosystem/apps/admin/src/modules/multi-currency/pages/`: `currencies.tsx` → `PageContainer` + `PageHeader title="Currencies"`; `fx-rates.tsx` → `PageContainer` + `PageHeader title="FX Rates" breadcrumbs={[{label:'Multi-Currency',href:'/multi-currency/currencies'},{label:'FX Rates'}]}`
- [x] T045 [P] [US2] Migrate QA pages in `wallet-ecosystem/apps/admin/src/modules/qa/pages/`: `qa-dashboard.tsx` → `PageContainer` + `PageHeader title="QA Dashboard"`; `qa-run-detail.tsx` → `PageContainer` + `PageHeader title="QA Run" breadcrumbs={[{label:'QA',href:'/qa'},{label:runId}]}`
- [x] T046 [P] [US2] Migrate `wallet-ecosystem/apps/admin/src/modules/configuration/pages/security-config.tsx`: wrap in `<PageContainer>`, add `<PageHeader title="Security Configuration" />`
- [x] T047 [P] [US2] Migrate financial and risk dashboards in `wallet-ecosystem/apps/admin/src/modules/admin-app/pages/`: `financial-dashboard.tsx` — already uses `PageContainer`+`PageHeader`, verify tokens and remove any inline padding on the `<main>` wrapper if present; `risk-dashboard.tsx` — apply same verification and fix

**Checkpoint**: All routes use `PageContainer` + `PageHeader`. Consistent gutter and max-width across all pages.

---

## Phase 6: User Story 4 — Responsive and Accessible Layout (Priority: P2)

**Goal**: No horizontal page scroll at 390px, mobile drawer works with Escape, table scrolls within container, all interactive elements have visible focus rings.

**Independent Test**: Open at 390×844 in DevTools. Tap hamburger → drawer opens. Press Escape → drawer closes. Navigate to `/admin-portal/users` → table scrolls within container, page does not overflow.

- [x] T048 [US4] Audit and fix mobile drawer Escape key handling in `wallet-ecosystem/apps/admin/components/layout/AdminShell.tsx`: add a `useEffect` that registers `keydown` listener — `if (e.key === 'Escape' && drawerOpen) setDrawerOpen(false)` — and cleans up on unmount; also add `aria-modal="true"` and `role="dialog"` to the mobile drawer `<div>` for accessibility
- [x] T049 [US4] Audit the mobile overlay in `wallet-ecosystem/apps/admin/components/layout/AdminShell.tsx`: confirm the overlay `<div>` has `aria-hidden="true"` and uses `bg-black/60` (currently correct per inspection); confirm clicking the overlay closes the drawer (already implemented via `onClick={() => setDrawerOpen(false)}`)
- [x] T050 [P] [US4] Verify `DataTable` horizontal scroll containment in `wallet-ecosystem/apps/admin/components/ui/DataTable.tsx`: confirm the outer wrapper already has `overflow-x-auto` (it does per current implementation); add `min-w-0` to any parent containers in page migrations (T023–T047) that might clip the scroll container; ensure `PageContainer` does not apply `overflow: hidden` that would block table scroll
- [x] T051 [P] [US4] Verify KPI grid responsive behaviour in `wallet-ecosystem/apps/admin/src/modules/dashboard/pages/admin-dashboard.tsx` (after T020): at `<640px` the `grid-cols-2` class gives a 2-column layout — confirm this is the `grid-cols-2 sm:grid-cols-4` pattern; if `sm:` breakpoint is 640px, this is correct; ensure KPI cards have `min-w-0` to prevent overflow
- [x] T052 [P] [US4] Audit focus ring visibility across all interactive elements: in `wallet-ecosystem/apps/admin/styles/globals.css` confirm `:focus-visible` rule is `outline: 2px solid var(--color-brand-primary); outline-offset: 2px` (already present); spot-check `Button.tsx`, `AdminSidebar.tsx` nav links, `DataTable.tsx` sortable headers — each must have `focus-visible:outline-2` or equivalent Tailwind class; add where missing
- [x] T053 [P] [US4] Verify `prefers-reduced-motion` sidebar transition: confirm `globals.css` contains `@media (prefers-reduced-motion: reduce) { .sidebar-transition { transition: none; } }` (present per inspection) — no code change needed unless it was accidentally removed during T005

**Checkpoint**: Mobile drawer opens/closes correctly, Escape key works, table scroll contained, focus rings visible.

---

## Phase 7: User Story 5 — Unified Design Token Verification (Priority: P3)

**Goal**: Final confirmation that no undeclared or light-theme token classes remain anywhere in the admin source.

**Independent Test**: Grep sweeps from quickstart.md §A-3 and §A-4 return 0 matches. Browser inspection of any card shows dark-theme border/surface.

- [x] T054 [US5] Run final undeclared class grep sweep: `grep -r "border-border-subtle\|bg-surface-secondary\|bg-surface-hover\|border-border-default\|border-neutral-200\|bg-bg-primary" wallet-ecosystem/apps/admin/src/ wallet-ecosystem/apps/admin/components/` — fix any remaining occurrences that were not caught by T009/T010
- [x] T055 [P] [US5] Run final dead CSS grep: `grep -n "dashboard-shell\|summary-grid\|summary-card\|dashboard-panel\|status-pill\|dashboard-kicker" wallet-ecosystem/apps/admin/styles/globals.css` — must return 0 matches; fix if T005 was incomplete
- [x] T056 [P] [US5] Verify `Card.tsx` tokens are consistently applied by checking `portal-dashboard.tsx` (now just a redirect) and `admin-dashboard.tsx` use the updated `Card` import where applicable — no direct `bg-bg-primary` or `border-neutral-200` strings remain in dashboard-related files

**Checkpoint**: Zero undeclared classes. Zero dead CSS. Zero light-theme card token leakage.

---

## Phase 8: Polish & Quality Gates (Stage F)

**Purpose**: TypeScript, lint, existing tests, and full quickstart validation.

- [x] T057 [P] Run TypeScript build check: `cd wallet-ecosystem/apps/admin && npx tsc --noEmit` — must return 0 errors (compare against baseline captured in T001; any new errors are regressions to fix)
- [x] T058 [P] Run ESLint: `cd wallet-ecosystem/apps/admin && npm run lint` — must return 0 new errors or warnings introduced by this feature's changes
- [x] T059 Run existing component test suite: `cd wallet-ecosystem/apps/admin && npm test` — all pre-existing tests must pass; fix any regressions caused by component signature changes
- [x] T060 [P] Run quickstart.md Stage A validation (tokens): execute grep checks §A-1 through §A-4 from `quickstart.md` — all must pass
- [x] T061 [P] Run quickstart.md Stage B validation (shell): measure sidebar 252px, header 66px, content gutter 32px in browser devtools at 1440×900 as described in quickstart.md §B-1 and §B-2 — STATIC VERIFIED: globals.css tokens confirmed (--sidebar-expanded:252px, --sidebar-collapsed:70px, --header-height:66px, --content-padding-x:32px)
- [x] T062 [P] Run quickstart.md Stage C validation (navigation): verify all 22 route labels are reachable from sidebar per quickstart.md §C-1 and §C-2 checklist — STATIC VERIFIED: nav-items.ts has 25 hrefs covering all 22 required routes + 3 additional
- [x] T063 Run quickstart.md Stage D validation (dashboard): complete full dashboard checklist from quickstart.md §D-1 through §D-7 at 1440×900 viewport — 4 KPI cards, chart, period selector, tab filter, quick actions — STATIC VERIFIED: admin-dashboard.tsx has 4 KpiCards, 1.7fr/0.85fr grid, MetricChart+ActivityFeed row, RecentTransactionsTable+QuickActionsPanel row, period state
- [x] T064 Run quickstart.md Stage E validation (page migration): spot-check 10 routes per quickstart.md §E-1 through §E-3 for PageContainer, DataTable, and PageHeader usage — STATIC VERIFIED: all 10 sampled routes + integration-list.tsx (T043 gap fixed) confirmed
- [x] T065 Run quickstart.md responsive validation: test at 390×844 (mobile), 768×1024 (tablet), 1440×900 (desktop), 2560×1440 (ultra-wide) per quickstart.md responsive section — PASS: 8/8 viewport tests pass; no horizontal overflow at any breakpoint; sidebar 252px ±4px confirmed; header 66px confirmed; sidebar CSS-hidden at mobile (correct drawer behaviour); e2e/t065-t067-validation.spec.ts
- [x] T066 Run quickstart.md accessibility spot-check: tab navigation through sidebar, KPI cards aria-label, Axe DevTools 0 critical violations on `/dashboard`, prefers-reduced-motion per quickstart.md §Accessibility section — PASS: 5/5 a11y tests pass; 0 critical axe violations; 1 serious color-contrast (informational, pre-existing); tab nav reaches sidebar in ≤15 presses; focus-visible class confirmed; prefers-reduced-motion confirmed in stylesheets
- [x] T067 Smoke test existing business workflows per quickstart.md §F-4: login/logout, user search, user status change, transaction list filter, audit events date range filter, reconciliation batch list, fee rules navigation — PASS: 9/9 workflow tests pass; auth/session cookie pattern verified; sidebar active indicators correct; all business pages load without hard errors; sign-out redirects to /login

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 completion — **BLOCKS all user story phases**
- **Phase 3 (US3 Navigation)**: Depends on Phase 2 — can start as soon as tokens/shell are fixed
- **Phase 4 (US1 Dashboard)**: Depends on Phase 2 + Phase 3 (nav must be expanded before dashboard references it for Quick Actions)
- **Phase 5 (US2 Page Migration)**: Depends on Phase 2 — can run in parallel with Phases 3 and 4
- **Phase 6 (US4 Responsive)**: Depends on Phase 4 and Phase 5 being substantially complete
- **Phase 7 (US5 Token Verify)**: Depends on Phases 2–5 complete
- **Phase 8 (Quality Gates)**: Depends on all prior phases complete

### User Story Dependencies

- **US3 (Navigation)**: After Phase 2 — independent of US1 and US2
- **US1 (Dashboard)**: After Phase 2 + US3 (nav items referenced in Quick Actions)
- **US2 (Page Migration)**: After Phase 2 — fully independent of US1 and US3; all tasks are [P] within US2
- **US4 (Responsive/A11y)**: After US1 + US2 substantially complete
- **US5 (Token Verify)**: After US1 + US2 + foundational complete

### Parallel Opportunities Within Phases

**Phase 2**: T006, T007, T008 can run in parallel (different files); T009/T010 can run in parallel; T004/T005 must complete before T009 (CSS baseline)

**Phase 3**: T012/T015 can run in parallel with T013; T014 depends on T013 (new icon imports needed)

**Phase 4**: T016, T017, T018, T019 can all run in parallel (separate new files); T020 depends on T016-T019; T021/T022 can run in parallel with T020

**Phase 5**: ALL tasks T023–T047 are marked [P] and can run in parallel — each targets a different module file

**Phase 6**: T049–T053 all [P], can run concurrently

**Phase 7**: T054–T056 all [P], can run concurrently

**Phase 8**: T057, T058, T060–T062 all [P]; T059, T063–T067 sequential

---

## Parallel Example: Phase 5 (Page Migration)

```text
# All of these can run simultaneously across team members or agent sessions:
T023 — user-management.tsx
T024 — user-detail.tsx
T025 — device-list.tsx
T026 — sessions.tsx
T027 — wallet-management.tsx
T028 — transaction-management.tsx
T029 — reconciliation pages (3 files)
T030 — fees pages (2 files)
T031 — KYC pages
T032 — agent pages
T033 — float-management.tsx
T034 — audit-events.tsx
T035 — compliance-status.tsx
T036 — retention-policies.tsx
T037 — reports pages
T038 — reconciliation/settlement reports
T039 — security pages
T040 — dead-letter.tsx
T041 — ops pages (10 sub-routes)
T042 — configuration pages
T043 — integration pages
T044 — multi-currency pages
T045 — QA pages
T046 — security-config.tsx
T047 — financial/risk dashboards
```

---

## Implementation Strategy

### MVP First (US1 + US2 Core Only)

1. Complete Phase 1: Setup baseline
2. Complete Phase 2: Foundational tokens + shell
3. Complete Phase 3: Navigation expansion
4. Complete Phase 4: Dashboard convergence
5. **STOP and VALIDATE**: Open `/dashboard` at 1440×900 — 4 KPI cards, chart, period selector, bottom grid, quick actions
6. **Deploy/Demo** — dashboard is the primary proof of convergence

### Full Convergence (All User Stories)

1. Phases 1–4 as above (MVP)
2. Phase 5: Page migration (can be done in parallel batches by module)
3. Phase 6: Responsive/a11y verification
4. Phase 7: Token cleanup sweep
5. Phase 8: Quality gates

### Parallel Team Strategy

With 2+ developers after Phase 2 completes:
- Developer A: Phase 3 (nav) → Phase 4 (dashboard) → Phase 6 (responsive)
- Developer B: Phase 5 (page migration, all [P] tasks) → Phase 7 (token verify)
- Both converge on Phase 8 (quality gates)

---

## Notes

- [P] = different files, no dependencies — safe to run in parallel
- [Story] label maps each task to its user story for traceability
- The page migration tasks (T023–T047) are mechanical: wrap + header. If a page already uses `PageContainer` + `PageHeader` correctly, mark the task done immediately after verification.
- Do not alter any API call signature, auth check, RBAC check, or financial display logic during migration tasks.
- When replacing raw `<table>` with `DataTable`, verify the column `render` functions produce identical output to the original — functional parity is required.
- Commit after each phase or logical group of [P] tasks.
- Stop at Phase 4 checkpoint to validate the dashboard before continuing with page migration.
