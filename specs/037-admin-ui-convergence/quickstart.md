# Quickstart Validation Guide: Admin Panel UI/UX Layout Convergence

**Date**: 2026-10-12
**Branch**: `037-admin-ui-convergence`

This guide documents how to validate that each stage of the convergence is complete and correct. Run these checks after implementing each stage — do not wait until all stages are done to validate.

---

## Prerequisites

```powershell
# From wallet-ecosystem/apps/admin/
npm run dev          # start dev server on http://localhost:3000
# OR from repo root:
docker compose up admin  # if Docker is configured
```

Login credentials (dev environment):
- URL: `http://localhost:3000/login`
- Email: `admin@olekard.local`
- Password: `Admin123!`

---

## Stage A — Design Token Standardization

### A-1: Card border/surface tokens

```bash
# Confirm no light-theme card classes remain
grep -r "border-neutral-200\|bg-bg-primary" wallet-ecosystem/apps/admin/components/
# Expected: 0 matches in components/ui/Card.tsx
# (bg-bg-primary may still appear in layout/body context — only check Card.tsx)
```

**Browser check**: Open any admin page. Inspect any `<article>` card element. Confirm computed border color matches `--color-neutral-700` (dark) not a light grey.

### A-2: Button height

```bash
grep -r "minHeight\|min-h-\[44" wallet-ecosystem/apps/admin/components/ui/Button.tsx
# Expected: min-h-[35px] sm:min-h-[44px] (responsive pattern, not hardcoded 44px)
```

**Browser check at 1440px**: Click-target on any Button should appear compact (~35px). Resize to 390px — button height increases to 44px.

### A-3: Dead CSS removal

```bash
grep -n "dashboard-shell\|summary-grid\|summary-card\|dashboard-panel\|status-pill" \
  wallet-ecosystem/apps/admin/styles/globals.css
# Expected: 0 matches
```

### A-4: Undeclared class remediation

```bash
grep -r "border-border-subtle\|bg-surface-secondary\|bg-surface-hover" \
  wallet-ecosystem/apps/admin/src/ wallet-ecosystem/apps/admin/components/
# Expected: 0 matches
```

---

## Stage B — AdminShell Standardization

### B-1: Shell dimension tokens

```bash
grep -A5 "sidebar-expanded\|header-height\|content-padding" \
  wallet-ecosystem/apps/admin/styles/globals.css
# Expected:
# --sidebar-expanded:  252px;
# --sidebar-collapsed: 70px;
# --header-height:     66px;
# --content-padding-x: 32px;
```

**Browser check**: Measure sidebar width at expanded state — should be 252px. Header height — 66px. Content gutter — 32px.

### B-2: PageContainer gutter and max-width

Open any list page at 1440px width. Right-click content area → Inspect. Check computed `padding-left`: should be ≥32px. Check the container's `max-width`: should be 1500px.

At viewport >1500px: content must be centred with visible background on both sides.

### B-3: Sidebar account area

**Browser check**: Confirm the sidebar bottom section shows the admin user's initial avatar, name/email, and role label at all viewport widths. In collapsed mode, only the avatar circle is visible.

### B-4: Collapsed sidebar tooltips

**Browser check**: Collapse the sidebar. Hover over each nav icon. A tooltip with the route label must appear within 1 second (browser-native `title` tooltip).

**Keyboard**: Tab into the nav items while collapsed. Each item must have `aria-label` matching the route label (verify via DevTools accessibility panel).

---

## Stage C — Navigation Expansion

### C-1: All routes reachable

```bash
# Count nav items
grep -c "href:" wallet-ecosystem/apps/admin/src/lib/navigation/nav-items.ts
# Expected: ≥22 items
```

**Browser check**: Click through each NAV_SECTIONS group. Verify:
- Workspace: Dashboard, Financial Dashboard, Risk Dashboard
- Operations: Users, Devices, Sessions, KYC Reviews, Agents, Agent Float, Wallets, Transactions, Reconciliation, Fee Rules
- Management: Audit Events, Compliance, Reports, Security, Multi-Currency, Configuration, Integrations, Dead Letter, Ops Center, QA Dashboard, Platform

### C-2: Active state

Navigate to `/kyc`. Confirm the "KYC Reviews" nav item has a visible left-border active indicator and the text color changes to `ok-primary` teal.

Navigate to `/fees/rules`. Confirm "Fee Rules" is highlighted and previous item is de-highlighted.

### C-3: Section headings in collapsed state

Collapse the sidebar. Section headings (Workspace, Operations, Management) must be hidden. Expand — they reappear.

---

## Stage D — Dashboard Convergence

### D-1: Single dashboard entry point

Navigate to `/admin-portal/dashboard`. Expected: redirected to `/dashboard` (HTTP 307 or client-side redirect). The `/admin-portal/dashboard` route must not show a different dashboard page.

### D-2: Dashboard layout at 1440×900

Open `/dashboard` at exactly 1440×900 viewport.

**Checklist**:
- [ ] Eyebrow label "OPERATIONS / DASHBOARD" visible above h1
- [ ] h1 reads "Business overview"
- [ ] Period selector dropdown (Last 7 days / Last 30 days / Last 90 days) visible in top-right of page title area
- [ ] Exactly 4 KPI cards in a single horizontal row (Total Users, Active Wallets, Transactions Today, KYC Pending)
- [ ] Chart occupies left ≈67% of middle row; ActivityFeed/operational panel occupies right ≈33%
- [ ] RecentTransactionsTable occupies left ≈67% of bottom row; QuickActionsPanel occupies right ≈33%
- [ ] No "Refresh" text-only button in top-right (removed from old layout)

### D-3: KPI cards show real data

All 4 KPI cards should show either:
- A real number from the API, or
- A loading skeleton (animated pulse), or
- An error state with "Retry" button

None of the cards may show a hardcoded/mock number.

### D-4: Period selector interaction

Click "Last 30 days". Expected:
- MetricChart loading state appears briefly
- Chart re-renders with data for the 30-day range (or explicit notice if unavailable)
- No console errors about missing data or type mismatches

### D-5: Transaction status tabs

In the RecentTransactionsTable:
- Click "Completed" tab → table shows only completed transactions
- Click "Failed" tab → table shows only failed transactions (or empty state with correct message)
- Click "All" tab → all transactions return
- Type in the search box → table filters live (debounced, ~300ms)

### D-6: Quick Actions panel

Click "Review KYC requests" → navigates to `/kyc`. No console error. No 404.
Click "View transactions" → navigates to `/admin-portal/transactions`. No console error.

### D-7: No duplicate KpiCard definition

```bash
grep -r "function KpiCard\|const KpiCard" \
  wallet-ecosystem/apps/admin/src/modules/admin-portal/pages/portal-dashboard.tsx
# Expected: 0 matches (local duplicate removed)
```

---

## Stage E — Page Migration

### E-1: PageContainer on all routes

Spot-check 10 routes from the inventory:

```
/sessions
/kyc
/agents
/float
/reconciliation/settlements
/compliance/retention
/reports/schedules
/security/findings
/multi-currency/fx-rates
/configuration/approvals
```

For each: inspect the page's content wrapper. It should be a `div` with `px-8 lg:px-9 py-8 max-w-[1500px]` or equivalent (from `PageContainer`). The `<main>` tag should not carry padding directly.

### E-2: DataTable usage

On `/admin-portal/users`: inspect the rendered table. It must be rendered via the shared `DataTable` component (look for `class="overflow-x-auto rounded-lg border border-neutral-700"` wrapping the table).

No raw `<table>` with inline Tailwind classes in user-management page.

### E-3: PageHeader usage

On any list page: confirm an `<h1>` with `text-2xl font-semibold` and adjacent description text (if applicable) appears at the top of the content area, rendered by `PageHeader`.

---

## Stage F — Quality Gates

### F-1: TypeScript build

```powershell
cd wallet-ecosystem/apps/admin
npx tsc --noEmit
# Expected: 0 errors
```

### F-2: ESLint

```powershell
cd wallet-ecosystem/apps/admin
npm run lint
# Expected: 0 new errors or warnings (pre-existing issues are documented separately)
```

### F-3: Existing tests

```powershell
cd wallet-ecosystem/apps/admin
npm test
# Expected: all pre-existing tests pass; no regressions
```

### F-4: No business logic regression

```
Manual smoke test — verify these workflows still function after all changes:
□ Login / logout as admin
□ View users list, search by email
□ View user detail page
□ Change a user status (suspend/reactivate) if admin has permission
□ View transaction list with status filter
□ View audit events with date range filter
□ Navigate to reconciliation batch list
□ Navigate to fee rules
```

---

## Responsive Validation

### Mobile (390×844)

1. Open the browser DevTools device emulator at 390×844.
2. Navigate to `/dashboard`.
3. **Expected**:
   - No horizontal page scroll
   - KPI cards: 2-column grid
   - Chart and activity panels: stacked vertically
   - Sidebar is hidden (not visible)
4. Tap the hamburger menu icon in the header.
5. **Expected**:
   - Sidebar drawer slides in from left
   - Semi-transparent overlay covers main content
   - All nav labels visible (not collapsed mode)
6. Press Escape key.
7. **Expected**: Drawer closes without page scroll.
8. Navigate to `/admin-portal/users`.
9. **Expected**: User table scrolls horizontally within its container; the page itself does not scroll horizontally.

### Tablet (768×1024)

1. Open at 768×1024.
2. Navigate to `/dashboard`.
3. **Expected**:
   - KPI grid: 2 columns (2×2 layout)
   - Chart and bottom rows: stacked (single column)
   - Sidebar visible at collapsed (70px icon-only) mode

### Large desktop (1440×900)

1. Open at 1440×900.
2. **Expected**:
   - Sidebar expanded (252px)
   - All 4 KPI cards in one row
   - Chart row: ~67/33 split
   - Bottom row: ~67/33 split
   - Content centred with equal gutters

### Ultra-wide (2560×1440)

1. Open at 2560px width.
2. **Expected**:
   - Content does NOT stretch edge-to-edge
   - Max-width cap (1500px) is clearly visible — background colour shows on both sides of the content area

---

## Accessibility Spot-Check

1. Tab through the sidebar nav items. Each must receive a visible focus ring.
2. Tab through the KPI cards. Each `<article>` must have an `aria-label`.
3. Open Axe DevTools (or browser a11y auditor) on `/dashboard`. Expected: 0 critical violations.
4. Activate `prefers-reduced-motion` in OS settings. Reload. Sidebar collapse/expand must have no CSS transition animation.
