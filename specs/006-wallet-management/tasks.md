# Tasks — E06 Wallet Management

**Feature**: E06 Wallet Management
**Spec**: `specs/006-wallet-management/spec.md`
**Plan**: `specs/006-wallet-management/plan.md`
**Status**: Implementation Ready

## Summary

| Phase | Description | Tasks | Stories |
|---|---|---|---|
| 1 | Setup | T001–T005 | — |
| 2 | Foundational | T006–T040 | — |
| 3 | US-WALLET-001 Auto-Create Wallet | T041–T050 | US1 |
| 4 | US-WALLET-002 Assign Wallet ID | T051–T054 | US2 |
| 5 | US-WALLET-003 Associate Wallet with User | T055–T060 | US3 |
| 6 | US-WALLET-004 Set Wallet Currency | T061–T068 | US4 |
| 7 | US-WALLET-005 Set Wallet Status | T069–T077 | US5 |
| 8 | US-WALLET-006 Configure Wallet Limits | T078–T086 | US6 |
| 9 | US-WALLET-007 View Available Balance | T087–T096 | US7 |
| 10 | US-WALLET-008 View Ledger Balance | T097–T099 | US8 |
| 11 | US-WALLET-009 View Held Balance | T100–T103 | US9 |
| 12 | US-WALLET-011 Calculate Available Balance | T104–T108 | US11 |
| 13 | US-WALLET-012 Prevent Overdraft | T109–T118 | US12 |
| 14 | US-WALLET-013 Freeze Wallet | T119–T125 | US13 |
| 15 | US-WALLET-014 Unfreeze Wallet | T126–T130 | US14 |
| 16 | US-WALLET-016 Frozen Enforcement | T131–T135 | US16 |
| 17 | US-WALLET-017 Status History | T136–T141 | US17 |
| 18 | US-WALLET-010 Pending Balance (P1) | T142–T145 | US10 |
| 19 | US-WALLET-015 Close Wallet (P1) | T146–T152 | US15 |
| 20 | Polish & Cross-Cutting | T153–T166 | — |

**Total tasks**: 166

---

## Phase 1 — Setup

Goal: Scaffold the Wallet module directory and register it with the application.

- [X] T001 Create Wallet module directory structure under `wallet-ecosystem/services/api/app/Modules/Wallet/` matching the layout in `specs/006-wallet-management/plan.md` (Console/Commands/, Domain/, Application/, Infrastructure/, Providers/)
- [X] T002 Create `wallet-ecosystem/services/api/app/Providers/WalletServiceProvider.php` with stub `register()` and `boot()` methods; add to `config/app.php` providers array
- [X] T003 Create `wallet-ecosystem/services/api/routes/modules/wallet.php` with 13 route stubs (POST /v1/wallets, GET /v1/wallets/my, GET /v1/wallets/{id}, PATCH /{id}/status, POST /{id}/freeze, POST /{id}/unfreeze, POST /{id}/close, GET /{id}/limits, PUT /{id}/limits, GET /{id}/pockets, POST /{id}/pockets, GET /{id}/pockets/{code}/balance, GET /{id}/status-history); register in WalletServiceProvider::boot()
- [X] T004 [P] Create test directories: `wallet-ecosystem/services/api/tests/Unit/Modules/Wallet/`, `tests/Feature/Api/V1/Wallets/`, `tests/Integration/Modules/Wallet/`, `tests/Security/Modules/Wallet/`, `tests/Concurrency/Modules/Wallet/`
- [X] T005 Add `wallet` database connection to `wallet-ecosystem/services/api/config/database.php` pointing to the same PostgreSQL host, using schema search path `wallet`

---

## Phase 2 — Foundational

Goal: All migrations, contracts, enums, exceptions, Eloquent models, and core domain services that are prerequisites for every user story.

Dependencies: Phase 1 complete.

### 2.1 Contract Extensions

- [X] T006 Add `getBalance(string $ledgerAccountReference): int` method signature to `wallet-ecosystem/services/api/app/Modules/Ledger/Domain/Contracts/LedgerModuleContract.php`
- [X] T007 Implement `getBalance()` in `wallet-ecosystem/services/api/app/Modules/Ledger/Infrastructure/NullImplementations/NullLedgerModule.php` — throw `ModuleNotImplementedException`
- [X] T008 Expand `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Contracts/WalletModuleContract.php` with all five methods: `isActive()`, `ownerActorId()`, `currency()`, `pocketExists()`, `availableBalance()`; add inline docblock noting `currency()` returns primary/home currency (BWP default)
- [X] T009 Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/NullImplementations/NullWalletModule.php` implementing `WalletModuleContract`; all five methods throw `ModuleNotImplementedException`; bind in WalletServiceProvider as the default implementation until E06 is wired

### 2.2 Domain Enums

- [X] T010 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Enums/WalletStatus.php` PHP enum with cases: `Pending`, `Active`, `Frozen`, `Suspended`, `Restricted`, `Closed`
- [X] T011 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Enums/PocketStatus.php` PHP enum with cases: `Active`, `Disabled`
- [X] T012 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Enums/WalletLimitType.php` PHP enum with cases: `DailyTransactionAmount`, `WeeklyTransactionAmount`, `MonthlyTransactionAmount`, `SingleTransactionAmount`, `MaxBalance`
- [X] T013 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Enums/WalletHoldStatus.php` PHP enum with cases: `Active`, `Released`, `Expired`

### 2.3 Domain Exceptions

- [X] T014 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Exceptions/WalletAlreadyExistsException.php` extending `DomainException`
- [X] T015 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Exceptions/WalletNotFoundException.php` extending `DomainException`
- [X] T016 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Exceptions/InvalidWalletTransitionException.php` with `fromStatus` and `toStatus` constructor properties
- [X] T017 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Exceptions/InsufficientBalanceException.php` with `available` and `requested` constructor properties
- [X] T018 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Exceptions/WalletNotTransactableException.php` with `status` constructor property
- [X] T019 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Exceptions/WalletHasBalanceException.php`
- [X] T020 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Exceptions/PocketAlreadyExistsException.php`
- [X] T021 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Exceptions/UnsupportedCurrencyException.php`

### 2.4 Migrations

- [X] T022 Create migration `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Migrations/2026_09_18_001000_create_wallet_currencies.php` — creates `wallet.currencies` table (code varchar PK, name, precision smallint nullable, is_enabled bool, is_tbd bool, created_at)
- [X] T023 Create migration `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Migrations/2026_09_18_001100_create_wallet_wallets.php` — creates `wallet.wallets` table with all fields from data-model.md; partial unique index on `owner_id WHERE status != 'closed'`; `$connection = 'wallet'`
- [X] T024 Create migration `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Migrations/2026_09_18_001200_create_wallet_currency_pockets.php` — creates `wallet.currency_pockets` table; unique constraint on `(wallet_id, currency_code)`; `ledger_account_reference` varchar not null unique
- [X] T025 Create migration `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Migrations/2026_09_18_001300_create_wallet_wallet_holds.php` — creates `wallet.wallet_holds` table with `expires_at timestamptz nullable`, `expired_at timestamptz nullable`, `released_at timestamptz nullable`; index on `(pocket_id, status)`
- [X] T026 Create migration `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Migrations/2026_09_18_001400_create_wallet_wallet_limits.php` — creates `wallet.wallet_limits` table with `is_tbd bool default true`, `limit_value bigint nullable`
- [X] T027 Create migration `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Migrations/2026_09_18_001500_create_wallet_wallet_status_history.php` — creates `wallet.wallet_status_history` append-only table; no update or delete permissions granted
- [X] T028 Create migration `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Migrations/2026_09_18_001600_seed_wallet_currencies.php` — seeds BWP (precision=2), USD (2), EUR (2), ZAR (2), CNY (2), USDT (precision=null, is_tbd=true); all is_enabled=true
- [X] T029 Create migration `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Migrations/2026_09_18_001700_seed_wallet_permissions.php` — inserts all 10 wallet permissions into `platform.permissions`; assigns Customer/Agent roles `wallet.read`, `wallet.pocket.create`, `wallet.balance.read`; assigns Admin all 10
- [X] T030 Create migration `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Migrations/2026_09_18_001800_seed_wallet_limit_defaults.php` — inserts 5 limit-type defaults into `platform.configuration_values` with `is_tbd=true`; also seeds config keys with `is_tbd=true`: `wallet.reference_format` (TBD-E06-007), `wallet.expire_holds_schedule` (TBD-E06-004), `wallet.pocket_creation_strategy` (TBD-E06-008; default value `'lazy'` so system is functional while TBD is open)
- [X] T031 Register all 9 migration files in `WalletServiceProvider::boot()` using `$this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations')`

### 2.5 Eloquent Models

- [X] T032 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Models/CurrencyModel.php` — `$connection = 'wallet'`, `$table = 'wallet.currencies'`, cast `is_enabled` and `is_tbd` to bool
- [X] T033 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Models/WalletModel.php` — `$connection = 'wallet'`, `$table = 'wallet.wallets'`, cast `status` to `WalletStatus` enum, hasMany `CurrencyPocketModel`, hasMany `WalletStatusHistoryModel`
- [X] T034 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Models/CurrencyPocketModel.php` — `$connection = 'wallet'`, `$table = 'wallet.currency_pockets'`, cast `status` to `PocketStatus` enum, belongsTo `WalletModel`, hasMany `WalletHoldModel`
- [X] T035 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Models/WalletHoldModel.php` — `$connection = 'wallet'`, `$table = 'wallet.wallet_holds'`, cast `status` to `WalletHoldStatus` enum, belongsTo `CurrencyPocketModel`
- [X] T036 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Models/WalletLimitModel.php` — `$connection = 'wallet'`, `$table = 'wallet.wallet_limits'`, cast `limit_type` to `WalletLimitType` enum
- [X] T037 [P] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Models/WalletStatusHistoryModel.php` — `$connection = 'wallet'`, `$table = 'wallet.wallet_status_history'`, disable update/delete (override `save()` to throw if model already exists in DB)

### 2.6 Core Domain Services

- [X] T038 Implement `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Services/WalletStateMachine.php` with a `transition(WalletStatus $from, WalletStatus $to): void` method that enforces all 12 valid transitions from FR-E06-032; throws `InvalidWalletTransitionException` on invalid transitions
- [X] T039 Implement `wallet-ecosystem/services/api/app/Modules/Wallet/Application/Services/WalletReferenceGenerator.php` — reads format from `wallet.reference_format` config key (TBD-E06-007 governance); uses interim default `WLT-{YYYYMMDD}-{8 random uppercase alphanum}`; retry loop up to 5 attempts on DB unique-constraint violation; throws after 5 failed attempts
- [X] T040 Create `wallet-ecosystem/services/api/app/Modules/Wallet/Application/Contracts/WalletHoldServiceContract.php` interface with `place()` and `release()` signatures as documented in `specs/006-wallet-management/contracts/wallet-hold-service-contract.php`

---

## Phase 3 — US-WALLET-001: Auto-Create Wallet (P0)

**Goal**: System automatically provisions a wallet when `kyc.case.approved` event is received; Admin can also create manually via API.

**Independent Test**: Publish `Kyc.Case.Approved` for a user with no wallet → wallet record created with `status = pending`, BWP pocket exists, `Wallet.Created` event in outbox, first status-history entry recorded.

Dependencies: Phase 2 complete (migrations, models, domain services).

- [X] T041 [US1] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Events/WalletCreatedEvent.php` with fields: `event_id` (UUID v7, generated at construction), `aggregate_id` (= walletId), `walletId`, `ownerId`, `actorId` (system service actor or creating Admin), `correlationId`, `occurredAt` (UTC ISO-8601), `schema_version` (= `'1'`); event name string `Wallet.Created`
- [X] T042 [US1] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Events/WalletActivatedEvent.php` with fields: `event_id` (UUID v7), `aggregate_id` (= walletId), `walletId`, `ownerId`, `actorId`, `correlationId`, `occurredAt` (UTC ISO-8601), `schema_version` (= `'1'`); event name `Wallet.Activated`
- [X] T043 [US1] Implement `wallet-ecosystem/services/api/app/Modules/Wallet/Application/Services/WalletProvisioningService.php` with method `provisionForUser(string $userId, string $correlationId): WalletModel` — wraps wallet creation in a DB transaction; calls `WalletReferenceGenerator`; creates wallet row, BWP pocket row (with UUID v7 `ledger_account_reference`), first `wallet_status_history` row; **creates per-wallet `wallet_limits` rows for all 5 limit types using defaults read from `platform.configuration_values` (all will have `is_tbd = true` at baseline; no limit value is invented)**; publishes `Wallet.Created` via `OutboxEventPublisher`; enforces idempotency via `platform.idempotency_records` keyed on `wallet.provision.{userId}`; throws `WalletAlreadyExistsException` if non-closed wallet exists
- [X] T044 [US1] Implement `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Jobs/ProcessKycApprovedForWalletJob.php` — queued job; accepts `userId` and `correlationId` from event payload; calls `WalletProvisioningService::provisionForUser()`; logs structured warning (not exception) if wallet already exists
- [X] T045 [US1] Register `ProcessKycApprovedForWalletJob` as listener for `Kyc.Case.Approved` outbox event in `WalletServiceProvider::boot()`
- [X] T046 [US1] Implement `POST /v1/wallets` in `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Controllers/WalletController.php` — requires `wallet.create` permission; calls `WalletProvisioningService::provisionForUser()`; enforces `Idempotency-Key` header via platform `IdempotencyService`; returns HTTP 201 with `WalletResource`
- [X] T047 [US1] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Requests/CreateWalletRequest.php` — validates `user_id` (required, UUID); requires `Idempotency-Key` header
- [X] T048 [US1] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Resources/WalletResource.php` — returns `id`, `wallet_reference`, `owner_id`, `status`, `pockets` (array of pocket summaries), `created_at`, `updated_at` wrapped in platform response envelope
- [X] T049 [US1] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Repositories/EloquentWalletRepository.php` with methods: `findById()`, `findByOwnerId()`, `findActiveByOwnerId()`, `save()`
- [X] T050 [US1] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/CreateWalletTest.php` — covers US-WALLET-001 scenarios 1–5: auto-provision via event, duplicate-event idempotency, atomic rollback, manual Admin creation, Idempotency-Key replay

---

## Phase 4 — US-WALLET-002: Assign Wallet ID (P0)

**Goal**: Every wallet receives a unique `wallet_reference` atomically at creation; collision retry is safe.

**Independent Test**: Create two wallets → both have non-null, distinct `wallet_reference` values matching `WLT-{YYYYMMDD}-{8 alphanum}` pattern.

Dependencies: T039 (WalletReferenceGenerator), T043 (WalletProvisioningService).

- [X] T051 [US2] Write unit test `wallet-ecosystem/services/api/tests/Unit/Modules/Wallet/WalletReferenceGeneratorTest.php` — asserts reference matches interim format, uniqueness across 1000 generated values, retry loop resolves after 1 simulated collision, TBD-E06-007 config key is consulted (mock returns null → uses default)
- [X] T052 [US2] Write integration test `wallet-ecosystem/services/api/tests/Integration/Modules/Wallet/WalletReferenceUniquenessTest.php` — creates 50 wallets concurrently; asserts no duplicate `wallet_reference` in DB
- [X] T053 [US2] Add `wallet_reference` uniqueness handling to `EloquentWalletRepository::save()` — catch unique constraint violation on `wallet_reference` column specifically; surface as `WalletReferenceCollisionException` to trigger generator retry (not a generic DB error)
- [X] T054 [US2] Update `WalletResource` to confirm `wallet_reference` field is present; add acceptance test asserting field is non-null in `POST /v1/wallets` response and in `GET /v1/wallets/{id}` response

---

## Phase 5 — US-WALLET-003: Associate Wallet with User (P0)

**Goal**: Wallet ownership is enforced; IDOR prevention returns 404 for cross-user access; `WalletModuleContract::ownerActorId()` is wired to real implementation.

**Independent Test**: Create wallet for user U → `GET /v1/wallets/{id}` returns `owner_id = U`; user V calling same endpoint receives HTTP 404.

Dependencies: T046–T049 (wallet creation + controller + repository).

- [X] T055 [US3] Implement `GET /v1/wallets/my` in `WalletController` — requires `wallet.read` permission; resolves wallet by authenticated actor's user_id; returns HTTP 404 if no wallet exists
- [X] T056 [US3] Implement `GET /v1/wallets/{id}` in `WalletController` — requires `wallet.read` (own) or `wallet.read.any` (Admin); enforces ownership check: if caller lacks `wallet.read.any` and `wallet.owner_id != caller_id`, return HTTP 404 (IDOR prevention); emit `wallet.idor.blocked` metric on blocked attempt
- [X] T057 [US3] Implement `WalletModuleContract::ownerActorId()` in a new `wallet-ecosystem/services/api/app/Modules/Wallet/Application/Services/WalletService.php` — delegates to `EloquentWalletRepository::findById()`; returns `null` for not-found wallets
- [X] T058 [US3] Implement `WalletModuleContract::isActive()` in `WalletService` — returns `true` only when wallet status is `Active`
- [X] T059 [US3] Bind `WalletModuleContract` to the real `WalletService` implementation in `WalletServiceProvider::register()`
- [X] T060 [US3] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/WalletOwnershipTest.php` — covers US-WALLET-003 scenarios 1–5: owner access returns owner_id, IDOR 404, duplicate wallet 409, ownerActorId for missing wallet returns null, isActive returns false for pending wallet

---

## Phase 6 — US-WALLET-004: Set Wallet Currency (P0)

**Goal**: Customers can add currency pockets to their wallet; duplicate pocket returns 409; unsupported/disabled currency returns 422; `Wallet.Pocket.Added` event emitted.

**Independent Test**: `POST /v1/wallets/{id}/pockets` with `currency_code = USD` → HTTP 201, pocket created with `status = active`; second call same currency → HTTP 409.

Dependencies: T033–T034 (models), T049 (wallet repo).

- [X] T061 [US4] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Events/WalletPocketAddedEvent.php` — fields: `event_id` (UUID v7), `aggregate_id` (= walletId), `walletId`, `pocketId`, `currencyCode`, `ledgerAccountReference`, `actorId`, `correlationId`, `occurredAt` (UTC ISO-8601), `schema_version` (= `'1'`); event name `Wallet.Pocket.Added`
- [X] T062 [US4] Implement `wallet-ecosystem/services/api/app/Modules/Wallet/Application/Services/WalletPocketService.php` with method `addPocket(string $walletId, string $currencyCode, string $actorId, string $correlationId): CurrencyPocketModel` — validates wallet is active/pending (FR-E06-012); validates currency enabled in `wallet.currencies`; checks no existing pocket for same currency; generates UUID v7 `ledger_account_reference`; inserts pocket row; publishes `Wallet.Pocket.Added` in same transaction
- [X] T063 [US4] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Repositories/EloquentCurrencyPocketRepository.php` with methods: `findByWalletAndCurrency()`, `findAllByWalletId()`, `save()`
- [X] T064 [US4] Implement `GET /v1/wallets/{id}/pockets` in `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Controllers/WalletPocketController.php` — requires `wallet.read`; ownership check; returns all pockets via `PocketResource`
- [X] T065 [US4] Implement `POST /v1/wallets/{id}/pockets` in `WalletPocketController` — requires `wallet.pocket.create`; enforces `Idempotency-Key`; calls `WalletPocketService::addPocket()`; returns HTTP 201
- [X] T066 [US4] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Requests/AddPocketRequest.php` — validates `currency_code` (required, string, max 10)
- [X] T067 [US4] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Resources/PocketResource.php` — returns `id`, `currency_code`, `status`, `precision`, `created_at`
- [X] T068 [US4] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/AddPocketTest.php` — covers US-WALLET-004 scenarios 1–5: add USD pocket + event, duplicate 409, unsupported currency 422, disabled currency 422, pockets list includes both pockets with precision

---

## Phase 7 — US-WALLET-005: Set Wallet Status (P0)

**Goal**: Admin can transition wallets through valid lifecycle states; `WalletStateMachine` enforces transitions; every change produces an immutable history entry and a domain event.

**Independent Test**: Activate a `pending` wallet → status becomes `active`, one status-history entry recorded, `Wallet.Activated` event in outbox. Attempt `closed → active` → HTTP 422.

Dependencies: T033 (WalletModel), T037 (WalletStatusHistoryModel), T038 (WalletStateMachine).

- [X] T069 [US5] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Events/WalletStatusChangedEvent.php` — fields: `event_id` (UUID v7), `aggregate_id` (= walletId), `walletId`, `fromStatus`, `toStatus`, `actorId`, `reason`, `correlationId`, `occurredAt` (UTC ISO-8601), `schema_version` (= `'1'`); event name `Wallet.Status.Changed`
- [X] T070 [US5] Implement `WalletService::transitionStatus(string $walletId, WalletStatus $to, string $reason, string $actorId, string $correlationId): WalletModel` — loads wallet with pessimistic lock; delegates to `WalletStateMachine::transition()`; updates wallet status; appends `WalletStatusHistoryModel` row; publishes appropriate domain event via `OutboxEventPublisher`; all in single transaction; uses `IdempotencyService` for duplicate prevention
- [X] T071 [US5] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Repositories/WalletStatusHistoryRepository.php` — append-only; `record()` method only; explicitly guard against update/delete calls
- [X] T072 [US5] Implement `PATCH /v1/wallets/{id}/status` in `WalletController` — requires `wallet.status.manage`; enforces `Idempotency-Key`; calls `WalletService::transitionStatus()`; returns updated `WalletResource`
- [X] T073 [US5] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Requests/UpdateWalletStatusRequest.php` — validates `status` (required, enum from FR-E06-031), `reason` (required string, max 500)
- [X] T074 [US5] Wire `AuditService::record()` call into `WalletService::transitionStatus()` with action slug `wallet.status.changed`, actor, wallet_id, old/new status, correlation_id
- [X] T075 [US5] Write unit test `wallet-ecosystem/services/api/tests/Unit/Modules/Wallet/WalletStateMachineTest.php` — asserts all 12 valid transitions succeed; all invalid transitions throw `InvalidWalletTransitionException`; covers every `from` state including `closed`
- [X] T076 [US5] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/WalletStatusTest.php` — covers US-WALLET-005 scenarios 1–5: pending→active + history + event, active→suspended + event, invalid transition 422, unauthorized Customer 403, idempotent Idempotency-Key replay
- [X] T077 [US5] Write unit test `wallet-ecosystem/services/api/tests/Unit/Modules/Wallet/WalletStatusHistoryRepositoryTest.php` — asserts `record()` inserts row; asserts calling `save()` on an existing model row throws; verifies no update/delete methods are callable

---

## Phase 8 — US-WALLET-006: Configure Wallet Limits (P0)

**Goal**: Admins can read and update per-wallet per-pocket limits; limits are enforced during debit operations; TBD-E06-004 values are seeded with `is_tbd=true`.

**Independent Test**: `PUT /v1/wallets/{id}/limits` with `daily_transaction_amount = 500000` → HTTP 200; `Wallet.Limits.Configured` event in outbox; audit event in `platform.audit_events`.

Dependencies: T036 (WalletLimitModel), T030 (seed migration), T070 (WalletService for status check).

- [X] T078 [US6] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Events/WalletLimitsConfiguredEvent.php` — fields: `event_id` (UUID v7), `aggregate_id` (= walletId), `walletId`, `actorId`, `changes` (array of `{limitType, oldValue, newValue}`), `correlationId`, `occurredAt` (UTC ISO-8601), `schema_version` (= `'1'`); event name `Wallet.Limits.Configured`
- [X] T079 [US6] Implement `wallet-ecosystem/services/api/app/Modules/Wallet/Application/Services/WalletLimitService.php` with methods: `getLimitsForWallet(string $walletId): array`, `updateLimits(string $walletId, array $limitUpdates, string $actorId, string $correlationId): array`, `enforceLimits(string $walletId, string $currencyCode, WalletLimitType $type, int $amount): void` (throws `TransactionLimitExceededException`); rejects updates on frozen/closed wallets; **`enforceLimits()` MUST skip any limit row where `is_tbd = true` — a TBD limit is treated as unlimited (no enforcement); log at `debug` level when a limit is skipped due to `is_tbd`; do NOT treat a null `limit_value` as zero**
- [X] T080 [US6] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Repositories/WalletLimitRepository.php` with `getByWalletId()`, `getByWalletAndCurrency()`, `upsert()`
- [X] T081 [US6] Implement `GET /v1/wallets/{id}/limits` in `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Controllers/WalletLimitController.php` — requires `wallet.read`; ownership check; returns limits via `LimitResource`
- [X] T082 [US6] Implement `PUT /v1/wallets/{id}/limits` in `WalletLimitController` — requires `wallet.limits.manage`; enforces `Idempotency-Key`; calls `WalletLimitService::updateLimits()`; publishes `Wallet.Limits.Configured`; writes audit record with old/new values; returns HTTP 200
- [X] T083 [US6] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Requests/UpdateLimitsRequest.php` — validates `limits` array (required), each entry has `currency_code`, `limit_type` (enum), `limit_value` (integer >= 0)
- [X] T084 [US6] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Resources/LimitResource.php` — returns `currency_code`, `limit_type`, `limit_value`, `is_tbd`, `effective_from`
- [X] T085 [US6] Add `TransactionLimitExceededException` to `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Exceptions/TransactionLimitExceededException.php`; map to HTTP 422 `TRANSACTION_LIMIT_EXCEEDED` in exception handler
- [X] T086 [US6] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/WalletLimitsTest.php` — covers US-WALLET-006 scenarios 1–5: set limit + event, update limit takes effect, invalid value 422, frozen wallet 422, audit event has old/new values; **also add unit test for `WalletLimitService::enforceLimits()` `is_tbd=true` skip behaviour: with all limits seeded as `is_tbd=true`, a debit of any amount passes enforcement without exception (no false `TRANSACTION_LIMIT_EXCEEDED` on fresh deployment)**

---

## Phase 9 — US-WALLET-007: View Available Balance (P0)

**Goal**: Customers can query available balance for any pocket; response includes all balance components; balance reads are permitted on frozen wallets; E07 dependency handled via stub mode.

**Independent Test**: Pocket with `ledger_balance=500` BWP and `held_amount=50` → `GET /v1/wallets/{id}/pockets/BWP/balance` returns `available_balance=450`.

Dependencies: T006–T007 (LedgerModuleContract getBalance), T034 (CurrencyPocketModel), T035 (WalletHoldModel).

- [X] T087 [US7] Implement `wallet-ecosystem/services/api/app/Modules/Wallet/Application/Services/WalletBalanceService.php` with method `getBalance(string $walletId, string $currencyCode, string $correlationId): array` — resolves pocket from repository; calls `LedgerModuleContract::getBalance($pocket->ledger_account_reference)` wrapped in try/catch for `ModuleNotImplementedException`; if exception and `wallet.stub_ledger_balance` config is true returns `ledger_balance=0` with `meta.ledger_unavailable=true`; in production propagates as HTTP 503; computes `held_amount` as SUM of active holds; computes `available_balance = max(0, ledger_balance - held_amount)`; returns `pending_balance = 0` (E11 stub); captures `as_of_timestamp` at query start (UTC, RFC 3339)
- [X] T088 [US7] Implement `GET /v1/wallets/{id}/pockets/{code}/balance` in `WalletPocketController` — requires `wallet.balance.read`; ownership check returns 404 for IDOR; records `$start = microtime(true)` before calling `WalletBalanceService::getBalance()`; emits `MetricsCollectorInterface::histogram('wallet.balance.query.duration', microtime(true) - $start)` after the call regardless of outcome; returns `BalanceResource`; does NOT block on frozen wallet (read is permitted per FR-E06-033)
- [X] T089 [US7] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Resources/BalanceResource.php` — returns all fields from FR-E06-021: `wallet_id`, `currency_code`, `precision`, `ledger_balance`, `held_amount`, `available_balance`, `pending_balance`, `wallet_status`, `as_of_timestamp`
- [X] T090 [US7] Implement `WalletModuleContract::availableBalance()` in `WalletService` — delegates to `WalletBalanceService::getBalance()` and returns `available_balance` integer
- [X] T091 [US7] Implement `WalletModuleContract::pocketExists()` in `WalletService` — delegates to `EloquentCurrencyPocketRepository::findByWalletAndCurrency()`; returns bool
- [X] T092 [US7] Create `wallet-ecosystem/services/api/config/wallet.php` with keys: `reference_format` (null), `expire_holds_schedule` (null), `stub_ledger_balance` (false), `show_pending_meta` (false); set `stub_ledger_balance=true` in `phpunit.xml` env
- [X] T093 [US7] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/WalletBalanceTest.php` — covers US-WALLET-007 scenarios 1–5: available=ledger−held, zero balance no error, unauthenticated 401, IDOR 404, frozen wallet read succeeds with `wallet_status: frozen`
- [X] T094 [US7] Write unit test `wallet-ecosystem/services/api/tests/Unit/Modules/Wallet/WalletBalanceServiceTest.php` — balance formula, max(0,...) never-negative, stub-ledger mode returns 0 with meta flag, production mode propagates exception as 503
- [X] T095 [US7] Write integration test `wallet-ecosystem/services/api/tests/Integration/Modules/Wallet/BalanceIntegrationTest.php` — with real PostgreSQL: verify `ledger_balance=0` before E07 via stub; verify held_amount computed from actual DB rows; verify `as_of_timestamp` is valid RFC 3339 UTC string
- [X] T096 [US7] Emit metrics in: `WalletBalanceService` (`wallet.balance.insufficient` on check fail), `WalletController` (`wallet.idor.blocked` on IDOR block), `WalletProvisioningService` (`wallet.created`), future `WalletService::freeze()` (`wallet.frozen`), future `WalletService::close()` (`wallet.closed`) — add metric stubs now, wire to `MetricsCollectorInterface`

---

## Phase 10 — US-WALLET-008: View Ledger Balance (P0)

**Goal**: The `ledger_balance` field correctly reflects `LedgerModuleContract::getBalance()`; `as_of_timestamp` is always present.

**Independent Test**: Mock `LedgerModuleContract::getBalance()` returning 27500 → balance response shows `ledger_balance=27500`.

Dependencies: T087–T089 (WalletBalanceService + BalanceResource).

- [X] T097 [US8] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/LedgerBalanceTest.php` — covers US-WALLET-008 scenarios 1–5: credits−debits=275 via mock, zero entries=0, response has currency_code/precision/ledger_balance/as_of_timestamp, consistent value under concurrent mock, unauthorized 403/404
- [X] T098 [US8] Write unit test: mock `LedgerModuleContract::getBalance()` returning 27500; assert `WalletBalanceService` returns `ledger_balance=27500` unchanged; assert `as_of_timestamp` is non-null and parseable as RFC 3339
- [X] T099 [US8] Write integration test verifying `WalletModuleContract::availableBalance()` returns correct integer via mock `LedgerModuleContract`; confirms contract method is wired to real `WalletBalanceService`

---

## Phase 11 — US-WALLET-009: View Held Balance (P0)

**Goal**: `held_amount` aggregates all active holds; individual hold records returned in balance response for transparency.

**Independent Test**: Two active holds of 40 and 60 BWP → `held_amount=100`; response includes `holds` array with two records.

Dependencies: T035 (WalletHoldModel), T087 (WalletBalanceService).

- [X] T100 [US9] Extend `WalletBalanceService::getBalance()` to also return a `holds` array — each element has `hold_id`, `amount`, `created_at`, `expires_at`, `description`; only `active` holds are included
- [X] T101 [US9] Update `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Resources/BalanceResource.php` to include `holds` array in response
- [X] T102 [US9] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/HeldBalanceTest.php` — covers US-WALLET-009 scenarios 1–5: sum of two holds=100, released hold reduces held_amount, zero holds=0, response includes holds list with hold_id/amount/description, frozen wallet returns all active holds intact
- [X] T103 [US9] Write unit test for `WalletBalanceService` hold aggregation: 2 active holds + 1 released hold → `held_amount` equals sum of active only; released hold absent from `holds` array

---

## Phase 12 — US-WALLET-011: Calculate Available Balance (P0)

**Goal**: `max(0, ledger_balance − held_amount)` formula is correct and never produces a negative value; concurrency cannot produce negative balance.

**Independent Test**: `ledger_balance=300`, `held_amount=80` → `available_balance=220`; `held_amount=80`, `ledger_balance=80` → `available_balance=0`.

Dependencies: T087 (WalletBalanceService), T095 (integration tests base).

- [X] T104 [US11] Add explicit never-negative assertion in `WalletBalanceService::getBalance()` — after computing `available_balance`, assert `$available >= 0`; throw `\RuntimeException` if violated (constitution Principle 4 defensive guard)
- [X] T105 [US11] Write unit test `wallet-ecosystem/services/api/tests/Unit/Modules/Wallet/BalanceFormulaTest.php` — covers US-WALLET-011 scenarios 1–5: 300−80=220, held≥ledger→0, new pocket→0, frozen wallet formula unchanged, race invariant (mock returns consistent state)
- [X] T106 [US11] Write concurrency test `wallet-ecosystem/services/api/tests/Concurrency/Modules/Wallet/ConcurrentDebitTest.php` — CC-E06-001: two simultaneous `WalletHoldService::place()` calls each for 60 BWP against pocket with `available_balance=100`; assert exactly one succeeds and one throws `InsufficientBalanceException`; final `available_balance=40`; exactly one `wallet_holds` row in DB
- [X] T107 [US11] Verify index on `wallet.wallet_holds(pocket_id, status)` is present in migration T025; confirm `WalletBalanceService` aggregates `held_amount` using `WHERE status = 'active'` on this index
- [X] T108 [US11] Write integration test: with real PostgreSQL, compute `available_balance` before and after inserting a hold record directly in DB; verify balance reflects DB state; verify no stale cache is returned

---

## Phase 13 — US-WALLET-012: Prevent Transaction Exceeding Available Balance (P0)

**Goal**: Every debit attempt is checked atomically via `SELECT FOR UPDATE`; overdrafts are impossible; `WalletHoldService` is the sole hold-creation path.

**Independent Test**: `available_balance=100`, debit 101 → HTTP 422 `INSUFFICIENT_BALANCE`, no ledger entry, no hold in DB.

Dependencies: T040 (WalletHoldServiceContract), T087 (WalletBalanceService), T079 (WalletLimitService).

- [X] T109 [US12] Implement `wallet-ecosystem/services/api/app/Modules/Wallet/Application/Services/WalletHoldService.php` implementing `WalletHoldServiceContract` — `place()` method: BEGIN transaction; `SELECT FOR UPDATE` on `currency_pockets` row; verify wallet status allows debits (throws `WalletNotTransactableException` otherwise); compute `available_balance` within lock scope; verify `amount <= available_balance` (throws `InsufficientBalanceException` otherwise); call `WalletLimitService::enforceLimits()` if limits configured; INSERT `wallet_holds` row; COMMIT; return hold record
- [X] T110 [US12] Implement `WalletHoldService::release(string $holdId, string $correlationId): void` — loads hold; verifies `status = active`; updates to `released`, sets `released_at`; wraps in transaction
- [X] T111 [US12] Add `computeLockedBalance(CurrencyPocketModel $pocket): array` method to `WalletBalanceService` — computes `ledger_balance` and `held_amount` assuming caller already holds `SELECT FOR UPDATE` lock on pocket row; used internally by `WalletHoldService::place()`
- [X] T112 [US12] Bind `WalletHoldServiceContract` to `WalletHoldService` in `WalletServiceProvider::register()`
- [X] T113 [US12] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/BalanceEnforcementTest.php` — covers US-WALLET-012 scenarios 1–5: debit=available_balance succeeds, debit>available→422 no hold placed, zero balance→422, concurrent two×60 against 100→one succeeds one fails, rollback releases hold
- [X] T114 [US12] Write concurrency test `wallet-ecosystem/services/api/tests/Concurrency/Modules/Wallet/ConcurrentHoldTest.php` — CC-E06-002: 10 concurrent `WalletHoldService::place()` calls each for 10 BWP against pocket with `available_balance=50`; assert exactly 5 succeed and 5 throw `InsufficientBalanceException`; final `held_amount=50`
- [X] T115 [US12] Write unit test `wallet-ecosystem/services/api/tests/Unit/Modules/Wallet/WalletHoldServiceTest.php` — `place()` happy path, `InsufficientBalanceException` path, `WalletNotTransactableException` for frozen wallet, `release()` transitions status, double-release attempt throws
- [X] T116 [US12] Write concurrency test `wallet-ecosystem/services/api/tests/Concurrency/Modules/Wallet/ConcurrentKycEventTest.php` — CC-E06-004: same `Kyc.Case.Approved` event delivered 5 times concurrently; exactly one wallet provisioned; idempotency key prevents 4 duplicates
- [X] T117 [US12] Write concurrency test `wallet-ecosystem/services/api/tests/Concurrency/Modules/Wallet/ConcurrentPocketCreationTest.php` — CC-E06-005: 5 concurrent `POST /v1/wallets/{id}/pockets` for same currency; exactly one succeeds (201), rest return 409; DB has exactly one pocket for that currency
- [X] T118 [US12] Add limit enforcement test to `WalletLimitsTest.php`: set `daily_transaction_amount=50000`; attempt hold for 50001 → `TRANSACTION_LIMIT_EXCEEDED`; hold for 50000 → succeeds

---

## Phase 14 — US-WALLET-013: Freeze Wallet (P0)

**Goal**: Authorized Compliance Officers can freeze active wallets; audit event written; `WalletModuleContract::isActive()` returns false after freeze.

**Independent Test**: Freeze active wallet → `status=frozen`, status-history entry recorded, `Wallet.Frozen` in outbox, `platform.audit_events` has `action=wallet.frozen`.

Dependencies: T070 (WalletService::transitionStatus), T038 (WalletStateMachine).

- [X] T119 [US13] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Events/WalletFrozenEvent.php` — fields: `event_id` (UUID v7), `aggregate_id` (= walletId), `walletId`, `actorId`, `reason`, `correlationId`, `occurredAt` (UTC ISO-8601), `schema_version` (= `'1'`); event name `Wallet.Frozen`
- [X] T120 [US13] Implement `WalletService::freeze(string $walletId, string $reason, string $actorId, string $correlationId): WalletModel` — delegates to `transitionStatus()` with `Active→Frozen`; publishes `Wallet.Frozen` event; writes `AuditService::record()` with action `wallet.frozen`; enforces `Idempotency-Key`; emits `wallet.frozen` metric
- [X] T121 [US13] Implement `POST /v1/wallets/{id}/freeze` in `WalletController` — requires `wallet.freeze` permission; enforces `Idempotency-Key`; returns updated `WalletResource`
- [X] T122 [US13] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Requests/FreezeWalletRequest.php` — validates `reason` (required string, max 500)
- [X] T123 [US13] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/FreezeWalletTest.php` — covers US-WALLET-013 scenarios 1–5: freeze active→frozen + history + event + audit, idempotent same-key replay, unauthorized Customer 403, closed wallet 422, isActive returns false after freeze
- [X] T124 [US13] Write concurrency test `wallet-ecosystem/services/api/tests/Concurrency/Modules/Wallet/FreezeRaceTest.php` — CC-E06-003: simultaneous freeze + debit; assert debit either completes before freeze commits or is rejected with `WALLET_FROZEN`; no partial state possible
- [X] T125 [US13] Verify `WalletModuleContract::isActive()` unit test: active→frozen cycle, `isActive()` returns `false` after freeze transition committed

---

## Phase 15 — US-WALLET-014: Unfreeze Wallet (P0)

**Goal**: Frozen wallets can be unfrozen back to active; `WalletModuleContract::isActive()` returns true after unfreeze.

**Independent Test**: Unfreeze frozen wallet → `status=active`, audit event written, subsequent debit succeeds.

Dependencies: T119–T122 (freeze implementation), T038 (WalletStateMachine).

- [X] T126 [US14] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Events/WalletUnfrozenEvent.php` — fields: `event_id` (UUID v7), `aggregate_id` (= walletId), `walletId`, `actorId`, `reason`, `correlationId`, `occurredAt` (UTC ISO-8601), `schema_version` (= `'1'`); event name `Wallet.Unfrozen`
- [X] T127 [US14] Implement `WalletService::unfreeze(string $walletId, string $reason, string $actorId, string $correlationId): WalletModel` — delegates to `transitionStatus()` with `Frozen→Active`; publishes `Wallet.Unfrozen` event; writes audit record `wallet.unfrozen`
- [X] T128 [US14] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Requests/UnfreezeWalletRequest.php` — validates `reason` (required, string, max 500); implement `POST /v1/wallets/{id}/unfreeze` in `WalletController` — requires `wallet.unfreeze` permission; enforces `Idempotency-Key`; returns updated `WalletResource`
- [X] T129 [US14] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/UnfreezeWalletTest.php` — covers US-WALLET-014 scenarios 1–5: frozen→active + history + event + audit, active wallet unfreeze→422, unauthorized 403, isActive returns true after unfreeze, full freeze/unfreeze history has 2 entries in chronological order
- [X] T130 [US14] Write unit test asserting `WalletStateMachine` allows `Frozen→Active` but throws on `Active→Active` unfreeze attempt; also covers `FreezeWalletRequest` validation

---

## Phase 16 — US-WALLET-016: Prevent Transactions on Frozen Wallet (P0)

**Goal**: Frozen, suspended, and restricted wallets block debits at `WalletHoldService::place()` before any balance check; balance reads remain unblocked.

**Independent Test**: Frozen wallet → debit, credit, and hold placement all return `WALLET_FROZEN`; balance read returns HTTP 200.

Dependencies: T109–T110 (WalletHoldService), T087 (WalletBalanceService).

- [X] T131 [US16] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Exceptions/WalletFrozenException.php` extending `WalletNotTransactableException`; map to HTTP 422 `WALLET_FROZEN` in exception handler
- [X] T132 [US16] Verify `WalletHoldService::place()` status check (T109) throws `WalletFrozenException` for `Frozen` wallets, `WalletNotTransactableException` for `Suspended`/outbound `Restricted`; add explicit status-to-exception mapping
- [X] T133 [US16] Write unit test `wallet-ecosystem/services/api/tests/Unit/Modules/Wallet/FrozenWalletBlockingTest.php` — covers US-WALLET-016 scenarios 1–5: frozen blocks debit (WalletFrozenException), frozen blocks credit attempt, frozen blocks hold placement, frozen permits balance read (no exception), suspended/restricted return WalletNotTransactableException
- [X] T134 [US16] Write integration test confirming `GET /v1/wallets/{id}/pockets/{code}/balance` returns HTTP 200 with balance data on a frozen wallet; verify no status gate in `WalletPocketController` for read endpoints
- [X] T135 [US16] Write concurrency test `wallet-ecosystem/services/api/tests/Concurrency/Modules/Wallet/FreezeConcurrentDebitTest.php` — issue 20 concurrent freeze+debit request pairs; assert no run results in both completing after freeze committed; no inconsistent state possible

---

## Phase 17 — US-WALLET-017: Maintain Wallet Status History (P0)

**Goal**: Complete, immutable history of all lifecycle transitions is available via paginated API.

**Independent Test**: Create→activate→freeze→unfreeze; query `GET /v1/wallets/{id}/status-history` → 4 entries in ascending `occurred_at` order.

Dependencies: T071 (WalletStatusHistoryRepository), T037 (WalletStatusHistoryModel), T070 (transitionStatus).

- [X] T136 [US17] Implement `GET /v1/wallets/{id}/status-history` in `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Controllers/WalletStatusHistoryController.php` — requires `wallet.read`; ownership check; cursor-based pagination (default 20, max 100, ascending `occurred_at`); returns `WalletStatusHistoryResource` with pagination metadata
- [X] T137 [US17] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Resources/WalletStatusHistoryResource.php` — returns `id`, `from_status`, `to_status`, `reason`, `actor_id`, `occurred_at` per entry; wraps in envelope with `pagination.next_cursor` and `pagination.has_more`
- [X] T138 [US17] Implement cursor pagination in `WalletStatusHistoryRepository::paginateByWalletId(string $walletId, ?string $cursor, int $limit): array` — encode cursor as base64(`occurred_at|id`); decode and apply WHERE clause; return items + `next_cursor` + `has_more`
- [X] T139 [US17] Write unit test `wallet-ecosystem/services/api/tests/Unit/Modules/Wallet/WalletStatusHistoryImmutabilityTest.php` — assert `WalletStatusHistoryModel::save()` on an existing model throws; assert `WalletStatusHistoryRepository` has no update/delete methods; verify append-only constraint enforced at repository layer
- [X] T140 [US17] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/WalletStatusHistoryTest.php` — covers US-WALLET-017 scenarios 1–5: 4-entry full lifecycle, entry immutable after write, actor_id recorded correctly, cursor pagination returns entries in order, IDOR 404
- [X] T141 [US17] Write integration test: after full create→active→freeze→unfreeze lifecycle, assert `wallet_status_history` rows in DB are never modified; row count equals transition count; `from_status=null` on initial `pending` entry; entries are in chronological order

---

## Phase 18 — US-WALLET-010: View Pending Balance (P1)

**Goal**: `pending_balance` field reserved in API returning `0` until E11 delivers `TransactionServiceContract`; field MUST NOT be omitted.

**Independent Test**: Balance response always includes `pending_balance: 0`; field survives serialization.

Dependencies: T089 (BalanceResource), T087 (WalletBalanceService).

- [X] T142 [US10] Confirm `BalanceResource` includes `pending_balance: 0` explicitly (not omitted when zero); add assertion that the key exists in response even when its value is 0
- [X] T143 [US10] Add `meta.pending_balance_note` to balance response when `wallet.show_pending_meta` config is true: `"Pending balance reserved for E11 TransactionServiceContract integration; returns 0 until E11 is implemented."`; config default is false
- [X] T144 [US10] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/PendingBalanceTest.php` — covers US-WALLET-010 scenarios: `pending_balance=0` pre-E11, field present in all balance responses, field survives zero-value serialization, unauthorized 401/404
- [X] T145 [US10] Confirm `wallet.show_pending_meta` config key is present in `wallet-ecosystem/services/api/config/wallet.php` (T092); add comment: reserved for E11 integration

---

## Phase 19 — US-WALLET-015: Close Wallet (P1)

**Goal**: Wallets with zero balance across all pockets can be permanently closed; pockets transition to `disabled`; closure is idempotent.

**Independent Test**: Close zero-balance wallet → `status=closed`, all pockets `disabled`, subsequent debit → `WALLET_CLOSED`; close wallet with balance → 422 `WALLET_HAS_BALANCE`.

Dependencies: T070 (WalletService::transitionStatus), T087 (WalletBalanceService for zero-balance check), T038 (WalletStateMachine).

- [X] T146 [US15] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Events/WalletClosedEvent.php` — fields: `event_id` (UUID v7), `aggregate_id` (= walletId), `walletId`, `actorId`, `reason`, `correlationId`, `occurredAt` (UTC ISO-8601), `schema_version` (= `'1'`); event name `Wallet.Closed`
- [X] T147 [US15] Implement `WalletService::close(string $walletId, string $reason, string $actorId, string $correlationId): WalletModel` — verifies all pockets have `available_balance=0` and `held_amount=0` (throws `WalletHasBalanceException`; TBD-E06-003 admin override deferred); transitions wallet to `closed` via `WalletStateMachine`; transitions all `active` pockets to `disabled`; publishes `Wallet.Closed` event; writes audit record `wallet.closed`; emits `wallet.closed` metric; idempotent: already-closed wallet returns existing record
- [X] T148 [US15] Implement `POST /v1/wallets/{id}/close` in `WalletController` — requires `wallet.close` permission; enforces `Idempotency-Key`; returns updated `WalletResource`
- [X] T149 [US15] Create `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Requests/CloseWalletRequest.php` — validates `reason` (required, max 500)
- [X] T150 [US15] Map `WalletHasBalanceException` to HTTP 422 `WALLET_HAS_BALANCE` in exception handler; map `WalletFrozenException` (T131) to HTTP 422 `WALLET_FROZEN`
- [X] T151 [US15] Write feature test `wallet-ecosystem/services/api/tests/Feature/Api/V1/Wallets/CloseWalletTest.php` — covers US-WALLET-015 scenarios 1–5: zero-balance close + pockets disabled + event + history, non-zero balance 422, closed wallet debit 422, idempotent second close 200, isActive false after close
- [X] T152 [US15] Write unit test for `WalletStateMachine` close paths: permits `Pending→Closed`, `Active→Closed`, `Frozen→Closed`, `Suspended→Closed`, `Restricted→Closed`; `Closed→Closed` returns idempotent (not an exception)

---

## Phase 20 — Polish & Cross-Cutting Concerns

Goal: Architecture integrity test, hold expiry command, security sweep, and final validation.

Dependencies: All prior phases complete.

- [X] T153 Create `wallet-ecosystem/services/api/app/Modules/Wallet/Domain/Events/WalletHoldExpiredEvent.php` — fields: `event_id` (UUID v7), `aggregate_id` (= walletId, resolved through pocket → wallet), `walletId`, `holdId`, `pocketId`, `amount`, `actorId` (= `'system'`), `expiredAt`, `correlationId`, `occurredAt` (UTC ISO-8601), `schema_version` (= `'1'`); event name `Wallet.Hold.Expired`
- [X] T154 Implement `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Console/Commands/ExpireWalletHoldsCommand.php` (Artisan command `wallet:expire-holds`) — queries `wallet.wallet_holds WHERE expires_at <= NOW() AND status = 'active'` using `SELECT FOR UPDATE SKIP LOCKED` for safe concurrency; for each record: wraps update + outbox publish in a transaction; sets `status=expired`, records `expired_at`, generates `correlation_id`, publishes `Wallet.Hold.Expired`; logs count; idempotent re-runs produce no double-expiry
- [X] T155 Register `wallet:expire-holds` in `WalletServiceProvider::boot()` with conditional cron scheduling from `wallet.expire_holds_schedule` config (TBD-E06-004) using `->everyFiveMinutes()` default; read actual schedule from `wallet.expire_holds_schedule` config key (TBD-E06-004); log TBD status if config is null
- [X] T156 Write feature test for `wallet:expire-holds`: seed active hold with `expires_at` in the past; run command; assert `status=expired`, `expired_at` set, outbox has `Wallet.Hold.Expired`; re-run is idempotent
- [X] T157 [P] Write architecture integrity test `wallet-ecosystem/services/api/tests/Architecture/Modules/WalletBoundaryTest.php` — using `pest-arch` `arch()` helper: assert no class outside `App\Modules\Wallet` directly imports `App\Modules\Wallet\Infrastructure\Models\*`; assert cross-module usage goes through `WalletModuleContract`; assert `WalletHoldService` is not referenced outside `App\Modules\Wallet` (FR-E06-049)
- [X] T158 [P] Write security test `wallet-ecosystem/services/api/tests/Security/Modules/Wallet/WalletIdorTest.php` — for all 13 endpoints: Customer A token + Customer B's wallet_id → HTTP 404; verify no wallet data leakage in error response body
- [X] T159 [P] Write security test `wallet-ecosystem/services/api/tests/Security/Modules/Wallet/WalletPermissionTest.php` — for each of the 10 permissions: verify token without required permission returns HTTP 403; verify Customer cannot call `wallet.freeze`, `wallet.unfreeze`, `wallet.close`, `wallet.status.manage` endpoints
- [X] T160 [P] Write security test `wallet-ecosystem/services/api/tests/Security/Modules/Wallet/WalletIdempotencyReplayTest.php` — same `Idempotency-Key` on `POST /v1/wallets`, `POST /{id}/freeze`, `PUT /{id}/limits`; assert all replay responses match original body; assert no duplicate DB rows
- [X] T161 Run all 12 validation scenarios in `specs/006-wallet-management/quickstart.md`; record any path or command delta; update `quickstart.md` if any command changed
- [X] T162 [P] Update `WalletServiceProvider::register()` to bind all five `WalletModuleContract` methods to their concrete implementations in `WalletService`, `WalletPocketService`, and `WalletBalanceService`; remove `NullWalletModule` as default binding (replace with real implementation)
- [X] T163 [P] Write integration test that a DB rollback mid-mutation also rolls back the outbox event row — confirm `OutboxEventPublisher` is called inside the transaction boundary for all 9 event types
- [X] T164 Update `wallet-ecosystem/services/api/app/Modules/Wallet/Infrastructure/Http/Controllers/WalletController.php` to wire `WalletService::freeze()`, `unfreeze()`, and `close()` to their respective endpoints (complete the routing wired in T003)
- [X] T165 [P] Verify PHPStan level-8 compliance for all classes in `app/Modules/Wallet/`; add wallet module to `phpstan.neon` scan paths; fix any type errors
- [X] T166 Update `specs/006-wallet-management/checklists/requirements.md` — mark all 49 FRs and 15 SRs covered; add phase/task IDs next to each FR entry

---

## Dependencies

```
Phase 1 (T001–T005)
    └── Phase 2 (T006–T040) — all foundational prerequisites
            ├── Phase 3 (T041–T050) — US1: wallet creation + provisioning
            │       ├── Phase 4 (T051–T054) — US2: wallet_reference generation
            │       ├── Phase 5 (T055–T060) — US3: ownership + IDOR + contract
            │       ├── Phase 6 (T061–T068) — US4: currency pockets
            │       └── Phase 7 (T069–T077) — US5: lifecycle transitions
            │               ├── Phase 8 (T078–T086)  — US6: wallet limits
            │               ├── Phase 14 (T119–T125) — US13: freeze
            │               │       └── Phase 15 (T126–T130) — US14: unfreeze
            │               └── Phase 17 (T136–T141) — US17: status history
            ├── Phase 9 (T087–T096) — US7: balance service + endpoint
            │       ├── Phase 10 (T097–T099) — US8: ledger balance (tests)
            │       ├── Phase 11 (T100–T103) — US9: held balance
            │       ├── Phase 12 (T104–T108) — US11: formula + concurrency
            │       ├── Phase 18 (T142–T145) — US10: pending balance P1
            │       └── Phase 13 (T109–T118) — US12: WalletHoldService + enforcement
            │               └── Phase 16 (T131–T135) — US16: frozen enforcement
            └── Phase 19 (T146–T152) — US15: close wallet P1 (after Phase 7 + Phase 9)
                    └── Phase 20 (T153–T166) — Polish (all phases complete)
```

### Parallel Opportunities per Phase

**Phase 2**: T010–T013 (enums) | T014–T021 (exceptions) | T032–T037 (models) — all parallelizable within groups.

**Phase 3**: T041–T042 (events) are parallel; T043 depends on T041; T046–T048 (controller/request/resource) are parallel after T043.

**Phase 7**: T069 (event) | T071 (repo) | T073 (request) — parallel; T070 depends on T038+T069+T071.

**Phase 9**: T087–T089 are sequential core; T090–T096 are parallel after T087.

**Phase 20**: T157–T160 (all test writing) are fully parallel.

---

## Implementation Strategy

**MVP Scope (Phases 1–5)**: Wallet provisioning, UUID v7 reference, ownership enforcement, IDOR prevention. Delivers a testable wallet foundation for downstream E07–E09 epics.

**Core Completion (Phases 1–17 + 20)**: All 15 P0 stories complete including freeze/unfreeze, balance service with E07 stub, hold enforcement with concurrency safety, and architecture/security tests.

**Full Delivery (Phases 1–20)**: All 17 stories including P1 pending balance stub and wallet closure.

---

## TBD Governance

All TBD-seeded values tracked per constitution Principle 2. No task invents a TBD value.

| TBD ID | Config Key | Seeded Value | Resolves In |
|---|---|---|---|
| TBD-E06-001 | USDT precision | `NULL` in `wallet.currencies` (T028) | Business decision |
| TBD-E06-002 | Step-up MFA for freeze/unfreeze/close | No config key — lives in auth config; skip implementation until resolved | Security/auth team decision |
| TBD-E06-003 | Admin close-with-balance override | Not implemented (closure strictly requires zero balance) | E06 ADR or future epic |
| TBD-E06-004 | `wallet.expire_holds_schedule` | `is_tbd=true` in `platform.configuration_values` (T030) | Operations decision |
| TBD-E06-005 | Rate limits for wallet endpoints | No wallet config key — lives in platform rate-limit config; not applied until resolved | Platform/ops team decision |
| TBD-E06-006 | Wallet creation SLA target | No config value — latency is measured (T096 `wallet.created` counter) but no alert threshold until resolved | Business/SRE decision |
| TBD-E06-007 | `wallet.reference_format` | `is_tbd=true` in `platform.configuration_values` (T030) | Business/brand decision |
| TBD-E06-008 | `wallet.pocket_creation_strategy` | `is_tbd=true`, default `'lazy'` in `platform.configuration_values` (T030) | Business decision; lazy is safe default |

---

## Definition of Done

- [X] All 166 tasks marked `[x]`
- [X] `php artisan test tests/Unit/Modules/Wallet/ tests/Feature/Api/V1/Wallets/ tests/Integration/Modules/Wallet/` — all green
- [X] `php artisan test tests/Security/Modules/Wallet/` — all green
- [X] `php artisan test tests/Concurrency/Modules/Wallet/` — all green (real PostgreSQL required)
- [X] `php artisan test tests/Architecture/Modules/WalletBoundaryTest.php` — green
- [X] All 9 outbox events published within their DB transactions (T163 verified)
- [X] All 4 TBD config keys seeded with `is_tbd=true`; no TBD value hard-coded
- [X] No mutable balance field exists on any Eloquent model
- [X] `WalletModuleContract` fully wired to real implementations; `NullWalletModule` remains available for other modules pre-E06
- [X] `checklists/requirements.md` fully checked (T166 complete)
