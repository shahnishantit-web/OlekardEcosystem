# Tasks: P07 — Durable Idempotency, Concurrency and Recovery

**Input**: Design documents from `specs/056-durable-idempotency-recovery/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Organization**: Tasks are grouped by user story (US1–US4) with a shared foundational phase.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to
- All file paths relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup

**Purpose**: Verify P06 prerequisites and existing codebase state.

- [x] T001 Confirm `payment_attempts` table has `operation_id` UUID column (from P06 migration `2026_10_21_000001_p06_payment_attempt_state_engine.php`); confirm `PaymentAttemptStatus::Submitting` and `PaymentAttemptStatus::Unknown` exist in `app/Modules/Payment/Domain/Enums/PaymentAttemptStatus.php`
- [x] T002 Confirm `ReconciliationExceptionType` has `ContradictoryEvidence` and `ExcessCapture` cases in `app/Modules/Payment/Domain/Enums/ReconciliationExceptionType.php`; confirm `PaymentAttemptCrashRecoveryJob.php` does NOT already exist in `app/Modules/Payment/Application/Jobs/` (would indicate partial prior work)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: New DB table, models, interfaces, value objects, and exception that ALL user stories depend on. MUST complete before any user story phase.

### Migration

- [x] T003 Create migration `app/Modules/Payment/Infrastructure/Migrations/2026_10_21_000010_create_payment_idempotency_records.php`:
  - Schema: `payment.payment_idempotency_records`
  - Columns: `id UUID PK`, `actor_id VARCHAR(255) NOT NULL`, `scope VARCHAR(64) NOT NULL`, `idempotency_key VARCHAR(255) NOT NULL`, `request_hash CHAR(64) NOT NULL`, `response_status SMALLINT NOT NULL`, `response_body JSONB NOT NULL DEFAULT '{}'`, `created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()`, `expires_at TIMESTAMPTZ NOT NULL`
  - `UNIQUE INDEX payment_idempotency_records_actor_scope_key_idx ON (actor_id, scope, idempotency_key)`
  - `INDEX payment_idempotency_records_expires_at_idx ON (expires_at)`
  - `down()`: `DROP TABLE IF EXISTS payment.payment_idempotency_records`

### Domain Layer

- [x] T004 [P] Create `app/Modules/Payment/Domain/Contracts/PaymentIdempotencyRepositoryInterface.php`:
  - Method: `findRecord(string $actorId, string $scope, string $idempotencyKey): ?PaymentIdempotencyRecordModel`
  - Method: `saveRecord(string $actorId, string $scope, string $idempotencyKey, string $requestHash, int $responseStatus, array $responseBody): PaymentIdempotencyRecordModel`
  - Method: `pruneExpired(): int`

- [x] T005 [P] Create `app/Modules/Payment/Domain/ValueObjects/IdempotencyResult.php`:
  - `readonly class IdempotencyResult` with 4 properties: `bool $isReplay`, `bool $isConflict`, `int $responseStatus`, `array $responseBody`
  - Static factory: `IdempotencyResult::replay(int $status, array $body): self`
  - Static factory: `IdempotencyResult::conflict(): self`
  - Static factory: `IdempotencyResult::proceed(): self`

- [x] T006 [P] Create `app/Modules/Payment/Domain/Exceptions/ConcurrentModificationException.php`:
  - `final class ConcurrentModificationException extends \RuntimeException`
  - Constructor: `(string $resource, int $tries)`
  - Message: `"Concurrent modification on {$resource} after {$tries} deadlock retries"`

- [x] T007 [P] Add `case DuplicateCapture = 'duplicate_capture';` to `app/Modules/Payment/Domain/Enums/ReconciliationExceptionType.php` after the `ExcessCapture` case (P06 addition)

### Infrastructure Layer

- [x] T008 Create `app/Modules/Payment/Infrastructure/Models/PaymentIdempotencyRecordModel.php`:
  - `final class PaymentIdempotencyRecordModel extends Model`
  - `$connection = 'payment'`, `$table = 'payment.payment_idempotency_records'`
  - `$keyType = 'string'`, `$incrementing = false`
  - `$fillable`: `['id', 'actor_id', 'scope', 'idempotency_key', 'request_hash', 'response_status', 'response_body', 'expires_at']`
  - `casts()`: `response_body` → `'array'`, `response_status` → `'integer'`, `expires_at` → `'datetime'`

- [x] T009 Create `app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentIdempotencyRepository.php`:
  - Implements `PaymentIdempotencyRepositoryInterface`
  - `findRecord()`: DB query on `actor_id, scope, idempotency_key` where `expires_at > NOW()`
  - `saveRecord()`: `insertOrIgnore` with `Uuid::uuid7()` PK and `expires_at = now()->addDays($ttlDays)` (TTL from config key `payment.idempotency_ttl_days`, default 90)
  - `pruneExpired()`: `DELETE WHERE expires_at < NOW()`, return count

### Service Provider Registration

- [x] T010 Add to `app/Modules/Payment/Providers/PaymentServiceProvider.php` `register()` method:
  - `$this->app->bind(PaymentIdempotencyRepositoryInterface::class, EloquentPaymentIdempotencyRepository::class);`
  - `$this->app->singleton(PaymentIdempotencyService::class);`
  - Add required `use` imports for the two new classes

---

## Phase 3: US1 — HTTP-Layer Idempotency with Redis Acceleration (Priority: P1) — MVP

**Goal**: Controllers cache serialised HTTP responses in Redis+DB; retried requests return the cached response without re-executing.

**Independent Test**: Call `POST /v1/payment/intents` twice with the same `Idempotency-Key` → second call returns same `id`. Flush Redis, retry → still same `id` from DB. Change request body, retry → `HTTP 422 IDEMPOTENCY_KEY_MISMATCH`. Verifies SC-01, SC-02, SC-03.

### Implementation for US1

- [x] T011 [US1] Create `app/Modules/Payment/Application/Services/PaymentIdempotencyService.php`:
  - Constructor: `(PaymentIdempotencyRepositoryInterface $repository, ConfigurationServiceInterface $config, CacheRepository $cache)`
  - `check(string $actorId, string $scope, string $idempotencyKey, array $requestBody): IdempotencyResult`:
    1. Compute `$hash = hash('sha256', json_encode($this->canonicalize($requestBody)))`
    2. Compute `$redisKey = 'payment:idem:' . hash('sha256', "{$actorId}:{$scope}:{$idempotencyKey}")`
    3. Try `$this->cache->get($redisKey)` → if hit, decode JSON, compare hash → replay or conflict
    4. On Redis miss: `$this->repository->findRecord($actorId, $scope, $idempotencyKey)` → if found, repopulate Redis, return replay or conflict
    5. On not found: return `IdempotencyResult::proceed()`
  - `store(string $actorId, string $scope, string $idempotencyKey, array $requestBody, int $responseStatus, array $responseBody): void`:
    1. Compute hash and redisKey
    2. `$this->repository->saveRecord(...)` (insertOrIgnore — safe on duplicate)
    3. `$this->cache->put($redisKey, json_encode(['hash' => $hash, 'status' => $responseStatus, 'body' => $responseBody]), $redisTtlSeconds)`
  - Private `canonicalize(array $body): array`: ksort + trim string values recursively
  - Private `acquireLock(string $redisKey): CacheLock|null`: `$this->cache->lock("payment:idem:lock:{$redisKey}", 30)->block(30)`

- [x] T012 [P] [US1] Modify `app/Modules/Payment/Http/Controllers/PaymentIntentController.php` `store()` method:
  - Inject `PaymentIdempotencyService $idempotencyService` via constructor (or method injection)
  - At top of `store()`: extract `$actorId` from authenticated user, `$idempotencyKey` from `$request->header('Idempotency-Key')` (require non-empty; 400 if missing)
  - Call `$result = $idempotencyService->check($actorId, 'payment.intent.create', $idempotencyKey, $request->all())`
  - If `$result->isConflict`: return `response()->json(['error_code' => 'IDEMPOTENCY_KEY_MISMATCH'], 422)`
  - If `$result->isReplay`: return `response()->json($result->responseBody, $result->responseStatus)`
  - After successful orchestration: call `$idempotencyService->store($actorId, 'payment.intent.create', $idempotencyKey, $request->all(), 201, $responseArray)`

- [x] T013 [US1] Modify `app/Modules/Payment/Http/Controllers/PaymentAttemptController.php` `store()` method:
  - Same pattern as T012 with scope `'payment.attempt.initiate'`
  - Idempotency key required; store after successful attempt creation
  - **Note**: T013 and T014 both edit this file — run sequentially, not in parallel

- [x] T014 [US1] Modify `app/Modules/Payment/Http/Controllers/PaymentAttemptController.php` `outcome()` method:
  - Same pattern with scope `'payment.outcome'`
  - Idempotency key required for outcome posting; store after successful outcome handling
  - **Note**: Sequence after T013 — both edit `PaymentAttemptController.php`

- [x] T015 [US1] Create `app/Console/Commands/PrunePaymentIdempotencyRecordsCommand.php`:
  - `Signature = 'payment:prune-idempotency-records'`
  - Calls `$this->repository->pruneExpired()`, outputs count deleted
  - Register in `app/Console/Kernel.php` `schedule()`: `->weekly()`

- [x] T016 [US1] Write `app/Modules/Payment/Tests/Unit/IdempotencyServiceTest.php`:
  - Test 1: Redis hit — `check()` returns `IdempotencyResult::replay` without hitting DB
  - Test 2: Redis miss, DB hit — `check()` hits DB, returns replay, repopulates Redis
  - Test 3: Key reuse with different body hash — `check()` returns `IdempotencyResult::conflict`
  - Test 4: First-time key — `check()` returns `IdempotencyResult::proceed`
  - Test 5: `store()` writes to both Redis and DB
  - Uses `Cache::fake()` and mocked `PaymentIdempotencyRepositoryInterface`

---

## Phase 4: US2 — Crash Recovery for SUBMITTING Attempts (Priority: P2)

**Goal**: Periodic job detects stuck SUBMITTING attempts and resolves them via provider query.

**Independent Test**: Insert a SUBMITTING attempt with `initiated_at = now()->subSeconds(120)`, run `payment:recover-crashed-attempts` artisan command (or dispatch job), verify attempt transitions out of SUBMITTING. Verifies SC-04, SC-05.

### Implementation for US2

- [x] T017 [US2] Create `app/Modules/Payment/Application/Jobs/PaymentAttemptCrashRecoveryJob.php`:
  - `final class PaymentAttemptCrashRecoveryJob implements ShouldQueue` with `uniqueId(): string { return 'payment_crash_recovery_scan'; }`
  - Constructor: no args
  - `handle(PaymentAttemptRepositoryInterface $repo, ConfigurationServiceInterface $config): void`:
    1. Read threshold: `$threshold = (int)($config->getOrDefault('payment.crash_recovery_threshold_seconds', 60))`
    2. Call `$repo->findStuckInSubmitting($threshold)` — uses the injected repository (NOT `PaymentAttemptModel::where()` directly; domain boundary must be respected per Constitution P7)
       - If `findStuckInSubmitting()` does not yet exist on `PaymentAttemptRepositoryInterface`, add `findStuckInSubmitting(int $secondsOld): \Illuminate\Support\Collection` to the interface and implement it in `EloquentPaymentAttemptRepository` with `->where('status', 'submitting')->where('initiated_at', '<', now()->subSeconds($secondsOld))->get()`
    3. For each: dispatch `new PaymentAttemptSingleRecoveryJob($attempt->id)`
    4. Log: `Log::info("CrashRecoveryScan: dispatched {$count} recovery jobs")`

- [x] T018 [US2] Create `app/Modules/Payment/Application/Jobs/PaymentAttemptSingleRecoveryJob.php`:
  - `final class PaymentAttemptSingleRecoveryJob implements ShouldQueue` with `public int $tries = 3`
  - `uniqueId(): string { return 'payment_single_recovery:'.$this->attemptId; }`
  - `handle(PaymentAttemptRepositoryInterface $attemptRepo, PaymentIntentRepositoryInterface $intentRepo, PaymentOrchestrationService $orchestration, WalletModuleContract $wallet): void`:
    1. Load attempt; if null or not SUBMITTING: return early
    2. Load intent; if null: return early
    3. Determine query reference: `$ref = $attempt->provider_reference_id ?? $attempt->operation_id`
    4. Resolve adapter: `$adapterKey = 'payment.adapter.'.$attempt->provider_code`; if not bound: proceed to `handleCrashUnknown()`
    5. Try `$adapter->queryPaymentStatus($ref)` → match the `PaymentStatusResult` status:
       - **`Succeeded`** → call `$orchestration->handleOutcome(['outcome' => 'succeeded', 'provider_reference_id' => $result->providerReferenceId, ...])`
       - **`Failed`** → call `$orchestration->handleOutcome(['outcome' => 'failed', 'failure_reason' => 'crash_recovery_provider_confirmed_failed', ...])`
       - **`NotFound` / `null` / adapter returns no record** → call `$orchestration->handleOutcome(['outcome' => 'failed', 'failure_reason' => 'crash_recovery_provider_no_record', ...])` — the provider was never reached; marking failed is safe and aligns with spec US2 AC-3 ("attempt transitions to failed, wallet hold released")
       - **`Unknown` / `Processing` / `Pending` / any other indeterminate status** → call `handleCrashUnknown()`
    6. `handleCrashUnknown()` private method:
       a. Transition attempt to `Unknown` via DB transaction (same pattern as `PaymentAttemptTimeoutJob::transitionToUnknown()`)
       b. Create `ReconciliationException(UnknownOutcome)` with attempt/intent IDs
       c. **Release wallet hold** (if any): call `$wallet->releaseHold($intent->payer_wallet_id, $attempt->id)` — prevents locked funds when outcome cannot be determined
       d. Emit operator alert via `Log::critical("CrashRecovery: UNKNOWN outcome for attempt {$attemptId}; manual reconciliation required")`
    7. Log all transitions at `info` level; adapter-not-bound and indeterminate-status at `warning`
    - **Note**: `handleOutcome()` with `failed` handles hold release automatically via the existing failed path; only the `handleCrashUnknown()` branch requires an explicit hold-release call.

- [x] T019 [US2] Add schedule to `app/Console/Kernel.php` `schedule()` method:
  - `$schedule->job(new PaymentAttemptCrashRecoveryJob())->everyMinute()->withoutOverlapping();`

- [x] T020 [US2] Write `app/Modules/Payment/Tests/Unit/CrashRecoveryJobTest.php`:
  - Test 1: Provider returns `Succeeded` → `handleOutcome()` called with `outcome=succeeded`
  - Test 2: Provider returns `Failed` → `handleOutcome()` called with `outcome=failed`
  - Test 3: Provider returns `NotFound` / no record → `handleOutcome()` called with `outcome=failed` and `failure_reason=crash_recovery_provider_no_record`; wallet hold released via `handleOutcome()` failed path
  - Test 4: Provider returns `Unknown`/`Processing` (indeterminate) → `handleCrashUnknown()` fires: attempt transitions to `Unknown`, `ReconciliationException(UnknownOutcome)` created, `wallet->releaseHold()` called explicitly
  - Test 5: No adapter bound → same as Test 4 (`handleCrashUnknown()` path)
  - Test 6: Attempt already terminal (`Succeeded`) → job returns early, no side effects
  - Uses mock `PaymentAttemptRepositoryInterface`, mock `WalletModuleContract`, mock adapter

---

## Phase 5: US3 — Duplicate Capture Recording (Priority: P2)

**Goal**: Guard D in `handleOutcome()` detects a second provider success with a different reference and records a `DuplicateCapture` reconciliation exception without altering payment state.

**Independent Test**: Call `handleOutcome()` with `outcome=succeeded` and `provider_reference_id=ref-002` on an already-succeeded attempt with `provider_reference_id=ref-001`. Verify: (a) attempt status unchanged; (b) `ReconciliationException(DuplicateCapture)` created with both refs. Verifies SC-04 (evidence preserved), SC-06.

### Implementation for US3

- [x] T021 [US3] Add Guard D to `app/Modules/Payment/Application/PaymentOrchestrationService.php` `handleOutcome()` method:
  - Position: AFTER Guard A (idempotent replay check, line ~370) and BEFORE Guard B (contradictory evidence check)
  - Guard D condition:
    ```php
    if (
        $attempt->status === PaymentAttemptStatus::Succeeded
        && $newAttemptStatus === PaymentAttemptStatus::Succeeded
        && $incomingProviderRef !== null
        && $incomingProviderRef !== $attempt->provider_reference_id
    ) {
        $this->reconciliationService?->create(ReconciliationExceptionType::DuplicateCapture, [
            'payment_intent_id'    => $intentId,
            'payment_attempt_id'   => $attemptId,
            'provider_code'        => $attempt->provider_code,
            'provider_reference_id' => $attempt->provider_reference_id, // original
            'detected_amount_minor' => $intent->amount_minor,           // duplicate charged the same amount
            'expected_amount_minor' => $intent->amount_minor,
            'currency_code'        => $intent->currency_code,
            'description'          => sprintf(
                'Duplicate capture: original ref %s, duplicate ref %s',
                $attempt->provider_reference_id,
                $incomingProviderRef,
            ),
        ]);
        return; // silent — do not change state, do not throw
    }
    ```
  - Verify Guard ordering comment is updated in the method docblock

- [x] T022 [US3] Write `app/Modules/Payment/Tests/Unit/DuplicateCaptureTest.php`:
  - Test 1: `succeeded` callback with different non-null ref on already-succeeded attempt → `ReconciliationException(DuplicateCapture)` created, returns silently
  - Test 2: `succeeded` callback with same ref (true idempotent replay) → Guard A fires first, Guard D never reached, no exception
  - Test 3: `succeeded` callback with null ref on already-succeeded attempt → Guard A fires (null ref treated as matching), no exception
  - Extends `Tests\TestCase` with DB facade mocking

---

## Phase 6: US4 — Deadlock Detection and Bounded Retry (Priority: P3)

**Goal**: DB transactions in `PaymentOrchestrationService` automatically retry on deadlock (SQLSTATE 40P01) up to a configurable maximum, surfacing `HTTP 409 CONCURRENT_MODIFICATION` only after all retries are exhausted.

**Independent Test**: Run `DeadlockRetryTest` which simulates a SQLSTATE 40P01 exception: verify retry fires the configured number of times; verify `ConcurrentModificationException` is thrown on exhaustion. Verifies SC-05.

### Implementation for US4

- [x] T023 [US4] Create `app/Modules/Payment/Application/DeadlockRetryTrait.php`:
  - `trait DeadlockRetryTrait`
  - `protected function withDeadlockRetry(callable $callback, string $resource = 'payment'): mixed`:
    1. Read `$maxRetries = (int)($this->config->getOrDefault('payment.deadlock_max_retries', 3))`
    2. Read `$baseMs = (int)($this->config->getOrDefault('payment.deadlock_retry_base_ms', 10))`
    3. For `$attempt = 0; $attempt <= $maxRetries; $attempt++`:
       - If `$attempt > 0`: `usleep((2 ** ($attempt - 1)) * $baseMs * 1000)` + log warning `"payment.deadlock_retry attempt={$attempt} resource={$resource}"`
       - Try `return $callback()`
       - Catch `\Illuminate\Database\QueryException $e` where `$e->errorInfo[0] === '40P01'`: continue loop
    4. Throw `new ConcurrentModificationException($resource, $maxRetries)`

- [x] T024 [US4] Add `use DeadlockRetryTrait;` to `app/Modules/Payment/Application/PaymentOrchestrationService.php`:
  - Wrap the `DB::connection('platform')->transaction()` call in `initiateAttempt()` with `$this->withDeadlockRetry(fn() => DB::connection('platform')->transaction(...), 'payment_attempt.initiate')`
  - Wrap the `DB::connection('platform')->transaction()` call in `handleOutcome()` with `$this->withDeadlockRetry(fn() => ..., 'payment_attempt.outcome')`
  - Do NOT wrap `DB::connection('payment')->transaction()` calls (different connection, deadlock domain is `platform`)

- [x] T025 [US4] Map `ConcurrentModificationException` to HTTP 409 in `app/Exceptions/Handler.php`:
  - In `register()` method, add: `$this->renderable(function (ConcurrentModificationException $e, $request) { return response()->json(['error_code' => 'CONCURRENT_MODIFICATION', 'message' => $e->getMessage()], 409); });`

- [x] T026 [US4] Write `app/Modules/Payment/Tests/Unit/DeadlockRetryTest.php`:
  - Test 1: Zero deadlocks → callback called once, result returned
  - Test 2: One deadlock then success → callback called twice, result returned (first call throws 40P01 QueryException)
  - Test 3: Max retries exhausted → `ConcurrentModificationException` thrown after `$maxRetries + 1` attempts
  - Test 4: Non-deadlock QueryException is NOT retried → propagates immediately on first error
  - Extends `Tests\TestCase`; mock `ConfigurationServiceInterface` for retry configuration

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: OpenAPI docs, static analysis, full test suite validation, Docker quickstart.

- [x] T027 Update `openapi/v1/payment.yaml`:
  1. Add two new values to the `ErrorCode` enum:
     - `IDEMPOTENCY_KEY_MISMATCH` — returned when `Idempotency-Key` is reused with a different request body
     - `CONCURRENT_MODIFICATION` — returned when deadlock retries are exhausted
  2. Add `Idempotency-Key` as a **required** header parameter to the three state-changing operations (Constitution P8 — financial mutations must be documented with idempotency requirements):
     - `POST /v1/payment/intents` (operation `createPaymentIntent`)
     - `POST /v1/payment/intents/{id}/attempts` (operation `createPaymentAttempt`)
     - `POST /v1/payment/intents/{id}/attempts/{attemptId}/outcome` (operation `postPaymentOutcome`)
     ```yaml
     parameters:
       - name: Idempotency-Key
         in: header
         required: true
         schema:
           type: string
           maxLength: 255
         description: >
           Client-generated unique key (UUID recommended). Requests sharing this key
           and an identical body return the original cached response. Reuse with a
           different body returns HTTP 422 IDEMPOTENCY_KEY_MISMATCH.
     ```
  3. Add `422` response to each of the three operations referencing the `IDEMPOTENCY_KEY_MISMATCH` error code, and `409` response referencing `CONCURRENT_MODIFICATION`

- [ ] T028 Run PHPStan level 8 on the Payment module: `./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8`
  - Resolve any new errors introduced by P07 code (expected: `@phpstan-param` on array arguments in new services)

- [ ] T029 Run the P07 unit tests: `php artisan test app/Modules/Payment/Tests/Unit/IdempotencyServiceTest.php app/Modules/Payment/Tests/Unit/CrashRecoveryJobTest.php app/Modules/Payment/Tests/Unit/DuplicateCaptureTest.php app/Modules/Payment/Tests/Unit/DeadlockRetryTest.php`
  - All T016, T020, T022, T026 tests must PASS

- [ ] T030 Run quickstart.md SC-01 through SC-08 validation scenarios (Docker required):
  - SC-01: Idempotent intent creation (Redis hit) → same `id` returned
  - SC-02: DB fallback after Redis flush → same `id` returned
  - SC-03: Payload mismatch rejection → `422 IDEMPOTENCY_KEY_MISMATCH`
  - SC-04: Crash recovery SUBMITTING → Succeeded
  - SC-05: Crash recovery no provider record → UNKNOWN + ReconciliationException
  - SC-06: Duplicate capture recording → `DuplicateCapture` exception created
  - SC-07: Deadlock retry returns success
  - SC-08: Deadlock exhaustion returns `409 CONCURRENT_MODIFICATION`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — verify P06 baseline
- **Foundational (Phase 2)**: Depends on Phase 1 — BLOCKS all user story phases
- **US1 (Phase 3)**: Depends on Phase 2; MVP — deliver and validate before proceeding
- **US2 (Phase 4)**: Depends on Phase 2; independent of US1
- **US3 (Phase 5)**: Depends on Phase 2; independent of US1 and US2
- **US4 (Phase 6)**: Depends on Phase 2; T024 modifies `PaymentOrchestrationService` — may conflict with T021 (US3); sequence US3 then US4 OR apply changes to the same file in one edit
- **Polish (Phase 7)**: Depends on all phases completing

### User Story Dependencies

- **US1 (Phase 3)**: Can start immediately after Phase 2
- **US2 (Phase 4)**: Can start immediately after Phase 2 — independent of US1
- **US3 (Phase 5)**: Can start immediately after Phase 2 — modifies `handleOutcome()`
- **US4 (Phase 6)**: Can start after Phase 2 — modifies `handleOutcome()` (sequence after US3 to avoid merge conflict on that method)

### Parallel Opportunities

- T004, T005, T006, T007 can run in parallel (different files)
- T012, T013, T014 can run in parallel (different methods in same or related files — caution on `PaymentAttemptController`)
- T017, T018 (crash recovery jobs) can run in parallel
- T021 (Guard D) and T023 (deadlock trait) operate on different concerns within `PaymentOrchestrationService` but modify the same file — sequence them

---

## Parallel Example: Phase 2 Foundational

```bash
# Launch in parallel (different files):
Task T004: Create PaymentIdempotencyRepositoryInterface
Task T005: Create IdempotencyResult value object
Task T006: Create ConcurrentModificationException
Task T007: Add DuplicateCapture enum case

# Then sequential (depends on T004):
Task T008: Create PaymentIdempotencyRecordModel
Task T009: Create EloquentPaymentIdempotencyRepository
Task T010: Register bindings in PaymentServiceProvider
```

---

## Implementation Strategy

### MVP First (US1 Only)

1. Complete Phase 1: Setup (verify)
2. Complete Phase 2: Foundational (T003–T010)
3. Complete Phase 3: US1 (T011–T016)
4. **STOP and VALIDATE**: SC-01, SC-02, SC-03 pass
5. Network retry double-charge risk eliminated — this is the core financial safety win

### Incremental Delivery

1. Setup + Foundational → Foundation ready
2. US1 → HTTP-layer idempotency → SC-01, SC-02, SC-03 pass (MVP)
3. US2 → Crash recovery → SC-04, SC-05 pass
4. US3 → Duplicate capture recording → SC-06 passes
5. US4 → Deadlock retry → SC-07, SC-08 pass
6. Polish → OpenAPI, PHPStan, full suite

---

## Notes

- **`DeadlockRetryTrait` requires `$this->config`** — only usable in `PaymentOrchestrationService` which already has `ConfigurationServiceInterface` injected
- **`PaymentIdempotencyService` stores full `$responseBody` in Redis** — responses must be serialisable to JSON; no Eloquent model instances in response arrays (use array shapes only, consistent with existing `intentShape()` pattern)
- **Guard D position matters**: must come AFTER Guard A (same-ref replay) and BEFORE Guard B (contradictory evidence) — comment the ordering explicitly in the method
- **Crash recovery job uses `uniqueId()`** — prevents multiple overlapping scan jobs via Laravel Horizon's unique job feature
- **T013/T014 share `PaymentAttemptController`** — if editing in parallel, coordinate to avoid conflicting edits to the same file; prefer sequential editing
