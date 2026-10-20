# Tasks: E19 â€” Admin Portal

**Feature directory**: `specs/019-admin-portal/`
**Backend module path**: `wallet-ecosystem/services/api/app/Modules/Admin/`
**Migrations path**: `wallet-ecosystem/services/api/app/Modules/Admin/Infrastructure/Migrations/`
**Tests path**: `wallet-ecosystem/services/api/tests/`
**Frontend path**: `wallet-ecosystem/apps/admin/`

---

## Phase 1: Setup

**Purpose**: Scaffold the Admin module skeleton and frontend entry points.

- [X] T001 Create module directory tree: `app/Modules/Admin/{Domain/{Contracts,Enums,Models},Application/{Services,Jobs},Infrastructure/{Http/{Controllers,Requests,Resources},Migrations,Providers},routes}`
- [X] T002 Create `app/Modules/Admin/Infrastructure/Providers/AdminServiceProvider.php` â€” extend `Illuminate\Support\ServiceProvider`; bind `AdminMetricsInterface` to `AdminMetricsService`; register module routes from `routes/admin.php`
- [X] T003 Register `AdminServiceProvider` in `bootstrap/providers.php` (Laravel 11+ providers file)
- [X] T004 [P] Create `app/Modules/Admin/routes/admin.php` â€” define route stubs for all 29 admin endpoints (see contracts/); prefix `/v1/admin`; apply `identity.auth` middleware group
- [X] T005 [P] Create `database/seeders/AdminPermissionSeeder.php` â€” seed permissions: `admin.read`, `admin.write`, `admin.freeze_wallet`, `admin.approve_adjustment`, `admin.reverse_transaction`, `admin.refund_transaction`, `admin.export_transactions`; assign to admin-officer role via E04 permission infrastructure; seed two test admin users for local/testing environments (admin@olekard.local, checker@olekard.local)
- [X] T006 [P] Add `admin` queue to `config/horizon.php` worker configuration
- [X] T007 [P] Verify cross-module contracts exist and are importable: `grep -r 'class UserService' app/Modules/Identity && grep -r 'interface WalletModuleContract' app/ && grep -r 'class LedgerService' app/Modules/Ledger && grep -r 'interface TransactionModuleContract' app/ && grep -r 'class AccountHoldService' app/Modules/Risk` â€” if any are absent, raise a blocker and halt Phase 3; do NOT create TODO comments in production code

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Enums, domain contract, migrations, and models that every story phase depends on. No story phase can begin until this phase is complete.

### Enums (parallelizable)

- [X] T008 [P] Create `app/Modules/Admin/Domain/Enums/AdminActionType.php` â€” backed string enum; cases: `SuspendUser`, `ReactivateUser`, `ResetAuth`, `TerminateSession`, `FreezeWallet`, `UnfreezeWallet`, `WalletAdjustmentCreated`, `WalletAdjustmentApproved`, `WalletAdjustmentRejected`, `TransactionReversed`, `TransactionRefunded`, `TransactionsExported`
- [X] T009 [P] Create `app/Modules/Admin/Domain/Enums/AdjustmentStatus.php` â€” backed string enum; cases: `PendingApproval`, `Approved`, `Rejected`, `Posted`; add `isTerminal(): bool` helper (returns true for Approvedâ†’no, Rejectedâ†’yes, Postedâ†’yes)

### Domain Contract (parallelizable)

- [X] T010 [P] Create `app/Modules/Admin/Domain/Contracts/AdminMetricsInterface.php` â€” methods: `userAction(string $actionType): void`, `walletAction(string $actionType): void`, `adjustmentTotal(string $status): void`, `transactionAction(string $actionType): void`, `exportRows(int $count, string $format): void`, `dashboardLoad(int $ms, bool $cached): void`

### Migrations (parallelizable â€” different files)

- [X] T011 [P] Create migration `app/Modules/Admin/Infrastructure/Migrations/2026_09_26_000001_create_admin_actions_table.php` â€” schema `admin`; columns: `id` UUID PK, `action_type` VARCHAR(50) NOT NULL, `actor_id` UUID NOT NULL, `subject_type` VARCHAR(30) NOT NULL, `subject_id` UUID NOT NULL, `metadata` JSONB DEFAULT '{}', `reason` TEXT, `ip_address` INET, `user_agent` TEXT, `correlation_id` VARCHAR(128), `performed_at` TIMESTAMPTZ NOT NULL DEFAULT NOW(); indexes: `(actor_id, performed_at DESC)`, `(subject_type, subject_id, performed_at DESC)`, `(action_type, performed_at DESC)`; no updated_at (append-only)
- [X] T012 [P] Create migration `app/Modules/Admin/Infrastructure/Migrations/2026_09_26_000002_create_admin_wallet_adjustments_table.php` â€” schema `admin`; columns per data-model.md; UNIQUE `idempotency_key`; CHECK `adjustment_status IN ('pending_approval','approved','rejected','posted')`; CHECK `maker_id != checker_id` OR `checker_id IS NULL`; CHECK `amount > 0`; indexes: `(wallet_id, status, created_at DESC)`, `(maker_id, created_at DESC)`, `(status, created_at DESC)`

### Eloquent Models (parallelizable)

- [X] T013 [P] Create `app/Modules/Admin/Domain/Models/AdminAction.php` â€” `protected $connection = 'admin'`; `protected $table = 'admin.admin_actions'`; cast `action_type` â†’ `AdminActionType`; boot: override `updating` and `deleting` to `throw new \RuntimeException('AdminAction is append-only')` (constitution P4)
- [X] T014 [P] Create `app/Modules/Admin/Domain/Models/AdminWalletAdjustment.php` â€” `protected $connection = 'admin'`; cast `status` â†’ `AdjustmentStatus`; scope `pendingApproval()`, `posted()`; fillable: idempotency_key, wallet_id, adjustment_type, amount, currency, reason, status, maker_id

### Metrics Service

- [X] T015 Create `app/Modules/Admin/Application/Services/AdminMetricsService.php` implementing `AdminMetricsInterface` â€” emit StatsD/Prometheus counters per metric method; bind in AdminServiceProvider

**Checkpoint**: Phase 2 complete â†’ 2 tables exist, models loadable, enums and interface defined. Run `php artisan migrate` and verify no errors before proceeding.

---

## Phase 3: US-ADMIN-001â€“008 â€” Dashboard & Statistics (P0) ðŸŽ¯ MVP

**Goal**: Deliver the Admin Dashboard showing all 7 KPI groups (users, wallets, transactions, failures, KYC, agents, risk) aggregated from domain modules with Redis caching.

**Independent Test (SC-1 from quickstart.md)**: GET `/v1/admin/dashboard?period=today` returns HTTP 200 with all 7 KPI sections populated and `X-Stats-Age` header present.

- [X] T016 [US1] Implement `app/Modules/Admin/Application/Services/DashboardService.php` â€” inject: WalletModuleContract (E06), TransactionModuleContract (E11), KycModuleContract (E05), AgentModuleContract (E12), AccountHoldService (E18), Redis; methods: `getDashboard(string $period): array` (orchestrates all sub-methods), `getUserStats(string $period): array`, `getWalletStats(): array`, `getTransactionStats(string $period): array`, `getFailureStats(string $period): array`, `getKycStats(): array`, `getAgentStats(string $period): array`, `getRiskStats(): array`; each method: check Redis cache key `admin:dashboard:{method}:{period}` â†’ on miss execute cross-module query â†’ set cache TTL 300s; return `['data' => ..., 'cached_at' => ..., 'cache_age_seconds' => int]`
- [X] T017 [P] [US1] Create `app/Modules/Admin/Infrastructure/Http/Resources/DashboardResource.php` â€” wraps the full dashboard array response with `data` key; create `UserStatsResource.php`, `WalletStatsResource.php`, `TransactionStatsResource.php`, `FailureStatsResource.php`, `KycStatsResource.php`, `AgentStatsResource.php`, `RiskStatsResource.php` â€” one resource per dashboard sub-endpoint
- [X] T018 [US1] Implement `app/Modules/Admin/Infrastructure/Http/Controllers/AdminDashboardController.php` â€” inject DashboardService, AdminMetricsInterface; methods: `index()`, `users()`, `wallets()`, `transactions()`, `failures()`, `kyc()`, `agents()`, `risk()`; require `admin.read` permission on all; set `X-Stats-Age: {cache_age_seconds}` response header; emit `dashboardLoad` metric; thin controller â€” delegate all logic to DashboardService
- [X] T019 [US1] Write integration tests `tests/Feature/Modules/Admin/DashboardTest.php` â€” all 7 KPI sections present in `/dashboard`; X-Stats-Age = 0 on first request, > 0 on second within 5 min; 401 without token; 403 with customer token; individual sub-endpoints return correct schema

**Checkpoint**: `GET /v1/admin/dashboard` returns correct KPI data. SC-1 passes.

---

## Phase 4: US-ADMIN-009â€“014 â€” User Management (P0/P1)

**Goal**: Allow admins to search users, view profiles, suspend, reactivate, reset authentication, and view/terminate sessions.

**Independent Test (SC-2/SC-3 from quickstart.md)**: Search for a user â†’ suspend â†’ attempt duplicate suspend â†’ 409; reactivate â†’ user status = active.

- [X] T020 [US9] Implement `app/Modules/Admin/Application/Services/UserAdminService.php` â€” inject: UserService (E01), UserProfileModuleContract (E02), DeviceSessionService (E03), KycModuleContract (E05), AuditServiceInterface, AdminMetricsInterface; methods: `searchUsers(array $filters): LengthAwarePaginator` (delegates to E01), `getUserProfile(string $id): array` (merges E01 user + E02 UserProfileModuleContract::getProfile() + E05 KYC status), `suspendUser(string $id, string $reason, string $actorId, string $ip): void` (delegates to E01; creates AdminAction; 409 if already suspended; emits `admin.user.suspended` audit event), `reactivateUser(...)`, `resetUserAuth(...)`, `listSessions(string $userId): array`, `terminateSession(string $userId, string $sessionId, string $reason, string $actorId): void`
- [X] T021 [P] [US9] Create `app/Modules/Admin/Infrastructure/Http/Requests/SearchUsersRequest.php` â€” validate: `q` optional string; `status` in [active, suspended, all]; `kyc_tier` integer 0â€“3; `per_page` max 100; `page` min 1
- [X] T022 [P] [US9] Create `app/Modules/Admin/Infrastructure/Http/Requests/UserActionRequest.php` â€” validate: `reason` required string min:10 max:2000
- [X] T023 [P] [US9] Create `app/Modules/Admin/Infrastructure/Http/Resources/UserSummaryResource.php`, `UserDetailResource.php`, `UserActionResource.php`, `SessionSummaryResource.php`
- [X] T024 [US9] Implement `app/Modules/Admin/Infrastructure/Http/Controllers/AdminUserController.php` â€” methods: `list()`, `show()`, `suspend()`, `reactivate()`, `resetAuth()`; require `admin.read` for list/show; `admin.write` for suspend/reactivate/resetAuth; pass `$request->ip()` and `$request->userAgent()` to service for audit metadata
- [X] T024a [US14] Add `sessions()` and `terminateSession()` to `AdminUserController` â€” `sessions()`: require `admin.read`; delegate to UserAdminService::listSessions(); return `SessionSummaryResource` collection; `terminateSession()`: require `admin.write`; delegate to UserAdminService::terminateSession(); pass ip and userAgent for audit; 409 if session already revoked; 404 if session not found for this user
- [X] T025 [US9] Write integration tests `tests/Feature/Modules/Admin/UserManagementTest.php` â€” SC-2: search â†’ suspend â†’ 409 duplicate â†’ reactivate; SC-3: reset-auth invalidates sessions; view sessions (US14) before terminate â†’ count N; terminate â†’ count N-1; terminate without admin.write â†’ 403; 404 for unknown user

**Checkpoint**: User management CRUD and lifecycle transitions functional. SC-2 and SC-3 pass.

---

## Phase 5: US-ADMIN-015â€“019 â€” Wallet Read Operations + Freeze/Unfreeze (P0)

**Goal**: Allow admins to search wallets, view balances, browse ledger history, and freeze/unfreeze wallets.

**Independent Test (SC-4/SC-5 from quickstart.md)**: Search wallet â†’ view balance â†’ view ledger entries; freeze wallet â†’ evaluate-transaction-risk returns block; unfreeze â†’ evaluate returns allow.

- [X] T026 [US15] Implement `app/Modules/Admin/Application/Services/WalletAdminService.php` â€” inject: WalletModuleContract (E06), LedgerService (E07), AccountHoldService (E18), AuditServiceInterface, AdminMetricsInterface; methods: `searchWallets(array $filters): LengthAwarePaginator`, `getWallet(string $id): array`, `getWalletLedger(string $id, array $filters): LengthAwarePaginator`, `freezeWallet(string $walletId, string $reason, string $makerId, string $checkerId, string $holdType, string $ip): AccountHold` (delegates to E18 AccountHoldService::placeHold(); records AdminAction; emits `admin.wallet.frozen`; 409 if already frozen), `unfreezeWallet(string $walletId, string $reason, string $actorId, string $ip): void` (delegates to E18 AccountHoldService::liftHold(); records AdminAction; emits `admin.wallet.unfrozen`; 409 if not frozen)
- [X] T027 [P] [US15] Create `app/Modules/Admin/Infrastructure/Http/Requests/SearchWalletsRequest.php`, `FreezeWalletRequest.php` (validate: reason, checker_id UUID, hold_type in [risk_freeze, compliance_hold, aml_investigation]), `UnfreezeWalletRequest.php`, `WalletLedgerRequest.php` (from/to dates, entry_type, per_page, page)
- [X] T028 [P] [US15] Create `app/Modules/Admin/Infrastructure/Http/Resources/WalletSummaryResource.php`, `WalletDetailResource.php` (include account_hold if frozen), `LedgerEntryResource.php`, `WalletActionResource.php`
- [X] T029 [US15] Implement `app/Modules/Admin/Infrastructure/Http/Controllers/AdminWalletController.php` â€” methods: `list()`, `show()`, `freeze()`, `unfreeze()`, `ledger()`; require `admin.read` for list/show/ledger; `admin.write` + `admin.freeze_wallet` for freeze/unfreeze; validate `checker_id` â‰  authenticated admin ID in FreezeWalletRequest or controller guard (422 if same)
- [X] T030 [US15] Write integration tests `tests/Feature/Modules/Admin/WalletManagementTest.php` â€” SC-4: search + view + ledger; SC-5: freeze â†’ subsequent evaluate-risk â†’ block; 409 duplicate freeze; unfreeze â†’ evaluate â†’ allow; 403 without admin.freeze_wallet

**Checkpoint**: Wallet lifecycle operations functional. SC-4 and SC-5 pass.

---

## Phase 6: US-ADMIN-020â€“021 â€” Wallet Adjustment Maker-Checker (P0)

**Goal**: Allow authorized admins (maker) to create controlled wallet balance adjustments, and a different admin (checker) to approve or reject them, with double-entry journal posting on approval.

**Independent Test (SC-6 from quickstart.md)**: Maker creates adjustment â†’ same maker approve fails 422 â†’ checker approves â†’ status=posted; ledger entry type=admin_adjustment visible; idempotent re-create returns same record.

- [X] T031 [US20] Implement `app/Modules/Admin/Application/Services/AdminWalletAdjustmentService.php` â€” inject: LedgerService (E07), AuditServiceInterface, AdminMetricsInterface; methods: `createAdjustment(array $data, string $makerId, string $ip): AdminWalletAdjustment` (check UNIQUE idempotency_key â†’ if exists return existing; create AdminWalletAdjustment status=pending_approval; record AdminAction WalletAdjustmentCreated; emit `admin.wallet.adjustment.created`); `approveAdjustment(string $adjId, string $checkerId, string $reason, string $ip): AdminWalletAdjustment` â€” wrap entire body in `DB::transaction()`: (1) `lockForUpdate()` the adjustment record; (2) return 409 if status â‰  `pending_approval`; (3) return 422 if `checkerId == makerId`; (4) persist `status=approved`, `approved_at`, `checker_id` to DB (intermediate state per data-model.md state machine â€” allows rollback recovery); (5) call `postToLedger()` â€” if E07 throws, outer `DB::transaction()` rolls back and status reverts to `pending_approval` (partial outcome prohibited; constitution P4); (6) persist `status=posted`, `posted_at`, `ledger_transaction_id`; (7) emit `admin.wallet.adjustment.posted`; `rejectAdjustment(string $adjId, string $checkerId, string $reason, string $ip): AdminWalletAdjustment` (lockForUpdate; 409 if not pending_approval; 422 if maker==checker; set status=rejected, rejected_at, checker_id, rejection_reason; emit rejected); `postToLedger(AdminWalletAdjustment $adj): void` (call LedgerService::postJournal() with double-entry: credit adj = DR admin.suspense / CR wallet.{id}.available; debit adj = DR wallet.{id}.available / CR admin.suspense; entry_type='admin_adjustment'; return ledger_transaction_id â€” called inside the outer DB::transaction)
- [X] T032 [P] [US20] Create `app/Modules/Admin/Infrastructure/Http/Requests/CreateAdjustmentRequest.php` â€” validate: idempotency_key required max:128, adjustment_type in [credit, debit], amount positive decimal string, currency BWP, reason min:10 max:2000
- [X] T033 [P] [US20] Create `app/Modules/Admin/Infrastructure/Http/Requests/ApproveAdjustmentRequest.php`, `RejectAdjustmentRequest.php` â€” validate: reason required min:10 max:2000
- [X] T034 [P] [US20] Create `app/Modules/Admin/Infrastructure/Http/Resources/AdjustmentResource.php`
- [X] T035 [US20] Add to `AdminWalletController`: `createAdjustment()`, `approveAdjustment()`, `rejectAdjustment()` â€” require `admin.write` for create; `admin.write` + `admin.approve_adjustment` for approve/reject; route: `POST /v1/admin/wallets/{id}/adjustments`, `POST /v1/admin/adjustments/{id}/approve`, `POST /v1/admin/adjustments/{id}/reject`
- [X] T036 [US20] Write unit tests `tests/Unit/Modules/Admin/Services/AdminWalletAdjustmentServiceTest.php` â€” create â†’ pending_approval; idempotent create returns same record; same maker cannot approve â†’ 422; checker approves â†’ status transitions approvedâ†’posted inside DB::transaction; ledger postJournal called with correct double-entry; postToLedger throws â†’ DB::transaction rolls back â†’ adjustment remains pending_approval (partial outcome guard; constitution P4); reject â†’ status=rejected; no ledger on reject; 409 on approve of rejected record
- [X] T037 [US20] Write integration tests `tests/Feature/Modules/Admin/WalletAdjustmentTest.php` â€” SC-6: full maker-checker flow; idempotent create; maker-checker violation; ledger entry visible via wallet ledger endpoint after posting; **concurrent approve**: send two simultaneous HTTP requests approving the same adjustment (use Guzzle async or pcntl_fork); assert exactly one HTTP 200 response with status=posted; assert the other is HTTP 409; assert `admin.admin_wallet_adjustments` has exactly one row with status=posted; assert ledger has exactly one admin_adjustment entry for this wallet (constitution P4 concurrency) _(requires live Docker stack for concurrency assertion)_

**Checkpoint**: Maker-checker adjustment flow complete. SC-6 passes.

---

## Phase 7: US-ADMIN-022â€“025 â€” Transaction Management (P0)

**Goal**: Allow admins to search, view, investigate failed transactions, and reverse completed transactions with compensating ledger entries.

**Independent Test (SC-7/SC-8 from quickstart.md)**: Search transactions â†’ view detail â†’ investigate failed â†’ reverse completed; compensating ledger entries visible; 409 on duplicate reversal.

- [X] T038 [US22] Implement `app/Modules/Admin/Application/Services/TransactionAdminService.php` (search/view/investigate/reverse) â€” inject: TransactionModuleContract (E11), LedgerService (E07), AuditServiceInterface, AdminMetricsInterface; `searchTransactions(array $filters): LengthAwarePaginator` (delegates to E11); `getTransaction(string $id): array` (E11 + include ledger entries + AdminAction history); `investigateFailedTransaction(string $id): array` (E11 detail + error codes + retry history; 422 if tx not failed); `reverseTransaction(string $id, string $reason, string $actorId, string $ip, string $idempotencyKey): array` â€” check AdminAction records for existing `metadata->idempotency_key == $idempotencyKey` for this transaction â†’ if found return existing action array (idempotent replay; no new ledger entry); otherwise: fetch tx; 422 if not reversible type; 409 if tx status already reversed/refunded (and this is a new key, not a replay); post compensating entries via LedgerService::postJournal() entry_type='admin_reversal'; update tx statusâ†’'reversed'; record AdminAction with `metadata.idempotency_key = $idempotencyKey`; emit `admin.transaction.reversed` (constitution P8)
- [X] T039 [P] [US22] Create `app/Modules/Admin/Infrastructure/Http/Requests/SearchTransactionsRequest.php` â€” validate: q optional string, status enum, type enum, from/to dates, min/max amount strings, customer_id/wallet_id UUIDs, per_page max 100
- [X] T040 [P] [US22] Create `app/Modules/Admin/Infrastructure/Http/Requests/TransactionActionRequest.php` â€” validate: reason required min:10 max:2000
- [X] T041 [P] [US22] Create `app/Modules/Admin/Infrastructure/Http/Resources/TransactionSummaryResource.php`, `TransactionDetailResource.php` (include ledger_entries + admin_actions), `InvestigationResource.php`, `TransactionActionResource.php`
- [X] T042 [US22] Implement `app/Modules/Admin/Infrastructure/Http/Controllers/AdminTransactionController.php` (list/show/investigate/reverse) â€” require `admin.read` for list/show/investigate; `admin.write` + `admin.reverse_transaction` for reverse; 422 on non-reversible type; for `reverse()`: require `Idempotency-Key` header (return HTTP 400 with message "Idempotency-Key header is required" if missing); extract `$request->header('Idempotency-Key')` and pass as fifth argument to `TransactionAdminService::reverseTransaction()`
- [X] T043 [US22] Write integration tests `tests/Feature/Modules/Admin/TransactionManagementTest.php` â€” SC-7: search + view + investigate failed; SC-8: reverse transfer â†’ compensating ledger entries; 409 if tx already reversed (new Idempotency-Key); same Idempotency-Key replay â†’ HTTP 200 same response, no new ledger entry (idempotency); missing Idempotency-Key header â†’ HTTP 400; 403 without admin.reverse_transaction

**Checkpoint**: Transaction search/view/investigate/reverse functional. SC-7 and SC-8 pass.

---

## Phase 8: US-ADMIN-026â€“027 â€” Transaction Refund & Export (P1)

**Goal**: Allow admins to refund transactions (new credit entry) and export filtered transaction sets as CSV.

**Independent Test (SC-9 from quickstart.md)**: Refund completed transaction â†’ credit entry posted; export â‰¤10,000 rows â†’ synchronous CSV response.

- [X] T044 [US26] Add `TransactionAdminService::refundTransaction(string $id, string $reason, string $actorId, string $ip, string $idempotencyKey): array` â€” check AdminAction records for existing `metadata->idempotency_key == $idempotencyKey` for this transaction â†’ if found return existing action array (idempotent replay; no new credit posted); otherwise: fetch tx; 409 if tx status already reversed/refunded (new key); post new credit entry via LedgerService::postJournal() (entry_type='admin_refund'; credit customer wallet; debit admin.suspense); update tx statusâ†’'refunded'; record AdminAction with `metadata.idempotency_key = $idempotencyKey`; emit `admin.transaction.refunded`; add `AdminTransactionController::refund()` that requires `Idempotency-Key` header (return HTTP 400 if missing), extracts it, and passes to service; require `admin.write` + `admin.refund_transaction` permission; update route in `routes/admin.php` (constitution P8)
- [X] T045 [US27] Implement `app/Modules/Admin/Application/Services/ExportService.php` â€” inject: TransactionModuleContract (E11), Storage facade, AuditServiceInterface; `exportTransactions(array $filters, string $actorId, string $ip): mixed`; count matching rows from E11; if â‰¤10,000: stream CSV directly (`StreamedResponse`, CSV headers, iterate transactions into CSV rows); if >10,000: create export job record; dispatch `ExportTransactionsJob`; return job_id and poll URL; record AdminAction type=TransactionsExported in both paths
- [X] T046 [US27] Create `app/Modules/Admin/Application/Jobs/ExportTransactionsJob.php` â€” runs on `admin` queue; accepts filters + actorId; queries E11 TransactionModuleContract in 1000-row chunks; writes CSV to `Storage::disk(config('filesystems.admin_exports', 'local'))->put("exports/{$jobId}.csv", ...)` (configurable disk â€” set `admin_exports` to `s3` in production; I3 fix); generates signed URL via `Storage::temporaryUrl()` valid 24h; updates job status in Redis cache key `admin:export:{jobId}` with TTL **90000 seconds (25h)** â€” exceeds 24h URL validity to prevent poll returning 404 while URL remains active (U1 fix); cache value structure: `{status:'queued'|'processing'|'completed'|'failed', download_url:string|null, row_count:int|null, error:string|null, queued_at:ISO8601}`; emits `exportRows` metric
- [X] T047 [P] [US27] Create `app/Modules/Admin/Infrastructure/Http/Requests/ExportRequest.php` â€” validate: status, type, from/to dates, customer_id, wallet_id (all optional)
- [X] T048 [US27] Add `AdminTransactionController::export()` and `exportStatus()` endpoints â€” `export()`: call ExportService; 200 CSV response for sync; 202 JSON with job_id for async; `exportStatus()`: read job status from cache; return download_url when completed; require `admin.read` + `admin.export_transactions` permission
- [X] T049 [US26] Write integration tests `tests/Feature/Modules/Admin/TransactionRefundExportTest.php` â€” refund: HTTP 200; credit ledger entry posted; 409 if tx already refunded (new Idempotency-Key); same Idempotency-Key replay â†’ HTTP 200 same response, no new credit entry (idempotency); missing Idempotency-Key header â†’ HTTP 400; 403 without admin.refund_transaction; export: small set â†’ 200 CSV; Content-Type: text/csv; header row present; large set â†’ 202 + job_id returned; exportStatus poll returns completed with download_url

**Checkpoint**: Refund and export complete. SC-9 passes.

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: Next.js admin portal UI pages, PHPStan, OpenAPI consolidation, seeder, and full quickstart validation.

- [X] T050 [P] Implement Next.js admin dashboard page `wallet-ecosystem/apps/admin/app/(dashboard)/page.tsx` â€” fetch `GET /v1/admin/dashboard`; render `DashboardKpiCard` components for each of 7 KPI sections using shared design tokens; display cache age indicator; auto-refresh every 5 minutes; skeleton loading states
- [X] T051 [P] Implement Next.js user management pages â€” `wallet-ecosystem/apps/admin/app/users/page.tsx` (search form + paginated UserSummaryCard list), `wallet-ecosystem/apps/admin/app/users/[id]/page.tsx` (profile detail + Suspend/Reactivate/Reset-Auth buttons with `UserActionModal` confirmation), `wallet-ecosystem/apps/admin/app/users/[id]/sessions/page.tsx` (session list with Terminate button per session)
- [X] T052 [P] Implement Next.js wallet management pages â€” `wallet-ecosystem/apps/admin/app/wallets/page.tsx` (search + list), `wallet-ecosystem/apps/admin/app/wallets/[id]/page.tsx` (balance detail + `WalletFreezeModal` for freeze with checker_id input + unfreeze), `wallet-ecosystem/apps/admin/app/wallets/[id]/ledger/page.tsx` (date-filtered ledger with pagination), `wallet-ecosystem/apps/admin/app/wallets/[id]/adjustment/page.tsx` (`AdjustmentMakerForm` for maker + `AdjustmentCheckerApproval` panel for checker with approve/reject)
- [X] T053 [P] Implement Next.js transaction management pages â€” `wallet-ecosystem/apps/admin/app/transactions/page.tsx` (search + multi-filter + paginated list), `wallet-ecosystem/apps/admin/app/transactions/[id]/page.tsx` (full detail + ledger entries + Reverse/Refund buttons with reason modal), `TransactionExportButton.tsx` component with progress indicator for async jobs (polls `exportStatus()`)
- [X] T054 [P] Run PHPStan on `app/Modules/Admin/` at level 6: `./vendor/bin/phpstan analyse app/Modules/Admin --level=6`; fix all reported issues before marking complete
- [X] T055 [P] Generate `openapi/v1/admin.yaml` consolidating all 4 contract files (`admin-dashboard.yaml`, `admin-users.yaml`, `admin-wallets.yaml`, `admin-transactions.yaml`) into a single OpenAPI 3.0 document with all 29 endpoints; place alongside `openapi/v1/risk.yaml`
- [X] T056 [P] Create `database/seeders/AdminPortalTestSeeder.php` â€” seed: 2 admin users (admin@olekard.local / Admin123!, checker@olekard.local / Admin123!); 5 test customers with wallets (min 3 transactions each, including 1 failed); guard: first line of `run()` must be `if (! App::isLocal() && ! App::environment('testing')) { return; }` â€” prevents accidental execution in staging/production; register in `DatabaseSeeder::run()`
- [X] T057 [P] Run quickstart.md SC-1 through SC-10 validation against running Docker stack; document pass/fail results _(requires live Docker stack)_

**Checkpoint**: PHPStan clean; all SC-1â€“SC-10 quickstart scenarios pass; Next.js admin portal pages functional.

---

## Dependencies & Execution Order

### Phase Dependencies

| Phase | Depends On | Can Parallelise With |
|-------|-----------|----------------------|
| Phase 1 (Setup) | None | â€” |
| Phase 2 (Foundational) | Phase 1 complete | â€” |
| Phase 3 (Dashboard US1â€“8) | Phase 2 complete | Phases 4â€“8 (if staffed) |
| Phase 4 (User Mgmt US9â€“14) | Phase 2 complete | Phases 3, 5â€“8 |
| Phase 5 (Wallet Read+Freeze US15â€“19) | Phase 2 complete | Phases 3â€“4, 6â€“8 |
| Phase 6 (Wallet Adjustment US20â€“21) | Phase 2 + Phase 5 (WalletAdminService) | Phases 3â€“4, 7â€“8 |
| Phase 7 (Transaction US22â€“25) | Phase 2 complete | Phases 3â€“6 |
| Phase 8 (Refund+Export US26â€“27) | Phase 7 (TransactionAdminService) | Phases 3â€“6 |
| Phase 9 (Polish) | All story phases complete | â€” |

### User Story Dependencies

- **US-ADMIN-001â€“008** (Phase 3): Foundational only â€” MVP deliverable (dashboard without any mutations)
- **US-ADMIN-009â€“013** (Phase 4): Foundational only â€” user lifecycle operations
- **US-ADMIN-014** (Phase 4): Requires Phase 4 (UserAdminService) â€” sessions sub-feature
- **US-ADMIN-015â€“019** (Phase 5): Foundational only â€” wallet reads + freeze/unfreeze
- **US-ADMIN-020â€“021** (Phase 6): Requires Phase 5 (WalletAdminService initialized) â€” adjustment workflow
- **US-ADMIN-022â€“025** (Phase 7): Foundational only â€” transaction reads + reverse
- **US-ADMIN-026â€“027** (Phase 8): Requires Phase 7 (TransactionAdminService initialized) â€” refund, export

### Within Each Phase

1. Service implementation before controller implementation
2. FormRequest classes before controllers using them
3. Resources before controllers returning them
4. Integration tests after implementation complete

---

## Parallel Execution Examples

### Phase 2 (all parallelizable after T011â€“T012 migrations):
```
T008 AdminActionType enum  |  T009 AdjustmentStatus enum  |  T010 AdminMetricsInterface
T011 admin_actions migration  |  T012 admin_wallet_adjustments migration
T013 AdminAction model  |  T014 AdminWalletAdjustment model
```

### Phase 3 (after T016 DashboardService):
```
T017 Dashboard Resources  (parallel with T016 during development)
```

### Phase 6 (after T031 AdminWalletAdjustmentService):
```
T032 CreateAdjustmentRequest  |  T033 ApproveAdjustmentRequest  |  T034 AdjustmentResource
```

### Phase 9 (all independent):
```
T050 Dashboard UI  |  T051 User Mgmt UI  |  T052 Wallet UI  |  T053 Transaction UI
T054 PHPStan  |  T055 OpenAPI  |  T056 Seeder  |  T057 Quickstart
```

---

## Implementation Strategy

### MVP First (Phase 3 â€” Dashboard Only)

1. Complete Phase 1 (Setup) â€” ~30 min
2. Complete Phase 2 (Foundational) â€” ~1.5 hours (parallelizable)
3. Complete Phase 3 (Dashboard) â€” ~2 hours
4. **VALIDATE SC-1**: `GET /v1/admin/dashboard` returns all 7 KPI sections
5. **MVP complete** â€” Admin portal is usable for operational monitoring

### Incremental Delivery

After MVP:
- Phase 4 (User Management) â€” enables user lifecycle operations
- Phase 5 (Wallet Read + Freeze) â€” enables wallet visibility and hold management
- Phase 6 (Wallet Adjustment) â€” enables financial corrections with governance
- Phase 7 (Transaction Read + Reverse) â€” enables transaction operations
- Phase 8 (Refund + Export) â€” completes transaction management
- Phase 9 (Polish) â€” Next.js UI, PHPStan, OpenAPI, production readiness

---

## Notes

- `[P]` = parallelizable (different files, no same-phase dependency)
- `[USN]` maps to US-ADMIN-00N from spec.md
- **API paths**: spec.md API stubs (e.g., `/v1/admin/view-dashboard`) are backlog-template artifacts; always implement the RESTful paths defined in `contracts/` (e.g., `GET /v1/admin/dashboard`, `POST /v1/admin/users/{id}/suspend`)
- **Event naming**: use dot-notation (`admin.user.suspended`, `admin.wallet.frozen`, etc.) per plan.md audit events table; the `US_ADMIN_NNN_REQUESTED` format in spec.md is a backlog-template stub and does not represent implementation event names
- **Idempotency-Key**: required on `reverse` and `refund` endpoints; store key in `AdminAction.metadata.idempotency_key`; on replay return the same response without re-executing the ledger operation (constitution P8)
- All amount comparisons and ledger amounts use BCMATH (`bccomp`, `bcadd`) â€” never float arithmetic
- `AdminAction` is append-only; enforce in model boot (constitution P4)
- Maker-checker enforced BOTH at DB level (CHECK constraint) AND service level (guard before approve)
- `approveAdjustment()` must use `DB::transaction()` wrapping all three steps (approvedâ†’postJournalâ†’posted); if postJournal fails the transaction rolls back to pending_approval (constitution P4 partial outcome guard)
- All state-changing admin endpoints record an `AdminAction` and emit an `admin.*` audit event via AuditServiceInterface
- Export job disk is configurable via `config('filesystems.admin_exports', 'local')` â€” set to `s3` in production for multi-server deployments
- PHPStan must pass (level 6) before release
- Next.js pages use shared design tokens from the design system; no inline styles


