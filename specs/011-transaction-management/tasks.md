# Tasks — E11 Transaction Management

**Input**: `specs/011-transaction-management/spec.md` + `plan.md`

**Module path**: `wallet-ecosystem/services/api/app/Modules/Transaction/`

**DB**: connection `platform`, schema prefix `transaction`

**Priority**: P0 stories (Phases 3–6) are MVP. P1 stories (Phases 7–8) build on top.

## Format: `[ID] [P?] [Story] Description with file path`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[TXN-xxx]**: Maps to story ID in spec.md

---

## Phase 1: Setup & Design Artifacts

**Purpose**: Resolve research dependencies, produce design artifacts, and create module skeleton.

**⚠️ REQUIRED BEFORE PHASE 2**: Phase 0 research (T001–T004) and Phase 1 design outputs (T005–T006) must be completed first.

- [X] T001 Confirm E07 LedgerModuleContract interface is stable — read wallet-ecosystem/services/api/app/Modules/Ledger/Domain/Contracts/LedgerModuleContract.php and verify: (a) `postEntry(array $lines, string $idempotencyKey, string $correlationId): JournalResult` exists, (b) `getJournalLines(string $journalId): array` or equivalent exists for reading original journal debit/credit lines needed by ReversalService::execute(); record both method signatures and error contracts in specs/011-transaction-management/research.md
- [X] T002 [P] Confirm E08–E10 outbox event payloads carry required projection fields (customer_id, wallet_id, amount, currency_code, direction, reference, type, status) — read E08/E09/E10 event class files and record findings in specs/011-transaction-management/research.md
- [X] T003 [P] Confirm Redis idempotency store TTL config key exists — read wallet-ecosystem/services/api/config/platform.php (or equivalent) for `transaction.idempotency.ttl_seconds`; add key if missing; record in specs/011-transaction-management/research.md
- [X] T004 [P] Confirm PostgreSQL tsvector is viable for US-TXN-010 search — verify PostgreSQL version supports GIN index on `to_tsvector` by reading existing migrations; record decision in specs/011-transaction-management/research.md
- [X] T005 Create specs/011-transaction-management/data-model.md — entity definitions, field types, indexes, FK constraints, and partial unique index on transaction.reversals for non-terminal status per plan.md Data Entities section
- [X] T006 [P] Create specs/011-transaction-management/contracts/api.yaml — OpenAPI 3.1.0 skeleton with all 12 E11 endpoints (paths, methods, bearerAuth security scheme, placeholder request/response schemas)
- [X] T007 Create TransactionServiceProvider with module registration (config, routes, migrations, event handlers) in wallet-ecosystem/services/api/app/Modules/Transaction/Providers/TransactionServiceProvider.php
- [X] T008 [P] Create module config file wallet-ecosystem/services/api/config/transaction.php — keys: `transaction.allowed_types`, `transaction.max_page_size`, `transaction.idempotency.ttl_seconds`, `transaction.reversal.max_age_days`, `transaction.reversal.require_maker_checker`
- [X] T009 [P] Create migration: create transaction.transaction_projections table with fields: id, reference, type, status, amount, currency_code, direction, wallet_id, customer_id, description, source_module, metadata, idempotency_key, correlation_id, ledger_journal_id, parent_transaction_id (UUID nullable, self-referencing FK for refund links), completed_at, failed_at, created_at, updated_at — add GIN index on tsvector(reference, description), indexes on customer_id, (customer_id, type), (customer_id, status), (customer_id, created_at DESC), and index on parent_transaction_id (nullable) — file: wallet-ecosystem/services/api/database/migrations/[timestamp]_create_transaction_projections_table.php
- [X] T010 [P] Create migration: create transaction.transaction_status_history table (id, transaction_id FK, previous_status, new_status, actor_id, actor_type, reason, occurred_at) with index on (transaction_id, occurred_at ASC) — file: wallet-ecosystem/services/api/database/migrations/[timestamp]_create_transaction_status_history_table.php
- [X] T011 [P] Create migration: create transaction.reversals table (all fields from plan.md) with partial unique index `UNIQUE (transaction_id) WHERE status IN ('pending_approval', 'approved')` to enforce single active reversal per transaction — file: wallet-ecosystem/services/api/database/migrations/[timestamp]_create_reversals_table.php
- [X] T012 [P] Create migration: create transaction.reversal_audit_log table (id, reversal_id FK, transaction_id, action, actor_id, actor_type, metadata, occurred_at) — file: wallet-ecosystem/services/api/database/migrations/[timestamp]_create_reversal_audit_log_table.php
- [X] T013 [P] Create migration: create transaction.transaction_receipts table (id, transaction_id FK UNIQUE, reference, amount, currency_code, type, channel, customer_id, created_at) — file: wallet-ecosystem/services/api/database/migrations/[timestamp]_create_transaction_receipts_table.php

**Checkpoint**: Research documented in research.md, data-model.md and contracts/api.yaml created, migrations ready, module skeleton exists.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core enums, models, and repositories that ALL user story phases depend on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T014 Create TransactionStatus enum (pending, processing, completed, failed, cancelled, reversed) with isTerminal() and allowedTransitionsFrom() methods in wallet-ecosystem/services/api/app/Modules/Transaction/Domain/Enums/TransactionStatus.php
- [X] T015 [P] Create ReversalStatus enum (pending_approval, approved, rejected, executed, failed) with isTerminal() method in wallet-ecosystem/services/api/app/Modules/Transaction/Domain/Enums/ReversalStatus.php
- [X] T016 [P] Create TransactionType enum (funding, transfer, withdrawal, bill_payment, reversal, refund, fee, commission) in wallet-ecosystem/services/api/app/Modules/Transaction/Domain/Enums/TransactionType.php
- [X] T017 [P] Create TransactionProjectionModel (connection: platform, table: transaction.transaction_projections, casts for enums, fillable fields) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Models/TransactionProjectionModel.php
- [X] T018 [P] Create TransactionStatusHistoryModel (connection: platform, table: transaction.transaction_status_history) — override save() and delete() to throw \RuntimeException('TransactionStatusHistory is append-only') in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Models/TransactionStatusHistoryModel.php
- [X] T019 [P] Create ReversalModel (connection: platform, table: transaction.reversals, casts for ReversalStatus enum) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Models/ReversalModel.php
- [X] T020 [P] Create ReversalAuditLogModel (connection: platform, table: transaction.reversal_audit_log) — override save() on existing records and delete() to throw \RuntimeException('ReversalAuditLog is append-only') in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Models/ReversalAuditLogModel.php
- [X] T021 [P] Create TransactionReceiptModel (connection: platform, table: transaction.transaction_receipts) — override save() on existing records and delete() to throw \App\Modules\Transaction\Domain\Exceptions\TransactionReceiptImmutableException in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Models/TransactionReceiptModel.php
- [X] T022 [P] Create TransactionReceiptImmutableException in wallet-ecosystem/services/api/app/Modules/Transaction/Domain/Exceptions/TransactionReceiptImmutableException.php
- [X] T023 Create TransactionProjectionRepository::listWithFilters(), findById(), findByIdOwnedBy() in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Repositories/TransactionProjectionRepository.php — listWithFilters() accepts type/status/from_date/to_date/search/page/per_page; search uses whereRaw("to_tsvector('english', reference || ' ' || coalesce(description, '')) @@ plainto_tsquery(?)")
- [X] T024 [P] Add transaction queue (`transaction`) to horizon configuration in wallet-ecosystem/services/api/config/horizon.php
- [X] T025 [P] Register transaction module permissions in permission seeder or via TransactionServiceProvider — permissions: transaction.read, transaction.create, transaction.admin, transaction.reversal.request, transaction.reversal.approve, transaction.reversal.execute, transaction.reversal.audit, transaction.refund.process

**Checkpoint**: Foundation ready — all enums, models, repositories, and permissions registered. User story phases can begin.

---

## Phase 3: US-TXN-001/002/003 — Transaction Projection Population (P0) 🎯 MVP

**Goal**: E11 receives domain events from E08–E10 and populates the unified `transaction_projections` read model. This is how E11 "creates" transaction records — by consuming upstream events.

**Independent Test**: Simulate a `Funding.Transaction.Completed` event; verify a `TransactionProjection` row is inserted with correct fields, a `TransactionStatusHistory` row is appended, and a unique `TXN-*` reference is assigned.

- [X] T026 [TXN-001] Create TransactionReferenceGenerator::generate(DateTime $date): string — formats TXN-{YYYYMMDD}-{8-char-uppercase-hex}, retry on DB collision (max 3 attempts), throws \RuntimeException('REFERENCE_GENERATION_FAILED') on 3 consecutive collisions — in wallet-ecosystem/services/api/app/Modules/Transaction/Domain/Services/TransactionReferenceGenerator.php
- [X] T027 [TXN-001] Create TransactionProjectionService::upsertFromEvent(array $eventPayload, string $sourceModule): TransactionProjection — maps event payload fields to TransactionProjection, calls TransactionReferenceGenerator if no reference in payload, appends TransactionStatusHistory row in same DB transaction — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/Services/TransactionProjectionService.php
- [X] T028 [P] [TXN-001] Create FundingTransactionEventHandler::handle() — handles ALL E08 funding transaction events (Funding.Transaction.Created, Funding.Transaction.StatusChanged, Funding.Transaction.Completed) to ensure pending/processing/completed funding transactions all appear in the projection; maps event payload fields to projection upsert; calls TransactionProjectionService::upsertFromEvent() with source_module='e08_funding' — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/EventHandlers/FundingTransactionEventHandler.php
- [X] T029 [P] [TXN-001] Create TransferTransactionEventHandler::handle() — handles Transfer.Created, Transfer.StatusChanged, Transfer.Completed from E09; calls TransactionProjectionService::upsertFromEvent() with source_module='e09_transfer' — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/EventHandlers/TransferTransactionEventHandler.php
- [X] T030 [P] [TXN-001] Create WithdrawalTransactionEventHandler::handle() — handles Withdrawal.Transaction.Created, Withdrawal.Transaction.StatusChanged, Withdrawal.Transaction.Completed from E10; calls TransactionProjectionService::upsertFromEvent() with source_module='e10_withdrawal' — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/EventHandlers/WithdrawalTransactionEventHandler.php
- [X] T031 [TXN-001] Register ALL event-to-handler bindings in TransactionServiceProvider::boot() — bind Funding.Transaction.Created, Funding.Transaction.StatusChanged, Funding.Transaction.Completed → FundingTransactionEventHandler; Transfer.Created, Transfer.StatusChanged, Transfer.Completed → TransferTransactionEventHandler; Withdrawal.Transaction.Created, Withdrawal.Transaction.StatusChanged, Withdrawal.Transaction.Completed → WithdrawalTransactionEventHandler — verify event class names against T002 research findings — in wallet-ecosystem/services/api/app/Modules/Transaction/Providers/TransactionServiceProvider.php

**Checkpoint**: Simulated event produces projection row and status history row. TransactionReferenceGenerator retries on collision.

---

## Phase 4: US-TXN-004/005 — Status Tracking & State History (P0)

**Goal**: Status transitions enforce the state machine (FR-E11-003) and append immutable history rows. The `GET /v1/transactions/{id}/status-history` endpoint exposes the append-only log.

**Independent Test**: Transition a transaction through `pending → processing → completed`; verify 3 history rows in ascending order; attempt `completed → pending`; verify 422 response.

- [X] T032 [TXN-004] Create TransactionStatusTransitionService::transition(TransactionProjectionModel $tx, TransactionStatus $newStatus, ?string $actorId, ?string $actorType, ?string $reason): void — validates allowed transition per TransactionStatus::allowedTransitionsFrom(), throws \App\Modules\Transaction\Domain\Exceptions\InvalidStatusTransitionException on prohibited transition, appends TransactionStatusHistoryModel row in same DB transaction — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/Services/TransactionStatusTransitionService.php
- [X] T033 [P] [TXN-004] Create InvalidStatusTransitionException in wallet-ecosystem/services/api/app/Modules/Transaction/Domain/Exceptions/InvalidStatusTransitionException.php
- [X] T034 [TXN-005] Implement GET /v1/transactions/{id}/status-history in TransactionController — own-or-admin ownership check (404 on cross-customer per FR-E11-011), returns history rows in ascending occurred_at order — in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Controllers/TransactionController.php
- [X] T035 [P] [TXN-005] Create TransactionStatusHistoryResource (fields: id, transaction_id, previous_status, new_status, actor_type, reason, occurred_at) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Resources/TransactionStatusHistoryResource.php
- [X] T036 [TXN-005] Register GET /v1/transactions/{id}/status-history route in TransactionServiceProvider

**Checkpoint**: Status machine blocks invalid transitions; history accumulates correctly; ownership check enforced on history endpoint.

---

## Phase 5: US-TXN-006-011 — Query & Detail (P0)

**Goal**: `GET /v1/transactions` provides unified paginated list with type/status/date/search filters. `GET /v1/transactions/{id}` returns full detail. Both are IDOR-safe (404 on cross-customer).

**Independent Test**: Create 30 transactions for customer A and 5 for customer B. Authenticated as customer A: list returns ≤20 results all belonging to A. Filter `?type=withdrawal` returns only withdrawals. `GET /v1/transactions/{id_of_B}` returns 404.

- [X] T037 [TXN-006] Implement GET /v1/transactions in TransactionController — delegates to TransactionProjectionRepository::listWithFilters(), applies ownership scope (customer_id = auth actor's customer_id unless transaction.admin permission), silently clamps per_page to transaction.max_page_size — in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Controllers/TransactionController.php
- [X] T038 [P] [TXN-006] Create ListTransactionsRequest — validates: type (in transaction.allowed_types or null), status (in TransactionStatus enum or null), from_date/to_date (ISO 8601 or null, from ≤ to), search (string ≤ 200 chars or null), page (int ≥ 1), per_page (int 1–100) — in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Requests/ListTransactionsRequest.php
- [X] T039 [P] [TXN-006] Create TransactionResource — fields: id, reference, type, status, amount, currency_code, direction, description, metadata, created_at, completed_at, failed_at, _links (status_history always; receipt only if status=completed) — EXCLUDES: wallet_id internals, ledger_journal_id — in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Resources/TransactionResource.php
- [X] T040 [P] [TXN-006] Create TransactionCollectionResource wrapping TransactionResource[] with meta (total, page, pages) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Resources/TransactionCollectionResource.php
- [X] T041 [TXN-010] Add full-text search to TransactionProjectionRepository::listWithFilters() — when $filters['search'] is provided, apply: whereRaw("to_tsvector('english', coalesce(reference,'') || ' ' || coalesce(description,'')) @@ plainto_tsquery(?)", [$search]) — in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Repositories/TransactionProjectionRepository.php
- [X] T042 [TXN-011] Implement GET /v1/transactions/{id} in TransactionController — calls TransactionProjectionRepository::findByIdOwnedBy($id, $customerId); returns 404 TRANSACTION_NOT_FOUND for non-existent or cross-customer IDs (never 403) — in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Controllers/TransactionController.php
- [X] T043 [TXN-011] Register GET /v1/transactions and GET /v1/transactions/{id} routes in TransactionServiceProvider

**Checkpoint**: List returns paginated ownership-scoped results; all 5 filter params work independently and combined; cross-customer ID returns 404.

---

## Phase 6: US-TXN-014/015/016/017/019 — Reversal Lifecycle (P0)

**Goal**: Full reversal state machine — request (with SELECT FOR UPDATE), approve (with maker-checker), reject, execute (with compensating ledger + SELECT FOR UPDATE), and audit trail. All state changes append to ReversalAuditLog.

**Independent Test**: Full lifecycle — request reversal on a completed transaction, approve it as a different actor (maker-checker), execute it; verify: 1 Reversal row, transaction.status = reversed, compensating journal created, 3+ ReversalAuditLog rows. Concurrency test CC-E11-001: two parallel requests → 1 Reversal row.

- [X] T044 [TXN-014] Create ReversalRepository::findActiveByTransactionId(), findByIdWithLock(), appendAuditLog() in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Repositories/ReversalRepository.php — findByIdWithLock() uses DB::connection('platform')->table('transaction.reversals')->lockForUpdate()
- [X] T045 [TXN-014] Create ReversalService::request(string $transactionId, string $requestedBy, string $idempotencyKey): ReversalModel — 1) validate transaction exists and is completed (else 422 TRANSACTION_NOT_REVERSIBLE), 2) validate within reversal.max_age_days window (else 422 REVERSAL_WINDOW_EXPIRED), 3) DB::connection('platform')->transaction(): SELECT FOR UPDATE on transaction row, check for existing active reversal (return existing if found — idempotent), create Reversal in pending_approval, append ReversalAuditLog — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/Services/ReversalService.php
- [X] T046 [P] [TXN-015] Create ReversalService::approve(string $reversalId, string $approvedBy, string $idempotencyKey): ReversalModel — 1) check idempotency store key 'reversal_approve:{reversalId}'; if present return cached result (idempotent per FR-E11-023), 2) validate reversal is pending_approval (else 422 REVERSAL_NOT_APPROVABLE), 3) if transaction.reversal.require_maker_checker = true enforce approvedBy ≠ requestedBy (else 403 MAKER_CHECKER_VIOLATION), 4) transition to approved, 5) store idempotency result, 6) append audit log — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/Services/ReversalService.php
- [X] T047 [TXN-016] Create ReversalService::reject(string $reversalId, string $rejectedBy, ?string $reason): ReversalModel — validate reversal is pending_approval (else 422 REVERSAL_NOT_REJECTABLE), transition to rejected, append audit log — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/Services/ReversalService.php
- [X] T048 [TXN-017] Create ReversalService::execute(string $reversalId, string $correlationId): ReversalModel — DB::connection('platform')->transaction(): 1) SELECT FOR UPDATE on Reversal row via ReversalRepository::findByIdWithLock(), 2) validate status = approved (else 422 REVERSAL_NOT_EXECUTABLE), 3) if already executed return idempotent result, 4) retrieve original journal lines from E07 via LedgerModuleContract::getJournalLines($tx->ledger_journal_id) — method signature confirmed in T001 research; if method unavailable use alternative confirmed in research.md, 5) construct compensating lines by inverting each original line (DEBIT↔CREDIT, same amounts and accounts), 6) post compensating journal via LedgerModuleContract::postEntry($compensatingLines, idempotency_key='reversal_execute:{reversalId}', correlationId), 7) on journal success: update transaction status → reversed + reversal status → executed + store compensating_journal_id in same DB transaction, 8) on journal failure: throw exception (DB transaction rolls back — reversal stays approved, no partial state), 9) append audit log — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/Services/ReversalService.php
- [X] T049 [TXN-014] Implement POST /v1/transactions/{id}/reversals in app/Modules/Transaction/Infrastructure/Http/Controllers/ReversalController.php — thin controller: validate RequestReversalRequest, call ReversalService::request(), return 201 ReversalResource
- [X] T050 [P] [TXN-014] Create RequestReversalRequest (required: Idempotency-Key header, permission: transaction.reversal.request) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Requests/RequestReversalRequest.php
- [X] T051 [TXN-015] Implement POST /v1/transactions/{id}/reversals/{rid}/approve in ReversalController — call ReversalService::approve(), return 200 ReversalResource
- [X] T052 [P] [TXN-015] Create ApproveReversalRequest (required: Idempotency-Key header validated and passed to ReversalService::approve() as $idempotencyKey; permission: transaction.reversal.approve) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Requests/ApproveReversalRequest.php
- [X] T053 [TXN-016] Implement POST /v1/transactions/{id}/reversals/{rid}/reject in ReversalController — call ReversalService::reject(), return 200 ReversalResource
- [X] T054 [P] [TXN-016] Create RejectReversalRequest (permission: transaction.reversal.approve, optional body field: reason string) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Requests/RejectReversalRequest.php
- [X] T055 [TXN-017] Implement POST /v1/transactions/{id}/reversals/{rid}/execute in ReversalController — call ReversalService::execute(), return 200 ReversalResource
- [X] T056 [P] [TXN-017] Create ExecuteReversalRequest (permission: transaction.reversal.execute) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Requests/ExecuteReversalRequest.php
- [X] T057 [TXN-019] Implement GET /v1/transactions/{id}/reversals/{rid}/audit in ReversalController — return ReversalAuditLogResource[] for all audit entries; permission: transaction.reversal.audit; 404 on non-existent reversal
- [X] T058 [P] [TXN-014] Create ReversalResource (fields: id, transaction_id, status, requested_by, requested_at, approved_by, approved_at, rejected_by, rejected_reason, executed_at, idempotency_key, created_at, updated_at) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Resources/ReversalResource.php
- [X] T059 [P] [TXN-019] Create ReversalAuditLogResource (fields: id, reversal_id, action, actor_id, actor_type, occurred_at, metadata) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Resources/ReversalAuditLogResource.php
- [X] T060 [TXN-014] Register all Reversal routes in TransactionServiceProvider — POST /v1/transactions/{id}/reversals, POST approve/reject/execute, GET audit

**Checkpoint**: Full reversal lifecycle works end-to-end. SELECT FOR UPDATE prevents duplicate Reversal rows. Compensating journal rollback tested. Maker-checker enforced. Audit log has all lifecycle entries.

---

## Phase 7: US-TXN-012/013 — Transaction Receipts (P1)

**Goal**: `TransactionReceipt` is created automatically when a transaction reaches `completed` status. Receipts are immutable. `GET /v1/transactions/{id}/receipt` returns the receipt JSON. `POST .../receipt/share` queues async delivery.

**Independent Test**: Complete a transaction via projection handler; verify a `TransactionReceipt` row is created with correct fields. Retrieve via API: 200. Attempt to update the receipt model: `TransactionReceiptImmutableException` thrown. Share via `email`: 202 returned, audit event recorded.

- [X] T061 [TXN-012] Create ReceiptService::createFromProjection(TransactionProjectionModel $tx): TransactionReceiptModel — idempotent (return existing if already created via firstOrCreate on transaction_id), creates TransactionReceipt record — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/Services/ReceiptService.php
- [X] T062 [TXN-012] Create TransactionCompletedReceiptHandler::handle() — triggered when a projection transitions to completed status, calls ReceiptService::createFromProjection() — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/EventHandlers/TransactionCompletedReceiptHandler.php
- [X] T063 [TXN-012] Implement GET /v1/transactions/{id}/receipt in app/Modules/Transaction/Infrastructure/Http/Controllers/ReceiptController.php — ownership check via TransactionProjectionRepository::findByIdOwnedBy(), return 404 RECEIPT_NOT_AVAILABLE if transaction not completed, return TransactionReceiptResource
- [X] T063b [TXN-013] Create NotificationServiceStub implementing a minimal NotificationServiceContract (interface with `sendReceiptShare(string $channel, string $recipient, TransactionReceiptModel $receipt): void`) — if E17 Notifications module is available bind the real service; otherwise bind the stub in TransactionServiceProvider — in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Services/NotificationServiceStub.php and app/Modules/Transaction/Domain/Contracts/NotificationServiceContract.php
- [X] T064 [TXN-013] Implement POST /v1/transactions/{id}/receipt/share in ReceiptController — validate ShareReceiptRequest, inject NotificationServiceContract and call sendReceiptShare(), emit transaction.receipt.shared audit event, return 202 Accepted
- [X] T065 [P] [TXN-013] Create ShareReceiptRequest (required: channel (email|sms), recipient string; ownership check on transaction) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Requests/ShareReceiptRequest.php
- [X] T066 [P] [TXN-012] Create TransactionReceiptResource (fields: id, reference, amount, currency_code, type, completed_at — EXCLUDES wallet_id, ledger_journal_id) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Resources/TransactionReceiptResource.php
- [X] T067 [TXN-012] Register TransactionCompletedReceiptHandler in TransactionServiceProvider event bindings and register GET /v1/transactions/{id}/receipt + POST .../receipt/share routes in wallet-ecosystem/services/api/app/Modules/Transaction/Providers/TransactionServiceProvider.php

**Checkpoint**: Receipt created on completion event. Immutability throws on save/delete. GET returns correct fields (no internal IDs). Share queues async job and records audit.

---

## Phase 8: US-TXN-018 — Refund (P1)

**Goal**: Privileged actor processes a refund — creates a new `Transaction` of type `refund` linked to the original via `parent_transaction_id`, posts compensating ledger entry. Parent transaction status is unchanged.

**Independent Test**: Process a refund on a completed transaction; verify a new `TransactionProjection` row of type `refund` with `parent_transaction_id` set; verify parent transaction status is still `completed`; verify compensating journal created.

- [X] T068 [TXN-018] Create RefundService::process(string $parentTransactionId, string $requestedBy, string $idempotencyKey): TransactionProjectionModel — validate parent exists and is completed; validate permission; post compensating ledger entry via LedgerModuleContract::postEntry() with idempotency_key='refund_process:{idempotencyKey}'; create new Transaction projection of type 'refund' with parent_transaction_id; parent transaction status MUST NOT change; append audit event — in wallet-ecosystem/services/api/app/Modules/Transaction/Application/Services/RefundService.php
- [X] T069 [TXN-018] Implement POST /v1/transactions/{id}/refunds in app/Modules/Transaction/Infrastructure/Http/Controllers/RefundController.php — thin controller: validate ProcessRefundRequest, call RefundService::process(), return 201 TransactionResource
- [X] T070 [P] [TXN-018] Create ProcessRefundRequest (required: Idempotency-Key header, permission: transaction.refund.process) in wallet-ecosystem/services/api/app/Modules/Transaction/Infrastructure/Http/Requests/ProcessRefundRequest.php
- [X] T071 [TXN-018] Register POST /v1/transactions/{id}/refunds route in TransactionServiceProvider

**Checkpoint**: Refund creates a new transaction row of type 'refund'; parent transaction unaffected; compensating journal balanced.

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: PHPStan level 8, unit tests for financial integrity paths, concurrency tests CC-E11-001/002, OpenAPI docs, DoD validation.

- [X] T072 Create phpstan-transaction.neon targeting app/Modules/Transaction/ at level 8 (inherit Eloquent suppressions from phpstan-withdrawal.neon pattern) in wallet-ecosystem/services/api/phpstan-transaction.neon
- [X] T073 Run PHPStan level 8 against app/Modules/Transaction/ via `php vendor/bin/phpstan analyse --configuration=phpstan-transaction.neon` and fix all real type errors (header casts, enum comparisons, Eloquent query builder types)
- [X] T074 [P] Write unit tests for TransactionStatusTransitionService — all 6 valid transitions pass; all prohibited transitions throw InvalidStatusTransitionException; history row appended on each valid transition — in wallet-ecosystem/services/api/tests/Unit/Modules/Transaction/TransactionStatusTransitionServiceTest.php
- [X] T075 [P] Write unit tests for ReversalService::request() — valid request creates Reversal in pending_approval; expired window returns 422; non-completed transaction returns 422; duplicate idempotencyKey returns existing reversal — in wallet-ecosystem/services/api/tests/Unit/Modules/Transaction/ReversalServiceRequestTest.php
- [X] T076 [P] Write unit tests for ReversalService::execute() — valid execution posts journal with idempotency_key='reversal_execute:{rid}'; journal failure leaves reversal in approved (rollback); already-executed reversal is idempotent — in wallet-ecosystem/services/api/tests/Unit/Modules/Transaction/ReversalServiceExecuteTest.php
- [X] T077 [P] Write concurrency test CC-E11-001: two parallel requests for same transaction reversal — verify exactly one Reversal row created; second caller receives the existing row — in wallet-ecosystem/services/api/tests/Unit/Modules/Transaction/ReversalConcurrencyTest.php
- [X] T078 [P] Write concurrency test CC-E11-002: two parallel execute calls on same Reversal — verify exactly one compensating journal entry created (idempotency_key uniqueness enforced) — in wallet-ecosystem/services/api/tests/Unit/Modules/Transaction/ReversalExecuteConcurrencyTest.php
- [X] T079 [P] Write unit tests for TransactionReceiptModel immutability — save() on existing record throws TransactionReceiptImmutableException; delete() throws same — in wallet-ecosystem/services/api/tests/Unit/Modules/Transaction/TransactionReceiptModelImmutabilityTest.php
- [X] T080 [P] Write unit tests for TransactionStatusHistoryModel immutability — save() on existing record throws RuntimeException; delete() throws RuntimeException — in wallet-ecosystem/services/api/tests/Unit/Modules/Transaction/TransactionStatusHistoryImmutabilityTest.php
- [X] T081 Complete OpenAPI documentation in specs/011-transaction-management/contracts/api.yaml — all 12 endpoints with request/response schemas, error codes (422 INVALID_STATUS_TRANSITION, 422 TRANSACTION_NOT_REVERSIBLE, 422 REVERSAL_WINDOW_EXPIRED, 403 MAKER_CHECKER_VIOLATION, 404 TRANSACTION_NOT_FOUND, etc.), bearerAuth, Idempotency-Key header, pagination meta schema
- [X] T082 Create specs/011-transaction-management/quickstart.md — validation scenarios: event projection population (simulate E08 event), reversal lifecycle end-to-end (request → approve → execute), CC-E11-001/002 concurrency scenarios, IDOR check (cross-customer 404)
- [X] T083 Validate DoD checklist: all 14 Epic DoD items met; PHPStan passes; unit tests run via `php vendor/bin/phpunit tests/Unit/Modules/Transaction/ --no-coverage`; mark all tasks [X] in specs/011-transaction-management/tasks.md

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately; T001–T006 (research + design) must complete before Phase 2
- **Phase 2 (Foundational)**: Depends on Phase 1 completion — BLOCKS all story phases
- **Phase 3 (Projection)**: Depends on Phase 2
- **Phase 4 (Status)**: Depends on Phase 3 (TransactionProjectionModel must exist)
- **Phase 5 (Query)**: Depends on Phase 4 (status machine must be in place for accurate reads)
- **Phase 6 (Reversal)**: Depends on Phase 5 (transaction detail/ownership check reused); depends on T001 (LedgerModuleContract confirmed)
- **Phase 7 (Receipts)**: Depends on Phase 3 (projection must exist before receipt creation)
- **Phase 8 (Refund)**: Depends on Phase 6 (same ledger pattern) and Phase 7 (receipt for refund)
- **Phase 9 (Polish)**: Depends on all prior phases

### User Story Dependencies

| Story | Depends On | Notes |
|-------|-----------|-------|
| US-TXN-001/002/003 | Phase 2 | First story — no prior story deps |
| US-TXN-004/005 | US-TXN-001 | Status machine applies to projections |
| US-TXN-006-011 | US-TXN-004/005 | Query requires status to be tracked |
| US-TXN-014-019 | US-TXN-006-011, T001 (Ledger) | Reversal queries transaction ownership |
| US-TXN-012-013 | US-TXN-001 | Receipts require projection to exist |
| US-TXN-018 | US-TXN-017 (Ledger path confirmed) | Same compensating ledger pattern |

### Critical Financial Integrity Tasks (must not be skipped)
- **T045**: SELECT FOR UPDATE in ReversalService::request() — prevents CC-E11-001 race condition
- **T048**: SELECT FOR UPDATE + rollback in ReversalService::execute() — prevents CC-E11-002 race condition
- **T077/T078**: Concurrency tests — required by Constitution Principle 4
- **T079/T080**: Immutability tests — required by Constitution Principle 4

---

## Parallel Execution Examples

### Phase 3 (Projection Population)
```
In parallel (T028, T029, T030): FundingHandler, TransferHandler, WithdrawalHandler
Sequential: T026 → T027 → [T028, T029, T030 in parallel] → T031
```

### Phase 5 (Query & Detail)
```
In parallel: T038 (ListTransactionsRequest), T039 (TransactionResource), T040 (CollectionResource)
Sequential: T037 (controller list) → T041 (add full-text search) → T042 (controller detail) → T043 (routes)
```

### Phase 6 (Reversal Lifecycle)
```
Sequential (financial integrity): T044 (repository) → T045 (request) → T046/T047 in parallel → T048 (execute)
Controllers/requests in parallel per endpoint: T049/T050, T051/T052, T053/T054, T055/T056
Resources in parallel: T058, T059
```

---

## Implementation Strategy

### MVP (P0 Stories Only — Phases 1–6)

1. Complete Phase 1: Setup + research + design artifacts
2. Complete Phase 2: Foundational — models, enums, repository
3. Complete Phase 3: Projection population from E08/E09/E10 events
4. Complete Phase 4: Status tracking + history endpoint
5. Complete Phase 5: Query + detail endpoints
6. Complete Phase 6: Reversal lifecycle with financial integrity controls
7. **STOP and VALIDATE**: Run concurrency tests CC-E11-001/002; verify PHPStan; test IDOR

### Full Delivery (Add P1 Stories — Phases 7–8)

8. Complete Phase 7: Receipts (immutable, share)
9. Complete Phase 8: Refund
10. Complete Phase 9: Polish (PHPStan, unit tests, OpenAPI, DoD)

### Parallel Team Strategy

- Developer A: Phases 1–2 → Phase 3 → Phase 6
- Developer B: Phase 4 → Phase 5 → Phase 9 (tests)
- Developer C: Phase 7 → Phase 8

All can proceed once Phase 2 is complete.

---

## Summary

| Phase | Stories | Priority | Task Range | Count |
|-------|---------|----------|------------|-------|
| 1 Setup | — | — | T001–T013 | 13 |
| 2 Foundational | — | — | T014–T025 | 12 |
| 3 Projection Population | US-TXN-001/002/003 | P0 | T026–T031 | 6 |
| 4 Status Tracking | US-TXN-004/005 | P0 | T032–T036 | 5 |
| 5 Query & Detail | US-TXN-006-011 | P0 | T037–T043 | 7 |
| 6 Reversal Lifecycle | US-TXN-014-019 | P0 | T044–T060 | 17 |
| 7 Receipts | US-TXN-012/013 | P1 | T061–T067 + T063b | 8 |
| 8 Refund | US-TXN-018 | P1 | T068–T071 | 4 |
| 9 Polish | — | — | T072–T083 | 12 |
| **Total** | **19 stories** | | **T001–T083 + T063b** | **84** |
