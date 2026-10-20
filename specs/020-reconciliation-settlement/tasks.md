# Tasks: E20 — Reconciliation & Settlement

**Feature directory**: `specs/020-reconciliation-settlement/`
**Backend module path**: `wallet-ecosystem/services/api/app/Modules/Reconciliation/`
**Migrations path**: `wallet-ecosystem/services/api/app/Modules/Reconciliation/Infrastructure/Migrations/`
**Tests path**: `wallet-ecosystem/services/api/tests/`
**Frontend path**: `wallet-ecosystem/apps/admin/`

---

## Phase 1: Setup

**Purpose**: Scaffold the Reconciliation module skeleton, routes, middleware, and permissions.

- [X] T001 Create module directory tree: `app/Modules/Reconciliation/{Domain/{Contracts,Enums,Models,ValueObjects,Adapters},Application/{Services,Jobs,Adapters},Infrastructure/{Http/{Controllers,Requests,Resources,Middleware},Migrations,Providers},routes}`
- [X] T001b [P] Create `config/reconciliation.php` — keys: `webhook_secret` (env `RECONCILIATION_WEBHOOK_SECRET`, default `''`); `provider_file_adapter` (FQCN string, default `\App\Modules\Reconciliation\Application\Adapters\CsvProviderFileAdapter::class`); `settlement_adapter` (FQCN string, default `\App\Modules\Reconciliation\Application\Adapters\NullSettlementAdapter::class`); `default_fee_rate` (bcmath-safe decimal string, default `'0'`); also add `reconciliation_imports` disk entry to `config/filesystems.php` with `driver=local`, `root=storage_path('app/reconciliation-imports')` — add `RECONCILIATION_WEBHOOK_SECRET=` to `.env.example`
- [X] T002 Create `app/Modules/Reconciliation/Infrastructure/Providers/ReconciliationServiceProvider.php` — extend `Illuminate\Support\ServiceProvider`; bind `ReconciliationMetricsInterface` to `ReconciliationMetricsService`; bind `ProviderFileAdapterInterface` to `CsvProviderFileAdapter` (config: `reconciliation.provider_file_adapter`); bind `ProviderWebhookAuthenticatorInterface` to `HmacWebhookAuthenticator`; bind `SettlementAdapterInterface` to `NullSettlementAdapter` (config: `reconciliation.settlement_adapter`); register module routes from `routes/reconciliation.php`; register `reconciliation.webhook_auth` middleware alias pointing to `ReconciliationWebhookAuth`
- [X] T003 Register `ReconciliationServiceProvider` in `bootstrap/providers.php`
- [X] T004 [P] Create `app/Modules/Reconciliation/routes/reconciliation.php` — NOTE: actual path is `routes/modules/reconciliation.php` per codebase convention (ServiceProvider uses `base_path('routes/modules/reconciliation.php')`) — define route stubs for all 18 endpoints (see contracts/); prefix `/v1/reconciliation`; apply `identity.auth` middleware to all routes except `POST /batches/api` (which uses `reconciliation.webhook_auth`); require permissions per plan.md Permissions table
- [X] T005 [P] Add `reconciliation` queue to `config/horizon.php` worker configuration — dedicate at least 1 worker; priority: lower than `default`, higher than `admin`
- [X] T006 [P] Create `app/Modules/Reconciliation/Infrastructure/Http/Middleware/ReconciliationWebhookAuth.php` — implement HMAC-SHA256 signature verification: compute `hash_hmac('sha256', $request->getContent(), config('reconciliation.webhook_secret'))`; compare with `X-Provider-Signature` header using `hash_equals()`; return 401 if mismatch; allow if verified
- [X] T007 [P] Create `database/seeders/ReconciliationPermissionSeeder.php` — seed 4 permissions: `reconciliation.read`, `reconciliation.write`, `reconciliation.approve_settlement`, `reconciliation.execute_settlement`; seed 3 roles: `reconciliation-officer` (read+write), `settlement-approver` (read+write+approve_settlement), `settlement-executor` (read+execute_settlement); use E04 permission infrastructure; seed test users for local/testing only: `recon-officer@olekard.local`, `settlement-approver@olekard.local`
- [X] T008 [P] Verify cross-module contracts are importable: — DONE: JournalEntryType enum extended with `Settlement = 'settlement'`; migration `2026_09_27_000010_extend_ledger_entry_type_for_settlement.php` alters E07 CHECK constraint to allow 'settlement' `grep -r 'interface LedgerModuleContract' app/ && grep -r 'interface AuditServiceInterface' app/ && grep -r 'class LedgerService' app/Modules/Ledger` — if any are absent, raise a blocker; do NOT create TODO stubs in production code; also check E07's `postEntry()` implementation for an entry-type whitelist: `grep -r 'entry_type' app/Modules/Ledger` — if a validated whitelist exists, add `'settlement'` to it before implementing T051; if no whitelist exists, document that `entry_type` is free-form and no change is needed

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Enums, value objects, adapter interfaces, domain contract, migrations, models, default adapters, and metrics service that every story phase depends on. No story phase can begin until this phase is complete.

### Enums (parallelizable)

- [X] T009 [P] Create `app/Modules/Reconciliation/Domain/Enums/BatchSourceType.php` — backed string enum; cases: `ProviderFile = 'provider_file'`, `ProviderApi = 'provider_api'`, `BankStatement = 'bank_statement'`
- [X] T010 [P] Create `app/Modules/Reconciliation/Domain/Enums/BatchStatus.php` — backed string enum; cases: `Draft`, `Processing`, `Matched`, `Completed`, `Failed`; add `isTerminal(): bool` (true for Completed, Failed)
- [X] T011 [P] Create `app/Modules/Reconciliation/Domain/Enums/ItemStatus.php` — backed string enum; cases: `Pending`, `Matched`, `Unmatched`, `Exception`
- [X] T012 [P] Create `app/Modules/Reconciliation/Domain/Enums/ExceptionType.php` — backed string enum; cases: `UnmatchedProvider = 'unmatched_provider'`, `UnmatchedBank = 'unmatched_bank'`, `AmountMismatch = 'amount_mismatch'`, `Duplicate = 'duplicate'`
- [X] T013 [P] Create `app/Modules/Reconciliation/Domain/Enums/ExceptionStatus.php` — backed string enum; cases: `Open`, `Investigating`, `Resolved`, `WrittenOff`; add `isTerminal(): bool` (true for Resolved, WrittenOff)
- [X] T014 [P] Create `app/Modules/Reconciliation/Domain/Enums/SettlementStatus.php` — backed string enum; cases: `Draft`, `PendingApproval`, `Approved`, `Executing`, `Executed`, `Rejected`, `Failed`; add `isTerminal(): bool` (true for Executed, Rejected, Failed); add `isApprovalPending(): bool` (true for PendingApproval only)

### Value Object

- [X] T015 [P] Create `app/Modules/Reconciliation/Domain/ValueObjects/ProviderTransactionDto.php` — NOTE: placed at `Domain/Adapters/ProviderTransactionDto.php` (co-located with adapter interfaces that use it) — readonly class; properties: `string $providerReference`, `string $amount` (decimal string), `string $currencyCode`, `\DateTimeImmutable $transactionDate`, `array $metadata`; add static `fromArray(array $row): self` factory

### Adapter Interfaces (parallelizable)

- [X] T016 [P] Create `app/Modules/Reconciliation/Domain/Adapters/ProviderFileAdapterInterface.php` — single method: `parse(string $fileContents, string $mimeType): array` returns `ProviderTransactionDto[]`
- [X] T017 [P] Create `app/Modules/Reconciliation/Domain/Adapters/ProviderWebhookAuthenticatorInterface.php` — single method: `verify(\Illuminate\Http\Request $request): bool`
- [X] T018 [P] Create `app/Modules/Reconciliation/Domain/Adapters/SettlementAdapterInterface.php` — single method: `execute(SettlementBatch $batch): string` returns external reference ID

### Domain Contract (parallelizable)

- [X] T019 [P] Create `app/Modules/Reconciliation/Domain/Contracts/ReconciliationMetricsInterface.php` — methods: `batchProcessed(string $sourceType, string $status): void`, `itemMatched(string $confidenceTier): void`, `exceptionCreated(string $exceptionType): void`, `exceptionResolved(string $resolutionType): void`, `settlementAction(string $actionType): void`, `settlementAmount(string $currencyCode, string $amount): void`
- [X] T019b [P] Create `app/Modules/Reconciliation/Domain/Contracts/ReconciliationModuleContract.php` — public interface defining methods this module exposes to other bounded contexts; minimum viable surface: `getBatchStatus(string $batchId): string`, `getSettlementStatus(string $settlementId): string`; bind a concrete implementation class in `ReconciliationServiceProvider`; include ONLY methods other modules genuinely call — do not pre-populate speculatively (constitution P7 extraction-readiness)

### Migrations (parallelizable — different files)

- [X] T020 [P] Create `app/Modules/Reconciliation/Infrastructure/Migrations/2026_09_27_000001_create_reconciliation_batches_table.php` — schema `reconciliation`; columns per data-model.md; UNIQUE `idempotency_key`; UNIQUE `(source_type, source_reference, batch_date)`; indexes: `(status, created_at DESC)`, `(submitted_by, created_at DESC)`
- [X] T021 [P] Create `app/Modules/Reconciliation/Infrastructure/Migrations/2026_09_27_000002_create_reconciliation_items_table.php` — schema `reconciliation`; FK to `reconciliation_batches(id)` ON DELETE CASCADE; nullable FK to `transaction.transaction_projections(id)`; indexes: `(batch_id, status)`, `(provider_reference, currency_code)`, `(internal_transaction_id)`, `(status, created_at DESC)`
- [X] T022 [P] Create `app/Modules/Reconciliation/Infrastructure/Migrations/2026_09_27_000003_create_reconciliation_exceptions_table.php` — schema `reconciliation`; FK to `reconciliation_items(id)`; FK to `reconciliation_batches(id)`; UNIQUE `(item_id)` (one exception per item at a time); indexes: `(status, created_at DESC)`, `(batch_id, status)`, `(assigned_to, status)`
- [X] T023 [P] Create `app/Modules/Reconciliation/Infrastructure/Migrations/2026_09_27_000004_create_settlement_batches_table.php` — schema `reconciliation`; columns per data-model.md; UNIQUE `idempotency_key`; CHECK `maker_id != checker_id OR checker_id IS NULL`; CHECK `net_amount >= 0`; CHECK `fee_amount >= 0`; CHECK `gross_amount >= 0`; indexes: `(status, created_at DESC)`, `(maker_id, created_at DESC)`, `(period_start, period_end, settlement_type)`
- [X] T024 [P] Create `app/Modules/Reconciliation/Infrastructure/Migrations/2026_09_27_000005_create_settlement_items_table.php` — schema `reconciliation`; FK to `settlement_batches(id)` ON DELETE CASCADE; FK to `reconciliation_items(id)`; UNIQUE `(settlement_batch_id, reconciliation_item_id)`

### Eloquent Models (parallelizable)

- [X] T025 [P] Create `app/Modules/Reconciliation/Domain/Models/ReconciliationBatch.php` — `protected $connection = 'reconciliation'`; `protected $table = 'reconciliation.reconciliation_batches'`; cast `source_type` → `BatchSourceType`; cast `status` → `BatchStatus`; scope `processing()`, `completed()`, `failed()`; `hasMany(ReconciliationItem::class)`, `hasMany(ReconciliationException::class)`
- [X] T026 [P] Create `app/Modules/Reconciliation/Domain/Models/ReconciliationItem.php` — `protected $connection = 'reconciliation'`; `protected $table = 'reconciliation.reconciliation_items'`; cast `item_type` (string), `status` → `ItemStatus`; `belongsTo(ReconciliationBatch::class)`; `hasOne(ReconciliationException::class)`; scope `unmatched()`, `matched()`
- [X] T027 [P] Create `app/Modules/Reconciliation/Domain/Models/ReconciliationException.php` — `protected $connection = 'reconciliation'`; `protected $table = 'reconciliation.reconciliation_exceptions'`; cast `exception_type` → `ExceptionType`; cast `status` → `ExceptionStatus`; `belongsTo(ReconciliationItem::class)`; `belongsTo(ReconciliationBatch::class)`; scope `open()`, `terminal()`
- [X] T028 [P] Create `app/Modules/Reconciliation/Domain/Models/SettlementBatch.php` — `protected $connection = 'reconciliation'`; `protected $table = 'reconciliation.settlement_batches'`; cast `status` → `SettlementStatus`; `hasMany(SettlementItem::class)`; scope `pendingApproval()`, `approved()`, `executed()`; fillable: idempotency_key, settlement_type, provider_code, period_start, period_end, currency_code, gross_amount, fee_amount, net_amount, status, maker_id
- [X] T029 [P] Create `app/Modules/Reconciliation/Domain/Models/SettlementItem.php` — `protected $connection = 'reconciliation'`; `protected $table = 'reconciliation.settlement_items'`; `belongsTo(SettlementBatch::class)`; `belongsTo(ReconciliationItem::class)`

### Default Adapters (parallelizable)

- [X] T030 [P] Create `app/Modules/Reconciliation/Application/Adapters/CsvProviderFileAdapter.php` implementing `ProviderFileAdapterInterface` — use PHP's `str_getcsv()` to parse CSV rows; map columns `provider_reference`, `amount`, `currency_code`, `transaction_date` to `ProviderTransactionDto`; throw `\InvalidArgumentException` if required columns missing; skip header row; handle UTF-8 BOM
- [X] T031 [P] Create `app/Modules/Reconciliation/Application/Adapters/HmacWebhookAuthenticator.php` implementing `ProviderWebhookAuthenticatorInterface` — read `X-Provider-Signature` header; compute expected signature with `hash_hmac('sha256', $request->getContent(), config('reconciliation.webhook_secret'))`; return `hash_equals($expected, $provided)`; return false if header absent
- [X] T032 [P] Create `app/Modules/Reconciliation/Application/Adapters/NullSettlementAdapter.php` implementing `SettlementAdapterInterface` — `execute()` returns `'NULL-' . $batch->id` (a deterministic fake external reference); emit `Log::info('settlement.null_adapter.executed', ...)` for observability in non-production environments

### Metrics Service

- [X] T033 Create `app/Modules/Reconciliation/Application/Services/ReconciliationMetricsService.php` implementing `ReconciliationMetricsInterface` — emit StatsD/Prometheus counters per method; bind in `ReconciliationServiceProvider`

**Checkpoint**: Phase 2 complete — 5 tables exist, models loadable, all enums/interfaces defined. Run `php artisan migrate` and verify no errors before proceeding.

---

## Phase 3: US-RECON-001–002 — Provider Import (P0) 🎯 MVP

**Goal**: Allow authorized operations staff to upload a provider transaction file or receive provider transactions via API, creating an idempotent `ReconciliationBatch` and queuing async matching.

**Independent Test (SC-1 from quickstart.md)**: `POST /v1/reconciliation/batches/file` with a 3-row CSV returns HTTP 201; batch `total_count == 3`; same `idempotency_key` returns HTTP 200 with same batch ID (SC-2).

- [X] T034 [US1] Implement `app/Modules/Reconciliation/Application/Services/ReconciliationImportService.php` — inject: `ProviderFileAdapterInterface`, `AuditServiceInterface`, `ReconciliationMetricsInterface`, Storage facade; methods: `importFromFile(UploadedFile $file, array $data, string $actorId): ReconciliationBatch` — store file to `Storage::disk(config('filesystems.reconciliation_imports', 'local'))`; check UNIQUE idempotency_key → return existing if found (HTTP 200 path); create `ReconciliationBatch` status=draft; bulk-insert `ReconciliationItem` rows from `ProviderFileAdapterInterface::parse()`; transition batch status to `processing`; dispatch `ProcessReconciliationBatchJob`; emit `reconciliation.batch.created` audit; `importFromApi(array $transactions, array $data): ReconciliationBatch` — same idempotency pattern; create items from normalized JSON array; dispatch job
- [X] T035 [P] [US1] Create `app/Modules/Reconciliation/Infrastructure/Http/Requests/ImportProviderFileRequest.php` — validate: `idempotency_key` required max:128; `batch_date` required date; `provider_code` optional max:50; `file` required mimes:csv,txt max:10240KB; create `ReceiveProviderApiRequest.php` — validate: `idempotency_key` required; `batch_date` required date; `transactions` required array min:1 max:5000; each item: `provider_reference` required, `amount` decimal string, `currency_code` in [BWP], `transaction_date` date
- [X] T036 [P] [US1] Create `app/Modules/Reconciliation/Infrastructure/Http/Resources/BatchSummaryResource.php` (id, source_type, source_reference, batch_date, status, total_count, matched_count, unmatched_count, exception_count, created_at) and `BatchDetailResource.php` (extends BatchSummary + submitted_by, processed_at, file_path, metadata)
- [X] T037 [US1] Implement `app/Modules/Reconciliation/Infrastructure/Http/Controllers/ReconciliationBatchController.php` — inject `ReconciliationImportService`, `ReconciliationMetricsInterface`; methods: `list(Request $request)` (require `reconciliation.read`; paginate with filters: source_type, status, from/to, per_page); `show(string $id)` (require `reconciliation.read`; 404 if not found); `importFile(ImportProviderFileRequest $request)` (require `reconciliation.write`; delegate to `importFromFile()`; return 201 or 200 based on idempotency); `receiveApi(ReceiveProviderApiRequest $request)` (uses `reconciliation.webhook_auth` middleware; delegate to `importFromApi()`; return 201 or 200); add `importBankStatement()` method stub returning 501 (implemented in Phase 5)
- [X] T038 [US1] Create `app/Modules/Reconciliation/Application/Jobs/ProcessReconciliationBatchJob.php` — queue: `reconciliation`; `public int $tries = 3`; `public int $timeout = 300`; constructor accepts `string $batchId`; `handle()`: load batch with `lockForUpdate` inside `DB::connection('reconciliation')->transaction()`; skip if not processing status; call `ReconciliationMatchingService::matchBatch($batch)` (injected via constructor); on exception: set batch status=failed; record error in `metadata['error']`; emit `batchProcessed` metric _(matching service call is a pass-through stub in Phase 3; full implementation in Phase 4)_
- [X] T039 [US1] Write integration tests `tests/Feature/Modules/Reconciliation/ImportTest.php` — SC-1: CSV upload → 201; batch total_count=3; SC-2: duplicate idempotency_key → 200 same ID; invalid CSV format → 422; missing file → 422; missing idempotency_key → 422; 401 without token; 403 with customer token; API receive endpoint → 201; duplicate API key → 200; invalid HMAC signature → 401 _(requires Docker)_

**Checkpoint**: `POST /v1/reconciliation/batches/file` creates batch and queues job. SC-1 and SC-2 pass.

---

## Phase 4: US-RECON-003–007 — Provider Matching & Exception Management (P0)

**Goal**: Implement the three-pass matching algorithm and the full exception lifecycle (auto-create on unmatched, manual resolve/write-off).

**Independent Test (SC-3 from quickstart.md)**: Import 3-row CSV (1 exact match, 1 fuzzy, 1 no match) → unmatched item detected → exception auto-created → resolve exception → 409 on re-resolve.

- [X] T040 [US3] [US9] Implement `app/Modules/Reconciliation/Application/Services/ReconciliationMatchingService.php` — inject: DB facade, `ReconciliationExceptionService`, `AuditServiceInterface`, `ReconciliationMetricsInterface`; `matchBatch(ReconciliationBatch $batch): void` — iterate `ReconciliationItem[]` for batch; for each item: **Pass 1** (exact): query `transaction.transaction_projections` via `DB::connection('pgsql')` where `reference = item.provider_reference AND amount_minor = bcmul(item.amount, '100', 0) AND currency_code = item.currency_code` → if found: `item.status = matched`, `item.match_confidence = 100`, `item.internal_transaction_id = tx.id`; **Pass 2** (fuzzy): same amount + currency, `created_at` within ±1 business day → `item.status = matched`, `item.match_confidence = 75`; **Pass 3** (unmatched): `item.status = unmatched`, `item.match_confidence = 0` → call `ReconciliationExceptionService::createForItem($item)`; update batch counts; set batch status=matched; emit `batchProcessed` metric; `markReconciled(string $itemId, string $reason, ?string $internalTransactionId, string $actorId): ReconciliationItem` — 409 if item already matched; update item status=matched; emit `reconciliation.item.reconciled` audit; `triggerRematching(string $batchId): void` — 409 if batch status=processing; set status=processing; dispatch `ProcessReconciliationBatchJob`
- [X] T041 [US6] Implement `app/Modules/Reconciliation/Application/Services/ReconciliationExceptionService.php` — inject: `AuditServiceInterface`, `ReconciliationMetricsInterface`; `createForItem(ReconciliationItem $item): ReconciliationException` — determine `exception_type` from `item.item_type` (provider_transaction → unmatched_provider; bank_transaction → unmatched_bank); create `ReconciliationException` status=open; emit `exceptionCreated` metric; `listExceptions(array $filters): LengthAwarePaginator`; `getException(string $id): ReconciliationException`; `resolveException(string $id, string $resolutionType, string $resolution, ?string $internalTransactionId, string $actorId, string $ip): ReconciliationException` — 409 if exception isTerminal(); if `resolution_type=manually_matched` AND `$internalTransactionId` provided: call `ReconciliationMatchingService::markReconciled()`; set exception status (Resolved or WrittenOff); emit `reconciliation.exception.resolved` audit; emit `exceptionResolved` metric
- [X] T042 [P] [US3] Create `app/Modules/Reconciliation/Infrastructure/Http/Requests/MarkReconciledRequest.php` — validate: `reason` required min:10 max:2000; `internal_transaction_id` optional UUID; create `ResolveExceptionRequest.php` — validate: `resolution_type` required in [manually_matched, written_off, reversed, duplicate_confirmed]; `resolution` required min:10 max:2000; `internal_transaction_id` required_if:resolution_type,manually_matched UUID
- [X] T043 [P] [US3] Create `app/Modules/Reconciliation/Infrastructure/Http/Resources/ItemSummaryResource.php` (id, batch_id, item_type, provider_reference, internal_transaction_id, amount, currency_code, transaction_date, status, match_confidence, matched_at); create `ExceptionSummaryResource.php` (id, item_id, batch_id, exception_type, status, assigned_to, opened_at, resolved_at); create `ExceptionDetailResource.php` (extends ExceptionSummary + item embedded via ItemSummaryResource, resolved_by, resolution, resolution_type)
- [X] T044 [US3] Implement `app/Modules/Reconciliation/Infrastructure/Http/Controllers/ReconciliationMatchingController.php` — inject `ReconciliationMatchingService`; methods: `triggerMatch(string $id)` (require `reconciliation.write`; delegate to `triggerRematching()`; return 202 with batch status); `listItems(string $id, Request $request)` (require `reconciliation.read`; filter by status, item_type; paginate); `listUnmatched(string $id, Request $request)` (require `reconciliation.read`; filter items where status=unmatched optionally by item_type); `markReconciled(MarkReconciledRequest $request, string $id)` (require `reconciliation.write`; delegate to `markReconciled()`; 409 if already matched)
- [X] T045 [US6] Implement `app/Modules/Reconciliation/Infrastructure/Http/Controllers/ReconciliationExceptionController.php` — inject `ReconciliationExceptionService`; methods: `list(Request $request)` (require `reconciliation.read`; filter by status, exception_type, assigned_to, from; paginate); `show(string $id)` (require `reconciliation.read`; 404 if not found); `resolve(ResolveExceptionRequest $request, string $id)` (require `reconciliation.write`; delegate to `resolveException()`; 409 if terminal; 422 if manually_matched without internal_transaction_id)
- [X] T046 [US3] Write unit tests `tests/Unit/Modules/Reconciliation/Services/ReconciliationMatchingServiceTest.php` — exact match: provider_reference + amount + currency → status=matched, confidence=100; fuzzy match: same amount ±1 business day → status=matched, confidence=75; unmatched: no candidate → exception auto-created, status=open; concurrent `matchBatch()` calls on same batch: assert exactly one completes and the other exits early (lockForUpdate guard); `markReconciled` idempotency: 409 on already-matched item _(requires Docker for concurrency assertion)_
- [X] T047 [US3] Write integration tests `tests/Feature/Modules/Reconciliation/MatchingExceptionTest.php` — SC-3: import CSV → unmatched detected → exception created → resolve → 409 on re-resolve; SC-4: manual mark-reconciled → item status=matched; triggerMatch on processing batch → 409; listUnmatched returns only unmatched items; resolve with manually_matched but missing internal_transaction_id → 422; 403 without reconciliation.write _(requires Docker)_

**Checkpoint**: Full provider reconciliation lifecycle functional. SC-3 and SC-4 pass.

---

## Phase 5: US-RECON-008–011 — Bank Statement Reconciliation (P1)

**Goal**: Allow admins to import bank statements and identify unmatched bank deposits and withdrawals using the same matching infrastructure.

**Independent Test (SC-5 from quickstart.md)**: `POST /v1/reconciliation/batches/bank-statement` → batch with `source_type=bank_statement`; unmatched bank items returned via `?item_type=bank_transaction`.

- [X] T048 [US8] Add `importBankStatement(UploadedFile $file, array $data, string $actorId): ReconciliationBatch` to `ReconciliationImportService` — identical flow to `importFromFile()` but `source_type = BatchSourceType::BankStatement`; items created with `item_type = 'bank_transaction'`; same idempotency check; update `ReconciliationBatchController::importBankStatement()` to replace the Phase 3 stub (remove 501 return); require `reconciliation.write` permission; create `app/Modules/Reconciliation/Infrastructure/Http/Requests/ImportBankStatementRequest.php` — validate: `idempotency_key` required max:128, `batch_date` required date, `file` required mimes:csv,txt max:10240KB
- [X] T049 [US8] [US10] [US11] Write integration tests `tests/Feature/Modules/Reconciliation/BankStatementTest.php` — SC-5: bank statement import → source_type=bank_statement; unmatched bank items returned with item_type=bank_transaction; unmatched deposits (positive `amount` value — `ReconciliationItem.amount` stores the signed decimal string as-is from the source CSV) via `?item_type=bank_transaction`; unmatched withdrawals (negative `amount` value, e.g. `"-250.00"`) identified — confirm both are returned by `GET /batches/{id}/unmatched?item_type=bank_transaction`; idempotent re-import → same batch ID; 403 without reconciliation.write _(requires Docker)_

**Checkpoint**: Bank statement import and unmatched identification functional. SC-5 passes.

---

## Phase 6: US-RECON-012–016 — Settlement Workflow (P1)

**Goal**: Implement the full settlement lifecycle: calculate net amount, generate batch, maker-checker approval, execute via adapter, post double-entry ledger journal.

**Independent Test (SC-6 from quickstart.md)**: Create settlement → submit → same-maker approve fails 422 → checker approves → execute → status=executed; `ledger_transaction_id` non-null; idempotent create returns same batch (SC-7).

- [X] T050 [US12] Implement `app/Modules/Reconciliation/Application/Services/SettlementService.php` — inject: `LedgerModuleContract` (E07), `AuditServiceInterface`, `ReconciliationMetricsInterface`, DB facade; methods: `calculateAndCreate(array $data, string $makerId, string $ip, string $idempotencyKey): SettlementBatch` — check UNIQUE idempotency_key → return existing if found; query `reconciliation_items` for matched items in period (or use explicit `reconciliation_item_ids`); sum amounts with `bcadd()` → `gross_amount`; `fee_amount` from `config('reconciliation.default_fee_rate', '0')`; `net_amount = bcsub(gross, fee, 4)`; create `SettlementBatch` status=draft; bulk-insert `SettlementItem` rows; emit `reconciliation.settlement.created` audit; `submitForApproval(string $batchId, string $makerId, string $ip): SettlementBatch` — 409 if not draft; transition to pending_approval; emit audit; `approveSettlement(string $batchId, string $checkerId, string $reason, string $ip, string $idempotencyKey): SettlementBatch` — wrap entire body in `DB::connection('reconciliation')->transaction()`: (1) `lockForUpdate()` on batch; (2) 409 if status ≠ pending_approval; (3) 422 if `$checkerId == $batch->maker_id`; (4) persist status=approved, approved_at, checker_id; (5) emit `reconciliation.settlement.approved` audit; `rejectSettlement(string $batchId, string $checkerId, string $reason, string $ip): SettlementBatch` — wrapped in `DB::connection('reconciliation')->transaction()` with `lockForUpdate()`; 409 if not pending_approval; 422 if maker==checker; persist status=rejected, rejection_reason; emit audit
- [X] T051 [US15] Create `app/Modules/Reconciliation/Application/Jobs/ExecuteSettlementJob.php` — queue: `reconciliation`; `public int $tries = 3`; `public int $timeout = 300`; inject `SettlementAdapterInterface`, `LedgerModuleContract`, `ReconciliationMetricsInterface`; `handle()`: load `SettlementBatch` with `lockForUpdate()` inside `DB::connection('reconciliation')->transaction()`; skip if status ≠ approved (idempotency guard); set status=executing; call `SettlementAdapterInterface::execute($batch)` → store `$externalRef`; call `LedgerModuleContract::postEntry()` with `entry_type='settlement'` double-entry (DR settlement_suspense / CR provider_payable for provider_settlement; vice-versa for bank_settlement), idempotency_key `"settlement:{$batch->id}"`; set status=executed, executed_at, ledger_transaction_id, external_reference; emit `settlementAction('executed')` metric; on any exception: set status=failed; emit metric; **constitution P4**: if `postEntry()` throws, entire `DB::transaction()` rolls back → status reverts to approved (no partial outcome)
- [X] T052 [P] [US12] Create `app/Modules/Reconciliation/Infrastructure/Http/Requests/CreateSettlementRequest.php` — validate: `settlement_type` required in [provider_settlement, bank_settlement]; `period_start` required date; `period_end` required date after_or_equal:period_start; `currency_code` required in [BWP]; `provider_code` optional max:50; `reconciliation_item_ids` optional array of UUIDs; create `ApproveSettlementRequest.php` — validate: `reason` required min:10 max:2000; create `RejectSettlementRequest.php` — same validation as approve
- [X] T053 [P] [US12] Create `app/Modules/Reconciliation/Infrastructure/Http/Resources/SettlementSummaryResource.php` (id, settlement_type, period_start, period_end, currency_code, gross_amount, fee_amount, net_amount, status, maker_id, checker_id, approved_at, executed_at, ledger_transaction_id, created_at); create `SettlementDetailResource.php` (extends SettlementSummary + item_count, external_reference, rejection_reason)
- [X] T054 [US12] Implement `app/Modules/Reconciliation/Infrastructure/Http/Controllers/SettlementController.php` — inject `SettlementService`; methods: `list(Request $request)` (require `reconciliation.read`; filter: status, settlement_type, from/to; paginate); `show(string $id)` (require `reconciliation.read`; 404 if not found); `create(CreateSettlementRequest $request)` (require `reconciliation.write`; require `Idempotency-Key` header → 400 if missing; delegate to `calculateAndCreate()`; return 201 or 200); `submit(string $id)` (require `reconciliation.write`; delegate to `submitForApproval()`; 409 if not draft _(no Idempotency-Key required — the 409 guard is the natural idempotency mechanism; no money moves on submit)_); `approve(ApproveSettlementRequest $request, string $id)` (require `reconciliation.approve_settlement`; require `Idempotency-Key` header → 400 if missing; delegate to `approveSettlement()`; 409 if not pending; 422 if maker==checker); `reject(RejectSettlementRequest $request, string $id)` (require `reconciliation.approve_settlement`; delegate to `rejectSettlement()`; 409 if not pending; 422 if maker==checker); `execute(Request $request, string $id)` (require `reconciliation.execute_settlement`; require `Idempotency-Key` header → 400 if missing; 409 if not approved; dispatch `ExecuteSettlementJob`; return 202)
- [X] T055 [US12] Write unit tests `tests/Unit/Modules/Reconciliation/Services/SettlementServiceTest.php` — idempotent create: same key returns same batch; same maker cannot approve → 422; checker approves → status=approved inside DB transaction; concurrent approve: two simultaneous requests → exactly one 200 and one 409, exactly one row with status=approved in DB (constitution P4 concurrency); rejectSettlement → status=rejected; `ExecuteSettlementJob`: adapter called once; `LedgerModuleContract::postEntry()` called with entry_type='settlement'; ledger throws → DB::transaction rolls back → status reverts to approved _(requires Docker for concurrency assertion)_
- [X] T056 [US12] Write integration tests `tests/Feature/Modules/Reconciliation/SettlementTest.php` — SC-6: full lifecycle create→submit→maker-checker violation→checker approves→execute→executed; ledger_transaction_id non-null; SC-7: idempotent create → same ID (HTTP 200); missing Idempotency-Key on create → 400; missing Idempotency-Key on approve → 400; 403 without reconciliation.approve_settlement; 403 without reconciliation.execute_settlement _(requires Docker)_

**Checkpoint**: Full settlement lifecycle functional. SC-6 and SC-7 pass.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: PHPStan, OpenAPI consolidation, Next.js UI pages, test seeder, Docker quickstart validation.

- [X] T057 [P] Run PHPStan on `app/Modules/Reconciliation/` at level 6: `./vendor/bin/phpstan analyse app/Modules/Reconciliation --level=6`; fix all reported issues before marking complete _(requires Docker)_
- [X] T058 [P] Generate `openapi/v1/reconciliation.yaml` consolidating `contracts/recon-import.yaml`, `contracts/recon-matching.yaml`, `contracts/recon-settlement.yaml` into a single OpenAPI 3.1.0 document; place alongside `openapi/v1/admin.yaml`
- [X] T059 [P] Implement Next.js reconciliation batch list page `wallet-ecosystem/apps/admin/app/(admin)/reconciliation/page.tsx` — fetch `GET /v1/reconciliation/batches`; render paginated `BatchSummaryCard` list with source_type badge and status chip; include file upload form triggering `POST /v1/reconciliation/batches/file`; show matched/unmatched/exception counts per batch row
- [X] T060 [P] Implement Next.js batch detail page `wallet-ecosystem/apps/admin/app/(admin)/reconciliation/[id]/page.tsx` — fetch batch + items; tabbed view: All / Matched / Unmatched / Exceptions; `MarkReconciledModal` for unmatched items; `TriggerMatchButton` for re-matching; create `wallet-ecosystem/apps/admin/app/(admin)/reconciliation/[id]/exceptions/page.tsx` for exception list + resolve modal
- [X] T061 [P] Implement Next.js settlement pages — `wallet-ecosystem/apps/admin/app/(admin)/reconciliation/settlements/page.tsx` (list with status filter + "New Settlement" button opening create form); `wallet-ecosystem/apps/admin/app/(admin)/reconciliation/settlements/[id]/page.tsx` (detail with Submit/Approve/Reject/Execute action buttons gated by status and permission; show maker-checker warning if same user attempts approve)
- [X] T062 [P] Create `database/seeders/ReconciliationTestSeeder.php` — guard: first line `if (! app()->isLocal() && ! app()->environment('testing')) { return; }`; seed: 1 provider file batch (3 items: 1 matched, 1 fuzzy-matched, 1 unmatched with open exception); 1 bank statement batch (2 items: 1 matched, 1 unmatched); 1 settlement batch in pending_approval status with net_amount > 0; register in `DatabaseSeeder::run()`
- [X] T063 [P] Run quickstart.md SC-1 through SC-8 validation against running Docker stack; document pass/fail results in `specs/020-reconciliation-settlement/checklists/docker-validation.md` _(requires live Docker stack)_

**Checkpoint**: PHPStan clean; all SC-1–SC-8 quickstart scenarios pass; Next.js pages functional.

---

## Dependencies & Execution Order

### Phase Dependencies

| Phase | Depends On | Can Parallelise With |
|-------|-----------|----------------------|
| Phase 1 (Setup) | None | — |
| Phase 2 (Foundational) | Phase 1 complete | — |
| Phase 3 (Provider Import US1–2) | Phase 2 complete | Phases 4–6 (if staffed) |
| Phase 4 (Matching+Exceptions US3–7) | Phase 2 + Phase 3 (import service + batch model) | Phase 5 |
| Phase 5 (Bank Statement US8–11) | Phase 2 + Phase 3 (import service reuse) | Phase 4, 6 |
| Phase 6 (Settlement US12–16) | Phase 2 + Phase 4 (ReconciliationItem.status=matched needed for settlement items) | Phase 5 |
| Phase 7 (Polish) | All story phases complete | — |

### User Story Dependencies

- **US-RECON-001–002** (Phase 3): Foundational only — import creates batches
- **US-RECON-003–007** (Phase 4): Requires Phase 3 (import service, batch/item models)
- **US-RECON-008–011** (Phase 5): Requires Phase 3 (import service reused for bank_statement source_type)
- **US-RECON-012–013** (Phase 6): Requires Phase 4 (matched items needed for settlement calculation)
- **US-RECON-014–016** (Phase 6): Requires US-RECON-012–013 (SettlementService already wired)

---

## Parallel Execution Examples

### Phase 2 Parallelizable Tasks

```bash
# All enums/interfaces/migrations/models are independent files — run together:
T009  BatchSourceType enum
T010  BatchStatus enum
T011  ItemStatus enum
T012  ExceptionType enum
T013  ExceptionStatus enum
T014  SettlementStatus enum
T015  ProviderTransactionDto
T016  ProviderFileAdapterInterface
T017  ProviderWebhookAuthenticatorInterface
T018  SettlementAdapterInterface
T019  ReconciliationMetricsInterface
T020  create_reconciliation_batches_table migration
T021  create_reconciliation_items_table migration
T022  create_reconciliation_exceptions_table migration
T023  create_settlement_batches_table migration
T024  create_settlement_items_table migration
T025  ReconciliationBatch model
T026  ReconciliationItem model
T027  ReconciliationException model
T028  SettlementBatch model
T029  SettlementItem model
T030  CsvProviderFileAdapter
T031  HmacWebhookAuthenticator
T032  NullSettlementAdapter
# Then sequentially:
T033  ReconciliationMetricsService (binds to interface — depends on T019)
```

### Phase 3 Parallelizable Tasks

```bash
# Resources and requests run while service is being implemented:
T035  ImportProviderFileRequest + ReceiveProviderApiRequest
T036  BatchSummaryResource + BatchDetailResource
# Then service → controller → job → tests:
T034  ReconciliationImportService
T037  ReconciliationBatchController (depends on T034, T035, T036)
T038  ProcessReconciliationBatchJob (depends on T037)
T039  ImportTest (Docker)
```

---

## Implementation Strategy

### MVP First (US-RECON-001–002 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL — blocks all stories)
3. Complete Phase 3: Provider Import (US-RECON-001–002)
4. **STOP and VALIDATE**: `POST /v1/reconciliation/batches/file` creates batch, items, and queues job (SC-1, SC-2)
5. Demo: file upload → batch created → job processing

### Incremental Delivery

1. Setup + Foundational → infrastructure ready
2. Phase 3 (Provider Import) → MVP: can ingest provider files
3. Phase 4 (Matching + Exceptions) → full provider reconciliation cycle
4. Phase 5 (Bank Statements) → bank reconciliation added
5. Phase 6 (Settlement) → settlement workflow completes the epic
6. Phase 7 (Polish) → production-ready

### Parallel Team Strategy

With multiple developers:
- After Phase 2: Developer A works Phase 3 + 4; Developer B starts Phase 5; Developer C researches settlement approach for Phase 6
- Once matching is complete (T040): Developer C starts Phase 6 settlement in parallel with Developer B completing Phase 5
