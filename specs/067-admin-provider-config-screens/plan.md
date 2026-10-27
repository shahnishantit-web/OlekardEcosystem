# Implementation Plan: P20 — Admin Provider Configuration and Policy Screens

**Branch**: `067-admin-provider-config-screens` | **Date**: 2026-10-26 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/067-admin-provider-config-screens/spec.md`

---

## Summary

P20 delivers Next.js admin screens inside the existing admin shell (`wallet-ecosystem/apps/admin`) for browsing the payment provider registry, editing provider account configuration through a maker-checker draft/approve cycle, driving lifecycle transitions (activate/suspend/restore), managing module availability and display order, and reviewing version history. The backend domain logic is owned by P03/P05/P10; P20 adds a small set of thin read/write backend endpoints that are needed to support the UI (account listing/detail, account lifecycle, draft listing, module config), and builds the complete frontend experience.

---

## Technical Context

**Language/Version**: TypeScript 5.x (frontend); PHP 8.4+ (backend additions)

**Primary Dependencies**:
- Next.js 14+ App Router (admin shell — already running)
- React 18 (via Next.js)
- SWR or React Query for data fetching (follow existing admin pattern)
- Tailwind CSS + design tokens (from E35 — `@olekard/design-tokens`)
- `@/components/ui/*` shared UI components (Badge, Button, Table, Loading, ErrorState, PageContainer, PageHeader — already present in admin app)
- `@/lib/api/payment.ts` (extend — already exists)
- Laravel + Eloquent (backend thin controller additions)

**Storage**: PostgreSQL via `payment` DB connection (backend reads existing P05 tables)

**Testing**: Vitest + React Testing Library (frontend); PHPStan level 8 (backend additions)

**Target Platform**: Admin web application — desktop-first (1024px+); responsive for approval reads on tablet/mobile

**Project Type**: Admin web application (frontend-dominant; minimal backend additions)

**Performance Goals**: Provider list loads in < 1 second; config form saves draft in < 500 ms

**Constraints**:
- No secrets returned to browser at any point; `callback_secret_reference` uses `SecretReference` VO already in place
- `callback_url` input absent from all screens (E25 owns ingress)
- Adapter identifier restricted to allowlist from `GET /v1/admin/payment/providers/adapters`
- All state changes require server-side permission check; UI gates are UX convenience only
- Reuse existing design tokens, shared components, and `lib/api/payment.ts` patterns

**Scale/Scope**: ~8 new admin screens; ~15 new React components; ~6 new backend thin endpoints; ~2 backend controllers extended

---

## Constitution Check

| Principle | Check | Status |
|-----------|-------|--------|
| P1 Source-of-Truth | P03/P05/P10 are the authoritative backends; P20 surfaces their state | ✅ PASS |
| P2 TBD Governance | Module config entity treated as provisional (see research.md); no TBD values invented | ✅ PASS |
| P3 Configuration | Provider limits, fees, module availability managed centrally via these screens | ✅ PASS |
| P5 Authorization | All routes protected by `identity.require-permission` middleware; self-approval blocked server-side | ✅ PASS |
| P6 Zero Trust | `SecretReference` VO already masks credentials in all serializations | ✅ PASS |
| P7 Domain Boundaries | New backend endpoints are thin controllers; business logic stays in existing services | ✅ PASS |
| P8 API Contracts | New endpoints use `/v1` versioned routes; OpenAPI entries required | ✅ PASS |
| P10 UX/Design System | Admin desktop-first; design tokens from E35; reuse existing shared components | ✅ PASS |
| P11 Accessibility | WCAG 2.1 AA; keyboard navigation, focus management, screen reader labels required | ✅ PASS |
| P14 No Silent Arch Changes | Next.js + Laravel stack unchanged; no new frameworks introduced | ✅ PASS |

**Gate result**: PASS — proceed to Phase 1 design.

---

## Project Structure

### Documentation (this feature)

```text
specs/067-admin-provider-config-screens/
├── plan.md              ← this file
├── research.md          ← Phase 0 output
├── data-model.md        ← Phase 1 output
├── contracts/           ← Phase 1 output (API contracts for new backend endpoints)
│   └── provider-config-admin-api.yaml
├── quickstart.md        ← Phase 1 output
└── tasks.md             ← Phase 2 output (NOT created by /speckit-plan)
```

### Source Code Layout

```text
# ── Backend (thin new endpoints) ─────────────────────────────────────────────
wallet-ecosystem/services/api/
├── app/Modules/Payment/
│   ├── Http/Controllers/Admin/
│   │   ├── PaymentProviderAdminController.php       (EXTEND — add account endpoints)
│   │   ├── ProviderAccountAdminController.php       (NEW — account CRUD + lifecycle)
│   │   ├── ProviderConfigDraftListAdminController.php (NEW — list/get drafts with diff)
│   │   └── PaymentModuleConfigAdminController.php   (NEW — module availability CRUD)
│   ├── Application/Services/
│   │   ├── PaymentProviderAdminService.php          (EXTEND — add account methods)
│   │   └── PaymentModuleConfigService.php           (NEW — module config read/write)
│   ├── Infrastructure/Models/
│   │   └── PaymentModuleConfigModel.php             (NEW — see data-model.md)
│   └── Infrastructure/Migrations/
│       └── 2026_10_26_000027_create_payment_module_configs_table.php (NEW)
└── routes/modules/payment.php                       (EXTEND — add new admin routes)

# ── Frontend (admin screens) ──────────────────────────────────────────────────
wallet-ecosystem/apps/admin/
├── app/(admin)/payments/providers/
│   ├── page.tsx                                      (NEW — Provider Registry list)
│   ├── [providerId]/
│   │   ├── page.tsx                                  (NEW — Provider detail + accounts)
│   │   └── accounts/
│   │       └── [accountId]/
│   │           ├── page.tsx                          (NEW — Account detail + config form)
│   │           └── history/
│   │               └── page.tsx                      (NEW — Version history)
│   └── approvals/
│       └── page.tsx                                  (NEW — Approval queue)
├── app/(admin)/payments/config/
│   └── modules/
│       └── page.tsx                                  (NEW — Module availability + display order)
├── src/modules/payment/
│   ├── components/
│   │   ├── ProviderStatusBadge.tsx                   (NEW)
│   │   ├── EnvironmentBadge.tsx                      (NEW)
│   │   ├── ProviderAccountForm.tsx                   (NEW — config edit form)
│   │   ├── ConfigDiffPreview.tsx                     (NEW — old vs. new diff display)
│   │   ├── ApprovalQueueTable.tsx                    (NEW)
│   │   ├── VersionHistoryTimeline.tsx                (NEW)
│   │   ├── ModuleAvailabilityForm.tsx                (NEW)
│   │   └── ProviderLifecycleControls.tsx             (NEW — activate/suspend/restore)
│   ├── hooks/
│   │   ├── useProviders.ts                           (NEW — SWR/React Query data hooks)
│   │   ├── useProviderAccount.ts                     (NEW)
│   │   ├── useConfigDrafts.ts                        (NEW)
│   │   └── useModuleConfig.ts                        (NEW)
│   └── api/
│       └── providerConfig.ts                         (NEW — API client methods for P20 endpoints)
├── lib/api/payment.ts                                (EXTEND — add account/draft/module types + methods)
└── openapi/v1/payment.yaml                          (EXTEND — add P20 backend endpoint schemas)
```

**Structure Decision**: Frontend-dominant feature using Next.js App Router with server and client components. Backend additions are thin controllers exposing existing domain data through new read/write endpoints. All financial state changes (draft, approve, reject, lifecycle) are routed through existing P03/P05 services.

---

## Complexity Tracking

No constitution violations. No complexity exceptions required.

---

## Backend API Gaps Analysis

The existing backend (`PaymentProviderAdminController`, `PaymentConfigChangeDraftAdminController`) covers provider CRUD and draft approval at the provider level. P20 needs these additional thin endpoints:

| Gap | New Endpoint | Controller | Permission |
|-----|-------------|------------|------------|
| Account listing | `GET /v1/admin/payment/providers/{id}/accounts` | `ProviderAccountAdminController` | `payment.providers.read` |
| Account detail | `GET /v1/admin/payment/providers/{id}/accounts/{accountId}` | `ProviderAccountAdminController` | `payment.providers.read` |
| Account update (draft) | `PATCH /v1/admin/payment/providers/{id}/accounts/{accountId}` | `ProviderAccountAdminController` | `payment.providers.manage` |
| Account lifecycle | `POST /v1/admin/payment/providers/{id}/accounts/{accountId}/lifecycle` | `ProviderAccountAdminController` | `payment.providers.manage` |
| Draft list | `GET /v1/admin/payment/providers/{id}/config-changes` | `ProviderConfigDraftListAdminController` | `payment.providers.read` |
| Draft detail | `GET /v1/admin/payment/providers/{id}/config-changes/{draftId}` | `ProviderConfigDraftListAdminController` | `payment.providers.read` |
| Module config read | `GET /v1/admin/payment/config/modules` | `PaymentModuleConfigAdminController` | `payment.providers.read` |
| Module config write | `PATCH /v1/admin/payment/config/modules` | `PaymentModuleConfigAdminController` | `payment.providers.manage` |

**Note on account update**: `PATCH /accounts/{accountId}` for non-high-risk fields applies directly; high-risk fields (`merchant_account_reference`, `callback_secret_reference`) redirect to the existing draft workflow, returning `requires_approval: true` with `draft_id` per the established P03/P05 pattern.

**Note on account lifecycle**: Uses existing `account_lifecycle_status` field on `PaymentProviderAccountModel`. Valid transitions: `draft → active`, `active → suspended`, `suspended → active`, `active → retired`. Requires `reason` field. Writes audit record via `AuditServiceInterface`.
