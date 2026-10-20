# UI Contracts: Unified Application Layout & UX Architecture

**Feature**: E36 | **Date**: 2026-10-10

UI contracts define the observable behavior guarantees for each new layout component. These are the acceptance-level contracts used to write tests.

---

## Contract 1: Admin Shell — Layout Structure

**Component**: `AdminShell` via `app/(admin)/layout.tsx`

| Condition | Observable Behavior |
|-----------|---------------------|
| Any authenticated admin visits a `(admin)` route | Left sidebar (256px) and top header (64px) are rendered |
| Sidebar is expanded | All nav section titles and item labels are visible |
| Sidebar is collapsed | Sidebar narrows to 72px; icons remain visible; labels are hidden; tooltips appear on hover/focus |
| Collapse toggle is activated | `sessionStorage['ok-sidebar-collapsed']` is updated; animation completes in ≤200ms |
| Page reloads (same tab) | Sidebar restores previous collapse state from `sessionStorage` |
| New tab is opened | Sidebar reverts to expanded (default) state |
| Viewport width < 1024px | Sidebar is hidden; drawer-toggle appears in header; activating it renders an overlay drawer |
| Drawer is open and user taps outside | Drawer closes |
| Drawer is open and user selects a nav item | Drawer closes and navigation proceeds |

---

## Contract 2: Admin Navigation — Route Highlighting

**Component**: `AdminSidebar`

| Condition | Observable Behavior |
|-----------|---------------------|
| Current URL matches a nav item's `href` exactly | Item has `aria-current="page"`, uses `ok-nav-active` background |
| Current URL starts with a nav item's `href` prefix | Active state is shown for parent route (prefix match) |
| Nav item has `requiredPermission` and user lacks it | Item is absent from the DOM (not just hidden with CSS) |
| Nav item has `requiredPermission` and user has it | Item is present and navigable |

---

## Contract 3: Admin Breadcrumbs

**Component**: `Breadcrumbs`

| Condition | Observable Behavior |
|-----------|---------------------|
| User is on any admin page | Breadcrumb trail renders from "Dashboard" to current page |
| User clicks a breadcrumb ancestor link | Navigation proceeds to that route |
| Current page breadcrumb item | Has `aria-current="page"` attribute; is not a link |
| Screen reader reads breadcrumbs | Announces as a navigation landmark with meaningful labels |

---

## Contract 4: Admin KPI Dashboard

**Component**: `KpiCard` × 6

| Condition | Observable Behavior |
|-----------|---------------------|
| Dashboard mounts | All 6 KPI cards show loading skeletons simultaneously (parallel fetch) |
| An API call succeeds | That card's skeleton is replaced with formatted value + label + timestamp |
| An API call fails | That card shows error message + "Retry" button; other cards unaffected |
| User clicks "Retry" on a failed card | That card re-fetches independently |
| Admin has restricted role | Cards for unauthorized data are not rendered |

---

## Contract 5: Customer Bottom Navigation — 5 Tabs

**Widget**: Updated `MainShell`

| Condition | Observable Behavior |
|-----------|---------------------|
| Authenticated customer opens app | Bottom nav shows exactly 5 destinations: Home, Wallet, Services, Activity, Account |
| Customer taps any tab | Active tab indicator uses Consumer Green (#16A34A) `indicatorColor` |
| Customer navigates deep into a sub-screen | Bottom nav remains visible; active tab reflects the root section |
| Device has a system gesture bar | `SafeArea` insets prevent content/nav overlap |
| Customer taps Activity tab | `TransactionListScreen` is displayed at `/activity` |
| Customer taps Account tab | `ProfileScreen` is displayed at `/profile` |

---

## Contract 6: Customer Wallet Summary Card

**Widget**: `WalletSummaryCard`

| Condition | Observable Behavior |
|-----------|---------------------|
| Balance is visible (default) | Formatted balance and currency code are displayed |
| Customer taps visibility toggle | Balance text is replaced with masked characters (e.g., "••••••"); icon changes to reveal icon |
| Customer taps reveal toggle | Balance text is shown again |
| App restarts | Balance is visible by default (state resets) |
| Wallet is loading | Card shows loading shimmer |
| Wallet fetch fails | Card shows error state with retry |
| Any analytics event fires while balance is hidden | Balance value is NOT included in the event payload |

---

## Contract 7: Agent Bottom Navigation — 5 Tabs

**Widget**: `AgentShell`

| Condition | Observable Behavior |
|-----------|---------------------|
| Authenticated agent opens app | Bottom nav shows: Dashboard, Customers, Transactions, Activity, Account |
| Agent taps any tab | Active tab indicator uses Agent Blue (#2563EB) `indicatorColor` |
| Agent taps Customers tab | `CustomerLookupScreen` is displayed at `/customers` |
| Agent taps Activity tab | `NotificationsScreen` is displayed at `/notifications` |
| Device is tablet (≥600dp) | Layout renders without overflow or clipping |

---

## Contract 8: Agent Float Status Card

**Widget**: `FloatStatusCard`

| Condition | Observable Behavior |
|-----------|---------------------|
| Float is in Normal range | Card background uses `ok.successBg`; text uses `ok.successFg`; label reads "Float OK" |
| Float is in Low range | Card background uses `ok.warningBg`; text uses `ok.warningFg`; label reads "Low Float" |
| Float is in Critical range | Card background uses `ok.errorBg`; text uses `ok.errorFg`; label reads "Critical" |
| Any float state | Status is conveyed by BOTH color AND visible text label (not color alone) |

---

## Contract 9: Agent Transaction Step Flow

**Widget**: `TransactionStepFlow`

| Condition | Observable Behavior |
|-----------|---------------------|
| Agent is on step 1 of Cash In | Step indicator shows step 1 highlighted; steps 2–5 shown as upcoming |
| Agent advances a step | Next step becomes highlighted; previous step shows completed indicator |
| Agent completes transaction | Result screen shows server-confirmed transaction ID and status |
| Transaction fails | Result screen shows error state with transaction status (not "Success") and retry path |

---

## Contract 10: Shared Flutter Widgets — Theme Adaptation

**Widgets**: `OkErrorView`, `OkLoadingShimmer`, `OkOfflineBanner`, `OkPrimaryButton`, `OkSuccessView`, `OkPinPadWidget`, `OkAmountDisplay`

| Condition | Observable Behavior |
|-----------|---------------------|
| Widget renders in Customer app | Uses Consumer Green (`OkThemeExtension.consumer()`) color palette |
| Widget renders in Agent app | Uses Agent Blue (`OkThemeExtension.agent()`) color palette |
| Widget renders in either app | No hardcoded hex colors in widget source code |
| `OkPinPadWidget` receives input | PIN digits are never logged; display shows masked characters |

---

## Contract 11: WCAG 2.2 AA — Layout Elements

| Condition | Observable Behavior |
|-----------|---------------------|
| axe-core runs on admin portal | Zero violations for nav, header, breadcrumbs, buttons, tables at all 6 viewports |
| Admin sidebar is collapsed | Collapsed items have `aria-label` tooltips; focus order is logical |
| Font scale is 1.5× in Flutter apps | No text clipping, no layout overflow, all touch targets ≥44dp |
| Any status indicator renders | Status uses both color AND a text label or icon |
| Admin table on 375px viewport | Table scrolls horizontally within container; page header remains accessible |
