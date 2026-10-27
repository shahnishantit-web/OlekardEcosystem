# Tasks: P02 — Payment Schema and API Contracts

**Input**: Design documents from `specs/051-payment-schema-api-contracts/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Mode**: Implement — PHP migrations, Eloquent models, PHP enums, OpenAPI YAML

**Note**: Tests are included per spec requirement SC-01 through SC-07 (unit tests for enums, feature test for migrations, optimistic lock test).

---

## Phase 1: Setup (Verification Prerequisites)

**Purpose**: Verify existing E48 baseline before writing any new code.

- [X] T001 [P] Verify E48 migrations exist at `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/` — confirm 8 files present (2026_10_18_000001 through 000008); record baseline
- [X] T002 [P] Read `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/PaymentIntentStatus.php` — confirm PHP 8.1 backed string enum pattern (namespace, enum declaration, `isTerminal()`, `canTransitionTo()` methods) before writing new enums
- [X] T003 [P] Read `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentIntentModel.php` — confirm current `$fillable` columns and `casts()` method structure before modification

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Confirm OpenAPI baseline and PHPStan state before making changes.

**⚠️ CRITICAL**: Phases 3–6 must not start until Phase 2 confirms the baseline.

- [X] T004 [P] Read `wallet-ecosystem/services/api/openapi/v1/payment.yaml` — record current `NextAction` schema (lines 181–215), `PaymentIntentResponse`, and existing component names; confirm no existing discriminated union before adding one
- [X] T005 [P] Verify `wallet-ecosystem/services/api/app/Modules/Payment/Tests/` directory exists and confirm PHPStan baseline: `./vendor/bin/phpstan analyse app/Modules/Payment --level=8 --no-progress` returns 0 errors (Docker-gated — note result, proceed if Docker unavailable)

**Checkpoint**: E48 baseline confirmed. US1–US4 implementation may now begin.

---

## Phase 3: US1 — Separate Finance, Accounting, and Fulfilment States (Priority: P1) 🎯 MVP

**Goal**: Add `accounting_status` and `fulfilment_status` columns to `payment_intents` as independent state dimensions, with CHECK constraints and PHP 8.1 backed enums.

**Independent Test (SC-01, SC-02)**: Migration runs clean; CHECK constraint rejects `'invalid_value'` for `accounting_status`; existing rows default to `'pending_ledger'` and `'pending'` respectively.

- [X] T006 [P] [US1] Create `PaymentAccountingStatus` PHP enum in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/PaymentAccountingStatus.php` — cases: `PendingLedger='pending_ledger'`, `LedgerPosted='ledger_posted'`, `LedgerFailed='ledger_failed'`; methods: `isTerminal(): bool`, `canTransitionTo(self $target): bool` following PaymentIntentStatus.php pattern
- [X] T007 [P] [US1] Create `PaymentFulfilmentStatus` PHP enum in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/PaymentFulfilmentStatus.php` — cases: `Pending='pending'`, `Fulfilled='fulfilled'`, `FulfilmentFailed='fulfilment_failed'`; methods: `isTerminal(): bool`, `canTransitionTo(self $target): bool`
- [X] T008 [US1] Create additive migration `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000001_add_multi_state_to_payment_intents.php` — `protected $connection = 'payment'`; `up()` adds `accounting_status varchar(32) NOT NULL DEFAULT 'pending_ledger'` and `fulfilment_status varchar(32) NOT NULL DEFAULT 'pending'` via `Schema::connection('payment')->table(...)`; adds CHECK constraints via `DB::connection('payment')->statement(...)` per contracts/migration-contracts.md; adds 2 partial indices; `down()` drops columns, constraints, indices with IF EXISTS guards
- [X] T009 [US1] Update `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentIntentModel.php` — add `'accounting_status'` and `'fulfilment_status'` to `$fillable`; add to `casts()`: `'accounting_status' => PaymentAccountingStatus::class`, `'fulfilment_status' => PaymentFulfilmentStatus::class` (requires T006, T007)
- [X] T010 [P] [US1] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/PaymentAccountingStatusTest.php` — test `isTerminal()` for all 3 cases; test `canTransitionTo()` valid transitions (PendingLedger→LedgerPosted, PendingLedger→LedgerFailed); test invalid transition (LedgerPosted cannot transition to PendingLedger) (requires T006)
- [X] T011 [P] [US1] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/PaymentFulfilmentStatusTest.php` — test `isTerminal()` for all 3 cases; test `canTransitionTo()` valid transitions (Pending→Fulfilled, Pending→FulfilmentFailed); test invalid transition (Fulfilled cannot transition to Pending) (requires T007)

**Checkpoint**: `accounting_status` and `fulfilment_status` columns exist in schema. `PaymentIntentModel` casts them to typed enums. Unit tests pass. P08 can query `accounting_status` independently. SC-01/SC-02 partially satisfied.

---

## Phase 4: US2 — Fee Snapshot and Optimistic Locking (Priority: P1)

**Goal**: Add `version`, `fee_quote_minor`, and `fee_quote_provider_code` columns to `payment_intents`; optimistic locking pattern verified by test.

**Independent Test (SC-01, SC-03)**: Migration runs clean; `fee_quote_minor < 0` is rejected; updating with stale `version` returns 0 affected rows.

- [X] T012 [US2] Create additive migration `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000002_add_fee_snapshot_and_version_to_payment_intents.php` — `protected $connection = 'payment'`; `up()` adds `version bigint NOT NULL DEFAULT 1`, `fee_quote_minor bigint NOT NULL DEFAULT 0`, `fee_quote_provider_code varchar(64) NULL`; adds CHECK `fee_quote_minor >= 0`; `down()` drops all 3 columns and the CHECK (IF EXISTS guards) per contracts/migration-contracts.md
- [X] T013 [US2] Update `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentIntentModel.php` — add `'version'`, `'fee_quote_minor'`, `'fee_quote_provider_code'` to `$fillable`; add to `casts()`: `'version' => 'integer'`, `'fee_quote_minor' => 'integer'` (requires T009 completion — same file; requires T012 migration pattern understanding)
- [X] T014 [US2] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/PaymentIntentOptimisticLockTest.php` — test that two sequential DB updates using `->where('version', 1)` pattern: first returns 1 affected row and increments version to 2; second returns 0 affected rows (stale version detected); uses a seeded test database row (see quickstart.md Scenario 4 for the pattern)

**Checkpoint**: `version`, `fee_quote_minor`, `fee_quote_provider_code` present on `payment_intents`. Model casts are correct. Optimistic lock test passes. SC-01/SC-03 partially satisfied.

---

## Phase 5: US3 — Payment Allocation Table (Priority: P1)

**Goal**: Create `payment.payment_allocations` table with FK, unique constraints, and status enum; new Eloquent model + repository interface; relation added to PaymentIntentModel.

**Independent Test (SC-01, SC-02)**: Migration creates table; `amount_minor = 0` rejected; duplicate idempotency_key rejected; `allocations()` relation loads correctly from PaymentIntentModel.

- [X] T015 [P] [US3] Create `PaymentAllocationStatus` PHP enum in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/PaymentAllocationStatus.php` — cases: `Allocated='allocated'`, `Settled='settled'`, `Refunded='refunded'`, `Cancelled='cancelled'`; methods: `isTerminal(): bool`, `canTransitionTo(self $target): bool`
- [X] T016 [P] [US3] Create migration `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000003_create_payment_allocations_table.php` — `protected $connection = 'payment'`; `up()` creates `payment.payment_allocations` with all 10 columns per data-model.md; adds FK constraint, CHECK constraints for `amount_minor`, `status`, `allocation_type`, `recipient_type`; adds 3 indices (idempotency unique, partial unique intent+type+recipient, intent+status); `down()` drops table only (does NOT affect payment_intents)
- [X] T017 [US3] Create `PaymentAllocationModel` in `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentAllocationModel.php` — final class; `$connection = 'payment'`; `$table = 'payment.payment_allocations'`; `$keyType = 'string'`; `$incrementing = false`; `$fillable` with all 10 writable columns; `casts()`: `'status' => PaymentAllocationStatus::class`, `'amount_minor' => 'integer'`, datetime casts; `belongsTo(PaymentIntentModel::class, 'payment_intent_id')` relation (requires T015, T016)
- [X] T018 [P] [US3] Create repository interface `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentAllocationRepositoryInterface.php` — methods: `create(array $data): PaymentAllocationModel`, `findByIdempotencyKey(string $key): ?PaymentAllocationModel`, `findByIntentId(string $intentId): Collection` — typed returns; DocBlock each method (requires T017 model class name)
- [X] T019 [US3] Update `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentIntentModel.php` — add `public function allocations(): HasMany { return $this->hasMany(PaymentAllocationModel::class, 'payment_intent_id'); }` (requires T009 + T013 completion — same file; requires T017)
- [X] T020 [P] [US3] Create unit test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/PaymentAllocationStatusTest.php` — test `isTerminal()` for all 4 cases (Allocated=false, Settled/Refunded/Cancelled=true); test `canTransitionTo()` valid transitions (Allocated→Settled, Allocated→Refunded, Allocated→Cancelled); test invalid transition (Settled cannot transition) (requires T015)
- [X] T021 [US3] Create feature test `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Feature/PaymentSchemaP02Test.php` — tests: (1) all 3 P02 migrations run on test DB; (2) `payment_intents` has 5 new columns; (3) `payment_allocations` table exists with correct column count; (4) `accounting_status = 'invalid'` INSERT is rejected with constraint violation; (5) duplicate `idempotency_key` on payment_allocations is rejected; (6) `fee_quote_minor = -1` INSERT is rejected — uses `RefreshDatabase` or test-specific DB connection (requires T008, T012, T016, Docker-gated)

**Checkpoint**: `payment_allocations` table exists. Model, enum, repository interface complete. `PaymentIntentModel::allocations()` relation works. SC-01/SC-02 fully satisfied.

---

## Phase 6: US4 — Complete Versioned API and Event Contracts (Priority: P2)

**Goal**: Extend `openapi/v1/payment.yaml` with typed next_action union, new components, updated PaymentIntentResponse; create `openapi/v1/payment-events.yaml` with 4 event schemas.

**Independent Test (SC-04, SC-05, SC-06)**: Both YAML files lint with 0 errors; 4 next_action types present; `payment.confirmed` and `payment.refund.confirmed` event schemas exist.

- [X] T022 [US4] Replace `NextAction` schema in `wallet-ecosystem/services/api/openapi/v1/payment.yaml` — remove existing flat `NextAction` object; add `oneOf` discriminated union with `discriminator.propertyName: type`; add 4 typed sub-schemas: `NextActionRedirect`, `NextActionOtpVerification`, `NextActionPollingRequired`, `NextActionWalletStepUp` per contracts/openapi-extension-contract.md (requires T004 baseline read)
- [X] T023 [P] [US4] Add `PaginatedMeta`, `PaginatedLinks`, and `ErrorCode` components to `wallet-ecosystem/services/api/openapi/v1/payment.yaml` `components.schemas` section — `ErrorCode` enum with 15 error code values per data-model.md; `PaginatedMeta` with 4 required integer fields; `PaginatedLinks` with 4 nullable URI fields; ALSO update `GET /admin/payment/providers` and `GET /admin/payment/reconciliation/exceptions` 200-response schemas from `{data: array}` to `{data: array, meta: {$ref: PaginatedMeta}, links: {$ref: PaginatedLinks}}` (both describe "Paginated list of..." in their summaries but currently return bare arrays)
- [X] T024 [P] [US4] Update `PaymentIntentResponse` schema in `wallet-ecosystem/services/api/openapi/v1/payment.yaml` — add `accounting_status` (string, enum values), `fulfilment_status` (string, enum values), `fee_quote_minor` (integer, minimum 0) fields to the existing schema
- [X] T025 [US4] Update `ErrorEnvelope` schema in `wallet-ecosystem/services/api/openapi/v1/payment.yaml` — change `error.code` property from inline `type: string` to `$ref: '#/components/schemas/ErrorCode'` (requires T023)
- [X] T026 [P] [US4] Add `security: [{BearerAuth: []}]` block to each path in `wallet-ecosystem/services/api/openapi/v1/payment.yaml` that is missing it (note: all 12 paths already have `security:` or are intentionally unauthenticated — `/payment/callbacks/{providerCode}`); add `x-required-permission` annotation to admin paths per contracts/openapi-extension-contract.md; VERIFY that all 5 existing financial mutation endpoints still carry `- $ref: '#/components/parameters/IdempotencyKeyHeader'` after any schema edits (POST /payment/intents, POST /payment/intents/{intentId}/attempts, POST /payment/intents/{intentId}/refunds, POST /admin/payment/providers, PUT /admin/payment/providers/{id})
- [X] T027 [US4] Create `wallet-ecosystem/services/api/openapi/v1/payment-events.yaml` — OpenAPI 3.1.0 header with title "OleKard Payment — Event Schemas" version "1.0.0"; add `PaymentEventEnvelope` base schema with required fields: `event_id`, `event_type`, `schema_version`, `occurred_at`, `correlation_id`, `data` per contracts/openapi-extension-contract.md
- [X] T028 [P] [US4] Add `PaymentConfirmedEvent` and `PaymentFailedEvent` schemas to `wallet-ecosystem/services/api/openapi/v1/payment-events.yaml` — each uses `allOf: [$ref: PaymentEventEnvelope]` + typed `data` object per contracts/openapi-extension-contract.md (requires T027)
- [X] T029 [P] [US4] Add `RefundConfirmedEvent` and `PaymentReversedEvent` schemas to `wallet-ecosystem/services/api/openapi/v1/payment-events.yaml` — each uses `allOf: [$ref: PaymentEventEnvelope]` + typed `data` object per contracts/openapi-extension-contract.md (requires T027)

**Checkpoint**: Both YAML files written. NextAction discriminated union complete. 4 event schemas defined. SC-04/SC-05/SC-06 ready to verify.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Run validation gates, confirm PHPStan clean, verify quickstart scenarios.

- [ ] T030 Run PHPStan on Payment module `./vendor/bin/phpstan analyse app/Modules/Payment --level=8 --no-progress` from `wallet-ecosystem/services/api/` — MUST produce 0 errors; fix any type errors in new enum or model files (Docker-gated)
- [ ] T031 [P] Lint `wallet-ecosystem/services/api/openapi/v1/payment.yaml` with `npx @redocly/cli lint openapi/v1/payment.yaml` — MUST produce 0 errors (Docker-gated; requires Node.js with @redocly/cli)
- [ ] T032 [P] Lint `wallet-ecosystem/services/api/openapi/v1/payment-events.yaml` with `npx @redocly/cli lint openapi/v1/payment-events.yaml` — MUST produce 0 errors (Docker-gated)
- [ ] T033 Run payment module unit tests `./vendor/bin/phpunit app/Modules/Payment/Tests/Unit/ --no-coverage` from `wallet-ecosystem/services/api/` — all 4 new unit tests must pass (T010, T011, T014, T020) (Docker-gated)
- [ ] T034 Run quickstart Scenario 3 (CHECK constraint rejection) per `specs/051-payment-schema-api-contracts/quickstart.md` — confirm `accounting_status = 'invalid_value'` INSERT produces constraint violation; record pass/fail (Docker-gated)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — T001/T002/T003 all parallelizable
- **Phase 2 (Foundational)**: Depends on Phase 1 — T004/T005 parallelizable
- **Phase 3 (US1)**: Depends on Phase 2 — T006/T007 parallel; T008 parallel; T009 requires T006+T007; T010 requires T006; T011 requires T007
- **Phase 4 (US2)**: Depends on Phase 2 — T012 independent; T013 requires T012 understanding; T014 requires T013
- **Phase 5 (US3)**: Depends on Phase 2 — T015/T016 parallel; T017 requires T015+T016; T018 requires T017; T019 requires T017; T020 requires T015; T021 requires T008+T012+T016 (Docker-gated)
- **Phase 6 (US4)**: Depends on Phase 2 (T004) — T022 requires T004; T023/T024/T026 parallel; T025 requires T023; T027 independent; T028/T029 parallel after T027
- **Phase 7 (Polish)**: Depends on Phases 3–6 all complete

### User Story Dependencies

- **US1 (P1)**: Independent after Phase 2
- **US2 (P1)**: Independent after Phase 2 — can run parallel with US1
- **US3 (P1)**: Independent after Phase 2 — can run parallel with US1/US2 (T017 depends on T015, T019 depends on T017)
- **US4 (P2)**: Independent after Phase 2 — can run parallel with US1/US2/US3

### Parallel Opportunities

- T001, T002, T003 (Setup): all independent
- T004, T005 (Foundational): independent
- T006, T007 (US1 enums): different files
- T008 (US1 migration 1): independent of T006/T007 (can run in parallel)
- T010, T011 (US1 tests): independent of each other
- T015, T016 (US3): different files
- T018, T020 (US3): can run parallel after T017/T015 respectively
- T022 (US4) and T023, T024, T026 (US4): T023/T024/T026 are independent; T022 sequential
- T028, T029 (US4 events): parallel after T027
- T031, T032 (Polish lint): parallel; different files

---

## Parallel Example: US1 + US2 + US3 Simultaneously

```text
# After Phase 2 completes, launch in parallel:

Task A (US1 enums): T006 PaymentAccountingStatus.php
                    T007 PaymentFulfilmentStatus.php

Task B (US1 migration): T008 2026_10_21_000001_add_multi_state_to_payment_intents.php

Task C (US2 migration): T012 2026_10_21_000002_add_fee_snapshot_and_version_to_payment_intents.php

Task D (US3 enum+migration): T015 PaymentAllocationStatus.php
                              T016 2026_10_21_000003_create_payment_allocations_table.php

# After enums complete:
Task E: T009 PaymentIntentModel (US1 casts) — after T006, T007
Task F: T017 PaymentAllocationModel — after T015, T016

# After Task E:
Task G: T013 PaymentIntentModel (US2 fee/version casts)
```

---

## Implementation Strategy

### MVP (US1 Only — Unblocks P08)

1. Complete Phase 1: Setup verification
2. Complete Phase 2: Foundational (baseline confirmed)
3. Complete T006, T007 (enums), T008 (migration), T009 (model update)
4. **STOP and VALIDATE**: migration runs; PaymentIntentModel casts accounting_status correctly
5. P08 can now query independent `accounting_status` — core blocker resolved

### Full Delivery

1. Complete Phases 1–2
2. Complete US1 (T006–T011)
3. Complete US2 (T012–T014) — optimistic locking ready
4. Complete US3 (T015–T021) — allocations table ready for P17/P18
5. Complete US4 (T022–T029) — OpenAPI contracts complete for mobile/P03+
6. Complete Phase 7 (T030–T034) — all gates pass

---

## Notes

- [P] tasks = independent files, no sequential dependency
- [US1/US2/US3/US4] maps to spec.md user stories for traceability
- Docker-gated tasks (T005, T021, T030–T034) require running Docker environment; mark SKIP if Docker unavailable and note in final status
- T021 (feature test) is Docker-gated but should be written regardless — test runs when Docker is available
- FR-B01–FR-B04 are BLOCKED — do not implement allocation_type validation beyond the current varchar CHECK IN list, do not invent bank fee calculation rules
- All 3 migrations use `$connection = 'payment'` and `Schema::connection('payment')`
