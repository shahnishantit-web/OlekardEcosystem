# Tasks: P11 — Outbox Inbox Notifications and Domain Delivery

**Input**: Design documents from `specs/060-outbox-inbox-delivery/`

**Prerequisites**: plan.md ✓ | spec.md ✓ | research.md ✓ | data-model.md ✓ | contracts/ ✓ | quickstart.md ✓

**Base path**: `wallet-ecosystem/services/api/`

**Tests**: Included for all user stories per spec acceptance criteria. Unit tests run without Docker; PHPStan + Feature tests are Docker-gated.

**Analyze remediation applied (2026-10-24)**: 8 findings resolved — I1 (FR-008 spec note added), I2 (T007a+T010a version guard), I3 (T002 no interface method), I4 (correlation_id filter), I5 (last_error in list), I6 (stuck_count), C1 (T018a OutboxDeadLetterService), U1 (T001a NotificationService). Total tasks: 36 (T001–T031 + T001a + T007a + T010a + T018a + T021a).

**Organization**: Grouped by user story for independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: User story this task belongs to (US1–US5)

---

## Phase 1: Setup

**Purpose**: Shared DTO prerequisite used by all payment event listeners.

- [x] T001 Create `CreatePaymentNotificationCommand` DTO in `app/Modules/Notification/Application/Commands/CreatePaymentNotificationCommand.php` — `readonly` class with constructor fields: `string $notificationType`, `string $sourceEventId`, `string $payerActorId`, `?string $correlationId`, `int $amountMinor`, `string $currencyCode`
- [x] T001a [P] Verify `NotificationService` in `app/Modules/Notification/Application/Services/NotificationService.php` has a method that accepts `CreatePaymentNotificationCommand`; if absent, add `createFromPaymentEvent(CreatePaymentNotificationCommand $command): void` — implementation: `DB::table('notification.notifications')->insertOrIgnore(['id' => (string) Uuid::uuid7(), 'source_event_id' => $command->sourceEventId, 'notification_type' => $command->notificationType, 'recipient_actor_id' => $command->payerActorId, 'status' => 'queued', 'created_at' => now()])` — the unique constraint `(source_event_id, notification_type)` provides deduplication; no second insert is needed

**Checkpoint**: DTO available for all 4 event listeners; NotificationService accepts it.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared platform infrastructure required for US3 and backward-compatible across all events. Must complete before US3 tasks begin; US1/US2/US4/US5 may begin in parallel.

**⚠️ NOTE**: US1, US4, US5 do NOT depend on this phase and may start immediately after Phase 1.

- [x] T002 Update docblock on `app/Modules/Platform/Domain/Contracts/IntegrationEventInterface.php` — add a `@convention` docblock comment documenting the `causationId()` duck-typing convention: "Events that carry causation context MAY implement `causationId(): ?string`; OutboxEventPublisher calls this via `method_exists()` — do NOT declare it on this interface, as adding a method declaration would be a breaking change requiring all 20+ existing implementors to be updated (see research.md Decision 3)" — **do NOT add a method declaration to the interface**
- [x] T003 Modify `OutboxEventPublisher::buildRow()` in `app/Modules/Platform/Application/Services/OutboxEventPublisher.php` — replace hardcoded `'causation_id' => null` with `'causation_id' => method_exists($event, 'causationId') ? $event->causationId() : null`; also inject `eventId` (outbox row UUID) into payload so listeners can look up the row for version checking (FR-010)

**Checkpoint**: Publisher populates `causation_id` for any future event that declares the method; all existing events remain unaffected.

---

## Phase 3: User Story 1 — Reliable Downstream Delivery (Priority: P1) 🎯 MVP

**Goal**: All 4 payment event types (`payment.confirmed`, `payment.failed`, `payment.reversed`, `refund.confirmed`) reach registered Laravel event listeners when dispatched by `InProcessDriver`.

**Independent Test**: Dispatch each event type via `Event::dispatch()`; assert the corresponding listener's `handle()` is invoked; assert `NotificationService` is called with the correct `notification_type`.

- [x] T004 [P] [US1] Create `PaymentConfirmedListener` in `app/Modules/Payment/Application/EventHandlers/PaymentConfirmedListener.php` — inject `NotificationService` and `PaymentAllocationModel` (or DB facade); `handle(array $payload)`: (a) insert into `payment.payment_allocations` with `idempotency_key = $payload['allocationReference']`, `payment_intent_id`, `payment_attempt_id`, `amount_minor`, `currency_code`; (b) dispatch `new CreatePaymentNotificationCommand(notificationType: 'payment_confirmed', sourceEventId: $payload['eventId'], ...)` to NotificationService; both inserts wrapped in `DB::transaction()`
- [x] T005 [P] [US1] Create `PaymentFailedListener` in `app/Modules/Payment/Application/EventHandlers/PaymentFailedListener.php` — inject `NotificationService`; `handle(array $payload)`: dispatch notification_type='payment_failed' with sourceEventId=$payload['eventId'], payerActorId=$payload['payerActorId']
- [x] T006 [P] [US1] Create `PaymentReversedListener` in `app/Modules/Payment/Application/EventHandlers/PaymentReversedListener.php` — inject `NotificationService`; `handle(array $payload)`: dispatch notification_type='payment_reversed' with sourceEventId=$payload['eventId'], payerActorId=$payload['payerActorId']
- [x] T007 [P] [US1] Create `RefundConfirmedListener` in `app/Modules/Payment/Application/EventHandlers/RefundConfirmedListener.php` — inject `NotificationService`; `handle(array $payload)`: dispatch notification_type='refund_confirmed' with sourceEventId=$payload['eventId'], payerActorId=$payload['payerActorId']
- [x] T007a [US1] Add event_version guard to all 4 listener `handle()` methods (FR-010) — at the start of each `handle(array $payload)`: look up the outbox row `$outboxRow = OutboxEventModel::find($payload['eventId'] ?? null)`; if `$outboxRow` is null, log a warning and return early; if `$outboxRow->event_version` is not in the P11 supported set (`['1']`), call `$outboxRow->update(['status' => 'dead_letter'])` then `Log::warning('outbox.unsupported_version', ['id' => $payload['eventId'], 'version' => $outboxRow->event_version])` and return early without creating allocations or notifications — apply this guard to all 4 listener files (PaymentConfirmedListener, PaymentFailedListener, PaymentReversedListener, RefundConfirmedListener)
- [x] T008 [US1] Create `PaymentEventSubscriber` in `app/Modules/Payment/Application/EventHandlers/PaymentEventSubscriber.php` — `subscribe(Dispatcher $events): void` registers: `$events->listen('payment.confirmed', PaymentConfirmedListener::class)`, `$events->listen('payment.failed', PaymentFailedListener::class)`, `$events->listen('payment.reversed', PaymentReversedListener::class)`, `$events->listen('refund.confirmed', RefundConfirmedListener::class)`
- [x] T009 [US1] Register `PaymentEventSubscriber` in `app/Modules/Payment/Providers/PaymentServiceProvider.php` — add `$this->app['events']->subscribe(PaymentEventSubscriber::class)` inside `boot()` method
- [x] T010 [P] [US1] Write `PaymentListenerRoutingTest` in `tests/Unit/Modules/Payment/EventHandlers/PaymentListenerRoutingTest.php` — 4 unit tests: (1) `test_payment_confirmed_listener_calls_notification_service`, (2) `test_payment_failed_listener_calls_notification_service`, (3) `test_payment_reversed_listener_calls_notification_service`, (4) `test_refund_confirmed_listener_calls_notification_service`; each mocks injected services and asserts correct notification_type
- [x] T010a [P] [US1] Write `PaymentListenerVersionGuardTest` in `tests/Unit/Modules/Payment/EventHandlers/PaymentListenerVersionGuardTest.php` — 4 tests (one per listener): `test_unsupported_version_dead_letters_outbox_row_and_returns_early`; for each: mock `OutboxEventModel::find()` to return a model with `event_version = '2'`; call `handle($payload)`; assert `OutboxEventModel::update()` called with `['status' => 'dead_letter']`; assert `NotificationService::createFromPaymentEvent()` NOT called; assert `Log::warning()` called

**Checkpoint**: All 4 event types reach their listeners; T010/T010a pass (no Docker needed).

---

## Phase 4: User Story 2 — Consumer Deduplication (Priority: P1)

**Goal**: Re-delivering the same event ID to any listener produces exactly one downstream effect; the second invocation completes gracefully with no DB writes.

**Independent Test**: Call `handle()` twice with identical `eventId`; assert allocation count = 1, notification count = 1.

- [x] T011 [US2] Verify `PaymentConfirmedListener::handle()` in `app/Modules/Payment/Application/EventHandlers/PaymentConfirmedListener.php` uses `DB::table('payment.payment_allocations')->insertOrIgnore([...])` and `DB::table('notification.notifications')->insertOrIgnore([...])` inside a single `DB::transaction()` — `insertOrIgnore()` maps to `INSERT ... ON CONFLICT DO NOTHING` honouring the `idempotency_key` UNIQUE constraint and `(source_event_id, notification_type)` UNIQUE constraint respectively
- [x] T012 [P] [US2] Verify `PaymentFailedListener::handle()` in `app/Modules/Payment/Application/EventHandlers/PaymentFailedListener.php` uses `insertOrIgnore()` when creating the notification row — ensures `(source_event_id, notification_type)` unique constraint silently deduplicates
- [x] T013 [P] [US2] Verify `PaymentReversedListener::handle()` in `app/Modules/Payment/Application/EventHandlers/PaymentReversedListener.php` uses `insertOrIgnore()` for notification row
- [x] T014 [P] [US2] Verify `RefundConfirmedListener::handle()` in `app/Modules/Payment/Application/EventHandlers/RefundConfirmedListener.php` uses `insertOrIgnore()` for notification row
- [x] T015 [P] [US2] Write `PaymentConfirmedListenerDedupTest` in `tests/Unit/Modules/Payment/EventHandlers/PaymentConfirmedListenerDedupTest.php` — 2 tests: `test_duplicate_delivery_creates_single_allocation` (call handle() twice; assert insertOrIgnore called twice but allocation count=1), `test_duplicate_delivery_creates_single_notification` (same pattern for notifications)
- [x] T016 [P] [US2] Write `PaymentEventDedupTest` in `tests/Unit/Modules/Payment/EventHandlers/PaymentEventDedupTest.php` — 3 tests: `test_duplicate_payment_failed_creates_single_notification`, `test_duplicate_payment_reversed_creates_single_notification`, `test_duplicate_refund_confirmed_creates_single_notification`; each calls handle() twice with same eventId and asserts insertOrIgnore called; no exception thrown

**Checkpoint**: Dedup tests pass; all 4 listeners idempotent; no duplicate rows on re-delivery.

---

## Phase 5: User Story 3 — Event Causation Chain (Priority: P2)

**Goal**: `OutboxEventPublisher::buildRow()` correctly populates `causation_id` when an event implements `causationId()`; null for events that do not (all P11 root events are null; listener-to-child threading deferred to P12).

**Independent Test**: Mock `IntegrationEventInterface` with and without `causationId()` method; assert `outbox_events.causation_id` is populated / null accordingly.

- [x] T017 [P] [US3] Write `OutboxEventPublisherCausationTest` in `tests/Unit/Modules/Platform/Services/OutboxEventPublisherCausationTest.php` — 2 tests: `test_causation_id_populated_when_event_implements_method` (mock event with `causationId()` returning a UUID; assert buildRow includes it); `test_causation_id_null_when_event_lacks_method` (mock event without `causationId()`; assert buildRow sets null)
- [x] T018 [US3] Write `OutboxCausationDocumentationTest` in `tests/Feature/Modules/Payment/OutboxCausationDocumentationTest.php` — add `markTestSkipped('T018/P11: listener-to-child causation ID threading deferred to P12 — requires InProcessDriver to pass outbox row ID to listener; see research.md Decision 6')` guard; document expected test body: assert `payment.confirmed` outbox row has null causation_id; assert future child event (e.g. `fulfillment.allocated`) will carry causation_id = payment.confirmed outbox row id

**Checkpoint**: T017 passes (no Docker needed); T018 skipped with clear deferral message for P12.

---

## Phase 6: User Story 4 — Dead-Letter Management and Audited Replay (Priority: P2)

**Goal**: Operations staff can list dead-letter outbox events, replay a single event (reset to pending), and every replay writes an audit record.

**Independent Test**: Seed an `OutboxEventModel` row with `status=dead_letter`; call `replay()` endpoint; assert `status=pending`, `attempts=0`; assert audit record exists.

- [x] T018a [US4] Create `OutboxDeadLetterService` in `app/Modules/Platform/Application/Services/OutboxDeadLetterService.php` — `replay(string $id, string $actorId): OutboxEventModel`: find model or throw `ModelNotFoundException`; throw `ConflictException` if `status !== 'dead_letter'`; wrap in `DB::transaction()`: set `status='pending'`, `attempts=0`, `next_attempt_at=now()`; write audit record via `AuditService` (action=`outbox.dead_letter.replay`, actor_id=`$actorId`, subject_type=`OutboxEvent`, subject_id=`$id`, metadata=[event_name, aggregate_id, previous_status=`dead_letter`, new_status=`pending`]); return updated model — **this is where all replay business logic lives; controller delegates here (Principle 7)**
- [x] T019 [US4] Create `OutboxAdminController` in `app/Modules/Platform/Infrastructure/Http/Controllers/OutboxAdminController.php` with 3 methods — controller stays thin (delegates domain logic to services):
  - `deadLetters(Request $request): JsonResponse` — authorize `operations.dead_letter.manage`; query `OutboxEventModel::where('status','dead_letter')`; filter by `event_name` if provided; filter by `correlation_id` if provided (FR-011); paginate (default 20, max 100); return per contract (id, event_name, aggregate_id, aggregate_type, attempts, **last_error**, correlation_id, causation_id, created_at, updated_at)
  - `replay(string $id, Request $request): JsonResponse` — authorize `operations.dead_letter.manage`; call `$this->deadLetterService->replay($id, auth()->id())`; catch `ModelNotFoundException` → return 404; catch `ConflictException` → return 409; return 200 per contract
  - `health(Request $request): JsonResponse` — authorize `operations.dead_letter.manage`; delegate to `$this->healthService->getHealthMetrics($request->query('event_name'))`
- [x] T020 [US4] Add 3 routes to `routes/modules/reliability.php` inside existing operations auth middleware group: `Route::get('/v1/operations/outbox/health', [OutboxAdminController::class, 'health'])`, `Route::get('/v1/operations/outbox/dead-letters', [OutboxAdminController::class, 'deadLetters'])`, `Route::post('/v1/operations/outbox/dead-letters/{id}/replay', [OutboxAdminController::class, 'replay'])`; add `use App\Modules\Platform\Infrastructure\Http\Controllers\OutboxAdminController;` import
- [x] T021 [P] [US4] Write `OutboxAdminDeadLettersTest` in `tests/Unit/Modules/Platform/Http/Controllers/OutboxAdminControllerTest.php` — 5 tests for `deadLetters()`: `test_returns_paginated_dead_letter_events`, `test_filters_by_event_name`, `test_filters_by_correlation_id` (I4), `test_response_includes_last_error_field` (I5), `test_returns_403_when_lacking_permission`
- [x] T021a [P] [US4] Write `OutboxDeadLetterServiceTest` in `tests/Unit/Modules/Platform/Services/OutboxDeadLetterServiceTest.php` — 4 tests for `replay()`: `test_replay_sets_status_to_pending_and_resets_attempts`, `test_replay_throws_conflict_when_event_not_dead_letter`, `test_replay_throws_not_found_when_event_absent`, `test_replay_writes_audit_record_via_audit_service`
- [x] T022 [P] [US4] Write `OutboxAdminReplayControllerTest` in same file `tests/Unit/Modules/Platform/Http/Controllers/OutboxAdminControllerTest.php` — 3 thin-controller tests for `replay()`: `test_replay_delegates_to_dead_letter_service`, `test_replay_maps_not_found_exception_to_404`, `test_replay_maps_conflict_exception_to_409`
- [x] T023 [US4] Write `OutboxReplayFeatureTest` in `tests/Feature/Modules/Platform/OutboxReplayFeatureTest.php` — add `markTestSkipped('T023/P11: requires live DB with platform.outbox_events; Docker-gated; validates SC-005 replay audit chain')` guard; document test body: seed dead_letter row; authenticate as admin with operations.dead_letter.manage; POST replay; assert status=pending; assert audit record; assert event re-dispatched on next Horizon cycle

**Checkpoint**: T021/T022 unit tests pass (no Docker needed); T023 skipped pending Docker.

---

## Phase 7: User Story 5 — Queue Health Observability (Priority: P3)

**Goal**: `GET /v1/operations/outbox/health` returns per-event-type metrics including `oldest_pending_age_seconds`; response within 200 ms p95.

**Independent Test**: Mock `OutboxEventModel` query; assert `getHealthMetrics()` returns correct DTO shape with computed age.

- [x] T024 [US5] Create `OutboxQueueHealthService` in `app/Modules/Platform/Application/Services/OutboxQueueHealthService.php` — `getHealthMetrics(?string $eventName = null): array` executes a single GROUP BY query on `platform.outbox_events`: `SELECT event_name, COUNT(*) FILTER (WHERE status IN ('pending','failed')) AS pending_count, COUNT(*) FILTER (WHERE status = 'dead_letter') AS dead_letter_count, COUNT(*) FILTER (WHERE status = 'processing' AND updated_at < now() - interval '5 minutes') AS stuck_count, MIN(created_at) FILTER (WHERE status IN ('pending','failed')) AS oldest_pending_at FROM platform.outbox_events [WHERE event_name = ?] GROUP BY event_name`; compute `oldest_pending_age_seconds = now() - oldest_pending_at` (null if no pending rows); return array of associative arrays with keys: `event_name`, `pending_count`, `dead_letter_count`, `stuck_count`, `oldest_pending_age_seconds` — the stale threshold `5 minutes` is configurable via `platform.outbox_stuck_threshold_seconds` (config key; default 300)
- [x] T025 [US5] Inject `OutboxQueueHealthService` into `OutboxAdminController` constructor in `app/Modules/Platform/Infrastructure/Http/Controllers/OutboxAdminController.php`; implement `health()` body: call `$this->healthService->getHealthMetrics($request->query('event_name'))`; return `['data' => $metrics, 'meta' => ['queried_at' => now()->toIso8601String()]]` as JsonResponse
- [x] T026 [P] [US5] Write `OutboxQueueHealthServiceTest` in `tests/Unit/Modules/Platform/Services/OutboxQueueHealthServiceTest.php` — 4 tests: `test_returns_metrics_with_correct_shape` (mock DB query; assert all 5 fields present: event_name, pending_count, dead_letter_count, stuck_count, oldest_pending_age_seconds), `test_filters_by_event_name` (assert query includes WHERE clause), `test_stuck_count_reflects_stale_processing_rows` (assert stuck_count > 0 when processing row is older than threshold), `test_returns_empty_when_no_events` (empty DB; returns empty array)
- [x] T027 [US5] Write `OutboxQueueHealthFeatureTest` in `tests/Feature/Modules/Platform/OutboxQueueHealthFeatureTest.php` — add `markTestSkipped('T027/P11: requires live DB; Docker-gated; validates SC-004 lag reporting accuracy')` guard; document test body: insert outbox row with created_at = now() - 10 minutes; assert health endpoint returns oldest_pending_age_seconds between 590 and 610; assert response time < 200ms

**Checkpoint**: T026 passes (no Docker needed); T024/T025 wired and compiling; T027 skipped pending Docker.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Static analysis, full test run, quickstart validation.

- [ ] T028 [P] Run PHPStan level 8 on all P11 Payment EventHandler files: `./vendor/bin/phpstan analyse app/Modules/Payment/Application/EventHandlers/ app/Modules/Notification/Application/Commands/CreatePaymentNotificationCommand.php --level=8` (Docker-gated; report 0 errors required)
- [ ] T029 [P] Run PHPStan level 8 on all P11 Platform files: `./vendor/bin/phpstan analyse app/Modules/Platform/Application/Services/OutboxQueueHealthService.php app/Modules/Platform/Application/Services/OutboxDeadLetterService.php app/Modules/Platform/Infrastructure/Http/Controllers/OutboxAdminController.php app/Modules/Platform/Application/Services/OutboxEventPublisher.php app/Modules/Platform/Domain/Contracts/IntegrationEventInterface.php --level=8` (Docker-gated; report 0 errors required)
- [x] T030 Run all P11 unit tests: `php artisan test tests/Unit/Modules/Payment/EventHandlers/ tests/Unit/Modules/Platform/Services/OutboxQueueHealthServiceTest.php tests/Unit/Modules/Platform/Services/OutboxEventPublisherCausationTest.php tests/Unit/Modules/Platform/Http/` — expected: ~20 tests, all pass (no Docker required)
- [ ] T031 Run quickstart.md validation scenarios SC-001 through SC-006 using Docker: `docker compose up -d && php artisan test tests/Feature/Modules/Platform/ tests/Feature/Modules/Payment/OutboxCausationDocumentationTest.php tests/Feature/Modules/Payment/OutboxCausationDocumentationTest.php` — Docker-gated; SC-001 crash durability; SC-002 deduplication idempotency; SC-003 causation chain; SC-004 queue health lag; SC-005 dead-letter replay audit; SC-006 no PII in payloads

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Setup (T001) — needed before US3 (Phase 5)
- **US1 (Phase 3)**: Depends on Setup (T001) only — can start immediately after T001
- **US2 (Phase 4)**: Depends on US1 completion (T004–T009) — modifies same listener files
- **US3 (Phase 5)**: Depends on Foundational (T002–T003) — tests T002/T003 changes
- **US4 (Phase 6)**: Depends on Setup (T001) only — independent of US1–US3
- **US5 (Phase 7)**: Depends on US4 (T019) — OutboxAdminController must exist for health() wiring
- **Polish (Phase 8)**: Depends on all user stories being complete

### User Story Dependencies

```
T001/T001a ─────────┬──── US1 (T004–T010a) ──── US2 (T011–T016)
                    │
                    ├──── US4 (T018a–T023) ──── US5 (T024–T027)
                    │
T002–T003 ──────────┴──── US3 (T017–T018)

All complete ────── Polish (T028–T031)
```

### Within Each User Story

- Listener creation [P] tasks (T004–T007, T010, T010a) can run in parallel — different files
- T007a (version guard) modifies T004–T007 files — run after them or combine into initial implementation
- Unit tests [P] tasks (T010, T010a, T015, T016, T017, T021, T021a, T022, T026) can run in parallel — different files
- T018a (service) must complete before T019 (controller) — controller injects the service
- Service + controller tasks must complete before wiring/integration tasks

### Parallel Opportunities

```bash
# Phase 3 — US1 (all different files, run together):
Task T004: PaymentConfirmedListener
Task T005: PaymentFailedListener
Task T006: PaymentReversedListener
Task T007: RefundConfirmedListener
Task T010: PaymentListenerRoutingTest
Task T010a: PaymentListenerVersionGuardTest

# Phase 4 — US2 (different listeners, run together):
Task T012: PaymentFailedListener dedup
Task T013: PaymentReversedListener dedup
Task T014: RefundConfirmedListener dedup
Task T015: PaymentConfirmedListenerDedupTest
Task T016: PaymentEventDedupTest

# Phase 8 — Polish (different tools):
Task T028: PHPStan Payment
Task T029: PHPStan Platform
```

---

## Implementation Strategy

### MVP First (US1 + US2 — both P1)

1. T001 + T001a: Create DTO; verify/extend NotificationService
2. T004–T009: Create all 4 listeners + subscriber + registration
3. T007a: Add version guard to all 4 listeners
4. T010 + T010a: Verify routing tests + version guard tests pass
5. T011–T014: Add dedup (insertOrIgnore) to all listeners
6. T015–T016: Verify dedup tests pass
7. **STOP and VALIDATE**: 4 event types reliably reach listeners; unknown versions dead-lettered; duplicate delivery is idempotent — MVP!

### Incremental Delivery

1. MVP (US1+US2) → 4 listeners live, events flowing, dedup active
2. US3 (Causation) → Framework in place; root events carry null causation_id; P12 threads child events
3. US4 (Dead-letter admin) → Operators can list + replay dead-letter events
4. US5 (Queue health) → Operators can monitor lag metrics
5. Polish → PHPStan clean + full test suite + quickstart validation

---

## Notes

- `[P]` tasks write to different files and have no shared state dependencies — safe to parallelize
- US1 and US2 are both P1; US2 modifies the same listener files created by US1 (sequential dependency within US1→US2)
- `insertOrIgnore()` in Laravel maps to `INSERT ... ON CONFLICT DO NOTHING` in PostgreSQL — relies on existing unique constraints; NO new migrations
- All 4 payment events are root events in P11 scope: `causation_id = null`; causation threading from listener to child events requires P12 (InProcessDriver must expose outbox row ID to listener)
- T002 adds a docblock convention note to IntegrationEventInterface — it does NOT add a method declaration (that would be a breaking change for all 20+ implementors)
- T007a: version guard does an `OutboxEventModel::find()` lookup per listener invocation — one extra DB read per event; acceptable for P11; consider caching or dispatcher enhancement in P12
- FR-008 field requirements (consumer_name, event_name, processed_at, correlation_id) are satisfied by semantic equivalence via existing constraints — see spec.md Assumptions ADR-P11-001 for rationale
- T023, T027, T028, T029, T031 are Docker-gated; mark `markTestSkipped` in feature tests; PHPStan tasks require running API container or local `vendor/bin/phpstan`
- `operations.dead_letter.manage` permission is an existing E04 permission — no new permission creation needed
- T018a (OutboxDeadLetterService) owns all replay business logic; T019 (OutboxAdminController) is a thin delegation layer only — constitution Principle 7
- The `OutboxAdminController::health()` method stub can be created in T019 with a placeholder; T025 wires the service; both can be coded together
