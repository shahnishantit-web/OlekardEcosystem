# Implementation Plan: ADM-P1-015 — Reports & Analytics

**Branch**: `039-reports-analytics` | **Date**: 2026-10-14 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/039-reports-analytics/spec.md`

## Summary

Complete the four user stories for the Reports & Analytics module in the OleKard admin app. Five page files and five route files already exist as scaffolding (E33 work); this plan targets the gaps preventing acceptance criteria from being met: on-demand generation form improvements, status normalisation, download via the export flow, schedule re-activation, and reconciliation summary KPI cards with date range filtering.

All work is frontend-only (Next.js + TypeScript). Backend APIs are already live from E24.

---

## Technical Context

**Language/Version**: TypeScript 5.x, React 18, Next.js 14 (App Router)

**Primary Dependencies**:
- `@/lib/api/authenticated-client` — `AuthenticatedApiClient`; methods `get`, `post`, `put`, `delete`; returns `ApiResponse<T>` where `.data = T`
- `@/lib/api/admin-app-client` — `AdminAppClient`; wraps `/v1/admin-app/*`; `.createReport()`, `.getReport()`, `.listReconciliation()`, `.listSettlement()`
- `@/lib/auth/context` — `useAuth()` returns `{ authenticatedClient, hasPermission }`
- UI kit — `Card`, `Badge`, `Button`, `Loading`, `ErrorState`, `Empty`, `Dialog`, `Toast`, `Select`, `Input`, `Sheet`, `Table`, `PageContainer`, `PageHeader` (all in `@/components/`)
- `@/lib/utils/idempotency` — `generateIdempotencyKey(seed)`

**Storage**: N/A — frontend only; all state from existing E24 backend APIs

**Testing**: Jest 29 + React Testing Library; `jest-environment-jsdom`; run from `wallet-ecosystem/apps/admin`

**Target Platform**: Web browser, desktop-first (Next.js SSR/CSR hybrid via App Router)

**Performance Goals**: Report list loads in <2s; polling interval 5s; max 36 poll iterations (~3 min)

**Constraints**:
- Must not break existing 638+ passing tests
- No new backend endpoints — use only existing E24 API surface
- No new Next.js route files — all 5 routes already exist
- No changes to `admin-app-client.ts` — use methods as-is; add wrapper functions in new `src/modules/reports/api/reports.ts`

**Scale/Scope**: 4 user stories, 5 existing page files (modify 4, no change to 1), 1 new API wrapper file, 1 new test file

**Project Type**: Next.js admin web application — feature completion

---

## Constitution Check

| Principle | Status | Notes |
|-----------|--------|-------|
| P5 — Authorization | ✅ PASS | `hasPermission('adminapp.reports.read')` gates list; `hasPermission('adminapp.reports.generate')` gates form; `hasPermission('adminapp.reports.schedule')` gates schedule mutations |
| P6 — Zero Trust | ✅ PASS | All API calls authenticated via `authenticatedClient`; no credentials in frontend |
| P11 — Accessibility | ✅ MUST | ARIA labels on all buttons/forms; `role="status"` on polling indicator; keyboard navigation on tables |
| P13 — Observability | ✅ PASS | Download audit is server-side; no additional frontend instrumentation required |
| P14 — No Silent Architecture | ✅ PASS | TypeScript + Next.js + existing component library; no new framework or library introduced |
| P10 — UX | ✅ PASS | Desktop-first; existing design token classes used throughout |

No violations. Phase 0 research may proceed.

---

## Project Structure

### Documentation (this feature)

```text
specs/039-reports-analytics/
├── plan.md          ← This file
├── research.md      ← Phase 0 output
├── data-model.md    ← Phase 1 output
├── quickstart.md    ← Phase 1 output
├── contracts/       ← Phase 1 output (references E24 contract)
└── tasks.md         ← /speckit-tasks output (not created here)
```

### Source Code

```text
wallet-ecosystem/apps/admin/
├── src/modules/reports/
│   ├── api/
│   │   └── reports.ts              NEW — typed wrappers for report API calls
│   └── pages/
│       ├── report-list.tsx         MODIFY — fix form (type options, redirect, 429 handling), status normalisation, permission gate
│       ├── report-job-detail.tsx   MODIFY — fix download (use export flow with polling), retry failed jobs
│       ├── report-schedule-list.tsx MODIFY — add re-activate (PUT), fix hardcoded filters in create
│       ├── reconciliation-report.tsx MODIFY — add date range filter form, KPI summary cards
│       └── settlement-report.tsx   NO CHANGE — already correct
├── __tests__/features/reports/
│   └── p1015-reports-analytics.test.tsx  NEW — 20+ tests across 4 user stories
└── (no new route files)
```

---

## Complexity Tracking

No constitution violations requiring justification.

---

## API Surface Used

| Endpoint | Method | Used by |
|----------|--------|---------|
| `/v1/reports/jobs` | GET | Report list (filter by status/type) |
| `/v1/reports/jobs` | POST | On-demand report generation (via `authenticatedClient.post`) |
| `/v1/reports/jobs/{id}` | GET | Polling status in report-list form + detail page |
| `/v1/reports/jobs/{jobId}/exports` | POST | Request CSV/Excel download |
| `/v1/reports/exports/{exportId}` | GET | Poll export status → get `download_url` |
| `/v1/reports/schedules` | GET | Schedule list |
| `/v1/reports/schedules` | POST | Create schedule |
| `/v1/reports/schedules/{id}` | PUT | Update / re-activate schedule |
| `/v1/reports/schedules/{id}` | DELETE | Deactivate schedule (sets inactive, not physical delete) |
| `/v1/admin-app/reconciliation` | GET | Reconciliation batch list with date range params |

**Note**: `AdminAppClient.createReport()` uses `/v1/admin-app/reports` (different base from `/v1/reports/jobs`). Both create report jobs; status enum is the same: `requested | processing | complete | failed`. The admin-app path uses `report_id` for the job identifier (not `id`). All status references will use the canonical set: `requested | processing | complete | failed`.

---

## Phase 0: Research

See [research.md](research.md).

## Phase 1: Design

See [data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md).
