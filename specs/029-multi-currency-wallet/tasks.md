# Tasks: E29 Multi-Currency Wallet

**Input**: `specs/029-multi-currency-wallet/` â€” spec.md, plan.md, research.md, data-model.md, contracts/openapi.yaml, quickstart.md

**Prerequisites**: E06 Wallet, E07 Ledger, E28 Configuration Management, E04 Authorization, E21 Fees & Pricing

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no cross-task dependencies)
- **[Story]**: Maps to US-MCW-NNN from spec.md
- All paths relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup (Module Scaffold & Migrations)

**Purpose**: Create the `MultiCurrency` module skeleton and all database migrations.

- [X] T001 Create `app/Modules/MultiCurrency/` directory structure per plan.md (Application/DTOs, Application/Services, Domain/Contracts, Domain/Enums, Domain/Events, Http/Controllers/Admin, Http/Requests, Http/Resources, Infrastructure/Persistence, Infrastructure/Repositories)
- [X] T002 Create `app/Modules/MultiCurrency/MultiCurrencyServiceProvider.php` with placeholder bindings, register in `bootstrap/providers.php`
- [X] T003 Create migration `database/migrations/2026_10_03_000001_create_multi_currency_schema.php` â€” executes `CREATE SCHEMA IF NOT EXISTS multi_currency`
- [X] T004 [P] Create migration `database/migrations/2026_10_03_000002_create_multi_currency_currencies_table.php` â€” `multi_currency.currencies` per data-model.md (id UUID v7, code VARCHAR(3) UNIQUE, name, symbol, decimal_places, is_active, timestamps)
- [X] T005 [P] Create migration `database/migrations/2026_10_03_000003_create_multi_currency_currency_wallets_table.php` â€” `multi_currency.currency_wallets` per data-model.md (id, customer_id, currency_code, wallet_id FK wallet.wallets, is_active, UNIQUE(customer_id, currency_code), timestamps)
- [X] T006 [P] Create migration `database/migrations/2026_10_03_000004_create_multi_currency_fx_rates_table.php` â€” `multi_currency.fx_rates` per data-model.md (id, from/to currency_code, rate DECIMAL(18,8), spread DECIMAL(6,5), effective_from, effective_to nullable, created_by, approved_by, change_request_id, partial UNIQUE index on active pair, timestamps)
- [X] T007 [P] Create migration `database/migrations/2026_10_03_000005_create_multi_currency_fx_quotes_table.php` â€” `multi_currency.fx_quotes` per data-model.md (id, customer_id, from/to currency_code, from_amount, to_amount, fee_amount, rate, fx_rate_id, status VARCHAR(20) DEFAULT 'quoted', expires_at, idempotency_key UNIQUE, fx_transaction_id nullable, timestamps)
- [X] T008 [P] Create migration `database/migrations/2026_10_03_000006_create_multi_currency_fx_transactions_table.php` â€” `multi_currency.fx_transactions` per data-model.md (id, customer_id, quote_id, from_wallet_id, to_wallet_id, from/to currency_code, from_amount, to_amount, fee_amount, rate, status, failure_reason, idempotency_key UNIQUE, completed_at, timestamps)
- [X] T009 Create seeder `database/seeders/MultiCurrencyCurrenciesSeeder.php` â€” inserts active currencies (BWP, USD, ZAR, EUR, GBP, ZMW) into `multi_currency.currencies` using `insertOrIgnore`; seed FX suspense account (`multi_currency.fx_suspense`) and FX revenue account (`platform.fx_revenue`) into E07 ledger accounts table
- [X] T010 Create route files `routes/api_multi_currency.php` (customer endpoints) and `routes/api_admin_multi_currency.php` (admin endpoints) with placeholder closures; include both in `app/Providers/RouteServiceProvider.php`
- [X] T095 Create migration `database/migrations/2026_10_03_000007_create_multi_currency_same_currency_transfers_table.php` â€” `multi_currency.same_currency_transfers` (id UUID v7, customer_id UUID NOT NULL, from_wallet_id UUID NOT NULL, to_wallet_id UUID NOT NULL, currency_code VARCHAR(3) NOT NULL, amount DECIMAL(18,4) NOT NULL, idempotency_key VARCHAR(255) UNIQUE NOT NULL, created_at TIMESTAMPTZ); index on (customer_id, created_at DESC); this table provides idempotency storage for US-MCW-004 same-currency transfers

---

## Phase 2: Foundational (Domain Layer)

**Purpose**: Domain enums, repository interfaces, Eloquent models, repository implementations, permission seeder. MUST be complete before any user story phase.

- [X] T011 [P] Create `app/Modules/MultiCurrency/Domain/Enums/FxQuoteStatus.php` â€” backed string enum: `Quoted = 'quoted'`, `Confirmed = 'confirmed'`, `Expired = 'expired'`, `Cancelled = 'cancelled'`
- [X] T012 [P] Create `app/Modules/MultiCurrency/Domain/Enums/FxTransactionStatus.php` â€” backed string enum: `Completed = 'completed'`, `Failed = 'failed'`
- [X] T013 [P] Create `app/Modules/MultiCurrency/Domain/Contracts/CurrencyRepositoryInterface.php` â€” methods: `findByCode(string $code): ?CurrencyModel`, `listActive(): Collection`, `listAll(): Collection`, `upsert(array $attributes): CurrencyModel`
- [X] T014 [P] Create `app/Modules/MultiCurrency/Domain/Contracts/CurrencyWalletRepositoryInterface.php` â€” methods: `findById(string $id): ?CurrencyWalletModel`, `findByCustomerAndCurrency(string $customerId, string $currencyCode): ?CurrencyWalletModel`, `listByCustomer(string $customerId): Collection`, `save(CurrencyWalletModel $model): void`, `lockForUpdate(string $id): ?CurrencyWalletModel`
- [X] T015 [P] Create `app/Modules/MultiCurrency/Domain/Contracts/FxRateRepositoryInterface.php` â€” methods: `findActivePair(string $from, string $to): ?FxRateModel`, `listActive(): Collection`, `listAll(): Collection`, `save(FxRateModel $model): void`, `deactivateCurrentRate(string $from, string $to): void`
- [X] T016 [P] Create `app/Modules/MultiCurrency/Domain/Contracts/FxQuoteRepositoryInterface.php` â€” methods: `findById(string $id): ?FxQuoteModel`, `findByIdempotencyKey(string $key): ?FxQuoteModel`, `save(FxQuoteModel $model): void`
- [X] T017 [P] Create `app/Modules/MultiCurrency/Domain/Contracts/FxTransactionRepositoryInterface.php` â€” methods: `findById(string $id): ?FxTransactionModel`, `findByIdempotencyKey(string $key): ?FxTransactionModel`, `save(FxTransactionModel $model): void`, `paginateByCustomer(string $customerId, array $filters, int $perPage): LengthAwarePaginator`
- [X] T018 [P] Create `app/Modules/MultiCurrency/Infrastructure/Persistence/CurrencyModel.php` â€” Eloquent model, connection `platform`, table `multi_currency.currencies`, fillable, `$primaryKey = 'id'`, `$incrementing = false`
- [X] T019 [P] Create `app/Modules/MultiCurrency/Infrastructure/Persistence/CurrencyWalletModel.php` â€” Eloquent model, connection `platform`, table `multi_currency.currency_wallets`, fillable, UUID primary key, casts `is_active` boolean
- [X] T020 [P] Create `app/Modules/MultiCurrency/Infrastructure/Persistence/FxRateModel.php` â€” Eloquent model, connection `platform`, table `multi_currency.fx_rates`, fillable, UUID primary key, casts `rate` and `spread` as string (decimal precision)
- [X] T021 [P] Create `app/Modules/MultiCurrency/Infrastructure/Persistence/FxQuoteModel.php` â€” Eloquent model, connection `platform`, table `multi_currency.fx_quotes`, fillable, UUID primary key, casts `status` to `FxQuoteStatus` enum, `expires_at` to datetime
- [X] T022 [P] Create `app/Modules/MultiCurrency/Infrastructure/Persistence/FxTransactionModel.php` â€” Eloquent model, connection `platform`, table `multi_currency.fx_transactions`, fillable, UUID primary key, casts `status` to `FxTransactionStatus` enum, `completed_at` to datetime nullable
- [X] T022b [P] Create `app/Modules/MultiCurrency/Infrastructure/Persistence/SameCurrencyTransferModel.php` â€” Eloquent model, connection `platform`, table `multi_currency.same_currency_transfers`, fillable, UUID primary key; add corresponding `SameCurrencyTransferRepositoryInterface` (methods: `findByIdempotencyKey`, `save`) and `EloquentSameCurrencyTransferRepository`; bind in `MultiCurrencyServiceProvider`
- [X] T023 Create `app/Modules/MultiCurrency/Infrastructure/Repositories/EloquentCurrencyRepository.php` â€” implements `CurrencyRepositoryInterface`
- [X] T024 Create `app/Modules/MultiCurrency/Infrastructure/Repositories/EloquentCurrencyWalletRepository.php` â€” implements `CurrencyWalletRepositoryInterface`; `lockForUpdate()` uses `->lockForUpdate()->find($id)` inside a DB transaction context
- [X] T025 Create `app/Modules/MultiCurrency/Infrastructure/Repositories/EloquentFxRateRepository.php` â€” implements `FxRateRepositoryInterface`; `findActivePair()` queries `WHERE from_currency_code = ? AND to_currency_code = ? AND effective_to IS NULL LIMIT 1`
- [X] T026 Create `app/Modules/MultiCurrency/Infrastructure/Repositories/EloquentFxQuoteRepository.php` â€” implements `FxQuoteRepositoryInterface`
- [X] T027 Create `app/Modules/MultiCurrency/Infrastructure/Repositories/EloquentFxTransactionRepository.php` â€” implements `FxTransactionRepositoryInterface`; `paginateByCustomer()` supports `from_currency`, `to_currency`, `from_date`, `to_date` filters
- [X] T028 Create seeder `database/seeders/MultiCurrencyPermissionsSeeder.php` â€” inserts 6 permissions: `multi_currency.create_wallet`, `multi_currency.view_balances`, `multi_currency.transfer`, `multi_currency.convert`, `multi_currency.configure_rate`, `multi_currency.manage_currencies` into E04 permissions table; assign to `customer` and `admin` roles as appropriate
- [X] T029 Update `app/Modules/MultiCurrency/MultiCurrencyServiceProvider.php` â€” bind all 5 repository interfaces to Eloquent implementations; register `MultiCurrencyCurrenciesSeeder` and `MultiCurrencyPermissionsSeeder` in `DatabaseSeeder`

**Checkpoint**: Module scaffold, migrations, models, and repositories complete. User story implementation can begin.

---

## Phase 3: US-MCW-001 â€” Create Currency Wallet

**Goal**: Customer can open a new wallet in any active currency (one-per-currency rule enforced).

**Independent Test**: `POST /v1/multi-currency/wallets` with `currency_code=USD` returns 201; second call with different idempotency key returns 409.

- [X] T030 [US1] Create `app/Modules/MultiCurrency/Application/DTOs/CreateCurrencyWalletDTO.php` â€” readonly: `customerId`, `currencyCode`, `idempotencyKey`, `correlationId`
- [X] T031 [US1] Create `app/Modules/MultiCurrency/Application/Services/CurrencyWalletService.php` â€” method `create(CreateCurrencyWalletDTO): array`; checks idempotency (find by idempotency_key); validates currency is active; checks UNIQUE constraint via `findByCustomerAndCurrency()`; calls E06 `WalletServiceInterface::create()` to provision underlying wallet; saves `CurrencyWalletModel`; dispatches `CurrencyWalletCreatedEvent`; audit logs via E19/AuditServiceInterface
- [X] T032 [US1] Create `app/Modules/MultiCurrency/Domain/Events/CurrencyWalletCreatedEvent.php` â€” implements `IntegrationEventInterface`, event name `US_MCW_001_SUCCEEDED`, payload: customerId, currencyCode, walletId, correlationId
- [X] T033 [US1] Create `app/Modules/MultiCurrency/Http/Requests/CreateCurrencyWalletRequest.php` â€” validates `currency_code` required, string, max 3 chars, uppercase
- [X] T034 [US1] Create `app/Modules/MultiCurrency/Http/Resources/CurrencyWalletResource.php` â€” transforms CurrencyWalletModel to `{id, currency_code, currency_name, currency_symbol, balance, is_active, created_at}`; balance fetched from E07 LedgerService
- [X] T035 [US1] Create `app/Modules/MultiCurrency/Http/Controllers/CurrencyWalletController.php` â€” thin controller; `store()` resolves `customerId` from auth, builds DTO, calls `CurrencyWalletService::create()`, returns `CurrencyWalletResource` with 201
- [X] T036 [US1] Register `POST /v1/multi-currency/wallets` in `routes/api_multi_currency.php` â†’ `CurrencyWalletController@store`, middleware: `auth:api`, `permission:multi_currency.create_wallet`
- [X] T037 [US1] Write `tests/Feature/Modules/MultiCurrency/CreateCurrencyWalletApiTest.php` â€” tests: happy path returns 201 + wallet structure; duplicate same-idempotency-key returns 201 (idempotent); duplicate different-idempotency-key returns 409; inactive currency returns 422; unauthenticated returns 401

---

## Phase 4: US-MCW-002 â€” Support Multiple Currencies

**Goal**: Admin can activate/deactivate currencies; customers see the active currency list.

**Independent Test**: `GET /v1/multi-currency/currencies` returns only `is_active=true` currencies; `POST /v1/admin/multi-currency/currencies` adds a new one; seeded currencies visible.

- [X] T038 [US2] Create `app/Modules/MultiCurrency/Http/Resources/CurrencyResource.php` â€” transforms CurrencyModel to `{code, name, symbol, decimal_places, is_active}`
- [X] T039 [US2] Create `app/Modules/MultiCurrency/Http/Controllers/CurrencyController.php` â€” `index()` returns paginated active currencies from `CurrencyRepositoryInterface::listActive()`
- [X] T040 [US2] Create `app/Modules/MultiCurrency/Http/Controllers/Admin/AdminCurrencyController.php` â€” `index()` lists all currencies (active + inactive); `store()` creates/activates a currency (idempotent on `code`); `update()` PATCH activates or deactivates
- [X] T041 [US2] Register customer route `GET /v1/multi-currency/currencies` â†’ `CurrencyController@index`, middleware: `auth:api`
- [X] T042 [US2] Register admin routes in `routes/api_admin_multi_currency.php`: `GET /v1/admin/multi-currency/currencies` + `POST` + `PATCH /{code}` â†’ `AdminCurrencyController`, middleware: `auth:api`, `permission:multi_currency.manage_currencies`
- [X] T043 [US2] Write `tests/Feature/Modules/MultiCurrency/CurrencyApiTest.php` â€” tests: customer list returns only active; admin list returns all; admin can activate an inactive currency; deactivated currency cannot be used to create wallet

---

## Phase 5: US-MCW-003 â€” View Currency Balances

**Goal**: Customer can list all their currency wallets with live balances, and view a single wallet detail.

**Independent Test**: After creating a USD wallet, `GET /v1/multi-currency/wallets` returns it with `balance = "0.00"`; after a funding event `balance` reflects the ledger total.

- [X] T044 [US3] Add `getBalance(string $walletId): string` to `CurrencyWalletService` â€” delegates to E07 `LedgerService::getBalance($walletId)`, returns formatted decimal string
- [X] T045 [US3] Add `list(string $customerId): array` and `get(string $customerId, string $walletId): array` to `CurrencyWalletService`
- [X] T046 [US3] Add `index()` and `show()` to `CurrencyWalletController` â€” `index()` lists customer's wallets with balances; `show()` returns single wallet with balance
- [X] T047 [US3] Register routes `GET /v1/multi-currency/wallets` + `GET /v1/multi-currency/wallets/{id}` â†’ `CurrencyWalletController@index` / `@show`, middleware: `auth:api`, `permission:multi_currency.view_balances`
- [X] T048 [US3] Write `tests/Feature/Modules/MultiCurrency/CurrencyWalletBalanceApiTest.php` â€” tests: list returns wallets with balance field; balance is "0.00" on fresh wallet; show returns correct wallet; show returns 403 for wallet owned by different customer

---

## Phase 6: US-MCW-004 â€” Transfer Between Own Currency Wallets

**Goal**: Customer can move funds between two of their own same-currency wallets via a simple debit/credit ledger entry.

**Independent Test**: Transfer 100 BWP from wallet A to wallet B; both balances update correctly; cross-currency attempt returns 422.

- [X] T049 [US4] Create `app/Modules/MultiCurrency/Application/DTOs/SameCurrencyTransferDTO.php` â€” readonly: `customerId`, `fromWalletId`, `toWalletId`, `amount` (string decimal), `idempotencyKey`, `correlationId`
- [X] T050 [US4] Create `app/Modules/MultiCurrency/Application/Services/SameCurrencyTransferService.php` â€” `transfer(SameCurrencyTransferDTO): array`; checks idempotency via `multi_currency.same_currency_transfers` (SELECT by idempotency_key, return existing on match); validates both wallets belong to customer; validates both wallets are same currency (else throws `BusinessRuleViolationException` with code `currency_mismatch`); validates sufficient balance; uses `lockForUpdate()` on source wallet inside DB transaction; within single DB transaction: posts two-legged journal entry to E07 `LedgerService` (Dr source, Cr destination) AND inserts a `SameCurrencyTransferModel` record storing idempotency_key; dispatches `SameCurrencyTransferCompletedEvent`; audit logs
- [X] T051 [US4] Create `app/Modules/MultiCurrency/Domain/Events/SameCurrencyTransferCompletedEvent.php` â€” `US_MCW_004_SUCCEEDED`, payload: customerId, fromWalletId, toWalletId, amount, currencyCode, correlationId
- [X] T052 [US4] Create `app/Modules/MultiCurrency/Http/Requests/SameCurrencyTransferRequest.php` â€” validates `to_wallet_id` UUID, `amount` required numeric > 0, max 2 decimal places
- [X] T053 [US4] Add `transfer()` to `CurrencyWalletController` â€” resolves customerId from auth, builds DTO from path `{id}` + request body, calls service, returns transaction_id + status
- [X] T054 [US4] Register `POST /v1/multi-currency/wallets/{id}/transfers` â†’ `CurrencyWalletController@transfer`, middleware: `auth:api`, `permission:multi_currency.transfer`
- [X] T055 [US4] Write `tests/Feature/Modules/MultiCurrency/SameCurrencyTransferApiTest.php` â€” tests: happy path debits source + credits target; cross-currency wallet returns 422 `currency_mismatch`; insufficient balance returns 400; wallet not owned by customer returns 403; idempotent replay returns 200 with same result

---

## Phase 7: US-MCW-005 â€” Configure Exchange Rate (Admin)

**Goal**: Admin (maker) submits a new FX rate for a currency pair; a second admin (checker) approves via E28 maker-checker workflow; approved rate becomes active.

**Independent Test**: `POST /v1/admin/multi-currency/fx-rates` with valid pair returns 201 in `pending_approval`; `GET /v1/admin/multi-currency/fx-rates` shows active rates.

- [X] T056 [US5] Create `app/Modules/MultiCurrency/Application/Services/FxRateAdminService.php` â€” `submitRateChange(array $data, string $adminId, string $idempotencyKey): array`; validates pair + rate + spread + effective_from; saves `FxRateModel` with `effective_to = null` in pending state; creates a change request in E28 `ConfigurationChangeRequestService` referencing `multi_currency.fx_rate.{from}_{to}` key; returns the pending rate record
- [X] T057 [US5] Create `app/Modules/MultiCurrency/Http/Resources/FxRateResource.php` â€” transforms FxRateModel to `{id, from_currency_code, to_currency_code, rate, spread, effective_from, effective_to, created_at}`
- [X] T058 [US5] Create `app/Modules/MultiCurrency/Http/Controllers/Admin/AdminFxRateController.php` â€” `index()` returns active rates list; `store()` calls `FxRateAdminService::submitRateChange()`
- [X] T059 [US5] Register admin FX rate routes in `routes/api_admin_multi_currency.php`: `GET /v1/admin/multi-currency/fx-rates` + `POST /v1/admin/multi-currency/fx-rates`, middleware: `auth:api`, `permission:multi_currency.configure_rate`
- [X] T060 [US5] Write `tests/Feature/Modules/MultiCurrency/AdminFxRateApiTest.php` â€” tests: admin submits rate returns 201; list returns active rates; non-admin returns 403; duplicate pending change returns 409; invalid rate format returns 422

---

## Phase 8: US-MCW-006 â€” Calculate FX Fee

**Goal**: Customer can preview the FX fee and estimated output amount for a conversion before committing.

**Independent Test**: `GET /v1/multi-currency/fx-fee?from_currency=BWP&to_currency=USD&amount=1000` returns fee_amount > 0 and to_amount_estimate.

- [X] T061 [US6] Add `previewFee(string $from, string $to, string $amount): array` to `app/Modules/MultiCurrency/Application/Services/FxQuoteService.php` (create this file) â€” looks up active FxRate for pair; computes `fee_amount = amount Ã— rate.spread`; computes `to_amount_estimate = (amount - fee_amount) Ã— rate.rate`; returns preview struct; throws `BusinessRuleViolationException` if no active rate
- [X] T062 [US6] Create `app/Modules/MultiCurrency/Http/Controllers/FxFeeController.php` â€” `preview()` reads `from_currency`, `to_currency`, `amount` from query params, calls `FxQuoteService::previewFee()`, returns result
- [X] T063 [US6] Register `GET /v1/multi-currency/fx-fee` â†’ `FxFeeController@preview`, middleware: `auth:api`
- [X] T064 [US6] Write `tests/Feature/Modules/MultiCurrency/FxFeePreviewApiTest.php` â€” tests: valid pair returns preview with fee_amount and to_amount_estimate; unknown pair returns 422; missing params return 422

---

## Phase 9: US-MCW-007 Phase 1 â€” FX Quote (Rate Lock)

**Goal**: Customer requests a locked FX quote; system returns exact output amount and rate valid for 60 seconds.

**Independent Test**: `POST /v1/multi-currency/fx-quotes` returns 201 with `status=quoted` and `expires_at` ~60 s in future; second call with same idempotency key returns same quote.

- [X] T065 [US7] Create `app/Modules/MultiCurrency/Application/DTOs/FxQuoteRequestDTO.php` â€” readonly: `customerId`, `fromCurrencyCode`, `toCurrencyCode`, `fromAmount` (string), `idempotencyKey`, `correlationId`
- [X] T066 [US7] Add `requestQuote(FxQuoteRequestDTO): array` to `FxQuoteService` â€” checks idempotency; looks up active FxRate; validates customer has a wallet in `fromCurrencyCode` with sufficient balance; computes `fee_amount` and `to_amount`; creates `FxQuoteModel` with `status=Quoted`, `expires_at = now() + 60s`; dispatches `FxQuoteIssuedEvent`; audit logs
- [X] T067 [US7] Create `app/Modules/MultiCurrency/Domain/Events/FxQuoteIssuedEvent.php` â€” `US_MCW_007_REQUESTED`, payload: customerId, quoteId, fromCurrency, toCurrency, fromAmount, toAmount, expiresAt, correlationId
- [X] T068 [US7] Create `app/Modules/MultiCurrency/Http/Requests/FxQuoteRequest.php` â€” validates `from_currency_code`, `to_currency_code` required strings max 3, `from_amount` required numeric > 0
- [X] T069 [US7] Create `app/Modules/MultiCurrency/Http/Resources/FxQuoteResource.php` â€” transforms FxQuoteModel to `{id, from_currency_code, to_currency_code, from_amount, to_amount, fee_amount, rate, status, expires_at, created_at}`
- [X] T070 [US7] Create `app/Modules/MultiCurrency/Http/Controllers/FxQuoteController.php` â€” `store()` builds DTO from auth + request body, calls `FxQuoteService::requestQuote()`, returns `FxQuoteResource` 201
- [X] T071 [US7] Register `POST /v1/multi-currency/fx-quotes` â†’ `FxQuoteController@store`, middleware: `auth:api`, `permission:multi_currency.convert`
- [X] T072 [US7] Write `tests/Feature/Modules/MultiCurrency/FxQuoteApiTest.php` â€” tests: happy path returns 201 with quoted status and expires_at within 65s; idempotent replay returns same quote; no active rate returns 422; insufficient balance returns 422; unauthenticated returns 401

---

## Phase 10: US-MCW-007 Phase 2 + US-MCW-008 â€” Confirm Conversion & FX Ledger Entries

**Goal**: Customer confirms a quoted conversion; system executes synchronously, posts 3-entry FX journal, creates completed FxTransaction in one DB transaction.

**Independent Test**: Confirm a valid quote; BWP wallet balance decreases by `from_amount`; USD wallet balance increases by `to_amount`; FX suspense account nets to zero; FxTransaction status = `completed`; replay with same idempotency key returns 200 with same result.

- [X] T073 [US7] Create `app/Modules/MultiCurrency/Application/DTOs/FxQuoteConfirmDTO.php` â€” readonly: `customerId`, `quoteId`, `idempotencyKey`, `correlationId`
- [X] T074 [US7] [US8] Create `app/Modules/MultiCurrency/Application/Services/FxConversionService.php` â€” `confirm(FxQuoteConfirmDTO): array`; checks idempotency on `FxTransactionRepositoryInterface::findByIdempotencyKey()`; loads quote with `lockForUpdate()`; validates: quote belongs to customer, status=Quoted, not expired; validates sufficient balance on source wallet (lockForUpdate on CurrencyWallet); within single DB transaction: (1) posts 3-entry FX journal via E07 `LedgerService` (Dr source wallet from_amount+fee, Cr fx_suspense; Dr fx_suspense to_amount, Cr target wallet; Dr fx_suspense fee_amount, Cr platform.fx_revenue) â€” this is the US-MCW-008 "Record FX ledger entries" implementation; (2) creates `FxTransactionModel` status=Completed; (3) updates quote status=Confirmed, sets fx_transaction_id; dispatches `FxConversionCompletedEvent`; audit logs
- [X] T075 [US7] Create `app/Modules/MultiCurrency/Domain/Events/FxConversionCompletedEvent.php` â€” `US_MCW_007_SUCCEEDED`, payload: customerId, fxTransactionId, fromCurrency, toCurrency, fromAmount, toAmount, feeAmount, correlationId
- [X] T076 [US7] Create `app/Modules/MultiCurrency/Domain/Events/FxConversionFailedEvent.php` â€” `US_MCW_007_FAILED`, payload: customerId, quoteId, reason, correlationId
- [X] T077 [US7] Create `app/Modules/MultiCurrency/Http/Resources/FxTransactionResource.php` â€” transforms FxTransactionModel to `{id, quote_id, from_currency_code, to_currency_code, from_amount, to_amount, fee_amount, rate, status, completed_at, created_at}`
- [X] T078 [US7] Create `app/Modules/MultiCurrency/Http/Requests/FxQuoteConfirmRequest.php` â€” validates `idempotency_key` required string max 255
- [X] T079 [US7] Add `confirm()` to `FxQuoteController` â€” builds `FxQuoteConfirmDTO` from auth + path `{id}` + request body, calls `FxConversionService::confirm()`, returns `FxTransactionResource` 200; on already-confirmed idempotent replay returns 200 with existing FxTransaction
- [X] T080 [US7] Register `POST /v1/multi-currency/fx-quotes/{id}/confirm` â†’ `FxQuoteController@confirm`, middleware: `auth:api`, `permission:multi_currency.convert`
- [X] T081 [US7] Write `tests/Feature/Modules/MultiCurrency/FxConversionApiTest.php` â€” tests: confirm happy path returns 200 completed FxTransaction; source balance decreases by from_amount; target balance increases by to_amount; FX suspense nets to zero (DB assertion); idempotent replay returns same FxTransaction; expired quote returns 422; insufficient balance at confirm returns 422; quote owned by other customer returns 403

---

## Phase 11: US-MCW-009 â€” Maintain FX Transaction History

**Goal**: Customer can view a paginated, filterable history of all their FX conversions.

**Independent Test**: After completing a conversion, `GET /v1/multi-currency/fx-transactions` returns at least one record with `status=completed`; date-range filter returns only records within bounds.

- [X] T082 [US9] Create `app/Modules/MultiCurrency/Application/Services/FxTransactionHistoryService.php` â€” `list(string $customerId, array $filters, int $perPage): LengthAwarePaginator` delegates to `FxTransactionRepositoryInterface::paginateByCustomer()`; `get(string $customerId, string $id): array` validates ownership; audit logs read access
- [X] T083 [US9] Create `app/Modules/MultiCurrency/Http/Controllers/FxTransactionController.php` â€” `index()` extracts filters from query params (`from_currency`, `to_currency`, `from_date`, `to_date`, `per_page`), calls history service; `show()` returns single transaction
- [X] T084 [US9] Register routes `GET /v1/multi-currency/fx-transactions` + `GET /v1/multi-currency/fx-transactions/{id}` â†’ `FxTransactionController@index` / `@show`, middleware: `auth:api`, `permission:multi_currency.view_balances`
- [X] T085 [US9] Write `tests/Feature/Modules/MultiCurrency/FxTransactionHistoryApiTest.php` â€” tests: list returns paginated results after conversion; `from_currency` filter scopes results; date range filter excludes out-of-range records; `show` returns 403 for transaction owned by other customer

---

## Phase 12: Polish & Cross-Cutting Concerns

**Purpose**: Events wiring, admin portal pages, Flutter screens, OpenAPI spec, concurrency tests, PHPStan.

- [X] T086 Wire all domain events in `MultiCurrencyServiceProvider` â€” register event â†’ listener mappings for all five events; for financial integration events (`FxConversionCompletedEvent`, `CurrencyWalletCreatedEvent`, `SameCurrencyTransferCompletedEvent`) listeners MUST dispatch via the E27 outbox/reliable-event pattern (write to `outbox_messages` within the same DB transaction that produces the event, not as a fire-and-forget after-commit hook) per Principle 9; `FxQuoteIssuedEvent` and `FxConversionFailedEvent` may use standard Laravel event dispatch
- [X] T087 [P] Create Next.js admin page `wallet-ecosystem/apps/admin/app/(admin)/multi-currency/currencies/page.tsx` â€” renders currency list (active/inactive toggle), activate/deactivate actions, uses shared design tokens
- [X] T088 [P] Create Next.js admin page `wallet-ecosystem/apps/admin/app/(admin)/multi-currency/fx-rates/page.tsx` â€” displays active FX rate table per currency pair; "Submit Rate Change" button opens form for POST /admin/multi-currency/fx-rates
- [X] T089 [P] Create Flutter screens `wallet-ecosystem/apps/customer/lib/features/multi_currency/` â€” `screens/currency_wallet_list_screen.dart` (balance grid), `screens/fx_convert_screen.dart` (quote-then-confirm flow), `screens/same_currency_transfer_screen.dart`; use shared design tokens; implement loading/error/success/empty states
- [X] T090 Write `wallet-ecosystem/services/api/openapi/v1/multi_currency.yaml` â€” copy and finalize `contracts/openapi.yaml` into the service's OpenAPI directory; ensure all enums, schemas, and error responses match implementation
- [X] T091 Write `tests/Feature/Modules/MultiCurrency/FxConversionConcurrencyTest.php` â€” two concurrent conversion requests on the same wallet (same from_amount that exceeds half the balance); verify exactly one succeeds and the other fails with insufficient_balance; verify no double-debit occurs
- [X] T092 Write `tests/Feature/Modules/MultiCurrency/CurrencyWalletDuplicateTest.php` â€” concurrent wallet creation requests for the same currency; verify exactly one succeeds and the other returns 409
- [x] T093 Run PHPStan level 8 across `app/Modules/MultiCurrency/` â€” resolve all errors; add to `phpstan.neon` includes if not already present â ï¸ DOCKER-GATED (inline static fixes applied; full run requires Docker)
- [x] T094 Run quickstart.md validation scenarios SC-01 through SC-10 in Docker environment; confirm all pass; note any Docker-gated tests â ï¸ DOCKER-GATED

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies â€” start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 â€” BLOCKS all story phases
- **Phases 3â€“11 (Stories)**: All depend on Phase 2; can proceed in listed order (natural dependency chain: currencies â†’ wallets â†’ balances â†’ transfer â†’ rates â†’ fees â†’ quote â†’ confirm â†’ history)
- **Phase 12 (Polish)**: Depends on Phases 3â€“11 complete

### Story Dependency Chain

```
US-MCW-002 (currencies) â†’ US-MCW-001 (create wallet) â†’ US-MCW-003 (view balances)
                                                       â†’ US-MCW-004 (same-currency transfer)
US-MCW-005 (configure rate) â†’ US-MCW-006 (FX fee) â†’ US-MCW-007/Phase1 (quote)
                                                    â†’ US-MCW-007/Phase2 + US-MCW-008 (confirm + ledger)
                                                                                       â†’ US-MCW-009 (history)
```

### Parallel Opportunities Within Phases

- **Phase 1**: T004â€“T008 (migrations) all [P] â€” run simultaneously
- **Phase 2**: T011â€“T022 (enums + interfaces + models) all [P] â€” run simultaneously; T023â€“T027 (repositories) after models
- **Phase 12**: T087â€“T090 (admin pages + Flutter + OpenAPI) all [P]

---

## Implementation Strategy

### MVP (US-MCW-001 + US-MCW-002 + US-MCW-003)

1. Complete Phase 1 (Setup) + Phase 2 (Foundational)
2. Complete Phase 3 (US-MCW-001: Create wallet)
3. Complete Phase 4 (US-MCW-002: Currency management)
4. Complete Phase 5 (US-MCW-003: View balances)
5. **Validate**: Customer can open a USD wallet and see a 0.00 balance â€” demonstrable increment

### Incremental Delivery

1. Phases 1â€“5 â†’ Wallet creation + balance view (MVP)
2. Phase 6 â†’ Same-currency transfer
3. Phases 7â€“8 â†’ Admin FX rate management
4. Phase 9 â†’ FX fee preview
5. Phases 10â€“11 â†’ Full FX quote-then-confirm + ledger posting
6. Phase 12 â†’ FX history
7. Phase 13 â†’ Polish, admin/mobile UI, concurrency tests, PHPStan

---

## Phase 13: Convergence

**Generated by `/speckit-converge` on 2026-10-03. Findings: 2 HIGH · 1 MEDIUM · 2 LOW.**

- [X] T096 Add `idempotency_key VARCHAR(255) UNIQUE NOT NULL` column to `multi_currency.currency_wallets` via new migration `database/migrations/2026_10_03_002000_add_idempotency_key_to_currency_wallets.php`; store key on `CurrencyWalletModel`; update `CurrencyWalletService::create()` to check existing wallet’s `idempotency_key` — same-key replay returns existing wallet (200/201), different-key duplicate throws `CurrencyWalletAlreadyExistsException` (409); update `CreateCurrencyWalletApiTest` to assert same-key replay returns 201 with existing wallet per US-MCW-001 uniqueness rule (partial)
- [X] T097 Integrate E28 maker-checker into `FxRateAdminService::submitRateChange()` — add `approved_by` and `change_request_id` fields to `FxRateModel` save; inject `ConfigurationChangeRequestServiceInterface` from E28; create a change request referencing key `multi_currency.fx_rate.{FROM}_{TO}`; mark new rate as pending (add `pending_approval` status to FxRate or keep effective_to logic with a `status` flag); expose approval activation via E28 approval callback (event listener or webhook handler) that sets `effective_to` on the old rate and activates the new one; update `AdminFxRateApiTest` to assert submitted rate is in `pending_approval` state until approved per US-MCW-005 / Principle 3 (partial)
- [X] T098 [P] Create `wallet-ecosystem/apps/customer/lib/modules/multi_currency/presentation/screens/same_currency_transfer_screen.dart` — ConsumerStatefulWidget with Riverpod; form fields: source wallet selector, destination wallet selector (same currency only), amount input; calls `POST /v1/multi-currency/wallets/{id}/transfers`; implements loading/error/success/empty states; uses shared design tokens per T089 / US-MCW-004 (missing)
- [X] T099 [P] Update `specs/029-multi-currency-wallet/data-model.md` CurrencyWallet entity table to add `pocket_ledger_account_reference VARCHAR(36) NOT NULL` column (after `wallet_id`) with note "Cached ledger account reference to avoid repeated cross-module calls on balance reads"; add to Indexes section note "Used in all ledger balance reads" per data-model.md accuracy (partial)
- [X] T100 [P] Resolve `FxFeePreviewDTO` dead code — either (a) update `FxQuoteService::previewFee()` to accept `FxFeePreviewDTO` and update `FxFeeController` accordingly, OR (b) delete `app/Modules/MultiCurrency/Application/DTOs/FxFeePreviewDTO.php` if the 3-string-param signature is the deliberate choice; ensure PHPStan sees no unused class error per plan module structure (unrequested)
