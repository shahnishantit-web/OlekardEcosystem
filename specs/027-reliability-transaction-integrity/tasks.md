# Tasks: E27 — Reliability & Transaction Integrity

**Input**: Design documents from `specs/027-reliability-transaction-integrity/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/reliability-admin-api.yaml ✓, quickstart.md ✓

**Organization**: Tasks grouped by user story for independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: User story label (US1—US5)

---

## Phase 1: Setup

**Purpose**: Foundation files used across all user stories — create these first before any story work begins.

- [x] T001 Create `wallet-ecosystem/services/api/config/reliability.php` with env-var defaults: `transaction_timeout_seconds` (30), `orphan_threshold_minutes` (5), `max_retry_attempts` (3), `retry_base_delay_seconds` (5), `dead_letter_ttl_days` (30), `idempotency_ttl_days` (7), `ledger_scan_interval_minutes` (60)
- [x] T002 [P] Create `HasCorrelationId` trait at `wallet-ecosystem/services/api/app/Modules/Platform/Concerns/HasCorrelationId.php` — adds `public readonly string $correlationId` constructor property and an `injectCorrelationContext(): void` helper that calls `Log::withContext(['correlation_id' => $this->correlationId])`
- [x] T003 [P] Create `OrphanDetectableInterface` at `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Contracts/OrphanDetectableInterface.php` — methods: `findOrphanedTransactionIds(Carbon $threshold): array`, `recoverOrphanedTransaction(string $transactionId, string $correlationId): string` (returns resolution status: 'recovered'|'reversed'|'skipped')
- [x] T004 [P] Create `DeadLetterServiceInterface` at `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Contracts/DeadLetterServiceInterface.php` — methods: `capture(string $operationType, string $sourceId, array $payload, string $failureReason, int $retryCount, array $retryHistory, string $correlationId): DeadLetterItemModel`, `findById(string $id): ?DeadLetterItemModel`, `list(array $filters, int $perPage): LengthAwarePaginator`, `replay(string $id, string $actorId, ?string $note, string $correlationId): DeadLetterItemModel`, `dismiss(string $id, string $actorId, string $reason, string $correlationId): DeadLetterItemModel`, `stats(): array`
- [x] T005 [P] Create `E27ReliabilityPermissionsSeeder` at `wallet-ecosystem/services/api/database/seeders/E27ReliabilityPermissionsSeeder.php` — upserts `operations.dead_letter.manage` permission into `identity.permissions` via `DB::connection('identity')`
- [x] T006 Create `wallet-ecosystem/services/api/routes/modules/reliability.php` — empty skeleton with `identity.auth` + `identity.require-permission:operations.dead_letter.manage` middleware group and placeholder comments for dead-letter + ledger-integrity route registrations; register in `PlatformServiceProvider::boot()` via `loadRoutesFrom(base_path('routes/modules/reliability.php'))`

---

## Phase 2: Foundation — Idempotency Middleware Enhancement

**Purpose**: The idempotency `require` mode is a prerequisite for all of US1 — complete before any financial route audits begin.

- [x] T007 Extend `wallet-ecosystem/services/api/app/Http/Middleware/IdempotencyMiddleware.php` — add optional `bool $requireKey = false` constructor param (set via middleware alias). When `$requireKey = true` and no `Idempotency-Key` header is present, return HTTP 422 with body `{'success':false,'error':{'code':'IDEMPOTENCY_KEY_REQUIRED','message':'Idempotency-Key header is required for this operation.'}}`
- [x] T008 Register `idempotency:require` middleware alias in `wallet-ecosystem/services/api/bootstrap/app.php` (or `app/Providers/AppServiceProvider.php`) — alias maps to `IdempotencyMiddleware::class` instantiated with `requireKey: true`; verify existing `idempotency` alias remains unchanged (opt-in mode still works)
- [x] T009 Modify `wallet-ecosystem/services/api/app/Modules/Platform/Providers/PlatformServiceProvider.php` — add `loadRoutesFrom(base_path('routes/modules/reliability.php'))` in `boot()`, matching the pattern used by other module service providers

---

## Phase 3: User Story 1 — Complete Financial Idempotency & Duplicate Prevention (Priority: P1)

**Goal**: Every financial mutation endpoint enforces `Idempotency-Key`; duplicate provider callbacks are silently rejected without a second ledger posting.

**Independent Test**: Submit any financial POST without `Idempotency-Key` â†’ HTTP 422 `IDEMPOTENCY_KEY_REQUIRED`. Submit with the same key twice â†’ second returns original response with `Idempotency-Replay: true`. Replay a funding callback with same `provider_reference_id` â†’ no second ledger entry.

- [x] T010 [US1] Audit `wallet-ecosystem/services/api/routes/modules/funding.php` — add `idempotency:require` middleware to `POST /funding/transactions` and `POST /funding/agent-cash-in`; confirm `ProviderCallbackService` has `provider_reference_id` deduplication guard (add if missing per research.md Decision 2)
- [x] T011 [US1] Audit `wallet-ecosystem/services/api/routes/modules/transfer.php` — add `idempotency:require` to `POST /transfers` (initiate) and `POST /transfers/{id}/confirm`
- [x] T012 [US1] Audit `wallet-ecosystem/services/api/routes/modules/withdrawal.php` — add `idempotency:require` to `POST /withdrawals` and `POST /withdrawals/{id}/confirm`; verify `WithdrawalConfirmationService` has `provider_reference_id` uniqueness guard — add if missing
- [x] T013 [US1] Audit `wallet-ecosystem/services/api/routes/modules/service.php` — add `idempotency:require` to `POST /service/payments`; verify `ServicePaymentService` has `provider_reference_id` deduplication — add if missing
- [x] T014 [P] [US1] Audit `wallet-ecosystem/services/api/routes/modules/qr.php` — add `idempotency:require` to `POST /qr/payments`; verify `QrPaymentService` has `provider_reference_id` deduplication — add if missing
- [x] T015 [P] [US1] Audit `wallet-ecosystem/services/api/routes/modules/agent-float.php` — add `idempotency:require` to float cash-in (`POST /agent-float/cash-in`), float cash-out (`POST /agent-float/cash-out`), and float funding mutation routes
- [x] T016 [P] [US1] Audit `wallet-ecosystem/services/api/routes/modules/commission.php` — add `idempotency:require` to commission reversal mutation route (`POST /commissions/{id}/reverse`)
- [x] T017 [US1] Write `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/Idempotency/IdempotencyEnforcementTest.php` — verifies: (a) financial POST routes return HTTP 422 `IDEMPOTENCY_KEY_REQUIRED` when key is absent; (b) duplicate key returns original response body with `Idempotency-Replay: true` header; (c) mismatched payload on same key returns HTTP 409
- [x] T018 [US1] Write `wallet-ecosystem/services/api/tests/Feature/Modules/Funding/DuplicateCallbackTest.php` — seeds a completed funding transaction; replays the provider callback with same `provider_reference_id`; verifies HTTP 200 returned and no additional ledger entry is created

---

## Phase 4: User Story 2 — Transaction Atomicity & Ledger Integrity (Priority: P1)

**Goal**: Wallet operations are fully atomic; the ledger is periodically verified for debit/credit balance equality.

**Independent Test**: Inject failure mid-posting â†’ both journal entries rolled back, wallet balances unchanged. Run `reliability:scan-ledger-integrity` â†’ snapshot appears in admin API with `outcome=clean`.

- [x] T019 [US2] Create migration `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Migrations/2026_10_02_027002_create_platform_ledger_integrity_snapshots_table.php` — table `platform.ledger_integrity_snapshots` per data-model.md columns; indexes on `(scan_at DESC)` and `(outcome)`
- [x] T020 [P] [US2] Create `LedgerIntegritySnapshotModel` at `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Models/LedgerIntegritySnapshotModel.php` — `platform` connection, `platform.ledger_integrity_snapshots` table, cast `affected_wallet_ids` â†’ array, `$timestamps = false` (has `created_at` only)
- [x] T021 [US2] Create `LedgerIntegrityJob` at `wallet-ecosystem/services/api/app/Jobs/LedgerIntegrityJob.php` — implements `ShouldQueue`; uses `HasCorrelationId` trait; queries `ledger.journal_entries` grouped by `wallet_id` for completed entries: `SUM(debit_amount) - SUM(credit_amount) AS delta` â†’ finds wallets where `ABS(delta) > 0`; writes `LedgerIntegritySnapshotModel` record; emits `ALERT_LEDGER_INTEGRITY_FAILURE` audit event per discrepant wallet
- [x] T022 [US2] Register `LedgerIntegrityJob` in `wallet-ecosystem/services/api/app/Console/Kernel.php` — `$schedule->job(new LedgerIntegrityJob(correlationId: (string) Uuid::uuid7()), 'platform.critical')->hourly()`
- [x] T023 [P] [US2] Create `LedgerIntegritySnapshotResource` at `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Http/Resources/LedgerIntegritySnapshotResource.php` — transforms model to JSON per `contracts/reliability-admin-api.yaml` `LedgerIntegritySnapshot` schema
- [x] T024 [US2] Create `LedgerIntegrityAdminController` at `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Http/Controllers/LedgerIntegrityAdminController.php` — `index()` paginates `LedgerIntegritySnapshotModel` ordered by `scan_at DESC`; returns paginated `LedgerIntegritySnapshotResource`
- [x] T025 [US2] Add ledger-integrity route to `wallet-ecosystem/services/api/routes/modules/reliability.php` — `GET /v1/operations/ledger-integrity/snapshots` â†’ `LedgerIntegrityAdminController@index`
- [x] T026 [US2] Create `wallet-ecosystem/services/api/app/Console/Commands/ScanLedgerIntegrityCommand.php` — `reliability:scan-ledger-integrity`; dispatches `LedgerIntegrityJob` synchronously (`dispatchSync`); outputs snapshot outcome and discrepancy count to console
- [x] T027 [US2] Write `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/Reliability/LedgerIntegrityTest.php` — test 1: balanced ledger data â†’ snapshot `outcome=clean`, `discrepancy_count=0`; test 2: artificially insert unbalanced completed entry â†’ snapshot `outcome=discrepancy_found` and `ALERT_LEDGER_INTEGRITY_FAILURE` audit event emitted; test 3: in-flight (non-completed) entries excluded from scan
- [x] T028 [US2] Write `wallet-ecosystem/services/api/tests/Feature/Modules/Ledger/AtomicRollbackTest.php` — posts debit entry successfully; injects mock to throw during credit entry; asserts `DB::rollBack()` fired, no completed journal entries exist, wallet balance unchanged

---

## Phase 5: User Story 3 — Interrupted Transaction Recovery (Priority: P2)

**Goal**: Stuck pending financial transactions are automatically detected and transitioned; orphan threshold is configurable.

**Independent Test**: Seed funding transaction with `status=pending_provider` and `updated_at` older than threshold; run `reliability:detect-orphans`; verify status transitions to `timeout_pending_review`.

- [x] T029 [US3] Add migration `wallet-ecosystem/services/api/app/Modules/Funding/Infrastructure/Migrations/2026_10_02_027003_add_dead_lettered_at_to_funding_transactions.php` — adds nullable `dead_lettered_at TIMESTAMPTZ` to `funding.transactions`
- [x] T030 [P] [US3] Add migration `wallet-ecosystem/services/api/app/Modules/Transfer/Infrastructure/Migrations/2026_10_02_027004_add_dead_lettered_at_to_transfer_transactions.php` — adds nullable `dead_lettered_at TIMESTAMPTZ` to `transfer.transactions`
- [x] T031 [P] [US3] Add migration `wallet-ecosystem/services/api/app/Modules/Withdrawal/Infrastructure/Migrations/2026_10_02_027005_add_dead_lettered_at_to_withdrawal_transactions.php` — adds nullable `dead_lettered_at TIMESTAMPTZ` to `withdrawal.transactions`
- [x] T032 [US3] Create `FundingTransactionTimeoutJob` at `wallet-ecosystem/services/api/app/Modules/Funding/Application/Jobs/FundingTransactionTimeoutJob.php` — implements `ShouldQueue`; uses `HasCorrelationId` trait; reads `reliability.orphan_threshold_minutes` via `ConfigurationServiceInterface`; queries `funding.transactions` where `status='pending_provider'` and `updated_at < threshold`; transitions each to `timeout_pending_review`; logs `funding.transaction.timeout_detected` per record
- [x] T033 [US3] Create `TransferTransactionTimeoutJob` at `wallet-ecosystem/services/api/app/Modules/Transfer/Application/Jobs/TransferTransactionTimeoutJob.php` — same pattern for `transfer.transactions` where `status='pending'` and `updated_at < threshold`; transitions to `timeout_pending_review`
- [x] T034 [US3] Create `WithdrawalTransactionTimeoutJob` at `wallet-ecosystem/services/api/app/Modules/Withdrawal/Application/Jobs/WithdrawalTransactionTimeoutJob.php` — same pattern for `withdrawal.transactions` where `status='pending_provider'` and `updated_at < threshold`; transitions to `timeout_pending_review`
- [x] T035 [US3] Register `FundingTransactionTimeoutJob`, `TransferTransactionTimeoutJob`, `WithdrawalTransactionTimeoutJob` in `wallet-ecosystem/services/api/app/Console/Kernel.php` — `->everyFiveMinutes()` on the `reliability` queue; pass a fresh `correlationId: (string) Uuid::uuid7()` per dispatch
- [x] T036 [US3] Create `wallet-ecosystem/services/api/app/Console/Commands/DetectOrphansCommand.php` — `reliability:detect-orphans` with optional `--dry-run` flag; injects `OrphanDetectionService` (created in T074); when `--dry-run`, calls `OrphanDetectionService::detectAll(dryRun: true)` and logs what would be transitioned without writing; when live, calls `OrphanDetectionService::detectAll()` and reports counts; NOTE: T074 must be complete before this command's injection is wired — until then, dispatch three timeout jobs synchronously as a scaffolding implementation
- [x] T037 [US3] Write `wallet-ecosystem/services/api/tests/Feature/Modules/Funding/FundingTransactionTimeoutTest.php` — seeds funding row with `status=pending_provider`, `updated_at = now()->subMinutes(10)`; runs job; asserts status â†’ `timeout_pending_review`; seeds second row with `updated_at = now()->subMinutes(2)`; asserts NOT transitioned (within threshold)
- [x] T038 [P] [US3] Write `wallet-ecosystem/services/api/tests/Feature/Modules/Transfer/TransferTransactionTimeoutTest.php` — same pattern for transfer transactions

---

## Phase 6: User Story 4 — Dead-Letter Visibility & Operator Replay (Priority: P2)

**Goal**: Every failed job is captured, visible in admin, replayable exactly once, dismissible with justification; all replay/dismiss actions are audit-logged.

**Independent Test**: Capture item via `DeadLetterService::capture()` â†’ appears in list API; replay â†’ status `replayed`; second replay â†’ HTTP 409; dismiss a different item â†’ status `dismissed`; dismissed item cannot be replayed.

- [x] T039 [US4] Create migration `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Migrations/2026_10_02_027001_create_platform_dead_letter_items_table.php` — `platform.dead_letter_items` with all columns per data-model.md; composite indexes on `(status, created_at)`, `(operation_type, status)`, `(source_id)`, `(expires_at)`
- [x] T040 [US4] Create `DeadLetterItemModel` at `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Models/DeadLetterItemModel.php` — `platform` connection, `platform.dead_letter_items` table; casts: `payload` â†’ array, `retry_history` â†’ array, `replay_outcome` â†’ array; `$timestamps = false` (manual `created_at`/`expires_at`)
- [x] T041 [P] [US4] Create `DeadLetterRepositoryInterface` at `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Contracts/DeadLetterRepositoryInterface.php` — methods: `save(DeadLetterItemModel $item): void`, `findById(string $id): ?DeadLetterItemModel`, `paginate(array $filters, int $perPage): LengthAwarePaginator`, `countByStatus(): array`
- [x] T042 [US4] Create `EloquentDeadLetterRepository` at `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Repositories/EloquentDeadLetterRepository.php` — implements `DeadLetterRepositoryInterface`; `paginate()` applies filters: `status`, `operation_type`, `from`/`to` on `created_at`; orders by `created_at DESC`
- [x] T043 [US4] Create `DeadLetterService` at `wallet-ecosystem/services/api/app/Modules/Platform/Application/Services/DeadLetterService.php` — injects `DeadLetterRepositoryInterface` and `AuditServiceInterface`; `capture(string $operationType, string $sourceId, array $payload, string $failureReason, int $retryCount, array $retryHistory, string $correlationId, ?Model $sourceModel = null): DeadLetterItemModel` creates model with `status=pending`, `expires_at = now()->addDays(config('reliability.dead_letter_ttl_days'))`; when `$sourceModel` is provided, sets `$sourceModel->dead_lettered_at = now()` and saves it within the same DB call before returning (satisfies data-model.md `dead_lettered_at` cross-reference, resolves M5); `replay()` uses `DB::transaction()` with status-check optimistic guard (load, assert `status=pending`, set `status=replayed`, save) then emits `DEAD_LETTER_ITEM_REPLAYED` audit event; `dismiss()` same pattern plus emits `DEAD_LETTER_ITEM_DISMISSED`; throws `ConflictException` if item is not `pending`; `stats()` returns counts from `countByStatus()`
- [x] T044 [P] [US4] Create `DeadLetterItemResource` at `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Http/Resources/DeadLetterItemResource.php` — transforms `DeadLetterItemModel`; masks sensitive `payload` fields via existing `PiiMasker`; omits `retry_history` from list responses (include only in single-item show)
- [x] T045 [P] [US4] Create `ReplayDeadLetterRequest` at `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Http/Requests/ReplayDeadLetterRequest.php` — optional `note` string max 500
- [x] T046 [P] [US4] Create `DismissDeadLetterRequest` at `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Http/Requests/DismissDeadLetterRequest.php` — required `reason` string min 10 max 1000
- [x] T047 [US4] Create `DeadLetterAdminController` at `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Http/Controllers/DeadLetterAdminController.php` — `index()`, `show()`, `stats()`, `replay()`, `dismiss()`; thin controller delegating to `DeadLetterService`; returns standard response envelope
- [x] T048 [US4] Complete dead-letter routes in `wallet-ecosystem/services/api/routes/modules/reliability.php` — `GET /v1/operations/dead-letter` â†’ `index`, `GET /v1/operations/dead-letter/stats` â†’ `stats`, `GET /v1/operations/dead-letter/{id}` â†’ `show`, `POST /v1/operations/dead-letter/{id}/replay` â†’ `replay`, `POST /v1/operations/dead-letter/{id}/dismiss` â†’ `dismiss`; ledger-integrity route was added by T025 — do not re-add it here
- [x] T049 [US4] Bind `DeadLetterRepositoryInterface` â†’ `EloquentDeadLetterRepository` and `DeadLetterServiceInterface` â†’ `DeadLetterService` in `wallet-ecosystem/services/api/app/Modules/Platform/Providers/PlatformServiceProvider.php`
- [x] T050 [P] [US4] Create `DeadLetterCleanupJob` at `wallet-ecosystem/services/api/app/Jobs/DeadLetterCleanupJob.php` — purges `platform.dead_letter_items` where `expires_at < now()` AND `status = 'pending'`; replayed/dismissed items follow audit retention, not this TTL
- [x] T051 [US4] Register `DeadLetterCleanupJob` in `wallet-ecosystem/services/api/app/Console/Kernel.php` — `$schedule->job(new DeadLetterCleanupJob(), 'default')->dailyAt('05:00')`
- [x] T052 [P] [US4] Create `PurgeDeadLetterCommand` at `wallet-ecosystem/services/api/app/Console/Commands/PurgeDeadLetterCommand.php` — `reliability:purge-dead-letter`; dispatches `DeadLetterCleanupJob` synchronously; reports purge count to console
- [x] T053 [US4] Create admin portal page `wallet-ecosystem/apps/admin/src/modules/operations/pages/dead-letter.tsx` — filter bar (status dropdown, operation_type input, date-range pickers), paginated table with columns: operation_type, source_id, failure_reason, retry_count, status badge, created_at, and Actions column with Replay button (confirm dialog) + Dismiss button (dialog requiring `reason` â‰¥ 10 chars); calls `/v1/operations/dead-letter` API
- [x] T054 [US4] Create admin portal route `wallet-ecosystem/apps/admin/app/(admin)/operations/dead-letter/page.tsx` — route wrapper importing from `src/modules/operations/pages/dead-letter.tsx`
- [x] T055 [US4] Write `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/Reliability/DeadLetterTest.php` — `capture()` creates pending item; `list()` paginates and filters by status/type; `replay()` â†’ status `replayed` + audit event; second `replay()` â†’ `ConflictException` (HTTP 409); `dismiss()` â†’ status `dismissed` + audit event; cleanup job purges only expired `pending` items, not `replayed` or `dismissed`; concurrent replay race: only one transaction commits

---

## Phase 7: User Story 5 — Distributed Correlation ID Propagation (Priority: P3)

**Goal**: All financial background job logs carry the originating HTTP request's correlation ID for end-to-end traceability.

**Independent Test**: Dispatch `FundingInitiationJob` with a known `correlationId`; verify log entries during job execution contain that correlation ID in context.

- [x] T056 [US5] Verify `wallet-ecosystem/services/api/app/Http/Middleware/CorrelationIdMiddleware.php` sets `X-Correlation-ID` response header and `Log::withContext(['correlation_id' => ...])` for all HTTP requests (no code change expected — document confirmation with inline comment referencing FR-018/FR-019)
- [x] T057 [US5] Add `HasCorrelationId` trait to `wallet-ecosystem/services/api/app/Modules/Funding/Application/Jobs/FundingInitiationJob.php` — add `public readonly string $correlationId` to constructor; call `$this->injectCorrelationContext()` at start of `handle()`; update all dispatch call-sites in `FundingInitiationService` to pass `correlationId` from current request context
- [x] T058 [P] [US5] Add `HasCorrelationId` trait to `wallet-ecosystem/services/api/app/Modules/Withdrawal/Application/Jobs/WithdrawalPayoutJob.php` — same pattern; update dispatch call-sites in `WithdrawalInitiationService`
- [x] T059 [P] [US5] Add `HasCorrelationId` trait to `wallet-ecosystem/services/api/app/Modules/Service/Application/Jobs/TransitionTimedOutPaymentsJob.php` and `wallet-ecosystem/services/api/app/Modules/Service/Application/Jobs/ReQueryTimeoutTransactionJob.php`
- [x] T060 [P] [US5] Audit all 10 Reporting jobs in `wallet-ecosystem/services/api/app/Modules/Reporting/Infrastructure/Jobs/` — confirm `correlationId` already in constructor per research.md; add `$this->injectCorrelationContext()` call in `handle()` for each job that does not already call it
- [x] T061 [P] [US5] Add `HasCorrelationId` trait to new jobs created in this epic: `LedgerIntegrityJob` (T021), `DeadLetterCleanupJob` (T050), `FundingTransactionTimeoutJob` (T032), `TransferTransactionTimeoutJob` (T033), `WithdrawalTransactionTimeoutJob` (T034)
- [x] T062 [US5] Write `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/Reliability/CorrelationIdPropagationTest.php` — verifies `X-Correlation-ID` present in API responses (sample 3 financial endpoints); verifies dispatching `FundingInitiationJob` with `correlationId='test-corr-123'` results in log entries containing `correlation_id='test-corr-123'` (use `Log::listen()` to capture log context)

---

## Phase 8: Polish & Cross-Cutting Concerns

- [x] T063 Create `wallet-ecosystem/services/api/openapi/v1/reliability.yaml` — OpenAPI 3.1.0 spec for all 6 reliability-admin endpoints per `contracts/reliability-admin-api.yaml`; include `BearerAuth` security scheme and all request/response schemas
- [x] T064 [P] Update `wallet-ecosystem/apps/admin/src/lib/navigation/nav-items.ts` — add `Operations` section with `Dead Letter` nav item (`href: '/operations/dead-letter'`, `requiredPermission: 'operations.dead_letter.manage'`)
- [x] T065 [P] Create `wallet-ecosystem/services/api/app/Console/Commands/AuditIdempotencyCoverageCommand.php` — `reliability:audit-idempotency-coverage`; iterates all registered routes (POST/PATCH/PUT); reports which are missing `idempotency.require` or `idempotency` middleware; exits non-zero when uncovered routes found; also write `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/Reliability/IdempotencyCoverageCommandTest.php` — runs `Artisan::call('reliability:audit-idempotency-coverage')` and asserts exit code 0 (zero uncovered routes after T010—T016 applied); satisfies SC-001 "verified by an automated endpoint-coverage scan"
- [x] T066 Run PHPStan at level 8 on all new and modified PHP files — target new Platform services, new jobs, new controllers; fix any type errors before this task is marked complete
- [x] T067 [P] Extend `phpunit.xml` to include `tests/Feature/Modules/Platform/Reliability/` directory in the Feature testsuite (if not already covered by glob patterns)
- [x] T068 Run quickstart SC-001 through SC-007 validation in Docker Compose environment per `quickstart.md` — requires Docker running; mark pass/fail per scenario

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — T001—T006 start immediately; T002/T003/T004/T005 parallel
- **Phase 2 (Foundation)**: Depends on T001 (reliability config) — T007/T008/T009 sequential; all must complete before US1 route audits
- **Phase 3 (US1)**: Depends on Phase 2 complete — T010—T016 parallel; T017/T018 after T007/T008
- **Phase 4 (US2)**: Depends on Phase 1 complete — independent of US1; T019â†’T020â†’T021â†’T022 sequential; T023 parallel with T020
- **Phase 5 (US3)**: Depends on Phase 1 + T029—T031 migrations; T032/T033/T034 parallel; T035 (Kernel) after T032—T034
- **Phase 6 (US4)**: Depends on T004 (interface) + T005 (seeder); T039â†’T040â†’T041â†’T042â†’T043 sequential; T044—T046 parallel after T040; T053/T054 after T047/T048
- **Phase 7 (US5)**: Depends on T002 (HasCorrelationId trait) — T057—T061 parallel; T062 after T057
- **Phase 8 (Polish)**: Depends on all stories complete — T063—T067 parallel; T068 Docker-gated

### User Story Dependencies

- **US1 (P1)**: After Phase 2 (Foundation)
- **US2 (P1)**: After Phase 1 (Setup) — independent of US1
- **US3 (P2)**: After Phase 1 (Setup) + US2 migrations; independent of US1
- **US4 (P2)**: After Phase 1 (Setup) — independent of US1—US3
- **US5 (P3)**: After T002 (HasCorrelationId trait from Phase 1); T061 requires US3 new jobs to exist first

### Within Each Phase

- Migrations before models; models before repositories; repositories before services; services before controllers
- T002/T003/T004 can run in parallel (different files)

---

## Parallel Execution Examples

```
# Phase 1 parallel batch:
T002: HasCorrelationId trait  ||  T003: OrphanDetectableInterface  ||  T004: DeadLetterServiceInterface  ||  T005: Seeder

# Phase 3 US1 route audits (after T007/T008 complete):
T010: Funding  ||  T011: Transfer  ||  T012: Withdrawal  ||  T013: Service  ||  T014: QR  ||  T015: AgentFloat  ||  T016: Commission

# Phase 4 US2 parallel:
T020: SnapshotModel  ||  T023: SnapshotResource  (both after T019 migration)

# Phase 6 US4 parallel:
T041: RepositoryInterface  ||  T044: Resource  ||  T045: ReplayRequest  ||  T046: DismissRequest  (all after T040 model)

# Phase 7 US5 parallel:
T058: WithdrawalPayoutJob  ||  T059: ServiceJobs  ||  T060: ReportingJobs  ||  T061: New epic jobs
```

---

## Implementation Strategy

### MVP First (US1 + US2 — Transport Safety, both P1)

1. Phase 1 Setup â†’ Phase 2 Foundation
2. Phase 3 US1 — all financial routes enforce `Idempotency-Key`
3. **CHECKPOINT**: Run T017 — all endpoints return 422 for missing key ✓
4. Phase 4 US2 — ledger integrity scan + admin API
5. **CHECKPOINT**: Run `reliability:scan-ledger-integrity` — snapshot shows `clean` ✓
6. These two user stories close the most critical financial correctness gaps and can ship independently

### Incremental Delivery

1. **Sprint 1**: Setup + Foundation â†’ Idempotency enforcement live (US1 complete)
2. **Sprint 2**: Ledger Integrity scan + admin endpoint (US2 complete)
3. **Sprint 3**: Orphan Detection timeout jobs for Funding/Transfer/Withdrawal (US3 complete)
4. **Sprint 4**: Dead-Letter infrastructure + admin portal section (US4 complete)
5. **Sprint 5**: Correlation ID propagation to all financial jobs (US5 complete)
6. **Sprint 6**: Polish — PHPStan, nav-items, coverage audit command, OpenAPI docs

### Docker-Gated Tasks

- **T068**: Quickstart SC-001—SC-007 validation — requires Docker Compose environment

---

## Phase 9: Analysis Remediation

**Source**: `/speckit-analyze` findings — C1 (Constitution P4 violation), H1 (FR-013 gap), H3 (FR-012 gap)

**Insertion order**: T069 must run after T027/T028 (wallet test infrastructure). T070 must run after T043 (`DeadLetterService`). T071/T072 must run after T002 (`HasCorrelationId`) and after T032—T034 (target jobs exist). T070 depends on T072 (reads `$retryHistory` property set by T072).

- [x] T069 [US2] Write `wallet-ecosystem/services/api/tests/Feature/Modules/Wallet/WalletConcurrencyTest.php` — dispatches two simultaneous transfer requests against the same wallet using database-level lock contention simulation (seed wallet with balance 100, attempt two concurrent debits of 60 each — only one can succeed); asserts final balance is exactly 40 (not -20); asserts one transaction committed and one is retried/rejected; asserts `ledger.journal_entries` contains exactly one debit/credit pair for the wallet (no duplicate entries); satisfies Constitution P4 "concurrency and race conditions MUST be explicitly tested" and FR-008
- [x] T070 [P] Create `DeadLetterJobFailureListener` at `wallet-ecosystem/services/api/app/Listeners/DeadLetterJobFailureListener.php` — handles `Illuminate\Queue\Events\JobFailed`; derives `operation_type` from job class short name (snake_case without `Job` suffix, dot-namespaced, e.g. `FundingInitiationJob` â†’ `funding.initiation`); reads `$event->job->payload()['data']['command']` to extract source_id from job's `$sourceId` property (falls back to UUID v7); reads `$retryHistory`, `$correlationId` from job instance if properties exist; calls `DeadLetterService::capture(operationType, sourceId, payload, failureReason, retryCount, retryHistory, correlationId)`; register in `wallet-ecosystem/services/api/app/Providers/EventServiceProvider.php` under `JobFailed::class`; satisfies FR-013
- [x] T071 [P] Add `backoff(): array` and `$tries` property to all financial `ShouldQueue` jobs — reads `config('reliability.retry_base_delay_seconds', 5)` and `config('reliability.max_retry_attempts', 3)`; `backoff()` returns exponentially increasing delays: `[base, base*2, base*4]` truncated to `$tries` entries; set `public int $tries` from config; target jobs (all exist by end of Phase 7): `FundingInitiationJob`, `WithdrawalPayoutJob`, `FundingTransactionTimeoutJob`, `TransferTransactionTimeoutJob`, `WithdrawalTransactionTimeoutJob`; satisfies FR-012 exponential backoff
- [x] T072 Add `$retryHistory` accumulation to financial jobs modified in T071 — add `public array $retryHistory = []` property; implement `failed(Throwable $exception): void` on each job: appends `['attempt' => $this->attempts(), 'failed_at' => now()->toIso8601String(), 'reason' => $exception->getMessage()]` to `$this->retryHistory`; `DeadLetterJobFailureListener` (T070) reads this property when building the retry history for `capture()`; satisfies FR-012 retry history recording and ensures `retry_history` JSONB column in `platform.dead_letter_items` is populated correctly

---

## Phase 10: Analysis Remediation (H2, M1, M2, M3, M6)

**Source**: `/speckit-analyze` findings — H2 (FR-011 orphan auto-recovery), M1 (FR-020 domain event correlation), M2 (FR-002 actor identity), M3 (US3/AC3 customer notification), M6 (OrphanDetectionService registry)

**Insertion order**: T074 before T036 is fully wired. T075 after T032—T034 (timeout jobs exist). T076 after T075 (reversal logic must exist first). T073 after T057 (HasCorrelationId infrastructure). T077 independent of all others.

- [x] T073 [P] [US5] Audit domain events in Funding, Transfer, and Withdrawal modules — add `public readonly string $correlationId` property and constructor parameter to each financial domain event class (e.g. `FundingInitiatedEvent`, `FundingCompletedEvent`, `TransferInitiatedEvent`, `TransferCompletedEvent`, `WithdrawalInitiatedEvent`, `WithdrawalCompletedEvent`); update dispatch call-sites in each module's Application Service to pass `correlationId` from the originating job or request context; in each event subscriber's `handle()` method, add `Log::withContext(['correlation_id' => $event->correlationId])` as the first line; satisfies FR-020 "propagated into every background job, domain event, and log entry" and US5/AC5 "subscriber's processing logs carry the original correlation ID from the event payload"
- [x] T074 Create `OrphanDetectionService` at `wallet-ecosystem/services/api/app/Modules/Platform/Application/Services/OrphanDetectionService.php` — holds a registry of `OrphanDetectableInterface` implementations injected via constructor (initially Funding, Transfer, Withdrawal recovery services); `detectAll(bool $dryRun = false, ?Carbon $threshold = null): array` iterates each registered implementation, calls `findOrphanedTransactionIds($threshold ?? now()->subMinutes(config('reliability.orphan_threshold_minutes')))`, then calls `recoverOrphanedTransaction($txId, $correlationId)` per ID (skipped when `$dryRun`); returns `['module' => ..., 'detected' => N, 'resolved' => N, 'skipped' => N]` per module; bind in `PlatformServiceProvider` with all three module recovery services injected; update `DetectOrphansCommand` (T036) to fully delegate to this service; resolves M6 inconsistency between plan.md registry pattern and direct-dispatch implementation
- [x] T075 [US3] Implement orphan auto-recovery in each module timeout job (T032—T034) — after transitioning a transaction to `timeout_pending_review`, each job attempts a provider status re-query: (1) call the module's ProviderAdapterInterface to re-query the transaction status; (2) if provider confirms **completed**: call the module's `CompleteTransactionService` to apply any missing ledger entries and mark the transaction `completed`; (3) if provider confirms **failed**: call the module's `ReverseTransactionService` to issue a compensating ledger entry (debit that reverses the credit, or vice versa) and mark the transaction `reversed`; (4) if provider is unreachable or ambiguous: leave in `timeout_pending_review` for manual operator review; implement `recoverOrphanedTransaction(string $transactionId, string $correlationId): string` on each module's `OrphanRecoveryService` (new class at `app/Modules/{Module}/Application/Services/{Module}OrphanRecoveryService.php`) returning `'recovered'|'reversed'|'skipped'`; satisfies FR-011 "completed if safely confirmed, reversed via compensating ledger entry if not"
- [x] T076 [US3] Emit `TransactionReversedEvent` from orphan reversal path — create `TransactionReversedEvent` (or reuse existing if present from E09/E10) at `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Events/TransactionReversedEvent.php` with properties: `transactionId`, `moduleType` (`funding|transfer|withdrawal`), `walletId`, `amount`, `currency`, `correlationId`, `reversedAt`; dispatch from each `{Module}OrphanRecoveryService` (T075) when the `reversed` path is taken; register a `NotifyCustomerOnReversalListener` that calls the Notification module's `NotificationService` to send an in-app and/or SMS notification to the wallet owner; satisfies US3/AC3 "the customer is notified" when a transaction is reversed; if the notification service is not yet wired for this event type, emit the event and log a warning so the subscriber can be added without code change
- [x] T077 [P] Audit and update `wallet-ecosystem/services/api/app/Modules/Platform/Application/Services/IdempotencyService.php` — verify `store(string $key, int $statusCode, mixed $responseBody, string $actorId, string $operationType, Carbon $expiresAt): IdempotencyRecord` signature includes `actorId`; if `actor_id` column is missing from `platform.idempotency_keys` table, add migration `2026_10_02_027006_add_actor_id_to_platform_idempotency_keys.php` adding nullable `actor_id UUID`; verify `IdempotencyMiddleware` passes the authenticated user ID when calling `store()`; extend T017 (`IdempotencyEnforcementTest`) with an assertion that the stored idempotency record's `actor_id` matches the request's authenticated user; satisfies FR-002 "completed idempotency record MUST storeâ€¦actor identity"

## Phase 11: Convergence

- [x] T078 Add `lockForUpdate()` to `DeadLetterService::replay()` and `::dismiss()` — in `wallet-ecosystem/services/api/app/Modules/Platform/Application/Services/DeadLetterService.php`, replace the `$this->repository->findById($id)` call inside the `replay()` DB transaction (line 78) with a locked fetch (e.g. `DeadLetterItemModel::where('id', $id)->lockForUpdate()->first()`), and apply the same change inside `dismiss()`; this ensures that under PostgreSQL `READ COMMITTED`, two concurrent replay or dismiss requests acquire an exclusive row lock so only one proceeds and the second hits the `status !== 'pending'` guard after the first commits; satisfies FR-016 "exactly one replay MUST succeed and the other MUST be rejected" (partial)

