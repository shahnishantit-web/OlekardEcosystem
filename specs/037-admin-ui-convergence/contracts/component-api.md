# Component API Contracts: Admin Panel UI/UX Layout Convergence

**Date**: 2026-10-12

These contracts define the public interface of each modified or new component. They must remain stable across the migration — callers must not break when components are updated to these specs.

---

## Modified Components

### `Card`

```typescript
// Location: wallet-ecosystem/apps/admin/components/ui/Card.tsx

interface CardProps extends HTMLAttributes<HTMLElement> {
  as?: 'article' | 'section' | 'div';    // default: 'article'
  semanticLabel?: string;                  // maps to aria-label
  className?: string;                      // override/extend classes
}
```

**Visual contract**:
- Default background: `bg-ok-surface` (CSS var `--ok-surface`)
- Default border: `border border-neutral-700`
- Border radius: `rounded-lg`
- Elevation: `shadow-sm`
- Padding: `p-4`
- All defaults overridable via `className` prop

**Breaking change**: `border-neutral-200` and `bg-bg-primary` are removed. Any caller relying on light-mode card appearance must explicitly override via `className`.

---

### `Button`

```typescript
// Location: wallet-ecosystem/apps/admin/components/ui/Button.tsx

type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;    // default: 'primary'
  loading?: boolean;          // default: false
}
```

**Visual contract**:
- Desktop (`≥640px`): `min-h-[35px]`
- Mobile (`<640px`): `min-h-[44px]`
- Padding: `px-4`
- Border radius: `rounded-md`
- All variants unchanged (primary/secondary/danger/ghost styles preserved)

**Non-breaking**: Callers passing explicit height classes via `className` override the responsive min-height.

---

### `PageContainer`

```typescript
// Location: wallet-ecosystem/apps/admin/components/layout/PageContainer.tsx

interface PageContainerProps {
  children: React.ReactNode;
}
```

**Layout contract**:
- Horizontal padding: `32px` (default), `36px` at `lg` breakpoint
- Vertical padding: `32px`
- Max content width: `1500px`, centred
- Width: `100%`

**Usage**: Wrap every `app/(admin)/*/page.tsx` content in `PageContainer`. The only exception is `AdminDashboardPage` which manages its own internal layout but still uses `PageContainer` as its outermost wrapper.

---

### `PageHeader`

```typescript
// Location: wallet-ecosystem/apps/admin/components/layout/PageHeader.tsx
// No interface change — existing contract is correct

interface PageHeaderProps {
  title: string;
  description?: string;
  actions?: React.ReactNode;
  breadcrumbs?: BreadcrumbItem[];
}
```

**Visual contract** (unchanged):
- Title: `text-2xl font-semibold text-text-primary`
- Description: `text-sm text-text-secondary`
- Actions: right-aligned flex row
- Bottom margin: `mb-6`

---

### `MetricChart`

```typescript
// Location: wallet-ecosystem/apps/admin/components/dashboard/MetricChart.tsx

interface MetricChartProps {
  period?: '7' | '30' | '90';  // NEW prop — default: '7'
}
```

**Behaviour contract**:
- `period='7'` → last 7 days, daily buckets
- `period='30'` → last 30 days, daily buckets
- `period='90'` → last 90 days, weekly buckets
- If API lacks date filtering: renders chart of all available data + notice banner "Period filter not available — showing all transactions"
- Loading, error, and empty states: unchanged from current implementation

---

## New Components

### `RecentTransactionsTable`

```typescript
// Location: wallet-ecosystem/apps/admin/components/dashboard/RecentTransactionsTable.tsx

interface RecentTransactionsTableProps {
  // No props — self-contained component
}
```

**Internal API**:
- Fetches via `searchAdminTransactions(authenticatedClient, { status?, q?, per_page: 8, page })`
- Status tabs: `'All' | 'completed' | 'pending' | 'failed'`
- Search: debounced text input filters by reference/customer email
- Pagination: Previous / Next controls
- Renders via `DataTable` with columns: Transaction type, Amount (formatted), Status badge, Date

**Visual contract**:
- Card surface: `bg-ok-surface border border-neutral-700 rounded-lg`
- Card header: title "Recent transactions" + "View all →" link to `/admin-portal/transactions`
- Tab bar: matches wireframe tab style (active: `bg-neutral-700/40 text-text-primary`)
- Table: uses `DataTable` component — no raw `<table>` markup

---

### `QuickActionsPanel`

```typescript
// Location: wallet-ecosystem/apps/admin/components/dashboard/QuickActionsPanel.tsx

interface QuickActionsPanel {
  // No props — static configuration
}
```

**Content contract** (6 fixed links):

| Label | Route | Icon (lucide-react) |
|-------|-------|-------------------|
| Manage customers | `/admin-portal/users` | `Users` |
| Review KYC requests | `/kyc` | `ClipboardList` |
| Manage agents | `/agents` | `UserCheck` |
| View transactions | `/admin-portal/transactions` | `ArrowLeftRight` |
| Reconciliation | `/reconciliation/batches` | `RefreshCw` |
| Fee rules | `/fees/rules` | `Percent` |

**Visual contract**:
- Card surface: `bg-ok-surface border border-neutral-700 rounded-lg`
- Each link: full-width, `border border-neutral-700 rounded-md` row with label + arrow icon
- Hover: `hover:bg-neutral-700/30`
- No active state (navigation-only, not selection)

---

## Navigation Contract

### `NAV_SECTIONS` (expanded)

```typescript
// Location: wallet-ecosystem/apps/admin/src/lib/navigation/nav-items.ts

export const NAV_SECTIONS: NavSection[] = [
  {
    title: 'Workspace',
    items: [
      { href: '/dashboard',            label: 'Dashboard',            icon: LayoutDashboard },
      { href: '/financial-dashboard',  label: 'Financial Dashboard',  icon: TrendingUp },
      { href: '/risk-dashboard',       label: 'Risk Dashboard',       icon: ShieldAlert },
    ],
  },
  {
    title: 'Operations',
    items: [
      { href: '/admin-portal/users',         label: 'Users',           icon: Users },
      { href: '/devices',                    label: 'Devices',         icon: Monitor },
      { href: '/sessions',                   label: 'Sessions',        icon: Clock },
      { href: '/kyc',                        label: 'KYC Reviews',     icon: ClipboardCheck },
      { href: '/agents',                     label: 'Agents',          icon: UserCheck },
      { href: '/float',                      label: 'Agent Float',     icon: Banknote },
      { href: '/admin-portal/wallets',       label: 'Wallets',         icon: Wallet },
      { href: '/admin-portal/transactions',  label: 'Transactions',    icon: ArrowLeftRight },
      { href: '/reconciliation/batches',     label: 'Reconciliation',  icon: RefreshCw },
      { href: '/fees/rules',                 label: 'Fee Rules',       icon: Percent },
    ],
  },
  {
    title: 'Management',
    items: [
      { href: '/audit',                  label: 'Audit Events',        icon: FileText },
      { href: '/compliance',             label: 'Compliance',          icon: Shield },
      { href: '/reports',                label: 'Reports',             icon: BarChart2 },
      { href: '/security/dashboard',     label: 'Security',            icon: ShieldAlert,    requiredPermission: 'security.findings.read' },
      { href: '/multi-currency/currencies', label: 'Multi-Currency',  icon: Globe },
      { href: '/configuration',          label: 'Configuration',       icon: Settings },
      { href: '/integrations',           label: 'Integrations',        icon: Plug },
      { href: '/operations/dead-letter', label: 'Dead Letter',         icon: Inbox,          requiredPermission: 'operations.dead_letter.manage' },
      { href: '/ops',                    label: 'Ops Center',          icon: Activity },
      { href: '/qa',                     label: 'QA Dashboard',        icon: FlaskConical,   requiredPermission: 'qa.execute' },
      { href: '/platform/security-config', label: 'Platform',         icon: Lock },
    ],
  },
];
```

**Tooltip contract**: Each nav item with `collapsed=true` MUST have `title={item.label}` (existing) AND `aria-label={item.label}` (existing) — browser-native tooltip is sufficient per `research.md` R-04.

---

## Dashboard Page Layout Contract

```
DashboardPage Layout Grid
──────────────────────────────────────────────────────────
Viewport ≥950px:

  ┌─────────────────────────────────────────────────────┐
  │ Eyebrow + H1 + Subtitle     [Period Selector] [Export]│
  ├──────────┬──────────┬──────────┬──────────────────────┤
  │ KPI Card │ KPI Card │ KPI Card │ KPI Card             │
  │ Users    │ Wallets  │ Txn Today│ KYC Pending          │
  ├──────────────────────────────┬──────────────────────┤
  │                              │                        │
  │  MetricChart (1.7fr)         │  ActivityFeed (0.85fr) │
  │                              │                        │
  ├──────────────────────────────┼──────────────────────┤
  │                              │                        │
  │  RecentTransactionsTable     │  QuickActionsPanel     │
  │  (1.7fr)                     │  (0.85fr)              │
  │                              │                        │
  └──────────────────────────────┴──────────────────────┘

Grid CSS:
  KPI row:     grid-cols-2 sm:grid-cols-4 gap-4
  Middle row:  grid-cols-1 css-grid [1.7fr 0.85fr] @media >950px
  Bottom row:  grid-cols-1 css-grid [1.7fr 0.85fr] @media >950px

  Tailwind equivalent for middle/bottom:
    lg:grid-cols-[minmax(0,1.7fr)_minmax(270px,0.85fr)]

Viewport <950px (tablet): both rows stack to 1 column
Viewport <640px (mobile): KPI row goes to 2 columns; sidebar becomes drawer
──────────────────────────────────────────────────────────
```
