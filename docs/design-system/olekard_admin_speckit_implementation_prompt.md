# OleKard Admin Panel — Pixel-Consistent Layout Recovery & Implementation

## Mission
You are a Principal UI/UX Architect, Senior Next.js Engineer, and Design System Maintainer. The existing OleKard Admin Panel looks scattered and fails to present a coherent enterprise layout. Repair the **existing Admin UI across all implemented routes**, using the attached `olekard_admin_wireframe.html` as the **visual and behavioral acceptance reference**. This is a layout convergence/refactor, NOT a greenfield rewrite.

The reference combines Stripe-inspired operational data presentation with Linear-style compact navigation. It is **functional HTML/CSS/JavaScript reference code**, not production app code. Do not paste it wholesale into Next.js; translate it into semantic React/TypeScript components using installed libraries and existing design tokens.

## Inputs to inspect first
- `olekard_admin_wireframe.html` (required reference file)
- Existing Admin Next.js application and all implemented routes
- Existing Tailwind config/CSS variables, fonts, theme tokens, shadcn/ui components, RBAC, authentication, layouts, dashboards, tables and tests
- Actual project conventions, dependencies, routing, loading and error patterns

## Strict layout contract
1. Single coherent global AdminShell throughout existing Admin modules, except deliberate auth/public pages.
2. Desktop: fixed/sticky left navigation, expanded width ~252px, collapsed ~70px, app header 66px, content inset 32–36px, max readable content width 1500px.
3. Header must align with main content and contain breadcrumbs, existing working search/notifications if available, and account affordances.
4. Sidebar groups: Workspace, Operations, Management; items only for implemented + authorized routes. Clear active state; consistent 38px target rows; correct expanded/collapsed state; tooltips in collapsed mode.
5. Overview content order must match HTML reference: Page heading/action bar → 4 metric cards → main transaction trend chart + operational activity panel → recent transaction table + quick actions. No random floating cards, arbitrary widths or isolated pieces.
6. Four desktop KPI columns when space allows; two at smaller desktop/tablet, one or two on mobile based on actual width. Card alignment and spacing must be consistent.
7. Desktop analytical/chart section and bottom section must align as 1.7fr / 0.85fr where available; stack below ~950px.
8. Card radius, border, surface, typography, paddings, row height and controls must be unified. Derive appearance from existing color tokens. **Do not replace existing OleKard color scheme.**
9. Tables: stable headers, compact clear rows, working search, status filters, safe responsive horizontal handling, empty/loading/error states, permission-aware actions, backend pagination when supported.
10. Detail/form/management pages must inherit AdminShell, PageHeader, standard main-content gutter and reusable surface components.
11. Mobile (<640px): sidebar drawer with overlay and Escape-to-close; header and controls not clipped; no horizontal page scroll; table may horizontally scroll within its container.
12. Respect WCAG 2.2 AA: semantic elements, visible focus, keyboard nav, accessible names, sufficient contrast, reduced motion support.
13. Never fabricate live data, wire sample numbers to production, introduce mock buttons as if functional, or substitute placeholder pages for real implemented modules. Use existing APIs, statuses and permission rules.
14. Do not alter backend APIs, authentication, RBAC, financial calculations, security rules, or wallet logic without a documented and separately approved reason.

## Behavior acceptance criteria
- Sidebar expand/collapse works; active route visibly highlighted; route navigation does not reset application shell unexpectedly.
- Reporting selector offers Last 7 / 30 / 90 days and updates genuine data queries/charts or explicitly shows the unavailable state; never show fabricated figures.
- Transaction status tabs All / Completed / Pending / Failed and keyword search work with existing real transactions and backend filtering as appropriate.
- Breadcrumb changes accurately with route.
- Quick actions navigate to real permission-eligible routes only.
- Loading, empty and error states do not destabilize card/table layout.
- Charts resize without overflow.
- All implemented modules present consistent page title, breadcrumbs, actions, standard data surfaces and spacing.

## Required process: audit BEFORE edits
1. Run a repository inventory. Identify all Next.js routes and pages, nested layouts, UI component sources, CSS overrides, existing brand tokens, permissions, and data dependencies.
2. Compare the *running actual Admin* against `olekard_admin_wireframe.html` at 1440×900, 1280×800, 768×1024 and 390×844, where browser tooling is available.
3. Write `docs/admin-ui-audit.md` including: (a) page inventory, (b) visual defects and likely root causes, (c) duplicated shells/styles/components, (d) proposed canonical component architecture, (e) phased file-by-file migration plan, and (f) risk and test plan.
4. Stop and present the audit + architecture plan for approval before large-scale changes.

## Approved implementation stages, after approval
- A. Standardize design tokens (use existing palette), typography, spacing, borders, statuses, and elevation.
- B. Build shared AdminShell: Sidebar, Header, Breadcrumbs, PageContainer, PageHeader, responsive drawer.
- C. Consolidate primitives: MetricCard, ChartPanel, ActivityFeed, DataTable, FilterBar, EmptyState, LoadingSkeleton, ErrorState, ActionMenu, DetailSection, FormSection.
- D. Implement Overview arrangement, using real API data and authorization.
- E. Migrate all existing Admin list/detail/create/edit pages to standardized shells and shared components; do not replace functional content with placeholders.
- F. Add or update focused component/integration/e2e tests. Validate keyboard accessibility, route state, breakpoint behavior, search/filter queries, data correctness and authorized actions.

## Quality gates
- TypeScript, lint, build and applicable tests pass.
- Compare screenshots with the reference at all four viewports and capture before/after where possible.
- No unexpected horizontal page overflow or overlapping header/sidebar.
- No clipped labels, inconsistent page gutters, duplicate nav menus, scattered cards or inconsistent button spacing.
- Existing authenticated workflows, RBAC, routes and API contracts remain intact.
- Do not mark a stage COMPLETE if its mandatory checks fail; report blockers truthfully.

## Deliverables
- Initial audit and implementation plan.
- Changed files, component map, token mapping, route migration checklist.
- Before/after viewport comparisons, actual tests and failures, accessibility findings.
- Final report: COMPLETED / PARTIAL / BLOCKED with explicit Definition-of-Done status.

## Important note about the reference
The standalone HTML deliberately contains **mock amounts, fake events, non-production module placeholders, and conceptual search/report buttons**. These demonstrate interaction and spatial structure ONLY. Never deploy those values or placeholders in the real application. Keep the layout fidelity while preserving actual OleKard functionality.

**Begin with the existing repository audit and comparison against the attached HTML. Do not start by redesigning individual pages independently.**
