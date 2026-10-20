# Feature Specification: Unified Application Layout & UX Architecture

**Feature Branch**: `036-unified-layout-ux`

**Created**: 2026-10-10

**Status**: COMPLETE/RELEASE READY

**Input**: OleKard Unified Application Layout & UX Architecture — production-ready navigation shells, dashboards, page templates, and cross-application visual consistency for Admin Portal (Next.js), Customer Mobile App (Flutter), and Agent Mobile App (Flutter).

---

## Overview

This feature modernizes the application layout and navigation architecture across the three primary OleKard applications. It does not alter business logic, API contracts, authentication/authorization rules, or database schemas. It builds on the approved design tokens from E35 (Unified Design System) and applies them consistently to every visible surface.

**Scope boundary**: Layout, navigation shells, page templates, and shared component architecture. Excludes new business features, data model changes, and API modifications.

---

## User Scenarios & Testing

### User Story 1 — Admin Portal Navigation Shell (Priority: P1)

An administrator opens the OleKard Admin Portal and immediately navigates across any implemented module using a consistent, role-aware sidebar. On desktop, a collapsible sidebar and sticky top header orient the admin at all times. On tablet or mobile, the sidebar collapses to a drawer that is accessible via the header toggle.

**Why this priority**: The Admin Portal is the primary operational surface for internal staff and forms the navigation backbone for all 30+ implemented admin modules. Without a coherent shell, every module is an isolated experience.

**Independent Test**: Open the admin portal at `/`, verify the sidebar renders with OleKard branding and Teal theme, navigate to three different sections (e.g., Dashboard, Users, Reports), verify the active route is highlighted and breadcrumbs are correct, then resize the viewport to 768px and verify the sidebar becomes a drawer.

**Acceptance Scenarios**:

1. **Given** an authenticated admin user, **When** they open the portal, **Then** the left sidebar renders with OleKard branding, the Teal primary color (#0F766E), and navigation groups corresponding to implemented modules.
2. **Given** the admin is on any module page, **When** the sidebar is visible, **Then** the current route is highlighted using `var(--ok-nav-active)` and breadcrumbs correctly reflect the navigation path.
3. **Given** the admin collapses the sidebar, **When** the collapse button is activated, **Then** the sidebar narrows to 72px, icons remain visible with tooltips, and the main content area expands to fill the space.
4. **Given** the viewport is narrower than 1024px, **When** the page loads, **Then** the sidebar is hidden and a drawer-toggle appears in the header; tapping it opens the navigation drawer over the content.
5. **Given** the admin uses keyboard navigation, **When** tabbing through the sidebar, **Then** all navigation items receive visible focus rings meeting 3:1 contrast against the sidebar background.
6. **Given** an admin with limited role permissions, **When** the sidebar renders, **Then** only navigation items corresponding to their permitted modules are displayed; no permitted routes are hidden via CSS alone.

---

### User Story 2 — Customer Mobile Navigation Shell (Priority: P2)

A customer opens the OleKard Customer App and navigates between primary destinations (Home, Wallet, Services, Activity, Account) using a persistent bottom navigation bar. The app shell provides a consistent Consumer Green theme across all screens, safe-area handling, and the correct app bar for each section.

**Why this priority**: The bottom navigation defines the customer's primary interaction model. Implementing it correctly and consistently is a prerequisite for applying layout changes to individual customer screens (US5).

**Independent Test**: Launch the customer app, verify the bottom navigation shows the correct tabs for implemented destinations, tap each tab, verify the active tab is highlighted with Consumer Green (#16A34A), and verify no overflow or clipping at safe-area boundaries.

**Acceptance Scenarios**:

1. **Given** an authenticated customer, **When** the main shell renders, **Then** a persistent bottom navigation shows tabs for: Home, Wallet, Services, Activity, Account — in that order.
2. **Given** the customer taps a bottom nav tab, **When** the navigation completes, **Then** the active tab uses the Consumer Green primary color and the page title updates.
3. **Given** the app is displayed on a device with a system navigation bar or notch, **When** any screen renders, **Then** content is inset correctly using SafeArea and no UI is obscured.
4. **Given** a tab corresponds to a feature that is not yet implemented, **When** the app renders, **Then** that tab does not appear in the bottom navigation (no non-functional destinations).
5. **Given** the customer navigates deep into a sub-screen, **When** they use the back button or gesture, **Then** navigation returns to the correct parent and the bottom navigation reflects the correct active section.

---

### User Story 3 — Agent Mobile Navigation Shell (Priority: P3)

An agent opens the OleKard Agent App and sees an operationally focused layout: Agent Blue (#2563EB) theme, a bottom navigation tailored to operational workflows (Dashboard, Customers, Transactions, Activity, Account), and a dashboard header showing float balance and operational status.

**Why this priority**: The Agent App serves field operators who need fast, clear access to cash-in/cash-out and customer lookup operations. The navigation shell must prioritize operational speed over information density.

**Independent Test**: Launch the agent app, verify the bottom navigation shows operational tabs, open the Dashboard, verify the float balance card renders with correct Agent Blue theme colors, and confirm the operational status indicator is visible.

**Acceptance Scenarios**:

1. **Given** an authenticated agent, **When** the main shell renders, **Then** a persistent bottom navigation shows: Dashboard, Customers, Transactions, Activity, Account.
2. **Given** the agent is on the Dashboard tab, **When** it renders, **Then** the float balance card is visible, float status uses semantic colors (green = normal, amber = low, red = critical), and today's transaction count and value are displayed.
3. **Given** the agent's float balance falls below the low threshold, **When** the Dashboard renders, **Then** the float card uses the warning semantic color (#F59E0B background) AND shows a non-color text indicator ("Low Float").
4. **Given** the agent taps the Customers tab, **When** the screen renders, **Then** a customer search field is visible with 44dp minimum touch target height.
5. **Given** the viewport is a tablet (≥600dp), **When** the app renders, **Then** layout adapts without clipping or overflow.

---

### User Story 4 — Admin Dashboard (Priority: P4)

An administrator opens the Admin Dashboard and sees a role-aware operational overview: KPI cards for key metrics, recent activity, and quick-action links — all populated from actual implemented backend endpoints. No fabricated data or non-functional sections appear.

**Why this priority**: The dashboard is the landing page for all admin sessions. A clear, data-driven dashboard establishes trust and operational efficiency. It depends on US1 (the admin shell) being complete.

**Independent Test**: Log in as an admin, navigate to the dashboard, verify KPI cards load from real API data with loading skeletons while fetching, verify data refreshes without full page reload, and confirm no section appears if its backing endpoint is not implemented.

**Acceptance Scenarios**:

1. **Given** an authenticated admin, **When** the dashboard loads, **Then** KPI cards display with loading skeletons during data fetch, then replace with real values from implemented API endpoints.
2. **Given** the dashboard data is loaded, **When** the admin views KPI cards, **Then** each card shows: metric name, formatted value, unit/currency, and data freshness timestamp.
3. **Given** an API call fails for one KPI section, **When** the dashboard renders, **Then** only the failed section shows an error state with a retry action; other sections render normally.
4. **Given** an admin with restricted role, **When** the dashboard renders, **Then** only KPI sections authorized for their role are shown; the dashboard does not expose unauthorized data.
5. **Given** the admin clicks Refresh, **When** the action completes, **Then** data is refetched and timestamps update without a full page reload.

---

### User Story 5 — Admin Page Template Consistency (Priority: P5)

Every implemented admin module page follows a consistent layout pattern: breadcrumb → page heading → action buttons → filter/search bar → data table or form → pagination. Reusable layout components replace one-off page implementations.

**Why this priority**: With 30+ admin modules implemented, layout inconsistency creates cognitive overhead for administrators. This story standardizes the chrome so module-level code can focus on business logic.

**Independent Test**: Navigate to Users, Reports, QA Runs, and Approvals pages; verify each renders a breadcrumb, a consistent page heading style, a primary action button, and a data table or form with the same structural layout.

**Acceptance Scenarios**:

1. **Given** any implemented admin module page, **When** it renders, **Then** the page structure matches: breadcrumb > page heading > description/context > primary action > content area.
2. **Given** an admin module with a data table, **When** the page renders, **Then** the table has: accessible column headers (scope="col"), consistent row spacing, loading skeleton, empty state with message, and pagination controls.
3. **Given** a table has more records than fit one page, **When** the admin interacts with pagination, **Then** page navigation works correctly and the URL reflects the current page state.
4. **Given** a table row has a destructive action (delete, revoke), **When** the admin activates it, **Then** a confirmation dialog appears before the action executes.
5. **Given** an admin uses keyboard navigation on a page, **When** tabbing through interactive elements, **Then** focus order follows a logical reading sequence and all actions are reachable without a mouse.

---

### User Story 6 — Customer Screens Layout Polish (Priority: P6)

All implemented customer screens (home, wallet, transaction history, services, authentication flow, device management, notifications) render with the Consumer Green theme using the correct layout scaffold — CustomerPageScaffold — rather than ad-hoc per-screen layouts. No screen contains hardcoded color values.

**Why this priority**: Individual screen polish depends on the Customer Shell (US2) being established. This story ensures all customer-facing surfaces are consistent and token-driven.

**Independent Test**: Navigate through Login → Home → Wallet → Transaction History → Settings and verify: all screens use the Consumer Green surface/background, no raw hex colors in widget code, correct app bar titles, and back navigation works throughout.

**Acceptance Scenarios**:

1. **Given** any implemented customer screen, **When** it renders, **Then** the scaffold uses `AppTheme.dark` (Consumer Green) and background color is `#001B12`.
2. **Given** the Home screen, **When** it renders, **Then** a wallet summary card shows balance, currency, and a balance-visibility toggle (hide/reveal balance).
3. **Given** a customer's recent transactions, **When** they are displayed on the Home or Activity screen, **Then** each item shows: icon, description, amount with sign (+/-), currency, date, and semantic status badge.
4. **Given** the customer is on the Login screen, **When** they enter their PIN, **Then** the PIN input uses secure input handling (no plaintext display, no logging) and provides haptic/visual feedback per digit.
5. **Given** any screen has content that requires scrolling, **When** the keyboard opens, **Then** the content scrolls to keep the focused field visible and the scaffold does not overflow.

---

### User Story 7 — Agent Screens Layout Polish (Priority: P7)

All implemented agent screens (dashboard, cash-in, cash-out, customer lookup, customer onboarding, transaction history, profile) render consistently using the Agent Blue theme and the AgentPageScaffold. Transaction workflows present clear multi-step flows with progress indication.

**Why this priority**: Depends on US3 (agent shell). Operational screens must be fast, unambiguous, and error-resistant.

**Independent Test**: Navigate through Agent Login → Dashboard → Cash In workflow → Customer Lookup → Transaction History; verify Agent Blue theme, correct progress steps in Cash In, explicit transaction status on the result screen, and no hardcoded colors.

**Acceptance Scenarios**:

1. **Given** the Cash In workflow, **When** the agent progresses through it, **Then** a step indicator shows: Customer Selection → Amount Entry → Fee Display → Review → Confirmation, with the current step clearly highlighted.
2. **Given** the agent submits a Cash In transaction, **When** the server confirms it, **Then** the result screen shows the server-confirmed transaction ID, amount, and status — not a success inferred from the button tap.
3. **Given** a transaction fails with a network or server error, **When** the agent views the result, **Then** the failure screen shows a clear error description, the transaction status (failed/pending), and a safe retry path that prevents double-submission.
4. **Given** the agent searches for a customer by phone number, **When** results load, **Then** each result shows masked PII (first name + last initial, masked phone), and full details are only revealed on explicit selection.
5. **Given** any agent screen, **When** it renders, **Then** all primary action buttons have a minimum touch target of 44×44dp.

---

### User Story 8 — Accessibility & Responsive Validation (Priority: P8)

All three applications pass WCAG 2.2 AA criteria for the layouts implemented in US1–US7. Admin validates at 375px, 768px, 1024px, 1280px, 1440px. Flutter apps validate on small (360dp), standard (390dp), and large (430dp) form factors.

**Why this priority**: Accessibility and responsiveness are non-functional requirements that affect every user. They cannot be retroactively fixed after deployment.

**Independent Test**: Run axe-core against the admin portal at each breakpoint; run `flutter test` widget tests for each golden test capturing the Consumer and Agent layouts; verify no WCAG AA violations on all 15 admin UI components.

**Acceptance Scenarios**:

1. **Given** the admin portal at any viewport width, **When** axe-core or equivalent runs, **Then** zero WCAG AA violations are reported for all layout-related elements (navigation, headers, buttons, form controls, tables).
2. **Given** the admin sidebar is collapsed, **When** a keyboard user tabs through it, **Then** collapsed nav items display tooltips via `aria-label` and focus order remains logical.
3. **Given** the Flutter customer or agent app, **When** font scale is set to 1.5×, **Then** no text is clipped, no layout overflows, and all touch targets remain ≥44dp.
4. **Given** any status indicator (float balance, transaction status, KYC status), **When** rendered, **Then** status is communicated by both color AND a visible text label or icon.
5. **Given** the admin portal on a 375px viewport, **When** a data table renders, **Then** the table scrolls horizontally within its container rather than breaking the page layout, and the page header remains accessible.

---

### Edge Cases

- What happens when an admin user's session expires mid-navigation? → Must redirect to login with return URL; no blank or broken state.
- What happens when the agent app loses connectivity while a transaction is in-flight? → Must show a clear pending/unknown state; must not claim success.
- What happens when a customer's wallet balance is hidden and they receive a push notification? → Notification must not reveal the balance.
- What happens when an admin module is implemented in the backend but the frontend page is not yet built? → The nav item must not appear or must link to a graceful "coming soon" rather than a 404.
- What happens when a table has 0 rows? → Empty state with a descriptive message and a relevant action (e.g., "No users found — Invite User").
- What happens when a bottom nav tab is tapped while a modal or bottom sheet is open? → The modal/sheet must close safely before navigating.
- What happens when the admin sidebar has more nav items than fit the viewport height? → The sidebar must be scrollable without cutting off the user account section at the bottom.
- What happens when a Flutter widget tree is rebuilt due to theme change? → No navigation state is lost, no screens flash.

---

## Requirements

### Functional Requirements

#### FR-001 — Admin Sidebar Shell
The admin portal MUST render a left-side navigation sidebar with: OleKard branding, Teal application identity (#0F766E), collapsible behavior (256px expanded / 72px collapsed), navigation groups matching implemented modules, active route highlighting, and a user account section at the bottom.

#### FR-002 — Admin Sidebar Collapse State Persistence
The sidebar collapse/expand state MUST persist across page navigations within the same browser session using `sessionStorage` (tab-scoped). The preference resets when the tab is closed. Cross-tab synchronization is not required.

#### FR-003 — Admin Responsive Navigation Drawer
At viewport widths below 1024px, the sidebar MUST be replaced by a hidden drawer accessible via a header toggle button. The drawer MUST close when a nav item is selected or when the user taps/clicks outside it.

#### FR-004 — Admin Header
The admin portal top header MUST include: sidebar toggle button, breadcrumb navigation, notification access (where implemented), and user account menu with logout. It MUST NOT display controls that have no implemented function.

#### FR-005 — Admin Breadcrumbs
Every admin page MUST render breadcrumbs reflecting the navigation hierarchy from root to current page. Breadcrumb links MUST be navigable except for the current page (aria-current="page").

#### FR-006 — Admin Navigation Groups
Navigation items MUST be organized into logical groups (Overview, Identity & Access, Financial Operations, Monitoring, Configuration) with group headers. Only routes with implemented pages are included.

#### FR-007 — Admin Page Template
Every admin module page MUST follow the standard layout: breadcrumb → page heading (h1) → optional description → primary action button → optional filter/search bar → main content → loading/empty/error state → pagination where applicable.

#### FR-008 — Admin Data Tables
Admin tables MUST include: accessible column headers with `scope="col"`, consistent row height, loading skeleton, empty-state component, pagination controls, and row-level actions with destructive-action confirmation.

#### FR-009 — Admin Dashboard KPIs
The admin dashboard MUST render KPI cards for the following core operational metrics: Total Users, Active Wallets, Transactions Today, KYC Pending, Active Agents, Open Disputes (~6 cards). All cards MUST load in parallel from their respective implemented API endpoints. Each card MUST show a loading skeleton while fetching. Failed cards MUST show a per-card error state with retry; other cards continue to render normally. No card may show fabricated data.

#### FR-010 — Customer Bottom Navigation
The customer app MUST implement a persistent BottomNavigationBar with tabs matching implemented destinations. The active tab MUST use Consumer Green (#16A34A). Tabs corresponding to unimplemented features MUST be absent.

#### FR-011 — Customer App Shell
The customer app MUST use a unified `AppShell` widget wrapping all authenticated screens, providing: consistent app bar, bottom navigation, safe-area insets, and theme application. The scaffold background MUST be `#001B12`.

#### FR-012 — Customer Wallet Summary Card
The customer home screen MUST include a wallet summary card showing: balance, currency code, last-updated timestamp, and a balance visibility toggle. The visibility preference is session-only (in-memory Riverpod state) and resets to visible on every app launch; it MUST NOT be persisted to local storage. The balance MUST NOT be logged or included in analytics payloads.

#### FR-013 — Customer Quick Actions Grid
The customer home screen MUST render a quick-actions grid containing only implemented actions (e.g., Send, Receive, Add Money). Each action cell MUST have a minimum 44×44dp touch target.

#### FR-014 — Customer Secure PIN Input
Any screen requiring PIN entry MUST use a secure PIN input widget: no plaintext display, no auto-fill for security-sensitive contexts, no PIN value in logs, keyboard obscured or custom numeric pad.

#### FR-015 — Customer Authentication Screen Layout
Authentication screens (Login, OTP, Device Verification, PIN Setup) MUST use consistent layout: centered single-column form, OleKard logo at top, page-specific heading, supporting text, input fields, and primary action button at bottom. E01 security contracts MUST be preserved.

#### FR-016 — Agent Bottom Navigation
The agent app MUST implement a persistent BottomNavigationBar with tabs: Dashboard, Customers, Transactions, Activity, Account. The active tab MUST use Agent Blue (#2563EB).

#### FR-017 — Agent Dashboard Header
The agent dashboard MUST display: masked agent identifier, operational status, and float balance card. Float status MUST use both color AND text label (Normal / Low Float / Critical).

#### FR-018 — Agent Transaction Workflow
Multi-step agent transaction flows (Cash In, Cash Out) MUST display a step progress indicator showing all steps and the current step. The result screen MUST show server-confirmed transaction ID and status, not a client-inferred result.

#### FR-019 — Agent Customer Search
The customer search widget in the agent app MUST: accept phone number input, show masked results (name + last initial, masked phone), require explicit selection before showing full details, and have ≥44dp touch targets.

#### FR-020 — Shared Component Reuse
Flutter widgets that serve identical structural purposes in both Customer and Agent apps (error states, loading states, empty states, PIN input, confirmation dialogs) MUST be implemented once in a shared location and themed per application using `OkThemeExtension`.

#### FR-021 — No Hardcoded Colors in Layout Code
No layout component, page scaffold, navigation shell, or dashboard widget MUST contain hardcoded hex color literals. All colors MUST reference `OkTokens` constants, `OkThemeExtension` fields, `Theme.of(context).colorScheme.*`, or CSS custom properties.

#### FR-022 — WCAG 2.2 AA Compliance for Layout Elements
All navigation, header, sidebar, breadcrumb, and page-template elements MUST pass WCAG 2.2 AA: 4.5:1 for text, 3:1 for UI component boundaries, visible focus rings, semantic landmark roles, and logical tab order.

#### FR-023 — Touch Target Sizing
All interactive controls in Flutter apps MUST have a minimum touch target of 44×44dp. Admin portal interactive controls MUST meet the 44×44px minimum.

#### FR-024 — Keyboard Navigation — Admin
All admin shell components (sidebar, header, breadcrumbs, modals) MUST be fully keyboard-navigable. Focus must not be trapped except in modals (where focus MUST be trapped until dismissed).

#### FR-025 — Reduced Motion
Admin portal CSS MUST include `@media (prefers-reduced-motion: reduce)` disabling or minimizing transitions. Flutter animations MUST respect `MediaQuery.of(context).disableAnimations`.

#### FR-026 — Role-Based Navigation Visibility
Admin navigation items MUST reflect the authenticated user's role and permissions. Items must be removed from the DOM, not merely hidden with CSS. The backend remains the authoritative authorization check.

#### FR-027 — Route Inventory Maintained
A route inventory document MUST be maintained listing: application, route path, screen name, auth required, role/permission required, layout parent, nav entry, implementation status.

#### FR-028 — Non-Functional Destinations Excluded
No navigation entry (bottom nav tab, sidebar item, quick-action button) MUST link to a non-functional or unimplemented screen. Future features MUST be absent from navigation until their screens are implemented.

### Key Entities

- **AdminShell**: Top-level layout wrapper for admin portal — sidebar + header + content area
- **AdminSidebar**: Collapsible left navigation with grouped nav items, branding, and user section
- **AdminHeader**: Fixed top bar with toggle, breadcrumbs, notifications, and user menu
- **PageContainer**: Content width-constrained wrapper with standard padding
- **PageHeader**: Standardized h1 + description + actions pattern used on every admin module page
- **CustomerAppShell**: Flutter widget providing bottom nav + safe area + theme for all authenticated customer screens
- **AgentAppShell**: Flutter widget providing bottom nav + agent dashboard header for all authenticated agent screens
- **WalletSummaryCard**: Displays wallet balance with visibility toggle; never logs balance value
- **FloatStatusCard**: Agent dashboard widget showing float balance + semantic status
- **TransactionStepFlow**: Multi-step wrapper widget for agent transactions with step indicator
- **CustomerSearchWidget**: Agent-side customer lookup widget with masked PII display

---

## Success Criteria

### Measurable Outcomes

- **SC-001**: An admin user can navigate to any implemented module in ≤2 clicks from the dashboard, regardless of viewport width (375px through 1920px).
- **SC-002**: All 15 admin UI component snapshot tests pass after layout implementation; zero regressions in existing admin functional tests.
- **SC-003**: `flutter analyze` returns 0 issues in both Customer and Agent apps after layout implementation.
- **SC-004**: Zero WCAG 2.2 AA violations reported by axe-core on the admin portal layout elements at all six representative viewports.
- **SC-005**: Customer and Agent apps have zero hardcoded hex color literals outside of `design_tokens.dart` and `OkTokens`.
- **SC-006**: Admin sidebar collapse/expand animation completes in ≤200ms with `prefers-reduced-motion: reduce` eliminating the animation.
- **SC-007**: All 5 customer bottom navigation tabs and 5 agent bottom navigation tabs render correctly and navigate to their respective screens without errors.
- **SC-008**: Flutter widget tests for CustomerAppShell and AgentAppShell pass on 360dp, 390dp, and 430dp simulated viewports.
- **SC-009**: The admin route inventory lists 100% of implemented admin routes with correct auth and permission metadata.
- **SC-010**: Transaction result screens (Cash In / Cash Out) show server-confirmed status in 100% of success and failure scenarios — never client-inferred status.

---

## Assumptions

- E35 (Unified Design System) is COMPLETE/RELEASE READY. `OkTokens`, `OkThemeExtension.consumer()`, `OkThemeExtension.agent()`, and `tokens.css` with `[data-theme="admin"]` are all in place and correct. This feature builds on those tokens without modifying them.
- E01 through E34 backend modules are implemented as recorded in their respective feature status memories. Frontend screens for these modules exist but may be inconsistent in layout.
- The admin portal already has 15 shared UI components (Badge, Button, Card, etc.) verified by E35. This feature extends them with layout shell components.
- Riverpod is the state management framework for both Flutter apps; no new state management will be introduced.
- go_router is the routing solution for both Flutter apps; no router replacement.
- The admin portal uses Next.js App Router (not Pages Router). Layout components use RSC-compatible patterns where appropriate.
- No new npm packages beyond what already exists in the admin portal will be introduced for this layout work.
- The Customer App's authentication flow (E01) is fully functional; this feature only adjusts its visual presentation.
- Tablet layout optimization (≥600dp) for Flutter apps is in scope only for layout correctness (no overflow, no clipping). Separate tablet-specific designs are out of scope.
- The "Services" tab in the customer app will be present but may show an empty state if no services are enabled in the current environment.

---

## Clarifications

### Session 2026-10-10

- Q: Should the admin sidebar navigation groups be hardcoded or driven from the backend role/permission system? → A: Navigation groups are frontend-defined based on implemented routes, filtered client-side by the authenticated user's resolved permissions. The backend remains the authoritative authorization check for all API calls. Navigation visibility is a UX affordance, not a security boundary.
- Q: Should the Customer App's "Services" bottom tab be included even if no services are live in the current environment? → A: Yes — include the Services tab but render a graceful empty state ("Services coming soon") so the navigation structure is complete and stable across environments.
- Q: Should the route inventory be a markdown document in `specs/036-unified-layout-ux/` or maintained in code? → A: A markdown document in `specs/036-unified-layout-ux/route-inventory.md` is the specification artifact. Code-level route registration remains in the respective app routers.
- Q: Should the admin sidebar collapse/expand preference persist tab-scoped (sessionStorage) or cross-tab (localStorage)? → A: sessionStorage — tab-scoped only; resets when the tab is closed. Cross-tab synchronization is not required.
- Q: Should the customer wallet balance visibility toggle persist across app sessions or reset each launch? → A: Session-only — in-memory Riverpod state, resets to visible on every app launch. No local storage required.
- Q: Which metric categories should appear as KPI cards on the admin dashboard? → A: Core operational metrics — Total Users, Active Wallets, Transactions Today, KYC Pending, Active Agents, Open Disputes (~6 cards). All load in parallel with per-card error states and retry.
