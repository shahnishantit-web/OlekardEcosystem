# Tasks: E14 â€” Agent Commission

**Input**: `specs/014-agent-commission/` â€” spec.md, plan.md, data-model.md, contracts/api.yaml, research.md, quickstart.md

**Prerequisites**: plan.md âœ“ spec.md âœ“ research.md âœ“ data-model.md âœ“ contracts/ âœ“ quickstart.md âœ“

**Organization**: Tasks grouped by user story to enable independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no blocking dependency on incomplete sibling task)
- **[Story]**: User story label (US1â€“US9); Setup and Foundational phases carry no label

---

## Phase 1: Setup

**Purpose**: Create the Commission module skeleton so all subsequent phases have their scaffolding in place.

- [X] T001 Create Commission module directory tree per plan.md â€” `wallet-ecosystem/services/api/app/Modules/Commission/` with subdirectories `Application/Services/`, `Domain/Enums/`, `Domain/Events/`, `Domain/Exceptions/`, `Infrastructure/Http/Controllers/`, `Infrastructure/Http/Requests/`, `Infrastructure/Http/Resources/`, `Infrastructure/Listeners/`, `Infrastructure/Migrations/`, `Infrastructure/Models/`, `Infrastructure/Repositories/`, `Infrastructure/Providers/`
- [X] T002 Create Commission module test directory tree â€” `wallet-ecosystem/services/api/tests/Unit/Commission/` and `wallet-ecosystem/services/api/tests/Feature/Commission/`
- [X] T003 Register `CommissionServiceProvider` in `wallet-ecosystem/services/api/bootstrap/providers.php` (or equivalent module loader list)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Migrations, permissions, shared domain primitives, and event processing infrastructure MUST be complete before any user story implementation begins.

**âš ï¸ CRITICAL**: All Phase 3â€“9 user story phases depend on Phase 2 completion.

- [X] T004 Create migration `2026_09_30_014000_add_agent_tier_to_agents.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Migrations/` â€” adds `agent_tier VARCHAR(50) NULL` column and partial index to `agent.agents` (platform connection)
- [X] T005 [P] Create migration `2026_09_30_014100_create_commission_rules.php` â€” creates `commission.commission_rules` table with all columns and indexes per data-model.md
- [X] T006 [P] Create migration `2026_09_30_014200_create_commissions.php` â€” creates `commission.commissions` table with all columns, UNIQUE index on `idempotency_key`, composite indexes per data-model.md
- [X] T007 [P] Create migration `2026_09_30_014300_create_commission_payouts.php` â€” creates `commission.commission_payouts` table with all columns, UNIQUE index on `idempotency_key`, deferred-status partial index per data-model.md
- [X] T008 [P] Create migration `2026_09_30_014400_create_commission_processed_events.php` â€” creates `commission.processed_events` table with UNIQUE index on `(event_name, event_id)` per data-model.md
- [X] T009 [P] Create migration `2026_09_30_014500_seed_commission_permissions.php` â€” inserts all 11 permissions (`commission.rules.create`, `commission.rules.view`, `commission.rules.update`, `commission.rules.delete`, `commission.view_own`, `commission.view_any`, `commission.payout.approve`, `commission.payout.cancel`, `commission.reverse`, `commission.tier.manage`, `commission.report.view`) into `auth.permissions`
- [ ] T010 Run migrations and verify schema â€” `php artisan migrate` from `wallet-ecosystem/services/api/`; confirm all 6 migrations apply cleanly
- [X] T011 [P] Create domain enums â€” `CommissionRuleType.php`, `CommissionAppliesTo.php`, `CommissionRuleStatus.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Domain/Enums/`
- [X] T012 [P] Create domain enums â€” `CommissionStatus.php`, `CommissionPayoutStatus.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Domain/Enums/`
- [X] T013 [P] Create domain exceptions â€” `CommissionExpenseAccountNotConfiguredException.php`, `CommissionRuleNotFoundException.php`, `CommissionNotFoundException.php`, `CommissionPayoutNotFoundException.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Domain/Exceptions/`
- [X] T014 [P] Create domain exceptions â€” `CommissionAlreadyProcessedException.php`, `CommissionAlreadyReversedException.php`, `PayoutCancellationNotAllowedException.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Domain/Exceptions/`
- [X] T015 [P] Create domain events â€” `CommissionRuleCreatedEvent.php`, `CommissionCalculatedEvent.php`, `CommissionReversedEvent.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Domain/Events/` â€” extend `BaseIntegrationEvent`, implement `eventName()`, `aggregateType()`, `payload()`
- [X] T016 [P] Create domain events â€” `CommissionPaidEvent.php`, `CommissionPayoutApprovedEvent.php`, `CommissionPayoutDeferredEvent.php`, `CommissionPayoutFailedEvent.php`, `CommissionPayoutCancelledEvent.php`, `CommissionLedgerPostFailedEvent.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Domain/Events/`
- [X] T017 Create `CommissionProcessedEventRepository.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Repositories/` â€” implements `ProcessedEventRepositoryInterface`; reads/writes `commission.processed_events`; `hasBeenProcessed(string $eventName, string $eventId): bool`; `markAsProcessed(string $eventName, string $eventId): void`
- [X] T018 Create `CommissionServiceProvider.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Providers/` â€” `register()` binds `CommissionProcessedEventRepository` as singleton; `boot()` calls `loadMigrationsFrom()` and `loadRoutesFrom()`; registers event listeners as stubs for `FloatCashInEvent`, `FloatCashOutEvent`, `AgentReactivatedEvent`

**Checkpoint**: Migrations applied, permissions seeded, enums/exceptions/events/provider scaffolded. Phase 3+ can begin.

---

## Phase 3: User Story 1 â€” Commission Rule Configuration (Priority: P0) ðŸŽ¯ MVP Entry

**Goal**: Authorized admins can create, update, deactivate, and view commission rules; all changes are audit-logged; unauthorized actors are rejected.

**Independent Test**: Admin POSTs flat BWP 2.00 cash-in rule â†’ HTTP 201 with status `active`; PATCH supersedes it â†’ new version active, old `superseded`; unauthenticated POST â†’ 401.

- [X] T019 [US1] Create `CommissionRuleModel.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Models/` â€” Eloquent model for `commission.commission_rules`; casts `status` to `CommissionRuleStatus`, `rule_type` to `CommissionRuleType`, `applies_to` to `CommissionAppliesTo`; connection: `platform`
- [X] T020 [US1] Create `CommissionRuleService.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Application/Services/` â€” methods: `create(array $data, string $actorId): CommissionRuleModel`; `supersede(string $ruleId, array $changes, string $actorId): CommissionRuleModel` (transitions existing to `superseded`, creates new active version); `deactivate(string $ruleId, string $actorId): CommissionRuleModel`; `findById(string $ruleId): CommissionRuleModel`; `list(array $filters, int $page, int $perPage): LengthAwarePaginator`; injects `AuditServiceInterface`, `EventPublisherInterface`; emits `CommissionRuleCreatedEvent`
- [X] T021 [P] [US1] Create `CreateCommissionRuleRequest.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Requests/` â€” validates `rule_type` (in:flat,percentage), `rate` (integer, min:1), `applies_to` (in:cash_in,cash_out,both), `agent_tier` (nullable, max:50), `valid_from` (date), `valid_to` (nullable, date, after_or_equal:valid_from)
- [X] T022 [P] [US1] Create `UpdateCommissionRuleRequest.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Requests/` â€” same fields as Create but all nullable for partial update
- [X] T023 [P] [US1] Create `CommissionRuleResource.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Resources/` â€” wraps `CommissionRuleModel`; exposes all fields per contracts/api.yaml `CommissionRuleResource` schema
- [X] T024 [US1] Create `CommissionRuleController.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Controllers/` â€” thin controller: `store()`, `index()`, `show()`, `update()`, `destroy()`; authorize using `commission.rules.*` permissions; inject `CommissionRuleService`; return `CommissionRuleResource` or paginated collection
- [X] T025 [US1] Register commission rule routes in `CommissionServiceProvider::boot()` â€” `POST/GET /v1/commission-rules`, `GET/PATCH/DELETE /v1/commission-rules/{ruleId}` per contracts/api.yaml; middleware: `identity.auth`
- [X] T026 [US1] Write unit tests for `CommissionRuleService` in `wallet-ecosystem/services/api/tests/Unit/Commission/CommissionRuleServiceTest.php` â€” test: create flat rule, create percentage rule, create tier-scoped rule, supersede (oldâ†’superseded, newâ†’active), deactivate (statusâ†’expired), reject rate â‰¤ 0, audit log called, `CommissionRuleCreatedEvent` emitted
- [X] T027 [US1] Write feature tests for commission rule CRUD in `wallet-ecosystem/services/api/tests/Feature/Commission/CommissionRuleApiTest.php` â€” test: POST 201, GET 200 list, GET 200 single, PATCH 200 supersede, DELETE 200 deactivate, unauthenticated 401, missing permission 403, invalid rate 400

**Checkpoint**: Commission rules can be created, listed, updated, and deactivated by admin.

---

## Phase 4: User Story 2 + 4 â€” Transaction Commission Calculation & Ledger Recording (Priority: P0)

**Goal**: Every qualifying cash-in/cash-out event automatically calculates a commission, posts a balanced double-entry ledger journal, and creates a `pending` commission record â€” idempotently, without affecting the originating transaction.

**Independent Test**: Trigger `FloatCashIn.Completed` event for agent with active flat BWP 2.00 rule â†’ one `Commission` record (pending, amount=200, non-null journal_id); replaying the same event creates no duplicate.

- [X] T028 [US2] Create `CommissionModel.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Models/` â€” Eloquent model for `commission.commissions`; casts `status` to `CommissionStatus`, `transaction_type` to `CommissionAppliesTo`; connection: `platform`; `belongsTo(CommissionRuleModel)`, `belongsTo(CommissionPayoutModel)`
- [X] T029 [US2] Create skeleton of `CommissionCalculationService.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Application/Services/` with constructor injection: `CommissionProcessedEventRepository`, `LedgerModuleContract`, `ConfigurationServiceInterface`, `AuditServiceInterface`, `EventPublisherInterface`; public method signature `calculate(string $agentId, string $transactionId, string $transactionType, int $amount, string $currencyCode, string $walletId): ?CommissionModel`
- [X] T030 [US2] Implement event idempotency and agent status guard in `CommissionCalculationService::calculate()` â€” step 1: call `CommissionProcessedEventRepository::hasBeenProcessed(eventName, eventId)` and return if duplicate; step 2: load agent from `agent.agents`; if suspended return null (no commission for suspended agents per US2 AC5)
- [X] T031 [US2] Implement rule resolution in `CommissionCalculationService` â€” query `commission.commission_rules` with filters: `status = active`, `valid_from <= today`, `(valid_to IS NULL OR valid_to >= today)`, `applies_to IN ('both', $transactionType)`; tier-scoped rules (matching `agent.agent_tier`) take precedence over global (null `agent_tier`); among same-specificity candidates, use `created_at DESC LIMIT 1`; return null (skip) if no matching rule
- [X] T032 [US2] Implement commission amount arithmetic in `CommissionCalculationService` â€” flat: `$commissionAmount = $rule->rate`; percentage: `$commissionAmount = (int) floor($amount * $rule->rate / 10000)` (basis-point calculation); return null if calculated amount is zero
- [X] T033 [US4] Implement ledger account lazy-registration in `CommissionCalculationService` â€” resolve expense account key `ledger.commission_expense_account.{$currencyCode}` from `ConfigurationServiceInterface`; throw `CommissionExpenseAccountNotConfiguredException` if absent; call `LedgerModuleContract::registerAccount('commission_payable:{$agentId}', 'liability', $currencyCode, $agentId, 'agent_commission')` if not yet registered
- [X] T034 [US4] Implement accrual journal posting in `CommissionCalculationService` â€” wrap in platform-connection DB transaction; call `LedgerModuleContract::postEntry()` with accrual journal (debit `commission_expense:{$currency}` / credit `commission_payable:{$agentId}`) per data-model.md; capture returned `journalId`; if posting throws, do NOT persist commission record, emit `CommissionLedgerPostFailedEvent`; INSERT `commission.commissions` with UNIQUE constraint on `idempotency_key` = `{transactionId}:{transactionType}` â€” catch `UniqueConstraintViolation` and return existing record; call `ProcessedEventRepository::markAsProcessed()`; publish `CommissionCalculatedEvent`
- [X] T035 [US2] Create `FloatCashInCommissionListener.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Listeners/` â€” handles `FloatCashInEvent` (`AgentFloat.CashIn.Completed`); extracts `agentId`, `floatTransactionId`, `amount`, `currencyCode`, `walletId` from event payload; calls `CommissionCalculationService::calculate()` with `transactionType = 'cash_in'`; uses `ProcessedEventRepository` guard at listener level with key `(AgentFloat.CashIn.Completed, event.correlationId)`
- [X] T036 [P] [US2] Create `FloatCashOutCommissionListener.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Listeners/` â€” mirrors `FloatCashInCommissionListener` with `transactionType = 'cash_out'`; handles `FloatCashOutEvent` (`AgentFloat.CashOut.Completed`)
- [X] T037 [US2] Complete listener bindings in `CommissionServiceProvider` â€” replace stub registrations from T018 with: `FloatCashInEvent::class => [FloatCashInCommissionListener::class]`, `FloatCashOutEvent::class => [FloatCashOutCommissionListener::class]`
- [X] T038 [US2] Write unit tests for `CommissionCalculationService` in `wallet-ecosystem/services/api/tests/Unit/Commission/CommissionCalculationServiceTest.php` â€” test: flat rate calculation, percentage basis-point calculation, no active rule â†’ returns null, suspended agent â†’ returns null, expense account not configured â†’ throws exception, duplicate transactionId â†’ returns existing record without DB write, ledger failure â†’ no commission persisted, `CommissionCalculated` event published on success
- [X] T039 [US4] Write unit test for accrual journal in `wallet-ecosystem/services/api/tests/Unit/Commission/CommissionLedgerPostingTest.php` â€” mock `LedgerModuleContract`; assert `postEntry()` called once with `debit commission_expense:{currency}` + `credit commission_payable:{agentId}` + `transaction_reference = commission_accrual:{commissionId}`; assert returned `journalId` stored on commission record
- [X] T040 [US2] Write feature tests for commission calculation listeners in `wallet-ecosystem/services/api/tests/Feature/Commission/CommissionCalculationListenerTest.php` â€” dispatch `FloatCashInEvent` and `FloatCashOutEvent` and assert commission record created with correct amount; assert second dispatch with same transactionId creates no duplicate; assert `CommissionCalculated` outbox event present
- [X] T041 [US2] Write concurrency test for duplicate commission prevention in `wallet-ecosystem/services/api/tests/Feature/Commission/CommissionIdempotencyConcurrencyTest.php` â€” dispatch same `floatTransactionId` event concurrently (5 goroutines or sequential rapid-fire); assert exactly 1 commission record exists

**Checkpoint**: Commission calculation and ledger recording implemented. Qualifying float events automatically produce idempotent commission records with balanced journal entries.

---

## Phase 5: User Story 5 + 6 â€” View Earned & Pending Commissions (Priority: P0)

**Goal**: Agents and admins can retrieve paginated, date-filtered lists of paid commissions (US5) and pending/approved commissions with aggregate total (US6); unauthorized access is rejected.

**Independent Test**: After 3 cash-ins, GET `/v1/agents/{id}/commissions/pending` returns 3 records with `pending_total = 600`; GET with status=paid returns empty until a payout executes.

- [X] T042 [US5] Create `CommissionReportService.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Application/Services/` â€” method `listPaid(string $agentId, array $filters, int $page, int $perPage): LengthAwarePaginator`; filters: `from`, `to` on `paid_at` (use `updated_at` where status=paid); method `listPending(string $agentId, int $page, int $perPage): array` â€” returns `['data' => LengthAwarePaginator, 'pending_total' => int, 'currency_code' => string]` (pending_total = SUM of amounts where status IN (pending, approved))
- [X] T043 [P] [US5] Create `CommissionResource.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Resources/` â€” wraps `CommissionModel`; exposes `id`, `agent_id`, `transaction_id`, `transaction_type`, `amount`, `currency_code`, `rule_id`, `status`, `payout_id` (nullable), `journal_id`, `created_at`, `paid_at` (nullable, derived from updated_at when status=paid) per contracts/api.yaml
- [X] T044 [P] [US5] Create `PendingCommissionsSummaryResource.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Resources/` â€” wraps array from `listPending()`; returns `data[]`, `pending_total`, `currency_code`, `meta`
- [X] T045 [US5] Create `AgentCommissionController.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Controllers/` â€” `index()`: list paid commissions (US5); `pending()`: pending/approved with aggregate (US6); authorize `commission.view_own` (own agent) or `commission.view_any` (admin); inject `CommissionReportService`
- [X] T046 [US5] Register agent commission routes in `CommissionServiceProvider::boot()` â€” `GET /v1/agents/{agentId}/commissions`, `GET /v1/agents/{agentId}/commissions/pending` per contracts/api.yaml
- [X] T047 [US5] Write feature tests for earned and pending commission endpoints in `wallet-ecosystem/services/api/tests/Feature/Commission/AgentCommissionViewTest.php` â€” test: pending list with correct pending_total, paid list with date filter, paid commission disappears from pending after payout, own-agent access 200, cross-agent non-admin 403, unauthenticated 401

**Checkpoint**: Read-only commission views functional for both agents and admins.

---

## Phase 6: User Story 7 â€” Approve and Execute Commission Payout (Priority: P0)

**Goal**: Admin approves a batch of pending commissions via a single API call; execution is immediate and atomic; agent's float wallet is credited; all linked commissions transition to `paid`; duplicate approval requests are idempotent.

**Independent Test**: POST `/v1/commission-payouts` with 3 pending commission IDs â†’ HTTP 201 status=`completed`; agent float balance +600 minor units; all 3 commissions `paid`; idempotency replay returns same result without double-crediting.

- [X] T048 [US7] Create `CommissionPayoutModel.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Models/` â€” Eloquent model for `commission.commission_payouts`; cast `status` to `CommissionPayoutStatus`; `hasMany(CommissionModel, 'payout_id')`; connection: `platform`
- [X] T049 [US7] Create skeleton of `CommissionPayoutService.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Application/Services/` with constructor injection: `IdempotencyServiceInterface`, `LedgerModuleContract`, `AuditServiceInterface`, `EventPublisherInterface`; public method signature `approveAndExecute(string $adminId, string $agentId, array $commissionIds, string $idempotencyKey): CommissionPayoutModel`
- [X] T050 [US7] Implement idempotency gate and status validation in `CommissionPayoutService::approveAndExecute()` â€” call `IdempotencyServiceInterface::check($idempotencyKey)`: if existing record found and status != `failed` â†’ return cached response; validate all `$commissionIds` have `status = pending` â†’ throw `CommissionAlreadyProcessedException` if not
- [X] T051 [US7] Implement payout execution in `CommissionPayoutService` â€” begin platform-connection DB transaction; check agent status via `AgentModel`; if suspended: create `CommissionPayout(status=deferred)`, set commissions to `approved`, commit, publish `CommissionPayoutDeferredEvent`, return; otherwise: resolve `walletId` from `agent.float_wallets WHERE agent_id = ?`; create `CommissionPayout(status=approved)`; call `LedgerModuleContract::postEntry()` with payout journal (debit `commission_payable:{agentId}` / credit `agent_float:{walletId}`); on success: set `status=completed`, `executed_at`, `journal_id`; bulk-update commissions to `paid`, `payout_id`; on failure: set `status=failed`, keep commissions `pending`; commit; publish events; audit-log
- [X] T052 [US7] Create `ApproveCommissionPayoutRequest.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Requests/` â€” validates `agent_id` (required UUID), `commission_ids` (required array, min:1, each UUID)
- [X] T053 [P] [US7] Create `CommissionPayoutResource.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Resources/` â€” wraps `CommissionPayoutModel`; exposes all fields per contracts/api.yaml `CommissionPayoutResource` schema including `commission_ids` array loaded via `hasMany`
- [X] T054 [US7] Create `CommissionPayoutController.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Controllers/` â€” `store()` (approve+execute), `show()` (get payout); authorize `commission.payout.approve`; extract `Idempotency-Key` header; inject `CommissionPayoutService`
- [X] T055 [US7] Register payout routes in `CommissionServiceProvider::boot()` â€” `POST /v1/commission-payouts`, `GET /v1/commission-payouts/{payoutId}` per contracts/api.yaml
- [X] T056 [US7] Write unit tests for `CommissionPayoutService` in `wallet-ecosystem/services/api/tests/Unit/Commission/CommissionPayoutServiceTest.php` â€” test: active agent â†’ completed with journal posted, agent suspended â†’ deferred, commission not pending â†’ CommissionAlreadyProcessedException, non-admin â†’ UNAUTHORIZED, ledger failure â†’ failed status, idempotency replay of completed â†’ same result no double-credit
- [X] T057 [US7] Write feature tests for payout approval in `wallet-ecosystem/services/api/tests/Feature/Commission/CommissionPayoutApiTest.php` â€” test: POST 201 completed, GET 200 payout details, non-admin 403, idempotency-key replay returns same result, already-processed commission 400
- [X] T058 [US7] Write concurrency test for duplicate payout prevention in `wallet-ecosystem/services/api/tests/Feature/Commission/PayoutIdempotencyConcurrencyTest.php` â€” submit same `Idempotency-Key` payout from 5 concurrent requests; assert agent float credited exactly once; assert exactly 1 `CommissionPayout` record in DB

**Checkpoint**: Payout approval and execution fully functional, idempotent, audit-logged. Admin can pay agents via single API call.

---

## Phase 7: User Story 8 â€” Deferred and Failed Payout Recovery (Priority: P0)

**Goal**: Payouts deferred due to suspended agents auto-execute on reactivation; failed payouts retry on idempotency-key resubmission; deferred payouts can be cancelled, reverting commissions to `pending`.

**Independent Test**: Approve payout for suspended agent â†’ deferred; reactivate agent â†’ payout auto-completes; cancel a second deferred payout â†’ linked commissions revert to `pending`.

- [X] T059 [US8] Create `AgentReactivatedCommissionListener.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Listeners/` â€” handles `AgentReactivatedEvent` (`agent.reactivated`); checks `CommissionProcessedEventRepository::hasBeenProcessed()`; queries `commission_payouts WHERE agent_id = ? AND status = deferred`; calls `CommissionPayoutService::executeDeferred(payoutId)` for each; marks event as processed after all payouts attempted
- [X] T060 [US8] Implement `CommissionPayoutService::executeDeferred(string $payoutId): CommissionPayoutModel` â€” same execution steps as `approveAndExecute` from payout step (T051) but skips agent-status check; resolves `walletId`, posts ledger journal, transitions payout to `completed`, commissions to `paid`, publishes events
- [X] T061 [US8] Implement `CommissionPayoutService::cancel(string $payoutId, string $actorId, string $idempotencyKey): CommissionPayoutModel` â€” validate `status = deferred` (throw `PayoutCancellationNotAllowedException` if status is `completed` or `failed`); within DB transaction: set `status = cancelled`, `cancelled_by`, `cancelled_at`; bulk-update non-reversed linked commissions back to `status = pending`, clear `payout_id`; publish `CommissionPayoutCancelledEvent`; audit-log
- [X] T062 [US8] Add `cancel()` to `CommissionPayoutController` â€” `DELETE /v1/commission-payouts/{payoutId}`; authorize `commission.payout.cancel`; inject `Idempotency-Key` header; call `CommissionPayoutService::cancel()`; return 200 `CommissionPayoutResource` or 409 on `PayoutCancellationNotAllowedException`
- [X] T063 [US8] Register cancel payout route in `CommissionServiceProvider::boot()` â€” `DELETE /v1/commission-payouts/{payoutId}` per contracts/api.yaml
- [X] T064 [US8] Complete listener binding in `CommissionServiceProvider` â€” replace `AgentReactivatedEvent` stub from T018 with `AgentReactivatedEvent::class => [AgentReactivatedCommissionListener::class]`
- [X] T065 [US8] Write unit tests for deferred and failed recovery in `wallet-ecosystem/services/api/tests/Unit/Commission/DeferredPayoutTest.php` â€” test: suspended agent at approval â†’ deferred; `AgentReactivatedCommissionListener` â†’ `executeDeferred()` called; failed payout + same idempotency key â†’ retry succeeds; cancel deferred â†’ commissions revert to pending; cancel `completed` â†’ `PayoutCancellationNotAllowedException`; cancel with partially-reversed commissions â†’ only non-reversed commissions revert
- [X] T066 [US8] Write feature tests for recovery flows in `wallet-ecosystem/services/api/tests/Feature/Commission/PayoutRecoveryTest.php` â€” test: deferred payout HTTP 201 status=deferred, DELETE cancel HTTP 200 status=cancelled, attempt cancel completed HTTP 409, listener replay idempotency (fire `AgentReactivatedEvent` twice, `executeDeferred` called once per payout)

**Checkpoint**: All P0 user stories complete. Full commission lifecycle (calculate â†’ view â†’ approve â†’ pay â†’ recover) is implemented and tested.

---

## Phase 7.5: FR-E14-014 â€” Commission Reversal (Priority: P0)

**Goal**: Admin can reverse any commission â€” `pending` or `paid` â€” by posting compensating ledger journals; a `paid` commission reversal additionally claws back the amount from the agent's float wallet. P0 financial integrity requirement per constitution Principle 4 ("Reversals use compensating entries").

**Independent Test**: Reverse a `pending` commission â†’ 1 compensating journal posted, commissionâ†’`reversed`, float unchanged; reverse a `paid` commission â†’ 2 compensating journals posted, agent float debited by commission amount.

- [X] T079 [US4] Create `CommissionReversalService.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Application/Services/` â€” method `reverse(string $commissionId, string $actorId, string $reason, string $idempotencyKey): CommissionModel`; validate commission status is `pending` or `paid` (throw `CommissionAlreadyReversedException` if `reversed`); for `pending`: post accrual compensating journal (debit `commission_payable:{agentId}` / credit `commission_expense:{currency}`); for `paid`: post accrual compensating journal THEN float clawback journal (debit `agent_float:{walletId}` / credit `commission_payable:{agentId}`); transition commission to `reversed`; audit-log with `reason`; emit `CommissionReversedEvent`
- [X] T080 [US4] Create `CommissionReversalController.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Controllers/` and `ReverseCommissionRequest.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Requests/` â€” `POST /v1/commissions/{commissionId}/reverse`; authorize `commission.reverse`; register route in `CommissionServiceProvider`
- [X] T081 [US4] Write unit tests for `CommissionReversalService` in `wallet-ecosystem/services/api/tests/Unit/Commission/CommissionReversalServiceTest.php` â€” test: pending reversal posts 1 compensating journal + commissionâ†’reversed + float unchanged, paid reversal posts 2 journals + float debited, double-reverse throws `CommissionAlreadyReversedException`, idempotency key prevents duplicate reversal, audit log called with reason

**Checkpoint**: Commission reversal functional. All P0 financial integrity requirements (FR-E14-014) complete.

---

## Phase 8: User Story 3 â€” Tier-Based Commission Calculation (Priority: P1)

**Goal**: Agents assigned a commission tier earn higher rates when their cumulative monthly volume qualifies; tier assignment is admin-managed; untiered agents continue using global rules.

**Independent Test**: Agent assigned tier `premium`; premium-tier rule (BWP 3.00) applied instead of global rule (BWP 2.00) on next qualifying transaction.

- [X] T067 [US3] Create `AgentCommissionTierController.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Controllers/` â€” `update()` method: `PATCH /v1/agents/{agentId}/commission-tier`; authorize `commission.tier.manage`; loads agent from `agent.agents`, sets `agent_tier` column, saves; audit-logs the tier change via `AuditServiceInterface`
- [X] T068 [P] [US3] Create `SetAgentTierRequest.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Requests/` â€” validates `agent_tier` (nullable, string, max:50)
- [X] T069 [US3] Register commission-tier route in `CommissionServiceProvider::boot()` â€” `PATCH /v1/agents/{agentId}/commission-tier` per contracts/api.yaml
- [X] T070 [US3] Implement monthly volume aggregation in `CommissionCalculationService` â€” private method `calculateMonthlyVolume(string $agentId): int` â€” queries `commission.commissions WHERE agent_id = ? AND created_at >= start_of_current_month AND status != 'reversed'`; uses `idx_commissions_agent_month` partial index
- [X] T071 [US3] Extend rule selection in `CommissionCalculationService` to support volume-threshold tier promotion â€” add optional `volume_threshold INT NULL` column to `commission_rules` (new migration `2026_09_30_014600_add_volume_threshold_to_commission_rules.php`); when multiple active rules qualify, select the highest `volume_threshold` satisfied by `calculateMonthlyVolume()` (agent-favorable per US3 AC4); monthly volume resets to 0 on first day of month
- [X] T072 [US3] Write unit tests for tier-based rule selection in `wallet-ecosystem/services/api/tests/Unit/Commission/TierBasedCommissionTest.php` â€” test: tiered agent uses tier rule, untiered agent uses global rule, multiple tier rules â†’ highest applicable volume_threshold wins, monthly volume reset on month boundary, overlapping tiers â†’ agent-favorable resolution
- [X] T073 [US3] Write feature tests for commission tier management in `wallet-ecosystem/services/api/tests/Feature/Commission/AgentCommissionTierTest.php` â€” test: PATCH sets tier (HTTP 200), calculation uses tier rule after tier assignment, null tier â†’ reverts to global rule, non-admin PATCH 403

**Checkpoint**: Tier-based commission calculation functional. Agents assigned tiers earn higher rates automatically based on monthly volume.

---

## Phase 9: User Story 9 â€” Commission Report (Priority: P1)

**Goal**: Admin can retrieve a paginated, status-filtered, date-filtered cross-agent commission report with totals by status and transaction type. No CSV export (deferred to E24).

**Independent Test**: After seeding 3 pending + 2 paid + 1 reversed records, GET `/v1/commissions/report` returns 6 records with correct `totals_by_status` and `totals_by_type`; query with 10,000 records returns in under 500ms.

- [X] T074 [US9] Extend `CommissionReportService` with `report(array $filters, int $page, int $perPage): array` â€” `$filters`: `agent_id`, `status`, `transaction_type`, `from`, `to`; runs aggregate SELECT for `totals_by_status` (SUM(amount) GROUP BY status) and `totals_by_type` (SUM(amount) GROUP BY transaction_type); uses composite index `idx_commissions_agent_created`; returns `['data' => LengthAwarePaginator, 'totals_by_status' => [...], 'totals_by_type' => [...]]`
- [X] T075 [US9] Create `CommissionReportController.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Controllers/` â€” `index()` for admin cross-agent report; authorize `commission.report.view`; inject `CommissionReportService`
- [X] T076 [P] [US9] Create `CommissionReportRequest.php` in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Requests/` â€” validates optional filters: `agent_id` (UUID), `status` (in:pending,approved,paid,reversed), `transaction_type` (in:cash_in,cash_out), `from`/`to` (date), `page`/`per_page` (integer)
- [X] T077 [US9] Register report route in `CommissionServiceProvider::boot()` â€” `GET /v1/commissions/report` per contracts/api.yaml
- [X] T078 [US9] Write feature tests for commission report in `wallet-ecosystem/services/api/tests/Feature/Commission/CommissionReportApiTest.php` â€” test: paginated response with correct totals, status filter, type filter, date range filter, performance test with 10k records under 500ms (SC-E14-004), admin-only access (non-admin 403)

**Checkpoint**: Full commission reporting API functional. All 9 user stories implemented.

---

## Phase 10: Polish & Cross-Cutting Concerns

**Purpose**: Observability hardening, static analysis, and final quickstart validation. Commission reversal (FR-E14-014) was completed in Phase 7.5.

- [X] T082 [P] Add structured log statements to all 5 application services (`CommissionRuleService`, `CommissionCalculationService`, `CommissionPayoutService`, `CommissionReversalService`, `CommissionReportService`) â€” log at start and completion of financial operations; include `correlationId`, `agentId`, `actorId` in context
- [X] T083 [P] Emit operational metrics in `CommissionCalculationService` and `CommissionPayoutService` â€” `commission.calculated`, `commission.payout.completed`, `commission.payout.deferred`, `commission.payout.failed`, `commission.reversed` per plan.md Â§Observability
- [X] T084 [P] Add PHPDoc OpenAPI annotations to all 8 controllers in `wallet-ecosystem/services/api/app/Modules/Commission/Infrastructure/Http/Controllers/` â€” `#[OA\Get]`, `#[OA\Post]`, `#[OA\Patch]`, `#[OA\Delete]` per contracts/api.yaml schemas
- [X] T085 Run PHPStan static analysis at level 8 â€” `./vendor/bin/phpstan analyse app/Modules/Commission/ --level=8` from `wallet-ecosystem/services/api/`; fix all reported type errors
- [X] T086 Run full quickstart.md validation â€” execute all 9 scenarios from `specs/014-agent-commission/quickstart.md` against running Docker stack; verify SC-E14-001 through SC-E14-006 pass; document failures as follow-up issues
- [X] T087 Update `specs/014-agent-commission/plan.md` Constitution Check â€” confirm all 7 items `[x]` after final implementation review

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies â€” start immediately
- **Phase 2 (Foundational)**: Requires Phase 1 â€” **BLOCKS all user story phases**
- **Phase 3â€“7 (User Stories, P0)**: All require Phase 2; complete in priority order
- **Phase 7.5 (Reversal, P0)**: Requires Phase 2; complete before Phase 8 â€” all P0 financial integrity requirements must be done before P1 work begins
- **Phase 8â€“9 (User Stories, P1)**: Require Phase 2; begin after Phase 7.5
- **Phase 10 (Polish)**: Requires Phases 3â€“9 and 7.5

### User Story Dependencies

| Story | After Phase 2 | Also Logically Requires |
|-------|--------------|------------------------|
| US1 (Rule Config) | âœ… | â€” |
| US2+US4 (Calculation) | âœ… | US1 (commission rules must exist) |
| US5+US6 (View) | âœ… | US2 (commission records must exist) |
| US7 (Payout) | âœ… | US2 (pending commissions needed) |
| US8 (Recovery) | âœ… | US7 (payout model and service) |
| US3 (Tier-Based) | âœ… | US2 (extends calculation service) |
| US9 (Report) | âœ… | US2 (commission data to report on) |

### Parallel Opportunities

**Phase 2 parallel groups:**
```
Group A (migrations): T004, T005, T006, T007, T008, T009
Group B (enums):      T011, T012
Group C (exceptions): T013, T014
Group D (events):     T015, T016
Sequential: T017 â†’ T018
```

**Phase 3 parallel groups:**
```
Group A (requests/resource): T021, T022, T023
Sequential: T019 â†’ T020 â†’ T024 â†’ T025
Tests (after T025): T026, T027
```

**Phase 4 parallel groups:**
```
Listeners (after T034): T035, T036
Tests (after T037): T038, T039, T040, T041
```

---

## Implementation Strategy

### MVP Scope (P0 â€” Phases 1â€“7, 7.5)

1. Phase 1 + 2 â†’ Foundation ready
2. Phase 3 (US1) â†’ Admin configures commission rules â€” **Milestone 1**
3. Phase 4 (US2+US4) â†’ System auto-calculates commissions â€” **Milestone 2**
4. Phase 5 (US5+US6) â†’ Commission visibility for agents and admins â€” **Milestone 3**
5. Phase 6 (US7) â†’ Admin approves payouts; agents receive commission â€” **Milestone 4 (MVP)**
6. Phase 7 (US8) â†’ Deferred/failed recovery â€” **Milestone 5 (Production-ready P0)**
7. Phase 7.5 (FR-E14-014) â†’ Commission reversals â€” **Milestone 6 (Full P0)**

### P1 Extensions (Phases 8â€“9)

8. Phase 8 (US3) â†’ Tier-based rates
9. Phase 9 (US9) â†’ Admin commission report
10. Phase 10 remainder â†’ Polish, metrics, static analysis

### Incremental Delivery

Each phase checkpoint produces independently testable and demonstrable functionality. Stop at any checkpoint to validate before continuing.
