# Tasks: E36 — Unified Application Layout & UX Architecture

**Input**: Design documents from `specs/036-unified-layout-ux/`

**Prerequisites**: plan.md ✅, spec.md ✅, research.md ✅, data-model.md ✅, contracts/ui-contracts.md ✅, quickstart.md ✅

**Format**: `- [ ] [ID] [P?] [Story?] Description — file path`

---

## Phase 1: Setup

**Purpose**: Confirm project structure is ready for implementation.

- [X] T001 Confirm `packages/design-system/lib/src/` directory exists and contains `tokens.dart`; note current exports in `packages/design-system/lib/design_system.dart`
- [X] T002 Confirm `apps/admin/app/(admin)/` route group exists with 50+ `page.tsx` files and NO existing `layout.tsx` inside `(admin)/`
- [X] T003 Confirm both `apps/customer/pubspec.yaml` and `apps/agent/pubspec.yaml` reference `design_system: path: ../../packages/design-system`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Migrate shared Flutter widgets to the design-system package and create the admin shell stub. MUST complete before user story phases.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T004 Create directory `packages/design-system/lib/src/widgets/` and add `.gitkeep` placeholder
- [X] T005 [P] Create `packages/design-system/lib/src/widgets/ok_error_view.dart` — migrated from both apps' `shared/widgets/error_view.dart`; widget accepts `message: String`, `onRetry: VoidCallback?`; reads `OkThemeExtension.of(context)` for colors; no hardcoded hex
- [X] T006 [P] Create `packages/design-system/lib/src/widgets/ok_loading_shimmer.dart` — migrated from both apps' `shared/widgets/loading_shimmer.dart`; accepts `width`, `height`, `borderRadius`; shimmer uses `ok.surface` base color
- [X] T007 [P] Create `packages/design-system/lib/src/widgets/ok_offline_banner.dart` — migrated from both apps' `shared/widgets/offline_banner.dart`; reads `ConnectivityService` or accepts `isOffline: bool`; uses `OkTokens.colorStatusWarning` for amber background (no hardcoded `offlineAmber`)
- [X] T008 [P] Create `packages/design-system/lib/src/widgets/ok_primary_button.dart` — migrated from both apps' `shared/widgets/primary_button.dart`; accepts `label`, `onPressed`, `isLoading`; minimum height 48dp; uses `OkThemeExtension.primary`
- [X] T009 [P] Create `packages/design-system/lib/src/widgets/ok_success_view.dart` — migrated from both apps' `shared/widgets/success_view.dart`; accepts `title`, `message`, `onContinue`; uses `ok.successFg`
- [X] T010 [P] Create `packages/design-system/lib/src/widgets/ok_pin_pad_widget.dart` — migrated from both apps' `shared/widgets/pin_pad_widget.dart`; PIN digits never logged; no plaintext display; accepts `onCompleted: void Function(String pin)`, `pinLength: int`
- [X] T011 [P] Create `packages/design-system/lib/src/widgets/ok_amount_display.dart` — migrated from both apps' `shared/widgets/amount_display.dart`; accepts `amountMinorUnits: int`, `currencyCode: String`, `isCredit: bool?`; formats via `intl`; uses `ok.successFg`/`ok.errorFg` for credit/debit sign
- [X] T012 Update `packages/design-system/lib/design_system.dart` to export all 7 new widgets: `ok_error_view.dart`, `ok_loading_shimmer.dart`, `ok_offline_banner.dart`, `ok_primary_button.dart`, `ok_success_view.dart`, `ok_pin_pad_widget.dart`, `ok_amount_display.dart`
- [X] T013 [P] Update all `import` references in `apps/customer/lib/shared/widgets/` — replace each file's content with a `export 'package:design_system/...'` re-export pointing to the corresponding `Ok*` widget; update all consumer imports in `apps/customer/lib/` to use `design_system` package
- [X] T014 [P] Update all `import` references in `apps/agent/lib/shared/widgets/` — replace each file's content with a `export 'package:design_system/...'` re-export pointing to the corresponding `Ok*` widget; update all consumer imports in `apps/agent/lib/` to use `design_system` package
- [X] T015 Create stub `apps/admin/app/(admin)/layout.tsx` — returns `<>{children}</>` temporarily; confirms the Next.js nested layout is wired and all admin pages still load

---

## Phase 3: US1 — Admin Portal Navigation Shell (Priority: P1) 🎯 MVP

**Goal**: Authenticated admin users navigate the entire portal via a collapsible sidebar + sticky header, with active route highlighting, permission filtering, and mobile drawer behavior.

**Independent Test**: Open admin portal at `/dashboard`; verify sidebar renders with OleKard branding and Teal `ok-primary` color; navigate to Users, Reports, Fee Rules (1 click each); collapse sidebar → width narrows to 72px, icons remain, tooltips visible; resize to 768px → drawer-toggle appears in header.

- [X] T016 [US1] Create `apps/admin/components/layout/Breadcrumbs.tsx` — accepts `items: Array<{label: string; href?: string}>`; final item has `aria-current="page"` and is not a link; wrapped in `<nav aria-label="Breadcrumb">`; uses `text-text-secondary` and `text-text-primary` tokens
- [X] T017 [US1] Create `apps/admin/components/layout/AdminHeader.tsx` — `'use client'`; accepts `onSidebarToggle: () => void`, `sidebarCollapsed: boolean`; renders sidebar toggle button (hamburger/arrow icon, `aria-label="Toggle navigation"`), `<Breadcrumbs>` (breadcrumbs derived from `usePathname()`), user avatar/menu placeholder with logout link; height 64px; `bg-ok-surface border-b border-neutral-200`
- [X] T018 [US1] Create `apps/admin/lib/hooks/useBreadcrumbs.ts` — `'use client'` hook; reads `usePathname()` and maps URL segments against `NAV_SECTIONS` in `src/lib/navigation/nav-items.ts` to build breadcrumb trail array; returns `Array<{label: string; href?: string}>`
- [X] T019 [US1] Update `apps/admin/components/layout/AdminHeader.tsx` to import and call `useBreadcrumbs()` and pass result to `<Breadcrumbs>`
- [X] T020 [US1] Create `apps/admin/components/layout/AdminSidebar.tsx` — `'use client'`; props: `currentPath: string`, `collapsed: boolean`, `onToggle: () => void`; reads `NAV_SECTIONS` from `src/lib/navigation/nav-items.ts`; filters items by `requiredPermission` using `useSession()` (next-auth) resolved permissions — items without matching permission are absent from DOM; renders OleKard logo (text "OleKard" in `text-ok-primary`), nav groups with section headers, `<Navigation>` component for items in each group, user account section at bottom; collapsed state: width 72px, icons only with `title` tooltip; expanded state: width 256px, icons + labels; active route: current page uses `bg-ok-nav-active text-ok-primary`; sidebar has `overflow-y-auto` to handle tall nav
- [X] T021 [US1] Create `apps/admin/components/layout/AdminShell.tsx` — `'use client'`; manages `collapsed` state with `sessionStorage` key `ok-sidebar-collapsed` (read on mount, write on toggle); manages `drawerOpen` state for mobile; renders `<AdminSidebar>` on left + `<AdminHeader>` on top + `<main>` content; desktop: sidebar always visible at left; viewport < 1024px: sidebar hidden, drawer rendered as overlay when `drawerOpen` is true; sidebar transition: `transition-all duration-200` (≤200ms per SC-006); `@media (prefers-reduced-motion: reduce)` handled via Tailwind `motion-reduce:transition-none`
- [X] T022 [US1] Update `apps/admin/app/(admin)/layout.tsx` to import and render `<AdminShell>` wrapping `{children}` — remove temporary stub; verify all admin pages still receive the shell
- [X] T023 [US1] Add `@media (prefers-reduced-motion: reduce) { .sidebar-transition { transition: none !important; } }` to `apps/admin/styles/globals.css`; add `sidebar-transition` class to sidebar element in `AdminShell.tsx`
- [X] T024 [US1] Write widget test `apps/admin/__tests__/layout/AdminShell.test.tsx` — renders `<AdminShell>` with mock children; asserts sidebar renders with `aria-label="Main navigation"`; asserts `sessionStorage` key is read on mount; asserts collapse toggle changes width class; asserts drawer appears at 768px viewport mock
- [X] T025 [US1] Run `npx jest apps/admin/__tests__/layout/` and confirm all tests pass; fix any issues found

**Checkpoint**: Admin portal has a working sidebar + header. Navigate to 5+ modules and verify active highlighting, breadcrumbs, and collapse behavior.

---

## Phase 4: US2 — Customer Mobile Navigation Shell (Priority: P2)

**Goal**: Authenticated customers navigate via a 5-tab bottom nav (Home, Wallet, Services, Activity, Account) with Consumer Green active-tab highlighting and correct SafeArea behavior.

**Independent Test**: Launch customer app; verify 5 tabs visible; tap Activity → TransactionListScreen; tap Account → ProfileScreen; active tab uses `#16A34A` indicator; no clipping at system nav bar.

- [X] T026 [US2] Update `apps/customer/lib/core/theme/app_theme.dart` — add `NavigationBarTheme` to `ThemeData.dark`: `indicatorColor: AppColors.primary`, `labelTextStyle` selected state color `AppColors.primary`, icon theme selected color `AppColors.primary`; background `AppColors.surface`
- [X] T027 [US2] Update `apps/customer/lib/core/shell/main_shell.dart` to 5 tabs — rename index 3 from "Profile" to "Account" (`Icons.person`); add Activity tab at index 3 (`Icons.receipt_long_outlined` / `Icons.receipt_long`); update `_indexForLocation` to map `/activity` → index 3; update `_onTap` to navigate `case 3: context.go('/activity')`, `case 4: context.go('/profile')`; wrap scaffold `body` in `SafeArea(top: true, bottom: false)` to protect content from status bar
- [X] T028 [US2] Update `apps/customer/lib/core/router/app_router.dart` — add two routes inside `ShellRoute`: `GoRoute(path: '/activity', builder: (_, __) => const TransactionListScreen())` and `GoRoute(path: '/activity/:id', builder: (context, state) => TransactionDetailScreen(id: state.pathParameters['id']!))` — remove old `/home/transactions` and `/home/transactions/:id` entries from the shell (or keep as redirects to `/activity`)
- [X] T029 [US2] Create `apps/customer/test/widget/shell/main_shell_test.dart` — pumps `MainShell` with `ProviderScope` + `MaterialApp` wrapping; asserts 5 `NavigationDestination` widgets render; asserts tap on index 3 calls navigation to `/activity`; asserts tap on index 4 calls navigation to `/profile`; tests at three simulated widths (360, 390, 430)
- [X] T030 [US2] Run `flutter test apps/customer/test/widget/shell/` and confirm all tests pass; run `flutter analyze apps/customer/lib/` and confirm 0 issues

**Checkpoint**: Customer app shows 5 tabs. Activity and Account tabs navigate correctly. Consumer Green is visible on active tab indicator.

---

## Phase 5: US3 — Agent Mobile Navigation Shell (Priority: P3)

**Goal**: Authenticated agents navigate via a 5-tab bottom nav (Dashboard, Customers, Transactions, Activity, Account) with Agent Blue theme; Customers tab shows CustomerLookupScreen directly from the shell.

**Independent Test**: Launch agent app; verify 5 tabs; tap Customers → `CustomerLookupScreen`; tap Activity → `NotificationsScreen`; active tab uses Agent Blue (#2563EB); no overflow on 600dp tablet.

- [X] T031 [US3] Update `apps/agent/lib/core/theme/app_theme.dart` — add `NavigationBarTheme` with `indicatorColor: AppColors.primary` (Agent Blue), selected label/icon color `AppColors.primary`; background `AppColors.surface`
- [X] T032 [US3] Create `apps/agent/lib/core/shell/agent_shell.dart` — `StatelessWidget AgentShell({required Widget child})`; renders 5-tab `NavigationBar` using `AgentShell._destinations`; maps locations via `_indexForLocation(String loc)`: `/dashboard`→0, `/customers`→1, `/transactions`→2, `/notifications`→3, `/profile`/`/change-pin`/`/security`→4; `Scaffold` with `bottomNavigationBar`; wraps body with `SafeArea(top: true, bottom: false)`
- [X] T033 [US3] Update `apps/agent/lib/core/router/app_router.dart` — replace inline `ScaffoldWithBottomNav` class with import of `AgentShell`; update `ShellRoute` builder to `(_, __, child) => AgentShell(child: child)`; move `/customers` route from `parentNavigatorKey: _rootNavigatorKey` into `ShellRoute.routes` with `parentNavigatorKey: _shellNavigatorKey`; add `/notifications` route inside `ShellRoute` pointing to `NotificationsScreen`
- [X] T034 [US3] Remove the inline `ScaffoldWithBottomNav` class from `apps/agent/lib/core/router/app_router.dart` (now replaced by `AgentShell`)
- [X] T035 [US3] Create `apps/agent/test/widget/shell/agent_shell_test.dart` — pumps `AgentShell` with mock child; asserts 5 `NavigationDestination` labels (Dashboard, Customers, Transactions, Activity, Account); asserts Customers tap routes to `/customers`; asserts Agent Blue `indicatorColor` is set
- [X] T036 [US3] Run `flutter test apps/agent/test/widget/shell/` and confirm tests pass; run `flutter analyze apps/agent/lib/` and confirm 0 issues

**Checkpoint**: Agent app shows 5 tabs. Customers tab is functional from the bottom nav. Agent Blue active indicator visible.

---

## Phase 6: US4 — Admin Dashboard (Priority: P4)

**Goal**: Admin dashboard shows 6 KPI cards (Total Users, Active Wallets, Transactions Today, KYC Pending, Active Agents, Open Disputes) loading in parallel from real API endpoints, each with loading skeleton and per-card error state.

**Independent Test**: Log in as admin → dashboard; all 6 cards show skeletons then real values; block one API call → that card shows error + retry; click retry → card re-fetches.

- [X] T037 [US4] Create `apps/admin/src/modules/dashboard/api/kpi-summary.ts` — exports 5 new fetcher functions (total-users already in `dashboard.ts`): `getActiveWalletsSummary`, `getTransactionsTodaySummary`, `getKycPendingSummary`, `getActiveAgentsSummary`, `getOpenDisputesSummary`; each calls its respective implemented endpoint (`/v1/admin/wallets/summary`, `/v1/admin/transactions/summary`, `/v1/admin/kyc/summary`, `/v1/admin/agents/summary`, `/v1/admin/support/summary`) and returns typed response
- [X] T038 [US4] Create `apps/admin/components/dashboard/KpiCard.tsx` — accepts `label: string`, `value: number | string | null`, `unit?: string`, `refreshedAt?: Date | null`, `loading?: boolean`, `error?: boolean`, `onRetry?: () => void`; loading state: animated shimmer skeleton using CSS; error state: error icon + "Failed to load" text + "Retry" button; loaded state: large formatted value + label + unit + `refreshedAt` timestamp; uses `bg-ok-surface border border-neutral-200 rounded-lg p-6`; no hardcoded colors
- [X] T039 [US4] Update `apps/admin/src/modules/dashboard/pages/admin-dashboard.tsx` to render a 6-card `KpiCard` grid using `Promise.all` for parallel fetch; each card manages its own `loading`/`error`/`data` state via `useEffect`; role check: only render cards authorized for the current admin's permissions; grid layout: `grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6`
- [X] T040 [US4] Write `apps/admin/__tests__/dashboard/admin-dashboard.test.tsx` — mocks all 6 API fetchers; asserts all 6 KpiCards render; asserts loading skeleton shown while fetching; asserts error state + retry shown when one fetcher rejects; asserts formatted value shown when fetcher resolves
- [X] T041 [US4] Run `npx jest apps/admin/__tests__/dashboard/` and confirm tests pass

**Checkpoint**: Admin dashboard shows 6 KPI cards with real data, skeletons, and per-card error states.

---

## Phase 7: US5 — Admin Page Template Consistency (Priority: P5)

**Goal**: Every admin module page follows a consistent breadcrumb → h1 → description → actions → content pattern using reusable `PageContainer` and `PageHeader` components.

**Independent Test**: Navigate to Users, Reports, Approvals, QA Runs pages; verify each renders breadcrumb trail, consistent h1, primary action button aligned right, and main content in same structural slot.

- [X] T042 [US5] Create `apps/admin/components/layout/PageContainer.tsx` — `<div className="px-6 lg:px-8 py-6 max-w-screen-2xl mx-auto w-full">{children}</div>`; exported as named export
- [X] T043 [US5] Create `apps/admin/components/layout/PageHeader.tsx` — accepts `title: string`, `description?: string`, `actions?: React.ReactNode`, `breadcrumbs?: Array<{label: string; href?: string}>`; renders: `<Breadcrumbs>` (if provided), `<h1 className="text-2xl font-semibold text-text-primary">{title}</h1>`, optional `<p className="text-sm text-text-secondary">{description}</p>`, `actions` slot aligned to right; wrapped in `<div className="mb-6 flex items-start justify-between gap-4">`
- [X] T043b [P] [US5] Audit all `<table>` elements across `apps/admin/src/modules/` — for every `<th>` in a column-header row, confirm `scope="col"` is present; add it where missing; run `grep -r "<th" apps/admin/src/modules/` to locate all instances, then inspect each file and apply the attribute in-place (FR-008)
- [X] T044 [US5] Apply `<PageContainer>` + `<PageHeader>` to `apps/admin/src/modules/admin-portal/pages/user-management.tsx` and `user-detail.tsx`, `wallet-management.tsx`, `transaction-management.tsx`
- [X] T045 [P] [US5] Apply `<PageContainer>` + `<PageHeader>` to `apps/admin/src/modules/admin-app/pages/agent-management.tsx`, `kyc-review.tsx`, `float-management.tsx`, `financial-dashboard.tsx`, `risk-dashboard.tsx`
- [X] T046 [P] [US5] Apply `<PageContainer>` + `<PageHeader>` to `apps/admin/src/modules/reconciliation/pages/batch-list.tsx`, `settlement-list.tsx`; `apps/admin/src/modules/fees/pages/fee-rules-list.tsx`, `fee-rule-create.tsx`
- [X] T047 [P] [US5] Apply `<PageContainer>` + `<PageHeader>` to `apps/admin/src/modules/audit/pages/audit-events.tsx`, `compliance-status.tsx`, `retention-policies.tsx`; `apps/admin/src/modules/reports/pages/report-list.tsx`, `report-job-detail.tsx`, `report-schedule-list.tsx`
- [X] T048 [P] [US5] Apply `<PageContainer>` + `<PageHeader>` to `apps/admin/src/modules/security/pages/dashboard.tsx`, `findings.tsx`; `apps/admin/src/modules/integrations/pages/api-client-list.tsx`, `webhook-monitor.tsx`; `apps/admin/src/modules/operations/pages/dead-letter.tsx`
- [X] T049 [P] [US5] Apply `<PageContainer>` + `<PageHeader>` to `apps/admin/src/modules/configuration/pages/configuration-list.tsx`, `configuration-approvals.tsx`, `security-config.tsx`; `apps/admin/src/modules/multi-currency/pages/currencies.tsx`, `fx-rates.tsx`; `apps/admin/src/modules/qa/pages/qa-dashboard.tsx`

**Checkpoint**: All admin pages have consistent breadcrumb → h1 → content structure. Snapshot tests should reflect the new structure.

---

## Phase 8: US6 — Customer Screens Layout Polish (Priority: P6)

**Goal**: All implemented customer screens use Consumer Green theme from `AppTheme.dark`, no hardcoded hex colors, and the home screen includes a `WalletSummaryCard` with balance visibility toggle.

**Independent Test**: Navigate Login → Home → Wallet → Activity → Account; all screens use `#001B12` background; no raw hex literals in widget code; Home shows `WalletSummaryCard` with hide/show balance toggle; PIN input shows masked characters.

- [X] T050 [US6] Add `balanceVisibleProvider` to `apps/customer/lib/modules/home/presentation/home_providers.dart` — `final balanceVisibleProvider = StateProvider<bool>((_) => true);`
- [X] T051 [US6] Create `apps/customer/lib/modules/home/presentation/widgets/wallet_summary_card.dart` — `ConsumerWidget WalletSummaryCard({required Wallet wallet})`; reads `balanceVisibleProvider`; displays formatted balance (using `OkAmountDisplay`) when visible, masked `'••••••'` when hidden; currency code badge; `lastUpdated` formatted timestamp; visibility toggle `IconButton` (44dp target) toggling `balanceVisibleProvider`; surface card using `ok.surface` fill; no hardcoded colors; balance value excluded from any analytics call
- [X] T052 [US6] Update `apps/customer/lib/modules/home/presentation/screens/home_screen.dart` to include `WalletSummaryCard(wallet: wallet)` near the top of the screen body; reads `walletProvider` for wallet data; shows `OkLoadingShimmer` while loading
- [X] T053 [US6] Audit `apps/customer/lib/modules/auth/presentation/screens/login_screen.dart`, `register_screen.dart`, `pin_creation_screen.dart`, `forgot_pin_screen.dart` — replace any hardcoded hex `Color(0xFF...)` with `AppColors.*` or `OkThemeExtension.of(context).*` references; ensure all screens use `scaffoldBackgroundColor: AppColors.background (#001B12)`
- [X] T054 [US6] Audit `apps/customer/lib/modules/wallet/presentation/screens/wallet_detail_screen.dart` and `apps/customer/lib/modules/transactions/presentation/screens/transaction_list_screen.dart`, `transaction_detail_screen.dart` — replace any hardcoded hex with token references; ensure surface cards use `AppColors.surface (#062A1A)`
- [X] T055 [US6] Verify `apps/customer/lib/modules/auth/presentation/screens/pin_creation_screen.dart` uses `OkPinPadWidget` (from design_system) rather than a custom PIN pad; if using custom, replace with `OkPinPadWidget` — ensures no PIN values are logged
- [X] T056 [US6] Run `flutter analyze apps/customer/lib/` and confirm 0 issues; run `grep -r "Color(0xFF" apps/customer/lib/` and confirm zero matches outside `design_tokens.dart` and `ok_theme_extension.dart`

**Checkpoint**: Customer Home shows WalletSummaryCard with toggle. All screens green-themed. No hardcoded colors.

---

## Phase 9: US7 — Agent Screens Layout Polish (Priority: P7)

**Goal**: All agent screens use Agent Blue theme; the dashboard shows `AgentDashboardHeader` and `FloatStatusCard` with semantic color+text labels; Cash In/Cash Out flows show `TransactionStepFlow` step indicator; result screens show server-confirmed status.

**Independent Test**: Agent Login → Dashboard (float card visible with status label) → Cash In flow (step indicator shows all 5 steps) → Result screen (server transaction ID visible) → Customer Lookup (masked PII before selection).

- [X] T057 [US7] Create `apps/agent/lib/modules/dashboard/presentation/widgets/agent_dashboard_header.dart` — `ConsumerWidget AgentDashboardHeader()`; reads `authNotifierProvider` for agent display name and masked agent ID; shows operational status dot (green=online, grey=offline based on connectivity); no PII beyond display name + masked ID; uses `ok.surface` background, `ok.primary` accent
- [X] T058 [US7] Create `apps/agent/lib/modules/dashboard/presentation/widgets/float_status_card.dart` — `ConsumerWidget FloatStatusCard()`; reads `floatProvider`; shows loading shimmer while fetching; error state with retry; loaded state: formatted balance (`OkAmountDisplay`), currency, status label + background per level: Normal (`ok.successBg`/`ok.successFg`/"Float OK"), Low (`ok.warningBg`/`ok.warningFg`/"Low Float"), Critical (`ok.errorBg`/`ok.errorFg`/"Critical"); status communicated by BOTH color AND text label (FR-017); card footer includes a "View Float" `TextButton` that calls `context.go('/float')`
- [X] T059 [US7] Create `apps/agent/lib/shared/widgets/transaction_step_flow.dart` — `StatelessWidget TransactionStepFlow({required List<String> steps, required int currentStep, required Widget child})`; renders row of step chips: completed (checkmark, `ok.successFg`), current (filled circle, `ok.primary`), upcoming (empty circle, `ok.surface`); current step label shown below indicator; wraps `child` below indicator
- [X] T060 [US7] Update `apps/agent/lib/modules/dashboard/presentation/screens/dashboard_screen.dart` — replace any placeholder float display with `FloatStatusCard()` and `AgentDashboardHeader()` at the top; ensure screen uses `AppTheme.dark` (Agent Blue) scaffold
- [X] T060b [US7] Update `apps/agent/lib/modules/dashboard/presentation/screens/dashboard_screen.dart` — add a "Quick Actions" row below `FloatStatusCard` with two `InkWell` tiles: "Top Up Float" (`context.go('/float/top-up')`) and "My Commissions" (`context.go('/commissions')`); each tile must have minimum height 48dp (≥44dp touch target); icons use `ok.primary` color; labels use `ok.textPrimary`; no hardcoded colors
- [X] T061 [US7] Update `apps/agent/lib/modules/cash_in/presentation/screens/cash_in_form_screen.dart` and `cash_in_confirmation_screen.dart` — wrap with `TransactionStepFlow(steps: ['Find Customer', 'Enter Amount', 'Review Fees', 'Confirm', 'Result'], currentStep: X)`; step indices: search=0, form=1, confirmation=3
- [X] T062 [US7] Update `apps/agent/lib/modules/cash_in/presentation/screens/cash_in_result_screen.dart` — ensure result screen shows server-confirmed transaction ID (`result.transactionId`) and server-confirmed status (`result.status`); NOT a hardcoded "Success" message; wrap with `TransactionStepFlow(currentStep: 4)`
- [X] T063 [US7] Update `apps/agent/lib/modules/cash_out/presentation/screens/` (`cash_out_form_screen.dart`, `cash_out_confirmation_screen.dart`, `cash_out_result_screen.dart`) — apply `TransactionStepFlow(steps: ['Scan Code', 'Enter Amount', 'Confirm', 'Result'], currentStep: X)` to each; result screen shows server-confirmed ID and status
- [X] T064 [US7] Audit `apps/agent/lib/modules/customer_onboarding/presentation/screens/customer_lookup_screen.dart` — verify PII masking: search results show first name + last initial + masked phone; full details only revealed after explicit row tap; all interactive elements have ≥44dp touch targets
- [X] T065 [US7] Run `flutter analyze apps/agent/lib/` and confirm 0 issues; run `grep -r "Color(0xFF" apps/agent/lib/` and confirm zero matches outside `design_tokens.dart` and `ok_theme_extension.dart`

**Checkpoint**: Agent dashboard shows float status with semantic labels. Cash In/Out flows have step indicator. Result screens show server-confirmed transaction IDs.

---

## Phase 10: US8 — Accessibility & Responsive Validation (Priority: P8)

**Goal**: All three apps pass WCAG 2.2 AA criteria for layout elements. Admin validates at 6 viewports; Flutter apps pass widget tests at 360dp/390dp/430dp.

**Independent Test**: Run axe-core on admin portal at 375px and 1440px with zero AA violations on nav/header/breadcrumbs; run `flutter test` with golden tests on shell widgets.

- [X] T066 [US8] Install and configure `@axe-core/react` (already in devDependencies or add) in `apps/admin/__tests__/a11y/`; create `layout-a11y.test.tsx` that mounts `AdminShell` with mock routes and runs axe; assert zero violations for: `nav`, `header`, `breadcrumb nav`, all `<button>` elements, all `<a>` link elements
- [X] T067 [US8] Create `apps/admin/__tests__/a11y/sidebar-keyboard.test.tsx` — renders `AdminSidebar` in collapsed state; asserts all nav items have `aria-label` matching their label; asserts tab order follows DOM order; asserts no keyboard trap outside modal context
- [X] T068 [US8] Create `apps/admin/__tests__/responsive/sidebar-responsive.test.tsx` — uses `@testing-library/react` with `window.innerWidth` mock at 375, 768, 1024, 1280, 1440, 1920; at <1024px asserts sidebar is not visible and drawer toggle is visible; at ≥1024px asserts sidebar is visible
- [X] T069 [US8] Create `apps/customer/test/widget/shell/main_shell_a11y_test.dart` — pumps `MainShell` at 360dp, 390dp, 430dp device sizes using `binding.window.physicalSizeTestValue`; asserts no overflow (`Overflow` widget not found); asserts bottom nav tap targets ≥44dp; asserts `Semantics` labels present on all 5 nav items
- [X] T070 [US8] Create `apps/agent/test/widget/shell/agent_shell_a11y_test.dart` — pumps `AgentShell` at 360dp, 390dp, 430dp; asserts no overflow; asserts all 5 nav item tap targets ≥44dp; tests at 600dp to verify tablet layout has no clipping
- [X] T071 [US8] Update `apps/customer/lib/core/shell/main_shell.dart` and `apps/agent/lib/core/shell/agent_shell.dart` to check `MediaQuery.of(context).disableAnimations` — if true, set `NavigationBar.animationDuration` to `Duration.zero`
- [X] T072 [US8] Run full admin Jest suite `npx jest --testPathPattern="__tests__"` and confirm: all a11y tests pass (zero axe violations), all responsive tests pass, all pre-existing snapshot tests still pass
- [X] T073 [US8] Run `flutter test apps/customer/test/` and `flutter test apps/agent/test/` — confirm all new shell and a11y widget tests pass; confirm existing golden tests still pass (or run `--update-goldens` if shell changes affected golden baselines)

**Checkpoint**: All accessibility and responsive tests pass. Zero WCAG AA violations for layout elements. Flutter shell tests pass at all viewport sizes.

---

## Phase 11: Polish & Cross-Cutting Concerns

**Purpose**: Route inventory verification, final static analysis, snapshot update, and integration validation.

- [X] T074 Verify `specs/036-unified-layout-ux/route-inventory.md` reflects actual state — cross-check each entry against actual `page.tsx` files in `apps/admin/app/(admin)/`; update any entries that differ; confirm all 54 admin routes are listed
- [X] T075 Run `flutter analyze packages/design-system/lib/` — confirm 0 issues in the design-system package after adding 7 new widgets
- [X] T076 Update `apps/admin/__tests__/a11y/component-snapshots.test.tsx` (from E35) — update any snapshots that changed due to E36 layout additions (run `npx jest --updateSnapshot` for changed snapshots only); verify 21 snapshots still pass
- [X] T077 Run `npx jest apps/admin/` (full suite) — all tests pass; zero regressions in existing feature tests; new E36 tests pass
- [X] T078 Run `flutter analyze apps/customer/lib/ apps/agent/lib/ packages/design-system/lib/` in a single pass — confirm combined 0 issues
- [X] T079 Execute quickstart.md SC-005 grep check — confirm zero hardcoded `Color(0xFF` in shell/widget files for both Flutter apps
- [X] T080 Execute quickstart.md SC-001 manual check — log in as admin, navigate to 5 modules (Dashboard → Users → Reports → Fee Rules → Security Dashboard) each in ≤2 clicks; document result
- [X] T081 Update `specs/036-unified-layout-ux/spec.md` Status field from "Draft" to "COMPLETE/RELEASE READY"
- [X] T082 Update `memory/project_feature036_spec.md` — record final task count, Docker-gated tasks (T073 golden `--update-goldens`), and status COMPLETE/RELEASE READY

---

## Dependencies & Execution Order

### Phase Dependencies

```
Phase 1 (Setup)
  └─ Phase 2 (Foundational) — T004–T015
        ├─ Phase 3 (US1 Admin Shell) — T016–T025
        │     └─ Phase 6 (US4 Admin Dashboard) — T037–T041
        │           └─ Phase 7 (US5 Page Templates) — T042–T049
        ├─ Phase 4 (US2 Customer Shell) — T026–T030
        │     └─ Phase 8 (US6 Customer Polish) — T050–T056
        └─ Phase 5 (US3 Agent Shell) — T031–T036
              └─ Phase 9 (US7 Agent Polish) — T057–T065
Phase 10 (US8 A11y) — T066–T073 [after Phase 3+4+5]
Phase 11 (Polish) — T074–T082 [after all US phases]
```

### Parallel Opportunities

- T005–T011 (shared widget creation): All parallel — different files
- T013–T014 (app re-export updates): Parallel — different apps
- T024 (admin shell test) and T028 (customer router) can run in parallel — different stacks
- T044–T049 (apply PageContainer to module groups): All parallel — different module directories
- T045, T046, T047, T048, T049: Fully parallel
- T053 and T054 (customer screen audit): Parallel — different screens
- T061 and T063 (cash-in and cash-out step flow): Parallel — different flows

---

## Parallel Execution Examples

```bash
# Phase 2: Create all 7 shared widgets simultaneously
Task T005: ok_error_view.dart
Task T006: ok_loading_shimmer.dart
Task T007: ok_offline_banner.dart
Task T008: ok_primary_button.dart
Task T009: ok_success_view.dart
Task T010: ok_pin_pad_widget.dart
Task T011: ok_amount_display.dart

# Phase 7: Apply PageContainer to all module groups simultaneously
Task T045: agents/kyc/float/financial/risk pages
Task T046: reconciliation/fees pages
Task T047: audit/compliance/reports pages
Task T048: security/integrations/operations pages
Task T049: configuration/multi-currency/qa pages
```

---

## Implementation Strategy

### MVP First (Admin Shell Only — Phase 1–3)

1. Complete Phase 1: Setup (3 tasks)
2. Complete Phase 2: Foundational (15 tasks)
3. Complete Phase 3: US1 Admin Shell (10 tasks)
4. **STOP and VALIDATE**: Open admin portal; verify sidebar, header, breadcrumbs, collapse, mobile drawer
5. Deliver admin shell as first shippable increment

### Incremental Delivery

1. Setup + Foundational → enables all three app streams
2. US1 (Admin Shell) + US2 (Customer Shell) + US3 (Agent Shell) in parallel → shells complete
3. US4 (Admin Dashboard) + US6 (Customer Polish) + US7 (Agent Polish) in parallel
4. US5 (Admin Page Templates)
5. US8 (Accessibility) → final validation gate
6. Phase 11 (Polish) → release ready
