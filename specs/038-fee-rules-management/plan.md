# Implementation Plan: ADM-P1-014 — Fee Rules Management

**Branch**: `038-fee-rules-management` | **Date**: 2026-10-14 | **Spec**: [spec.md](spec.md)

## Summary

Completes the Fee Rules Management section of the OleKard Admin Portal. Two Next.js pages and their module pages already exist (`fee-rules-list.tsx`, `fee-rule-create.tsx`). This plan adds: (1) a fee rule detail page with inline deactivation dialog, (2) row-click navigation from the list, (3) a fee-impact simulation panel using `POST /v1/fees/calculate`, and (4) Jest + React Testing Library tests covering all three user stories.

## Technical Context

**Language/Version**: TypeScript 5 / Next.js 14 (App Router)

**Primary Dependencies**: React 18, `@testing-library/react`, `@testing-library/user-event`, jest-axe — all already installed in `wallet-ecosystem/apps/admin`

**Storage**: N/A — pure frontend; all state comes from the E21 backend over REST

**Testing**: Jest (existing config in `wallet-ecosystem/apps/admin/jest.config.ts`), `@testing-library/react`, `userEvent`

**Target Platform**: Desktop browser (admin portal); Next.js App Router `(admin)` group

**Performance Goals**: Simulation panel responds in < 2 s on normal connection; list page renders in < 1 s

**Constraints**: Zero raw API error codes shown to users; all financial operations require RBAC enforcement client-side (button visibility) and server-side (API)

**Scale/Scope**: Single admin app; ~3 new page components; ~1 API wrapper module; ~1 test file (~60–80 tests)

## Constitution Check

| Principle | Status | Notes |
|-----------|--------|-------|
| P3 — Config over hard-coding | ✅ PASS | Fee rules are DB-backed, centrally configurable via UI |
| P4 — Financial Integrity | ✅ PASS | Deactivation uses `DELETE` (soft-delete, no ledger mutation); simulation is stateless; no direct balance mutations |
| P5 — Identity & Authorization | ✅ PASS | Buttons conditionally rendered based on `permissions` from auth context; server enforces RBAC |
| P8 — API Contracts | ✅ PASS | Uses existing versioned `/v1/fees/*` endpoints; create form already sends `Idempotency-Key` |
| P10 — UX / Design System | ✅ PASS | Uses existing `Dialog`, `Toast`, `Badge`, `Card`, `Button`, `Loading`, `ErrorState` components |
| P11 — Accessibility | ✅ PASS | Dialog uses `role="dialog" aria-modal`, focus management via existing `Dialog.tsx`; all buttons have accessible labels |
| P12 — Test-First Quality | ✅ PASS | Test file covers all acceptance scenarios per spec |
| P13 — Observability | ✅ PASS | Audit events are emitted server-side (`FeeRuleCreated`, `FeeRuleDeactivated`); frontend does not bypass them |

## Project Structure

### Documentation (this feature)

```text
specs/038-fee-rules-management/
├── plan.md              ✅ this file
├── research.md          ✅ Phase 0 output
├── data-model.md        ✅ Phase 1 output
├── quickstart.md        ✅ Phase 1 output
└── tasks.md             ← Phase 2 output (/speckit-tasks)
```

### Source Code

```text
wallet-ecosystem/apps/admin/

# EXISTING — do not recreate
app/(admin)/fees/rules/page.tsx                          # list route
app/(admin)/fees/rules/create/page.tsx                   # create route
src/modules/fees/pages/fee-rules-list.tsx                # list component
src/modules/fees/pages/fee-rule-create.tsx               # create form

# NEW — to be created
app/(admin)/fees/rules/[id]/page.tsx                     # detail route
src/modules/fees/pages/fee-rule-detail.tsx               # detail component (+ deactivation dialog + simulation panel)
src/modules/fees/api/fees.ts                             # API wrapper (GET rule, DELETE rule, POST calculate)

# MODIFIED — small extension
src/modules/fees/pages/fee-rules-list.tsx                # add onClick row navigation + cursor-pointer

# TEST — new
__tests__/features/fees/p1014-fee-rules-management.test.tsx
```

## API Mapping

| Story | Action | Method | Path | Notes |
|-------|--------|--------|------|-------|
| US-P1-014-001 | Create | `POST` | `/v1/fees/rules` | Already implemented; add 409 error handling |
| US-P1-014-001 | Get detail | `GET` | `/v1/fees/rules/{id}` | New |
| US-P1-014-002 | Deactivate | `DELETE` | `/v1/fees/rules/{id}` | 204; soft-delete to `deactivated` status |
| US-P1-014-003 | Simulate | `POST` | `/v1/fees/calculate` | Stateless; no Idempotency-Key needed |

## Component Architecture

### `fee-rule-detail.tsx`

Top-level state:
- `rule: FeeRule | null` — fetched on mount via `GET /v1/fees/rules/{id}`
- `deactivateDialog: boolean` — controls confirmation modal
- `deactivating: boolean` — in-flight state
- `toast: { message, kind } | null` — success/error feedback
- `simOpen: boolean` — whether simulation panel is expanded
- `simAmount: string` — raw amount input
- `simLoading: boolean`
- `simResult: FeeCalculationResult | null`
- `simError: string | null`

Sub-components (inlined in the same file):
- `<FeeRuleStatusBadge>` — active → success, deactivated → error, expired → warning, superseded → neutral
- `<DetailField>` — dt/dd label-value pair (same pattern as `agent-detail.tsx`)
- `<DeactivateDialog>` — wraps `Dialog` component; shows financial impact warning
- `<SimulationPanel>` — collapsible section; calls `POST /v1/fees/calculate`

### `fees.ts` (API module)

```ts
// Pure wrappers — all error handling done in the component
getFeeRule(client, id)          // GET /v1/fees/rules/{id}
deactivateFeeRule(client, id)   // DELETE /v1/fees/rules/{id}
calculateFee(client, req)       // POST /v1/fees/calculate
```

No AdminAppClient wrapper needed — fees module uses `authenticatedClient` directly (consistent with existing `fee-rule-create.tsx`).

## Complexity Tracking

No constitution violations. No additional complexity introduced beyond the existing module pattern.
