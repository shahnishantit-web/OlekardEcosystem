# Tasks: P16 — Reversals, Disputes and Chargebacks

**Spec**: [spec.md](spec.md) | **Plan**: [plan.md](plan.md) | **Date**: 2026-10-24

**Prerequisite phase complete**: P15 ✓

**Format**: `[ID] [P?] [Story?] Description — file path`
- `[P]` = parallel-safe (different files, no dependency on concurrent tasks)
- `[US#]` = user story this task belongs to

---

## Phase 1: Setup (Migrations)

**Purpose**: Create the three new database tables for P16. All tasks are parallel-safe.

- [X] T001 [P] Create migration `create_payment_dispute_cases_table` — `payment.payment_dispute_cases` with all columns, CHECK constraints, UNIQUE index on (provider_code, provider_dispute_reference), and partial index on open cases; see data-model.md for full schema — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_24_000011_create_payment_dispute_cases_table.php`
- [X] T002 [P] Create migration `create_dispute_case_events_table` — `payment.dispute_case_events` append-only timeline; columns: id, dispute_case_id (FK), event_type, actor_id (nullable), payload (jsonb), occurred_at, correlation_id; index on (dispute_case_id, occurred_at) — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_24_000012_create_dispute_case_events_table.php`
- [X] T003 [P] Create migration `create_dispute_holds_table` — `payment.dispute_holds` with id, dispute_case_id (FK), hold_amount_minor, currency_code, settled_funds_receivable (bool, default false), authorised_by, authorised_at, released_at (nullable); UNIQUE index on dispute_case_id (one hold per case) — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_24_000013_create_dispute_holds_table.php`

**Checkpoint**: `php artisan migrate` runs cleanly; three new tables exist in `payment` schema.

---

## Phase 2: Foundational (Domain Contracts — Blocks All US Phases)

**Purpose**: Enums, interfaces, exceptions, Eloquent models, repository, outbox events, and service bindings. All user story phases depend on this phase.

**⚠️ CRITICAL**: No user story implementation may begin until this phase is complete.

- [X] T004 Create `PaymentDisputeCaseStatus` enum — cases: Received, UnderReview, Won, Lost, ClosedReversed, InvestigationRequired; implement `canTransitionTo(self $to): bool` enforcing the transition table in data-model.md; implement `isTerminal(): bool` (Won, Lost, ClosedReversed); implement `allowsFinancialActions(): bool` (Received, UnderReview, InvestigationRequired) — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/PaymentDisputeCaseStatus.php`
- [X] T005 [P] Create `DisputeCaseType` enum (Reversal='reversal', Dispute='dispute', Chargeback='chargeback'), `DisputeEntitlementAction` enum (BlockUnused='blocked', ConsumedEvidenceRecorded='consumed', PostDrawLiability='post_draw_liability', NoHandler='no_handler', CoordinationFailed='coordination_failed', Pending='pending'), and `DisputeAccountingStatus` enum (Pending, CorrectingJournalPosted, FeePosted, HoldReleased, ReceivableRecorded, PendingChargebackFeePolicy) — three files in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/`
- [X] T006 [P] Extend `ReconciliationExceptionType` enum — add `case UnmatchedDisputeEvent = 'unmatched_dispute_event'` — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Enums/ReconciliationExceptionType.php`
- [X] T007 [P] Create `DisputeEntitlementHandlerInterface` — methods: `supportedPurposeType(): string` and `handleDisputeEntitlement(string $caseId, string $purposeReference, string $correlationId): DisputeEntitlementResult`; include `@throws \Throwable` for timeout/error — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/DisputeEntitlementHandlerInterface.php`
- [X] T008 [P] Create `DisputeEntitlementResult` value object — `readonly` class with `action: DisputeEntitlementAction`, `evidenceReference: ?string = null`, `notes: ?string = null` — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/DisputeEntitlementResult.php`
- [X] T009 [P] Create `DisputeOutcomeRequest` value object — `readonly` class with `outcome: string` (won|lost|closed_reversed), `providerDecisionReference: string`, `chargebackFeeMinor: int = 0` — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/DisputeOutcomeRequest.php`
- [X] T010 [P] Create `PaymentDisputeCaseRepositoryInterface` — methods: `findById(string $id): ?PaymentDisputeCaseModel`, `findByProviderRef(string $providerCode, string $providerDisputeReference): ?PaymentDisputeCaseModel`, `listFiltered(array $filters, int $perPage, int $page): LengthAwarePaginator`, `save(PaymentDisputeCaseModel $case): void`, `appendEvent(string $caseId, string $eventType, ?string $actorId, array $payload, string $correlationId): void` — `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentDisputeCaseRepositoryInterface.php`
- [X] T011 [P] Create four domain exceptions: `DisputeCaseAlreadyExistsException` (carries providerRef), `DisputeCaseNotFoundException` (carries caseId), `DisputeCaseClosedException` (carries caseId and currentStatus), `DisputeInvalidTransitionException` (carries fromStatus, toStatus) — four files in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Exceptions/`
- [X] T012 [P] Create `PaymentDisputeCaseModel` — Eloquent model; `$connection = 'payment'`; `$table = 'payment.payment_dispute_cases'`; cast `status` to `PaymentDisputeCaseStatus`, `case_type` to `DisputeCaseType`, `entitlement_action` to `DisputeEntitlementAction`, `accounting_status` to `DisputeAccountingStatus`; cast timestamps; `$fillable` with all columns from data-model.md — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentDisputeCaseModel.php`
- [X] T013 [P] Create `DisputeCaseEventModel` — Eloquent model; `$connection = 'payment'`; `$table = 'payment.dispute_case_events'`; cast `payload` as `array`; no `updated_at`; `$fillable` with all columns — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/DisputeCaseEventModel.php`
- [X] T014 [P] Create `DisputeHoldModel` — Eloquent model; `$connection = 'payment'`; `$table = 'payment.dispute_holds'`; cast timestamps; `$fillable` with all columns — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/DisputeHoldModel.php`
- [X] T015 Implement `EloquentPaymentDisputeCaseRepository` — implements `PaymentDisputeCaseRepositoryInterface`; `findByProviderRef()` uses scoped query on (provider_code, provider_dispute_reference); `listFiltered()` applies status, case_type, requires_finance_review, deadline_before filters + pagination; `appendEvent()` uses `DisputeCaseEventModel::create()` — `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Repositories/EloquentPaymentDisputeCaseRepository.php`
- [X] T016 [P] Create six outbox integration events — each implements `IntegrationEventInterface`; idempotency keys as specified in contracts/dispute-events.yaml; payload fields per data-model.md: `DisputeCaseReceivedEvent` (key `dispute.received:{caseId}`), `DisputeHoldAuthorisedEvent` (key `dispute.hold:{caseId}`), `DisputeWonEvent` (key `dispute.won:{caseId}`), `DisputeLostEvent` (key `dispute.lost:{caseId}`), `DisputeReversedEvent` (key `dispute.reversed:{caseId}`), `DisputeLateContradictoryOutcomeEvent` (key `dispute.contradictory:{caseId}:{epochMs}`) — six files in `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Events/`
- [X] T017 Add `payment.disputes.view`, `payment.disputes.manage`, `payment.disputes.finance_review` to the payment module permission catalogue — update `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/PaymentPermissionCatalogueTest.php` and the relevant permission seeder
- [X] T018 Bind `PaymentDisputeCaseRepositoryInterface` → `EloquentPaymentDisputeCaseRepository` in the IoC container; register all six dispute outbox events in the event catalogue; add `MonitorDisputeDeadlinesJob` to the scheduler (daily) — `wallet-ecosystem/services/api/app/Modules/Payment/Providers/PaymentServiceProvider.php`

**Checkpoint**: Foundation complete. `php artisan test Tests/Unit/PaymentPermissionCatalogueTest.php` passes. All US phases may begin.

---

## Phase 3: User Story 1 — Provider Event Ingestion and Deduplication (Priority: P1) 🎯 MVP

**Goal**: Authenticated provider dispute/reversal/chargeback events create exactly one `PaymentDisputeCase`; duplicates are acknowledged; unknown attempts create a `ReconciliationException`.

**Independent Test**: POST the same event twice — verify one case created; POST for unknown attempt — verify 422 + `ReconciliationException(unmatched_dispute_event)`; POST invalid signature — verify 401.

- [X] T019 [P] [US1] Write `DisputeDeduplicationTest` — unit tests: (a) second event with same provider_code + provider_dispute_reference returns existing case, no new record; (b) event for unknown payment_attempt_id → throws `DisputeCaseNotFoundException` equivalent, triggers `ReconciliationException(UnmatchedDisputeEvent)` creation; (c) invalid provider signature → rejected before DisputeService is called — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/DisputeDeduplicationTest.php`
- [X] T020 [US1] Implement `DisputeService::receive()` — (1) authenticate using `BankCallbackVerifier` or provider validator; (2) check duplicate: if `findByProviderRef()` returns existing case, return `['action' => 'duplicate_acknowledged', 'case' => $existing]`; (3) look up `PaymentAttemptModel` by `payment_attempt_id`, reject with `ReconciliationException(UnmatchedDisputeEvent)` if not found; (4) calculate `prior_refunds_minor` via `PaymentRefundRepositoryInterface::sumConfirmedRefundsForAttempt()`; (5) set `net_exposure_minor = max(0, disputed_amount_minor - prior_refunds_minor)`; (6) persist `PaymentDisputeCaseModel` in DB transaction; (7) append `case_received` event via `appendEvent()`; (8) publish `DisputeCaseReceivedEvent` to outbox OUTSIDE the DB transaction — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/DisputeService.php`
- [X] T021 [US1] Create `BankDisputeCallbackHandler` — E25 inbox event handler for `bank.dispute_received` events; validates payload structure; calls `DisputeService::receive()`; handles `DisputeCaseAlreadyExistsException` as `duplicate_acknowledged` — `wallet-ecosystem/services/api/app/Modules/Payment/Application/EventHandlers/BankDisputeCallbackHandler.php`
- [X] T022 [US1] Create `BankDisputeCallbackController` — POST `/api/v1/payment/bank/dispute-callback`; validates required fields (provider_code, provider_dispute_reference, payment_attempt_id, case_type, disputed_amount_minor, currency_code); calls `DisputeService::receive()`; returns 201 on created, 201 with `action = duplicate_acknowledged` on duplicate, 422 on unmatched attempt, 401 on auth failure — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/BankDisputeCallbackController.php`
- [X] T023 [US1] Register bank dispute callback route in payment routes — POST route at the `api/v1/payment/bank/dispute-callback` path with `identity.auth` middleware — `wallet-ecosystem/services/api/routes/modules/payment.php`
- [X] T024 [US1] Write `BankDisputeCallbackControllerTest` — `@group docker`; tests: valid event → 201 + case created; duplicate event → 201 + duplicate_acknowledged; unknown attempt → 422 + ReconciliationException in DB; duplicate DB record not created on replay; DisputeCaseReceivedEvent in outbox for new case — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Feature/BankDisputeCallbackControllerTest.php`

**Checkpoint**: SC-001 and SC-002 from quickstart.md pass.

---

## Phase 4: User Story 2 — Financial Exposure Assessment and Authorised Hold (Priority: P1)

**Goal**: Finance operator authorises a hold on available funds or records a settled-funds receivable; hold never debits wallet; double-compensation guard limits hold to net exposure.

**Independent Test**: Create case → authorise hold → verify case in `under_review`, `DisputeHold` created; attempt second hold → 409; settled-funds path → `settled_funds_receivable = true`.

- [X] T025 [US2] Write `DisputeDoubleCompensationTest` — unit tests: (a) net_exposure_minor = disputed_amount - prior_confirmed_refunds; (b) hold_amount cannot exceed net_exposure_minor → rejected; (c) settled_funds path creates hold with `settled_funds_receivable = true`; (d) duplicate hold call (same case already under_review) → `DisputeCaseClosedException` or 409; (e) fully-refunded dispute (net_exposure = 0) → hold of 0 is a receivable — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/DisputeDoubleCompensationTest.php`
- [X] T026 [US2] Implement `DisputeService::authoriseHold()` — (1) load case, verify `status = received`, else throw `DisputeInvalidTransitionException`; (2) verify `$holdAmountMinor <= $case->net_exposure_minor`, else throw `\InvalidArgumentException`; (3) DB transaction: create `DisputeHold`, update case `status = under_review`, `exposure_type`, `hold_amount_minor`; (4) `appendEvent()` with `hold_authorised` event type and actor_id; (5) publish `DisputeHoldAuthorisedEvent` OUTSIDE transaction — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/DisputeService.php`
- [X] T027 [US2] Create `AdminDisputeCaseController::hold()` — POST `/api/v1/admin/payment/dispute-cases/{id}/hold`; validate request (hold_amount_minor: min 1 when exposure_type = hold; min 0 when exposure_type = settled_funds_receivable — zero-amount receivable path is valid; exposure_type required enum [hold, settled_funds_receivable]); require `Idempotency-Key` header; call `DisputeService::authoriseHold()`; return case detail on success, 409 if wrong state, 422 if amount exceeds net_exposure_minor — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/AdminDisputeCaseController.php`

**Checkpoint**: SC-003 and SC-004 from quickstart.md pass.

---

## Phase 5: User Story 3 — Domain Module Entitlement Coordination (Priority: P2)

**Goal**: Domain modules may register a `DisputeEntitlementHandlerInterface` to apply protective entitlement actions; missing or failing handlers are handled gracefully; post-draw liability cases are flagged.

**Independent Test**: Register stub handler returning each action type; verify `entitlement_action` field set correctly; verify `requires_finance_review = true` for `post_draw_liability`; verify `coordination_failed` on timeout; verify `no_handler` when no handler registered.

- [X] T028 [US3] Write `DisputeEntitlementCoordinationTest` — unit tests: (a) registered handler returns `block_unused` → `entitlement_action = blocked`, case updated; (b) handler returns `consumed_evidence_recorded` → `entitlement_action = consumed`, evidence_reference recorded on event payload; (c) handler returns `post_draw_liability` → `entitlement_action = post_draw_liability`, `requires_finance_review = true`, `liability_exception_type = post_draw_raffle`; (d) no handler registered → `entitlement_action = no_handler`; (e) handler throws exception → `entitlement_action = coordination_failed`, error logged — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/DisputeEntitlementCoordinationTest.php`
- [X] T029 [US3] Implement `DisputeService::coordinateEntitlements()` — (1) look up purpose type from the associated `PaymentIntentModel` via `PaymentAttemptModel`; (2) try to resolve `DisputeEntitlementHandlerInterface` from IoC at key `payment.dispute_entitlement.{purpose_type}`; if not bound → set `entitlement_action = no_handler`, append event, return; (3) call `handler->handleDisputeEntitlement($caseId, $purposeReference, $correlationId)`; (4) on success: update case `entitlement_action`, set `requires_finance_review = true` and `liability_exception_type` if action = PostDrawLiability; append entitlement event with action and evidence_reference in payload; (5) on exception: set `entitlement_action = coordination_failed`, log at error level, append event — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/DisputeService.php`

**Checkpoint**: `DisputeEntitlementCoordinationTest` passes.

---

## Phase 6: User Story 4 — Provider Outcome, Correcting Journals and Hold Release (Priority: P2)

**Goal**: Won/lost/reversed outcomes post correct idempotent journals via the ledger; double-compensation guard deducts prior refunds from chargeback amount; contradictory outcomes trigger investigation state without financial side effects.

**Independent Test**: Won → hold released, no journal; lost (net 3000 after 2000 prior refunds) → journal for 3000 only; reversed → compensating journal; duplicate lost → idempotency prevents duplicate journals; contradictory (won after lost) → investigation_required, no new journal.

- [X] T030 [US4] Write `DisputeIdempotencyTest` — unit tests: (a) duplicate `recordOutcome(won)` call — `LedgerModuleContract::postEntry()` never called twice (already won → idempotency gate); (b) `recordOutcome(lost)` with prior_refunds_minor = 2000 and disputed_amount = 5000 → net_chargeback = 3000, journal posted for 3000; (c) `recordOutcome(lost)` when net_chargeback_minor = 0 (fully refunded) → no chargeback journal posted; (d) contradictory outcome (won after lost terminal state) → case moves to `investigation_required`, `DisputeLateContradictoryOutcomeEvent` published, no new journal; (e) `recordOutcome(closed_reversed)` → reversal compensating journal posted with idempotency key `dispute:{caseId}:chargeback` — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Unit/DisputeIdempotencyTest.php`
- [X] T031 [US4] Implement `DisputeService::recordOutcome()` — accepts `DisputeOutcomeRequest $request` and `string $caseId`; (1) load case; (2) contradictory guard: if case is terminal (won/lost/closed_reversed) AND incoming outcome = same terminal outcome → idempotent no-op, return without event or state change; if case is terminal AND incoming outcome ≠ terminal outcome (opposite direction) OR case is `investigation_required` → DB transaction: append `contradictory_outcome` event, set status = `investigation_required`, publish `DisputeLateContradictoryOutcomeEvent` OUTSIDE transaction, return; (3) verify case in `under_review` else throw `DisputeInvalidTransitionException`; (4) if **won**: DB transaction: update case `status = won`, set `closed_at`; release hold (set `released_at = now()` on `DisputeHold`); append `outcome_won` event; set `accounting_status = hold_released`; publish `DisputeWonEvent` OUTSIDE transaction; (5) if **lost**: calculate net = `max(0, case->disputed_amount_minor - case->prior_refunds_minor)`; if net > 0: call `LedgerModuleContract::postEntry()` with idempotency key `dispute:{caseId}:chargeback` OUTSIDE DB transaction; if `chargebackFeeMinor > 0`: call `postEntry()` with key `dispute:{caseId}:fee` OUTSIDE transaction; DB transaction: update case `status = lost`, record journal IDs, `accounting_status`, `closed_at`; append `outcome_lost` event; publish `DisputeLostEvent` OUTSIDE transaction; (6) if **closed_reversed**: call `LedgerModuleContract::postEntry()` with reversal compensating journal and key `dispute:{caseId}:chargeback` OUTSIDE DB transaction; DB transaction: update case `status = closed_reversed`, record journal ID, `closed_at`; **update `PaymentAttemptModel::$outcome = 'reversed'` within this same DB transaction** (using `$case->payment_attempt_id`); append `outcome_reversed` event; publish `DisputeReversedEvent` OUTSIDE transaction — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/DisputeService.php`
- [X] T032 [US4] Add `AdminDisputeCaseController::outcome()` method — POST `/api/v1/admin/payment/dispute-cases/{id}/outcome`; validate request (outcome enum, provider_decision_reference required, chargeback_fee_minor >= 0 default 0); require `Idempotency-Key`; require `payment.disputes.manage` permission; call `DisputeService::recordOutcome()`; return 200 with updated case detail; 409 if case not in under_review; 422 for invalid outcome — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/AdminDisputeCaseController.php`

**Checkpoint**: SC-005, SC-006, SC-007 from quickstart.md pass.

---

## Phase 7: User Story 5 — Case Management APIs and Closure (Priority: P2)

**Goal**: Admin operators can list, filter, and manage dispute cases; customers get ownership-gated read-only summaries; liability-exception cases require finance review before closure.

**Independent Test**: List cases filtered by status; retrieve detail with full timeline; submit evidence → `evidence_submitted = true`; customer sees summary without provider data; other customer gets 403; finance-review gate prevents closure without acknowledgement.

- [X] T033 [P] [US5] Create `DisputeCaseListResource` — maps `PaymentDisputeCaseModel` to list response fields per contracts/dispute-api.yaml: id, payment_attempt_id, provider_code, provider_dispute_reference, case_type, status, disputed_amount_minor, currency_code, net_exposure_minor, entitlement_action, response_deadline, requires_finance_review, liability_exception_type, created_at — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Resources/DisputeCaseListResource.php`
- [X] T034 [P] [US5] Create `DisputeCaseDetailResource` — extends list fields with: reason_code, exposure_type, hold (nested DisputeHoldSummary or null), prior_refunds_minor, accounting_status, chargeback_ledger_journal_id, fee_ledger_journal_id, provider_decision_reference, finance_reviewed, finance_reviewed_by, finance_reviewed_at, evidence_submitted, closed_at, timeline (array of DisputeCaseEvent) — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Resources/DisputeCaseDetailResource.php`
- [X] T035 [P] [US5] Create `CustomerDisputeSummaryResource` — maps to customer-safe fields only: id, case_type, status, status_label (human-readable), disputed_amount_minor, currency_code, created_at; NO provider_dispute_reference, fees, case-owner notes, actor IDs — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Resources/CustomerDisputeSummaryResource.php`
- [X] T036 [US5] Implement `AdminDisputeCaseController::index()` and `show()` — `index()`: resolve filters (status, case_type, requires_finance_review, deadline_before, page, per_page) from query; call `PaymentDisputeCaseRepositoryInterface::listFiltered()`; return `DisputeCaseListResource` collection + pagination meta; `show()`: load case by ID, load related DisputeHold and DisputeCaseEvent timeline, return `DisputeCaseDetailResource`; both require `payment.disputes.view` — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/AdminDisputeCaseController.php`
- [X] T037 [US5] Implement `AdminDisputeCaseController::submitEvidence()` and `acknowledgeFinanceReview()` — `submitEvidence()`: require `payment.disputes.manage`; verify case is not terminal; call `DisputeService::recordEvidence(caseId, evidenceRef, actorId)`; `acknowledgeFinanceReview()`: require `payment.disputes.finance_review`; verify case has `requires_finance_review = true`; set `finance_reviewed = true`, `finance_reviewed_by`, `finance_reviewed_at`; append `finance_review_acknowledged` event; return 200 — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/AdminDisputeCaseController.php`
- [X] T038 [US5] Implement `DisputeService::recordEvidence()` — verify case not terminal; update `evidence_submitted = true`; append `evidence_submitted` event with payload `{evidence_reference, submitted_by: actorId}`; no journal entry required — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/DisputeService.php`
- [X] T039 [US5] Create `CustomerDisputeSummaryController` — GET `/api/v1/payment/intents/{intentId}/disputes`; load intent; enforce ownership check (`$intent->payer_actor_id !== $user->id` → 403); load dispute cases via `PaymentDisputeCaseRepositoryInterface::listFiltered(['payment_intent_id' => $intentId])`; return `CustomerDisputeSummaryResource` collection; require `payment.disputes.view` — `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Customer/CustomerDisputeSummaryController.php`
- [X] T040 [US5] Register all admin dispute-case routes and customer dispute summary route — admin: GET /api/v1/admin/payment/dispute-cases (index), GET /{id} (show), POST /{id}/hold, POST /{id}/outcome, POST /{id}/evidence, POST /{id}/finance-review; customer: GET /api/v1/payment/intents/{intentId}/disputes; with appropriate permission middleware — `wallet-ecosystem/services/api/routes/modules/payment.php`
- [X] T041 [US5] Write `AdminDisputeCaseControllerTest` — `@group docker`; tests: list with status filter returns only matching cases; show returns full timeline; hold endpoint moves case to under_review; outcome endpoint posts correct terminal state; submit evidence sets evidence_submitted flag; finance-review gate rejects closure without acknowledgement; permission checks enforced — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Feature/AdminDisputeCaseControllerTest.php`
- [X] T042 [US5] Write `CustomerDisputeSummaryControllerTest` — `@group docker`; tests: payer sees their cases; non-payer gets 403; intent not found returns 404; response contains no provider_dispute_reference or fee fields — `wallet-ecosystem/services/api/app/Modules/Payment/Tests/Feature/CustomerDisputeSummaryControllerTest.php`

**Checkpoint**: SC-008 from quickstart.md passes.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Scheduled alerting, OpenAPI documentation, operator runbook, static analysis, and quickstart validation.

- [X] T043 Create `MonitorDisputeDeadlinesJob` — `ShouldBeUnique` (uniqueId = 'monitor_dispute_deadlines'); `handle(ConfigurationServiceInterface, MetricsCollectorInterface)`: query `payment_dispute_cases` WHERE `status NOT IN ('won','lost','closed_reversed')` AND `response_deadline <= now() + threshold`; emit `payment.dispute.approaching_deadline_count` gauge; log at WARNING with case IDs and deadlines; register as daily scheduled job in `PaymentServiceProvider` — `wallet-ecosystem/services/api/app/Modules/Payment/Application/Jobs/MonitorDisputeDeadlinesJob.php`
- [X] T044 [P] Add P16 paths and schemas to OpenAPI documentation — add all endpoints from `specs/063-reversals-disputes-chargebacks/contracts/dispute-api.yaml` to `openapi/v1/payment.yaml`: POST bank callback, GET admin list, GET admin detail, POST hold, POST outcome, POST evidence, POST finance-review, GET customer summary; add schemas: `DisputeCaseStatus`, `DisputeCaseType`, `DisputeEntitlementAction`, `DisputeCaseListItem`, `DisputeCaseDetail`, `DisputeHoldSummary`, `DisputeCaseEvent`, `CustomerDisputeSummary` — `wallet-ecosystem/services/api/openapi/v1/payment.yaml`
- [X] T045 [P] Write operator runbook — sections: (1) Investigate open dispute case (list by status/deadline), (2) Authorise hold (available funds vs settled-funds receivable path), (3) Record outcome — won, lost, reversed, (4) Handle contradictory provider outcome (investigation_required), (5) Finance review gate for raffle/event liability exceptions, (6) Double-compensation calculation explanation, (7) Metric reference table; include all relevant API curl examples — `wallet-ecosystem/services/api/docs/runbooks/dispute-resolution.md`
- [ ] T046 [Docker-gated] Run PHPStan level 8 on Payment module and fix all errors — `./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8` from `wallet-ecosystem/services/api/`
- [ ] T047 [Docker-gated] Run quickstart.md validation — execute scenarios SC-001 through SC-008 per `specs/063-reversals-disputes-chargebacks/quickstart.md`; record pass/fail results

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately; all three tasks parallel
- **Phase 2 (Foundational)**: Depends on Phase 1 completion — blocks all user stories
- **Phase 3 (US1)**: Depends on Phase 2 — can start immediately after
- **Phase 4 (US2)**: Depends on Phase 2 + US1 DisputeService base
- **Phase 5 (US3)**: Depends on Phase 2 + Phase 4 (coordinateEntitlements added to service)
- **Phase 6 (US4)**: Depends on Phase 2 + Phase 4 (authoriseHold changes case state)
- **Phase 7 (US5)**: Depends on Phase 2 + Phases 3–6 (reads case state from all previous operations)
- **Phase 8 (Polish)**: Depends on all previous phases

### Within Phase 2 — Parallel Groups

```bash
# Group A (no dependencies, all parallel):
T001, T002, T003, T004, T005, T006, T007, T008, T009, T010, T011, T012, T013, T014, T016

# Group B (depends on T012-T014 for imports):
T015 (EloquentRepository — depends on models T012-T014)
T017 (permissions — depends on enums T004-T005)
T018 (ServiceProvider bindings — depends on T015, T017)
```

### DisputeService Evolution Across Phases

The `DisputeService` is built incrementally:
- Phase 3 → adds `receive()`
- Phase 4 → adds `authoriseHold()`
- Phase 5 → adds `coordinateEntitlements()`
- Phase 6 → adds `recordOutcome()`, `recordEvidence()`

Each method is independently testable without the others.

---

## Parallel Example: Phase 2

```bash
# All enums and interfaces can be generated simultaneously:
T005: DisputeCaseType + DisputeEntitlementAction + DisputeAccountingStatus enums
T007: DisputeEntitlementHandlerInterface
T008: DisputeEntitlementResult value object
T010: PaymentDisputeCaseRepositoryInterface
T011: 4 exceptions
T012: PaymentDisputeCaseModel
T013: DisputeCaseEventModel
T014: DisputeHoldModel
T016: 6 outbox events
```

---

## Implementation Strategy

### MVP (US1 + US2 — P1 Stories Only)

1. Complete Phase 1 (Setup) + Phase 2 (Foundational)
2. Complete Phase 3 (US1 — Ingestion)
3. Complete Phase 4 (US2 — Hold)
4. **STOP and VALIDATE**: SC-001 to SC-004 from quickstart.md
5. Deploy — operators can see and hold dispute cases

### Incremental Delivery

- MVP above → adds US3 (entitlement coordination) → adds US4 (outcomes + journals) → adds US5 (full API surface)
- Each story adds operator capability without breaking previous stories

---

## Notes

- `[P]` tasks operate on different files and have no cross-task data dependencies
- `DisputeService` is the only file built across multiple phases; each method is independent
- Financial idempotency keys follow the pattern `dispute:{caseId}:{action}`
- Outbox events published OUTSIDE DB transactions (per P15 precedent)
- `@group docker` tests require the live identity + payment DB and should run in Docker
- AD-17 (chargeback fee accounting) is deferred: fee journal is posted when `chargeback_fee_minor > 0` is provided; if AD-17 is unresolved, set `accounting_status = pending_chargeback_fee_policy` (mirrors AD-16 pattern from P15)
