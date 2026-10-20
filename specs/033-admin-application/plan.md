# Implementation Plan — E33 Admin Application

## Constitution Check
- [x] Approved Laravel/Next.js/Flutter stack respected — backend: Laravel (new AdminApp module); frontend: existing Next.js admin app at `wallet-ecosystem/apps/admin/`
- [x] No TBD value invented — aggregation approach delegates all TBD domain decisions to prior epics (E01–E32)
- [x] Authorization remains server-side — admin.read/admin.write enforced in Laravel policies; role-to-story matrix enforced per clarification; frontend renders but backend authorizes
- [x] Financial integrity preserved — freeze/unfreeze/reverse delegate to existing domain services with their ledger guarantees; no compensating entries created in AdminApp
- [x] Security/audit requirements incorporated — all 22 stories emit AUDIT_EVENT_RECORDED; maker-checker on 6 state-changing stories
- [x] UX/design-system requirements incorporated — new pages use existing shared token-driven component library (`components/ui/`)
- [x] QA strategy incorporated — 8 QA scenario types per story including authorization, idempotency, and audit verification

## Technical Context
- Backend: Laravel / PHP 8.4+ — new `Modules/AdminApp/` in the modular monolith
- Database: PostgreSQL — no new tables owned by AdminApp; reads from existing domain tables via domain services
- Queue/cache: Redis — dashboard metrics cached with 5-minute TTL (`adminapp.dashboard.{type}`)
- Web: Next.js 14 + TypeScript — `wallet-ecosystem/apps/admin/` (existing); new pages added to existing route groups
- API: REST/OpenAPI — 34 REST endpoints at `/v1/admin-app/` (list/detail/action triples per resource group; documented in `openapi/v1/admin-app.yaml`)
- Testing: PHPUnit/Pest (Laravel), Jest (Next.js), E2E for critical flows (KYC approve, wallet freeze, transaction reverse)
- Observability: correlation IDs on all requests; financial/security events auditable

## Architecture Overview

### Backend — AdminApp Aggregation Module
Location: `wallet-ecosystem/services/api/app/Modules/AdminApp/`

Structure:
```
Modules/AdminApp/
  Http/
    Controllers/
      DashboardController.php       # US-ADMINAPP-001/002/003
      UserController.php            # US-ADMINAPP-004/005/006
      KycController.php             # US-ADMINAPP-007/008/009
      WalletController.php          # US-ADMINAPP-010/011/012
      TransactionController.php     # US-ADMINAPP-013/014/015
      AgentController.php           # US-ADMINAPP-016/017/018
      ReconciliationController.php  # US-ADMINAPP-019/020
      ComplianceController.php      # US-ADMINAPP-021/022
    Requests/
    Resources/
  Console/Commands/
    RefreshDashboardCacheCommand.php  # runs every 5 min
  Providers/
    AdminAppServiceProvider.php
  routes/
    api.php
```

Each controller method is a thin orchestrator: authorize → call domain service → return DTO. No business logic lives here.

Route group: `Route::prefix('v1/admin-app')->middleware(['identity.auth', AddCorrelationIdHeader::class])->group(...)`

### Frontend — Existing Admin Portal Additions
Location: `wallet-ecosystem/apps/admin/`

New pages / sections:
```
app/(admin)/
  kyc/                              # US-ADMINAPP-007/008/009 (NEW)
    page.tsx                        # KYC review queue
    [id]/
      page.tsx                      # KYC detail + approve/reject
  agents/                           # US-ADMINAPP-016/017 (NEW)
    page.tsx                        # Agent management list
    [id]/
      page.tsx                      # Agent detail + approve action
  float/                            # US-ADMINAPP-018 (NEW)
    page.tsx                        # Float management
  financial-dashboard/              # US-ADMINAPP-002 (NEW)
    page.tsx
  risk-dashboard/                   # US-ADMINAPP-003 (NEW)
    page.tsx
```

Existing pages enhanced:
- `app/(admin)/admin-portal/dashboard/` → US-ADMINAPP-001 (add 5-min refresh + last-updated timestamp)
- `app/(admin)/admin-portal/wallets/` → US-ADMINAPP-011/012 (add freeze/unfreeze actions with maker-checker)
- `app/(admin)/admin-portal/transactions/` → US-ADMINAPP-015 (add reversal action with maker-checker)

New module API files:
```
src/modules/
  kyc/api/kyc.ts                    # /v1/admin-app/kyc endpoints
  agents/api/agents.ts              # /v1/admin-app/agent endpoints
  float/api/float.ts                # /v1/admin-app/float endpoints
```

### Role-Based Access — Permission Slugs
All enforced server-side in Laravel policies and reflected in frontend middleware:

| Story | Permission slug |
|-------|----------------|
| 001 Operational dashboard | `adminapp.dashboard.operational` |
| 002 Financial dashboard | `adminapp.dashboard.financial` |
| 003 Risk dashboard | `adminapp.dashboard.risk` |
| 004 Search users | `adminapp.users.read` |
| 005 Manage users | `adminapp.users.write` |
| 006 Manage roles | `adminapp.roles.manage` |
| 007 Review KYC | `adminapp.kyc.review` |
| 008 Approve KYC | `adminapp.kyc.approve` |
| 009 Reject KYC | `adminapp.kyc.reject` |
| 010 Search wallets | `adminapp.wallets.read` |
| 011 Freeze wallet | `adminapp.wallets.freeze` |
| 012 Unfreeze wallet | `adminapp.wallets.unfreeze` |
| 013 Search transactions | `adminapp.transactions.read` |
| 014 Investigate transactions | `adminapp.transactions.investigate` |
| 015 Reverse transaction | `adminapp.transactions.reverse` |
| 016 Approve agent | `adminapp.agents.approve` |
| 017 Manage agent | `adminapp.agents.manage` |
| 018 Manage float | `adminapp.float.manage` |
| 019 Reconciliation | `adminapp.reconciliation.read` |
| 020 Settlement | `adminapp.settlement.read` |
| 021 Audit | `adminapp.audit.read` |
| 022 Reports | `adminapp.reports.read` |

These slugs are seeded by E33 and must be assigned to the role groups (Admin/Operations/Finance/Compliance) consistent with the role-to-story matrix in the spec.

### Dashboard Cache Strategy
- Artisan command: `php artisan adminapp:refresh-dashboard-cache`
- Schedule: every 5 minutes via `$schedule->command(...)->everyFiveMinutes()`
- Redis keys: `adminapp:dashboard:operational`, `adminapp:dashboard:financial`, `adminapp:dashboard:risk`
- TTL: 300 seconds (fallback if scheduler misses)
- Response includes `cached_at` (ISO 8601 UTC) timestamp
- Frontend: React Query `refetchInterval: 300_000` on dashboard pages

### Maker-Checker Integration (Stories 008/009/011/012/015/016)
Reuses E28 `MakerCheckerWorkflow`. New action types registered:
- `adminapp.kyc.approve`, `adminapp.kyc.reject`
- `adminapp.wallet.freeze`, `adminapp.wallet.unfreeze`
- `adminapp.transaction.reverse`
- `adminapp.agent.approve`

Flow:
1. Maker POSTs action → AdminApp controller creates pending approval via MakerCheckerService → returns `202 Accepted` + `{ approval_id, status: "pending_approval" }`
2. Checker reviews via existing `/v1/admin/configuration/approvals/{id}` endpoint
3. On approval → MakerCheckerWorkflow calls the domain service method

## Data / Financial Controls
- Freeze/unfreeze wallet → delegates to `WalletService::freeze/unfreeze` (E06); idempotency enforced there
- Transaction reversal → delegates to `TransactionReversalService` (E09/E10); compensating ledger entries created there; AdminApp enforces scope (wallet-to-wallet + agent cash-in/out only)
- Float management → delegates to `AgentFloatService` (E13)
- All write operations require `Idempotency-Key` header; AdminApp validates presence before forwarding
- Reconciliation/settlement → read-only aggregation from `ReconciliationService` (E20)

## Observability
- Correlation IDs on all requests (existing admin auth middleware pattern)
- All 22 stories emit `AUDIT_EVENT_RECORDED` via existing `AuditService`
- Dashboard cache hit/miss tracked in Redis INFO
- Laravel Horizon monitors the dashboard refresh jobs

## Phase 0 — Research
See `research.md`. All unknowns resolved. No blocking TBDs.

## Phase 1 — Design
Artifacts produced:
- `data-model.md` — view models and aggregated DTOs (11 view models; no new DB tables)
- `contracts/dashboards.md` — US-ADMINAPP-001/002/003 (3 GET endpoints)
- `contracts/user-wallet-transaction.md` — US-ADMINAPP-004–015 (12 endpoints; 5 with maker-checker)
- `contracts/agent-finance.md` — US-ADMINAPP-016–020 (8 endpoints; 1 with maker-checker)
- `contracts/compliance.md` — US-ADMINAPP-021/022 (4 endpoints)
- `quickstart.md` — 9 validation scenarios (SC-1 through SC-9)

## Implementation Approach
1. Seed new `adminapp.*` permission slugs and assign to roles (E04 integration).
2. Scaffold `Modules/AdminApp/` with controllers, routes, and service provider.
3. Implement `RefreshDashboardCacheCommand` and register in scheduler.
4. Register 6 maker-checker action types with E28 `MakerCheckerWorkflow`.
5. Implement each controller group in dependency order: dashboards → users → KYC → wallets → transactions → agents → finance → compliance.
6. Add frontend: new pages for KYC, agents, float, financial/risk dashboards; enhance existing wallet/transaction pages.
7. Enforce role-based access in Next.js middleware and per-page server checks.
8. Write PHPUnit/Pest tests (unit + feature) and Jest tests for frontend.
9. Update OpenAPI docs.

## Complexity Tracking
Any architecture deviation requires an ADR and constitution check update.
