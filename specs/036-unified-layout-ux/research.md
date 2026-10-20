# Research: Unified Application Layout & UX Architecture

**Feature**: E36 | **Date**: 2026-10-10 | **Branch**: `036-unified-layout-ux`

---

## Decision 1: Admin Shell Implementation Strategy

**Decision**: Create `app/(admin)/layout.tsx` as a Next.js App Router nested layout that wraps all admin routes. Implement `AdminShell`, `AdminSidebar`, `AdminHeader`, and `Breadcrumbs` as new components in `components/layout/`.

**Rationale**: Next.js App Router's nested layouts are the idiomatic way to apply a persistent shell to a route group. A single `(admin)/layout.tsx` automatically applies to all 50+ pages inside `(admin)/` with zero per-page changes. This is the lowest-impact approach for the existing codebase.

**Alternatives considered**:
- Per-page shell wrapping: Requires editing all 50+ page files — high blast radius, error-prone.
- Higher-order component wrapping: Non-idiomatic for App Router; causes hydration issues with RSC.

**Evidence**: `app/(admin)/` route group already exists with 50+ pages. No `layout.tsx` exists inside `(admin)/` today. The root `layout.tsx` only provides `<Providers>` and theme body class.

---

## Decision 2: Admin Navigation Structure

**Decision**: Reuse the existing `NAV_SECTIONS` array from `src/lib/navigation/nav-items.ts`. Extend `NavSection` and `NavItem` to support icons (optional `icon` field). The `AdminSidebar` component reads `NAV_SECTIONS` directly and filters items by `requiredPermission` using the session's resolved permissions.

**Rationale**: `NAV_SECTIONS` already defines 8 logical groups (Overview, Users & Identity, Finance, Compliance & Audit, Security, Quality, Operations, Platform) with `requiredPermission` metadata per item and section. This work is done. No new architecture needed — just render it.

**Alternatives considered**:
- Backend-driven navigation: Over-engineered for a layout feature; the spec explicitly states navigation is frontend-defined and filtered client-side.
- Hardcoded sidebar: Would duplicate NAV_SECTIONS logic — rejected.

**Evidence**: `src/lib/navigation/nav-items.ts` lines 1–90. All 8 sections cover the 50+ implemented routes.

---

## Decision 3: Admin Sidebar Collapse State

**Decision**: Use `sessionStorage` with key `ok-sidebar-collapsed` (boolean). Read on mount; write on toggle. No cross-tab sync.

**Rationale**: Confirmed by E36 clarification Q1 — session-scoped only. `sessionStorage` resets on tab close which is the desired behavior.

**Alternatives considered**: `localStorage` (cross-tab, rejected per clarification), React state only (no persistence across navigations, rejected per FR-002).

---

## Decision 4: Customer App Shell — 5-Tab Navigation

**Decision**: Update `main_shell.dart` to 5 tabs: Home (`/home`), Wallet (`/wallet`), Services (`/services`), Activity (`/activity`), Account (`/profile`). Rename "Profile" tab to "Account". Add Activity route `/activity` → `TransactionListScreen`. Apply Consumer Green (`AppColors.primary` = `#16A34A`) to `NavigationBar.indicatorColor` and `NavigationBarTheme`.

**Rationale**: The existing `MainShell` has 4 tabs. The spec requires 5. Activity (transaction history) is a primary destination — promoting it from a nested route under `/home/transactions` to a top-level shell tab reduces navigational depth. "Account" is the UX convention for the user profile + settings hub.

**Existing state**: `MainShell` at `lib/core/shell/main_shell.dart` — 4 tabs, default Material3 `NavigationBar` colors (not Consumer Green).

**Alternatives considered**: Keep 4 tabs and show Activity in Home — reduces discoverability of transaction history. Rejected per US2/US6 requirements.

---

## Decision 5: Agent App Shell Extraction

**Decision**: Extract `ScaffoldWithBottomNav` from `app_router.dart` to a new file `lib/core/shell/agent_shell.dart`. Update to 5 tabs: Dashboard (`/dashboard`), Customers (`/customers`), Transactions (`/transactions`), Activity (`/notifications`), Account (`/profile`). Apply Agent Blue (`#2563EB`) theming to `NavigationBarTheme`.

**Rationale**: Inline `ScaffoldWithBottomNav` in the router file is an anti-pattern — shell logic belongs in its own widget. Customers (`/customers` → `CustomerLookupScreen`) is the agent's primary operational tab and should be in the bottom nav. Currently it is only reachable via deep links or transaction flows.

**Existing state**: `ScaffoldWithBottomNav` in `app_router.dart` lines 282–340 — 4 tabs, default Material3 colors.

**Note**: `/customers` route currently uses `parentNavigatorKey: _rootNavigatorKey` (full-screen outside shell). Moving it into the shell requires changing it to `parentNavigatorKey: _shellNavigatorKey` and adding a shell route.

---

## Decision 6: Shared Flutter Widgets — Design System Package

**Decision**: Migrate theme-agnostic shared widgets from both apps to `packages/design-system/lib/src/widgets/`. Both apps already depend on `design_system` (path: `../../packages/design-system`). New widget names use `Ok` prefix: `OkErrorView`, `OkLoadingShimmer`, `OkOfflineBanner`, `OkPrimaryButton`, `OkSuccessView`, `OkPinPadWidget`. Both apps' existing copies are replaced with imports from `design_system`.

**Widgets to migrate** (identical purpose in both apps):
- `shared/widgets/error_view.dart` → `OkErrorView`
- `shared/widgets/loading_shimmer.dart` → `OkLoadingShimmer`
- `shared/widgets/offline_banner.dart` → `OkOfflineBanner`
- `shared/widgets/primary_button.dart` → `OkPrimaryButton`
- `shared/widgets/success_view.dart` → `OkSuccessView`
- `shared/widgets/pin_pad_widget.dart` → `OkPinPadWidget`
- `shared/widgets/amount_display.dart` → `OkAmountDisplay`

**Theming**: Each widget reads `OkThemeExtension.of(context)` for colors — automatically adapts to Consumer Green or Agent Blue based on the app's `ThemeData`.

**Rationale**: FR-020 requires shared widgets to exist "once in a shared location." Both apps already depend on `design_system`. No pubspec.yaml changes needed.

**Risk**: Breaking change for both apps. Mitigated by keeping old files in place and using `export` re-directs until migration is complete, then removing.

---

## Decision 7: Admin Dashboard KPI Cards — API Mapping

**Decision**: The admin dashboard renders 6 KPI cards in parallel. Each card has its own API fetcher (React `use()` + Suspense per card, or parallel `Promise.all`). Per FR-009, each card shows its own loading skeleton and per-card error state.

**KPI → Endpoint mapping** (endpoints from implemented backend):
| KPI | Endpoint | Field |
|-----|----------|-------|
| Total Users | `GET /v1/admin/profile/dashboard/summary` | `total_users` |
| Active Wallets | `GET /v1/admin/wallets/summary` | `active_count` |
| Transactions Today | `GET /v1/admin/transactions/summary` | `today_count` |
| KYC Pending | `GET /v1/admin/kyc/summary` | `pending_count` |
| Active Agents | `GET /v1/admin/agents/summary` | `active_count` |
| Open Disputes | `GET /v1/admin/support/summary` | `open_disputes` |

**Note**: If a summary endpoint does not exist for a module, the KPI card renders an error state with retry. No card shows a fabricated value.

---

## Decision 8: New Layout Components — No New npm Packages

**Decision**: All admin layout components are implemented using existing stack: Next.js App Router, Tailwind CSS (with E35 tokens), React. No new npm packages are introduced.

**Rationale**: Spec assumption 6 — "No new npm packages beyond what already exists in the admin portal will be introduced for this layout work."

**Icon approach**: Use inline SVG or HTML entities for navigation icons. The admin portal does not currently have an icon library. SVG icons are inlined as React components.

---

## Decision 9: Flutter `NavigationBarTheme` Application

**Decision**: Consumer Green and Agent Blue are applied to `NavigationBar` via `NavigationBarTheme` in each app's `ThemeData`. Specifically:
- `indicatorColor`: `AppColors.primary` (Consumer Green / Agent Blue)
- `labelTextStyle`: Selected state uses `AppColors.primary`
- `iconTheme`: Selected icon color = `AppColors.primary`

This uses Material3 `NavigationBarTheme` — no custom painting needed.

**Rationale**: Both apps use Material3 (`useMaterial3: true`). Theming via `ThemeData` is the correct approach — avoids hardcoding colors in the shell widget.

---

## Decision 10: `WalletSummaryCard` Balance Visibility State

**Decision**: `balanceVisible` is a `StateProvider<bool>((_) => true)` in the customer app's Riverpod graph. Toggling via an icon button in the card rebuilds only the card widget. Resets to `true` on app restart (in-memory only).

**Rationale**: Confirmed by E36 clarification Q2. No persistence required. Balance must not be included in any analytics call when hidden — the `AnalyticsService` must check `balanceVisible` before logging wallet events.
