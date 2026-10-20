# Tasks: E08 Wallet Funding

**Input**: `specs/008-wallet-funding/` — spec.md, plan.md, data-model.md, contracts/, research.md, quickstart.md

**Prerequisites**: All E01–E07 migrations run; `ledger.float_account.BWP` config key seeded (TBD-E07-001 advance gate).

**Base path**: `wallet-ecosystem/services/api/`

**Tests**: Included — PHPStan level 8 + Pest feature / unit / concurrency / architecture tests (per spec §19 DoD).

**Organization**: 8 phases. Phases 3–6 map to the four story groups from spec.md §6 in priority order (P0 before P1).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no shared state dependency)
- **[US1]**: Funding Initiation — US-FUND-001–006 (P0)
- **[US2]**: Provider Processing — US-FUND-007–010 (P0)
- **[US3]**: Agent Cash-In — US-FUND-015–019 (P0)
- **[US4]**: Bank Matching — US-FUND-011–014 (P1)

---

## Phase 1: Setup (Module Scaffold)

**Purpose**: Create the Funding module skeleton, wire dependency stubs, register routes.

- [X] T001 Create `app/Modules/Funding/` directory tree per plan.md module structure (Application/, Domain/, Infrastructure/, Providers/)
- [X] T002 Create `app/Modules/Funding/Providers/FundingServiceProvider.php` with empty `register()` and `boot()` stubs
- [X] T003 Register `FundingServiceProvider::class` in `bootstrap/providers.php` (or `config/app.php` providers array)
- [X] T004 [P] Add `getPocketLedgerAccountRef(string $walletId, string $currencyCode): string` stub to `app/Modules/Wallet/Domain/Contracts/WalletModuleContract.php` (dependency gap — research.md §6)
- [X] T005 [P] Add `verifyTransactionPin(string $actorId, string $pin): bool` stub to `app/Modules/Auth/Domain/Contracts/AuthModuleContract.php` (dependency gap — research.md §5)
- [X] T006 Create `routes/modules/funding.php` with route group stub (`/v1/funding`) and include it from the main route service provider

**Checkpoint**: `php artisan route:list` shows `/v1/funding` prefix registered with no errors.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Migrations, models, enums, exceptions, domain events, and module contracts. No user-story work can begin until this phase is complete.

**⚠️ CRITICAL**: All tasks below must complete before any Phase 3+ task.

### Migrations

- [X] T007 Create migration `database/migrations/2026_09_19_001100_create_funding_transactions_table.php` — all columns, indexes, and UNIQUE constraints per data-model.md §1 (connection: `ledger`)
- [X] T008 Create migration `database/migrations/2026_09_19_001200_create_funding_provider_callbacks_table.php` — per data-model.md §2
- [X] T009 Create migration `database/migrations/2026_09_19_001300_create_funding_receipts_table.php` — per data-model.md §3
- [X] T010 Create migration `database/migrations/2026_09_19_001400_create_funding_bank_reconciliations_table.php` — per data-model.md §4
- [X] T011 Create migration `database/migrations/2026_09_19_001500_create_funding_unmatched_deposits_table.php` — per data-model.md §5
- [X] T012 Create migration `database/migrations/2026_09_19_001600_seed_funding_permissions.php` — insert `funding.initiate`, `funding.read`, `funding.agent-cashin`, `funding.unmatched.review` permissions into `platform.permissions`
- [X] T013 Create migration `database/migrations/2026_09_19_001700_seed_funding_configuration_stubs.php` — seed `funding.processing.timeout_minutes=60`, `funding.bank_reference.expiry_days=3`, `funding.idempotency.ttl_seconds=86400`, `funding.mock.success_rate=1.0`, `ledger.float_account.BWP=NULL` into `platform.configuration_values`

### Enums

- [X] T014 [P] Create `app/Modules/Funding/Domain/Enums/FundingMethod.php` — cases: `MobileMoney`, `BankTransfer`, `Card`, `Agent` with `value` strings per FR-E08-002
- [X] T015 [P] Create `app/Modules/Funding/Domain/Enums/FundingStatus.php` — cases: `Pending`, `Processing`, `AwaitingMatch`, `Completed`, `Failed`, `Cancelled` per FR-E08-003
- [X] T016 [P] Create `app/Modules/Funding/Domain/Enums/FundingFailureReason.php` — cases: `ProviderDeclined`, `AmountMismatch`, `ProcessingTimeout`, `BankReferenceExpired`, `WalletSuspended`, `FloatInsufficient`

### Domain Exceptions

- [X] T017 [P] Create all 8 domain exception classes in `app/Modules/Funding/Domain/Exceptions/`: `FundingTransactionNotFoundException`, `FundingLimitExceededException`, `InsufficientFloatException`, `TransactionNotModifiableException`, `DuplicateCallbackException`, `FloatAccountNotConfiguredException`, `FeeChangedException`, `BankReferenceGenerationException` — each extends `\RuntimeException` with appropriate message and HTTP status hint

### Domain Events

- [X] T018 [P] Create `app/Modules/Funding/Domain/Events/FundingTransactionInitiated.php` — readonly DTO with: `fundingTransactionId`, `walletId`, `currencyCode`, `amount`, `status`, `occurredAt`, `correlationId` (no PII per FR-E08-045)
- [X] T019 [P] Create `app/Modules/Funding/Domain/Events/FundingTransactionConfirmed.php` — same DTO shape
- [X] T020 [P] Create `app/Modules/Funding/Domain/Events/FundingTransactionCompleted.php` — include `ledgerJournalId`, `netAmount`
- [X] T021 [P] Create `app/Modules/Funding/Domain/Events/FundingTransactionFailed.php` — include `failureReason`
- [X] T022 [P] Create `app/Modules/Funding/Domain/Events/FundingTransactionCancelled.php`
- [X] T023 [P] Create `app/Modules/Funding/Domain/Events/FundingTransactionExpired.php` — include `failureReason` (`processing_timeout` | `bank_reference_expired`) per FR-E08-047 (resolves analyze finding H2)
- [X] T024 [P] Create `app/Modules/Funding/Domain/Events/FundingTransactionAmountMismatch.php`
- [X] T025 [P] Create `app/Modules/Funding/Domain/Events/FundingAgentCashInCompleted.php` — include `agentId`, `netAmount`
- [X] T026 [P] Create `app/Modules/Funding/Domain/Events/FundingBankDepositReconciled.php` — include `bankNotificationId`, `bankReferenceCode`
- [X] T027 [P] Create `app/Modules/Funding/Domain/Events/FundingUnmatchedDepositReceived.php` — include `bankNotificationId`, `rawReference`

### Eloquent Models

- [X] T028 [P] Create `app/Modules/Funding/Infrastructure/Models/FundingTransactionModel.php` — `$connection='platform'`, `$table='funding.funding_transactions'`, `$fillable`, `$casts` (enums, timestamps), `scopeStale()` and `scopeExpiredBankRef()` query scopes (needed by expiry command)
- [X] T029 [P] Create `app/Modules/Funding/Infrastructure/Models/FundingProviderCallbackModel.php` — `$connection='platform'`, `$table='funding.funding_provider_callbacks'`, cast `raw_payload` as array
- [X] T030 [P] Create `app/Modules/Funding/Infrastructure/Models/FundingReceiptModel.php` — immutable: override `save()` after insert and `delete()` to throw `LogicException` (same pattern as E07 `JournalLineModel`); `$connection='platform'`, `$table='funding.funding_receipts'`
- [X] T031 [P] Create `app/Modules/Funding/Infrastructure/Models/BankReconciliationModel.php` — `$connection='platform'`, `$table='funding.bank_reconciliations'`
- [X] T032 [P] Create `app/Modules/Funding/Infrastructure/Models/UnmatchedDepositModel.php` — `$connection='platform'`, `$table='funding.unmatched_deposits'`, cast `raw_payload` as array

### Module Contracts and Adapters

- [X] T033 Create `app/Modules/Funding/Domain/Contracts/FundingModuleContract.php` — interface with `getFundingTransaction(string $id): array` and `isFundingCompleted(string $ref): bool` per contracts/funding-module-contract.php
- [X] T034 Create `app/Modules/Funding/Domain/Contracts/FundingProviderAdapter.php` — interface with `initiate(array $transaction): array` and `verifyCallback(array $payload, string $signature, string $provider): array` per contracts/funding-provider-adapter.php
- [X] T035 Create `app/Modules/Funding/Application/FundingService.php` — implements `FundingModuleContract`; delegates to internal services; bound in `FundingServiceProvider::register()`
- [X] T036 Create `app/Modules/Funding/Infrastructure/Adapters/MockFundingProviderAdapter.php` — implements `FundingProviderAdapter`; `initiate()` returns successful `ProviderResult`; `verifyCallback()` validates `X-Mock-Signature` header via HMAC-SHA256 with configurable mock secret; configurable success/failure via `funding.mock.success_rate`

### Test Infrastructure

- [X] T037 Create `tests/Feature/Modules/Funding/FundingTestCase.php` — base test case; seeds customer + wallet + agent + float account; provides `assertAuditEventRecorded(string $action)` helper; mocks `MetricsCollectorInterface`
- [X] T038 Create `tests/Architecture/Modules/FundingBoundaryTest.php` — Pest architecture test asserting `App\Modules\Funding` does not depend on `App\Modules\Wallet` internals, only contracts; no direct Eloquent model use outside Infrastructure/

**Checkpoint**: `php artisan migrate` runs without errors; all model `$connection='platform'` smoke-tests pass; `php artisan test tests/Architecture/Modules/FundingBoundaryTest.php` green.

---

## Phase 3: Funding Initiation — US-FUND-001–006 (P0) 🎯 MVP

**Goal**: Customer can initiate a pending funding transaction with fee quote and funding limits enforced, confirm it (transitions to `processing` or `awaiting_match`), and cancel a pending or awaiting_match transaction.

**Independent Test**: `php artisan test tests/Feature/Modules/Funding/FundingInitiationTest.php` — quickstart.md Scenario 1 (steps 1–2), Scenario 9, Scenario 10, Scenario 11.

### Implementation

- [X] T039 [P] [US1] Implement `FundingFeeService` in `app/Modules/Funding/Application/Services/FundingFeeService.php` — `calculate(int $amount, FundingMethod $method, string $currencyCode): FeeResult`; reads `funding.fee.{method}.{currency_code}` from `PlatformConfigurationService`; formula: `round(amount × rate_bps / 10000)` (FR-E08-012–014)
- [X] T040 [P] [US1] Implement `FundingLimitService` in `app/Modules/Funding/Application/Services/FundingLimitService.php` — validates per-transaction min/max and daily limit from `platform.configuration_values` keys `funding.limit.{method}.{tier}.{min|max}_amount`; daily sum query scoped to `status=completed AND date=today UTC`; apply FR-E08-011 agent-method exemption (resolves analyze finding M1)
- [X] T041 [US1] Implement `FundingInitiationService` in `app/Modules/Funding/Application/Services/FundingInitiationService.php` — resolves wallet ownership (IDOR → 404), KYC tier, calls `FundingFeeService` + `FundingLimitService`, writes `FundingTransaction` in `pending` status, calls `IdempotencyService` (`funding:{key}` namespace), emits `FundingTransactionInitiated` via `OutboxEventPublisher`, writes `platform.audit_events`; all inside `DB::connection('platform')->transaction()` (FR-E08-005–008; CC-E08-003 daily-limit race guard)
- [X] T042 [P] [US1] Create `app/Modules/Funding/Infrastructure/Http/Requests/InitiateFundingRequest.php` — validates `wallet_id`, `currency_code`, `amount`, `funding_method`, `correlation_id`; enforces `Idempotency-Key` header (400 without it)
- [X] T043 [P] [US1] Create `app/Modules/Funding/Infrastructure/Http/Resources/FundingTransactionResource.php` — includes all public fields: id, status, amount, fee_amount, net_amount, currency_code, funding_method, transaction_reference, bank_reference_code, bank_reference_expires_at, ledger_journal_id, completed_at, created_at
- [X] T044 [US1] Implement `FundingTransactionController::initiate()` in `app/Modules/Funding/Infrastructure/Http/Controllers/FundingTransactionController.php` — thin controller; delegates to `FundingInitiationService`; returns `FundingTransactionResource` with 201/200
- [X] T045 [P] [US1] Implement `FundingTransactionController::show()` and `FundingTransactionController::index()` — IDOR-safe (customer sees only own transactions); paginated list with `status` filter
- [X] T046 [US1] Implement `FundingConfirmationService` in `app/Modules/Funding/Application/Services/FundingConfirmationService.php` — recalculates fee at confirmation; throws `FeeChangedException` on mismatch (FR-E08-015); for non-bank: transitions to `processing`, dispatches `FundingInitiationJob`; for bank_transfer: transitions to `awaiting_match`, calls `BankReferenceGenerator::generate()`, stores `bank_reference_code` + `bank_reference_expires_at`; emits `FundingTransactionConfirmed` (FR-E08-016–017)
- [X] T047 [US1] Create `app/Modules/Funding/Application/Jobs/FundingInitiationJob.php` — queued job implementing `ShouldQueue`; calls `FundingProviderAdapter::initiate()`; on success stores `provider_reference_id`; on job failure (queue unavailable or permanent failure) transitions transaction to `failed` with `failure_reason=processing_timeout` and emits `FundingTransactionFailed` (resolves analyze finding C2 and M2)
- [X] T048 [US1] Create `app/Modules/Funding/Application/Services/BankReferenceGenerator.php` — generates `OLK-{YYYYMMDD}-{8CHAR}` with retry-on-collision (max 5 retries); throws `BankReferenceGenerationException` if exhausted (resolves analyze finding M3); uniqueness enforced via `bank_reference_code` UNIQUE constraint
- [X] T049 [US1] Create `app/Modules/Funding/Infrastructure/Http/Requests/ConfirmFundingRequest.php` — validates `quoted_fee_amount`, `correlation_id`; enforces `Idempotency-Key`
- [X] T050 [US1] Implement `FundingTransactionController::confirm()` — delegates to `FundingConfirmationService`; 422 on `FeeChangedException` with updated fee in response body; note: FundingTransaction stays in `pending` on FEE_CHANGED rejection (resolves analyze finding L1)
- [X] T051 [US1] Implement `FundingCancellationService` in `app/Modules/Funding/Application/Services/FundingCancellationService.php` — permits `pending → cancelled` and `awaiting_match → cancelled`; rejects all other statuses with `TransactionNotModifiableException` (422 TRANSACTION_NOT_CANCELLABLE); cancelled bank reference can never match (FR-E08-028); emits `FundingTransactionCancelled`; audits
- [X] T052 [US1] Create `app/Modules/Funding/Infrastructure/Http/Requests/CancelFundingRequest.php` and implement `FundingTransactionController::cancel()` — POST /v1/funding/transactions/{id}/cancel

### Tests

- [X] T053 [US1] Write `tests/Feature/Modules/Funding/FundingInitiationTest.php` — covers: happy path (201), idempotent replay (200), missing idempotency key (400), wallet not found (404), KYC insufficient (422), amount below minimum (422), amount above maximum (422), daily limit exceeded (422), cancel pending (200), cancel awaiting_match (200), cancel processing (422), fee mismatch at confirmation (422 FEE_CHANGED with updated fee), IDOR (customer cannot see another's transaction returns 404); audit events asserted for each mutation; maps to US-FUND-001–006 acceptance scenarios and SC-E08-009, SC-E08-010, SC-E08-011
- [X] T054 [P] [US1] Write `tests/Unit/Modules/Funding/FundingFeeServiceTest.php` — fee formula correctness, zero fee when config absent, rounding behaviour
- [X] T055 [P] [US1] Write `tests/Unit/Modules/Funding/FundingLimitServiceTest.php` — below min, above max, daily sum calculation, agent method exemption when config key absent

**Checkpoint**: Phase 3 tests green; `FundingTransaction` can be created, fee-quoted, confirmed (MockFundingProviderAdapter returns success immediately via sync path for test purposes), and cancelled. `GET /v1/funding/transactions` returns paginated list.

---

## Phase 4: Provider Processing — US-FUND-007–010 (P0)

**Goal**: Provider success/failure callback received → wallet credited exactly once via LedgerModuleContract; duplicate callbacks idempotent; amount mismatch transitions to failed; HMAC verified before any state change.

**Independent Test**: `php artisan test tests/Feature/Modules/Funding/ProviderCallbackTest.php` — quickstart.md Scenario 1 (step 3 onward), Scenario 3, Scenario 5, Scenario 12.

### Implementation

- [X] T056 [US2] Implement `FundingCreditService` in `app/Modules/Funding/Application/Services/FundingCreditService.php` — calls `WalletModuleContract::getPocketLedgerAccountRef($walletId, $currencyCode)` to resolve pocket reference; reads `PlatformConfigurationService::get("ledger.float_account.{currency}")` (throws `FloatAccountNotConfiguredException` if null — boot-time validation); calls `LedgerModuleContract::postEntry()` with `entry_type=credit`, two-line journal (debit platform float, credit customer pocket); sets `FundingTransaction.status=completed` and `ledger_journal_id` atomically; writes `FundingReceipt`; emits `FundingTransactionCompleted` inside same DB transaction (FR-E08-024; no direct wallet balance mutation)
- [X] T057 [US2] Implement `ProviderCallbackService` in `app/Modules/Funding/Application/Services/ProviderCallbackService.php` — persists raw `FundingProviderCallback` before processing (audit trail); calls `FundingProviderAdapter::verifyCallback()` for HMAC (SR-E08-001, SR-E08-009); checks `IdempotencyService` for `funding_callback:{provider_reference_id}` — returns 200 if duplicate; validates amount match (FR-E08-023); on success: calls `FundingCreditService`; on failure: transitions to `failed`, emits `FundingTransactionFailed`; inline processing (no queue) — 200 returned only after wallet credited (research.md §3)
- [X] T058 [US2] Create `app/Modules/Funding/Infrastructure/Http/Requests/ProviderCallbackRequest.php` — no Bearer auth; no Idempotency-Key header; accepts raw body
- [X] T059 [US2] Implement `ProviderCallbackController` in `app/Modules/Funding/Infrastructure/Http/Controllers/ProviderCallbackController.php` — POST /v1/funding/callbacks/{provider}; public route; passes to `ProviderCallbackService`; returns 400 on `INVALID_CALLBACK_SIGNATURE` before any state mutation
- [X] T060 [P] [US2] Create `app/Modules/Funding/Infrastructure/Http/Resources/FundingReceiptResource.php` — all receipt fields per FR-E08-042
- [X] T061 [P] [US2] Implement `FundingReceiptController::show()` in `app/Modules/Funding/Infrastructure/Http/Controllers/FundingReceiptController.php` — GET /v1/funding/receipts/{receipt_reference}; owner-scoped (customer or agent); 404 RECEIPT_NOT_FOUND

### Tests

- [X] T062 [US2] Write `tests/Feature/Modules/Funding/ProviderCallbackTest.php` — covers: success callback → wallet credited, `FundingReceipt` created, `ledger_journal_id` set (SC-E08-001 complete); failure callback → `status=failed`, no ledger entry, `FundingTransaction.Failed` event (SC-E08-005); duplicate success callback (same `provider_reference_id`) → 200, wallet credited exactly once, no second `postEntry()` call (SC-E08-003); amount mismatch callback → `status=failed`, `FundingTransaction.AmountMismatch` event (EC-E08-03); invalid HMAC → 400, no state change (SC-E08-012); wallet suspended at credit time → `status=failed`, `postEntry()` error surfaced (EC-E08-04); audit events for each mutation
- [X] T063 [P] [US2] Write `tests/Concurrency/Modules/Funding/DuplicateCallbackConcurrencyTest.php` — two simultaneous success callbacks for the same `provider_reference_id` → exactly one `postEntry()` call; `IdempotencyService` UNIQUE constraint is the guard (CC-E08-001)

**Checkpoint**: Full digital funding flow works end-to-end including mock provider callback → wallet balance increases by `net_amount`. SC-E08-001 through SC-E08-003, SC-E08-005, SC-E08-012 pass.

---

## Phase 5: Agent Cash-In — US-FUND-015–019 (P0)

**Goal**: Agent performs a single-request cash-in: debits agent's float account, credits customer's pocket wallet, returns receipt. Fee is always zero.

**Independent Test**: `php artisan test tests/Feature/Modules/Funding/AgentCashInTest.php` + `tests/Concurrency/Modules/Funding/AgentFloatConcurrencyTest.php` — quickstart.md Scenario 2, Scenario 4.

### Implementation

- [X] T064 [US3] Implement `AgentCashInService` in `app/Modules/Funding/Application/Services/AgentCashInService.php` — enforces `fee_amount=0` as first domain assertion regardless of config (FR-E08-050); calls `AuthModuleContract::verifyTransactionPin()` for PIN (422 INVALID_PIN on failure; plaintext PIN never logged); resolves customer pocket via `WalletModuleContract::getPocketLedgerAccountRef()`; verifies customer KYC tier, wallet active, limits; agent float reference: `agent_float_{agentId}_{currencyCode}` (research.md §8); calls `LedgerModuleContract::registerAccount()` for agent float (idempotent); acquires `SELECT ... FOR UPDATE` on agent float ledger account row; verifies float ≥ amount (422 INSUFFICIENT_FLOAT); calls `LedgerModuleContract::postEntry()` with `entry_type=debit` two-line journal (debit agent float, credit customer pocket); all inside single `DB::connection('platform')->transaction()` (CC-E08-002); handles step-up auth if `amount ≥ auth.step_up_threshold BWP` (SR-E08-006; FR-E08-037 — restrict to BWP-denominated transactions in E08; resolves analyze finding M4); writes `FundingTransaction` status=completed + `FundingReceipt`; emits `FundingAgentCashInCompleted`; audits
- [X] T065 [US3] Create `app/Modules/Funding/Infrastructure/Http/Requests/AgentCashInRequest.php` — validates `customer_identifier`, `amount`, `currency_code`, `transaction_pin`, `correlation_id`; enforces `Idempotency-Key`; accepts optional `X-Step-Up-Token` header
- [X] T066 [US3] Implement `AgentTransactionController` in `app/Modules/Funding/Infrastructure/Http/Controllers/AgentTransactionController.php` — POST /v1/funding/agent-transactions (201 sync); GET /v1/funding/agent-transactions/{id}; requires `funding.agent-cashin` permission

### Tests

- [X] T067 [US3] Write `tests/Feature/Modules/Funding/AgentCashInTest.php` — covers: happy path (201, fee=0, net_amount=amount, receipt present, agent float decremented, customer pocket incremented; SC-E08-002); idempotent replay (200, same receipt); incorrect PIN → 422 INVALID_PIN; insufficient float → 422 INSUFFICIENT_FLOAT (exactly at float balance succeeds — EC-E08-05); customer not found → 404; customer wallet not found → 422; KYC insufficient → 422; step-up required → 403 STEP_UP_REQUIRED; audit events for cash-in; `fee_amount=0` assertion regardless of config value (FR-E08-050)
- [X] T068 [US3] Write `tests/Concurrency/Modules/Funding/AgentFloatConcurrencyTest.php` — two simultaneous cash-in requests from same agent, float covers only one; exactly one succeeds; float balance = 0 after successful one (CC-E08-002; SC-E08-004)

**Checkpoint**: Agent cash-in completes synchronously in a single request. Float debit and pocket credit are atomic. SC-E08-002, SC-E08-004 pass.

---

## Phase 6: Bank Matching — US-FUND-011–014 (P1)

**Goal**: Bank push notification matched to an open `awaiting_match` transaction → wallet credited; unmatched deposits stored with event emitted; admin can review and resolve.

**Independent Test**: `php artisan test tests/Feature/Modules/Funding/BankMatchingTest.php` — quickstart.md Scenario 6, Scenario 7.

### Implementation

- [X] T069 [US4] Implement `BankMatchingService` in `app/Modules/Funding/Application/Services/BankMatchingService.php` — queries `FundingTransaction WHERE bank_reference_code=? AND status=awaiting_match AND bank_reference_expires_at > NOW() AND amount=? AND pocket_currency_code=?`; acquires `SELECT ... FOR UPDATE` on matched row (CC-E08-004 race guard); delegates to `FundingCreditService` on match; stores `BankReconciliation` record; emits `FundingBankDepositReconciled`; on no match: creates `UnmatchedDeposit`, emits `FundingUnmatchedDepositReceived`; idempotent via `funding_bank:{bank_notification_id}` namespace (FR-E08-029–030)
- [X] T070 [US4] Implement `BankNotificationService` in `app/Modules/Funding/Application/Services/BankNotificationService.php` — validates `X-Bank-Api-Key` system credential (SR-E08-007); delegates to `BankMatchingService`; returns `{matched: true, funding_transaction_id}` or `{matched: false, unmatched_deposit_id}`
- [X] T071 [US4] Create `app/Modules/Funding/Infrastructure/Http/Requests/BankNotificationRequest.php` — validates `bank_notification_id`, `amount`, `currency_code`, `reference`, `received_at`; API key in `X-Bank-Api-Key` header
- [X] T072 [US4] Implement `BankNotificationController` in `app/Modules/Funding/Infrastructure/Http/Controllers/BankNotificationController.php` — POST /v1/funding/bank/notifications; system-to-system only; no customer Bearer auth (SR-E08-007)
- [X] T073 [P] [US4] Create `app/Modules/Funding/Infrastructure/Http/Resources/UnmatchedDepositResource.php`
- [X] T074 [P] [US4] Implement `AdminUnmatchedDepositController` in `app/Modules/Funding/Infrastructure/Http/Controllers/AdminUnmatchedDepositController.php` — GET /v1/funding/admin/unmatched-deposits (paginated, filterable by `resolution_status`); PATCH /v1/funding/admin/unmatched-deposits/{id} (update resolution_status + notes); requires `funding.unmatched.review` permission; audits PATCH action (SR-E08-010)
- [X] T075 [US4] Implement `BankReconciliationService` in `app/Modules/Funding/Application/Services/BankReconciliationService.php` — creates `BankReconciliation` record linking `bank_notification_id` to `funding_transaction_id` (FR-E08-029); UNIQUE constraint on `bank_notification_id` handles simultaneous duplicate notifications (EC-E08-08)

### Tests

- [X] T076 [US4] Write `tests/Feature/Modules/Funding/BankMatchingTest.php` — covers: end-to-end bank transfer (reference generated → bank notification → match → wallet credited → BankReconciliation record; SC-E08-006); unmatched deposit (invalid reference → UnmatchedDeposit stored → event emitted → no wallet credit; SC-E08-007); expired reference → bank notification for expired reference → unmatched deposit (EC-E08-07); cancelled transaction reference → notification for cancelled tx reference → unmatched deposit (FR-E08-028); duplicate `bank_notification_id` → 200 idempotent (CC-E08-004 via UNIQUE constraint; EC-E08-08); invalid API key → 401; audit events
- [X] T077 [US4] Write `tests/Concurrency/Modules/Funding/BankMatchingConcurrencyTest.php` — two simultaneous bank notifications for same `bank_reference_code` → exactly one credit, UNIQUE constraint on `bank_reconciliations.bank_notification_id` prevents double processing (CC-E08-004)

**Checkpoint**: Full bank transfer flow works end-to-end. SC-E08-006, SC-E08-007 pass. Unmatched deposit admin review API functional.

---

## Phase 7: Transaction Expiry & Metrics (Cross-Cutting)

**Purpose**: Stale transactions expired on a schedule; 9 metrics emitted from service classes.

### Implementation

- [X] T078 Implement `ExpireStaleTransactionsCommand` in `app/Modules/Funding/Console/ExpireStaleTransactionsCommand.php` — signature: `funding:expire-stale {--dry-run}`; processes `processing` timeout (FR-E08-047 case 1) and `awaiting_match` expiry (FR-E08-047 case 2) in single pass; each transition in its own DB transaction; failure on one record does not block others (FR-E08-048); emits `FundingTransactionExpired` for each; reads config keys `funding.processing.timeout_minutes` and `funding.bank_reference.expiry_days` (FR-E08-049); `--dry-run` outputs record IDs without transitioning
- [X] T079 Register `funding:expire-stale` in `FundingServiceProvider::boot()` as a scheduled command running every 5 minutes; bind command in `FundingServiceProvider::register()` (research.md §12)
- [X] T080 Inject `MetricsCollectorInterface` into `FundingInitiationService`, `FundingCreditService`, `ProviderCallbackService`, `AgentCashInService`, `BankMatchingService`, `ExpireStaleTransactionsCommand` — emit all 9 metrics from FR-E08-051: `funding.transactions.initiated`, `funding.transactions.completed`, `funding.transactions.failed`, `funding.transactions.cancelled`, `funding.transactions.expired`, `funding.callbacks.processing.duration` (histogram), `funding.agent_cashin.posted`, `funding.bank.deposits.matched`, `funding.bank.deposits.unmatched`; metric emission after DB transaction commits; failure does not roll back business operation (FR-E08-052)

### Tests

- [X] T081 Write `tests/Unit/Modules/Funding/ExpireStaleTransactionsCommandTest.php` — covers: `processing` timeout transition (status=failed, reason=processing_timeout, Expired event emitted); `awaiting_match` reference expiry transition; no-op when no stale records; `--dry-run` lists IDs without modifying status; per-record DB transaction isolation (failure on one does not block others; FR-E08-048)

**Checkpoint**: `php artisan funding:expire-stale --dry-run` works without errors. Expiry command transitions correctly with appropriate events.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Complete `FundingServiceProvider` wiring, run full concurrency battery, run PHPStan, validate quickstart.md.

- [X] T082 Complete `FundingServiceProvider::register()` — bind `FundingModuleContract → FundingService`, `FundingProviderAdapter → MockFundingProviderAdapter`, `MetricsCollectorInterface → NullMetricsCollector` (test default); validate `ledger.float_account.BWP` config key at boot, throw `FloatAccountNotConfiguredException` if null (plan.md dependency gap)
- [X] T083 Finalize `routes/modules/funding.php` — wire all 12 endpoints per contracts/api-contract.md with correct middleware: `auth:sanctum`, `identity.require-permission:funding.X`, idempotency enforcement middleware; bank notification route uses `auth.api-key` middleware (SR-E08-007); admin routes require `funding.unmatched.review`
- [X] T084 Write `tests/Concurrency/Modules/Funding/DailyLimitConcurrencyTest.php` — two simultaneous initiation requests from same customer where combined amount would exceed daily limit; CC-E08-003 SELECT-in-transaction guard ensures exactly one passes (CC-E08-003)
- [X] T085 [P] Write audit-trail integration assertions — add `assertAllFundingMutationsAuditedTest.php` in `tests/Feature/Modules/Funding/` verifying FR-E08-046 audit actions for: funding.transaction.initiated, funding.transaction.confirmed, funding.transaction.completed, funding.transaction.failed, funding.transaction.cancelled, funding.agent_cashin.initiated, funding.agent_cashin.completed, funding.bank_deposit.matched, funding.bank_deposit.unmatched, funding.unmatched_deposit.resolved (SC-E08-008)
- [X] T086 Run PHPStan level 8 over `app/Modules/Funding/` and fix all type errors: `./vendor/bin/phpstan analyse app/Modules/Funding/ --level=8`
- [X] T087 [P] Add `Funding.Transaction.Expired` row to `spec.md` §7.13 Domain Events table and update plan.md observability block from "9 domain events" to "10 domain events" (resolves analyze finding H2)
- [X] T088 [P] Fix `spec.md` FR-E08-038 cross-reference from "(see §7.11)" to "(see §7.12)" (resolves analyze finding H1)
- [X] T089 [P] Swap §19 DoD and §20 Clarifications section order in `spec.md` so DoD follows §18 (resolves analyze finding H3)
- [X] T090 Run `quickstart.md` Scenarios 1–12 against local environment and confirm all pass; note any environment prerequisite gaps

**Checkpoint**: PHPStan level 8 clean; all 12 SC-E08-001 through SC-E08-012 pass; audit trail integration test green; quickstart.md validation complete.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 complete — BLOCKS all story phases
- **Phase 3 (Initiation)**: Depends on Phase 2 — can start first after foundation; is MVP gate
- **Phase 4 (Provider Processing)**: Depends on Phase 3 (`FundingCreditService` reuses `FundingTransactionModel` + `FundingProviderAdapter` interface)
- **Phase 5 (Agent Cash-In)**: Depends on Phase 2 + `FundingCreditService` from Phase 4 (`AgentCashInService` reuses same credit posting path)
- **Phase 6 (Bank Matching)**: Depends on Phase 3 (confirmation generates bank reference) + Phase 4 (`FundingCreditService`)
- **Phase 7 (Expiry & Metrics)**: Depends on Phases 3–5 (injects `MetricsCollectorInterface` into those services; expiry command modifies `FundingTransactionModel`)
- **Phase 8 (Polish)**: Depends on all prior phases

### Within Each Phase

- Migrations (T007–T013) → Models → Services → HTTP layer
- Enums, exceptions, events (T014–T027) are independent [P] and can run in parallel after migrations
- All [P]-marked tasks share no file dependencies within their phase

### Parallel Opportunities

```bash
# Phase 2 parallelizable block (after T007–T013 migrations done):
T014 FundingMethod enum
T015 FundingStatus enum
T016 FundingFailureReason enum
T017 Domain exceptions (8 files)
T018–T027 Domain events (10 files)
T028–T032 Eloquent models (5 files)

# Phase 3 parallel block:
T039 FundingFeeService
T040 FundingLimitService
T042 InitiateFundingRequest
T043 FundingTransactionResource
T045 show() + index() endpoints
T054 FeeService unit tests
T055 LimitService unit tests
```

---

## Implementation Strategy

### MVP First (Phases 1–4)

1. Phase 1: Module scaffold + dependency stubs
2. Phase 2: Migrations + models + domain layer
3. Phase 3: Funding Initiation (pending → processing)
4. Phase 4: Provider Processing (callback → completed)
5. **STOP and VALIDATE**: Run `quickstart.md` Scenarios 1, 3, 5, 9, 10, 11, 12

### Incremental Delivery

- Phases 1–4 → Digital funding complete (mobile money flow via mock provider)
- Phase 5 → Agent cash-in complete
- Phase 6 → Bank transfer complete
- Phase 7 → Expiry + observability complete
- Phase 8 → Production-ready

### Critical Path

```
T001–T006 (scaffold)
  → T007–T013 (migrations)
    → T014–T038 (parallel: enums + models + events + exceptions + contracts)
      → T039–T040 (fee + limit services, parallel)
        → T041 (initiation service)
          → T046 (confirmation service)
            → T047 (FundingInitiationJob — resolves C2)
              → T056 (FundingCreditService)
                → T057 (ProviderCallbackService)
                  → Phase 5 (agent cash-in can run in parallel with Phase 6 from here)
```

---

## Notes

- `FundingInitiationJob` (T047) is required to resolve analyze finding **C2** (plan.md module structure lacked `Jobs/` directory for queued `initiate()` dispatch from FR-E08-016)
- T087–T089 resolve analyze findings H1, H2, H3 from the `/speckit-analyze` report (spec-only fixes, no implementation impact)
- E08 Advance Gate: **TBD-E07-001** (`ledger.float_account.BWP` config key) must be populated before `FundingCreditService` (T056) will work end-to-end; T013 seeds `NULL` stub
- E08 Advance Gate: **TBD-E07-002** (customer wallet pocket `account_type`) must be confirmed before `FundingCreditService` resolves the pocket reference via `WalletModuleContract::getPocketLedgerAccountRef()`
- All wallet credits flow through `LedgerModuleContract::postEntry()` — no direct balance mutation anywhere in this module
- `FundingReceipt` is immutable after insert (T030); do not add `update()` calls
- Agent cash-in `fee_amount=0` is a **domain assertion** in `AgentCashInService` (T064), not a config default
