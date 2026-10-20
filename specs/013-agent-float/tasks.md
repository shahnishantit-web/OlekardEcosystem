# Tasks: E13 — Agent Float Management

**Input**: `specs/013-agent-float/` (spec.md, plan.md, data-model.md, contracts/api.yaml, research.md)

**Base app path**: `wallet-ecosystem/services/api/app/Modules/AgentFloat/`
**Base tests path**: `wallet-ecosystem/services/api/tests/`

---

## Phase 1: Setup (Module Scaffolding)

**Purpose**: Create module skeleton and service provider so all phases can register their components.

- [X] T001 Create `AgentFloatServiceProvider` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Providers/AgentFloatServiceProvider.php` (register bindings, routes, listeners)
- [X] T002 Register `AgentFloatServiceProvider` in `wallet-ecosystem/services/api/config/app.php` providers array

**Checkpoint**: Module is loadable; `php artisan route:list` shows no errors.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Schema migrations, domain primitives, and Eloquent models that all user stories depend on. No user story work can begin until this phase is complete.

### Migrations

- [X] T003 Create migration `2026_09_26_001000_add_wallet_type_to_wallets.php` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Migrations/` (wallet connection): add `wallet_type VARCHAR(20) NOT NULL DEFAULT 'customer'`, check constraint `('customer','agent_float')`, drop `wallets_owner_id_active_unique`, create `wallets_owner_wallettype_active_unique (owner_id, wallet_type) WHERE status <> 'closed'`
- [X] T004 Create migration `2026_09_26_001100_create_agent_float_wallets.php` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Migrations/` (platform connection): `agent.float_wallets` table per data-model.md — `id`, `agent_id` (UNIQUE FK → `agent.agents`), `wallet_id` (NO database FK — cross-schema soft reference; `wallet.wallets` is on wallet connection, `agent.*` is on platform connection; FK enforcement is application-level only), `ledger_account_reference`, `created_at`, `updated_at`; indexes on `wallet_id`
- [X] T005 Create migration `2026_09_26_001200_create_agent_float_transactions.php` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Migrations/` (platform connection): `agent.float_transactions` table per data-model.md — all columns, check constraints on `type`, `direction`, `related_party_type`, `status`; unique on `idempotency_key`, `reference`; composite index `(agent_id, created_at DESC)`
- [X] T006 Create migration `2026_09_26_001300_create_agent_float_limits.php` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Migrations/` (platform connection): `agent.float_limits` table per data-model.md — `id`, `agent_id` (UNIQUE FK), `currency_code`, `max_balance`, `max_balance_is_tbd`, `per_transaction_limit`, `per_tx_is_tbd`, `daily_volume_limit`, `daily_vol_is_tbd`, `low_float_threshold`, `low_threshold_is_tbd`, `low_float_alerted`, `effective_from`, `created_by`, `updated_by`, `created_at`, `updated_at`
- [X] T007 Create migration `2026_09_26_001400_seed_agent_float_permissions.php` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Migrations/` (platform connection): seed 10 permissions from plan.md (`agent_float.wallet.create`, `agent_float.wallet.view`, `agent_float.wallet.view_any`, `agent_float.wallet.fund`, `agent_float.limits.manage`, `agent_float.limits.view`, `agent_float.cash_in`, `agent_float.cash_out`, `agent_float.transactions.view`, `agent_float.transactions.view_any`)

### Eloquent Models

- [X] T008 [P] Create `AgentFloatWalletModel` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Models/AgentFloatWalletModel.php` (connection: platform, table: `agent.float_wallets`, fillable, casts, `belongsTo` agent)
- [X] T009 [P] Create `FloatTransactionModel` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Models/FloatTransactionModel.php` (connection: platform, table: `agent.float_transactions`, fillable, casts for type/direction/related_party_type/status, `belongsTo` agent)
- [X] T010 [P] Create `FloatLimitModel` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Models/FloatLimitModel.php` (connection: platform, table: `agent.float_limits`, fillable, casts, `belongsTo` agent)

### Domain Primitives

- [X] T011 [P] Create `FloatTransactionType` enum in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Domain/Enums/FloatTransactionType.php` (cases: `Funding = 'funding'`, `CashIn = 'cash_in'`, `CashOut = 'cash_out'`)
- [X] T012 [P] Create `FloatTransactionDirection` enum in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Domain/Enums/FloatTransactionDirection.php` (cases: `Debit = 'debit'`, `Credit = 'credit'`)
- [X] T013 [P] Create all domain exception classes in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Domain/Exceptions/`: `FloatWalletAlreadyExistsException`, `FloatWalletNotFoundException`, `FloatWalletNotActiveException`, `InsufficientFloatException`, `FloatLimitExceededException`, `FloatTransactionLimitExceededException`, `FloatDailyLimitExceededException`, `FloatFundingAccountNotConfiguredException`, `AgentNotEligibleForFloatException` (one file each; each has a static named constructor)

**Checkpoint**: Migrations run cleanly; models resolve; enums/exceptions exist.

---

## Phase 3: US1 — Agent Float Wallet Provisioning (Priority: P0) 🎯 MVP

**Goal**: Admin can create exactly one float wallet per approved agent; idempotent on replay.

**Independent Test**: `POST /v1/agents/{id}/float-wallet` → `201` with `balance=0, status=active`. Replay returns `200` with same wallet. Second distinct request returns `409`.

- [X] T014 [US1] Implement `FloatWalletProvisioningService` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Application/Services/FloatWalletProvisioningService.php`: idempotency check, agent approved/active validation, uniqueness check on `agent.float_wallets`, create `wallet.wallets` record (wallet_type='agent_float', owner_id=agent.user_id), call `LedgerModuleContract::registerAccount('agent_float:{walletId}', 'asset', currencyCode, agentId, 'agent_float')`, create `agent.float_wallets` record, publish `FloatWalletCreatedEvent` to outbox, record idempotency response
- [X] T015 [P] [US1] Create `FloatWalletCreatedEvent` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Domain/Events/FloatWalletCreatedEvent.php` (implements `IntegrationEventInterface`; fields: agentId, walletId, ledgerAccountReference, occurredAt, correlationId)
- [X] T016 [P] [US1] Create `CreateFloatWalletRequest` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Requests/CreateFloatWalletRequest.php` (validates `currency_code` required string max:10, authorize agent_float.wallet.create permission)
- [X] T017 [US1] Create `FloatWalletResource` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Resources/FloatWalletResource.php` (maps wallet.wallets + balance from LedgerService + low_float_alert from float_limits; fields: id, agent_id, wallet_reference, status, balance, currency_code, low_float_alert, created_at)
- [X] T018 [US1] Create `AgentFloatWalletController` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Controllers/AgentFloatWalletController.php`: `create(CreateFloatWalletRequest, agentId)` method — thin controller, delegates to `FloatWalletProvisioningService`, returns 201/200/409/422 per contracts/api.yaml
- [X] T019 [US1] Register route `POST /v1/agents/{agentId}/float-wallet` in `AgentFloatServiceProvider` (middleware: api, auth:sanctum)
- [X] T020 [US1] Write feature test `FloatWalletProvisioningTest` in `wallet-ecosystem/services/api/tests/Feature/Modules/AgentFloat/FloatWalletProvisioningTest.php`: covers happy path (201), idempotent replay (200), duplicate wallet (409), ineligible agent (422), unauthorized (401/403)

**Checkpoint**: `POST /v1/agents/{id}/float-wallet` works; `FloatWalletProvisioningTest` passes.

---

## Phase 4: US2 — Float Wallet Funding (Priority: P0)

**Goal**: Admin can credit an agent's float wallet; double-entry ledger journal posted; idempotent.

**Independent Test**: Fund BWP 5,000 → `201` with `type=funding`; GET balance returns `500000`; replay returns `200` with no double credit.

- [X] T021 [US2] Implement `FloatFundingService` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Application/Services/FloatFundingService.php`: idempotency check, validate float wallet active, read platform funding account ref from `ConfigurationServiceInterface::get('ledger.agent_float_funding_account.{currency}')` (throw `FloatFundingAccountNotConfiguredException` if null — TBD-E13-001), validate amount > 0, post `LedgerModuleContract::postEntry([debit floatRef, credit platformRef])`, insert `agent.float_transactions` record, publish `FloatFundedEvent` to outbox via `EventPublisherInterface`, call `FloatAlertService::checkAndAlert()` post-commit (recovery path — balance may have risen above threshold), record audit via `AuditServiceInterface`, call `$this->metrics->increment('agent_float.fund.completed', ['currency' => ...])`, record idempotency response
- [X] T022 [P] [US2] Create `FloatFundedEvent` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Domain/Events/FloatFundedEvent.php` (implements `IntegrationEventInterface`; fields: agentId, walletId, amount, currencyCode, journalId, adminId, occurredAt, correlationId)
- [X] T023 [P] [US2] Create `FundFloatWalletRequest` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Requests/FundFloatWalletRequest.php` (validates: amount integer min:1, currency_code required; authorize agent_float.wallet.fund)
- [X] T024 [P] [US2] Create `FloatTransactionResource` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Resources/FloatTransactionResource.php` (maps FloatTransactionModel → id, type, amount, currency_code, direction, related_party_id, related_party_type, reference, status, created_at)
- [X] T025 [US2] Create `AgentFloatFundingController` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Controllers/AgentFloatFundingController.php`: `fund(FundFloatWalletRequest, agentId)` — delegates to `FloatFundingService`, returns 201/200/422/503
- [X] T026 [US2] Register route `POST /v1/agents/{agentId}/float-wallet/fund` in `AgentFloatServiceProvider`
- [X] T027 [US2] Write feature test `FloatFundingTest` in `wallet-ecosystem/services/api/tests/Feature/Modules/AgentFloat/FloatFundingTest.php`: happy path (201), idempotent replay (200), invalid amount (422), wallet not found (404), funding account not configured (503), unauthorized (401/403)

**Checkpoint**: Funding endpoint works; ledger journal verifiable; `FloatFundingTest` passes.

---

## Phase 5: US3 — Float Balance and Status View (Priority: P0)

**Goal**: Agent or admin can view current settled balance, status, and low-float alert state.

**Independent Test**: After funding BWP 5,000, GET returns `balance=500000`, `status=active`, `low_float_alert=false`.

- [X] T028 [US3] Add `show(agentId)` method to `AgentFloatWalletController` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Controllers/AgentFloatWalletController.php`: resolve `agent.float_wallets` → lookup `wallet.wallets` status → call `LedgerModuleContract::getBalance()` → check low_float_threshold from `FloatLimitModel` → return `FloatWalletResource`
- [X] T029 [US3] Register route `GET /v1/agents/{agentId}/float-wallet` in `AgentFloatServiceProvider` (auth: own-agent or admin with agent_float.wallet.view_any)
- [X] T030 [US3] Write feature test `FloatBalanceViewTest` in `wallet-ecosystem/services/api/tests/Feature/Modules/AgentFloat/FloatBalanceViewTest.php`: funded wallet shows correct balance, depleted wallet shows `low_float_alert=true` when below threshold, missing wallet returns 404, unauthorized access returns 403

**Checkpoint**: Balance view returns live settled balance from ledger.

---

## Phase 6: US4 — Cash-In Using Agent Float (Priority: P0)

**Goal**: Agent debits their float and credits customer wallet atomically; overdraft prevented.

**Independent Test**: Agent BWP 1,000 float + BWP 200 cash-in → agent float 800, customer wallet +200; insufficient float returns 422.

- [X] T031 [US4] Implement `FloatCashInService` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Application/Services/FloatCashInService.php`: idempotency fast-path, validate agent active + float wallet active, validate customer is agent-linked (check `agent_customer_relationships`), validate customer wallet active/not-frozen, inject `WalletModuleContract` and call `WalletModuleContract::getPocketLedgerAccountRef(customerWalletId, currencyCode)` to obtain `customerPocketRef` (throw if null), begin platform-connection transaction, `SELECT * FROM agent.float_wallets WHERE id = ? FOR UPDATE`, `LedgerModuleContract::getBalance()` check `>= amount` (throw `InsufficientFloatException` if not), `LedgerModuleContract::postEntry([credit floatRef, debit customerPocketRef])`, insert `agent.float_transactions`, commit, delegate to `FloatAlertService::checkAndAlert()` post-commit, publish `FloatCashInEvent` to outbox via `EventPublisherInterface`, audit via `AuditServiceInterface`, call `$this->metrics->increment('agent_float.cash_in.completed', ['currency' => ...])`, record idempotency response
- [X] T032 [P] [US4] Create `FloatCashInEvent` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Domain/Events/FloatCashInEvent.php` (implements `IntegrationEventInterface`; fields: agentId, customerId, walletId, amount, currencyCode, journalId, occurredAt, correlationId)
- [X] T033 [P] [US4] Create `CashInRequest` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Requests/CashInRequest.php` (validates: amount integer min:1, currency_code required; in `authorize()`: check `agent_float.cash_in` permission AND assert `$this->route('agentId') === auth()->user()->agent_id` — FR-E13-024 ownership check; agents may only operate their own float wallet, not another agent's)
- [X] T034 [US4] Create `AgentFloatCashInController` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Controllers/AgentFloatCashInController.php`: `cashIn(CashInRequest, agentId, customerId)` — delegates to `FloatCashInService`, returns 201/200/422
- [X] T035 [US4] Register route `POST /v1/agents/{agentId}/customers/{customerId}/cash-in` in `AgentFloatServiceProvider`
- [X] T036 [US4] Write feature test `CashInTest` in `wallet-ecosystem/services/api/tests/Feature/Modules/AgentFloat/CashInTest.php`: happy path, insufficient float (422), agent suspended (422), customer wallet frozen (422), unlinked customer (403), idempotent replay (200)
- [X] T037 [US4] Write concurrency test `CashInConcurrencyTest` in `wallet-ecosystem/services/api/tests/Concurrency/Modules/AgentFloat/CashInConcurrencyTest.php`: two concurrent BWP 150 cash-ins against BWP 200 float → exactly one succeeds, one gets INSUFFICIENT_FLOAT; final balance = BWP 50 (not negative)

**Checkpoint**: Cash-in works; overdraft prevention serializes concurrent requests; tests pass.

---

## Phase 7: US5 — Cash-Out Using Agent Float (Priority: P0)

**Goal**: Agent credits their float from customer wallet debit; customer insufficient balance prevented.

**Independent Test**: Agent BWP 500 float, customer BWP 300 wallet + BWP 100 cash-out → agent float 600, customer wallet 200; insufficient customer balance returns 422.

- [X] T038 [US5] Implement `FloatCashOutService` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Application/Services/FloatCashOutService.php`: idempotency fast-path, validate agent active + float wallet active, validate customer linked, inject `WalletModuleContract` and call `getPocketLedgerAccountRef(customerWalletId, currencyCode)` to obtain `customerPocketRef`, begin platform-connection transaction, lock `agent.float_wallets FOR UPDATE` (consistent locking pattern), check customer balance >= amount via `LedgerModuleContract::getBalance(customerPocketRef)` (throw `InsufficientCustomerBalanceException`), `LedgerModuleContract::postEntry([credit customerPocketRef, debit floatRef])`, insert `agent.float_transactions`, commit, call `FloatAlertService::checkAndAlert()` post-commit (G1 recovery path — cash-out increases float, may clear a previous low-float alert), publish `FloatCashOutEvent` to outbox via `EventPublisherInterface`, audit via `AuditServiceInterface`, call `$this->metrics->increment('agent_float.cash_out.completed', ['currency' => ...])`, record idempotency response
- [X] T039 [P] [US5] Create `FloatCashOutEvent` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Domain/Events/FloatCashOutEvent.php` (implements `IntegrationEventInterface`)
- [X] T040 [P] [US5] Add `InsufficientCustomerBalanceException` to `wallet-ecosystem/services/api/app/Modules/AgentFloat/Domain/Exceptions/InsufficientCustomerBalanceException.php`
- [X] T041 [P] [US5] Create `CashOutRequest` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Requests/CashOutRequest.php` (validates: amount integer min:1, currency_code; in `authorize()`: check `agent_float.cash_out` permission AND assert `$this->route('agentId') === auth()->user()->agent_id` — FR-E13-024 ownership check; same pattern as CashInRequest T033)
- [X] T042 [US5] Create `AgentFloatCashOutController` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Controllers/AgentFloatCashOutController.php`: `cashOut(CashOutRequest, agentId, customerId)`
- [X] T043 [US5] Register route `POST /v1/agents/{agentId}/customers/{customerId}/cash-out` in `AgentFloatServiceProvider`
- [X] T044 [US5] Write feature test `CashOutTest` in `wallet-ecosystem/services/api/tests/Feature/Modules/AgentFloat/CashOutTest.php`: happy path, insufficient customer balance (422), agent suspended (422), idempotent replay (200), unlinked customer (403)
- [X] T045 [US5] Write concurrency test `CashOutConcurrencyTest` in `wallet-ecosystem/services/api/tests/Concurrency/Modules/AgentFloat/CashOutConcurrencyTest.php`: two concurrent cash-outs that together exceed customer balance → one succeeds, one gets INSUFFICIENT_CUSTOMER_BALANCE

**Checkpoint**: Cash-out works; customer balance protection enforced; tests pass.

---

## Phase 8: US6 — Float Overdraft Prevention (Priority: P0)

**Goal**: Float never goes negative under any concurrency conditions; balance hits exactly zero; post-depletion operations fail.

**Note**: Overdraft prevention is implemented in `FloatCashInService` (T031 FOR UPDATE lock). This phase adds explicit unit tests for the boundary conditions.

- [X] T046 [US6] Write unit test `FloatCashInServiceTest` in `wallet-ecosystem/services/api/tests/Unit/Modules/AgentFloat/FloatCashInServiceTest.php`: tests `balance == amount` succeeds (float → 0), `balance < amount` throws `InsufficientFloatException`, `balance == 0` throws immediately; mock LedgerModuleContract for balance responses
- [X] T047 [US6] Write concurrency test `OverdraftPreventionTest` in `wallet-ecosystem/services/api/tests/Concurrency/Modules/AgentFloat/OverdraftPreventionTest.php`: 10 concurrent BWP 100 cash-ins against BWP 300 float → exactly 3 succeed, 7 fail; final balance = 0 (not negative); verify `agent.float_transactions` count = 3

**Checkpoint**: Overdraft boundary conditions verified; `OverdraftPreventionTest` passes with final balance ≥ 0.

---

## Phase 9: US7 — Float Limit Configuration (Priority: P1)

**Goal**: Admin sets per-agent float limits (max balance, per-tx, daily); limits are enforced in funding and cash-in/out; changes audit-logged.

**Independent Test**: Set max_balance=5000000 → fund request for BWP 60,000 returns 422 FLOAT_LIMIT_EXCEEDED.

- [X] T048 [US7] Implement `FloatLimitService` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Application/Services/FloatLimitService.php`: `updateLimits(agentId, limits[], actorId)` — upsert `agent.float_limits` record, set `is_tbd=false` for supplied fields, publish `FloatLimitsConfiguredEvent` to outbox via `EventPublisherInterface`, audit via `AuditServiceInterface`, call `$this->metrics->increment('agent_float.limits.updated')`; `getLimits(agentId)` — return current limits or null if none
- [X] T049 [P] [US7] Create `FloatLimitsConfiguredEvent` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Domain/Events/FloatLimitsConfiguredEvent.php` (fields: agentId, changes[], actorId, occurredAt, correlationId)
- [X] T050 [P] [US7] Create `UpdateFloatLimitsRequest` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Requests/UpdateFloatLimitsRequest.php` (validates: max_balance nullable int min:1, per_transaction_limit nullable int min:1, daily_volume_limit nullable int min:1, low_float_threshold nullable int min:0, at least one field present; authorize agent_float.limits.manage)
- [X] T051 [P] [US7] Create `FloatLimitResource` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Resources/FloatLimitResource.php` (maps FloatLimitModel → agent_id, currency_code, max_balance [null if is_tbd], per_transaction_limit, daily_volume_limit, low_float_threshold, effective_from, updated_by, updated_at)
- [X] T052 [US7] Create `AgentFloatLimitController` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Controllers/AgentFloatLimitController.php`: `update(UpdateFloatLimitsRequest, agentId)` and `show(agentId)` methods
- [X] T053 [US7] Register routes `PATCH /v1/agents/{agentId}/float-wallet/limits` and `GET /v1/agents/{agentId}/float-wallet/limits` in `AgentFloatServiceProvider`
- [X] T054 [US7] Add max_balance enforcement to `FloatFundingService` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Application/Services/FloatFundingService.php`: before posting journal, check `currentBalance + amount <= max_balance` (if not is_tbd); throw `FloatLimitExceededException` if exceeded
- [X] T055 [US7] Add per-transaction and daily-volume enforcement to `FloatCashInService` and `FloatCashOutService` in their respective files: check `per_transaction_limit` (if not is_tbd); check daily volume from `agent.float_transactions` count; throw appropriate exception if exceeded
- [X] T056 [US7] Write feature test `FloatLimitTest` in `wallet-ecosystem/services/api/tests/Feature/Modules/AgentFloat/FloatLimitTest.php`: set max_balance limit, fund exceeding limit → 422; set per-tx limit, cash-in exceeding → 422; PATCH limits audit log written; GET limits returns current config; admin-only enforcement (non-admin gets 403)

**Checkpoint**: All limit dimensions enforced; `FloatLimitTest` passes.

---

## Phase 10: US8 — Low-Float Monitoring and Alerts (Priority: P1)

**Goal**: `FloatLowAlertEvent` emitted once per threshold crossing; no duplicates; reset on recovery.

**Independent Test**: Float falls below threshold → one event in outbox; subsequent depletion without recovery → no second event; re-fund above threshold → recovery; next depletion triggers fresh alert.

- [X] T057 [US8] Implement `FloatAlertService` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Application/Services/FloatAlertService.php`: `checkAndAlert(agentId, currentBalance, correlationId)` — load `FloatLimitModel` for agent; if `low_float_threshold` is set AND `currentBalance < threshold` AND `low_float_alerted = false`: publish `FloatLowAlertEvent`, set `low_float_alerted = true`; if `currentBalance >= threshold` AND `low_float_alerted = true`: reset `low_float_alerted = false` (recovery)
- [X] T058 [P] [US8] Create `FloatLowAlertEvent` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Domain/Events/FloatLowAlertEvent.php` (implements `IntegrationEventInterface`; fields: agentId, walletId, currentBalance, threshold, currencyCode, occurredAt, correlationId)
- [X] T059 [US8] Wire `FloatAlertService::checkAndAlert()` call into `FloatCashInService` post-commit in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Application/Services/FloatCashInService.php` (pass updated balance after journal post)
- [X] T060 [US8] Verify recovery wiring in `FloatFundingService` post-commit in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Application/Services/FloatFundingService.php` (the `FloatAlertService::checkAndAlert()` call is already specified in T021; this task explicitly tests the funding-recovery path — a fresh low-float event can fire after next depletion; note: cash-out recovery is wired in T038)
- [X] T061 [US8] Write feature test `FloatLowAlertTest` in `wallet-ecosystem/services/api/tests/Feature/Modules/AgentFloat/FloatLowAlertTest.php`: threshold set to 50000; cash-in depletes to 49999 → `FloatLowAlertEvent` in outbox, `low_float_alerted=true`; second depletion → no second event; fund back to 60000 → `low_float_alerted=false`; third depletion → new event emitted; `low_float_alert=true` in balance view

**Checkpoint**: Low-float alert fires exactly once per threshold crossing; `FloatLowAlertTest` passes.

---

## Phase 11: US9 — Agent Float Transaction Report (Priority: P1)

**Goal**: Agent or admin retrieves paginated, date-filtered, type-filtered float transaction history.

**Independent Test**: After 1 funding + 1 cash-in + 1 cash-out, GET returns 3 records with correct fields; date filter, type filter, and pagination all work.

- [X] T062 [US9] Implement `FloatTransactionService` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Application/Services/FloatTransactionService.php`: `list(agentId, page, perPage, from, to, type)` — query `agent.float_transactions` with filters; return paginated result with meta
- [X] T063 [P] [US9] Create `ListFloatTransactionsRequest` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Requests/ListFloatTransactionsRequest.php` (validates: page int min:1, per_page int 1-100, from date nullable, to date nullable, type enum nullable; authorize agent_float.transactions.view or .view_any)
- [X] T064 [US9] Create `AgentFloatTransactionController` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Controllers/AgentFloatTransactionController.php`: `index(ListFloatTransactionsRequest, agentId)` — delegates to `FloatTransactionService`, returns paginated `FloatTransactionResource` collection
- [X] T065 [US9] Register route `GET /v1/agents/{agentId}/float-wallet/transactions` in `AgentFloatServiceProvider`
- [X] T066 [US9] Write feature test `FloatTransactionHistoryTest` in `wallet-ecosystem/services/api/tests/Feature/Modules/AgentFloat/FloatTransactionHistoryTest.php`: returns all 3 transaction types; date range filter narrows results; type filter returns subset; pagination meta correct; agent sees own history; non-owner gets 403; assert all `FloatTransaction` records have a non-null `journal_id` matching a real ledger journal (orphan integrity — no float transaction should exist without a corresponding double-entry journal)

**Checkpoint**: Transaction history endpoint works; all filters correct; `FloatTransactionHistoryTest` passes.

---

## Phase 12: Agent Lifecycle Integration

**Purpose**: Float wallet automatically mirrors agent suspension/reactivation events from E12.

- [X] T067 Create `AgentSuspendedFloatListener` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Listeners/AgentSuspendedFloatListener.php`: handles `AgentSuspendedEvent`; look up `agent.float_wallets` by `agent_id`; update `wallet.wallets.status = 'suspended'` for the float wallet; publish `FloatWalletStatusChangedEvent`; audit
- [X] T068 Create `AgentReactivatedFloatListener` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Listeners/AgentReactivatedFloatListener.php`: handles `AgentReactivatedEvent`; update `wallet.wallets.status = 'active'`; publish `FloatWalletStatusChangedEvent`; audit
- [X] T069 [P] Create `FloatWalletStatusChangedEvent` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Domain/Events/FloatWalletStatusChangedEvent.php` (fields: agentId, walletId, oldStatus, newStatus, triggeredBy, occurredAt, correlationId)
- [X] T070 Register `AgentSuspendedEvent → AgentSuspendedFloatListener` and `AgentReactivatedEvent → AgentReactivatedFloatListener` in `AgentFloatServiceProvider::boot()` in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Providers/AgentFloatServiceProvider.php`
- [X] T071 Write feature test `FloatWalletLifecycleTest` in `wallet-ecosystem/services/api/tests/Feature/Modules/AgentFloat/FloatWalletLifecycleTest.php`: suspend agent → float wallet status becomes `suspended`; cash-in attempt → 422 AGENT_SUSPENDED; reactivate agent → float wallet status `active`; cash-in succeeds

**Checkpoint**: Agent lifecycle changes automatically propagate to float wallet; `FloatWalletLifecycleTest` passes.

---

## Phase 13: Polish & Cross-Cutting Concerns

**Purpose**: Code quality, static analysis, OpenAPI docs, and final validation.

- [X] T072 [P] Add `@OA\` PHPDoc annotations to all 6 AgentFloat controllers in `wallet-ecosystem/services/api/app/Modules/AgentFloat/Infrastructure/Http/Controllers/` so that `php artisan l5-swagger:generate` produces output matching `contracts/api.yaml` — annotate all 7 route methods (create, show, fund, limits update/show, cash-in, cash-out, transaction index) with `@OA\Post`/`@OA\Get`/`@OA\Patch`, `@OA\Parameter`, `@OA\RequestBody`, `@OA\Response` for all documented status codes (201/200/409/422/503), and all error codes from api.yaml
- [X] T073 [P] Run PHPStan level 8 on `wallet-ecosystem/services/api/app/Modules/AgentFloat/` and fix all errors (`vendor/bin/phpstan analyse app/Modules/AgentFloat --level=8`)
- [X] T074 Run full test suite `php artisan test --filter=AgentFloat --no-coverage` from `wallet-ecosystem/services/api/` and confirm 0 failures; record final test count
- [X] T075 Update `specs/013-agent-float/checklists/requirements.md` with Implementation Status section: final test count, PHPStan result, COMPLETE/RELEASE READY status
- [X] T076 Write load test `CashInThroughputTest` in `wallet-ecosystem/services/api/tests/Performance/Modules/AgentFloat/CashInThroughputTest.php` (SC-E13-001): simulate 100 concurrent cash-in requests against a seeded agent with sufficient float; assert P95 latency ≤ 500ms and 0 balance inconsistencies post-run (verify `SELECT SUM(amount) FROM agent.float_transactions WHERE type='cash_in'` matches ledger debit total)
- [X] T077 Write query performance test `FloatTransactionQueryPerfTest` in `wallet-ecosystem/services/api/tests/Performance/Modules/AgentFloat/FloatTransactionQueryPerfTest.php` (SC-E13-004): seed 10,000 `agent.float_transactions` rows for a single agent; assert paginated history endpoint (`GET /v1/agents/{id}/float-wallet/transactions?per_page=50`) returns within 200ms; verify composite index `(agent_id, created_at DESC)` is used (EXPLAIN plan check)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 — BLOCKS all user stories
- **US1–US6 (Phases 3–8)**: All depend on Phase 2 completion; US2 depends on US1 (float wallet must exist to be funded); US3 depends on US1+US2 to show meaningful balance; US4+US5 depend on US1+US2
- **US7 (Phase 9)**: Depends on US1–US5 (enforces limits on existing services)
- **US8 (Phase 10)**: Depends on US4 (alert wired into cash-in) and US7 (needs threshold config)
- **US9 (Phase 11)**: Depends on US2+US4+US5 (needs transactions to list)
- **Phase 12 (Lifecycle)**: Depends on US1 (float wallet must exist to be suspended)
- **Phase 13 (Polish)**: Depends on all phases

### User Story Dependencies

```
Phase 2 (Foundation)
  └─► US1 (Provisioning) ──────────────────────────────┐
        └─► US2 (Funding) ──────────────────────────────┤
              ├─► US3 (Balance View)                    ├─► US7 (Limits)
              ├─► US4 (Cash-In) ──► US6 (Overdraft)   ├─► US8 (Alerts)
              └─► US5 (Cash-Out)                        └─► US9 (History)
  └─► Phase 12 (Lifecycle) ─────────────────────────── Depends on US1
```

### Parallel Opportunities Per Phase

**Phase 2**: T008–T013 all [P] — run simultaneously after T003–T007 complete

**Phase 3 (US1)**: T015 + T016 + T017 [P] — run after T014

**Phase 4 (US2)**: T022 + T023 + T024 [P] — run after T021

**Phase 6 (US4)**: T032 + T033 [P] — run after T031

**Phase 7 (US5)**: T039 + T040 + T041 [P] — run after T038

**Phase 9 (US7)**: T049 + T050 + T051 [P] — run after T048

**Phase 12**: T069 [P] — run after T067 + T068

---

## Parallel Example: Phase 2

```
# Run all model + enum + exception tasks simultaneously after migrations:
T008: AgentFloatWalletModel
T009: FloatTransactionModel
T010: FloatLimitModel
T011: FloatTransactionType enum
T012: FloatTransactionDirection enum
T013: All exception classes
```

---

## Implementation Strategy

### MVP (US1 + US2 + US3 only)

1. Complete Phase 1 (Setup)
2. Complete Phase 2 (Foundation — migrations + models)
3. Complete Phase 3 (US1 — wallet provisioning)
4. Complete Phase 4 (US2 — funding)
5. Complete Phase 5 (US3 — balance view)
6. **STOP and VALIDATE**: Admin can create + fund a float wallet; agent can view their balance
7. Proceed to US4+US5+US6 for full cash-in/cash-out capability

### Incremental Delivery

1. **MVP** (Phases 1–5): Float wallet lifecycle + funding + balance view
2. **Core ops** (Phases 6–8): Cash-in, cash-out, overdraft prevention
3. **Controls** (Phase 9): Limit enforcement
4. **Observability** (Phase 10): Low-float alerts
5. **Reporting** (Phase 11): Transaction history
6. **Automation** (Phase 12): Lifecycle event mirroring

---

## Notes

- All amounts in minor currency units (integers); never use floats for money
- `FloatCashInService` uses `SELECT ... FOR UPDATE` on `agent.float_wallets` — this is the overdraft serialization point (see research.md Decision 3)
- `TBD-E13-001`: float funding requires `ledger.agent_float_funding_account.BWP` seeded in platform config; in test/dev use `AgentFloatDevSeeder`
- `TBD-E13-002`: all `agent.float_limits` fields start `is_tbd=true`; no limit is enforced until admin explicitly sets it
- Dual-connection pattern in US1 (T014): `wallet.wallets` insert uses `wallet` connection; `agent.float_wallets` insert uses `platform` connection — these cannot be in the same DB transaction; handle by inserting wallet first, then float_wallets record; on failure of second insert, attempt rollback/cleanup of wallet record
- `FloatWalletResource.balance` is derived from `LedgerModuleContract::getBalance()` — never stored in a column
