# Data Model: Unified Application Layout & UX Architecture

**Feature**: E36 | **Date**: 2026-10-10

This feature introduces no database entities and no API schema changes. The "data model" for E36 is the component/widget model and the UI state model.

---

## Admin Portal — Component Model

### `AdminShell`
**Type**: Next.js Server/Client Component  
**File**: `apps/admin/components/layout/AdminShell.tsx`  
**Props**:
```ts
interface AdminShellProps {
  children: React.ReactNode;
}
```
**Renders**: `AdminSidebar` (left) + `AdminHeader` (top) + `main` content area (right/below).  
**State**: None — reads sidebar collapse from `sessionStorage` via `AdminSidebar`.

---

### `AdminSidebar`
**Type**: Client Component (`'use client'`)  
**File**: `apps/admin/components/layout/AdminSidebar.tsx`  
**Props**:
```ts
interface AdminSidebarProps {
  currentPath: string;
}
```
**State**:
- `collapsed: boolean` — read from `sessionStorage('ok-sidebar-collapsed')` on mount, written on toggle
**Reads**: `NAV_SECTIONS` from `src/lib/navigation/nav-items.ts`  
**Renders**: OleKard logo + nav sections + user account section at bottom.  
**Width**: 256px expanded / 72px collapsed.

---

### `AdminHeader`
**Type**: Client Component  
**File**: `apps/admin/components/layout/AdminHeader.tsx`  
**Props**:
```ts
interface AdminHeaderProps {
  onSidebarToggle: () => void;
  sidebarCollapsed: boolean;
}
```
**Renders**: Sidebar toggle button + `Breadcrumbs` + notification link (if implemented) + user menu with logout.  
**Height**: 64px.

---

### `Breadcrumbs`
**Type**: Client Component  
**File**: `apps/admin/components/layout/Breadcrumbs.tsx`  
**Props**:
```ts
interface BreadcrumbsProps {
  items: Array<{ label: string; href?: string }>;
}
```
**Behavior**: Last item has `aria-current="page"` and is not a link. All others are navigable links.

---

### `PageContainer`
**Type**: Server Component  
**File**: `apps/admin/components/layout/PageContainer.tsx`  
**Props**:
```ts
interface PageContainerProps {
  children: React.ReactNode;
  className?: string;
}
```
**Behavior**: Constrains content width, applies standard horizontal padding (px-6 lg:px-8).

---

### `PageHeader`
**Type**: Server Component  
**File**: `apps/admin/components/layout/PageHeader.tsx`  
**Props**:
```ts
interface PageHeaderProps {
  title: string;
  description?: string;
  actions?: React.ReactNode;
  breadcrumbs?: Array<{ label: string; href?: string }>;
}
```
**Renders**: `Breadcrumbs` (if provided) + `h1` title + optional description + optional action slot.

---

### `KpiCard`
**Type**: Client Component  
**File**: `apps/admin/components/dashboard/KpiCard.tsx`  
**Props**:
```ts
interface KpiCardProps {
  label: string;
  value: number | string | null;
  unit?: string;
  refreshedAt?: Date | null;
  loading?: boolean;
  error?: boolean;
  onRetry?: () => void;
}
```
**States**: loading (skeleton), error (message + retry button), loaded (value).

---

## Customer App — Widget Model

### `MainShell` (updated)
**Type**: Flutter `StatelessWidget`  
**File**: `apps/customer/lib/core/shell/main_shell.dart`  
**Tabs** (5): Home, Wallet, Services, Activity, Account  
**Tab → Route mapping**:
| Index | Label | Icon | Route |
|-------|-------|------|-------|
| 0 | Home | home | `/home` |
| 1 | Wallet | account_balance_wallet | `/wallet` |
| 2 | Services | grid_view | `/services` |
| 3 | Activity | receipt_long | `/activity` |
| 4 | Account | person | `/profile` |

**Theme**: `NavigationBarTheme` with `indicatorColor: AppColors.primary` (Consumer Green).  
**SafeArea**: Wraps scaffold body with `SafeArea(top: false)` to protect bottom nav from system bar.

---

### `WalletSummaryCard`
**Type**: Flutter `ConsumerWidget`  
**File**: `apps/customer/lib/modules/home/presentation/widgets/wallet_summary_card.dart`  
**Props**:
```dart
final Wallet wallet;
```
**Reads**: `walletProvider`, `balanceVisibleProvider` (StateProvider<bool>)  
**Renders**: Balance (masked/revealed), currency code, last-updated, visibility toggle button.  
**Invariant**: Balance value is NEVER included in any analytics call.

---

### `balanceVisibleProvider`
**Type**: `StateProvider<bool>`  
**File**: `apps/customer/lib/modules/home/presentation/home_providers.dart`  
**Default**: `true` (balance visible)  
**Persistence**: None — resets on app restart.

---

## Agent App — Widget Model

### `AgentShell`
**Type**: Flutter `StatelessWidget`  
**File**: `apps/agent/lib/core/shell/agent_shell.dart`  
**Tabs** (5): Dashboard, Customers, Transactions, Activity, Account  
**Tab → Route mapping**:
| Index | Label | Icon | Route |
|-------|-------|------|-------|
| 0 | Dashboard | dashboard | `/dashboard` |
| 1 | Customers | people | `/customers` |
| 2 | Transactions | receipt_long | `/transactions` |
| 3 | Activity | notifications | `/notifications` |
| 4 | Account | person | `/profile` |

**Theme**: `NavigationBarTheme` with `indicatorColor: AppColors.primary` (Agent Blue).

---

### `AgentDashboardHeader`
**Type**: Flutter `ConsumerWidget`  
**File**: `apps/agent/lib/modules/dashboard/presentation/widgets/agent_dashboard_header.dart`  
**Reads**: `authNotifierProvider` (agent identity, masked ID)  
**Renders**: Agent display name + masked agent ID + operational status indicator (online/offline).

---

### `FloatStatusCard`
**Type**: Flutter `ConsumerWidget`  
**File**: `apps/agent/lib/modules/dashboard/presentation/widgets/float_status_card.dart`  
**Reads**: `floatProvider` (FloatBalance entity)  
**Renders**: Float balance (formatted), currency, status badge.  
**Status levels**:
| Level | Color token | Text label |
|-------|-------------|------------|
| Normal | `ok.successBg` / `ok.successFg` | "Float OK" |
| Low | `ok.warningBg` / `ok.warningFg` | "Low Float" |
| Critical | `ok.errorBg` / `ok.errorFg` | "Critical" |

**Rule**: Status MUST be communicated by BOTH color AND text label (FR-017, FR-022).

---

### `TransactionStepFlow`
**Type**: Flutter `StatelessWidget`  
**File**: `apps/agent/lib/shared/widgets/transaction_step_flow.dart`  
**Props**:
```dart
final List<String> steps;
final int currentStep; // 0-indexed
final Widget child;
```
**Renders**: Step indicator row (steps as chips/circles) + current step label + child content.  
**Used by**: Cash In flow (5 steps), Cash Out flow (4 steps).

---

## Shared Design System — Widget Model

**Package**: `packages/design-system`  
**Exports** (new additions to `lib/design_system.dart`):

| Widget | File | Purpose |
|--------|------|---------|
| `OkErrorView` | `lib/src/widgets/ok_error_view.dart` | Error state with retry |
| `OkLoadingShimmer` | `lib/src/widgets/ok_loading_shimmer.dart` | Shimmer loading skeleton |
| `OkOfflineBanner` | `lib/src/widgets/ok_offline_banner.dart` | Connectivity warning banner |
| `OkPrimaryButton` | `lib/src/widgets/ok_primary_button.dart` | Themed elevated button |
| `OkSuccessView` | `lib/src/widgets/ok_success_view.dart` | Success confirmation display |
| `OkPinPadWidget` | `lib/src/widgets/ok_pin_pad_widget.dart` | Secure PIN entry pad |
| `OkAmountDisplay` | `lib/src/widgets/ok_amount_display.dart` | Formatted currency amount |

Each widget reads `OkThemeExtension.of(context)` for brand colors — automatically themes to Consumer Green or Agent Blue.

---

## UI State Model

### Admin Sidebar State
```
sessionStorage['ok-sidebar-collapsed'] = 'true' | 'false'
```
- Scope: Tab-scoped. Resets on tab close.
- Default: `'false'` (expanded).

### Customer Balance Visibility State
```
Riverpod: StateProvider<bool> balanceVisibleProvider
```
- Scope: In-memory, process lifetime.
- Default: `true` (visible).

### Admin Dashboard KPI Fetch State
```
Per-card React state: { loading: boolean, error: Error | null, data: T | null }
```
- All 6 cards fetch in parallel on dashboard mount.
- Each card independently retries on error.
