# Tasks: Shared Payment Integration and Orchestration (E48)

**Input**: Design documents from `specs/048-shared-payment-integration/`

**Feature Directory**: `specs/048-shared-payment-integration/`

**Spec**: 14 user stories | **Plan**: `plan.md` | **Data model**: `data-model.md` | **Contracts**: `contracts/`

**Stack**: Laravel/PHP 8.4+ (API) · Next.js 14/TypeScript (Admin) · Flutter 3.x/Dart (Mobile)

**API root**: `wallet-ecosystem/services/api/app/Modules/Payment/`
**Admin root**: `wallet-ecosystem/apps/admin/`
**Customer root**: `wallet-ecosystem/apps/customer/lib/modules/payment/`
**Agent root**: `wallet-ecosystem/apps/agent/lib/modules/payment/`

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Parallelizable — different files, no blocking dependencies within this phase
- **[Story]**: User story label mapping to spec.md stories (S01–S14)

---

## Phase 1: Setup — Architecture Decisions and Module Skeleton (Story S01)

**Goal**: Produce the two required ADRs, register the Payment module service provider, and verify the module skeleton loads.

**Independent Test**: `php artisan tinker --execute="app()->bound(\App\Modules\Payment\Domain\Contracts\PaymentModuleContract::class)"` returns `true`; PHPStan reports zero errors on new files.

- [X] T001 [S01] Create ADR docs/architecture/decisions/ADR-E48-001-payment-module-ownership.md — document module ownership boundary, consuming-module interface rule, adapter isolation constraint, status Accepted
- [X] T002 [P] [S01] Create ADR docs/architecture/decisions/ADR-E48-002-wallet-payment-adapter-delegation.md — document WalletModuleContract delegation pattern, prohibition on direct balance mutation, additive-extension rule for WalletModuleContract, status Accepted
- [X] T003 [S01] Create module directory wallet-ecosystem/services/api/app/Modules/Payment/ with Domain/, Application/, Infrastructure/, Http/, Providers/ subdirectories
- [X] T004 [S01] Create wallet-ecosystem/services/api/app/Modules/Payment/Providers/PaymentServiceProvider.php — register module boot, bind contracts, register adapters, define queue/job assignments; extend existing service provider registration pattern
- [X] T005 [S01] Register PaymentServiceProvider in wallet-ecosystem/services/api/bootstrap/providers.php and add 'payment' connection to config/database.php
- [X] T006 [P] [S01] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/ProductionBlockedException.php
- [X] T007 [P] [S01] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/DuplicateAttemptException.php
- [X] T008 [P] [S01] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/CapabilityUnsupportedException.php
- [X] T009 [P] [S01] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/RefundLimitExceededException.php
- [X] T010 [P] [S01] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/StepUpRequiredException.php
- [X] T011 [P] [S01] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/InsufficientFundsException.php
- [X] T012 [P] [S01] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/PurposeNotPayableException.php
- [X] T013 [P] [S01] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/PaymentIntentNotFoundException.php

**Checkpoint S01**: `php artisan tinker --execute="new \App\Modules\Payment\Domain\Exceptions\ProductionBlockedException()"` — no class-not-found error. ADRs exist and are Accepted.

---

## Phase 2: Foundational — Data Model, Core Contracts, Enums (Story S02)

**Goal**: Create the `payment.*` PostgreSQL schema, all eight tables, core enums, value objects, and the `BusinessPurposeResolverInterface` — everything required before any story can function.

**Independent Test**: Run all eight migrations against a test database and confirm tables exist with correct columns. Create a `PaymentIntent` Eloquent model record and confirm UUID v7 ID is assigned and `amount_minor` is stored as integer.

- [X] T014 [S02] Create migration app/Modules/Payment/Infrastructure/Migrations/2026_10_18_000001_create_payment_schema.php — `CREATE SCHEMA IF NOT EXISTS payment`
- [X] T015 [S02] Create migration app/Modules/Payment/Infrastructure/Migrations/2026_10_18_000002_create_payment_providers_table.php — columns per data-model.md; seed OleKard Wallet and MockBank rows
- [X] T016 [S02] Create migration app/Modules/Payment/Infrastructure/Migrations/2026_10_18_000003_create_payment_provider_accounts_table.php — columns per data-model.md; FK to payment_providers
- [X] T017 [S02] Create migration app/Modules/Payment/Infrastructure/Migrations/2026_10_18_000004_create_payment_intents_table.php — columns per data-model.md; BIGINT amount_minor; UNIQUE(idempotency_key, payer_actor_id); seed 9 payment.* permissions
- [X] T018 [S02] Create migration app/Modules/Payment/Infrastructure/Migrations/2026_10_18_000005_create_payment_attempts_table.php — columns per data-model.md; FK to payment_intents and payment_provider_accounts
- [X] T019 [S02] Create migration app/Modules/Payment/Infrastructure/Migrations/2026_10_18_000006_create_payment_refunds_table.php — columns per data-model.md; FK to payment_intents and payment_attempts
- [X] T020 [S02] Create migration app/Modules/Payment/Infrastructure/Migrations/2026_10_18_000007_create_payment_callbacks_table.php — columns per data-model.md; UNIQUE(provider_code, idempotency_key) WHERE idempotency_key IS NOT NULL
- [X] T021 [S02] Create migration app/Modules/Payment/Infrastructure/Migrations/2026_10_18_000008_create_reconciliation_exceptions_table.php — columns per data-model.md; also create payment.bank_provider_documentation_gaps table in this migration
- [X] T022 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/PaymentIntentStatus.php — all 11 cases from data-model.md; implement isTerminal(): bool and canTransitionTo(self $target): bool using static transition table
- [X] T023 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/PaymentAttemptStatus.php — all 8 cases; implement isTerminal(): bool
- [X] T024 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/PaymentRefundStatus.php — all 6 cases
- [X] T025 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/ProviderCapabilityEnum.php — all 8 cases from data-model.md
- [X] T026 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/ReconciliationExceptionType.php — all 7 types from data-model.md
- [X] T027 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/PayableObligation.php — readonly class: amount_minor int, currency_code string, organisation_id string, payer_actor_id string, metadata array; constructor validates amount_minor > 0 and not float
- [X] T028 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/PaymentIntentRequest.php — readonly class: purpose_type, purpose_reference, organisation_id, payer_actor_id, currency_code, provider_code, provider_account_id, idempotency_key, correlation_id, metadata array
- [X] T029 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/OptionsQuery.php — readonly: purpose_type, purpose_reference, currency_code, amount_minor int, organisation_id, actor_id, country_code
- [X] T030 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/PaymentProviderOption.php — readonly: provider_code, display_name, is_wallet bool, wallet_balance_minor ?int, has_sufficient_balance ?bool, is_default bool, sort_order int, checkout_flow ?string
- [X] T031 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/PaymentInitiateRequest.php — readonly: payment_intent_id, payment_attempt_id, amount_minor int, currency_code, payer_actor_id, wallet_id, step_up_token ?string, correlation_id, idempotency_key
- [X] T032 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/PaymentAttemptResult.php — readonly: status PaymentAttemptStatus, provider_reference_id ?string, next_action_type ?string, next_action_data ?array, fee_minor int, wallet_hold_id ?string, ledger_journal_id ?string
- [X] T033 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/PaymentRefundRequest.php — readonly: payment_intent_id, payment_attempt_id, refund_id, amount_minor int, currency_code, provider_reference_id, correlation_id, idempotency_key
- [X] T034 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/BusinessPurposeResolverInterface.php — per contracts/module-contract.md: supportedPurposeType(): string and resolve(purposeReference, actorId, correlationId): PayableObligation
- [X] T035 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentAdapterInterface.php — extends ProviderAdapterInterface (Platform E1000); methods: initiatePayment, queryPaymentStatus, initiateRefund, cancelPayment, getCapabilities, getProviderIdentifier per contracts/module-contract.md
- [X] T036 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentModuleContract.php — per contracts/module-contract.md: createPaymentIntent, getAvailableOptions, getPaymentStatus
- [X] T037 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentIntentRepositoryInterface.php — methods: save, findById, findByIdempotencyKey, findActiveForPurpose, findExpiring(before DateTimeImmutable)
- [X] T038 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentAttemptRepositoryInterface.php — methods: save, findById, findActiveForIntent, findByIdempotencyKey, findTimedOut(before DateTimeImmutable)
- [X] T039 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentRefundRepositoryInterface.php — methods: save, findById, findByIntentId, sumConfirmedRefundsForAttempt(attemptId): int
- [X] T040 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentProviderRepositoryInterface.php — methods: findByCode, findActiveForContext(OptionsQuery), findProviderAccount(providerAccountId)
- [X] T041 [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/StateMachines/PaymentIntentStateMachine.php — static ALLOWED_TRANSITIONS table matching data-model.md; assertValidTransition(current, target): void throws InvalidStateTransitionException with clear message
- [X] T042 [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/StateMachines/PaymentAttemptStateMachine.php — same pattern as T041 for attempt transitions
- [X] T043 [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentIntentModel.php — Eloquent; table = 'payment.payment_intents'; UUID v7 cast; PaymentIntentStatus cast; fillable, casts, relationships to PaymentAttemptModel and PaymentRefundModel
- [X] T044 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentAttemptModel.php — Eloquent; table = 'payment.payment_attempts'; UUID v7 cast; PaymentAttemptStatus cast; belongs-to PaymentIntentModel
- [X] T045 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentRefundModel.php — Eloquent; table = 'payment.payment_refunds'; UUID v7; PaymentRefundStatus cast
- [X] T046 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentProviderModel.php — Eloquent; table = 'payment.payment_providers'
- [X] T047 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentProviderAccountModel.php — Eloquent; table = 'payment.payment_provider_accounts'; JSON casts for array columns
- [X] T048 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentCallbackModel.php — Eloquent; table = 'payment.payment_callbacks'
- [X] T049 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/ReconciliationExceptionModel.php — Eloquent; table = 'payment.reconciliation_exceptions'
- [X] T050 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/BankProviderDocumentationGapModel.php — Eloquent; table = 'payment.bank_provider_documentation_gaps'; JSON casts for required_items, received_items
- [X] T051 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentIntentRepository.php — implements PaymentIntentRepositoryInterface; all methods using PaymentIntentModel; SELECT FOR UPDATE on findById when $lock = true parameter
- [X] T052 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentAttemptRepository.php — implements PaymentAttemptRepositoryInterface
- [X] T053 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentRefundRepository.php — implements PaymentRefundRepositoryInterface; sumConfirmedRefundsForAttempt uses DB::raw('SUM(amount_minor)') with FOR UPDATE
- [X] T054 [P] [S02] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentProviderRepository.php — implements PaymentProviderRepositoryInterface; findActiveForContext filters by is_enabled, !is_suspended, currency, amount, organisation, module
- [X] T055 [S02] Write unit tests wallet-ecosystem/services/api/tests/Unit/Modules/Payment/StateMachines/PaymentIntentStateMachineTest.php — test every valid transition passes; test every invalid transition throws InvalidStateTransitionException; test isTerminal() for all statuses
- [X] T056 [P] [S02] Write unit tests wallet-ecosystem/services/api/tests/Unit/Modules/Payment/StateMachines/PaymentAttemptStateMachineTest.php — same pattern for attempt state machine

**Checkpoint S02**: `php artisan migrate` applies 8 migrations cleanly on a fresh DB; all 9 payment.* permission rows exist; `PaymentIntentStateMachineTest` and `PaymentAttemptStateMachineTest` pass.

---

## Phase 3: Provider Registry and Capability Model (Story S03)

**Goal**: Implement provider registration, capability model, and option-discovery service so the platform can determine which payment options are available for any given context.

**Independent Test**: Seed OleKard Wallet provider + account for BWP. Call `ProviderRegistryService::discoverOptions(OptionsQuery)` — wallet option returned. Suspend the wallet provider — wallet option absent. Verify `CapabilityUnsupportedException` when unsupported capability requested.

- [X] T057 [S03] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/ProviderRegistryService.php — discoverOptions(OptionsQuery): array returns PaymentProviderOption[]; filters: is_enabled=true, is_suspended=false, currency in supported_currencies, amount between min/max, organisation in enabled_organisations OR empty array, module in enabled_modules OR empty array; ordered by sort_order; for wallet option sets wallet_balance_minor via WalletModuleContract::availableBalance() if payer has wallet
- [X] T058 [S03] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentProviderConfigValidator.php — validateForEnable(providerAccountId): void; checks all required fields non-null; checks adapter_identifier is registered in service provider; throws ValidationException with field-level messages
- [X] T059 [S03] Add PaymentProviderRegistryService binding in wallet-ecosystem/services/api/app/Modules/Payment/Providers/PaymentServiceProvider.php; bind all repository interfaces to their Eloquent implementations; register adapter bindings keyed by provider code
- [X] T060 [S03] Write unit tests wallet-ecosystem/services/api/tests/Unit/Modules/Payment/Services/ProviderRegistryServiceTest.php — test: active wallet option present; suspended provider absent; amount below min excluded; amount above max excluded; disabled provider excluded; wallet insufficient balance shows has_sufficient_balance=false; cross-organisation provider excluded; currency not supported excluded; capability flags respected

**Checkpoint S03**: `ProviderRegistryServiceTest` passes; `discoverOptions` returns correct options with suspension/limit filtering verified.

---

## Phase 4: Secure Admin Configuration (Story S04)

**Goal**: Next.js admin UI for registering, configuring, enabling/disabling, and monitoring payment providers — with audit trail and approval workflow for high-risk changes.

**Independent Test**: Navigate to `/admin/payment/providers`; create OleKard Wallet provider record; confirm it appears in list; confirm audit log has creation entry; confirm secret reference is not in API response; MockBank shows DEVELOPMENT MOCK banner.

- [X] T061 [P] [S04] Create wallet-ecosystem/apps/admin/lib/api/payment.ts — TypeScript interfaces PaymentProvider, PaymentProviderAccount, ReconciliationException per data-model.md TypeScript types section
- [X] T062 [P] [S04] Create wallet-ecosystem/apps/admin/src/modules/payment/api/payment-api-client.ts — functions: listProviders, getProvider, createProvider, updateProvider, suspendProvider, rotateCredential, listReconciliationExceptions, updateExceptionStatus; use existing AdminAppClient pattern from other modules
- [X] T063 [S04] Create backend route group in wallet-ecosystem/services/api/routes/api.php — add /v1/admin/payment routes: GET/POST /admin/payment/providers, GET/PUT /admin/payment/providers/{id}, POST /admin/payment/providers/{id}/suspend, GET/PATCH /admin/payment/reconciliation/exceptions/{id}; apply payment.providers.read and payment.providers.manage middleware
- [X] T064 [S04] Create wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/PaymentProviderAdminController.php — thin controller: index, store, show, update, suspend; delegates to PaymentProviderAdminService; validates RBAC; emits audit events via AuditService; NEVER returns credential values in response
- [X] T065 [S04] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentProviderAdminService.php — createProvider, updateProvider, suspendProvider, rotateCredentialReference; high-risk change detection (merchant_account_reference changes require payment.config.approve_high_risk approval); audit via AuditService for every write
- [X] T066 [S04] Create wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/ReconciliationExceptionAdminController.php — index (paginated, filterable by status), update (notes + status only; no financial state change); requires payment.reconciliation.manage
- [X] T067 [P] [S04] Create wallet-ecosystem/apps/admin/src/modules/payment/components/ProviderStatusBadge.tsx — badge showing: Active (green), Suspended (orange), Sandbox (blue), Mock/Dev (red with warning icon)
- [X] T068 [P] [S04] Create wallet-ecosystem/apps/admin/src/modules/payment/components/MockBannerAlert.tsx — prominent red banner "DEVELOPMENT MOCK — NOT FOR PRODUCTION"; renders when provider.environment === 'mock'; not dismissible
- [X] T069 [P] [S04] Create wallet-ecosystem/apps/admin/src/modules/payment/components/CapabilityFlagsEditor.tsx — checkbox grid for each ProviderCapabilityEnum value; disabled when provider is enabled (to prevent runtime capability changes)
- [X] T070 [P] [S04] Create wallet-ecosystem/apps/admin/src/modules/payment/components/CredentialRotationForm.tsx — form for new secret reference (not the value); confirm dialog; on save shows success (never shows old or new secret value); uses existing Dialog component
- [X] T071 [S04] Create wallet-ecosystem/apps/admin/src/modules/payment/pages/payment-providers.tsx — list page; columns: name, code, environment, status badge, sort order, action buttons; uses Table component; requires payment.providers.read permission gate
- [X] T072 [S04] Create wallet-ecosystem/apps/admin/src/modules/payment/pages/payment-provider-detail.tsx — detail page: provider info, account config, capability flags, documentation gaps (if mock/blocked), connection health indicator, audit history; requires permission gate
- [X] T073 [S04] Create wallet-ecosystem/apps/admin/src/modules/payment/pages/payment-provider-create.tsx — create form; all fields from PaymentProviderAccount; PaymentProviderConfigValidator errors shown inline; on submit calls createProvider; requires payment.providers.manage
- [X] T074 [S04] Create wallet-ecosystem/apps/admin/src/modules/payment/pages/payment-provider-edit.tsx — edit form; high-risk fields (merchant_account_reference) show approval-required warning using existing ApprovalWorkflow component; requires payment.providers.manage
- [X] T075 [S04] Create wallet-ecosystem/apps/admin/src/modules/payment/pages/reconciliation-exceptions.tsx — list page; columns: type, provider, intent reference, description, status, detected/expected amounts; filter by status; click → detail with investigation notes form; requires payment.reconciliation.view
- [X] T076 [S04] Add payment module routes to wallet-ecosystem/apps/admin/src/lib/navigation/ — add "Payment" section with providers and reconciliation links; add permission slugs to admin-app-client.ts
- [X] T077 [S04] Write Jest tests wallet-ecosystem/apps/admin/__tests__/features/payment/payment-providers.test.tsx — test: list renders with status badges; MockBannerAlert shown for mock provider; credential rotation form never shows secret value; high-risk edit shows approval warning; reconciliation exception notes save; RBAC gates (no payment.providers.read → redirect)

**Checkpoint S04**: `npm test -- --testPathPattern=payment-providers` passes; admin UI renders provider list with correct badges; mock banner visible; secret never in API response.

---

## Phase 5: Payment Orchestration and State Machines (Story S05)

**Goal**: `PaymentOrchestrationService` with intent creation, attempt initiation, outcome handling, and expiry — enforcing state machines, idempotency, and duplicate prevention.

**Independent Test**: Create intent → initiate attempt → simulate outcome → confirm state transitions; replay same callback → no second effect; attempt second simultaneous attempt → `DuplicateAttemptException`.

- [X] T078 [S05] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/PaymentOrchestrationService.php — methods: createIntent, initiateAttempt, handleOutcome, cancelAttempt, expireIntent; each method: (1) acquires idempotency lock via IdempotencyServiceInterface, (2) uses PaymentIntentStateMachine.assertValidTransition, (3) wraps DB writes in transaction, (4) publishes outbox event via OutboxEventPublisher, (5) records idempotency response; no float arithmetic; amounts always int
- [X] T079 [S05] Implement createIntent in PaymentOrchestrationService — resolves amount via BusinessPurposeResolverInterface; validates no duplicate active intent for same purpose+organisation via PaymentIntentRepository::findActiveForPurpose; creates PaymentIntent record; sets expires_at from ConfigurationService config key 'payment.intent_ttl_minutes' defaulting to 30
- [X] T080 [S05] Implement initiateAttempt in PaymentOrchestrationService — checks active attempt guard (DuplicateAttemptException if non-terminal attempt exists); resolves adapter via PaymentServiceProvider binding keyed by provider_code; calls adapter.initiatePayment(request); transitions intent via state machine; creates PaymentAttempt record; schedules PaymentAttemptTimeoutJob with timeout from ConfigurationService 'payment.attempt_timeout_minutes'
- [X] T081 [S05] Implement handleOutcome in PaymentOrchestrationService — transitions attempt and intent via state machines; posts wallet ledger entry for wallet provider via LedgerModuleContract (only in handleOutcome, not in adapter — adapter sets up hold, orchestrator posts debit); publishes payment.confirmed/payment.failed outbox event; for succeeded: links attempt.ledger_journal_id
- [X] T082 [S05] Implement expireIntent in PaymentOrchestrationService — transitions intent to expired; releases wallet hold via WalletModuleContract.releaseHold if attempt.wallet_hold_id is set; publishes payment.failed outbox event with reason 'intent_expired'
- [X] T083 [S05] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/Jobs/PaymentAttemptTimeoutJob.php — ShouldQueue; re-queries attempt from DB; if still non-terminal: calls adapter.queryPaymentStatus; if ambiguous creates ReconciliationException; if failed calls handleOutcome; if succeeded calls handleOutcome; uses database uniqueVia() to prevent duplicate jobs
- [X] T084 [S05] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/Jobs/PaymentIntentExpiredJob.php — ShouldQueue; finds non-succeeded intents past expires_at in batches; calls expireIntent for each; runs as scheduled job (register in console kernel schedule)
- [X] T085 [S05] Create wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/PaymentIntentController.php — thin controller: store (createIntent), show (status query); inject PaymentOrchestrationService; validate org scoping (payer must belong to request org); attach Idempotency-Key header middleware
- [X] T086 [P] [S05] Create wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/PaymentOptionsController.php — show (options list); injects ProviderRegistryService; validates actor authentication; returns PaymentProviderOption[] serialized per contracts/payment-api.md
- [X] T087 [S05] Add consumer-facing routes to wallet-ecosystem/services/api/routes/modules/payment.php — POST/GET /v1/payment/intents, GET /v1/payment/intents/{id}/status, POST /v1/payment/intents/{id}/attempts, GET /v1/payment/options; apply auth middleware
- [X] T088 [S05] Create wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/PaymentAttemptController.php — store: validates intent ownership (payer_actor_id = auth actor); calls PaymentOrchestrationService.initiateAttempt; returns attempt result per contracts/payment-api.md
- [X] T089 [S05] Write feature tests wallet-ecosystem/services/api/tests/Feature/Modules/Payment/PaymentOrchestrationTest.php — test: intent created with server-resolved amount; duplicate idempotency key returns same intent; mobile amount ignored (tampered amount test); DuplicateAttemptException on second non-terminal attempt; state machine invalid transition rejected; intent expiry transitions to expired; PHPStan level 8 on all new files
- [X] T090 [S05] Write concurrency test wallet-ecosystem/services/api/tests/Feature/Modules/Payment/PaymentIdempotencyTest.php — 50 parallel identical POST requests produce exactly one PaymentIntent record (use parallel HTTP client or DB-level lock test); verify UNIQUE constraint catches races

**Checkpoint S05**: `PaymentOrchestrationTest` and `PaymentIdempotencyTest` pass; PHPStan level 8 clean on all Payment module files so far.

---

## Phase 6: OleKard Wallet Adapter (Story S06)

**Goal**: Wallet payments work end-to-end through the orchestration layer — balance check, hold, ledger debit, step-up check, reversal.

**Independent Test**: Initiate a BWP 100.00 wallet payment — hold placed; debit posted; attempt `succeeded`; `payment.confirmed` event published. Then reverse — hold released; credit posted; `payment.reversed` published. Concurrent attempt → exactly one succeeds (concurrency test).

- [X] T091 [S06] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Adapters/WalletPaymentAdapter.php — implements PaymentAdapterInterface; inject WalletModuleContract, LedgerModuleContract, ConfigurationService (for step-up threshold), AuditService, IdempotencyServiceInterface; getProviderIdentifier() returns 'olekard_wallet'; getCapabilities() declares initiate_payment, query_payment, full_refund, partial_refund, cancel_payment only
- [X] T092 [S06] Implement WalletPaymentAdapter::initiatePayment — (1) verify wallet ownership via WalletModuleContract.ownerActorId(walletId) === payerActorId; (2) check spendable balance via WalletModuleContract.availableBalance(); (3) check step-up if amount_minor >= ConfigurationService.get('auth.step_up_threshold'); (4) createHold via WalletModuleContract.createHold(); (5) set attempt.wallet_hold_id; return PaymentAttemptResult with status=succeeded, no next_action (wallet is synchronous)
- [X] T093 [S06] Implement WalletPaymentAdapter::initiateRefund — (1) releaseHold via WalletModuleContract.releaseHold(holdId); (2) post compensating credit via LedgerModuleContract.postEntry with direction='credit'; (3) return PaymentRefundResult with status=succeeded; idempotent: if holdId already released, skip releaseHold silently; NO modification to historical ledger entries
- [X] T094 [S06] Implement WalletPaymentAdapter::cancelPayment — if attempt has wallet_hold_id and hold not yet released: releaseHold; return PaymentCancelResult succeeded; if no hold (never initiated): return succeeded as no-op
- [X] T095 [S06] Implement WalletPaymentAdapter::queryPaymentStatus — returns current attempt status from DB; wallet payments are synchronous so external query never needed; reads PaymentAttemptRepository.findById(providerReferenceId)
- [X] T096 [S06] Register WalletPaymentAdapter binding in PaymentServiceProvider — bind 'olekard_wallet' adapter key; verify APP_ENV guard does NOT apply to wallet (only mock adapters are production-blocked)
- [X] T097 [S06] Write unit tests wallet-ecosystem/services/api/tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php — test: ownership verification rejects wrong actor; insufficient funds returns InsufficientFundsException; step-up required when above threshold; hold placed with correct amount; hold released on refund; compensating credit posted on refund; duplicate initiatePayment same idempotency key is no-op (no second hold); queryPaymentStatus reads DB only; getCapabilities() has correct 5 capabilities
- [X] T098 [S06] Write concurrency test wallet-ecosystem/services/api/tests/Feature/Modules/Payment/WalletPaymentConcurrencyTest.php — two simultaneous initiatePayment calls for same wallet pocket; assert exactly one hold created; assert wallet.available_balance never negative; use real DB transactions, not mocks

**Checkpoint S06**: `WalletPaymentAdapterTest` and `WalletPaymentConcurrencyTest` pass; `payment.confirmed` outbox event verified in integration test.

---

## Phase 7: External Bank Adapter Framework (Story S07)

**Goal**: `AbstractBankPaymentAdapter`, `BankCallbackVerifier`, `MockBankPaymentAdapter` with permanent production guard, and `BankProviderDocumentationGap` tracking for Bank 1/2/3 slots.

**Independent Test**: Instantiate `MockBankPaymentAdapter` — succeeds in test env. Instantiate with `APP_ENV=production` forced — `ProductionBlockedException` thrown. Verify abstract bank adapter satisfies `PaymentAdapterInterface` via stub test.

- [X] T099 [S07] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Adapters/AbstractBankPaymentAdapter.php — abstract class implements PaymentAdapterInterface; inject AuditService, MetricsCollectorInterface (Platform), ConfigurationService; implement: base send() using ProviderAdapterInterface; abstract methods: getBaseUrl(), getAuthHeaders(), getSignatureAlgorithm(), getCallbackSecretReference(); emit audit event and metrics on every call per FR-E1000-020/021; NEVER log credential values
- [X] T100 [S07] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Adapters/BankCallbackVerifier.php — verify(rawPayload: string, signature: string, algorithm: string, secretRef: string): bool; resolves actual secret from ConfigurationService (secret reference → resolved value for HMAC only); computes HMAC using hash_hmac with resolved secret; constant-time comparison via hash_equals; throws WebhookSignatureException if invalid; secret resolved value is never stored or logged
- [X] T101 [S07] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Adapters/MockBankPaymentAdapter.php — extends AbstractBankPaymentAdapter; constructor reads app()->environment(); if 'production' or 'staging': throw ProductionBlockedException immediately; getProviderIdentifier() returns 'mock_bank'; initiatePayment() returns redirect URL 'http://localhost/mock-bank/checkout?ref={attempt_id}&test=1'; all outcomes configurable via config('payment.mock_bank.default_outcome') defaulting to 'success'
- [X] T102 [S07] Register MockBankPaymentAdapter in PaymentServiceProvider — binding keyed 'mock_bank'; conditional on !app()->isProduction() — if production, binding throws ProductionBlockedException; add second layer protection beyond constructor guard
- [X] T103 [S07] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/BankCheckoutFlow.php — cases: Redirect, HostedCheckout, Sdk, AppSwitch
- [X] T104 [P] [S07] Create BankProviderDocumentationGap seeder wallet-ecosystem/services/api/database/seeders/BankProviderDocumentationGapSeeder.php — seed Bank1Slot, Bank2Slot, Bank3Slot with required_items: ['base_url','auth_scheme','webhook_signature_algorithm','webhook_signature_header','supported_currencies','refund_api_endpoint','refund_parameters','realtime_confirmation_behaviour','settlement_timing','sandbox_access_details']
- [X] T105 [S07] Write unit tests wallet-ecosystem/services/api/tests/Unit/Modules/Payment/Adapters/MockBankPaymentAdapterTest.php — test: production guard throws ProductionBlockedException when APP_ENV=production; dev env instantiates successfully; initiatePayment returns redirect URL with correct dev domain; getCapabilities() correct; BankCallbackVerifier HMAC valid signature passes; invalid signature throws WebhookSignatureException; wrong algorithm throws WebhookSignatureException

**Checkpoint S07**: `MockBankPaymentAdapterTest` passes; production guard verified; documentation gap records seeded.

---

## Phase 8: First Mock Bank Integration (Story S08)

**Goal**: Complete mock bank payment flow — initiation, redirect, callback with HMAC verification, status query, full and partial refund simulation — all clearly labelled as dev-only.

**Independent Test**: Full SC-04 quickstart scenario: create intent → initiate attempt → get redirect URL → post HMAC-signed callback → intent status `succeeded` → request full refund → refund `succeeded`. Run SC-05 duplicate callback replay → exactly one outcome.

- [X] T106 [S08] Implement MockBankPaymentAdapter::initiatePayment fully — return redirect URL 'http://localhost/mock-bank/checkout?ref={attempt_id}'; set next_action_type='redirect'; set attempt timeout; return status=awaiting_action; log [MOCK] prefix in all log entries
- [X] T107 [S08] Implement MockBankPaymentAdapter::queryPaymentStatus — reads current attempt DB status; if 'awaiting_action' and configurable test delay elapsed: return 'succeeded' outcome (simulates async bank); configurable via config('payment.mock_bank.auto_confirm_seconds')
- [X] T108 [S08] Implement MockBankPaymentAdapter callback parsing — implement ProviderWebhookContract.parse() in MockBankCallbackParser: verify HMAC-SHA256 using BankCallbackVerifier; extract transaction_ref, status, amount, currency from payload; return NormalizedWebhookEvent with idempotency_key = payload.transaction_ref + ':' + payload.timestamp
- [X] T109 [P] [S08] Create wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Adapters/MockBankCallbackParser.php — implements ProviderWebhookContract; delegates signature verification to BankCallbackVerifier; maps 'success'→'succeeded', 'failed'→'failed', other→'unknown'; production check in constructor
- [X] T110 [S08] Implement MockBankPaymentAdapter::initiateRefund — validates amount <= original; returns mock refund reference 'MOCK-REFUND-{uuid}'; simulates provider processing (status=processing); subsequent queryRefundStatus returns succeeded; all operations log [MOCK] prefix
- [X] T111 [S08] Create mock bank local HTTP handler route wallet-ecosystem/services/api/routes/web.php — GET /mock-bank/checkout?ref= renders a simple dev-only HTML page with "Complete Mock Payment" button; POST /mock-bank/complete posts callback to payment callback endpoint; only registered when APP_ENV != production
- [X] T112 [S08] Write integration tests wallet-ecosystem/services/api/tests/Feature/Modules/Payment/MockBankIntegrationTest.php — test: full flow SC-04 (initiate→redirect→callback→succeeded); SC-05 duplicate callback replay produces exactly one outcome; partial refund within limit succeeds; partial refund exceeding limit throws RefundLimitExceededException; production guard prevents mock adapter use
- [X] T113 [S08] Create docs/guides/bank-integration-requirements.md — documentation checklist for real bank integration: base URL, authentication scheme (API key/OAuth2/HMAC), webhook endpoint and headers, signature algorithm and secret rotation, supported currencies, refund API, real-time vs async confirmation, settlement timing, sandbox credentials, PCI scope confirmation; mark as REQUIRED before Bank 1 live integration

**Checkpoint S08**: `MockBankIntegrationTest` passes; quickstart SC-04 and SC-05 pass.

---

## Phase 9: Shared Consumer Checkout — Flutter (Story S09)

**Goal**: `PaymentCheckoutSheet` Flutter widget that fetches provider options from the API, shows wallet balance, handles redirect flows with authoritative status polling, and covers all 10 payment status states.

**Independent Test**: Mount `PaymentCheckoutSheet` in Flutter widget test with mocked repository — 10 state transitions all verify per contracts/flutter-checkout.md test contract.

- [X] T114 [S09] Create wallet-ecosystem/apps/customer/lib/modules/payment/data/payment_api_client.dart — methods: getOptions, createIntent, initiateAttempt, getStatus; use existing core/api HTTP client with auth interceptor; map JSON responses to domain models
- [X] T115 [P] [S09] Create wallet-ecosystem/apps/customer/lib/modules/payment/data/payment_repository.dart — interface wrapping payment_api_client.dart; abstraction for testability
- [X] T116 [P] [S09] Create wallet-ecosystem/apps/customer/lib/modules/payment/domain/models/payment_intent.dart — fields per data-model.md Dart models section; PaymentIntentStatus enum with isTerminal getter
- [X] T117 [P] [S09] Create wallet-ecosystem/apps/customer/lib/modules/payment/domain/models/payment_option.dart — fields per data-model.md; isWallet bool, walletBalanceMinor int?, hasSufficientBalance bool?
- [X] T118 [P] [S09] Create wallet-ecosystem/apps/customer/lib/modules/payment/domain/models/payment_status.dart — PaymentIntentStatus enum mirroring server values; fromJson factory
- [X] T119 [S09] Create wallet-ecosystem/apps/customer/lib/modules/payment/domain/payment_notifier.dart — Riverpod AsyncNotifier<PaymentCheckoutState> per contracts/flutter-checkout.md; methods: selectOption, confirmPayment(stepUpToken), pollStatus, cancelPayment; polling: 3s for first 5 polls, then 10s, cap 30s; save payment_intent_id to SharedPreferences before redirect
- [X] T120 [S09] Create wallet-ecosystem/apps/customer/lib/modules/payment/presentation/payment_checkout_sheet.dart — ConsumerStatefulWidget; all 10 states per contracts/flutter-checkout.md state machine; mode: sheet|page; on redirect: open URL via url_launcher or in-app browser; on return: read saved payment_intent_id from SharedPreferences and poll status; NEVER parse redirect URL params as payment proof
- [X] T121 [P] [S09] Create wallet-ecosystem/apps/customer/lib/modules/payment/presentation/widgets/payment_option_tile.dart — shows provider display name, icon placeholder, is-default badge; disabled state for insufficient balance
- [X] T122 [P] [S09] Create wallet-ecosystem/apps/customer/lib/modules/payment/presentation/widgets/wallet_balance_tile.dart — shows authorised balance from option.walletBalanceMinor; "Insufficient Balance" badge when !option.hasSufficientBalance; balance value from API response only (never from local wallet state)
- [X] T123 [P] [S09] Create wallet-ecosystem/apps/customer/lib/modules/payment/presentation/widgets/payment_amount_summary.dart — shows: purpose description, recipient, base amount, disclosed fee, total amount; amounts formatted using existing formatCurrency utility from core/
- [X] T124 [S09] Create wallet-ecosystem/apps/customer/lib/modules/payment/presentation/payment_status_screen.dart — full-screen status display for succeeded/failed/cancelled/expired states; shows next action for user (receipt for succeeded, retry for failed)
- [X] T125 [S09] Create convenience function showPaymentCheckout in wallet-ecosystem/apps/customer/lib/modules/payment/presentation/payment_checkout_sheet.dart — showModalBottomSheet wrapper per contracts/flutter-checkout.md signature
- [X] T126 [S09] Mirror payment module to agent app: create wallet-ecosystem/apps/agent/lib/modules/payment/ with same structure — reuse all domain models and notifier; copy presentation layer (agents use same checkout UI)
- [X] T127 [S09] Write Flutter widget tests wallet-ecosystem/apps/customer/test/modules/payment/payment_checkout_sheet_test.dart — all 10 state transitions per contracts/flutter-checkout.md test contract: options load, wallet insufficient funds, wallet payment succeeds, step-up required, bank redirect opened, bank redirect return→poll→succeeded, bank redirect return→poll→failed, network error, expired, cancel

**Checkpoint S09**: `flutter test test/modules/payment/` — all 10 widget tests pass; `flutter analyze` reports no issues in payment module.

---

## Phase 10: Callback Processing and Internal Events (Story S10)

**Goal**: Async callback inbox — raw persist before any processing, HMAC verification, deduplication, state machine transitions, outbox event publishing, dead-letter handling.

**Independent Test**: POST signed mock bank callback → HTTP 202 → raw record persisted → job processes → attempt/intent succeeded → `payment.confirmed` outbox event published. Replay same callback → `duplicate` status, no second effect.

- [X] T128 [S10] Create wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/PaymentCallbackController.php — store: (1) persist raw body + headers + provider_code → PaymentCallbackModel status=received (in DB transaction); (2) dispatch PaymentCallbackProcessingJob; return 202 regardless of queue availability; HTTP 400 only if body is empty; RAW body must be captured before any JSON parsing
- [X] T129 [S10] Add callback route to wallet-ecosystem/services/api/routes/api.php — POST /v1/payment/callbacks/{provider_code}; NO auth middleware (called by external providers); apply rate limiting middleware 'throttle:payment-callback'
- [X] T130 [S10] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/Jobs/PaymentCallbackProcessingJob.php — implements ShouldQueue; (1) load PaymentCallbackModel; (2) resolve ProviderWebhookContract implementation for provider_code; (3) call parse(rawBody, signature, headers); (4) check idempotency: if idempotency_key already processed → mark callback duplicate, return; (5) check out-of-order: if attempt already terminal → mark callback duplicate, return; (6) call PaymentOrchestrationService.handleOutcome; (7) publish outbox event; (8) mark callback processed; MaxRetries=3; on failure → dead-letter queue; on dead-letter → create ReconciliationException type=missing_callback + emit alert
- [X] T131 [S10] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentCallbackService.php — resolveParser(providerCode): ProviderWebhookContract; maintains registry of parsers keyed by provider_code; throws ProviderException if no parser registered
- [X] T132 [S10] Create domain events wallet-ecosystem/services/api/app/Modules/Payment/Domain/Events/PaymentConfirmedEvent.php — implements IntegrationEventInterface; payload per contracts/payment-events.md
- [X] T133 [P] [S10] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Events/PaymentFailedEvent.php — implements IntegrationEventInterface
- [X] T134 [P] [S10] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Events/PaymentReversedEvent.php — implements IntegrationEventInterface
- [X] T135 [P] [S10] Create wallet-ecosystem/services/api/app/Modules/Payment/Domain/Events/RefundConfirmedEvent.php — implements IntegrationEventInterface
- [X] T136 [S10] Update PaymentOrchestrationService to publish events via OutboxEventPublisher — on handleOutcome(succeeded): publish PaymentConfirmedEvent; on handleOutcome(failed): publish PaymentFailedEvent; on reversal: publish PaymentReversedEvent; ensure publishing is within same DB transaction as state change
- [X] T137 [S10] Write feature tests wallet-ecosystem/services/api/tests/Feature/Modules/Payment/PaymentCallbackProcessingTest.php — test: raw body persisted before queue dispatch; valid HMAC signature accepted; invalid signature → callback marked signature_invalid, 401 not leaked; duplicate callback idempotency_key → marked duplicate, no state change; out-of-order (terminal attempt) → marked duplicate; payment.confirmed outbox event published exactly once; dead-letter after max retries creates reconciliation exception

**Checkpoint S10**: `PaymentCallbackProcessingTest` passes; `payment.confirmed` event payload matches contract; duplicate replay verified.

---

## Phase 11: Refunds and Reconciliation (Story S11)

**Goal**: Refund lifecycle with cumulative over-refund protection; reconciliation exception queue with admin investigation UI; immutable financial history.

**Independent Test**: Quickstart SC-06 — BWP 100 payment; BWP 60 refund succeeds; second BWP 60 refund fails with `RefundLimitExceededException`. Reconciliation exception created for simulated missing callback.

- [X] T138 [S11] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/RefundService.php — request(CreateRefundDTO): PaymentRefund; approve(refundId, approverActorId): void; initiate(refundId): void; confirm(refundId, providerRefundRef): void; fail(refundId, reason): void; in request(): atomically SELECT FOR UPDATE on payment_refunds + payment_attempts + sum confirmed refunds; validate cumulative amount ≤ original; validate provider has full_refund or partial_refund capability; emit refund.confirmed outbox event after confirm()
- [X] T139 [S11] Implement cumulative refund guard in RefundService::request — DB::transaction { lock PaymentAttemptModel FOR UPDATE; $confirmed = PaymentRefundRepository.sumConfirmedRefundsForAttempt(attemptId); if ($request->amount_minor + $confirmed > $attempt->amount_minor) throw RefundLimitExceededException; create PaymentRefund record }; this prevents concurrent over-refund
- [X] T140 [S11] Implement approval workflow in RefundService — if refund amount_minor >= ConfigurationService.get('payment.refund_approval_threshold_minor', 50000): set status=pending_approval; require second actor with payment.refunds.approve permission to call approve(); below threshold: auto-approve and transition directly to processing
- [X] T141 [S11] Create wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/PaymentRefundController.php — POST /v1/payment/intents/{intentId}/refunds; validates payment.refunds.request permission; validates intent is refundable; validates amount is integer; delegates to RefundService; NEVER auto-triggers financial reversal without provider confirmation for bank payments
- [X] T142 [S11] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/ReconciliationExceptionService.php — create(type, data): ReconciliationException; resolve(exceptionId, resolvedBy, reason, notes): void; escalate(exceptionId, actor): void; NO mark-as-paid: financial state changes only via verified provider evidence
- [X] T143 [S11] Write feature tests wallet-ecosystem/services/api/tests/Feature/Modules/Payment/RefundCumulativeProtectionTest.php — test: BWP 60 partial refund succeeds; second BWP 60 refund throws RefundLimitExceededException; concurrent refund requests race — exactly one succeeds (50 parallel); full refund after partial succeeds within remaining balance; refund on unsupported provider throws CapabilityUnsupportedException; refund above threshold requires approval
- [X] T171 [S11] Create wallet-ecosystem/services/api/tests/Feature/Modules/Payment/ReconciliationExceptionTest.php — test: scheduled PaymentIntentExpiredJob detects a succeeded PaymentAttempt whose payment_callback never arrived and creates ReconciliationException(type=missing_callback); test ReconciliationExceptionService.create() for all 7 ReconciliationExceptionType cases (missing_callback, unknown_outcome, amount_mismatch, orphan_provider_transaction, duplicate_transaction, failed_refund, unmatched_ledger); test admin investigation notes saved via ReconciliationExceptionAdminController; test resolution sets status=resolved with actor, timestamp, and reason recorded via AuditService; test mark-as-paid path does not exist (no HTTP route returns 200 for a financial state change without provider evidence); verifies SC-E48-010
- [X] T173 [P] [S11] Create wallet-ecosystem/services/api/tests/Unit/Modules/Payment/Services/RefundServiceTest.php — test: capability check rejects provider without full_refund or partial_refund in capability_flags; approval threshold gate applies for amounts >= ConfigurationService threshold; auto-approve for amounts below threshold; RefundService.request() does not call UPDATE or DELETE on any existing PaymentAttempt or PaymentIntent row (immutability); sumConfirmedRefundsForAttempt returns int (assert is_int()); RefundService status transitions match PaymentRefundStatus enum; state machine rejects invalid transition (e.g., succeeded → requested)

**Checkpoint S11**: `RefundCumulativeProtectionTest`, `ReconciliationExceptionTest`, and `RefundServiceTest` pass; reconciliation exception management verified; no mark-as-paid path exists; SC-E48-010 verified.

---

## Phase 12: Horizontal Module Integration (Story S12)

**Goal**: `PaymentModuleContract` wired as a Laravel binding; reference integration with a stub `StubPaymentModule` demonstrating multi-provider usage and event-driven fulfilment.

**Independent Test**: StubPaymentModule calls `PaymentModuleContract.createPaymentIntent()` → succeeds. `payment.confirmed` event fires → stub module records fulfilment idempotently. Same event delivered twice → exactly one fulfilment.

- [X] T144 [S12] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentApplicationService.php — implements PaymentModuleContract; createPaymentIntent: resolves BusinessPurposeResolver for purpose_type from service container; calls PaymentOrchestrationService; returns safe array shape per contract; getAvailableOptions: calls ProviderRegistryService; getPaymentStatus: validates actor owns intent, returns safe status shape
- [X] T145 [S12] Bind PaymentModuleContract in PaymentServiceProvider — bind(PaymentModuleContract::class, PaymentApplicationService::class)
- [X] T146 [S12] Create wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/BusinessPurposeResolverRegistry.php — resolverFor(purposeType): BusinessPurposeResolverInterface; resolvers registered by consuming modules via PaymentServiceProvider::registerPurposeResolver(purposeType, resolverClass); throws UnknownPurposeTypeException if not registered
- [X] T147 [S12] Create stub integration wallet-ecosystem/services/api/app/Modules/Payment/Tests/StubPurposeResolver.php — implements BusinessPurposeResolverInterface; supportedPurposeType() = 'stub_payment'; resolve() returns fixed PayableObligation(amount_minor=10000, currency='BWP', organisation_id=test_org, payer_actor_id=test_actor); only for test/local env
- [X] T148 [S12] Write feature tests wallet-ecosystem/services/api/tests/Feature/Modules/Payment/HorizontalModuleIntegrationTest.php — test: stub module creates intent via PaymentModuleContract; getAvailableOptions returns options list; payment.confirmed event consumed idempotently (delivered twice → one fulfilment); multiple providers work for same module; two different modules use same provider without cross-contamination; cross-organisation access denied (getPaymentStatus with wrong actor returns 403)

**Checkpoint S12**: `HorizontalModuleIntegrationTest` passes; PaymentModuleContract is the only interface stub module uses (assert no Payment domain imports in StubPurposeResolver test).

---

## Phase 13: Security and Operational Readiness (Story S13)

**Goal**: RBAC seeding, tenant isolation middleware, step-up enforcement, circuit breaker, log redaction, PHPStan level 8 clean across all Payment module files.

**Independent Test**: Quickstart SC-07 (cross-org access denied); SC-08 (production guard); SC-10 (PHPStan 0 errors); grep all log output for credential patterns → 0 matches.

- [X] T149 [S13] Verify all 9 payment.* permissions are seeded in migration T017; add any missing permission-to-role assignments in a new seeder wallet-ecosystem/services/api/database/seeders/PaymentPermissionRoleSeeder.php; assign payment.providers.read and payment.providers.manage to Platform Administrator role; assign payment.intents.read to Customer Support role
- [X] T150 [S13] Create wallet-ecosystem/services/api/app/Modules/Payment/Http/Middleware/PaymentOrganisationScopeMiddleware.php — validates that intent's organisation_id matches the authenticated actor's organisation; throws 403 if mismatch; applies to all consumer payment routes
- [X] T151 [S13] Implement circuit breaker in PaymentOrchestrationService — per-provider consecutive failure counter using Redis (via Laravel Cache); after ConfigurationService.get('payment.circuit_breaker_threshold', 5) consecutive failures: set provider circuit to open; dispatch PaymentCircuitOpenEvent; block new initiateAttempt for that provider with ProviderCircuitOpenException; circuit resets after ConfigurationService.get('payment.circuit_breaker_reset_minutes', 10) minutes
- [X] T152 [S13] Add rate limiting to payment routes in wallet-ecosystem/services/api/app/Modules/Payment/Providers/PaymentServiceProvider.php — register 'throttle:payment-intent' (30 per minute per actor), 'throttle:payment-callback' (100 per minute per IP) in RateLimiter::for definitions
- [X] T153 [S13] Audit log redaction — add PaymentLogRedactionMiddleware or verify existing Platform log redaction covers payment routes; grep test: run `php artisan test` with LOG_CHANNEL=stack and confirm no log entries contain strings matching /secret|credential|pin|otp|password|card.?number/i in payment module output
- [X] T154 [S13] Apply step-up check in WalletPaymentAdapter (T092) — verify step_up_token is validated using existing Identity module StepUpValidator; if token missing or expired: throw StepUpRequiredException with 'step_up_challenge_type' metadata; HTTP controller translates to 403 STEP_UP_REQUIRED with challenge info
- [ ] T155 [S13] Run PHPStan level 8 across all Payment module files — cd wallet-ecosystem/services/api && ./vendor/bin/phpstan analyse app/Modules/Payment --level=8; fix all errors until report shows [OK] No errors; commit clean report
- [X] T156 [S13] Write security test wallet-ecosystem/services/api/tests/Feature/Modules/Payment/PaymentSecurityTest.php — test: cross-org access denied (SC-07); credential not in API response (provider account response); payment.providers.read gate enforced; payment.providers.manage gate enforced; refund approval threshold gate enforced; rate limit returns 429; log redaction: no credential in log output

**Checkpoint S13**: `PaymentSecurityTest` passes; PHPStan level 8 = 0 errors; quickstart SC-07, SC-08, SC-10 all pass.

---

## Phase 14: Migration, Regression, and Release Documentation (Story S14)

**Goal**: Verified migration path, passing regression suite, OpenAPI spec, operational runbooks, and complete Definition of Done checklist.

**Independent Test**: `php artisan test --filter="Funding|Transfer|Withdrawal|Qr|Service"` — all existing tests pass without modification. `php artisan migrate` on fresh DB — success. `payment.yaml` validates.

- [ ] T157 [S14] Run existing regression suite and verify zero regressions: `php artisan test --filter="FundingTest|TransferTest|WithdrawalTest|QrTest|ServiceTest|WalletTest|LedgerTest" --stop-on-failure`; fix any breakage caused by new migrations or provider bindings (there should be none — additive only)
- [X] T158 [S14] Create wallet-ecosystem/services/api/openapi/v1/payment.yaml — document all endpoints per contracts/payment-api.md: GET /v1/payment/options, POST /v1/payment/intents, POST /v1/payment/intents/{id}/attempts, GET /v1/payment/intents/{id}/status, POST /v1/payment/callbacks/{provider_code}, POST /v1/payment/intents/{id}/refunds, admin endpoints; include request/response schemas, error envelopes, idempotency-key header documentation; validate with `swagger-cli validate`
- [X] T159 [P] [S14] Create docs/runbooks/payment-provider-suspension.md — step-by-step: when to suspend, how to call suspend API, effect on in-flight payments, how to drain queue, how to re-enable; expected outcomes at each step
- [X] T160 [P] [S14] Create docs/runbooks/payment-callback-dead-letter.md — step-by-step: how to find dead-lettered callbacks in DB, how to investigate, how to manually re-queue if safe, when to escalate; includes SQL queries for investigation
- [X] T161 [P] [S14] Create docs/runbooks/payment-reconciliation-exceptions.md — step-by-step: how to triage each exception type, which can be auto-resolved, which require manual bank contact, escalation path; SQL queries for each exception type
- [X] T162 [P] [S14] Create docs/runbooks/payment-secret-rotation.md — step-by-step: rotate secret reference in Vault/Secrets Manager, update callback_secret_reference in PaymentProviderAccount via admin API, verify HMAC verification still passes with new secret, rollback procedure
- [X] T163 [S14] Create migration checklist docs/migrations/E48-payment-module-checklist.md — steps: (1) apply migrations; (2) seed providers (OleKard Wallet disabled by default, MockBank dev-only); (3) seed permissions; (4) assign permissions to roles; (5) configure circuit breaker thresholds in ConfigurationService; (6) enable OleKard Wallet provider via admin UI after verification
- [X] T164 [S14] Verify WalletModuleContract backward compatibility — confirm no existing wallet tests fail; no WalletModuleContract method signatures changed; WalletPaymentAdapter uses createHold/releaseHold via approved interface only
- [ ] T165 [S14] Complete quickstart validation — run all 10 SC-01 through SC-10 scenarios from quickstart.md; document actual results (PASS/FAIL/DOCKER-GATED) in docs/qa/E48-quickstart-results.md; Docker-gated tests marked with expected results
- [X] T172 [P] [S14] Add wallet payment latency assertion — in wallet-ecosystem/services/api/tests/Feature/Modules/Payment/WalletPaymentConcurrencyTest.php add a single-actor latency test: time one complete wallet payment round-trip (availableBalance → createHold → LedgerModuleContract.postEntry → payment.confirmed outbox record inserted) using PHP microtime(); assert elapsed < 3.0 seconds; document measured p50 result in docs/qa/E48-definition-of-done.md; verifies SC-E48-004
- [X] T166 [S14] Final Definition of Done review — create docs/qa/E48-definition-of-done.md; verify and tick: PHPStan level 8 = 0 errors, unit tests pass, integration/feature tests pass, Flutter widget tests pass, OpenAPI payment.yaml validated, RBAC permissions seeded and assigned, audit events verified in integration test, circuit breaker tested, log redaction verified, no live bank adapter enabled, ADR-E48-001 and ADR-E48-002 status=Accepted, regression suite all green, runbooks present, wallet payment latency < 3s (T172), ReconciliationExceptionTest passes (T171)

**Checkpoint S14**: All regressions green; `payment.yaml` validates; all runbooks present; Definition of Done checklist fully ticked.

---

## Phase 15: Polish and Cross-Cutting Concerns

- [X] T167 [P] Add E48 to master roadmap — update specs/000-master-roadmap/spec.md to add E48 row in the Roadmap table with status Planned → In Progress
- [X] T168 [P] Update memory file — update .claude/projects/ memory index to reflect E48 IMPLEMENTATION READY status
- [x] T169 Run speckit-analyze — executed 2026-10-18; 12 findings (0 critical, 2 high, 5 medium, 5 low); H1+M3 remediated in spec.md SC-E48-008; H2 remediated by T171; M1 remediated by T172; M2 remediated in plan.md (DTOs removed, VOs canonical); M4 remediated by T173; L1–L5 low-priority, addressed in plan.md structure updates
- [X] T170 [P] Verify no Payment domain imports leak — grep -r "App\\Modules\\Payment\\Domain" --include="*.php" across all non-Payment modules; confirm zero results (consuming modules use only PaymentModuleContract)

---

## Dependencies and Execution Order

### Phase Dependencies

- **Phase 1 (S01)**: No dependencies — start immediately
- **Phase 2 (S02)**: Requires Phase 1 (ADRs accepted, module registered) — **BLOCKS all subsequent phases**
- **Phase 3 (S03)**: Requires Phase 2 — provider registry needs DB schema + domain contracts
- **Phase 4 (S04)** and **Phase 5 (S05)**: Both require Phase 3 — can run **in parallel**
- **Phase 6 (S06)** and **Phase 7 (S07)**: Both require Phase 5 — can run **in parallel**
- **Phase 8 (S08)**: Requires Phase 7
- **Phase 9 (S09)**: Requires Phase 6 + Phase 8
- **Phase 10 (S10)**: Requires Phase 5 (orchestration service for handleOutcome)
- **Phase 11 (S11)** and **Phase 12 (S12)**: Both require Phase 10 — can run **in parallel**
- **Phase 13 (S13)**: Cross-cuts S02–S12; final security validation requires all prior phases complete
- **Phase 14 (S14)**: Requires all prior phases complete
- **Phase 15**: Final polish after S14

### User Story Dependencies

| Story | Depends On | Can Parallelize With |
|-------|-----------|---------------------|
| S01 | — | — |
| S02 | S01 | — |
| S03 | S02 | — |
| S04 | S03 | S05 |
| S05 | S03 | S04 |
| S06 | S05 | S07 |
| S07 | S05 | S06 |
| S08 | S07 | — |
| S09 | S06 + S08 | S10 |
| S10 | S05 | S09 |
| S11 | S10 | S12 |
| S12 | S05 + S06 + S10 | S11 |
| S13 | S02–S05 (ongoing) | parallel validation |
| S14 | All | — |

### Within Each Phase

- All `[P]`-marked tasks in a phase can run in parallel (different files, no dependencies)
- Models before services in same phase
- Services before controllers in same phase
- Tests can be written alongside implementation (not test-first unless specifically requested)

---

## Parallel Execution Examples

### Phase 2 Parallel Group A (run simultaneously)
```
T022: PaymentIntentStatus enum
T023: PaymentAttemptStatus enum
T024: PaymentRefundStatus enum
T025: ProviderCapabilityEnum
T026: ReconciliationExceptionType enum
```

### Phase 2 Parallel Group B (run simultaneously, after T027-T033 VO)
```
T037: PaymentIntentRepositoryInterface
T038: PaymentAttemptRepositoryInterface
T039: PaymentRefundRepositoryInterface
T040: PaymentProviderRepositoryInterface
```

### Phase 2 Parallel Group C (run simultaneously, after T043)
```
T044: PaymentAttemptModel
T045: PaymentRefundModel
T046: PaymentProviderModel
T047: PaymentProviderAccountModel
T048: PaymentCallbackModel
T049: ReconciliationExceptionModel
T050: BankProviderDocumentationGapModel
```

### Phase 4 + Phase 5 (parallel teams)
```
Team A: T061–T077 (Admin UI, S04)
Team B: T078–T090 (Orchestration, S05)
```

### Phase 6 + Phase 7 (parallel teams)
```
Team A: T091–T098 (Wallet Adapter, S06)
Team B: T099–T113 (Bank Framework + Mock, S07+S08)
```

---

## Implementation Strategy

### MVP Scope (S01 + S02 + S03 + S05 + S06)

1. Phase 1 (S01): ADRs + module skeleton
2. Phase 2 (S02): Schema + contracts + enums + models
3. Phase 3 (S03): Provider registry
4. Phase 5 (S05): Orchestration + state machines
5. Phase 6 (S06): Wallet adapter
6. **STOP and VALIDATE**: OleKard Wallet payments work end-to-end; `payment.confirmed` event fires; regression suite green

This delivers the highest-value unblocked story (wallet as payment option for horizontal modules) without requiring any bank integration.

### Incremental Delivery

1. MVP → validate wallet payment works for any horizontal module
2. Add S04 Admin UI → providers configurable from admin
3. Add S07+S08 Mock Bank → bank checkout testable in dev
4. Add S09 Flutter checkout → mobile experience complete
5. Add S10 Callbacks → bank payments fully async
6. Add S11+S12 Refunds + Integration → operations-ready
7. Add S13+S14 Security + Release → production-eligible

### Notes

- `[P]` tasks have no file conflicts — safe to run concurrently
- Each story phase is independently testable — stop at any checkpoint to validate
- Bank 1/2/3 live integration remains BLOCKED until real bank documentation is received — this does NOT block any other story
- PHPStan level 8 is enforced at T155 and should be verified incrementally during development
- All amounts in task descriptions are examples in minor units (e.g., 10000 = BWP 100.00)
