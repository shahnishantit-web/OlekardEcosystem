# Implementation Plan: Admin Panel UI/UX Layout Convergence

**Branch**: `037-admin-ui-convergence` | **Date**: 2026-10-12 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/037-admin-ui-convergence/spec.md`

---

## Summary

Converge the OleKard Admin Next.js application (≈60 routes) to a single coherent enterprise shell matching the `olekard_admin_wireframe.html` reference. This is a layout refactor — no backend logic, financial calculations, APIs, authentication, or RBAC rules change. Six work stages proceed in dependency order: token standardization → shell dimensions → navigation expansion → dashboard convergence → page migration → quality gates.

---

## Technical Context

**Language/Version**: TypeScript 5.x, React 18, Next.js 14 (App Router)

**Primary Dependencies**:
- Tailwind CSS 3.x — utility-first styling, tokens via CSS custom properties
- `packages/design-system/build/css/tokens.css` — single source of truth for all CSS variables
- `lucide-react` — icon set used throughout admin nav/components
- `recharts` — charting library for MetricChart
- `@fontsource/inter` — typography

**Storage**: No persistence changes. `sessionStorage` for sidebar collapse state (existing).

**Testing**: Vitest / Jest component tests (existing pattern); TypeScript `tsc --noEmit`; ESLint; manual browser viewport verification at 1440×900, 1280×800, 768×1024, 390×844.

**Target Platform**: Desktop-first admin web app (Next.js App Router, Chrome/Firefox/Edge, deployed behind auth middleware).

**Project Type**: Internal web application (admin panel), layout/component refactor only.

**Performance Goals**: No regression — all existing data fetch patterns remain unchanged; no new network calls introduced beyond what period-selector support requires.

**Constraints**:
- Preserve all existing API call signatures, authentication flow, RBAC checks, and financial data display logic.
- No new npm packages may be added without justification; changes must use already-installed dependencies.
- Sidebar CSS transition must respect `prefers-reduced-motion`.
- Button height change must not break mobile-touch targets; `min-height: 44px` is retained on `<640px` via responsive CSS.

**Scale/Scope**: 6 shared layout components + 4 dashboard-specific components + ~60 page components to migrate (most migrations are mechanical PageContainer/PageHeader wiring).

---

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|-------|
| P1 — Source-of-Truth Requirements | ✅ PASS | No business intent changed; layout only |
| P2 — TBD Governance | ✅ PASS | No TBD values invented; period-selector shows explicit "unavailable" state if API doesn't support it |
| P3 — Configuration Over Hard-Coding | ✅ PASS | Shell tokens are CSS variables in the design system; not hard-coded values |
| P4 — Financial Integrity | ✅ PASS | No financial calculations, ledger, or wallet logic touched |
| P5 — Identity and Authorization | ✅ PASS | Auth middleware, RBAC checks, and server-side enforcement unchanged |
| P6 — Zero Trust and Security | ✅ PASS | No security rules or credential handling changed |
| P7 — Domain Boundaries | ✅ PASS | Frontend-only changes; controller/service boundaries unaffected |
| P8 — API Contracts | ✅ PASS | No API call signatures changed |
| P9 — Event Reliability | ✅ PASS | N/A for UI refactor |
| P10 — UX and Design System | ✅ PASS | Design tokens are the source of truth; admin is desktop-first; tokens.css is authoritative |
| P11 — Accessibility | ✅ PASS | WCAG 2.2 AA requirements are addressed in the spec's acceptance criteria |
| P12 — Test-First Quality | ✅ PASS | FR-F01–F04 define quality gates; existing tests must continue to pass |
| P13 — Observability | ✅ PASS | No observability infrastructure changed |
| P14 — No Silent Architecture Changes | ✅ PASS | Next.js / TypeScript stack preserved; no framework change |
| P15 — Release Governance | ✅ PASS | All acceptance criteria and quality gates defined |

**Post-design re-check**: All principles still pass after Phase 1 design. No violations requiring justification.

---

## Project Structure

### Documentation (this feature)

```text
specs/037-admin-ui-convergence/
├── plan.md              ← This file
├── research.md          ← Phase 0 output
├── data-model.md        ← Phase 1 output (component architecture)
├── quickstart.md        ← Phase 1 output (validation guide)
├── contracts/
│   └── component-api.md ← Phase 1 output (component prop contracts)
└── tasks.md             ← Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
wallet-ecosystem/apps/admin/
├── styles/
│   └── globals.css                        ← Stage A: remove dead CSS classes
├── components/
│   ├── layout/
│   │   ├── AdminShell.tsx                 ← Stage B: no change needed (logic correct)
│   │   ├── AdminSidebar.tsx               ← Stages B+C: token fix + nav expansion
│   │   ├── AdminHeader.tsx                ← Stage B: height token only
│   │   ├── Breadcrumbs.tsx                ← No change
│   │   ├── PageContainer.tsx              ← Stage B: gutter + max-width update
│   │   └── PageHeader.tsx                 ← No change
│   ├── ui/
│   │   ├── Card.tsx                       ← Stage A: border + surface token fix
│   │   ├── Button.tsx                     ← Stage A: min-height fix
│   │   ├── DataTable.tsx                  ← No change (used in migration)
│   │   └── FilterBar.tsx                  ← No change (used in migration)
│   └── dashboard/
│       ├── KpiCard.tsx                    ← No change (canonical source)
│       ├── MetricChart.tsx                ← Stage D: add period prop
│       ├── ActivityFeed.tsx               ← Stage D: repurpose to system events
│       ├── PendingTasksCard.tsx           ← No change
│       ├── QuickActionsPanel.tsx          ← Stage D: NEW component
│       └── RecentTransactionsTable.tsx    ← Stage D: NEW component
├── src/
│   └── lib/
│       └── navigation/
│           └── nav-items.ts               ← Stage C: full nav expansion
├── app/
│   └── (admin)/
│       ├── dashboard/
│       │   └── page.tsx                   ← Stage D: new dashboard layout
│       └── admin-portal/
│           └── dashboard/
│               └── page.tsx               ← Stage D: redirect to /dashboard
└── src/modules/
    ├── dashboard/
    │   └── pages/
    │       └── admin-dashboard.tsx        ← Stage D: canonical dashboard page rewrite
    ├── admin-portal/
    │   └── pages/
    │       ├── portal-dashboard.tsx       ← Stage D: replaced with redirect
    │       └── user-management.tsx        ← Stage E: fix undeclared token classes
    └── [all other pages]                  ← Stage E: mechanical PageContainer migration
```

**Structure Decision**: Single Next.js application (`wallet-ecosystem/apps/admin/`). All changes are frontend-only. No new packages, no new service files, no migration files.

---

## Complexity Tracking

No Constitution Check violations. No complexity justification required.
