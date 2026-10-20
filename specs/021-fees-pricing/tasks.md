# Tasks: E21 — Fees & Pricing

**Input**: `specs/021-fees-pricing/` — spec.md, plan.md, data-model.md, contracts/, research.md, quickstart.md

**Prerequisites**: plan.md ✓ | spec.md ✓ | data-model.md ✓ | contracts/ ✓ | research.md ✓ | quickstart.md ✓

**Organization**: Grouped by user story for independent implementation and testing.

**Tests**: Unit tests included per story. Feature tests (Docker-gated) in Polish phase.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Maps to US-FEE-NNN from spec.md
- All paths relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup (Module Scaffolding)

**Purpose**: Create the Fees module skeleton, schema migration, and service provider registration.

- [x] T001 Create fees schema migration at `app/Modules/Fees/Infrastructure/Migrations/2026_09_28_021000_create_fee_schema.php` — `CREATE SCHEMA IF NOT EXISTS fees` on `platform` connection; `down()` drops schema
- [x] T002 Create `FeesServiceProvider` at `app/Modules/Fees/Providers/FeesServiceProvider.php` — `boot()` loads `routes/modules/fees.php`; `register()` will bind FeesModuleContract (stub binding for now); follow CommissionServiceProvider pattern
- [x] T003 Register `FeesServiceProvider::class` in `bootstrap/providers.php` following the pattern of `CommissionServiceProvider` registration

---

## Phase 2: Foundational (Domain Primitives)

**Purpose**: Domain enums, value objects, contracts, exceptions, events, and permissions seeding. All [P] tasks can run simultaneously.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T004 [P] Create `FeeType` enum (`fixed`, `percentage`) at `app/Modules/Fees/Domain/Enums/FeeType.php`
- [x] T005 [P] Create `FeeRuleStatus` enum (`active`, `expired`, `deactivated`, `superseded`) at `app/Modules/Fees/Domain/Enums/FeeRuleStatus.php`
- [x] T006 [P] Create `FeeSubjectType` enum (`global`, `customer`, `agent`) at `app/Modules/Fees/Domain/Enums/FeeSubjectType.php`
- [x] T007 [P] Create `FeeTransactionType` enum (`transfer`, `funding`, `withdrawal`, `bill_payment`, `qr_payment`, `cash_in`, `cash_out`, `settlement`) at `app/Modules/Fees/Domain/Enums/FeeTransactionType.php`
- [x] T008 [P] Create `FeeStatus` enum (`posted`, `reversed`) at `app/Modules/Fees/Domain/Enums/FeeStatus.php`
- [x] T009 [P] Create domain exceptions: `FeeRuleConflictException`, `FeeRuleNotFoundException`, `InvalidFeeRateException`, `FeeCalculationException` at `app/Modules/Fees/Domain/Exceptions/`
- [x] T010 [P] Create domain events: `FeeRuleCreatedEvent`, `FeeRuleDeactivatedEvent`, `FeeRuleUpdatedEvent` at `app/Modules/Fees/Domain/Events/` — all implement `IntegrationEventInterface`; each carries rule ID, occurredAt, and correlationId; eventNames: 'Fees.FeeRule.Created', 'Fees.FeeRule.Deactivated', 'Fees.FeeRule.Updated'
- [x] T011 Create `FeeCalculationResult` readonly value object at `app/Modules/Fees/Domain/ValueObjects/FeeCalculationResult.php` — fields: `int $feeAmountMinor`, `?string $feeRuleId`, `?string $feeType`, `bool $hasApplicableRule`, `bool $minFeeApplied`, `bool $maxFeeApplied`
- [x] T012 Create `FeesModuleContract` interface at `app/Modules/Fees/Domain/Contracts/FeesModuleContract.php` — three methods: `calculateFee(string $transactionType, int $amountMinor, string $currency, string $subjectType, ?string $subjectId): FeeCalculationResult`; `recordFee(string $transactionId, int $amountMinor, ?string $feeRuleId, string $currency, string $debitAccountRef, string $creditAccountRef, string $idempotencyKey, string $correlationId): string` (feeRuleId nullable — null when no rule matched); `reverseFee(string $transactionId, string $debitAccountRef, string $creditAccountRef, string $idempotencyKey, string $correlationId): void`
- [x] T013 Create `PricingVersion` migration and `PricingVersionModel`: migration at `app/Modules/Fees/Infrastructure/Migrations/2026_09_28_021300_create_pricing_versions.php`; model at `app/Modules/Fees/Infrastructure/Models/PricingVersionModel.php` — fields: id, label, effective_from, effective_to, is_published, created_by, created_at
- [x] T014 Create permissions seeder migration at `app/Modules/Fees/Infrastructure/Migrations/2026_09_28_021400_seed_fees_permissions.php` — seed: `fees.rules.create`, `fees.rules.view`, `fees.rules.update`, `fees.rules.delete`, `fees.calculate`; assign `fees.rules.*` to Operations Admin role; assign `fees.calculate` to all authenticated roles

**Checkpoint**: Domain layer complete. FeesModuleContract interface defined. Permissions seeded.

---

## Phase 3: US-FEE-001 / US-FEE-002 / US-FEE-003 — Fee Rule CRUD (Priority: P0) 🎯 MVP

**Goal**: Operations admin can create, view, update, and deactivate fee rules for any transaction type using either `fixed` or `percentage` fee_type. Duplicate active rules for the same scope are rejected with 409.

**Independent Test**: `POST /v1/fees/rules` with a 0.5% transfer global rule → `status=active`; second POST for same (transfer, BWP, global) → 409; `DELETE /v1/fees/rules/{id}` → 204 and status becomes `deactivated`.

- [x] T015 [P] [US1] Create `fee_rules` migration at `app/Modules/Fees/Infrastructure/Migrations/2026_09_28_021100_create_fee_rules.php` — all columns, CHECK constraints (fee_type, transaction_type, subject_type, status, rate bounds, amount_minor > 0, min ≤ max, min/max only on percentage), partial unique index `WHERE status='active'`, lookup indexes per data-model.md
- [x] T016 [P] [US1] Create `FeeRuleModel` at `app/Modules/Fees/Infrastructure/Models/FeeRuleModel.php` — connection `platform`; table `fees.fee_rules`; casts for enums; fillable fields; `belongsTo(PricingVersionModel::class)` relationship; `hasMany(FeeModel::class)` relationship
- [x] T017 [US1] Implement `FeeRuleService::create(array $data, string $createdBy, string $idempotencyKey): FeeRuleModel` at `app/Modules/Fees/Application/Services/FeeRuleService.php` — wrap in `DB::transaction()` with `lockForUpdate()` to check existing active rule matching scope; throw `FeeRuleConflictException` (→ 409) if found; insert; dispatch `FeeRuleCreatedEvent`
- [x] T018 [US1] Implement `FeeRuleService::update(string $ruleId, array $data): FeeRuleModel` and `FeeRuleService::deactivate(string $ruleId): void` in same file — update sets valid_to or bounds; deactivate sets status=`deactivated`; both dispatch respective events
- [x] T019 [US2] Implement `FeeCalculationService::calculatePercentageFee(int $amountMinor, float $rate, ?int $minFeeMinor, ?int $maxFeeMinor): FeeCalculationResult` at `app/Modules/Fees/Application/Services/FeeCalculationService.php` — formula: `(int) bcadd(bcdiv(bcmul((string)$amountMinor, (string)$rate, 10), '100', 10), '0.5', 0)` (bcmath half-up integer rounding; avoids IEEE 754 float drift at large amounts; requires `ext-bcmath`); min/max application stubs (pass-through until Phase 6)
- [x] T020 [US3] Implement `FeeCalculationService::calculateFixedFee(int $ruleAmountMinor): FeeCalculationResult` in same file — returns the rule's `amount_minor` regardless of transaction amount; sets `feeType='fixed'`, `hasApplicableRule=true`
- [x] T021 [US1] Create `CreateFeeRuleRequest` at `app/Modules/Fees/Infrastructure/Http/Requests/CreateFeeRuleRequest.php` — validation: `fee_type` required enum; `rate` required/numeric/>0/≤100 when `fee_type=percentage` (INVALID_FEE_RATE); `amount_minor` required/integer/>0 when `fee_type=fixed` (INVALID_FEE_AMOUNT); `transaction_type` required enum; `subject_type` required enum; `subject_id` required UUID when `subject_type` is not `global`; `currency` string default BWP; `valid_from` required datetime
- [x] T022 [US1] Create `UpdateFeeRuleRequest` at `app/Modules/Fees/Infrastructure/Http/Requests/UpdateFeeRuleRequest.php` — all fields optional; `valid_to` datetime after valid_from; min/max bound integers (applicable to percentage rules only — error codes per spec)
- [x] T023 [P] [US1] Create `FeeRuleResource` at `app/Modules/Fees/Infrastructure/Http/Resources/FeeRuleResource.php` — JSON response matching `FeeRule` schema in contracts/fees-rules.yaml; casts enums to string values
- [x] T024 [US1] Implement `FeeRuleController` at `app/Modules/Fees/Infrastructure/Http/Controllers/FeeRuleController.php` — thin controller; `index()` paginated with filters (transaction_type, subject_type, status); `store()` → FeeRuleService::create(); `show()` → FeeRuleResource; `update()` → FeeRuleService::update(); `destroy()` → FeeRuleService::deactivate()
- [x] T025 [US1] Create `routes/modules/fees.php` — CRUD routes under `Route::prefix('v1')->middleware(['identity.auth'])`; `POST /v1/fees/rules` and `PATCH /v1/fees/rules/{id}` additionally include the platform `idempotency` middleware (same pattern as `routes/modules/transfer.php`) so duplicate keys replay the cached 201/200 response; each endpoint protected by `identity.require-permission:{slug}`; routes loaded by FeesServiceProvider
- [x] T026 [P] [US2] Write unit tests for `FeeCalculationService::calculatePercentageFee()` at `tests/Unit/Modules/Fees/FeeCalculationServiceTest.php` — BWP 1000 at 0.5% → 500; BWP 7 at 0.5% → 4 (rounding); `hasApplicableRule=true` and `feeType='percentage'`; large-amount precision: `amount_minor=999_999_999` at 0.5% → `4_999_999` (asserts bcmath produces no float drift)
- [x] T027 [P] [US3] Write unit tests for `FeeCalculationService::calculateFixedFee()` at `tests/Unit/Modules/Fees/FeeCalculationServiceTest.php` — BWP 200 with fixed 150 → 150; BWP 5000 with fixed 150 → 150 (amount does not affect output)
- [x] T028 [P] [US1] Write unit tests for `FeeRuleService` at `tests/Unit/Modules/Fees/FeeRuleServiceTest.php` — create success emits FeeRuleCreatedEvent; second create for same active scope throws FeeRuleConflictException; deactivate sets status=deactivated and emits FeeRuleDeactivatedEvent

**Checkpoint**: `POST/GET/PATCH/DELETE /v1/fees/rules` functional. Conflict guard returns 409. Missing permission returns 403. Unit tests pass.

---

## Phase 4: US-FEE-008 — Fee Preview (Priority: P0)

**Goal**: Any authenticated actor submits a transaction context and receives the exact fee that would be charged. Deterministic and stateless.

**Independent Test**: `POST /v1/fees/calculate` with BWP 500 transfer → `fee_amount_minor=25` (0.5% rule); unconfigured transaction_type → `fee_amount_minor=0`, `has_applicable_rule=false`.

- [x] T029 [US8] Implement `FeeCalculationService::resolveRule(string $transactionType, string $currency, string $subjectType, ?string $subjectId): ?FeeRuleModel` — resolution precedence: (1) agent query if subjectType=agent; (2) customer query if subjectType=customer; (3) global fallback; each query filters status='active' AND valid_from <= NOW() AND (valid_to IS NULL OR valid_to >= NOW())
- [x] T030 [US8] Implement `FeeCalculationService::calculate(string $transactionType, int $amountMinor, string $currency, string $subjectType, ?string $subjectId): FeeCalculationResult` — calls resolveRule(); if null → return zero-fee result; if fee_type=percentage → calculatePercentageFee(); if fee_type=fixed → calculateFixedFee(); attach rule_id to result
- [x] T031 [US8] Create `CalculateFeeRequest` at `app/Modules/Fees/Infrastructure/Http/Requests/CalculateFeeRequest.php` — validate: `transaction_type` required enum (UNSUPPORTED_TRANSACTION_TYPE for unknown values); `amount_minor` required integer min:1; `currency` string default BWP; `subject_type` required enum; `subject_id` required UUID when subject_type is not `global`
- [x] T032 [US8] Create `FeeCalculationResource` at `app/Modules/Fees/Infrastructure/Http/Resources/FeeCalculationResource.php` — wraps FeeCalculationResult; outputs fee_amount_minor, fee_type, rule_id, has_applicable_rule, min_fee_applied, max_fee_applied per fees-calculate.yaml schema
- [x] T033 [US8] Implement `FeeCalculateController::calculate()` at `app/Modules/Fees/Infrastructure/Http/Controllers/FeeCalculateController.php` — thin controller; delegates to FeeCalculationService::calculate(); returns 200 FeeCalculationResource
- [x] T034 [US8] Add `POST /v1/fees/calculate` route to `routes/modules/fees.php` protected by `identity.require-permission:fees.calculate`
- [x] T035 [P] [US8] Write unit tests for `FeeCalculationService::resolveRule()` at `tests/Unit/Modules/Fees/FeeCalculationServiceTest.php` — agent rule overrides global; customer rule overrides global; global fallback when no subject-specific rule; null when no rule at all; expired rule not returned; not-yet-effective rule not returned

**Checkpoint**: `POST /v1/fees/calculate` returns deterministic fee. SC-7 (no rule → zero) from quickstart.md passes.

---

## Phase 5: US-FEE-009 — Fee Ledger Recording (Priority: P0)

**Goal**: When a transaction completes, Fee record is created and a balanced double-entry journal is posted via E07 LedgerModuleContract. Recording is idempotent on transaction_id.

**Independent Test**: Call `FeesModuleContract::recordFee()` for a transfer transaction; `Fee` record created with `status=posted`; second call with same `transaction_id` returns existing fee ID without duplicate.

- [x] T036 [US9] Create `fees` migration at `app/Modules/Fees/Infrastructure/Migrations/2026_09_28_021200_create_fees.php` — `fees.fees` table per data-model.md; UNIQUE on `transaction_id`; CHECK status; CHECK amount_minor > 0; indexes
- [x] T037 [US9] Create `FeeModel` at `app/Modules/Fees/Infrastructure/Models/FeeModel.php` — connection `platform`; table `fees.fees`; casts for FeeStatus enum; `belongsTo(FeeRuleModel::class)` relationship
- [x] T038 [P] [US9] Create `FeePostedEvent` and `FeeReversedEvent` at `app/Modules/Fees/Domain/Events/` — both implement `IntegrationEventInterface`; FeePostedEvent: feeId, transactionId, amountMinor, currency, ledgerEntryId, occurredAt, correlationId; FeeReversedEvent: feeId, transactionId, reversalLedgerEntryId, occurredAt, correlationId; eventName() = 'Fees.Fee.Posted' / 'Fees.Fee.Reversed'
- [x] T039 [US9] Implement `FeeLedgerService::recordFee(string $transactionId, int $amountMinor, ?string $feeRuleId, string $currency, string $debitAccountRef, string $creditAccountRef, string $idempotencyKey, string $correlationId): string` at `app/Modules/Fees/Application/Services/FeeLedgerService.php` — if amountMinor=0 return ''; check UNIQUE(transaction_id) → if exists return existing fee ID (idempotent); wrap in `DB::transaction()`: call LedgerModuleContract::postEntry(entry_type='fee', debit $debitAccountRef, credit $creditAccountRef, idempotencyKey=$idempotencyKey) then INSERT FeeModel with returned ledger_entry_id; dispatch FeePostedEvent (implements IntegrationEventInterface) via OutboxEventPublisher after commit. Outbox delivery is automatic: EventPublisherInterface is bound to OutboxEventPublisher globally; no per-event registration required. Note: callers derive $idempotencyKey as `Uuid::uuid5(Uuid::NAMESPACE_URL, $transactionId.':fee')`
- [x] T040 [US9] Implement `FeeLedgerService::reverseFee(string $transactionId, string $debitAccountRef, string $creditAccountRef, string $idempotencyKey, string $correlationId): void` — load FeeModel by transaction_id; if status=reversed return (idempotent); wrap in `DB::transaction()`: post compensating entry (entry_type='fee', debit $debitAccountRef=revenue:fees:{currency}, credit $creditAccountRef=wallet:{customerId}), UPDATE status=reversed, reversal_ledger_entry_id; dispatch FeeReversedEvent via outbox after commit
- [x] T041 [US9] Create `FeesServiceFacade` (implements `FeesModuleContract`) at `app/Modules/Fees/Application/Services/FeesServiceFacade.php` — delegates calculateFee() to FeeCalculationService; delegates recordFee() and reverseFee() to FeeLedgerService; bind in `FeesServiceProvider::register()` via `$app->bind(FeesModuleContract::class, FeesServiceFacade::class)`
- [x] T042 [P] [US9] Write unit tests for `FeeLedgerService::recordFee()` at `tests/Unit/Modules/Fees/FeeLedgerServiceTest.php` — first-time call creates FeeModel and calls postEntry; second call with same transaction_id returns existing ID without duplicate; amount_minor=0 returns early without calling postEntry
- [x] T043 [P] [US9] Write unit tests for `FeeLedgerService::reverseFee()` at `tests/Unit/Modules/Fees/FeeLedgerServiceTest.php` — compensating entry has reversed debit/credit vs original; second call for already-reversed fee is idempotent
- [x] T044 [US9] Integrate E09 `TransferConfirmationService` at `app/Modules/Transfer/Application/Services/TransferConfirmationService.php` — within the same `DB::transaction()` as the transfer ledger commit, call `recordFee($txId, $feeAmount, $ruleId, $currency, debitAccountRef=config.transfer.fee_account.{currency}, creditAccountRef='revenue:fees:{currency}', idempotencyKey=Uuid::uuid5(NAMESPACE_URL, $txId.':fee'), correlationId)`. NOTE: reverseFee() deferred — `TransferCancellationService` only cancels pre-confirmation transfers (fee not yet recorded); a completed-transfer admin reversal service does not yet exist. reverseFee() must be added when that service is built.
- [x] T045 [P] [US9] Integrate E08 `FundingCreditService` at `app/Modules/Funding/Application/Services/FundingCreditService.php` — call `recordFee()` after credit posting (skip when fee_amount_minor=0). NOTE: reverseFee() deferred — `FundingCancellationService` cancels pre-credit attempts; a post-credit admin reversal service does not yet exist.
- [x] T046 [P] [US9] Integrate E10 `WithdrawalCallbackService` at `app/Modules/Withdrawal/Application/Services/WithdrawalCallbackService.php` — call `recordFee()` in `processSuccess()` after receipt creation. NOTE: reverseFee() deferred — `WithdrawalCancellationService` cancels pre-processing withdrawals; a post-completion admin reversal service does not yet exist.
- [x] T047 [P] [US9] Integrate E15 `ServicePaymentService` at `app/Modules/Service/Application/Services/ServicePaymentService.php` — call `recordFee()` within the inner DB::transaction() after tx status update. NOTE: reverseFee() deferred — no completed-payment reversal service exists yet.
- [x] T048 [P] [US9] Integrate E16 `QrPaymentService` at `app/Modules/Qr/Application/Services/QrPaymentService.php` — call `recordFee()` between QR status update and receipt creation. NOTE: reverseFee() deferred — no completed-QR-payment reversal service exists yet.
- [ ] T044b [US9] Wire `reverseFee()` in E09 admin transfer reversal service when built — idempotencyKey: `Uuid::uuid5(NAMESPACE_URL, $txId.':fee:reversal')`; debitAccountRef=`revenue:fees:{currency}`; creditAccountRef=`wallet:{customerId}` [deferred — no admin reversal service exists]
- [ ] T045b [US9] Wire `reverseFee()` in E08 admin funding reversal service when built [deferred]
- [ ] T046b [US9] Wire `reverseFee()` in E10 admin withdrawal reversal service when built [deferred]
- [ ] T047b [US9] Wire `reverseFee()` in E15 admin service-payment reversal service when built [deferred]
- [ ] T048b [US9] Wire `reverseFee()` in E16 admin QR-payment reversal service when built [deferred]

**Checkpoint**: Unit tests for FeeLedgerService pass. TransferConfirmationService recordFee() integration complete. SC-9 from quickstart.md runnable.

---

## Phase 6: US-FEE-004 / US-FEE-005 — Min/Max Fee Bounds (Priority: P1)

**Goal**: Percentage fee rules can specify a floor and ceiling; fees never fall outside the configured bounds.

**Independent Test**: 0.5% transfer rule, min=100, max=5000; BWP 100 transfer → fee=100 (floor applied, minFeeApplied=true); BWP 50,000 transfer → fee=5000 (ceiling applied, maxFeeApplied=true).

- [x] T049 [P] [US4] Add `min_fee_minor` validation to `CreateFeeRuleRequest` and `UpdateFeeRuleRequest` — integer > 0; applicable to percentage rules only (MIN_FEE_NOT_APPLICABLE_TO_FIXED_RULES on fixed); must be ≤ max_fee_minor when both set (FEE_BOUNDS_CONFLICT)
- [x] T050 [P] [US5] Add `max_fee_minor` validation to same request classes — integer > 0; applicable to percentage rules only (MAX_FEE_NOT_APPLICABLE_TO_FIXED_RULES); must be ≥ min_fee_minor when both set (FEE_BOUNDS_CONFLICT)
- [x] T051 [US4] Update `FeeCalculationService::calculatePercentageFee()` to apply min floor: if `rawFee < min_fee_minor` → `fee = min_fee_minor`; set `minFeeApplied=true` on FeeCalculationResult
- [x] T052 [US5] Update `FeeCalculationService::calculatePercentageFee()` to apply max ceiling: if `fee > max_fee_minor` → `fee = max_fee_minor`; set `maxFeeApplied=true` on FeeCalculationResult
- [x] T053 [P] [US4] Write unit tests for min floor at `tests/Unit/Modules/Fees/FeeCalculationServiceTest.php` — 50 minor units < floor 100 → 100 with minFeeApplied=true; 500 > floor → 500 with minFeeApplied=false; PATCH fixed rule with min_fee_minor → 422
- [x] T054 [P] [US5] Write unit tests for max ceiling at `tests/Unit/Modules/Fees/FeeCalculationServiceTest.php` — 25000 > ceiling 5000 → 5000 with maxFeeApplied=true; 25 < ceiling → 25 with maxFeeApplied=false; min > max → FEE_BOUNDS_CONFLICT

**Checkpoint**: SC-5 (floor) and SC-6 (ceiling) from quickstart.md pass.

---

## Phase 7: US-FEE-007 — Agent Fee Rule (Priority: P1)

**Goal**: Admin configures a fee rule scoped to a specific agent; agent-specific rule takes precedence over global for the same transaction type.

**Independent Test**: Per-agent fixed 50 minor-unit rule for agent B; `POST /v1/fees/calculate` with `subject_type=agent, subject_id={agentB}` → fee=50; same for agent C (no rule) → global rule applies.

- [x] T055 [US7] Add test scenario to `tests/Unit/Modules/Fees/FeeRuleServiceTest.php`: second active per-agent rule for same (agent, transaction_type, currency) returns 409 (conflict guard from T017 already covers this; T055 exercises agent subject_type specifically)
- [x] T057 [P] [US7] Write unit tests for agent-specific rule resolution at `tests/Unit/Modules/Fees/FeeCalculationServiceTest.php` — agent B gets per-agent override; agent C fallback to global; resolveRule() agent-first precedence verified (T029/T056 implementation confirmed correct)

**Checkpoint**: Per-agent fee rules created; fee preview correctly uses agent-specific rule over global.

---

## Phase 8: US-FEE-006 — Customer-Specific Fee Override (Priority: P2)

**Goal**: Admin configures a fee override for a specific customer; customer-specific rule takes precedence over global but not over an agent rule.

**Independent Test**: Global 0.5% + customer A 0.1% override; `POST /v1/fees/calculate` with `subject_type=customer, subject_id={A}` → fee=10 for BWP 1000; customer B → fee=50 (global).

- [x] T058 [US6] Add test scenario to `tests/Unit/Modules/Fees/FeeRuleServiceTest.php`: second active per-customer rule for same (customer, transaction_type, currency) returns 409 (conflict guard from T017; T058 exercises customer subject_type specifically)
- [x] T060 [P] [US6] Write unit tests for customer-specific rule at `tests/Unit/Modules/Fees/FeeCalculationServiceTest.php` — customer A gets 0.1% override; customer B unaffected by A's override; SC-8 from quickstart.md (10 minor units for BWP 1000); resolveRule() customer-after-agent precedence verified (T029/T059 implementation confirmed correct)

**Checkpoint**: Per-customer fee overrides work. SC-8 from quickstart.md passes.

---

## Phase 9: Polish & Cross-Cutting Concerns

- [x] T061 [P] Create consolidated OpenAPI spec at `openapi/v1/fees.yaml` — merge contracts/fees-rules.yaml and contracts/fees-calculate.yaml; shared schemas; two security schemes; placed alongside existing openapi/v1/*.yaml files
- [x] T062 [P] Add `@deprecated` comment to `config/reconciliation.php` `default_fee_rate` key — comment: "Deprecated by E21. Read from fees.fee_rules WHERE transaction_type='settlement' via FeesModuleContract::calculateFee(). Only used by NullSettlementAdapter."
- [x] T063 [P] Create seeder migration at `app/Modules/Fees/Infrastructure/Migrations/2026_09_28_021500_seed_settlement_fee_rule.php` — bootstrap `transaction_type='settlement', fee_type='fixed', amount_minor=1` fee rule placeholder via `FeeRuleModel::create()` (amount_minor=1 satisfies the `> 0` CHECK constraint; no DB bypass needed); add migration comment: "Bootstrap placeholder — admin must update amount_minor to real settlement fee before activating settlement adapter"
- [x] T064 [P] Create `FeeTestSeeder` at `database/seeders/FeeTestSeeder.php` — seeds: global 0.5% transfer rule (min=100, max=5000); fixed 150 bill_payment rule; customer-specific 0.1% transfer override; per-agent 50 cash_in rule; called from `DatabaseSeeder` in non-production environments
- [x] T065 [P] Create Admin UI page — Fee Rules list; route file at `wallet-ecosystem/apps/admin/app/(admin)/fees/rules/page.tsx`; component at `wallet-ecosystem/apps/admin/src/modules/fees/pages/fee-rules-list.tsx` — paginated table; columns: transaction_type, fee_type, rate/amount, subject_type, status; filter by transaction_type and status; "Create Rule" button
- [x] T066 [P] Create Admin UI page — Create Fee Rule form; route file at `wallet-ecosystem/apps/admin/app/(admin)/fees/rules/create/page.tsx`; component at `wallet-ecosystem/apps/admin/src/modules/fees/pages/fee-rule-create.tsx` — fee_type radio (percentage shows rate + min/max; fixed shows amount_minor); subject_type radio (customer/agent shows subject_id input); transaction_type select; valid_from/valid_to pickers; submits POST /v1/fees/rules with Idempotency-Key header
- [X] T067 Write feature tests at `tests/Feature/Modules/Fees/FeeRuleTest.php` — create 201; 409 conflict; 422 invalid rate; 422 min > max; 403 non-admin; 401 unauthenticated; GET index with filters; PATCH bounds; DELETE deactivate; concurrent recordFee: two simultaneous requests with same transaction_id → exactly one Fee row and one ledger entry (P4 concurrency requirement) [Docker-gated]
- [X] T068 Write feature tests at `tests/Feature/Modules/Fees/FeeCalculateTest.php` — SC-5 min floor; SC-6 max ceiling; SC-7 no rule → 0; SC-8 customer override; 422 unsupported transaction_type [Docker-gated]
- [X] T069 Run PHPStan level-6 on `app/Modules/Fees/` — 0 errors [Docker-gated]
- [X] T070 Run quickstart.md SC-1 through SC-12 validation against live Docker stack — 16/16 tests pass [Docker-gated]

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 — BLOCKS all user story phases
- **Phase 3 (US-FEE-001/002/003)**: After Phase 2; provides FeeRuleModel and FeeCalculationService
- **Phase 4 (US-FEE-008)**: After Phase 2 + Phase 3 (FeeCalculationService must exist)
- **Phase 5 (US-FEE-009)**: After Phase 2 + Phase 3 (FeeRuleModel needed) + Phase 4 (calculate() needed)
- **Phase 6 (US-FEE-004/005)**: After Phase 3 (extends calculatePercentageFee)
- **Phase 7 (US-FEE-007)**: After Phase 4 (resolveRule already handles agent priority)
- **Phase 8 (US-FEE-006)**: After Phase 7 (precedence chain complete)
- **Phase 9 (Polish)**: After all story phases

### Parallel Opportunities Within Phase 3

```
Batch 1 (after Phase 2): T015 (migration) + T016 (model)
Batch 2 (after T015+T016): T017+T018 (FeeRuleService) || T019 (percentage) || T020 (fixed) || T023 (resource)
Batch 3 (after T017-T020): T026 (unit test %) || T027 (unit test fixed) || T028 (unit test service)
```

### Parallel Opportunities Within Phase 5

```
After T039+T040: T042 (test recordFee) || T043 (test reverseFee) || T045-T048 (module integrations)
```

---

## Implementation Strategy

### MVP First (Phases 1–4)

1. Complete Phase 1 (Setup)
2. Complete Phase 2 (Foundational)
3. Complete Phase 3 (Fee Rule CRUD)
4. **STOP and VALIDATE**: Fee rules can be created/listed/updated/deactivated
5. Complete Phase 4 (Fee Preview)
6. **STOP and VALIDATE**: `POST /v1/fees/calculate` returns correct fees; SC-5/SC-6/SC-7 pass
7. Deploy — fee configuration and preview are live

### Incremental Delivery

- Phase 3 → Fee rule admin CRUD live (MVP)
- Phase 4 → Fee preview live (customers see fee before confirming)
- Phase 5 → Fee ledger recording live (fees deducted and posted with transactions)
- Phase 6 → Bounds live (min floor + max ceiling)
- Phase 7 → Agent fee rules live
- Phase 8 → Customer fee overrides live
- Phase 9 → OpenAPI, Admin UI, Docker tests

---

## Notes

- **T067–T070**: Docker-gated — leave as `[ ]` until Docker stack is available
- **E07 no-change**: `JournalEntryType::Fee = 'fee'` and DB CHECK already include `'fee'` — no E07 migration needed (research.md Decision 1)
- **Fee reversals**: Use compensating `postEntry(entry_type='fee')` with reversed debit/credit (research.md Decision 2) — no new E07 contract extension
- **US-FEE-001/002/003** share `POST /v1/fees/rules` — `fee_type` discriminator controls percentage vs fixed logic
- **E20 migration** (T062/T063): `config('reconciliation.default_fee_rate')` deprecated; NullSettlementAdapter unaffected
- **Cross-module integrations** (T044–T048): Additive changes to E08/E09/E10/E15/E16 — existing tests must not regress
