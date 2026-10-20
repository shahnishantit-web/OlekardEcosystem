# Quickstart Validation Guide: Unified Application Layout & UX Architecture

**Feature**: E36 | **Date**: 2026-10-10

This guide documents runnable validation scenarios to confirm E36 is correctly implemented.

---

## Prerequisites

```bash
# From repo root
cd wallet-ecosystem
docker compose up -d          # Start backend + DB + Redis
cd apps/admin && npm run dev  # Admin portal on http://localhost:3000
cd apps/customer && flutter run --flavor dev
cd apps/agent && flutter run --flavor dev
```

Seed credentials:
- Admin: `admin@olekard.local` / `Admin123!`
- Customer: `+26771000001`, PIN `123456`
- Agent: `+26771000002`, PIN `123456`

---

## SC-001: Admin Navigation — ≤2 Clicks from Dashboard

**Scenario**: Any implemented module reachable in ≤2 clicks

1. Log in as admin → land on Dashboard.
2. Click "Users" in sidebar → Users page loads. (**1 click**)
3. Return to Dashboard.
4. Click "Reports" in sidebar → Reports page loads. (**1 click**)
5. Return to Dashboard.
6. Click "Fee Rules" in sidebar → Fee Rules page loads. (**1 click**)

**Pass criteria**: All navigations succeed in 1 click from the dashboard. No module requires drilling through more than 1 nav level.

---

## SC-002: Admin Snapshot Tests Pass

```bash
cd wallet-ecosystem/apps/admin
npx jest __tests__/a11y/component-snapshots.test.tsx
```

**Pass criteria**: All 21 snapshots PASS. Zero regressions in existing admin functional tests.

```bash
npx jest --testPathPattern="features/" 
```

**Pass criteria**: All pre-existing feature tests pass.

---

## SC-003: Flutter Analyze — 0 Issues

```bash
flutter analyze wallet-ecosystem/apps/customer/lib/
flutter analyze wallet-ecosystem/apps/agent/lib/
flutter analyze wallet-ecosystem/packages/design-system/lib/
```

**Pass criteria**: `No issues found!` for all three.

---

## SC-004: Admin WCAG AA — Zero Violations

```bash
cd wallet-ecosystem/apps/admin
npx jest __tests__/a11y/
# OR run axe DevTools browser extension manually at:
# http://localhost:3000/dashboard (desktop: 1440px)
# http://localhost:3000/admin-portal/users (tablet: 768px)
# http://localhost:3000/reports (mobile: 375px)
```

**Pass criteria**: Zero WCAG 2.2 AA violations for layout elements (nav, header, breadcrumbs, buttons, table headers) at each viewport.

---

## SC-005: No Hardcoded Hex Colors in Flutter Layout Code

```bash
# Search for hardcoded hex colors in Flutter layout/shell/widget files
grep -r "0x[0-9A-Fa-f]\{6,8\}" \
  wallet-ecosystem/apps/customer/lib/core/shell/ \
  wallet-ecosystem/apps/customer/lib/modules/home/presentation/widgets/ \
  wallet-ecosystem/apps/agent/lib/core/shell/ \
  wallet-ecosystem/apps/agent/lib/modules/dashboard/presentation/widgets/ \
  wallet-ecosystem/packages/design-system/lib/src/widgets/

grep -r "Color(0xFF" \
  wallet-ecosystem/apps/customer/lib/core/shell/ \
  wallet-ecosystem/apps/agent/lib/core/shell/
```

**Pass criteria**: Zero matches (all colors reference `OkTokens`, `AppColors`, or `OkThemeExtension`).

---

## SC-006: Sidebar Animation ≤200ms + Reduced Motion

1. Open admin portal at 1440px viewport.
2. Open DevTools → Performance tab.
3. Click sidebar collapse toggle.
4. Measure transition duration.

**Pass criteria**: Animation ≤200ms.

```bash
# Verify reduced-motion media query in CSS
grep -r "prefers-reduced-motion" wallet-ecosystem/apps/admin/styles/
```

**Pass criteria**: At least one `@media (prefers-reduced-motion: reduce)` block exists that disables/removes the sidebar transition.

---

## SC-007: 5 Tabs Render Correctly — Customer + Agent

**Customer**:
1. Launch customer app (dev).
2. Verify bottom nav shows: Home, Wallet, Services, Activity, Account.
3. Tap each tab → verify navigation succeeds.
4. Tap Activity → `TransactionListScreen` renders.
5. Tap Account → `ProfileScreen` renders.

**Agent**:
1. Launch agent app (dev).
2. Verify bottom nav shows: Dashboard, Customers, Transactions, Activity, Account.
3. Tap Customers → `CustomerLookupScreen` renders.
4. Tap Activity → `NotificationsScreen` renders.

**Pass criteria**: All 10 tabs (5 per app) render and navigate without errors.

---

## SC-008: Flutter Widget Tests — Shell Viewports

```bash
cd wallet-ecosystem/apps/customer
flutter test test/golden/
flutter test test/widget/shell/

cd wallet-ecosystem/apps/agent
flutter test test/golden/
flutter test test/widget/shell/
```

**Pass criteria**: All shell widget tests pass at 360dp, 390dp, 430dp simulated viewports.

---

## SC-009: Route Inventory — 100% Admin Route Coverage

```bash
# Count routes in spec route-inventory.md vs actual pages
ls wallet-ecosystem/apps/admin/app/(admin)/**/page.tsx | wc -l
```

Cross-reference against `specs/036-unified-layout-ux/route-inventory.md`.

**Pass criteria**: Every `page.tsx` under `app/(admin)/` appears in the route inventory with auth requirement, permission, and implementation status.

---

## SC-010: Transaction Result Screen — Server-Confirmed Status

**Agent Cash In flow**:
1. Launch agent app.
2. Tap Cash In → search customer → enter amount → review → confirm.
3. On result screen: verify the **transaction ID** is visible (from server response).
4. Verify status shows server-confirmed value (`completed` / `pending` / `failed`).
5. Kill network after confirmation → verify result screen shows `pending` or error, NOT `success`.

**Pass criteria**: Transaction result always reflects server state, never a client-assumed outcome.

---

## Manual Spot-Check Scenarios

### Admin sidebar sessionStorage
1. Open admin portal.
2. Collapse sidebar.
3. Navigate to another page.
4. Verify sidebar remains collapsed.
5. Open a new tab to admin portal.
6. Verify sidebar is expanded (sessionStorage is tab-scoped).

### Customer balance visibility reset
1. Launch customer app.
2. Navigate to Home — balance is visible.
3. Tap hide balance icon — balance is masked.
4. Force-close and reopen app.
5. Verify balance is visible again (state reset).

### Agent float status colors
1. Set agent float balance to a value above threshold.
2. Open Dashboard → verify "Float OK" label with green background.
3. Set float to below low threshold via admin portal.
4. Refresh agent Dashboard → verify "Low Float" label with amber background.
5. Verify both color AND text label are present.
