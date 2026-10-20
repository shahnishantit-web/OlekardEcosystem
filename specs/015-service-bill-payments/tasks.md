# Tasks — E15 Service & Bill Payments

**Module**: `App\Modules\Service` | **Schema**: `service` | **Migrations**: `015000`–`015500`
**Branch**: `015-service-bill-payments`

## Format: `[ID] [P?] [Story?] Description — file path`

- **[P]**: Parallelizable (different files, no shared dependencies)
- **[Story]**: User story label (US0–US5); omitted in Setup/Foundational/Polish phases
- All paths relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup

**Purpose**: Module scaffold, route file, service provider skeleton.

- [X] T001 Create `app/Modules/Service/` directory tree matching plan.md module structure (Application/Services, Application/Jobs, Console, Domain/Contracts, Domain/Enums, Domain/Events, Domain/Exceptions, Infrastructure/Adapters, Infrastructure/Http/Controllers, Infrastructure/Http/Requests, Infrastructure/Http/Resources, Infrastructure/Migrations, Infrastructure/Models, Providers)
- [X] T002 Create `wallet-ecosystem/services/api/routes/modules/service.php` with empty route group stub (prefix `v1`, middleware `auth:sanctum`)
- [X] T003 Create `app/Modules/Service/Providers/ServiceServiceProvider.php` skeleton — register routes from `routes/modules/service.php`; defer adapter and console bindings to Phase 2

---

## Phase 2: Foundational

**Purpose**: Migrations, enums, exceptions, events, models, provider interface, mock adapter, service provider wiring. All user story phases depend on this phase completing first.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T004 Create migration `app/Modules/Service/Infrastructure/Migrations/2026_09_30_015000_create_service_catalog_table.php` — `service.services` table per data-model.md (id UUID v7, name, category, description, required_fields JSONB, fee_type, fee_value, min_amount, max_amount, currency_code, provider_code, status DEFAULT 'active', created_by, updated_by, timestamps; indexes on (status,category) and (provider_code))
- [X] T005 Create migration `app/Modules/Service/Infrastructure/Migrations/2026_09_30_015100_create_service_transactions_table.php` — `service.service_transactions` table per data-model.md (all 19 columns including `review_flag BOOLEAN DEFAULT false`; UNIQUE on idempotency_key; indexes on (customer_id,status,created_at DESC), (service_id,created_at DESC), (status,updated_at))
- [X] T006 [P] Create migration `app/Modules/Service/Infrastructure/Migrations/2026_09_30_015200_create_service_receipts_table.php` — `service.service_receipts` table per data-model.md (UNIQUE on transaction_id; UNIQUE on receipt_reference)
- [X] T007 [P] Create migration `app/Modules/Service/Infrastructure/Migrations/2026_09_30_015300_create_service_provider_callbacks_table.php` — `service.service_provider_callbacks` table per data-model.md (UNIQUE constraint on (provider_code, provider_reference))
- [X] T008 Create migration `app/Modules/Service/Infrastructure/Migrations/2026_09_30_015400_seed_service_permissions.php` — seed `service.manage`, `service.list`, `service.pay` permissions via E04 Permission model
- [X] T009 [P] Create migration `app/Modules/Service/Infrastructure/Migrations/2026_09_30_015500_seed_service_configuration_stubs.php` — seed 5 Platform Configuration stubs: `service.payment.validation_timeout_seconds=10`, `service.payment.provider_timeout_seconds=30`, `service.payment.requery_interval_seconds=300`, `service.payment.requery_max_attempts=12`, `service.payment.stale_initiated_timeout_minutes=30`
- [X] T010 [P] Create `app/Modules/Service/Domain/Enums/ServiceStatus.php` (active, inactive), `ServiceTransactionStatus.php` (initiated, pending_provider, completed, failed, timeout_pending_review, abandoned), `ServiceFeeType.php` (flat, percentage)
- [X] T011 [P] Create 9 domain exception classes in `app/Modules/Service/Domain/Exceptions/`: `ServiceNotFoundException`, `ServiceNotAvailableException`, `ServiceTransactionNotFoundException`, `InvalidCustomerReferenceException`, `ProviderValidationTimeoutException`, `InsufficientBalanceException`, `FeeChangedException`, `StepUpRequiredException`, `TransactionNotModifiableException` — plus `AmountOutOfRangeException` created as part of T034
- [X] T012 [P] Create catalog event classes in `app/Modules/Service/Domain/Events/`: `ServiceCreatedEvent`, `ServiceUpdatedEvent`, `ServiceDeactivatedEvent` — each carries service ID, actor ID, occurred_at, correlation_id
- [X] T013 [P] Create payment event classes in `app/Modules/Service/Domain/Events/`: `ServicePaymentInitiatedEvent`, `ServicePaymentCompletedEvent`, `ServicePaymentFailedEvent` — each carries transaction_id, customer_id, service_id, amount, fee_amount, currency_code, correlation_id, occurred_at
- [X] T014 Create `app/Modules/Service/Domain/Contracts/ServiceProviderAdapter.php` interface with 4 methods: `validateReference(array $params): array`, `submitPayment(array $params): array`, `queryStatus(array $params): array`, `verifyCallback(array $payload, string $signature, string $provider): array` (signatures per research.md §1)
- [X] T015 Create `app/Modules/Service/Infrastructure/Adapters/MockServiceProviderAdapter.php` skeleton implementing `ServiceProviderAdapter` — stub all 4 methods to throw `\RuntimeException('Not yet implemented')` (methods filled in later per story)
- [X] T016 Create `app/Modules/Service/Infrastructure/Models/ServiceModel.php` — Eloquent model for `service.services`; `$fillable`, `$casts` for `required_fields` (JSONB → array), `fee_type` (ServiceFeeType), `status` (ServiceStatus)
- [X] T017 [P] Create `app/Modules/Service/Infrastructure/Models/ServiceTransactionModel.php` — Eloquent model for `service.service_transactions`; `$casts` for `status` (ServiceTransactionStatus), `review_flag` (bool); relationships: `belongsTo ServiceModel`, `hasOne ServiceReceiptModel`, `hasMany ServiceProviderCallbackModel`
- [X] T018 [P] Create `app/Modules/Service/Infrastructure/Models/ServiceReceiptModel.php` — Eloquent model for `service.service_receipts`; immutable (`$guarded = ['id']`; no update allowed); `belongsTo ServiceTransactionModel`
- [X] T019 [P] Create `app/Modules/Service/Infrastructure/Models/ServiceProviderCallbackModel.php` — Eloquent model for `service.service_provider_callbacks`; `$casts` for `raw_payload` (JSONB → array), `processed` (bool)
- [X] T020 Update `app/Modules/Service/Providers/ServiceServiceProvider.php` — bind `ServiceProviderAdapter::class` to `MockServiceProviderAdapter::class` in app container; register `AbandonStaleServiceTransactionsCommand`; ensure migrations path is registered

**Checkpoint**: Migrate and verify all 4 tables exist, permissions seeded, config stubs seeded.

---

## Phase 3: US0 — Admin Service Catalog Management (Priority: P0) 🎯 MVP

**Goal**: Authorized admins can create, update, deactivate, and reactivate services in the catalog via API; all operations are audit-logged.

**Independent Test**: Admin creates an electricity service → service appears in active catalog → admin updates fee → admin deactivates → service excluded from customer discovery → admin reactivates → service reappears.

- [X] T021 [US0] Implement `app/Modules/Service/Application/Services/ServiceCatalogService.php` — `create(array $data, string $actorId): ServiceModel`, `update(string $id, array $data, string $actorId): ServiceModel`, `deactivate(string $id, string $actorId): void`, `reactivate(string $id, string $actorId): void`; each emits the corresponding domain event and writes an audit record; validates min_amount > 0, max_amount >= min_amount, fee_value >= 0
- [X] T022 [P] [US0] Create `app/Modules/Service/Infrastructure/Http/Requests/CreateServiceRequest.php` — validate: name (required, max 255), category (required, in enum values), required_fields (array of objects with name/label/validation_regex), fee_type (flat|percentage), fee_value (int >= 0), min_amount (int > 0), max_amount (int >= min_amount), currency_code (default BWP), provider_code (required)
- [X] T023 [P] [US0] Create `app/Modules/Service/Infrastructure/Http/Requests/UpdateServiceRequest.php` — same rules as CreateServiceRequest but all fields optional (PATCH semantics)
- [X] T024 [US0] Create `app/Modules/Service/Infrastructure/Http/Resources/ServiceResource.php` — expose: id, name, category, description, required_fields, fee_type, fee_value, min_amount, max_amount, currency_code, provider_code, status, created_at, updated_at
- [X] T025 [US0] Implement `app/Modules/Service/Infrastructure/Http/Controllers/ServiceCatalogController.php` — `index()` (paginated, all statuses), `store()`, `show()`, `update()`, `deactivate()`, `reactivate()`; all require `service.manage` permission; thin controller delegating to ServiceCatalogService
- [X] T026 [US0] Register admin catalog routes in `wallet-ecosystem/services/api/routes/modules/service.php`: `GET /v1/services/admin`, `POST /v1/services/admin`, `GET /v1/services/admin/{id}`, `PATCH /v1/services/admin/{id}`, `POST /v1/services/admin/{id}/deactivate`, `POST /v1/services/admin/{id}/reactivate` (all behind `service.manage` middleware)
- [X] T027 [US0] Write unit tests for `ServiceCatalogService` in `tests/Unit/Modules/Service/Application/ServiceCatalogServiceTest.php` — cover: create success, update fee, deactivate transitions to inactive, reactivate transitions to active, missing required field returns validation error, unauthorized actor throws `FORBIDDEN`

**Checkpoint**: `POST /v1/services/admin` creates a service; `GET /v1/services/admin` lists it; `POST /v1/services/admin/{id}/deactivate` sets status to inactive.

---

## Phase 4: US1 — Service Discovery (Priority: P1)

**Goal**: Authenticated customers can browse the active service catalog by category/search and view service details.

**Independent Test**: Customer lists services → filters by "UTILITIES" → searches "Botswana" → views service details including required fields, fee structure, and amount range. Unauthenticated request returns `UNAUTHORIZED`.

- [X] T028 [US1] Implement `app/Modules/Service/Application/Services/ServiceDiscoveryService.php` — `list(array $filters): LengthAwarePaginator` (filter by category, search name/description; `status = active` only); `show(string $id): ServiceModel` (active only; throws `ServiceNotFoundException` if inactive or missing)
- [X] T029 [US1] Implement `app/Modules/Service/Infrastructure/Http/Controllers/ServiceDiscoveryController.php` — `index()` (requires `service.list`), `show()` (requires `service.list`); delegates to ServiceDiscoveryService; returns ServiceResource
- [X] T030 [US1] Register customer discovery routes in `wallet-ecosystem/services/api/routes/modules/service.php`: `GET /v1/services` (category, search query params), `GET /v1/services/{id}` (both require `service.list` permission)
- [X] T031 [US1] Write unit tests for `ServiceDiscoveryService` in `tests/Unit/Modules/Service/Application/ServiceDiscoveryServiceTest.php` — cover: list returns only active, filter by category, search by name substring, show returns 404 for inactive service, unauthenticated request rejected

**Checkpoint**: `GET /v1/services?category=UTILITIES` returns only active utility services; inactive services excluded.

---

## Phase 5: US2 — Pre-Payment Validation (Priority: P1)

**Goal**: Customer validates their account reference with the provider (receives account name); customer gets a fee quote before committing to payment.

**Independent Test**: Customer submits valid 10-digit meter number → system calls MockServiceProviderAdapter::validateReference → returns `{valid: true, account_name: "Test Account"}`; fee calculation for BWP 100 flat-fee service returns `{amount: 10000, fee_amount: 200, total_amount: 10200}`.

- [X] T032 [US2] Implement `MockServiceProviderAdapter::validateReference` in `app/Modules/Service/Infrastructure/Adapters/MockServiceProviderAdapter.php` — return `{valid: true, account_name: 'Test Account'}` for any reference matching the service's `required_fields[0].validation_regex`; return `{valid: false, error_code: 'INVALID_CUSTOMER_REFERENCE'}` otherwise
- [X] T033 [US2] Implement `app/Modules/Service/Application/Services/ServiceValidationService.php` — `validateReference(string $serviceId, string $customerReference, string $correlationId): array`; resolve adapter via container; enforce `validation_timeout_seconds` from Platform Configuration; throw `ProviderValidationTimeoutException` on timeout; throw `ServiceNotAvailableException` if service is inactive
- [X] T034 [P] [US2] Implement `app/Modules/Service/Application/Services/ServiceFeeService.php` — `calculate(ServiceModel $service, int $amount): array` returns `{amount, fee_amount, total_amount, currency_code}`; flat: `fee_amount = service.fee_value`; percentage: `fee_amount = round(amount * service.fee_value / 10000)`; validate `amount >= service.min_amount && amount <= service.max_amount` (throws `AmountOutOfRangeException`); `assertFeeUnchanged(ServiceModel $service, int $quotedFeeAmount): void` throws `FeeChangedException` if fee has changed since quote
- [X] T035 [P] [US2] Create `app/Modules/Service/Infrastructure/Http/Requests/ValidateCustomerReferenceRequest.php` — require `customer_reference` (string, max 255)
- [X] T036 [P] [US2] Create `app/Modules/Service/Infrastructure/Http/Requests/CalculateServiceFeeRequest.php` — require `amount` (int, min 1)
- [X] T037 [US2] Implement `app/Modules/Service/Infrastructure/Http/Controllers/ServiceValidationController.php` — `validateReference(Request, string $serviceId)` delegates to ServiceValidationService; `calculateFee(Request, string $serviceId)` delegates to ServiceFeeService; both require `service.list` permission
- [X] T038 [US2] Register pre-payment routes in `wallet-ecosystem/services/api/routes/modules/service.php`: `POST /v1/services/{id}/validate-reference`, `POST /v1/services/{id}/calculate-fee`
- [X] T039 [US2] Write unit tests for `ServiceValidationService` in `tests/Unit/Modules/Service/Application/ServiceValidationServiceTest.php` — cover: valid reference returns account_name, invalid reference throws error, provider timeout returns `PROVIDER_VALIDATION_TIMEOUT`, inactive service throws `SERVICE_NOT_AVAILABLE`
- [X] T040 [US2] Write unit tests for `ServiceFeeService` in `tests/Unit/Modules/Service/Application/ServiceFeeServiceTest.php` — cover: flat fee calculation, percentage fee (basis points), amount below minimum throws error, amount above maximum throws error, `assertFeeUnchanged` passes when matching, throws `FeeChangedException` on mismatch

**Checkpoint**: `POST /v1/services/{id}/validate-reference {"customer_reference":"1234567890"}` returns `{valid: true, account_name: "Test Account"}`; `POST /v1/services/{id}/calculate-fee {"amount":10000}` returns `{fee_amount: 200, total_amount: 10200}`.

---

## Phase 6: US3 — Payment Initiation and Execution (Priority: P1)

**Goal**: Customer initiates a service payment; wallet is debited atomically with a balanced ledger journal; transaction persisted in `pending_provider` status; idempotency enforced; step-up enforced at threshold.

**Independent Test**: Customer with sufficient balance initiates BWP 100 payment with `Idempotency-Key`; `ServiceTransaction` created in `initiated`; wallet debited (principal + fee); balanced ledger journal posted; transaction transitions to `pending_provider`; re-submission with same key returns original response without second debit.

- [X] T041 [US3] Implement `service_payable:{currencyCode}` ledger account registration in `app/Modules/Service/Application/Services/ServicePaymentService.php` — call `LedgerModuleContract::ensureAccountExists("service_payable:{currency}", 'liability')` before first payment journal post (lazy, idempotent)
- [X] T042 [US3] Implement `MockServiceProviderAdapter::submitPayment` in `app/Modules/Service/Infrastructure/Adapters/MockServiceProviderAdapter.php` — return `{provider_reference: 'MOCK-'.strtoupper(uniqid()), status: 'success'}` synchronously
- [X] T043 [US3] Implement `app/Modules/Service/Application/Services/ServicePaymentService.php` — `initiate(array $data, string $customerId, string $correlationId): ServiceTransactionModel`; full flow: (1) resolve idempotency key → replay if exists; (2) verify service active; (3) recalculate fee via ServiceFeeService, throw `FeeChangedException` if changed; (4) check step-up threshold from Platform Configuration; (5) create `ServiceTransaction` in `initiated` status; (6) debit wallet via `WalletModuleContract` (SELECT FOR UPDATE on balance, throw `InsufficientBalanceException` on failure); (7) post double-entry ledger journal via `LedgerModuleContract`; (8) call `adapter->submitPayment()`; (9) transition to `pending_provider`; wrap steps 6–9 in DB transaction
- [X] T044 [P] [US3] Create `app/Modules/Service/Infrastructure/Http/Requests/InitiateServicePaymentRequest.php` — require: wallet_id (UUID), customer_reference (string), account_name (string, nullable), amount (int, min 1), currency_code (string); `Idempotency-Key` header required (validated in controller)
- [X] T045 [US3] Create `app/Modules/Service/Infrastructure/Http/Resources/ServiceTransactionResource.php` — expose: id, service_id, wallet_id, customer_reference, account_name, amount, fee_amount, total_amount, currency_code, status, journal_id, provider_code, provider_reference, failure_reason, review_flag, requery_count, correlation_id, created_at, updated_at
- [X] T046 [US3] Implement `app/Modules/Service/Infrastructure/Http/Controllers/ServicePaymentController.php` — `store()` (requires `service.pay`, enforces wallet ownership via `$request->user()->id === $data['customer_id']`, extracts `Idempotency-Key` header, delegates to ServicePaymentService); `show()` (wallet-owner check)
- [X] T047 [US3] Register payment routes in `wallet-ecosystem/services/api/routes/modules/service.php`: `POST /v1/services/{id}/payments` (`Idempotency-Key` required), `GET /v1/services/payments/{txId}`
- [X] T048 [US3] Write unit tests for `ServicePaymentService` in `tests/Unit/Modules/Service/Application/ServicePaymentServiceTest.php` — cover: success path (status transitions, balanced ledger, journal_id set), insufficient balance (initiated record created, no ledger entry), idempotency replay (same tx returned, no duplicate debit), service deactivated between validation and initiation, step-up required at threshold, step-up not required below threshold, wallet ownership enforcement
- [X] T049 [US3] Write concurrency test for concurrent wallet debit prevention in `tests/Integration/Modules/Service/ServicePaymentConcurrencyTest.php` — simulate two simultaneous requests with the same `Idempotency-Key` from the same wallet; assert exactly one `ServiceTransaction` row exists and exactly one ledger journal was posted (Constitution Principle 4; FR-E15-008)
- [X] T050 [US3] Write integration test for `initiated` record on insufficient balance in `tests/Integration/Modules/Service/ServicePaymentIntegrationTest.php` — assert `service_transactions` row exists with `status = 'initiated'` and no ledger journal for that correlation_id after an `INSUFFICIENT_BALANCE` error (verifies quickstart SC7 SQL check)

**Checkpoint**: `POST /v1/services/{id}/payments` with sufficient balance creates a transaction and ledger journal; re-submission returns same tx ID; `INSUFFICIENT_BALANCE` returns 402 with `initiated` record preserved.

---

## Phase 7: US4 — Provider Response Processing (Priority: P1)

**Goal**: Provider success/failure callbacks processed idempotently; balance restored on failure; timeout → automated re-query → admin force-outcome resolution.

**Independent Test**: A `pending_provider` transaction receives a success callback → transitions to `completed`, provider_reference stored, receipt triggered. A failure callback → compensating ledger entry posts, balance restored, `failed` status. Duplicate callback idempotent. Admin forces outcome on `timeout_pending_review` → audit logged.

- [X] T051 [US4] Implement `MockServiceProviderAdapter::verifyCallback` in `app/Modules/Service/Infrastructure/Adapters/MockServiceProviderAdapter.php` — always return `{valid: true, provider_reference: $payload['provider_reference'], status: $payload['status']}` (mock skips signature verification)
- [X] T052 [US4] Implement `MockServiceProviderAdapter::queryStatus` — return `{status: 'success'}` if `$params['provider_reference']` starts with `MOCK-`; otherwise `{status: 'pending'}`
- [X] T053 [US4] Implement `app/Modules/Service/Application/Services/ServiceOutcomeService.php` — `handleSuccess(string $txId, string $providerRef, string $correlationId): void` (transition `pending_provider`→`completed`, store `provider_reference`, trigger ServiceReceiptService::createReceipt, emit `ServicePaymentCompletedEvent`); `handleFailure(string $txId, string $reason, string $correlationId): void` (post compensating reversal journal, transition to `failed`, set `failure_reason`, emit `ServicePaymentFailedEvent`); `handleAmbiguous(string $txId, string $correlationId): void` (apply compensating entry, set `review_flag = true`, transition to `failed`); all operations wrapped in DB transaction; duplicate call on already-terminal status is a no-op
- [X] T054 [US4] Implement `app/Modules/Service/Application/Services/AdminResolutionService.php` — `resolve(string $txId, string $outcome, string $rationale, string $providerRef = null, string $actorId): void`; only operates on `timeout_pending_review` status (throws `TransactionNotModifiableException` otherwise); delegates to `ServiceOutcomeService::handleSuccess` or `handleFailure`; writes audit record with actor, rationale, outcome, timestamp
- [X] T055 [P] [US4] Create `app/Modules/Service/Infrastructure/Http/Requests/ServiceCallbackRequest.php` — validate: transaction_id (UUID), status (success|failure|unknown), provider_reference (string), reason (nullable string)
- [X] T056 [P] [US4] Create `app/Modules/Service/Infrastructure/Http/Requests/AdminForceOutcomeRequest.php` — validate: outcome (success|failure), rationale (required string, max 500), provider_reference (required on outcome=success)
- [X] T057 [US4] Implement `app/Modules/Service/Infrastructure/Http/Controllers/ServiceCallbackController.php` — `handle(Request, string $provider)`: (1) store raw payload to `service_provider_callbacks` (unique on provider_code+provider_reference; duplicate = 200 no-op); (2) call `adapter->verifyCallback()`; (3) resolve transaction; (4) dispatch `ServiceOutcomeService::handleSuccess/handleFailure/handleAmbiguous`; (5) mark callback `processed = true`; return 200 always (provider must never retry on our error)
- [X] T058 [US4] Implement `app/Modules/Service/Infrastructure/Http/Controllers/AdminServiceResolutionController.php` — `resolve(Request, string $txId)`: require `service.manage` permission; delegate to AdminResolutionService; return updated transaction resource
- [X] T059 [US4] Register callback and resolve routes in `wallet-ecosystem/services/api/routes/modules/service.php`: `POST /v1/services/callback/{provider}` (no standard auth middleware — provider secret auth via `verifyCallback`), `POST /v1/services/admin/transactions/{txId}/resolve` (`service.manage`)
- [X] T060 [US4] Implement `app/Modules/Service/Application/Jobs/ReQueryTimeoutTransactionJob.php` — query all `timeout_pending_review` transactions where `requery_count < config('service.payment.requery_max_attempts')`; call `adapter->queryStatus()` for each; on `success` dispatch `handleSuccess`; on `failure` dispatch `handleFailure`; increment `requery_count`; schedule every `requery_interval_seconds` via Laravel Scheduler
- [X] T061 [US4] Implement `app/Modules/Service/Application/Jobs/AbandonStaleTransactionsJob.php` — query all `initiated` transactions older than `stale_initiated_timeout_minutes`; transition each to `abandoned` (no wallet effect); schedule every 15 minutes via Laravel Scheduler
- [X] T062 [US4] Create `app/Modules/Service/Console/AbandonStaleServiceTransactionsCommand.php` — artisan command `service:abandon-stale-transactions`; dispatches `AbandonStaleTransactionsJob`; register in `ServiceServiceProvider`; wire both jobs to Laravel Scheduler in `app/Console/Kernel.php`
- [X] T063 [US4] Write unit tests for `ServiceOutcomeService` in `tests/Unit/Modules/Service/Application/ServiceOutcomeServiceTest.php` — cover: success path (transaction completed, receipt triggered, event emitted), failure path (compensating entry posted, balance restored, event emitted), ambiguous path (review_flag set, compensating entry), duplicate success callback idempotent (no second journal), unknown transaction reference throws error
- [X] T064 [US4] Write unit tests for `AdminResolutionService` in `tests/Unit/Modules/Service/Application/AdminResolutionServiceTest.php` — cover: force success on timeout_pending_review (audit logged, receipt generated), force failure on timeout_pending_review (compensating entry, audit logged), non-resolvable status throws `TransactionNotModifiableException`
- [X] T065 [US4] Write integration test for provider failure callback in `tests/Integration/Modules/Service/ServiceOutcomeIntegrationTest.php` — assert balance fully restored and compensating journal is balanced after failure callback (quickstart QS-E15-003)

**Checkpoint**: Success callback completes transaction and triggers receipt; failure callback reverses debit and restores balance; duplicate callback is idempotent; `timeout_pending_review` transitions correctly on re-query.

---

## Phase 8: US5 — Receipt Generation (Priority: P1)

**Goal**: Every completed payment has a retrievable, immutable receipt with a globally unique reference. Re-fetching returns the same receipt without regeneration.

**Independent Test**: Completed `ServiceTransaction` has a retrievable receipt via `GET /v1/services/payments/{txId}/receipt`; all required fields present; second fetch returns same `receipt_reference`; non-completed status returns `RECEIPT_NOT_AVAILABLE`.

- [X] T066 [US5] Implement `app/Modules/Service/Application/Services/ServiceReceiptService.php` — `createReceipt(ServiceTransactionModel $tx): ServiceReceiptModel` (called by `ServiceOutcomeService::handleSuccess`; idempotent — returns existing receipt if already created; generates UUID v7 as `receipt_reference`; snapshots service_name, customer_reference, provider_confirmation_reference, amounts, currency, issued_at); `getReceipt(string $txId, string $requestorId): ServiceReceiptModel` (throws `RECEIPT_NOT_AVAILABLE` if tx not completed; throws `FORBIDDEN` if requestorId != tx.customer_id)
- [X] T067 [US5] Create `app/Modules/Service/Infrastructure/Http/Resources/ServiceReceiptResource.php` — expose: id, receipt_reference, transaction_id, service_name, customer_reference, provider_confirmation_reference, amount, fee_amount, total_amount, currency_code, issued_at, created_at
- [X] T068 [US5] Implement `app/Modules/Service/Infrastructure/Http/Controllers/ServiceReceiptController.php` — `show(Request, string $txId)`: require `service.list` permission; delegate to ServiceReceiptService::getReceipt; return ServiceReceiptResource
- [X] T069 [US5] Register receipt route in `wallet-ecosystem/services/api/routes/modules/service.php`: `GET /v1/services/payments/{txId}/receipt` (requires `service.list`)
- [X] T070 [US5] Wire `ServiceReceiptService::createReceipt` into `ServiceOutcomeService::handleSuccess` in `app/Modules/Service/Application/Services/ServiceOutcomeService.php` (call after transaction transition to `completed`, within the same DB transaction)
- [X] T071 [US5] Write unit tests for `ServiceReceiptService` in `tests/Unit/Modules/Service/Application/ServiceReceiptServiceTest.php` — cover: createReceipt generates unique receipt_reference, idempotent re-call returns same receipt, getReceipt succeeds for completed tx, returns `RECEIPT_NOT_AVAILABLE` for pending_provider/failed tx, returns `FORBIDDEN` for different customer_id

**Checkpoint**: `GET /v1/services/payments/{txId}/receipt` returns full receipt for completed tx; second call returns identical data; `RECEIPT_NOT_AVAILABLE` for non-completed.

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: Event outbox wiring, E11 integration, observability, OpenAPI verification, performance tests, static analysis, full quickstart validation.

- [X] T072 Wire all domain events through the reliable outbox in `ServicePaymentService` and `ServiceOutcomeService` — use `EventPublisher::publishViaOutbox()` (or platform equivalent) for `ServicePaymentInitiatedEvent`, `ServicePaymentCompletedEvent`, `ServicePaymentFailedEvent` (FR-E15-010)
- [X] T073 [P] Create E11 Transaction Management event listeners in `app/Modules/Transaction/Application/EventHandlers/ServiceTransactionEventHandler.php` — `handleInitiated`, `handleCompleted`, `handleFailed`; each creates a unified transaction history entry via `TransactionProjectionService`; registered in `TransactionServiceProvider`; no direct writes to `transaction.*` tables (research.md §8)
- [X] T074 [P] Add structured logging and metrics emission to `ServicePaymentService` and `ServiceOutcomeService` — log at initiation and completion with `correlationId`, `transactionId`, `customerId`, `serviceId`; emit counters: `service.payment.initiated`, `service.payment.completed`, `service.payment.failed`, `service.validation.timeout`, `service.payment.timeout_pending_review` (plan.md observability section)
- [X] T075 [P] Audit all 15 API routes against `specs/015-service-bill-payments/contracts/api.yaml` — schema mismatch fixed: `required_fields` JSONB replaces non-existent `reference_label`/`reference_hint` across ServiceModel, ServiceCatalogService, all form requests, ServiceResource, and all test fixtures
- [X] T076 [P] Write performance fixture: seed 500 active services and time `GET /v1/services` per SC-E15-001 in `tests/Performance/ServiceCatalogPerformanceTest.php` — assert p95 < 2000 ms
- [X] T077 [P] Write performance tests for reference validation (SC-E15-002, target < 5 s) and payment initiation (SC-E15-003, target < 3 s) in `tests/Performance/ServicePaymentPerformanceTest.php`
- [X] T078 [P] Write receipt availability performance test (SC-E15-007, target < 1 s) in `tests/Performance/ServiceReceiptPerformanceTest.php`; add singleton bindings for all application services in `ServiceServiceProvider`
- [X] T079 Run PHPStan static analysis on `app/Modules/Service/` (`vendor/bin/phpstan analyse app/Modules/Service --level=8`) and resolve all errors — clean pass; fixes: `ServiceModel::where()->first()` replacing ambiguous `::find()`, removed unused `$feeService` from `ServiceValidationController`, removed unused `$total` from closure capture, replaced `->fresh()` with `->refresh()` + return
- [X] T080 Run full quickstart.md validation against Docker stack: execute SC1–SC9 manually, verify QS-E15-001 through QS-E15-004 SQL queries, run SC-E15-001 through SC-E15-007 performance checks; document pass/fail evidence — 47 unit+integration tests pass (2026-10-06); bugs fixed: missing total_amount/provider_code in INSERT, completed_at column removal, ServiceReceiptService column mismatch, StepUpRequiredException in config mock, HttpException code vs statusCode in receipt test
- [X] T081 Create `app/Modules/Service/Application/Jobs/TransitionTimedOutPaymentsJob.php` — queries `pending_provider` transactions where `updated_at < now() - provider_timeout_seconds` and transitions them to `timeout_pending_review`; wire to scheduler in `ServiceServiceProvider` every 2 minutes (before `ReQueryTimeoutTransactionJob`); closes FR-E15-013 gap (I1 remediation)
- [X] T082 Fix `ServicePaymentService::initiate()` — move `$this->audit->record()` and `$this->events->publish(ServicePaymentInitiatedEvent)` inside the inner `DB::connection('platform')->transaction()` closure; `OutboxEventPublisher` throws `LogicException` if called outside an active DB transaction; audit and outbox record are now atomic with ledger post and status transition (A1/secondary-bug remediation)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 — BLOCKS all story phases
- **Phase 3 (US0)**: Depends on Phase 2 — blocks Phases 4–8 (no services = no discovery)
- **Phase 4 (US1)**: Depends on Phase 3 (needs at least one seeded service)
- **Phase 5 (US2)**: Depends on Phase 4 (validation requires a discoverable service)
- **Phase 6 (US3)**: Depends on Phase 5 (payment requires validated reference + fee quote)
- **Phase 7 (US4)**: Depends on Phase 6 (outcome processing requires `pending_provider` transactions)
- **Phase 8 (US5)**: Depends on Phase 7 (receipts generated by outcome success handler)
- **Phase 9 (Polish)**: Depends on Phases 3–8 complete

### Within Each Phase

- Tasks marked `[P]` can run in parallel (different files, no shared dependencies)
- Non-`[P]` tasks in a phase run sequentially in task ID order
- All migration tasks (T004–T009) must complete before Eloquent models are usable

---

## Parallel Opportunities

```
Phase 2 — run together:
  T006, T007 (migrations)          T009 (config migration)
  T010 (enums)                     T011 (exceptions)
  T012, T013 (events)              T016, T017, T018, T019 (models)

Phase 3 (US0):
  T022, T023 (form requests)

Phase 5 (US2):
  T034 (ServiceFeeService)         T035, T036 (form requests)

Phase 6 (US3):
  T044 (InitiateServicePaymentRequest)

Phase 7 (US4):
  T055, T056 (form requests)       T051, T052 (mock adapter methods)

Phase 9 (Polish):
  T073, T074, T075, T076, T077, T078 (all cross-cutting)
```

---

## Implementation Strategy

### MVP Scope (US0 only — minimum shippable increment)

1. Complete Phase 1 + Phase 2
2. Complete Phase 3 (US0 — admin catalog management)
3. Validate: admin can create/update/deactivate services via API; catalog persists correctly
4. **STOP and demo**: backend service catalog API is live

### Full Feature Delivery (sequential by dependency)

1. Phase 1 + 2 → Foundation ready
2. Phase 3 (US0) → Admin catalog live
3. Phase 4 (US1) → Customer discovery live
4. Phase 5 (US2) → Pre-payment validation live
5. Phase 6 (US3) → Payment initiation live (money moves!)
6. Phase 7 (US4) → Outcome processing live (loop closed)
7. Phase 8 (US5) → Receipts live
8. Phase 9 → Polish, perf tests, quickstart validation, release gate

---

## Notes

- All financial state changes (debit, reversal) use `DB::transaction()` wrapping ledger post + status update
- `review_flag` on `ServiceTransactionModel` must be set to `true` in `ServiceOutcomeService::handleAmbiguous`
- `MockServiceProviderAdapter` is dev/test only; real adapter bindings are TBD per Constitution Principle 2
- Concurrency test T049 is **mandatory** per Constitution Principle 4 — do not skip
- Performance tests T076–T078 prove SC-E15-001, SC-E15-002, SC-E15-003, SC-E15-007
- All events must flow through the outbox (T072) before Phase 9 is considered complete
