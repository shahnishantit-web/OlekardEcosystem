# Data Model & Component Architecture: Admin Panel UI/UX Layout Convergence

**Date**: 2026-10-12

This feature involves no new database models. "Data model" in this context is the **component architecture** — the props contracts, state shapes, and rendering relationships for new and modified components.

---

## 1. Modified Shared Components

### 1.1 `Card.tsx`

```
Card
├── Props: as?, semanticLabel?, className?, children, ...HTMLAttributes
├── Default surface: bg-ok-surface border border-neutral-700 rounded-lg shadow-sm p-4
└── Change: replace bg-bg-primary + border-neutral-200 → bg-ok-surface + border-neutral-700
```

**Token mapping**:

| Before | After | CSS Variable |
|--------|-------|-------------|
| `bg-bg-primary` | `bg-ok-surface` | `--ok-surface` |
| `border-neutral-200` | `border-neutral-700` | `--color-neutral-700` |

---

### 1.2 `Button.tsx`

```
Button
├── Props: variant?, loading?, disabled?, children, className?, ...ButtonHTMLAttributes
├── Variants: primary / secondary / danger / ghost (unchanged)
└── Change: minHeight inline style 44px → Tailwind responsive min-h-[35px] sm:min-h-[44px]
```

**Height behaviour**:
- `<640px` (mobile): `min-h-[44px]` — WCAG 2.5.5 touch target
- `≥640px` (desktop/tablet): `min-h-[35px]` — matches wireframe control height

---

### 1.3 `PageContainer.tsx`

```
PageContainer
├── Props: children
├── Before: px-6 lg:px-8 py-6 max-w-screen-2xl mx-auto w-full
└── After:  px-8 lg:px-9 py-8 max-w-[1500px] mx-auto w-full
```

**Gutter mapping**:
- `px-8` = 32px (Tailwind default 4px unit × 8) — matches `--content-padding-x: 32px`
- `lg:px-9` = 36px — matches wireframe 36px gutter at lg+
- `max-w-[1500px]` — exact wireframe spec

---

### 1.4 `AdminSidebar.tsx`

CSS variables updated in `globals.css`:

```css
--sidebar-expanded:  252px;   /* was 256px */
--sidebar-collapsed: 70px;    /* was 68px  */
--header-height:     66px;    /* was 60px  */
```

New section at bottom of sidebar (side-bottom area):

```
AdminSidebar
├── brand bar (height: --header-height)
├── nav sections (flex-1, overflow-y-auto)
└── [NEW] user account area
    ├── avatar circle (initials)
    ├── username (truncated)
    └── role label ("Workspace Admin")
```

User data source: `useAuth()` hook already provides `user.name`, `user.email`. Role display defaults to "Workspace Admin" (no role claim in current auth context shape).

---

### 1.5 `MetricChart.tsx`

New prop: `period: '7' | '30' | '90'`

```typescript
interface MetricChartProps {
  period?: '7' | '30' | '90';  // NEW — defaults to '7'
}
```

**State shape** (existing + period):
```typescript
{
  transactions: AdminTransaction[];
  loading: boolean;
  error: boolean;
  period: '7' | '30' | '90';
}
```

**Query logic**:
- `period === '7'` → filter last 7 days: `created_after = now - 7d`, bucket by day
- `period === '30'` → filter last 30 days: bucket by day
- `period === '90'` → filter last 90 days: bucket by week
- If API doesn't support date filtering → show "Period data unavailable" notice; chart shows all available data

**Bucket strategy change**:
- Was: 24 hourly buckets (today only)
- New: `N` daily/weekly buckets based on period

---

### 1.6 `ActivityFeed.tsx` (repurposed)

```typescript
// Queries audit events instead of transactions
interface AuditEventItem {
  id: string;
  event_type: string;
  actor_type: string;
  action: string;
  outcome: 'success' | 'failure' | 'denied';
  occurred_at: string;
}
```

**API**: `GET /v1/audit/events?per_page=5&sort=occurred_at_desc`
- Falls back to "System events unavailable" state if API returns non-200.
- Displays icon per event_type, outcome badge, timeAgo label.

---

## 2. New Components

### 2.1 `RecentTransactionsTable.tsx`

Wraps `DataTable` with transaction-specific filtering.

```typescript
interface RecentTransactionsTableProps {
  // no props — self-contained, fetches own data
}

// Internal state:
{
  transactions: AdminTransaction[];
  loading: boolean;
  error: boolean;
  statusTab: 'All' | 'completed' | 'pending' | 'failed';
  searchQuery: string;
  page: number;
}
```

**Layout**:
```
RecentTransactionsTable
├── card header: "Recent transactions" + "View all →" link
├── tab bar: [All] [Completed] [Pending] [Failed]
├── search input (FilterBar inline)
└── DataTable
    ├── columns: Transaction, Amount, Status, Date
    ├── loading: skeleton rows
    ├── empty: "No transactions match your filters"
    └── pagination: page controls
```

**API**: `searchAdminTransactions(client, { status?, q?, per_page: 8, page })`

---

### 2.2 `QuickActionsPanel.tsx`

```typescript
interface QuickAction {
  label: string;
  href: string;
  icon: LucideIcon;
  description: string;
}

// No props — static content, links to real routes
```

**Layout**:
```
QuickActionsPanel
├── card header: "Quick actions"
├── subtitle: "Frequently used operations"
└── list of QuickAction links
    ├── icon + label
    └── chevron/arrow indicator
```

---

## 3. Dashboard Page Composition

### 3.1 `AdminDashboardPage` (canonical, at `/dashboard`)

```
AdminDashboardPage
├── page eyebrow: "OPERATIONS / DASHBOARD"
├── h1: "Business overview"
├── subtitle text
├── page actions: [period selector] [Export report — links to /reports]
│
├── KPI grid (grid-cols-2 sm:grid-cols-4)
│   ├── KpiCard: Total Users
│   ├── KpiCard: Active Wallets
│   ├── KpiCard: Transactions Today
│   └── KpiCard: KYC Pending
│
├── chart row (grid: 1.7fr / 0.85fr, stacks <950px)
│   ├── MetricChart (period prop from selector state)
│   └── PendingTasksCard (existing, repurposed as "Operational Activity")
│       → rename heading to "Pending Actions" or keep as activity-adjacent
│       → retains KYC + disputes count with real data
│
└── bottom row (grid: 1.7fr / 0.85fr, stacks <950px)
    ├── RecentTransactionsTable [NEW]
    └── QuickActionsPanel [NEW]
```

**Note on ActivityFeed**: The repurposed `ActivityFeed` (now querying audit events) replaces `PendingTasksCard` in the chart row's right panel. `PendingTasksCard` is removed from the dashboard OR merged into `QuickActionsPanel`. This simplifies the composition.

**Revised composition**:
```
chart row right:  ActivityFeed (operational system events)
bottom row right: QuickActionsPanel (with pending counts inline in action items)
```

---

## 4. Navigation Data Shape

### 4.1 Updated `NAV_SECTIONS` type (no change to type definition)

```typescript
// Existing types remain unchanged:
NavSection { title: string; items: NavItem[]; requiredPermission? }
NavItem    { href: string; label: string; icon?: LucideIcon; requiredPermission? }
```

**Section count**: 3 (down from 8)
**Item count**: ~22 (up from 14)

See `research.md` R-04 for full item list.

---

## 5. CSS Token Changes

### 5.1 `globals.css` delta

**Remove** (dead legacy classes):
```css
/* DELETE these classes entirely */
.dashboard-shell { ... }
.dashboard-header { ... }
.dashboard-kicker { ... }
.summary-grid { ... }
.summary-card, .dashboard-panel { ... }
.summary-card p, .summary-card span, .empty-state { ... }
.summary-card p { ... }
.summary-card strong { ... }
.summary-card span { ... }
.dashboard-panel h2 { ... }
.empty-state { ... }
@media (max-width: 700px) { .dashboard-shell, .dashboard-header, .summary-grid }
```

**Update** (shell dimension tokens):
```css
:root {
  --sidebar-expanded:  252px;   /* was 256px */
  --sidebar-collapsed: 70px;    /* was 68px  */
  --header-height:     66px;    /* was 60px  */
  --content-padding-x: 32px;   /* was 24px  */
  --content-padding-y: 32px;   /* was 24px  */
}
```

---

## 6. Undeclared Class Remediation Map

| File | Undeclared Class | Replacement |
|------|-----------------|-------------|
| `user-management.tsx` | `border-border-subtle` | `border-neutral-700/50` |
| `user-management.tsx` | `bg-surface-secondary` | `bg-ok-surface` |
| `user-management.tsx` | `bg-surface-hover` | (remove — row hover handled by `DataTable`) |
| Any other files | grep sweep required | Replace with declared equivalents |

---

## 7. Page Migration Pattern

All ~50 pages that bypass `PageContainer` follow this mechanical pattern:

**Before (typical)**:
```tsx
export function SomePage() {
  return (
    <main aria-label="..." className="px-6 py-8 max-w-6xl mx-auto">
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-xl font-semibold">Title</h1>
        <Button>Action</Button>
      </div>
      {/* content */}
    </main>
  );
}
```

**After**:
```tsx
export function SomePage() {
  return (
    <PageContainer>
      <PageHeader title="Title" actions={<Button>Action</Button>} />
      {/* content */}
    </PageContainer>
  );
}
```

Pages already using `PageContainer` and `PageHeader` correctly (e.g., `UserManagementPage`, `FinancialDashboardPage`, `AuditEventsPage`) need only the token class fixes from section 6.
