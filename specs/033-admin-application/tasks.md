# Tasks: E33 Admin Application

**Input**: `specs/033-admin-application/` — spec.md, plan.md, data-model.md, contracts/, research.md, quickstart.md

**Backend root**: `wallet-ecosystem/services/api/app/Modules/AdminApp/`  
**Frontend root**: `wallet-ecosystem/apps/admin/`

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no shared dependencies)
- **[US#]**: User story mapping (US1=ADMINAPP-001 … US22=ADMINAPP-022)

---

## Phase 1: Setup

**Purpose**: Scaffold the AdminApp module, seed permissions, register maker-checker action types, and wire up the Artisan scheduler command. No business logic yet.

- [X] T001 Create `Modules/AdminApp/` directory structure in `wallet-ecosystem/services/api/app/Modules/AdminApp/` (Http/Controllers, Http/Requests, Http/Resources, Console/Commands, Providers, routes)
- [X] T002 Create `Modules/AdminApp/Providers/AdminAppServiceProvider.php` — register routes, bind interfaces, schedule `adminapp:refresh-dashboard-cache` every 5 minutes
- [X] T003 [P] Create `Modules/AdminApp/routes/api.php` — route group `v1/admin-app` with `identity.auth` middleware; stub all 27 endpoint closures returning 501
- [X] T004 [P] Create `wallet-ecosystem/services/api/database/seeders/AdminAppPermissionsSeeder.php` — seed all 22 `adminapp.*` permission slugs and assign to Admin/Operations/Finance/Compliance roles per the role-to-story matrix from spec.md
- [X] T005 [P] Register AdminApp module in `wallet-ecosystem/services/api/bootstrap/app.php` (or `config/app.php` providers array)
- [X] T006 [P] Register 6 maker-checker action types (`adminapp.kyc.approve`, `adminapp.kyc.reject`, `adminapp.wallet.freeze`, `adminapp.wallet.unfreeze`, `adminapp.transaction.reverse`, `adminapp.agent.approve`) in `wallet-ecosystem/services/api/config/maker_checker.php` per E28 MakerCheckerWorkflow registration pattern
- [X] T007 Create `Modules/AdminApp/Console/Commands/RefreshDashboardCacheCommand.php` — command signature `adminapp:refresh-dashboard-cache`; queries domain services for each of the 3 dashboard types and writes JSON to Redis keys `adminapp:dashboard:operational`, `adminapp:dashboard:financial`, `adminapp:dashboard:risk` with TTL 300s and `cached_at` field
- [X] T008 [P] Create base form request `Modules/AdminApp/Http/Requests/AdminAppRequest.php` extending `FormRequest` with shared correlation ID resolution and `Idempotency-Key` header validation helper

**Checkpoint**: Module scaffolded, permissions seeded, maker-checker registered, cache command exists.

---

## Phase 2: Foundational

**Purpose**: Core shared infrastructure that ALL controller implementations depend on before any story can be completed.

- [X] T009 Create `Modules/AdminApp/Http/Resources/PaginatedResource.php` — shared paginated response wrapper (`data[]`, `meta.total/per_page/current_page/last_page`)
- [X] T010 [P] Create `Modules/AdminApp/Http/Resources/ErrorResource.php` — shared error envelope `{ error: { code, message, correlation_id } }` per contracts spec
- [X] T011 [P] Create `Modules/AdminApp/Http/Requests/PaginationRequest.php` — validates `per_page` (default 25, max 100/200 by endpoint), `page` (min 1)
- [X] T012 Create `Modules/AdminApp/Http/Middleware/RequireIdempotencyKey.php` — middleware that returns `422 ADMINAPP_IDEM_001` if `Idempotency-Key` header is absent on state-changing routes; register in AdminApp route group for all POST/PATCH routes
- [X] T013 [P] Add `adminapp.read` and `adminapp.write` Laravel policies or gate definitions in `AdminAppServiceProvider.php`; wire per-endpoint permission check using `identity.require-permission:{slug}` pattern consistent with E04
- [X] T014 [P] Update Next.js middleware `wallet-ecosystem/apps/admin/middleware.ts` — add route matchers for new E33 paths (`/kyc`, `/agents`, `/float`, `/financial-dashboard`, `/risk-dashboard`) with permission-slug gate per research.md Decision 4 pattern
- [X] T015 [P] Create `wallet-ecosystem/apps/admin/lib/api/admin-app-client.ts` — typed API client module wrapping fetch calls to `/v1/admin-app/*` with `Authorization`, `X-Correlation-ID`, and optional `Idempotency-Key` headers
- [X] T016 Verify `php artisan adminapp:refresh-dashboard-cache` runs without error and writes all 3 Redis keys; fix any service injection issues

**Checkpoint**: Shared infrastructure ready — all controller phases can begin.

---

## Phase 3: Dashboards — US-ADMINAPP-001/002/003

**Goal**: All three dashboard endpoints return cached metrics with `cached_at` timestamp; roles enforced; cache refreshed on demand and by scheduler.

**Independent Test**: `GET /v1/admin-app/dashboard/operational` returns 200 for Admin; `GET /v1/admin-app/dashboard/financial` returns 403 for Operations role; scheduler warms cache; `cached_at` is ≤5 min old (SC-1 from quickstart.md).

- [X] T017 [P] [US1] Create `Modules/AdminApp/Http/Controllers/DashboardController.php` — inject Redis; `operational()`, `financial()`, `risk()` methods each read from respective Redis key, return 503 `ADMINAPP_DASH_001` if key absent
- [X] T018 [P] [US1] Create `Modules/AdminApp/Http/Resources/OperationalDashboardResource.php` wrapping `OperationalDashboardMetrics` view model fields as defined in `data-model.md`
- [X] T019 [P] [US2] Create `Modules/AdminApp/Http/Resources/FinancialDashboardResource.php` wrapping `FinancialDashboardMetrics` view model fields
- [X] T020 [P] [US3] Create `Modules/AdminApp/Http/Resources/RiskDashboardResource.php` wrapping `RiskDashboardMetrics` view model fields
- [X] T021 [US1] Wire `GET /v1/admin-app/dashboard/operational` → `DashboardController@operational` in `routes/api.php`; add `adminapp.dashboard.operational` permission gate
- [X] T022 [P] [US2] Wire `GET /v1/admin-app/dashboard/financial` → `DashboardController@financial`; add `adminapp.dashboard.financial` permission gate
- [X] T023 [P] [US3] Wire `GET /v1/admin-app/dashboard/risk` → `DashboardController@risk`; add `adminapp.dashboard.risk` permission gate
- [X] T024 [P] [US1] Create `wallet-ecosystem/apps/admin/app/(admin)/admin-portal/dashboard/page.tsx` enhancement — add React Query `useQuery` with `refetchInterval: 300_000`, render `cached_at` as "Last updated X minutes ago" badge; use existing dashboard layout
- [X] T025 [P] [US2] Create `wallet-ecosystem/apps/admin/app/(admin)/financial-dashboard/page.tsx` — financial metrics page with polling and `cached_at` display; restrict via middleware to Finance/Admin roles
- [X] T026 [P] [US3] Create `wallet-ecosystem/apps/admin/app/(admin)/risk-dashboard/page.tsx` — risk metrics page with polling; restrict via middleware to Compliance/Admin roles
- [X] T027 Create `wallet-ecosystem/services/api/tests/Feature/AdminApp/DashboardTest.php` — test SC-1: 200 for authorized roles, 403 for unauthorized roles, 503 when Redis key absent, `cached_at` present in response

**Checkpoint**: All 3 dashboard endpoints live; cache warm; role gates enforced; UI pages functional.

---

## Phase 4: Users & Roles — US-ADMINAPP-004/005/006

**Goal**: Admin/Operations can search users, view detail (unmasked phone), suspend/reactivate accounts; Admin can view and update role permission assignments.

**Independent Test**: `GET /v1/admin-app/users?q=test` returns paginated results with masked phone; `PATCH /v1/admin-app/users/{id}/status` suspends user and creates audit entry; idempotent replay returns same 200 (SC-2).

- [X] T028 [P] [US4] Create `Modules/AdminApp/Http/Controllers/UserController.php` — `index()` (search/filter via E01 UserService), `show()` (single user, unmasked), `updateStatus()` (suspend/reactivate via E01 UserService)
- [X] T029 [P] [US4] Create `Modules/AdminApp/Http/Resources/AdminUserResource.php` — maps `AdminUserSearchResult` view model; phone number masked in list, unmasked in detail
- [X] T030 [US4] Wire `GET /v1/admin-app/users` → `UserController@index` and `GET /v1/admin-app/users/{id}` → `UserController@show`; add `adminapp.users.read` gate
- [X] T031 [US5] Wire `PATCH /v1/admin-app/users/{id}/status` → `UserController@updateStatus`; add `adminapp.users.write` gate + `RequireIdempotencyKey` middleware; emit `AUDIT_EVENT_RECORDED` via AuditService
- [X] T032 [P] [US6] Add `listRoles()` and `updateRolePermissions()` methods to existing `Modules/AdminApp/Http/Controllers/UserController.php` — delegate to E04 RoleService; add `adminapp.roles.manage` gate
- [X] T033 [P] [US6] Wire `GET /v1/admin-app/roles` and `PATCH /v1/admin-app/roles/{id}/permissions` in `routes/api.php`; add `RequireIdempotencyKey` on PATCH
- [X] T034 [P] [US4] Create `wallet-ecosystem/apps/admin/app/(admin)/admin-portal/users/page.tsx` enhancement — add search filters (status, KYC tier), masked phone display; use existing users table component
- [X] T035 [P] [US5] Add suspend/reactivate action in `wallet-ecosystem/apps/admin/app/(admin)/admin-portal/users/[id]/page.tsx` — confirmation dialog with reason field; call PATCH endpoint with idempotency key
- [X] T036 Create `wallet-ecosystem/services/api/tests/Feature/AdminApp/UserTest.php` — SC-2: search returns results, suspend creates audit entry, idempotency replay, Finance role denied user endpoints

**Checkpoint**: User search + management + role management all functional with audit trail.

---

## Phase 5: KYC Review — US-ADMINAPP-007/008/009

**Goal**: Compliance/Admin can list pending KYC submissions with document URLs; approve and reject via maker-checker; audit entries created.

**Independent Test**: `GET /v1/admin-app/kyc?status=pending_review` returns queue; `POST /v1/admin-app/kyc/{id}/approve` returns 202 with `approval_id`; wrong-state returns 422; checker approval executes KYC via E05 (SC-3).

- [X] T037 [P] [US7] Create `Modules/AdminApp/Http/Controllers/KycController.php` — `index()` (list/filter via E05 KycService), `show()` (single submission with signed doc URLs), `approve()` (maker: submit to MakerCheckerService), `reject()` (maker: submit to MakerCheckerService)
- [X] T038 [P] [US7] Create `Modules/AdminApp/Http/Resources/KycReviewResource.php` — maps `KycReviewItem` view model; signed doc URLs with 1h TTL from E05 storage service
- [X] T039 [US7] Wire `GET /v1/admin-app/kyc` + `GET /v1/admin-app/kyc/{id}` → `KycController@index/show`; add `adminapp.kyc.review` gate
- [X] T040 [US8] Wire `POST /v1/admin-app/kyc/{id}/approve` → `KycController@approve`; add `adminapp.kyc.approve` gate + `RequireIdempotencyKey`; calls `PendingAdminActionService::submit('adminapp.kyc.approve', payload)` → return 202; emit `AUDIT_EVENT_RECORDED`
- [X] T041 [US9] Wire `POST /v1/admin-app/kyc/{id}/reject` → `KycController@reject`; add `adminapp.kyc.reject` gate + `RequireIdempotencyKey`; return `422 ADMINAPP_KYC_002` if submission not in `pending_review` state; emit `AUDIT_EVENT_RECORDED`
- [X] T042 [P] [US7] Create `wallet-ecosystem/apps/admin/src/modules/kyc/api/kyc.ts` — typed API client functions: `listKyc()`, `getKyc()`, `approveKyc()`, `rejectKyc()`
- [X] T043 [P] [US7] Create `wallet-ecosystem/apps/admin/app/(admin)/kyc/page.tsx` — KYC review queue page; filterable by status/tier; document thumbnail previews; "Approve"/"Reject" buttons triggering maker-checker flow
- [X] T044 [P] [US7] Create `wallet-ecosystem/apps/admin/app/(admin)/kyc/[id]/page.tsx` — KYC detail page with full document view, user info, approve/reject actions with reason field
- [X] T045 Create `wallet-ecosystem/services/api/tests/Feature/AdminApp/KycTest.php` — SC-3: list queue, approve returns 202, wrong-state returns 422, checker approves, audit entry created; Operations role denied

**Checkpoint**: KYC review queue and maker-checker approve/reject flow fully operational.

---

## Phase 6: Wallets — US-ADMINAPP-010/011/012

**Goal**: Admin/Operations can search wallets with balance info; freeze/unfreeze via maker-checker.

**Independent Test**: `GET /v1/admin-app/wallets` returns paginated list with balance; `POST /v1/admin-app/wallets/{id}/freeze` returns 202; checker approves → wallet status becomes `frozen` (SC-4).

- [X] T046 [P] [US10] Create `Modules/AdminApp/Http/Controllers/WalletController.php` — `index()` (search/filter via E06 WalletService), `show()` (single wallet detail), `freeze()` (maker: submit to PendingAdminActionService), `unfreeze()` (maker: submit to PendingAdminActionService)
- [X] T047 [P] [US10] Create `Modules/AdminApp/Http/Resources/AdminWalletResource.php` — maps `AdminWalletSearchResult` view model with all freeze metadata fields
- [X] T048 [US10] Wire `GET /v1/admin-app/wallets` + `GET /v1/admin-app/wallets/{id}` → `WalletController@index/show`; add `adminapp.wallets.read` gate
- [X] T049 [US11] Wire `POST /v1/admin-app/wallets/{id}/freeze` → `WalletController@freeze`; add `adminapp.wallets.freeze` gate + `RequireIdempotencyKey`; calls `PendingAdminActionService::submit('adminapp.wallet.freeze', payload)` → 202; emit `AUDIT_EVENT_RECORDED`
- [X] T050 [US12] Wire `POST /v1/admin-app/wallets/{id}/unfreeze` → `WalletController@unfreeze`; add `adminapp.wallets.unfreeze` gate + `RequireIdempotencyKey`; validate wallet is currently `frozen` (return `422 ADMINAPP_WALLET_002` if not); emit `AUDIT_EVENT_RECORDED`
- [X] T051 [P] [US10] Enhance `wallet-ecosystem/apps/admin/app/(admin)/admin-portal/wallets/page.tsx` — add freeze/unfreeze action buttons in wallet detail view with maker-checker confirmation dialog and reason field; add `status` badge and `frozen_by` info display
- [X] T052 Create `wallet-ecosystem/services/api/tests/Feature/AdminApp/WalletTest.php` — SC-4: search returns results, freeze returns 202, checker approves wallet is frozen, unfreeze on non-frozen returns 422, Compliance role denied freeze

**Checkpoint**: Wallet search and freeze/unfreeze maker-checker fully operational.

---

## Phase 7: Transactions — US-ADMINAPP-013/014/015

**Goal**: Admin/Operations/Finance can search and investigate transactions; Admin/Operations can reverse eligible internal transactions via maker-checker; ineligible types return 422.

**Independent Test**: Search returns paginated list with `reversal_eligible` flag; investigate returns ledger entries; `POST /v1/admin-app/transactions/{id}/reverse` for `funding` type returns 422 ADMINAPP_015; for `wallet_transfer` returns 202 (SC-5).

- [X] T053 [P] [US13] Create `Modules/AdminApp/Http/Controllers/TransactionController.php` — `index()` (search/filter via E11 TransactionService), `show()` (with ledger entries + fraud assessment), `reverse()` (type eligibility check + maker: submit to PendingAdminActionService)
- [X] T054 [P] [US13] Create `Modules/AdminApp/Http/Resources/AdminTransactionResource.php` — maps `AdminTransactionSearchResult` view model; `reversal_eligible` computed from type (`wallet_transfer`, `agent_cash_in`, `agent_cash_out` = true; others = false)
- [X] T055 [US13] Wire `GET /v1/admin-app/transactions` + `GET /v1/admin-app/transactions/{id}` → `TransactionController@index/show`; add `adminapp.transactions.read` gate for index (Admin+Operations+Finance), `adminapp.transactions.investigate` for show (Admin+Operations only); show emits `AUDIT_EVENT_RECORDED` (investigation log)
- [X] T056 [US15] Wire `POST /v1/admin-app/transactions/{id}/reverse` → `TransactionController@reverse`; add `adminapp.transactions.reverse` gate + `RequireIdempotencyKey`; enforce eligibility: return `422 ADMINAPP_015` for ineligible types; for eligible types call `PendingAdminActionService::submit('adminapp.transaction.reverse', payload)` → 202
- [X] T057 [P] [US13] Enhance `wallet-ecosystem/apps/admin/app/(admin)/admin-portal/transactions/page.tsx` — add `reversal_eligible` badge, fraud flag indicators, and "Investigate" + "Reverse" action buttons in transaction detail drawer
- [X] T058 [P] [US15] Add reversal confirmation component in transaction detail — shows eligibility reason if ineligible, or maker-checker submission dialog with reason field if eligible
- [X] T059 Create `wallet-ecosystem/services/api/tests/Feature/AdminApp/TransactionTest.php` — SC-5: search returns results, investigate emits audit, reverse funding returns 422 ADMINAPP_015, reverse wallet_transfer returns 202, already-reversed returns 422 ADMINAPP_TXN_002

**Checkpoint**: Transaction search, investigation, and conditional reversal fully operational.

---

## Phase 8: Agents & Float — US-ADMINAPP-016/017/018

**Goal**: Admin/Operations can list/view/suspend agents and approve pending agents via maker-checker; Admin/Operations/Finance can view and adjust agent float balances.

**Independent Test**: `GET /v1/admin-app/agents?status=pending_approval` returns list; `POST /v1/admin-app/agents/{id}/approve` returns 202; checker approves → agent is active; `POST /v1/admin-app/float/{id}/adjust` with over-limit amount returns 422 ADMINAPP_FLOAT_001 (SC-6).

- [X] T060 [P] [US17] Create `Modules/AdminApp/Http/Controllers/AgentController.php` — `index()` (search/filter via E12 AgentService), `show()` (single agent with float info), `approve()` (maker: PendingAdminActionService), `updateStatus()` (suspend/reactivate via E12 AgentService)
- [X] T061 [P] [US17] Create `Modules/AdminApp/Http/Resources/AdminAgentResource.php` — maps `AdminAgentProfile` view model with float balance and commission rate
- [X] T062 [US17] Wire `GET /v1/admin-app/agents` + `GET /v1/admin-app/agents/{id}` → `AgentController@index/show`; add `adminapp.agents.manage` gate
- [X] T063 [US16] Wire `POST /v1/admin-app/agents/{id}/approve` → `AgentController@approve`; add `adminapp.agents.approve` gate + `RequireIdempotencyKey`; validate `pending_approval` state (return `422 ADMINAPP_AGENT_002` if not); submit to PendingAdminActionService → 202; emit `AUDIT_EVENT_RECORDED`
- [X] T064 [US17] Wire `PATCH /v1/admin-app/agents/{id}/status` → `AgentController@updateStatus`; add `adminapp.agents.manage` gate + `RequireIdempotencyKey`; emit `AUDIT_EVENT_RECORDED`
- [X] T065 [P] [US18] Add float methods to `Modules/AdminApp/Http/Controllers/AgentController.php` — `floatIndex()` (list float balances via E13 AgentFloatService) + `floatAdjust()` (credit/debit via E13 AgentFloatService; validate limit; return `422 ADMINAPP_FLOAT_001` if limit exceeded)
- [X] T066 [US18] Wire `GET /v1/admin-app/float` + `POST /v1/admin-app/float/{id}/adjust` in `routes/api.php`; add `adminapp.float.manage` gate (Admin+Finance only — Operations excluded per spec role matrix); `RequireIdempotencyKey` on POST; emit `AUDIT_EVENT_RECORDED` on adjust
- [X] T067 [P] [US16] Create `wallet-ecosystem/apps/admin/src/modules/agents/api/agents.ts` — `listAgents()`, `getAgent()`, `approveAgent()`, `updateAgentStatus()`, `listFloat()`, `adjustFloat()`
- [X] T068 [P] [US16] Create `wallet-ecosystem/apps/admin/app/(admin)/agents/page.tsx` — agent management list with status filter, pending approval badge, float balance column; "Approve" action for pending agents
- [X] T069 [P] [US17] Create `wallet-ecosystem/apps/admin/app/(admin)/agents/[id]/page.tsx` — agent detail with float info, commission rate, transaction history summary, suspend/reactivate action
- [X] T070 [P] [US18] Create `wallet-ecosystem/apps/admin/src/modules/float/api/float.ts` — float API client; create `wallet-ecosystem/apps/admin/app/(admin)/float/page.tsx` — float management table with utilization bars, top-up form, last-top-up timestamp
- [X] T071 Create `wallet-ecosystem/services/api/tests/Feature/AdminApp/AgentTest.php` — SC-6: list pending agents, approve returns 202, non-pending returns 422, checker approves agent is active, float over-limit returns 422, suspend creates audit entry; idempotent float adjust replay with different payload returns 409 ADMINAPP_IDEM_001; Operations role denied float endpoints (Admin+Finance only)

**Checkpoint**: Agent approval, management, and float management all functional.

---

## Phase 9: Reconciliation & Settlement — US-ADMINAPP-019/020

**Goal**: Admin/Finance can view reconciliation batches (with unmatched detail) and settlement records.

**Independent Test**: `GET /v1/admin-app/reconciliation` returns paginated list; `GET /v1/admin-app/reconciliation/{id}` returns batch with `unmatched_transaction_ids`; Finance role returns 200; Operations role returns 403.

- [X] T072 [P] [US19] Create `Modules/AdminApp/Http/Controllers/ReconciliationController.php` — `index()` (list batches via E20 ReconciliationService), `show()` (single batch with unmatched IDs), `settlementIndex()` (list settlements via E20), `settlementShow()` (single settlement)
- [X] T073 [P] [US19] Create `Modules/AdminApp/Http/Resources/ReconciliationResource.php` + `ReconciliationDetailResource.php` (includes `unmatched_transaction_ids`) mapping `ReconciliationSummary` view model
- [X] T074 [P] [US20] Create `Modules/AdminApp/Http/Resources/SettlementResource.php` mapping `SettlementSummary` view model
- [X] T075 [US19] Wire `GET /v1/admin-app/reconciliation` + `GET /v1/admin-app/reconciliation/{id}` → `ReconciliationController@index/show`; add `adminapp.reconciliation.read` gate
- [X] T076 [US20] Wire `GET /v1/admin-app/settlement` + `GET /v1/admin-app/settlement/{id}` → `ReconciliationController@settlementIndex/settlementShow`; add `adminapp.settlement.read` gate
- [X] T077 [P] [US19] Create `wallet-ecosystem/apps/admin/app/(admin)/reports/reconciliation/page.tsx` — render reconciliation batch list with matched/unmatched counts, discrepancy amount; link to detail view
- [X] T078 [P] [US20] Create `wallet-ecosystem/apps/admin/app/(admin)/reports/settlement/page.tsx` — settlement list with net position, status badge, provider info
- [X] T079 Create `wallet-ecosystem/services/api/tests/Feature/AdminApp/ReconciliationTest.php` — list batches, show detail includes unmatched IDs, settlement list/show, Operations role denied

**Checkpoint**: Reconciliation and settlement read views functional.

---

## Phase 10: Compliance — US-ADMINAPP-021/022

**Goal**: Admin/Compliance can query the immutable audit log with date-range filtering (max 90 days); Admin/Finance/Compliance can request, poll, and download reports.

**Independent Test**: `GET /v1/admin-app/audit` returns events; 91-day range returns 400 ADMINAPP_AUDIT_001; Operations denied; `POST /v1/admin-app/reports` returns 202 with `report_id`; `GET /v1/admin-app/reports/{id}` returns `download_url` when ready; idempotent replay returns same report_id (SC-7, SC-8).

- [X] T080 [P] [US21] Create `Modules/AdminApp/Http/Controllers/ComplianceController.php` — `auditIndex()` (read-only query of E23 AuditService; validate date range ≤ 90 days; return `400 ADMINAPP_AUDIT_001` if exceeded), `reportIndex()` + `reportCreate()` + `reportShow()` (delegate to E24 ReportService)
- [X] T081 [P] [US21] Create `Modules/AdminApp/Http/Requests/AuditIndexRequest.php` — validates `from_date`, `to_date` (both required together; max 90-day span)
- [X] T082 [P] [US22] Create `Modules/AdminApp/Http/Requests/ReportCreateRequest.php` — validates `report_type` (enum), `period_start`, `period_end`, `format` (enum)
- [X] T083 [US21] Wire `GET /v1/admin-app/audit` → `ComplianceController@auditIndex`; add `adminapp.audit.read` gate; validate 90-day range
- [X] T084 [US22] Wire `GET /v1/admin-app/reports`, `POST /v1/admin-app/reports`, `GET /v1/admin-app/reports/{id}` → `ComplianceController@reportIndex/reportCreate/reportShow`; add `adminapp.reports.read` gate; `RequireIdempotencyKey` on POST; POST returns 202 with `report_id`
- [X] T085 [P] [US21] Enhance existing `wallet-ecosystem/apps/admin/app/(admin)/audit/page.tsx` — add date-range filter (with 90-day max validation on frontend), event-type filter, actor/subject filters; render `AuditEventEntry` rows
- [X] T086 [P] [US22] Enhance existing `wallet-ecosystem/apps/admin/app/(admin)/reports/page.tsx` — add report request form (type, period, format), pending/generating/ready status display, download button (opens signed URL); polling for status
- [X] T087 Create `wallet-ecosystem/services/api/tests/Feature/AdminApp/ComplianceTest.php` — SC-7+SC-8: audit query returns events, 91-day range returns 400, Operations denied; report POST returns 202, status polling, idempotent replay returns same report_id

**Checkpoint**: Audit log browsing and report request/download fully operational.

---

## Phase 11: Polish & Cross-Cutting Concerns

**Purpose**: OpenAPI documentation, PHPStan static analysis, accessibility review, quickstart validation, OpenAPI contract completeness, domain event wiring, and concurrency tests.

- [X] T088 Create `wallet-ecosystem/services/api/openapi/v1/admin-app.yaml` — OpenAPI 3.1 spec for all 34 `/v1/admin-app/` endpoints; include all request/response schemas, error codes, security scheme, and permission slugs as x-permissions extension
- [x] T089 [P] Run PHPStan level 8 on `app/Modules/AdminApp/` and fix all errors: `vendor/bin/phpstan analyse app/Modules/AdminApp --level=8` (Docker-gated)
- [X] T090 [P] Run quickstart.md SC-1 through SC-9 validation scenarios against the running Docker stack; document any failures as issues (Docker-gated)
- [X] T091 [P] Add accessibility review for new Next.js pages (`/kyc`, `/agents`, `/float`, `/financial-dashboard`, `/risk-dashboard`) — verify ARIA labels, keyboard navigation, focus management per E33 backlog item P11 from `project_e21_backlog_from_e20.md`
- [X] T092 [P] Verify all 22 stories emit `AUDIT_EVENT_RECORDED` — grep `AuditService::record` call in each controller method; add any missing calls
- [X] T093 [P] Add `X-Correlation-ID` to all AdminApp controller responses by registering `AddCorrelationIdHeader` response middleware in AdminApp route group
- [X] T094 [P] Write `wallet-ecosystem/services/api/tests/Feature/AdminApp/RoleAccessMatrixTest.php` — automated spot-check of SC-9 role matrix (Finance denied operational dashboard, Operations denied KYC, etc.)
- [X] T095 [P] Write feature test `wallet-ecosystem/services/api/tests/Feature/AdminApp/IdempotencyTest.php` — verify replay of all 8 write endpoints returns same response without duplicate domain effects
- [X] T096 [P] Update `wallet-ecosystem/services/api/database/seeders/DatabaseSeeder.php` to include `AdminAppPermissionsSeeder`
- [X] T097 Add E33 module to module registry if a centralized registry pattern is used — no-op confirmed; no `modules.php` registry exists in the codebase
- [X] T099 [P] Verify `wallet-ecosystem/apps/admin/app/(admin)/reports/reconciliation/` and `app/(admin)/reports/settlement/` paths exist; if absent, create stub page.tsx files following the E19/E20 frontend pattern before executing T077/T078 (G1)
- [X] T100 [P] Wire `AdminActionRequestedEvent`, `AdminActionApprovedEvent`, `AdminActionRejectedEvent` domain events in `PendingAdminActionService` using the existing outbox/dispatch pattern from E09/E22; extend `BaseIntegrationEvent` (C1 — Constitution Principle 9)
- [X] T101 [P] Write `wallet-ecosystem/services/api/tests/Feature/AdminApp/ConcurrencyTest.php` — (a) two simultaneous `POST /v1/admin-app/float/{id}/adjust` requests on the same agent; verify only one wins and balance equals single-application; (b) two simultaneous `POST /v1/admin-app/wallets/{id}/freeze` requests on the same wallet; verify idempotent result with no state corruption (C2 — Constitution Principle 4)
- [X] T098 Run `/speckit-analyze` and `/speckit-converge` after implementation complete; address any HIGH/CRITICAL findings before marking E33 RELEASE READY

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 complete
- **User Stories (Phases 3–10)**: All depend on Phase 2 complete; can run in parallel by story group once Phase 2 is done
- **Polish (Phase 11)**: Depends on Phases 3–10 complete

### User Story Dependencies

All stories share the AdminApp module scaffold (Phase 1+2). Within story groups:

- **Dashboards (US1/2/3)**: Parallel — each dashboard is independent; RefreshDashboardCacheCommand is shared but implemented once in T007
- **Users & Roles (US4/5/6)**: US5 (suspend) depends on US4 (search) UI; US6 (roles) is independent
- **KYC (US7/8/9)**: US8/9 depend on US7 (list queue exists); approve/reject share same `KycController` and maker-checker pattern
- **Wallets (US10/11/12)**: US11/12 depend on US10 (search exists); freeze/unfreeze are independent of each other
- **Transactions (US13/14/15)**: US14 (investigate) requires US13 (search); US15 (reverse) requires US13 (search + reversal eligibility)
- **Agents/Float (US16/17/18)**: US16 (approve) depends on US17 (list must exist); US18 (float) is independent
- **Recon/Settlement (US19/20)**: Independent of each other; read-only
- **Compliance (US21/22)**: Independent of each other

### Within Each Phase

- Resources [P] before controllers that use them
- Controllers before route wiring
- Route wiring before tests

---

## Parallel Opportunities

### Phases 3–10 can run in parallel once Phase 2 is done:

```
After T016:
  ├── Phase 3 (Dashboards: T017–T027)
  ├── Phase 4 (Users: T028–T036)        <- parallel
  ├── Phase 5 (KYC: T037–T045)          <- parallel
  ├── Phase 6 (Wallets: T046–T052)      <- parallel
  ├── Phase 7 (Transactions: T053–T059) <- parallel
  ├── Phase 8 (Agents: T060–T071)       <- parallel
  ├── Phase 9 (Recon: T072–T079)        <- parallel
  └── Phase 10 (Compliance: T080–T087)  <- parallel
```

### Within each phase, [P] tasks can run in parallel:

```bash
# Phase 3 parallel example:
T018 [US1] OperationalDashboardResource
T019 [US2] FinancialDashboardResource
T020 [US3] RiskDashboardResource
T024 [US1] Dashboard UI page enhancement
T025 [US2] Financial dashboard page
T026 [US3] Risk dashboard page
```

---

## Implementation Strategy

### MVP: Dashboards Only (US-001/002/003)

1. Complete Phase 1 (Setup)
2. Complete Phase 2 (Foundational)
3. Complete Phase 3 (Dashboards)
4. **STOP and VALIDATE**: All 3 dashboards live, role access enforced, cache warm

### Incremental Delivery (suggested order)

1. Phase 1+2 → Module scaffolded
2. Phase 3 → Dashboards live (SC-1 passes)
3. Phase 4 → User management (SC-2 passes)
4. Phase 5 → KYC review (SC-3 passes)
5. Phase 6 → Wallet freeze/unfreeze (SC-4 passes)
6. Phase 7 → Transaction reversal (SC-5 passes)
7. Phase 8 → Agent approval + float (SC-6 passes)
8. Phase 9 → Recon/settlement visible
9. Phase 10 → Audit + reports (SC-7, SC-8 pass)
10. Phase 11 → SC-9 role matrix passes; PHPStan clean; OpenAPI complete

---

## Phase 12: Convergence

**Purpose**: Remediate gaps found by `/speckit-converge` — three missing frontend features (user detail + suspend/reactivate, wallet freeze/unfreeze, transaction reversal) and two partial items (KYC tier filter, module API file structure).

- [X] T102 [US5] Create `wallet-ecosystem/apps/admin/app/(admin)/admin-portal/users/[id]/page.tsx` — user detail route page that imports a `UserDetailPage` component; fix `UserRow.onSelect` to navigate to this route; implement `UserDetailPage` in `src/modules/admin-portal/pages/user-detail.tsx` with profile display, suspend/reactivate action buttons, confirmation dialog with reason field, PATCH call to `/v1/admin-app/users/{id}/suspend` or `/reactivate` with `Idempotency-Key` header, and role-gate on `adminapp.users.write` per US-ADMINAPP-005 (missing)
- [X] T103 [US11] [US12] Add freeze/unfreeze action buttons to `wallet-ecosystem/apps/admin/src/modules/admin-portal/pages/wallet-management.tsx` — add an Actions column to `WalletRow`; show "Freeze" when status is active and "Unfreeze" when status is frozen; on click, open a confirmation dialog with reason field and POST to `/v1/admin-app/wallets/{wallet_id}/freeze` or `/unfreeze` with `Idempotency-Key` header; gate on `adminapp.wallets.freeze` / `adminapp.wallets.unfreeze` per US-ADMINAPP-011/012 (missing)
- [X] T104 [US15] Add `reversal_eligible` badge and Reverse action to `wallet-ecosystem/apps/admin/src/modules/admin-portal/pages/transaction-management.tsx` — extend `AdminTransaction` type with `reversal_eligible: boolean`; render an "ELIGIBLE" badge in `TransactionRow` when true; add an Actions column with a "Reverse" button that opens a confirmation dialog with reason field; POST to `/v1/admin-app/transactions/{transaction_id}/reverse` with `Idempotency-Key` header; gate on `adminapp.transactions.reverse` per US-ADMINAPP-015 (missing)
- [X] T105 [P] [US4] Add KYC tier filter dropdown to `wallet-ecosystem/apps/admin/src/modules/admin-portal/pages/user-management.tsx` — add a `kycTier` state variable and a `<select>` with options (All, Tier 0, Tier 1, Tier 2, Tier 3); pass `kyc_tier` param to `searchAdminUsers` when a tier is selected per US-ADMINAPP-004 (partial)
- [X] T106 [P] Extract KYC, agents, and float API wrapper functions from `wallet-ecosystem/apps/admin/src/modules/admin-portal/api/admin-portal.ts` into `src/modules/kyc/api/kyc.ts`, `src/modules/agents/api/agents.ts`, and `src/modules/float/api/float.ts`; update import paths in their respective page components; or document this consolidation as an accepted deviation in plan.md per plan module API file structure (partial)

**Checkpoint**: All 22 US-ADMINAPP stories have complete frontend interaction paths; users can suspend/reactivate, freeze/unfreeze, and reverse transactions from the UI.

---

## Notes

- All 6 maker-checker stories (008/009/011/012/015/016) use the same `PendingAdminActionService::submit()` pattern registered in Phase 1 (T006)
- `RequireIdempotencyKey` middleware (T012) is applied to ALL POST/PATCH write routes; tests must verify idempotent replay
- Phone numbers are masked in list responses (`+267 *** *** 789`) and unmasked in single-user detail view; this masking must be applied in `AdminUserResource` not in the domain service
- `RefreshDashboardCacheCommand` (T007) aggregates across multiple domain services — it must be defensive: if one domain service call fails, log the error and write partial data rather than crashing the entire refresh
- The spec explicitly requires `AUDIT_EVENT_RECORDED` on all 22 stories — T092 audits this systematically
- T089 and T090 are Docker-gated and require the running dev stack to execute
- T098 (speckit-analyze + speckit-converge) is the final gate before RELEASE READY
