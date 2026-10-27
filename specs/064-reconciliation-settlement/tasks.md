# Tasks: P17 — Reconciliation Clearing and Provider Settlement

**Branch**: `064-reconciliation-settlement`
**Feature dir**: `specs/064-reconciliation-settlement/`
**Input artifacts**: plan.md, spec.md, data-model.md, contracts/statement-api.yaml, contracts/statement-events.yaml, research.md, quickstart.md

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (US1–US5)

## Path convention

All paths relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup

**Purpose**: Verify branch state; no file output.

- [X] T001 Verify feature branch `064-reconciliation-settlement` is checked out and all plan artifacts exist under `specs/064-reconciliation-settlement/`

---

## Phase 2: Foundational (Migrations + Shared Domain Types)

**Purpose**: Database schema and shared domain types that ALL user stories depend on. MUST complete before any story work.

- [X] T002 Create migration `2026_10_25_000014_create_provider_statements_table.php` in `database/migrations/` — columns: id (uuid v7), provider_code, account_id, environment, currency_code, period_start, period_end, source_hash (UNIQUE), status, total_lines, matched_lines, exception_lines, submitted_by (nullable uuid), ingested_at, matching_completed_at (nullable), timestamps; indexes: (provider_code, period_start, period_end), (status), (created_at)
- [X] T003 Create migration `2026_10_25_000015_create_provider_statement_lines_table.php` in `database/migrations/` — columns: id (uuid v7), statement_id (FK), line_type, provider_reference_id, amount_minor (bigint), currency_code, direction, settled_at, match_status, matched_entity_type (nullable), matched_entity_id (nullable uuid), clearing_record_id (nullable uuid), exception_id (nullable uuid), metadata (jsonb nullable), timestamps; indexes: (statement_id), (provider_reference_id), (match_status), (clearing_record_id), (matched_entity_id)
- [X] T004 Create migration `2026_10_25_000016_create_clearing_records_table.php` in `database/migrations/` — columns: id (uuid v7), payment_attempt_id (UNIQUE uuid FK), statement_id (uuid FK), statement_line_id (uuid FK), provider_code, currency_code, cleared_amount_minor (bigint), attempt_amount_minor (bigint), clearing_status varchar(30) NOT NULL DEFAULT 'cleared' CHECK (clearing_status IN ('cleared','bank_settled')), accounting_status varchar(40) NOT NULL, bank_settlement_batch_ref (nullable), bank_settled_at (nullable timestamptz), aged_at (nullable timestamptz), cleared_at, timestamps; indexes: UNIQUE (payment_attempt_id), (statement_id), (accounting_status), (clearing_status), (bank_settled_at)
- [X] T005 Create migration `2026_10_25_000017_create_reconciliation_correction_requests_table.php` in `database/migrations/` — columns: id (uuid v7), exception_id (uuid FK), idempotency_key (UNIQUE varchar 200), correction_type, description (text), evidence_note (text NOT NULL — required free-text rationale, min 10 chars enforced at service layer), evidence_reference (varchar 500 nullable — optional URL/document ref), debit_account (nullable), credit_account (nullable), amount_minor (bigint min 1), currency_code, status, requested_by (uuid), reviewed_by (nullable uuid), reviewed_at (nullable), rejection_reason (nullable text), ledger_entry_id (nullable varchar), posted_at (nullable), timestamps; indexes: UNIQUE (idempotency_key), (exception_id), (status), (requested_by), (reviewed_by)
- [X] T006 Create migration `2026_10_25_000018_alter_reconciliation_exceptions_add_owner_ageing.php` in `database/migrations/` — ADD COLUMN owner varchar(100) nullable; ADD COLUMN aged_at timestamptz nullable; DROP CONSTRAINT IF EXISTS recon_exceptions_type_check; ADD CONSTRAINT recon_exceptions_type_check CHECK (exception_type IN ('missing_callback','unknown_outcome','amount_mismatch','duplicate_transaction','orphan_provider','failed_refund','unmatched_ledger','contradictory_evidence','excess_capture','duplicate_capture','accounting_failure','unsupported_refund_method','definitive_refund_failure','unmatched_dispute_event','orphan_fee','partial_settlement')); DROP CONSTRAINT IF EXISTS recon_exceptions_status_check; ADD CONSTRAINT recon_exceptions_status_check CHECK (status IN ('open','under_investigation','resolved','escalated','acknowledged_external'))
- [X] T007 [P] Create enum `ProviderStatementStatus.php` in `app/Modules/Payment/Domain/Enums/` — cases: PendingMatch, Matching, Matched, PartiallyMatched, ExceptionRaised, Closed; add value() method returning snake_case string
- [X] T008 [P] Create enum `StatementLineType.php` in `app/Modules/Payment/Domain/Enums/` — cases: Capture, Fee, Refund, Reversal, Chargeback, InternalTransfer, Adjustment; add value() method
- [X] T009 [P] Create enum `StatementLineMatchStatus.php` in `app/Modules/Payment/Domain/Enums/` — cases: Pending, Matched, AmountMismatch, NoPlatformRecord, InternalTransfer, ExceptionRaised; add value() method
- [X] T010 [P] Create enum `CorrectionRequestStatus.php` in `app/Modules/Payment/Domain/Enums/` — cases: PendingApproval, Approved, Rejected, Posted, PostingFailed; add value() method
- [X] T011 [P] Extend `ReconciliationExceptionType.php` in `app/Modules/Payment/Domain/Enums/` — add two new cases: `OrphanFee` (value: 'orphan_fee') and `PartialSettlement` (value: 'partial_settlement'); verify all 16 cases match the constraint added in T006
- [X] T012 [P] Create `StatementIngestionCompletedEvent.php` in `app/Modules/Payment/Domain/Events/` — implements IntegrationEventInterface; constructor: statementId, providerCode, accountId, environment, currencyCode, periodStart, periodEnd, totalLines, sourceHash, ingestedAt; eventName(): 'payment.statement.ingestion_completed'
- [X] T013 [P] Create `PayoutQualifiedEvent.php` in `app/Modules/Payment/Domain/Events/` — implements IntegrationEventInterface; constructor takes PayoutQualificationSummary VO; eventName(): 'payment.reconciliation.payout_qualified'; idempotencyKey(): "payout.qualified:{statementId}:{versionHash}"
- [X] T013b [P] Create `StatementMatchingCompletedEvent.php` in `app/Modules/Payment/Domain/Events/` — implements IntegrationEventInterface; eventName(): 'payment.statement.matching_completed'; idempotencyKey(): "statement:matched:{statementId}"; constructor fields: statementId (string), finalStatus (ProviderStatementStatus), matchedLines (int), exceptionLines (int), totalLines (int), completedAt (Carbon); toArray() returns all fields; referenced in T034 (publish) and T039 (test)
- [X] T014 [P] Create `PayoutQualificationSummary.php` VO in `app/Modules/Payment/Domain/ValueObjects/` — readonly class; fields: statementId, providerCode, currencyCode, periodStart (Carbon), periodEnd (Carbon), grossClearedMinor (int), feeDeductionsMinor (int), disputeHoldDeductionsMinor (int), netQualifiedMinor (int), excludedAmountMinor (int), openExceptionCount (int), computedAt (Carbon), versionHash (string); add toArray()
- [X] T015 [P] Create `StatementAlreadyExistsException.php` in `app/Modules/Payment/Domain/Exceptions/` — extends RuntimeException; static withHash(string $hash, string $existingId): self
- [X] T016 [P] Create `CorrectionApprovalException.php` in `app/Modules/Payment/Domain/Exceptions/` — extends RuntimeException; static selfApprovalNotPermitted(): self; static notInPendingState(string $currentStatus): self

**Checkpoint**: Migrations and enums ready. User story implementation can begin.

---

## Phase 3: US1 — Provider Statement Ingestion (P1) 🎯 MVP

**Goal**: Authenticated provider can submit a settlement statement; duplicates are rejected via content hash; statement lines are persisted atomically; StatementMatchingJob is dispatched.

**Independent Test**: POST to `/api/v1/payment/provider/statement-callback` with a valid payload returns 202; re-submitting the same payload returns 409 (SC-001).

- [X] T017 [P] [US1] Create `ProviderStatementRepositoryInterface.php` in `app/Modules/Payment/Domain/Contracts/` — methods: create(array $data): ProviderStatementModel; findByHash(string $hash): ?ProviderStatementModel; findById(string $id): ?ProviderStatementModel; list(array $filters, int $perPage): LengthAwarePaginator; updateStatus(string $id, ProviderStatementStatus $status, array $extra = []): void; incrementMatchedLines(string $id, int $matched, int $exceptions): void
- [X] T018 [P] [US1] Create `ProviderStatementModel.php` in `app/Modules/Payment/Infrastructure/Models/` — extends Model; use HasUuids; table 'payment.provider_statements'; casts: status → ProviderStatementStatus, period_start/period_end/ingested_at/matching_completed_at → datetime; relationship: lines() hasMany ProviderStatementLineModel
- [X] T019 [P] [US1] Create `ProviderStatementLineModel.php` in `app/Modules/Payment/Infrastructure/Models/` — extends Model; use HasUuids; table 'payment.provider_statement_lines'; casts: line_type → StatementLineType, match_status → StatementLineMatchStatus, settled_at → datetime, metadata → array; belongs to ProviderStatementModel
- [X] T020 [US1] Create `EloquentProviderStatementRepository.php` in `app/Modules/Payment/Infrastructure/Repositories/` — implements ProviderStatementRepositoryInterface; all interface methods; list() applies filters: provider_code, status, currency_code, period_from/to; orders by created_at desc
- [X] T021 [US1] Create `ProviderStatementService.php` in `app/Modules/Payment/Application/Services/` — inject ProviderStatementRepositoryInterface, OutboxEventPublisher, AuditServiceInterface; method `ingest(array $statementData, ?string $actorId): ProviderStatementModel` — compute source_hash (SHA-256 of "{provider_code}|{account_id}|{environment}|{period_start_iso}|{period_end_iso}|{sorted_provider_reference_ids_csv}"), check findByHash, throw StatementAlreadyExistsException if exists, DB::transaction to insert statement + lines atomically, after commit publish StatementIngestionCompletedEvent via outbox and dispatch StatementMatchingJob, audit 'payment.statement.ingested'
- [X] T022 [US1] Create `StatementMatchingJob.php` stub in `app/Modules/Payment/Application/Jobs/` — implements ShouldQueue; constructor: string $statementId; handle(): calls StatementMatchingService::matchStatement($this->statementId); marked for completion in US2 (T031); stub logs 'matching_started' and updates statement status to 'matching'
- [X] T023 [US1] Create `BankStatementCallbackController.php` in `app/Modules/Payment/Http/Controllers/` — single action POST; validate payload against StatementIngestionPayload contract (provider_code required, account_id required, environment enum, currency_code, period_start/period_end datetime, lines array with line_type/provider_reference_id/amount_minor/currency_code/direction/settled_at); call ProviderStatementService::ingest(); return 202 StatementIngestionAccepted; catch StatementAlreadyExistsException → 409
- [X] T024 [P] [US1] Create `ProviderStatementListResource.php` in `app/Modules/Payment/Http/Resources/` — fields from data-model: id, provider_code, account_id, currency_code, period_start, period_end, status, total_lines, matched_lines, exception_lines, ingested_at
- [X] T025 [P] [US1] Create `ProviderStatementDetailResource.php` in `app/Modules/Payment/Http/Resources/` — extends list fields; adds source_hash, matching_completed_at; includes paginated lines collection (StatementLineItemResource with id, line_type, provider_reference_id, amount_minor, currency_code, direction, settled_at, match_status, matched_entity_type, matched_entity_id, clearing_record_id, exception_id)
- [X] T026 [US1] Create `ProviderStatementAdminController.php` in `app/Modules/Payment/Http/Controllers/Admin/` — index(): filters (provider_code, status, currency_code, period_from/to), paginated ProviderStatementListResource; show(): load statement with lines (filtered by match_status if provided), return ProviderStatementDetailResource; authorize payment.statement.view
- [X] T027 [US1] Register P17 routes in `routes/modules/payment.php` — add: POST /payment/provider/statement-callback → BankStatementCallbackController; GET /admin/payment/provider-statements → ProviderStatementAdminController@index; GET /admin/payment/provider-statements/{statementId} → show; (qualify and clearing/corrections routes added in later stories); bind StatementMatchingJob in service provider
- [X] T028 [US1] Extend `PaymentServiceProvider.php` in `app/Modules/Payment/Providers/` — bind ProviderStatementRepositoryInterface → EloquentProviderStatementRepository
- [X] T029 [US1] Extend `PaymentPermissionRoleSeeder.php` in `database/seeders/` — add permissions: payment.statement.ingest, payment.statement.view; assign to payment_admin and finance_ops roles
- [X] T030 [US1] Write unit test `ProviderStatementIngestionTest.php` in `app/Modules/Payment/Tests/Unit/` — 6 tests: SHA-256 hash computed deterministically from canonical string, same content different order produces same hash, duplicate hash throws StatementAlreadyExistsException, StatementIngestionCompletedEvent published after commit, StatementMatchingJob dispatched after commit, DB transaction rollback on partial failure does not persist any lines

**Checkpoint**: US1 complete — POST /payment/provider/statement-callback returns 202; duplicate returns 409.

---

## Phase 4: US2 — Statement Line Matching (P1)

**Goal**: Ingested statement lines are matched against platform payment records; unmatched lines become typed ReconciliationExceptions with owner and ageing; ClearingRecord created for matched capture lines.

**Independent Test**: After StatementMatchingJob runs, a capture line whose provider_reference_id matches an existing succeeded PaymentAttempt has match_status = 'matched' and a ClearingRecord exists. An unmatched capture line has match_status = 'exception_raised' and a ReconciliationException of type 'orphan_provider' exists.

- [X] T031 [P] [US2] Create `ClearingRecordRepositoryInterface.php` in `app/Modules/Payment/Domain/Contracts/` — methods: create(array $data): ClearingRecordModel; findByAttemptId(string $attemptId): ?ClearingRecordModel; list(array $filters, int $perPage): LengthAwarePaginator
- [X] T032 [P] [US2] Create `ClearingRecordModel.php` in `app/Modules/Payment/Infrastructure/Models/` — extends Model; use HasUuids; table 'payment.clearing_records'; casts: clearing_status → string ('cleared'|'bank_settled'), accounting_status → string, cleared_at/bank_settled_at/aged_at → datetime nullable; relationships: attempt() belongsTo PaymentAttemptModel, statement() belongsTo ProviderStatementModel; scopes: scopeCleared() (clearing_status='cleared'), scopeBankSettled() (clearing_status='bank_settled')
- [X] T033 [US2] Create `EloquentClearingRecordRepository.php` in `app/Modules/Payment/Infrastructure/Repositories/` — implements ClearingRecordRepositoryInterface; list() filters: provider_code, accounting_status, aged (aged_at IS NOT NULL), bank_settled (bank_settled_at IS [NOT] NULL); orders by cleared_at desc
- [X] T034 [US2] Create `StatementMatchingService.php` in `app/Modules/Payment/Application/Services/` — inject PaymentAttemptRepositoryInterface, PaymentRefundRepositoryInterface, PaymentDisputeCaseRepositoryInterface, ClearingRecordRepositoryInterface, ReconciliationExceptionService, OutboxEventPublisher; method `matchStatement(string $statementId): void` — load statement with lines in chunks of 200; per line type: capture → find PaymentAttemptModel by provider_reference_id+amount_minor+currency_code+status=succeeded; fee → find attempt fee; refund → find PaymentRefundModel by provider_submitted_ref+amount_minor; reversal/chargeback → find PaymentDisputeCaseModel by provider_dispute_reference+disputed_amount_minor; internal_transfer → classify directly as StatementLineMatchStatus::InternalTransfer (no ClearingRecord); on match: update line match_status=matched, matched_entity_type/id; create ClearingRecord for capture type; on no match: create ReconciliationException (orphan_provider for capture, orphan_fee for fee, others → amount_mismatch or no_platform_record); update line match_status; after all lines: update statement status + matched_lines/exception_lines counts; **bottom-up MissingCallback detection**: query PaymentAttemptModel WHERE status=succeeded AND provider_code=statement.provider_code AND provider_reference_id IS NOT NULL AND created_at BETWEEN period_start AND period_end; exclude any attempt.id that already appears in matched capture lines from this statement; for each orphaned attempt create ReconciliationException(MissingCallback) with owner='finance_ops' and payload ['statement_id'=>statementId, 'period_start'=>..., 'period_end'=>...]; publish StatementMatchingCompletedEvent (T013b) via outbox; dispatch PayoutQualificationJob if status=matched|partially_matched
- [X] T035 [US2] Create `ClearingService.php` in `app/Modules/Payment/Application/Services/` — inject ClearingRecordRepositoryInterface, ConfigurationServiceInterface, AuditServiceInterface; method `createFromMatch(string $attemptId, string $statementId, string $statementLineId, string $providerCode, string $currencyCode, int $clearedAmountMinor, int $attemptAmountMinor): ClearingRecordModel` — determine accounting_status: if provider requires AD-08/AD-09 resolution → pending_ledger_config, else qualified; set cleared_at; audit 'payment.clearing.created'; return model
- [X] T036 [US2] Complete `StatementMatchingJob.php` in `app/Modules/Payment/Application/Jobs/` — replace stub: inject StatementMatchingService via handle(StatementMatchingService $service); call $service->matchStatement($this->statementId); uses ShouldBeUniqueUntilProcessing to prevent concurrent runs on same statementId; retry(3) with exponential backoff
- [X] T037 [US2] Extend `ReconciliationExceptionService::create()` — accept new optional `owner` field from the data array and persist to `reconciliation_exceptions.owner` (column added in T006 migration); no other interface changes
- [X] T038 [US2] Extend `ProviderStatementAdminController.php` admin show() to pass `match_status` filter through to repository; bind ClearingRecordRepositoryInterface → EloquentClearingRecordRepository in PaymentServiceProvider (T028 extension)
- [X] T039 [US2] Write unit test `StatementMatchingServiceTest.php` in `app/Modules/Payment/Tests/Unit/` — 8 tests: capture line matched → ClearingRecord created with accounting_status=qualified, capture line not found → exception type=orphan_provider, fee line not found → exception type=orphan_fee, refund line matched → no ClearingRecord, internal_transfer line → match_status=internal_transfer no ClearingRecord, amount_mismatch → exception type=amount_mismatch, StatementMatchingCompletedEvent published after all lines, PayoutQualificationJob dispatched when partially_matched

**Checkpoint**: US2 complete — StatementMatchingJob processes lines; matched captures have ClearingRecord; exceptions typed and visible.

---

## Phase 5: US3 — Capture Clearing vs Provider Settlement (P2)

**Goal**: Bank settlement batch events update ClearingRecord with settlement confirmation; aging check raises PartialSettlement exceptions for stale clearing records; admin clearing-record endpoint available.

**Independent Test**: A ClearingRecord with bank_settled_at=null and cleared_at > ageing_window has a PartialSettlement exception and aged_at set after SettlementAgeingCheckJob runs (SC-008).

- [X] T040 [P] [US3] Create `BankSettlementBatchHandler.php` in `app/Modules/Payment/Application/EventHandlers/` — implements InboxEventHandlerInterface (or equivalent E25 pattern); event: 'bank.settlement.batch'; method handle(array $payload): void — for each line in payload.lines, find ClearingRecord by provider_reference_id (via ClearingRecordRepositoryInterface); if found, update bank_settlement_batch_ref and bank_settled_at; if not found, log warning 'bank_settlement.unmatched_line'; after all lines: audit 'payment.settlement.batch_received'
- [X] T041 [US3] Create `SettlementAgeingCheckJob.php` in `app/Modules/Payment/Application/Jobs/` — implements ShouldQueue; handle(): read ageing_threshold_days from ConfigurationServiceInterface key 'payment.reconciliation.settlement_ageing_days' (default 7); query ClearingRecord WHERE bank_settled_at IS NULL AND aged_at IS NULL AND cleared_at < now()->subDays($threshold); for each: call ReconciliationExceptionService::create(type=PartialSettlement, owner='finance_ops', payload=['clearing_record_id'=>...,'ageing_threshold_days'=>$threshold]); update ClearingRecord aged_at = now(); publish ageing_exception_raised outbox event; emit info log 'settlement.ageing_check.completed' with count
- [X] T042 [US3] Extend `ClearingService.php` — add method `matchBankSettlementBatch(string $providerCode, string $batchRef, Carbon $settledAt, array $lines): array` — returns ['matched'=>int,'unmatched'=>int]; called by BankSettlementBatchHandler; DB::transaction to bulk-update each matched ClearingRecord: set clearing_status='bank_settled', bank_settlement_batch_ref=$batchRef, bank_settled_at=$settledAt; unmatched lines: log warning 'bank_settlement.unmatched_line' (no exception); audit 'payment.clearing.bank_settled'
- [X] T043 [P] [US3] Create `ClearingRecordListResource.php` in `app/Modules/Payment/Http/Resources/` — fields: id, payment_attempt_id, statement_id, provider_code, currency_code, cleared_amount_minor, attempt_amount_minor, accounting_status, bank_settlement_batch_ref, bank_settled_at, aged_at, cleared_at
- [X] T044 [US3] Add admin clearing records list to `ProviderStatementAdminController.php` OR create `ClearingRecordAdminController.php` in `app/Modules/Payment/Http/Controllers/Admin/` — GET /admin/payment/clearing-records: filters (provider_code, accounting_status, aged bool, bank_settled bool); paginated ClearingRecordListResource; authorize payment.statement.view
- [X] T045 [US3] Register `SettlementAgeingCheckJob` in `routes/console.php` — `Schedule::job(SettlementAgeingCheckJob::class)->daily()->withoutOverlapping();`; register BankSettlementBatchHandler as inbox event listener in PaymentServiceProvider for event 'bank.settlement.batch'
- [X] T046 [US3] Register clearing record admin route in `routes/modules/payment.php` — GET /admin/payment/clearing-records; bind ClearingService in PaymentServiceProvider
- [X] T047 [US3] Write unit test `ClearingServiceTest.php` in `app/Modules/Payment/Tests/Unit/` — 7 tests: createFromMatch sets accounting_status=qualified for non-AD08/09 provider, createFromMatch sets accounting_status=pending_ledger_config for bank-payment provider, createFromMatch sets clearing_status='cleared' on creation, matchBankSettlementBatch transitions clearing_status from 'cleared' to 'bank_settled' for matched lines, matchBankSettlementBatch sets bank_settled_at and bank_settlement_batch_ref, unmatched bank settlement line logs warning (no exception created), ageing threshold read from ConfigurationServiceInterface not hard-coded

**Checkpoint**: US3 complete — bank settlement updates ClearingRecord; ageing exceptions raised daily; clearing admin endpoint available.

---

## Phase 6: US4 — Exception Management and Correction Approval (P2)

**Goal**: Finance operators can submit correction requests for reconciliation exceptions; a second distinct operator must approve; corrections are posted via LedgerModuleContract; self-approval is rejected 403.

**Independent Test**: Submit a correction as User A; attempt approve as User A → 403; approve as User B → 200 with ledger_entry_id populated (SC-006).

- [X] T048 [P] [US4] Create `ReconciliationCorrectionRepositoryInterface.php` in `app/Modules/Payment/Domain/Contracts/` — methods: create(array $data): ReconciliationCorrectionRequestModel; findByIdempotencyKey(string $key): ?ReconciliationCorrectionRequestModel; findById(string $id): ?ReconciliationCorrectionRequestModel; findByExceptionId(string $exceptionId): ?ReconciliationCorrectionRequestModel; list(array $filters, int $perPage): LengthAwarePaginator; updateStatus(string $id, CorrectionRequestStatus $status, array $extra = []): void
- [X] T049 [P] [US4] Create `ReconciliationCorrectionRequestModel.php` in `app/Modules/Payment/Infrastructure/Models/` — extends Model; use HasUuids; table 'payment.reconciliation_correction_requests'; casts: status → CorrectionRequestStatus, reviewed_at/posted_at → datetime nullable
- [X] T050 [US4] Create `EloquentReconciliationCorrectionRepository.php` in `app/Modules/Payment/Infrastructure/Repositories/` — implements ReconciliationCorrectionRepositoryInterface; list() filters: status, exception_id; orders by created_at desc
- [X] T051 [US4] Create `ReconciliationCorrectionService.php` in `app/Modules/Payment/Application/Services/` — inject ReconciliationCorrectionRepositoryInterface, LedgerModuleContract, ReconciliationExceptionService, OutboxEventPublisher, AuditServiceInterface; method `submit(string $exceptionId, string $correctionType, string $description, string $evidenceNote, int $amountMinor, string $currencyCode, string $requestedBy, ?string $evidenceReference = null, ?string $debitAccount = null, ?string $creditAccount = null): ReconciliationCorrectionRequestModel` — validate strlen($evidenceNote) >= 10 (throw ValidationException if not); idempotency key "recon:{exceptionId}:correction"; check findByIdempotencyKey first (idempotent if pending); create with status=pending_approval, evidence_note=$evidenceNote, evidence_reference=$evidenceReference; audit 'payment.reconciliation.correction_submitted' with before state = exception.status; method `approve(string $correctionId, string $reviewerId): ReconciliationCorrectionRequestModel` — load request; throw CorrectionApprovalException::notInPendingState() if status != pending_approval; throw CorrectionApprovalException::selfApprovalNotPermitted() if reviewerId == requestedBy; call LedgerModuleContract::postEntry(debitAccount, creditAccount, amountMinor, currencyCode, idempotencyKey, metadata); on success: updateStatus(approved then posted, ledger_entry_id, posted_at, reviewed_by, reviewed_at); publish correction_posted event; audit 'payment.reconciliation.correction_approved'; method `reject(string $correctionId, string $reviewerId, string $reason): ReconciliationCorrectionRequestModel` — load; validate pending; updateStatus(rejected, rejection_reason, reviewed_by, reviewed_at); audit 'payment.reconciliation.correction_rejected'
- [X] T052 [P] [US4] Create `ReconciliationCorrectionRequestResource.php` in `app/Modules/Payment/Http/Resources/` — all fields from data-model: id, exception_id, idempotency_key, correction_type, description, evidence_reference, amount_minor, currency_code, status, requested_by, reviewed_by, reviewed_at, rejection_reason, ledger_entry_id, posted_at, created_at
- [X] T053 [US4] Create `ReconciliationCorrectionAdminController.php` in `app/Modules/Payment/Http/Controllers/Admin/` — index(): filters (status, exception_id), paginated ReconciliationCorrectionRequestResource; authorize payment.reconciliation.view_corrections; submit() [POST]: validate (exception_id required, correction_type required, description required min 10, evidence_note required min 10 — "Required: free-text rationale for the correction", amount_minor required min 1, currency_code required, evidence_reference optional URL max 500, debit_account optional, credit_account optional); call ReconciliationCorrectionService::submit(); return 201; catch idempotency key conflict → 409; approve() [POST /{id}/approve]: authorize payment.reconciliation.approve_correction; call ReconciliationCorrectionService::approve($correctionId, auth()->id()); catch CorrectionApprovalException::selfApprovalNotPermitted → 403; catch notInPendingState → 409; return 200; reject() [POST /{id}/reject]: validate rejection_reason min 10; call ReconciliationCorrectionService::reject(); return 200
- [X] T053b [US4] Add `acknowledgeExternal()` method to `ReconciliationExceptionService.php` and `acknowledgeExternal()` action [POST /admin/payment/reconciliation-exceptions/{exceptionId}/acknowledge-external] to `ReconciliationCorrectionAdminController.php` — validates: exceptionId must be an open exception of type orphan_provider (else 422); requires evidence_note (min 10) in request body; sets `ReconciliationException.status = 'acknowledged_external'` and `acknowledged_at = now()`; does NOT post any ledger entry; audits 'payment.reconciliation.exception_acknowledged_external' with actor_id, evidence_note, exception_id; returns 200; implements US4-4 (orphan confirmed as valid external charge)
- [X] T054 [US4] Register correction routes and permissions in `routes/modules/payment.php` and `PaymentPermissionRoleSeeder.php` — correction routes: GET /admin/payment/reconciliation-corrections, POST /, POST /{id}/approve, POST /{id}/reject; exception route: POST /admin/payment/reconciliation-exceptions/{exceptionId}/acknowledge-external (from T053b); permissions: payment.reconciliation.view_corrections (→ finance_ops, payment_admin), payment.reconciliation.approve_correction (→ payment_admin only), payment.reconciliation.manage (→ finance_ops, payment_admin — already exists, covers acknowledge-external); bind ReconciliationCorrectionRepositoryInterface → EloquentReconciliationCorrectionRepository in PaymentServiceProvider
- [X] T055 [US4] Write unit test `ReconciliationCorrectionServiceTest.php` in `app/Modules/Payment/Tests/Unit/` — 10 tests: submit creates pending_approval record, idempotency key "recon:{exceptionId}:correction" format, submit without evidence_note (empty string) throws ValidationException, submit twice same exception returns existing pending record (idempotent), approve by same actor as requester throws CorrectionApprovalException::selfApprovalNotPermitted, approve when status != pending_approval throws CorrectionApprovalException::notInPendingState, LedgerModuleContract::postEntry() called exactly once with correct COA and idempotency key, status=posted and ledger_entry_id set after successful approve, reject sets rejection_reason and reviewed_by, acknowledgeExternal() sets status=acknowledged_external and does NOT call LedgerModuleContract

**Checkpoint**: US4 complete — dual-approval enforced; LedgerModuleContract called; self-approval returns 403.

---

## Phase 7: US5 — Payout Qualification and Totals Publishing (P2)

**Goal**: After statement matching, net qualified amounts are computed (excluding AD-08/09 records and dispute holds) and published as PayoutQualifiedEvent outbox event; admin can re-trigger qualification.

**Independent Test**: After triggering /qualify for a matched statement, outbox contains payment.reconciliation.payout_qualified with correct net_qualified_minor = gross_cleared - fees - dispute_holds (never negative) and excluded_amount_minor for pending_ledger_config records (SC-007).

- [X] T056 [US5] Create `PayoutQualificationService.php` in `app/Modules/Payment/Application/Services/` — inject ClearingRecordRepositoryInterface, PaymentRefundRepositoryInterface, PaymentDisputeCaseRepositoryInterface, ProviderStatementRepositoryInterface, OutboxEventPublisher, AuditServiceInterface; method `computeAndPublish(string $statementId): PayoutQualificationSummary` — load statement; query clearing records WHERE statement_id = statementId AND accounting_status = qualified; sum cleared_amount_minor → grossClearedMinor; query statement lines WHERE line_type = fee AND match_status = matched AND statement_id = statementId; sum amount_minor → feeDeductionsMinor; query open DisputeHold amounts for payment_attempt_ids in qualified clearing records via PaymentDisputeCaseRepositoryInterface; sum → disputeHoldDeductionsMinor; netQualifiedMinor = max(0, grossClearedMinor - feeDeductionsMinor - disputeHoldDeductionsMinor); query clearing records WHERE accounting_status = pending_ledger_config → excludedAmountMinor; count open exceptions for statement period → openExceptionCount; compute versionHash = hash('sha256', grossClearedMinor . feeDeductionsMinor . disputeHoldDeductionsMinor . openExceptionCount); build PayoutQualificationSummary; publish PayoutQualifiedEvent via outbox (idempotency key "payout.qualified:{statementId}:{versionHash}"); audit 'payment.payout.qualification_published'; return VO
- [X] T057 [US5] Create `PayoutQualificationJob.php` in `app/Modules/Payment/Application/Jobs/` — implements ShouldQueue; constructor: string $statementId; handle(PayoutQualificationService $service): void; calls $service->computeAndPublish($this->statementId); uses ShouldBeUniqueUntilProcessing
- [X] T058 [US5] Add `qualify()` method to `ProviderStatementAdminController.php` — POST /admin/payment/provider-statements/{statementId}/qualify; authorize payment.statement.view; validate statement status is matched or partially_matched (else 409); dispatch PayoutQualificationJob; return 202
- [X] T059 [US5] Register qualify route in `routes/modules/payment.php` — POST /admin/payment/provider-statements/{statementId}/qualify → ProviderStatementAdminController@qualify; bind PayoutQualificationService and PayoutQualificationJob in PaymentServiceProvider; ensure StatementMatchingJob chains PayoutQualificationJob in T036 (add dispatch at end of StatementMatchingJob::handle())
- [X] T060 [US5] Write unit test `PayoutQualificationServiceTest.php` in `app/Modules/Payment/Tests/Unit/` — 8 tests: gross_cleared_minor sums only accounting_status=qualified records, fee_deductions_minor sums matched fee lines for the statement, dispute_hold_deductions_minor deducts open DisputeHold amounts, net_qualified_minor cannot be negative (max 0), pending_ledger_config records excluded from net but counted in excluded_amount_minor, version_hash changes if dispute hold count changes, PayoutQualifiedEvent idempotency key includes version_hash, outbox event published after compute (not during DB transaction)

**Checkpoint**: US5 complete — qualification published; net_qualified_minor correctly excludes AD-08/09 and dispute holds.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: OpenAPI docs, operator runbook, PHPStan, Docker-gated feature tests, quickstart validation.

- [X] T061 [P] Extend `openapi/v1/payment.yaml` with P17 paths and schemas from `specs/064-reconciliation-settlement/contracts/statement-api.yaml` — append all P17 paths (provider-statement-callback, admin/provider-statements, admin/clearing-records, admin/reconciliation-corrections) and schemas (enums, request/response bodies) to the end of the existing payment.yaml
- [X] T062 [P] Create operator runbook `docs/payment/p17-reconciliation-operator-runbook.md` — sections: Statement Ingestion (trigger, dedup, hash format), Matching (job queue, chunk size, retry policy), Clearing (bank settlement batch handling, ageing window config key), Corrections (dual-approval workflow, ledger contract dependency, what to do on posting_failed), Qualification (event payload format, re-trigger procedure), Exception types table (all 16 types + owner assignment), Permissions table, Monitoring (queue depth for StatementMatchingJob, exception count metrics), Restart notes (idempotent re-dispatch, no duplicate ledger entries)
- [ ] T063 [P] Write Docker-gated feature test `ProviderStatementCallbackControllerTest.php` in `app/Modules/Payment/Tests/Feature/` — @group docker; 5 tests: valid payload returns 202 with statement_id, duplicate hash returns 409 with existing_statement_id, missing fields return 422, invalid webhook secret returns 401, valid payload dispatches StatementMatchingJob
- [ ] T064 [P] Write Docker-gated feature test `AdminProviderStatementControllerTest.php` in `app/Modules/Payment/Tests/Feature/` — @group docker; 6 tests: list returns paginated statements, list filters by status, list filters by provider_code, show returns statement with lines, show filters lines by match_status, qualify dispatches PayoutQualificationJob (or 409 if wrong status)
- [ ] T065 [P] Write Docker-gated feature test `ReconciliationCorrectionAdminControllerTest.php` in `app/Modules/Payment/Tests/Feature/` — @group docker; 9 tests: list returns paginated corrections, submit creates pending_approval record, submit without evidence_note returns 422, submit twice same exception returns 409 or existing (idempotent), approve by requester returns 403, approve by different actor with permission returns 200 with ledger_entry_id, approve without permission returns 403, reject updates status to rejected, acknowledge-external sets acknowledged_external status on eligible exception
- [ ] T065b [P] Instrument structured metrics in `ProviderStatementService` (import_count counter), `StatementMatchingService` (match_rate histogram per statement, exception_count counter by type), `ReconciliationCorrectionService` (correction_approval_lag histogram from submit to approve), `PayoutQualificationService` (qualification_latency histogram) — use platform MetricsClient (or equivalent observability client already used in P08/P15); covers FR-015 and Constitution P13
- [ ] T065c [P] Add performance benchmark and index validation — (1) PHPUnit test tagged @group performance: create 10,000 ProviderStatementLine records and verify StatementMatchingJob completes within 120s (SC-002 requirement is 60s; 120s is the Docker benchmark ceiling); (2) add query plan comment to `EloquentProviderStatementRepository::list()` and `ReconciliationExceptionService::listExceptions()` verifying (provider_code, period_start, period_end) and (type, owner, created_at) indexes are used — run EXPLAIN ANALYZE in a Docker test to confirm ≤500ms for 50,000 rows (SC-008)
- [ ] T066 Quickstart validation SC-001 to SC-008 — run against Docker stack per `specs/064-reconciliation-settlement/quickstart.md`; confirm all 8 scenarios pass; annotate any scenarios that need seeded fixtures; @group docker

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies
- **Foundational (Phase 2)**: Depends on Phase 1 — **BLOCKS all user stories**
- **US1 (Phase 3)**: Depends on Phase 2; T022 (StatementMatchingJob stub) must be a stub only — full matching logic lives in US2
- **US2 (Phase 4)**: Depends on US1 (ProviderStatementModel, service, job stub exist); T036 replaces the stub
- **US3 (Phase 5)**: Depends on US2 (ClearingRecordModel and Repository required); T045 depends on T033
- **US4 (Phase 6)**: Depends on Foundational (migrations, exception types); can run in parallel with US2/US3 if staffed
- **US5 (Phase 7)**: Depends on US2 (ClearingRecord populated) and US4 (DisputeHold query available)
- **Polish (Phase 8)**: Depends on all user stories complete

### Within Each User Story

- Repository interface → Model → Eloquent Repository (sequential)
- Domain types (enums, events, VOs) → Service (sequential)
- Service → Controller → Route registration (sequential)
- [P] tasks in same phase can run in parallel

### Parallel Opportunities

- T007–T016 (all Foundational enums/events/VOs/exceptions): all [P], run together
- T017–T025 (US1 interfaces, models, resources): all [P], run together then converge at T026 (service)
- T031–T032 (US2 repository interface + model): [P], then T033 (repository)
- T040 (US3 BankSettlementBatchHandler), T043 (ClearingRecordListResource): [P]
- T048–T049 (US4 repository interface + model): [P]
- T061–T065 (Polish tasks): all [P]

---

## Implementation Strategy

### MVP First (US1 + US2 Only)

1. Complete Phase 1: Setup verification
2. Complete Phase 2: Foundational (migrations + enums) — CRITICAL
3. Complete Phase 3: US1 — ingestion endpoint working, duplicates rejected
4. **VALIDATE**: POST to callback returns 202; duplicate returns 409; `provider_statements` table has row
5. Complete Phase 4: US2 — StatementMatchingJob classifies lines, ClearingRecords created
6. **VALIDATE**: Run queue worker; check line match_status; check clearing_records table
7. Deploy/demo if acceptable for phase

### Incremental Delivery

- US1 + US2 → core ingestion pipeline
- US3 → bank settlement confirmation + ageing
- US4 → exception governance (dual-approval corrections)
- US5 → payout qualification signal

### Parallel Team Strategy

With 3+ developers (after Phase 2 complete):
- Dev A: US2 (matching logic)
- Dev B: US4 (correction workflow)
- Dev C: US3 (bank settlement / ageing)
- Dev A (after US2): US5 (qualification)

---

## Notes

- All Docker-gated tasks tagged `@group docker` — skipped in non-Docker CI
- `LedgerModuleContract::postEntry()` mock in unit tests; real contract in Docker feature tests
- `PaymentDisputeCaseRepositoryInterface` must expose a method to sum open DisputeHold amounts for a list of attemptIds (verify this exists or add it in T056)
- The SettlementAgeingCheckJob (T041) checks `payment.reconciliation.settlement_ageing_days` from ConfigurationServiceInterface; ensure the config key exists in the configuration seeder
- AD-08/AD-09 provider detection logic (T035, T056): use a list of provider_codes that require bank-payment chart-of-accounts resolution; store in `config/payment.php` under `pending_ledger_config_providers`
- **C1 resolved**: T013b creates `StatementMatchingCompletedEvent.php`; referenced by T034 (publish) and T039 (test)
- **C2 resolved**: T034 includes bottom-up MissingCallback detection after processing all lines
- **I1/I2 resolved**: T004 adds `clearing_status` column (cleared → bank_settled lifecycle); T032 casts it; T042 transitions it; `accounting_status` remains for qualification readiness; net amounts remain computed at qualification time (not stored on ClearingRecord)
- **C3 resolved**: T053b adds `acknowledgeExternal()` method and endpoint for US4-4 OrphanProvider external confirmation
- **I5 resolved**: `evidence_note` (required text) and `evidence_reference` (optional URL) are separate fields; T005 migration + T051 service + T053 controller all enforce evidence_note as required
- **I6 resolved**: spec.md US5-2 updated to include versionHash in idempotency key format
- **T065b / D1 resolved**: metrics instrumentation task added for FR-015 / Constitution P13
- **T065c / D2 resolved**: performance benchmark task added for SC-002 (60s matching) and SC-008 (500ms admin API)
