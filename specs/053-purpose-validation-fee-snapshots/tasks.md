# Tasks: P04 â€” Purpose Validation, Quotes and Fee Snapshots

**Input**: Design documents from `specs/053-purpose-validation-fee-snapshots/`

**Prerequisites**: plan.md âœ…, spec.md âœ…, research.md âœ…, data-model.md âœ…, contracts/quote-api.md âœ…, quickstart.md âœ…

**User Stories**:
- US1 (P1): Purpose payable validation and enriched obligation
- US2 (P1): Immutable payment quote with fee snapshot
- US3 (P2): Stale quote revalidation before execution
- US4 (P2): Quote retrieval API
- US5 (P3): Purpose handler registration and dev stub

---

## Phase 1: Setup

**Purpose**: Verify/add the `payment` transaction type to the E21 Fees module â€” required before any fee calculation in this feature.

- [x] T001 Verify `payment` case exists in `FeeTransactionType` enum; if missing, add it in `wallet-ecosystem/services/api/app/Modules/Fees/Domain/Enums/FeeTransactionType.php`

**Checkpoint**: `FeesModuleContract::calculateFee('payment', ...)` will not throw an unrecognized transaction type.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared value objects, enums, and exceptions that all user stories depend on. MUST be complete before any story work.

- [x] T002 [P] Create `EnrichedPayableObligation` final readonly class with 8 fields (`amountMinor`, `currencyCode`, `organisationId`, `payerActorId`, `expiresAt: ?\DateTimeImmutable`, `holdReference: ?string`, `beneficiaryAllocations: array`, `metadata: array`) and validation (`amountMinor > 0`) in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/EnrichedPayableObligation.php`
- [x] T003 [P] Create `QuoteStatus` PHP 8.4 enum with cases `Active`, `Expired`, `Superseded`, `Consumed`, `Cancelled`; add `isTerminal(): bool` (Superseded/Consumed/Cancelled are terminal) and `canTransitionTo(self $target): bool` in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/QuoteStatus.php`
- [x] T004 [P] Create `QuoteStaleConfirmRequiredException` (extends `\DomainException`) â€” constructor accepts `PaymentQuoteModel $newQuote`, `int $previousTotalMinor`, `int $newTotalMinor`; exposes those as public readonly properties in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/QuoteStaleConfirmRequiredException.php`
- [x] T005 [P] Create `PurposeNoLongerPayableException` (extends `\DomainException`) with message "Purpose is no longer payable at execution time." in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/PurposeNoLongerPayableException.php`
- [x] T006 Update `BusinessPurposeResolverInterface::resolve()` return type annotation from `PayableObligation` to `EnrichedPayableObligation`; update PHPDoc and import in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/BusinessPurposeResolverInterface.php`
- [x] T007 Update `StubPurposeResolver::resolve()` to return `EnrichedPayableObligation` (forward all five original fields; set `expiresAt = null`, `holdReference = null`, `beneficiaryAllocations = []`) in `wallet-ecosystem/services/api/app/Modules/Payment/Tests/StubPurposeResolver.php`

**Checkpoint**: PHPStan on the foundational files shows 0 type errors. All downstream stories can now use `EnrichedPayableObligation`.

---

## Phase 3: User Story 1 â€” Purpose Payable Validation and Enriched Obligation (Priority: P1) â† MVP

**Goal**: When a payment intent is created, the resolved obligation is validated for expiry and actor ownership server-side. An expired or misowned purpose is rejected before any record is written.

**Independent Test**: Unit test that mocks the purpose resolver to return an expired obligation â†’ `PurposeNotPayableException` is thrown; mock returns a different `payerActorId` than the requesting actor â†’ also throws.

- [x] T008 [US1] Write unit test `EnrichedObligationValidationTest` covering: (a) expired `expiresAt` throws `PurposeNotPayableException`, (b) `payerActorId` mismatch throws `PurposeNotPayableException`, (c) null `expiresAt` is accepted, (d) valid future `expiresAt` with matching actor is accepted in `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/EnrichedObligationValidationTest.php`
- [x] T009 [US1] Add enriched obligation validation to `PaymentOrchestrationService::createIntent()` immediately after `$resolver->resolve(...)`: check `$obligation->expiresAt !== null && $obligation->expiresAt < now()` â†’ throw `PurposeNotPayableException('Purpose has expired.')`, check `$obligation->payerActorId !== $payerActorId` â†’ throw `PurposeNotPayableException('Actor does not own this purpose.')` in `wallet-ecosystem/services/api/app/Modules/Payment/Application/PaymentOrchestrationService.php`

**Checkpoint**: T008 unit tests pass. `createIntent()` rejects expired and misowned purposes before any DB write.

---

## Phase 4: User Story 2 â€” Immutable Payment Quote with Fee Snapshot (Priority: P1)

**Goal**: Every created intent has an immutable, versioned quote record containing base amount, computed fee, total, and a self-contained fee rule snapshot. The consumer can retrieve and verify the exact amount they will be charged.

**Independent Test**: Create an intent via `PaymentOrchestrationService::createIntent()` using a stub resolver and mocked `FeesModuleContract` â†’ `PaymentQuoteModel` record exists in DB with correct `total_amount_minor = base + fee`; `fee_rule_snapshot` JSONB contains `computed_fee_minor`. Call `GET /payment/intents/{id}/quote` â†’ 200 with full breakdown.

- [x] T010 [US2] Create migration `2026_10_21_000006_create_payment_quotes_table.php` â€” `payment.payment_quotes` table with: `id UUID PK`, `payment_intent_id UUID NOT NULL`, `version bigint NOT NULL DEFAULT 1`, `quote_status varchar(32) NOT NULL DEFAULT 'active'`, `base_amount_minor bigint NOT NULL`, `fee_amount_minor bigint NOT NULL DEFAULT 0`, `total_amount_minor bigint NOT NULL`, `currency_code varchar(3) NOT NULL`, `provider_code varchar(64) NOT NULL`, `rounding_mode varchar(32) NOT NULL`, `fee_rule_snapshot jsonb NOT NULL DEFAULT '{}'`, `obligation_snapshot jsonb NOT NULL DEFAULT '{}'`, `expires_at timestampTz NOT NULL`, `superseded_at timestampTz NULL`, `consumed_at timestampTz NULL`, `cancelled_at timestampTz NULL`, `created_at timestampTz NOT NULL DEFAULT now()`; indexes on `(payment_intent_id, quote_status)` and `(expires_at)` WHERE active; CHECK `total_amount_minor > 0` in `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000006_create_payment_quotes_table.php`
- [x] T011 [US2] Create migration `2026_10_21_000007_add_active_quote_id_to_payment_intents.php` â€” add `active_quote_id UUID NULL` column to `payment.payment_intents` (no FK constraint â€” avoids circular dependency on insert order); add index in `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000007_add_active_quote_id_to_payment_intents.php`
- [x] T012 [P] [US2] Create `PaymentQuoteModel` â€” `$connection = 'payment'`, `$table = 'payment.payment_quotes'`, `$incrementing = false`, `$keyType = 'string'`, `$fillable` covering all columns, casts: `quote_status` â†’ `QuoteStatus`, `fee_rule_snapshot/obligation_snapshot` â†’ `array`, datetime columns â†’ `immutable_datetime`; add `intent()` BelongsTo relationship in `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentQuoteModel.php`
- [x] T013 [P] [US2] Create `PaymentQuoteRepositoryInterface` with methods: `save(PaymentQuoteModel): void`, `findById(string $id): ?PaymentQuoteModel`, `findActiveForIntent(string $intentId): ?PaymentQuoteModel`, `findAllForIntent(string $intentId): array` in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentQuoteRepositoryInterface.php`
- [x] T014 [US2] Create `EloquentPaymentQuoteRepository` implementing `PaymentQuoteRepositoryInterface` â€” `findActiveForIntent` queries `WHERE quote_status = 'active'` and `ORDER BY version DESC LIMIT 1` in `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentQuoteRepository.php`
- [x] T015 [US2] Update `PaymentIntentModel` â€” add `active_quote_id` to `$fillable` and `$casts` (`'active_quote_id' => 'string'`); add `activeQuote()` hasOne relationship to `PaymentQuoteModel` via `active_quote_id` in `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentIntentModel.php`
- [x] T016 [US2] Create `QuoteService` with: (a) `createQuote(PaymentIntentModel $intent, EnrichedPayableObligation $obligation, FeeCalculationResult $feeResult, array $feeRuleDetails, string $providerCode, string $correlationId): PaymentQuoteModel` â€” **idempotency guard first**: call `findActiveForIntent($intent->id)` â€” if an active quote already exists for a `pending` intent with `version=1`, return the existing quote (idempotency replay); otherwise build fee snapshot JSONB, verify `total = base + fee` invariant (throw `\DomainException` on mismatch), create new `PaymentQuoteModel` with `version=1`, audit `payment.quote.created`; (b) `supersede(PaymentQuoteModel $old, string $correlationId): void` â€” sets `quote_status = Superseded`, `superseded_at = now()`, saves, audits `payment.quote.superseded`; (c) `refreshQuote(PaymentQuoteModel $old, EnrichedPayableObligation $obligation, FeeCalculationResult $feeResult, array $feeRuleDetails, string $correlationId): PaymentQuoteModel` â€” calls `supersede($old)`, creates new quote at `version = old.version + 1`; (d) `cancelQuotesForIntent(string $intentId, string $correlationId): void` â€” loads all active quotes, marks each `Cancelled`, audits `payment.quote.cancelled`; (e) constructor injects `PaymentQuoteRepositoryInterface`, `AuditServiceInterface`, `ConfigurationServiceInterface` in `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/QuoteService.php`
- [x] T017 [US2] Update `PaymentOrchestrationService::createIntent()` to inject `FeesModuleContract`, `QuoteService`, `PaymentQuoteRepositoryInterface`, and `FeeRuleModel` (for snapshot details); after obligation validation: call `FeesModuleContract::calculateFee('payment', $obligation->amountMinor, $currencyCode, 'customer', $payerActorId)`; **null-safe snapshot**: if `$feeResult->feeRuleId !== null` load `FeeRuleModel::find($feeResult->feeRuleId)` for snapshot details, otherwise build a zero-fee snapshot with `rule_id=null`, `fee_type='none'`, `computed_fee_minor=0`; call `QuoteService::createQuote(...)` (idempotency-safe â€” returns existing quote on replay); update `$intent->active_quote_id`, `$intent->fee_quote_minor`, `$intent->fee_quote_provider_code`, `$intent->version` inside the existing DB transaction in `wallet-ecosystem/services/api/app/Modules/Payment/Application/PaymentOrchestrationService.php`
- [x] T018 [P] [US2] Write `QuoteArithmeticTest` â€” test: `total_amount_minor = base + fee` (200 + 50 = 250), zero fee accepted (`fee = 0, total = base`), arithmetic invariant violation throws `\DomainException`, version starts at 1, version increments on refresh in `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/QuoteArithmeticTest.php`
- [x] T019 [P] [US2] Write `FeeSnapshotRoundingTest` â€” test: floor rounding truncates fractional minor units, round_half_up rounds 0.5 up, min fee applied when computed is below minimum, max fee cap applied when computed exceeds maximum, snapshot `min_fee_applied` and `max_fee_applied` booleans match in `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/FeeSnapshotRoundingTest.php`

**Checkpoint**: T018 and T019 unit tests pass. `createIntent()` now creates both an intent and a quote in one transaction. Quote arithmetic is invariant-protected.

---

## Phase 5: User Story 3 â€” Stale Quote Revalidation Before Execution (Priority: P2)

**Goal**: Before a payment attempt is initiated, the system checks whether the active quote is still within its TTL. Expired quotes trigger re-resolution; if the total changed, execution is blocked with a new quote. If the purpose is gone, the intent is cancelled.

**Independent Test**: Unit test with a mock resolver that returns a different total on second call â†’ `QuoteStaleConfirmRequiredException` thrown carrying new quote. Mock resolver that throws `PurposeNotPayableException` on second call â†’ intent cancelled.

- [x] T020 [US3] Add `revalidateQuote(PaymentIntentModel $intent, BusinessPurposeResolverRegistry $registry, FeesModuleContract $fees, string $providerCode, string $correlationId): RevalidationResult` to `QuoteService` â€” (1) re-resolves obligation via registry; on `PurposeNotPayableException` â†’ cancel intent (set `cancelled_at`, save), cancel active quote (set `quote_status = Cancelled`, `cancelled_at = now()`), audit `payment.quote.cancelled`, throw `PurposeNoLongerPayableException`; (2) re-computes fee; (3) if `newTotal == oldTotal` â†’ call `refreshQuote()` (supersede old row with `superseded_at = now()`, audit `payment.quote.superseded`, create new row version+1) â†’ return `RevalidationResult::unchanged()`; (4) if `newTotal != oldTotal` â†’ supersede old quote (audit `payment.quote.superseded`), create new active quote â†’ throw `QuoteStaleConfirmRequiredException($newQuote, $oldTotal, $newTotal)` in `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/QuoteService.php`
- [x] T021 [US3] Add `consumeQuote(PaymentQuoteModel $quote, string $correlationId): void` to `QuoteService` â€” sets `quote_status = Consumed`, `consumed_at = now()`, saves, audits `payment.quote.consumed` in `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/QuoteService.php`
- [x] T022 [US3] Update `PaymentOrchestrationService::initiateAttempt()` to: (1) after loading the intent, load active quote via `PaymentQuoteRepositoryInterface::findActiveForIntent()`; (2) read TTL from `$this->config->getOrDefault('payment.quote_ttl_seconds', 300)`; (3) if `$quote->expires_at < now()` OR TTL == 0: call `$this->quoteService->revalidateQuote(...)` â€” on `QuoteStaleConfirmRequiredException` re-throw directly; on `PurposeNoLongerPayableException` audit `payment.intent.cancelled` then re-throw; (4) if quote is fresh or refreshed: call `$this->quoteService->consumeQuote($quote, $correlationId)` (audits `payment.quote.consumed`) before the adapter call; (5) also call `cancelQuotesForIntent()` in `PaymentOrchestrationService::cancelAttempt()` and `expireIntent()` to propagate intent cancellation to quotes â€” audit `payment.quote.cancelled` per cancelled quote in `wallet-ecosystem/services/api/app/Modules/Payment/Application/PaymentOrchestrationService.php`
- [x] T023 [US3] Write `QuoteStaleRevalidationTest` â€” test: (a) fresh quote (not expired) â†’ `consumeQuote` called, attempt proceeds; (b) expired quote, same total â†’ `refreshQuote` called silently, no exception; (c) expired quote, different total â†’ `QuoteStaleConfirmRequiredException` thrown with new quote; (d) expired quote, purpose gone â†’ `PurposeNoLongerPayableException` thrown, intent cancelled in `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/QuoteStaleRevalidationTest.php`

**Checkpoint**: T023 unit tests pass. Stale quote path is guarded before any adapter call.

---

## Phase 6: User Story 4 â€” Quote Retrieval API (Priority: P2)

**Goal**: Consumers and admin users can retrieve the current quote for a payment intent via `GET /payment/intents/{id}/quote`, seeing the full fee breakdown before confirming.

**Independent Test**: Call endpoint as payer â†’ 200 with `fee_snapshot.computed_fee_minor`; call as different actor without admin permission â†’ 403; call for non-existent intent â†’ 404.

- [x] T024 [P] [US4] Create `PaymentQuoteController` with `show(Request $request, string $intentId)` â€” loads intent by ID; checks payer ownership (`intent->payer_actor_id == auth actor`) OR admin permission (`payment.intents.read`) â€” returns 403 if neither; loads `$repo->findActiveForIntent($intentId)` â€” returns 422 `NO_ACTIVE_QUOTE` if null; returns 200 with full quote shape including `fee_snapshot` JSONB; no `$this->authorize()` â€” ownership check inline in `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/PaymentQuoteController.php`
- [x] T025 [US4] Add route `Route::get('/intents/{id}/quote', [PaymentQuoteController::class, 'show'])->middleware('identity.require-permission:payment.intents.read')` inside the authenticated payment routes group in `wallet-ecosystem/services/api/routes/modules/payment.php`
- [x] T026 [US4] Update `openapi/v1/payment.yaml`: add `PaymentQuoteResponse` schema (all quote fields), `FeeSnapshotObject` schema (all snapshot fields), `QuoteStaleContext` schema (previous/new total + new quote); add `GET /payment/intents/{id}/quote` path with 200/403/404/422 responses; add `PURPOSE_NOT_PAYABLE`, `PURPOSE_NO_LONGER_PAYABLE`, `QUOTE_STALE_CONFIRM_REQUIRED`, `NO_ACTIVE_QUOTE`, `OBLIGATION_RESOLUTION_FAILED` to ErrorCode enum; add `quote` field to the `PaymentIntentCreateResponse` schema in `wallet-ecosystem/services/api/openapi/v1/payment.yaml`

**Checkpoint**: `GET /payment/intents/{id}/quote` route is registered and returns the correct shape.

---

## Phase 7: User Story 5 â€” Purpose Handler Registration and Dev Stub (Priority: P3)

**Goal**: Domain engineers can register purpose resolvers without modifying Payment internals. A production-blocked dev stub is available for local development that exercises the full quote/validation flow.

**Independent Test**: Construct `DevPurposeStubResolver` in a mocked `production` environment â†’ `ProductionBlockedException` thrown. Construct in `local` â†’ succeeds, `resolve()` returns `EnrichedPayableObligation` with `amountMinor = 10000`, `currencyCode = 'BWP'`.

- [x] T027 [P] [US5] Create `DevPurposeStubResolver` implementing `BusinessPurposeResolverInterface` â€” `supportedPurposeType()` returns `'dev_test_purpose'`; constructor throws `ProductionBlockedException` when `app()->environment('production', 'staging')`; `resolve()` returns `EnrichedPayableObligation` with `amountMinor=10000, currencyCode='BWP', organisationId=DevPurposeStubResolver::TEST_ORG_ID, payerActorId=$actorId, expiresAt=null, holdReference=null, beneficiaryAllocations=[]` in `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Stubs/DevPurposeStubResolver.php`
- [x] T028 [P] [US5] Write `DevStubProductionGuardTest` â€” test: construction in mocked `production` env throws; construction in `local` env returns valid instance; `resolve()` returns correct fixed obligation in `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/DevStubProductionGuardTest.php`
- [x] T029 [US5] Update `PaymentServiceProvider` â€” add `$this->app->bind(PaymentQuoteRepositoryInterface::class, EloquentPaymentQuoteRepository::class)`; add `$this->app->singleton(QuoteService::class)`; in `boot()` method: if `app()->environment('local', 'development')` register `DevPurposeStubResolver` into `BusinessPurposeResolverRegistry` for `dev_test_purpose` in `wallet-ecosystem/services/api/app/Modules/Payment/Providers/PaymentServiceProvider.php`

**Checkpoint**: T028 unit test passes. `DevPurposeStubResolver` is never accessible in production.

---

## Phase 8: Configuration Seeding and Concurrency Test

**Purpose**: Add the `payment.quote_ttl_seconds` configuration key and the constitution-required concurrency safety test (SC-06 / FR-16).

- [x] T030 Add `['key' => 'payment.quote_ttl_seconds', 'value' => '300', 'type' => 'integer', 'is_tbd' => false]` to the platform configuration seed array in `wallet-ecosystem/services/api/database/seeders/PlatformConfigurationSeeder.php`
- [x] T034 Write `ConcurrentPurposeIntentTest` â€” test that two simultaneous `createIntent()` calls for the identical `purpose_type + purpose_reference` result in at most one new intent record (the second call returns the existing intent via the `findActiveForPurpose` guard or the idempotency-key guard); use two mocked resolver calls and assert that the second call receives the existing intent without creating a duplicate quote row in `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/ConcurrentPurposeIntentTest.php`

---

## Phase 9: Polish and Validation (Docker-Gated)

**Purpose**: Static analysis, OpenAPI linting, and end-to-end quickstart verification.

- [x] T031 Run PHPStan level 8 on `app/Modules/Payment/` (`./vendor/bin/phpstan analyse app/Modules/Payment --level=8 --no-progress`); fix all type errors until 0 errors remain â€” static analysis, no Docker required â€” applies to all P04 files in `wallet-ecosystem/services/api/`
- [ ] T032 Run Redocly lint on `openapi/v1/payment.yaml` (`npx @redocly/cli lint openapi/v1/payment.yaml`); fix all schema/path errors until 0 errors remain in `wallet-ecosystem/services/api/openapi/v1/payment.yaml`
- [ ] T033 Run Docker quickstart scenarios SC-01 (intent creates quote), SC-02 (quote retrieval), SC-03 (client amount ignored) from `specs/053-purpose-validation-fee-snapshots/quickstart.md`; all must return expected HTTP status and body structure

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies â€” start immediately
- **Foundational (Phase 2)**: Depends on Setup â€” **BLOCKS all user stories**
- **US1 (Phase 3)**: Depends on Foundational
- **US2 (Phase 4)**: Depends on Foundational + US1 (validation must be in place before quote creation reads obligation)
- **US3 (Phase 5)**: Depends on US2 (needs QuoteService, quote model)
- **US4 (Phase 6)**: Depends on US2 (needs QuoteModel to exist and be queryable)
- **US5 (Phase 7)**: Depends on Foundational; can run in parallel with US3/US4
- **Config (Phase 8)**: Can run at any point after Phase 2
- **Config + Concurrency (Phase 8)**: T030 can run any time after Phase 2; T034 depends on US1+US2
- **Polish (Phase 9)**: Depends on all prior phases; T031 is static analysis (no Docker); T032/T033 are Docker-gated

### User Story Dependencies

- **US1**: Foundational â†’ US1 (no other story dependency)
- **US2**: Foundational + US1 â†’ US2 (enriched obligation must validate before quote saves it)
- **US3**: US2 â†’ US3 (QuoteService::createQuote must exist before revalidateQuote is added)
- **US4**: US2 â†’ US4 (quote table must exist before the endpoint reads from it)
- **US5**: Foundational â†’ US5 (independent of US1â€“US4; only needs the registry and EnrichedPayableObligation)

### Parallel Opportunities within Phases

- T002, T003, T004, T005 (Phase 2): All touch different files â€” run in parallel
- T006, T007 (Phase 2): Different files â€” run in parallel with T002â€“T005
- T010, T011 (Phase 4): Different migrations â€” run in parallel
- T012, T013 (Phase 4): Different interfaces/models â€” run in parallel
- T018, T019 (Phase 4): Different test files â€” run in parallel
- T024 (Phase 6): Controller file independent of route and OpenAPI â€” run T024 in parallel with T025/T026
- T027, T028 (Phase 7): Stub + test can be written together in parallel
- T031, T032 (Phase 9): Independent tools â€” run in parallel

---

## Parallel Example: Phase 2 (Foundational)

```
Run simultaneously:
  T002: Create EnrichedPayableObligation VO
  T003: Create QuoteStatus enum
  T004: Create QuoteStaleConfirmRequiredException
  T005: Create PurposeNoLongerPayableException

Then sequentially:
  T006: Update BusinessPurposeResolverInterface (depends on T002)
  T007: Update StubPurposeResolver (depends on T006)
```

## Parallel Example: Phase 4 (US2)

```
Run simultaneously:
  T010: Create payment_quotes migration
  T011: Create add_active_quote_id migration
  T012: Create PaymentQuoteModel
  T013: Create PaymentQuoteRepositoryInterface
  T018: Write QuoteArithmeticTest
  T019: Write FeeSnapshotRoundingTest

Then sequentially:
  T014: Create EloquentPaymentQuoteRepository (depends T013)
  T015: Update PaymentIntentModel (depends T012)
  T016: Create QuoteService (depends T012, T013, T014)
  T017: Update PaymentOrchestrationService createIntent (depends T016)
```

---

## Implementation Strategy

### MVP (US1 + US2 only â€” Phases 1â€“4 + Config)

1. Complete Phase 1: Setup (T001)
2. Complete Phase 2: Foundational (T002â€“T007)
3. Complete Phase 3: US1 obligation validation (T008â€“T009)
4. Complete Phase 4: US2 quote creation (T010â€“T019)
5. Complete Phase 8: Config seeding (T030)
6. **VALIDATE**: All unit tests pass, quote is created with each intent, fee snapshot is non-null
7. Stop here for a demo/review cycle before adding stale-revalidation and the API

### Incremental Additions

- Add US3 (T020â€“T023): stale-revalidation guard before attempts
- Add US4 (T024â€“T026): consumer-facing quote API
- Add US5 (T027â€“T029): dev stub for local development
- Polish (T031â€“T033): PHPStan, OpenAPI lint, Docker quickstart

---

## Notes

- [P] = task touches different files from other [P] tasks in same phase; no sequential dependency
- US1 â†’ US2 dependency: the obligation validation (T009) is part of `createIntent()`, which is also extended by T017; implement T009 before T017
- `QuoteService` is extended across phases (T016 for createQuote, T020 for revalidateQuote, T021 for consumeQuote, T022 for cancelQuotesForIntent) â€” each task adds methods to the same file; do them sequentially within their phases
- `PaymentOrchestrationService` is also modified in T009, T017, T022 â€” implement in that order
- T031 (PHPStan) will catch any missed type annotations; the return type change on `BusinessPurposeResolverInterface` is the most likely source of level-8 errors
- Docker-gated tasks (T031â€“T033) require the stack running; mark `markTestSkipped()` in any feature test stubs if needed

