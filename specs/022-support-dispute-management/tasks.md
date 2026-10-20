# Tasks: E22 — Support & Dispute Management

**Input**: `specs/022-support-dispute-management/`

**Prerequisites**: plan.md ✓ spec.md ✓ research.md ✓ data-model.md ✓ contracts/ ✓

**Base path**: `wallet-ecosystem/services/api/`

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (e.g., US1…US13)

---

## Phase 1: Setup (Module Scaffold)

**Purpose**: Create the module skeleton, register DB connection, and wire the service provider before any domain work begins.

- [X] T001 Create module directory tree: `app/Modules/Support/{Application/Services,Domain/{Contracts,Enums,Events,Exceptions},Infrastructure/{Http/{Controllers,Requests,Resources},Migrations,Models,Storage},Providers}/`
- [X] T002 Create `app/Modules/Support/Providers/SupportServiceProvider.php` — register DB connection `'support'` (same host as default, schema `support`), bind contracts, load module routes from a local `routes.php`
- [X] T003 Register `SupportServiceProvider` in `bootstrap/providers.php` (or `app/Providers/AppServiceProvider.php`)
- [X] T004 [P] Add `'support'` connection to `config/database.php` pointing to PostgreSQL `support` schema
- [X] T005 [P] Create `app/Modules/Support/Domain/Contracts/EvidenceStorageContract.php` — interface with `store(string $disputeId, string $evidenceId, UploadedFile $file): string` and `generateSignedUrl(string $fileKey, int $ttlMinutes = 15): string`
- [X] T006 [P] Create `app/Modules/Support/Infrastructure/Storage/LaravelDiskEvidenceStorage.php` — implement `EvidenceStorageContract` using Laravel disk; key format `support-evidence/{disputeId}/{evidenceId}.{ext}`; bind in `SupportServiceProvider`

**Checkpoint**: `php artisan config:clear` and `php artisan route:list` run without errors

---

## Phase 2: Foundational (Migrations, Enums, Models)

**Purpose**: Database schema, domain enums, and Eloquent models that every user story depends on.

⚠️ **CRITICAL — BLOCKS ALL USER STORIES**

- [X] T007 Create migration `app/Modules/Support/Infrastructure/Migrations/2026_09_29_220000_create_support_schema.php` — `CREATE SCHEMA IF NOT EXISTS support`; create sequences `support.ticket_number_seq` and `support.dispute_number_seq`
- [X] T008 Create migration `app/Modules/Support/Infrastructure/Migrations/2026_09_29_220100_create_support_tickets.php` — `support.support_tickets` table per data-model.md; UNIQUE on `ticket_number` and `idempotency_key`; indexes: `idx_support_tickets_reporter(reporter_id, reporter_type)`, `idx_support_tickets_status(status)`, `idx_support_tickets_assignee(assignee_id)` partial
- [X] T009 Create migration `app/Modules/Support/Infrastructure/Migrations/2026_09_29_220200_create_ticket_notes.php` — `support.ticket_notes` table; FK to `support_tickets` ON DELETE CASCADE; index `idx_ticket_notes_ticket_id(ticket_id, created_at)`
- [X] T010 Create migration `app/Modules/Support/Infrastructure/Migrations/2026_09_29_220300_create_disputes.php` — `support.disputes` table per data-model.md; UNIQUE partial index `idx_disputes_transaction_id_active` on `(transaction_id)` WHERE `status NOT IN ('rejected','refunded')`; UNIQUE on `refund_idempotency_key`; UNIQUE on `idempotency_key`; indexes `idx_disputes_reporter`, `idx_disputes_status`, `idx_disputes_escalated`
- [X] T011 Create migration `app/Modules/Support/Infrastructure/Migrations/2026_09_29_220400_create_dispute_evidence.php` — `support.dispute_evidence` table; FK to `disputes` ON DELETE CASCADE; CHECK `size_bytes <= 10485760`; UNIQUE on `idempotency_key`; index `idx_dispute_evidence_dispute_id(dispute_id, created_at)`
- [X] T012 Create migration `app/Modules/Support/Infrastructure/Migrations/2026_09_29_220500_seed_support_permissions.php` — insert 10 permissions to `identity.permissions`; assign: `support.create` → customer/agent/admin; `support.assign`/`support.resolve`/`support.close`/`support.investigate` → admin; `dispute.raise` → customer/agent; `dispute.approve`/`dispute.reject`/`dispute.refund` → admin; `dispute.escalate` → customer/agent/admin
- [X] T013 [P] Create `app/Modules/Support/Domain/Enums/TicketStatus.php` — backed string enum `Open='open'`, `InProgress='in_progress'`, `Resolved='resolved'`, `Closed='closed'`; method `isTransitionableTo(self $next): bool` encoding allowed transitions
- [X] T014 [P] Create `app/Modules/Support/Domain/Enums/TicketCategory.php` — backed string enum: `Account`, `Transaction`, `Kyc`, `Float`, `Commission`, `Technical`, `General`
- [X] T015 [P] Create `app/Modules/Support/Domain/Enums/TicketPriority.php` — backed string enum: `Low`, `Medium`, `High`, `Critical`
- [X] T016 [P] Create `app/Modules/Support/Domain/Enums/DisputeStatus.php` — backed string enum for all 7 statuses; method `isTerminal(): bool` (`Rejected`, `Refunded`); method `isTransitionableTo(self $next): bool`
- [X] T017 [P] Create `app/Modules/Support/Domain/Enums/DisputeReasonCode.php` — backed string enum for all 7 reason codes
- [X] T018 [P] Create `app/Modules/Support/Domain/Enums/EvidenceFileType.php` — backed string enum `ImageJpeg='image_jpeg'`, `ImagePng='image_png'`, `ImageWebp='image_webp'`, `Pdf='pdf'`; static `fromMimeType(string $mime): self` factory
- [X] T019 Create `app/Modules/Support/Infrastructure/Models/SupportTicketModel.php` — Eloquent model; `$connection='support'`; `$table='support.support_tickets'`; casts for `status`, `category`, `priority` to their enums; relationships `notes()` (hasMany TicketNoteModel), `dispute()` (belongsTo DisputeModel nullable); scopes `forReporter(string $id)`, `forStatus(TicketStatus $s)`
- [X] T020 Create `app/Modules/Support/Infrastructure/Models/TicketNoteModel.php` — Eloquent model; `$connection='support'`; `$table='support.ticket_notes'`; relationship `ticket()` (belongsTo SupportTicketModel); scope `publicOnly()` where `is_internal=false`; cast `is_internal` to bool
- [X] T021 Create `app/Modules/Support/Infrastructure/Models/DisputeModel.php` — Eloquent model; `$connection='support'`; `$table='support.disputes'`; casts for `status`, `reason_code`, `escalated`; relationships `evidence()` (hasMany DisputeEvidenceModel), `ticket()` (belongsTo SupportTicketModel nullable); scopes `forReporter(string $id)`, `activeForTransaction(string $txId)`, `escalatedOnly()`
- [X] T022 Create `app/Modules/Support/Infrastructure/Models/DisputeEvidenceModel.php` — Eloquent model; `$connection='support'`; `$table='support.dispute_evidence'`; cast `file_type` to EvidenceFileType; relationship `dispute()` (belongsTo DisputeModel); static `countForDispute(string $disputeId): int`

**Checkpoint**: `php artisan migrate` — all 6 migrations execute; `php artisan test --filter SupportFoundational` green

---

## Phase 3: US1 — Create Support Request (US-SUPPORT-001, P1)

**Goal**: Authenticated customer or agent submits a support request and receives a ticket with UUID v7 ID and sequential ticket number.

**Independent Test**: `POST /v1/support/tickets` with valid payload → 201 with `status=open` and `ticket_number` matching `TKT-\d{6}`; duplicate `Idempotency-Key` → original ticket unchanged.

- [X] T023 [P] [US1] Create `app/Modules/Support/Infrastructure/Http/Requests/CreateSupportTicketRequest.php` — validate `subject` (required, max:255), `description` (required), `category` (required, in TicketCategory values), `priority` (optional, in TicketPriority values)
- [X] T024 [P] [US1] Create `app/Modules/Support/Infrastructure/Http/Resources/SupportTicketResource.php` — expose all SupportTicketModel fields; include nested `notes` array (public only for non-admin, all for admin) in detail context; never expose `idempotency_key` or `correlation_id`
- [X] T025 [P] [US1] Create domain event `app/Modules/Support/Domain/Events/SupportTicketCreatedEvent.php` — fields: `ticketId`, `ticketNumber`, `reporterId`, `reporterType`, `category`, `occurredAt`, `correlationId`; implements `IntegrationEventInterface`
- [X] T026 [US1] Create `app/Modules/Support/Application/Services/SupportTicketService.php` — implement `createTicket(array $data, string $reporterId, string $reporterType, string $idempotencyKey, string $correlationId): SupportTicketModel`; use `IdempotencyServiceInterface::acquireLock()` / `findCompletedResponse()`; generate ticket number via `nextval('support.ticket_number_seq')` formatted `TKT-%06d`; create `SupportTicketModel`; publish `SupportTicketCreatedEvent` via `OutboxEventPublisher` inside DB transaction; record idempotency response on commit
- [X] T027 [US1] Create `app/Modules/Support/Infrastructure/Http/Controllers/SupportTicketController.php` — `store()` method: extract reporter from auth token, call `SupportTicketService::createTicket()`, return `SupportTicketResource` HTTP 201; add route `POST /v1/support/tickets` with auth+`support.create` middleware

**Checkpoint**: `curl -X POST /v1/support/tickets` returns 201 with `ticket_number=TKT-000001`; duplicate key returns same ticket

> **Test cadence note**: Feature tests for US1–US5 (ticket domain) are covered collectively by T097–T099 in Phase 16. Implement and checkpoint phases 3–7 together before running Phase 16 tests.

---

## Phase 4: US2 — View Support Requests (US-SUPPORT-002, P1)

**Goal**: Customers see only their tickets; admins see all with filters; cursor-based pagination; detail includes public notes only for non-admin.

**Independent Test**: Customer GETs their tickets list — exactly their tickets; admin GETs with `?status=open` — all open; customer GETs another customer's ticket → 403.

- [X] T028 [P] [US2] Add `listTickets(string $actorId, string $actorRole, array $filters, ?string $cursor, int $perPage): array` to `SupportTicketService` — apply `forReporter()` scope for non-admin; apply optional filters `status`, `category`, `assignee_id`, `reporter_id`, `date_from`, `date_to`; cursor pagination keyset on `(created_at DESC, id DESC)`; return `['items' => [...], 'meta' => ['next_cursor', 'prev_cursor', 'total']]`
- [X] T029 [P] [US2] Add `getTicket(string $ticketId, string $actorId, string $actorRole): SupportTicketModel` to `SupportTicketService` — enforce 403 if non-admin actor is not reporter; eager-load `notes` with `publicOnly()` scope for non-admin
- [X] T030 [P] [US2] Create domain exceptions `app/Modules/Support/Domain/Exceptions/TicketNotFoundException.php` and `TicketForbiddenException.php`; register in `SupportServiceProvider` handler mapping to HTTP 404 and 403
- [X] T031 [US2] Add `index()` and `show()` actions to `SupportTicketController` — `index()` calls `listTickets()`, returns paginated `SupportTicketResource` collection; `show()` calls `getTicket()`, returns detail with notes; add routes `GET /v1/support/tickets` and `GET /v1/support/tickets/{id}`
- [X] T032 [US2] Write HTTP feature test `tests/Feature/Support/SupportTicketViewTest.php` — reporter sees own tickets; admin sees all; cross-reporter 403; `?status=open` filter; pagination cursor round-trip

**Checkpoint**: SC-1 step 2 and SC-5 cross-reporter 403 from quickstart.md pass

---

## Phase 5: US3 — Assign Support Request (US-SUPPORT-003, P1)

**Goal**: Support manager assigns a ticket to a support agent; auto-transition to `in_progress`; internal note auto-appended; `support.assign` required.

**Independent Test**: Admin PATCHes `/assign` with valid `assignee_id` → `status=in_progress`, `assignee_id` set, internal note created; closed ticket → 422; invalid assignee → 422.

- [X] T033 [P] [US3] Create `app/Modules/Support/Infrastructure/Http/Requests/AssignTicketRequest.php` — validate `assignee_id` (required, uuid)
- [X] T034 [P] [US3] Create domain events `app/Modules/Support/Domain/Events/SupportTicketAssignedEvent.php` and `SupportTicketReassignedEvent.php` — fields: `ticketId`, `assigneeId`, `assignedById`, `occurredAt`, `correlationId`
- [X] T035 [P] [US3] Create domain exceptions `app/Modules/Support/Domain/Exceptions/TicketAlreadyClosedException.php` and `InvalidTicketStatusTransitionException.php`; map to HTTP 422
- [X] T036 [US3] Add `assignTicket(string $ticketId, string $assigneeId, string $assignedById, string $correlationId): SupportTicketModel` to `SupportTicketService` — validate assignee is admin user (look up via auth service); enforce 422 if `status=closed`; transition `open→in_progress` (or keep `in_progress` for re-assign); update `assignee_id`; create internal `TicketNoteModel` ("Assigned to [name] by [name]" or "Re-assigned …"); publish `SupportTicketAssignedEvent` / `SupportTicketReassignedEvent` via outbox; DB transaction
- [X] T037 [US3] Create `app/Modules/Support/Infrastructure/Http/Controllers/SupportTicketAssignController.php` — `__invoke()` checks `support.assign`, calls `assignTicket()`, returns `SupportTicketResource`; add route `PATCH /v1/support/tickets/{id}/assign`

**Checkpoint**: SC-1 step 3 from quickstart.md passes — `status=in_progress`, note auto-created

---

## Phase 6: US4 — Update Support Status (US-SUPPORT-004, P1)

**Goal**: State machine transitions enforced by `TicketStatus::isTransitionableTo()`; `resolved`/`closed` require `resolution_note`; reopen clears `resolved_at`.

**Independent Test**: Agent PATCHes `resolved` with note → `status=resolved`, `resolved_at` set; reporter reopens → `status=open`, `resolved_at=null`; direct `open→closed` → 422.

- [X] T038 [P] [US4] Create `app/Modules/Support/Infrastructure/Http/Requests/UpdateTicketStatusRequest.php` — validate `status` (required, in TicketStatus values), `resolution_note` (required_if:status,resolved,closed)
- [X] T039 [P] [US4] Create domain events `app/Modules/Support/Domain/Events/SupportTicketResolvedEvent.php` and `SupportTicketReopenedEvent.php` — fields: `ticketId`, `actorId`, `occurredAt`, `correlationId`
- [X] T040 [US4] Add `updateStatus(string $ticketId, TicketStatus $newStatus, ?string $resolutionNote, string $actorId, string $actorRole, string $correlationId): SupportTicketModel` to `SupportTicketService` — permission check (non-admin may only reopen; admin may do all); `TicketStatus::isTransitionableTo()` guard (422 on invalid); if transitioning to `Closed` and `ticket->dispute_id` is not null, verify linked `DisputeModel::find($ticket->dispute_id)->status->isTerminal()` — throw 422 `TICKET_HAS_ACTIVE_DISPUTE` if false; set/clear `resolved_at`, `closed_at`; append auto-note; publish event via outbox; DB transaction
- [X] T041 [US4] Create `app/Modules/Support/Infrastructure/Http/Controllers/SupportTicketStatusController.php` — `__invoke()` calls `updateStatus()`, returns `SupportTicketResource`; add route `PATCH /v1/support/tickets/{id}/status`

**Checkpoint**: SC-1 step 4 and reopen scenarios from quickstart.md pass

---

## Phase 7: US5 — Add Support Notes (US-SUPPORT-005, P1)

**Goal**: Internal notes visible to agents only; public notes visible to all; public note from agent triggers E17 notification to reporter; closed ticket notes rejected.

**Independent Test**: Admin POSTs `is_internal=true` note → stored; reporter GETs ticket detail → internal note absent; public note → E17 dispatch; note on closed ticket → 422.

- [X] T042 [P] [US5] Create `app/Modules/Support/Infrastructure/Http/Requests/AddTicketNoteRequest.php` — validate `content` (required), `is_internal` (optional bool); force `is_internal=false` for customer/agent callers in `prepareForValidation()`
- [X] T043 [P] [US5] Create `app/Modules/Support/Infrastructure/Http/Resources/TicketNoteResource.php` — expose `id`, `ticket_id`, `author_id`, `author_type`, `content`, `is_internal`, `created_at`
- [X] T044 [P] [US5] Create domain event `app/Modules/Support/Domain/Events/TicketNoteAddedEvent.php` — fields: `ticketId`, `noteId`, `authorId`, `isInternal`, `reporterId`, `occurredAt`, `correlationId`
- [X] T045 [US5] Create `app/Modules/Support/Application/Services/SupportTicketNoteService.php` — `addNote(string $ticketId, string $content, bool $isInternal, string $authorId, string $authorType, string $correlationId): TicketNoteModel`; enforce 422 if `ticket.status=closed`; if `authorType` is `customer` or `agent`, verify reporter account is active via E01/E03 user status lookup — throw 422 `REPORTER_ACCOUNT_DEACTIVATED` if inactive; create `TicketNoteModel`; publish `TicketNoteAddedEvent` via outbox; register queued event listener `TicketNoteAddedNotificationListener` that calls `NotificationModuleContract::dispatch($reporterId, 'push', ['type'=>'ticket_note_added','ticket_number'=>$ticket->ticket_number])` only when `isInternal=false` and `authorType='admin'`
- [X] T046 [US5] Create `app/Modules/Support/Infrastructure/Http/Controllers/SupportTicketNoteController.php` — `store()` calls `SupportTicketNoteService::addNote()`, returns `TicketNoteResource` HTTP 201; add route `POST /v1/support/tickets/{id}/notes`

**Checkpoint**: SC-2 internal/public note scenarios from quickstart.md pass

---

## Phase 8: US6 — Raise Transaction Dispute (US-DISPUTE-001, P1)

**Goal**: Customer or agent raises a dispute against a terminal-state transaction; one active dispute per transaction; idempotent.

**Independent Test**: `POST /v1/disputes` → 201 with `status=raised`, `dispute_reference=DSP-000001`; second for same transaction → 409; pending transaction → 422; duplicate idempotency key → original.

- [X] T047 [P] [US6] Create `app/Modules/Support/Infrastructure/Http/Requests/RaiseDisputeRequest.php` — validate `transaction_id` (required, uuid), `description` (required), `ticket_id` (optional, uuid)
- [X] T048 [P] [US6] Create `app/Modules/Support/Infrastructure/Http/Resources/DisputeResource.php` — expose all DisputeModel fields; include nested `evidence` array in detail response; exclude `refund_idempotency_key`, `correlation_id`, `idempotency_key` from output
- [X] T049 [P] [US6] Create domain event `app/Modules/Support/Domain/Events/DisputeRaisedEvent.php` — fields: `disputeId`, `transactionId`, `reporterId`, `reporterType`, `occurredAt`, `correlationId`
- [X] T050 [P] [US6] Create domain exceptions `DisputeAlreadyExistsException.php` and `TransactionNotDisputableException.php` in `app/Modules/Support/Domain/Exceptions/`; map to HTTP 409 and 422
- [X] T051 [US6] Create `app/Modules/Support/Application/Services/DisputeService.php` — `raiseDispute(string $transactionId, string $description, string $reporterId, string $reporterType, ?string $ticketId, string $idempotencyKey, string $correlationId): DisputeModel`; use `IdempotencyServiceInterface`; verify transaction is terminal-state via `TransactionModuleContract` (resolve via app container); enforce unique active dispute via `DisputeModel::activeForTransaction()` (409 if found); generate `dispute_reference` from `support.dispute_number_seq`; lookup `currency_code` from transaction; create `DisputeModel` with `status=raised`; publish `DisputeRaisedEvent` via outbox; DB transaction
- [X] T052 [US6] Create `app/Modules/Support/Infrastructure/Http/Controllers/DisputeController.php` — `store()` creates dispute; `index()` lists (reporter scope for non-admin); `show()` returns detail with evidence; add routes `POST /v1/disputes`, `GET /v1/disputes`, `GET /v1/disputes/{id}`
- [X] T053 [US6] Write HTTP feature test `tests/Feature/Support/DisputeRaiseTest.php` — happy path; 409 duplicate transaction; 422 pending transaction; cross-reporter 403; idempotency replay

**Checkpoint**: SC-3 step 1 from quickstart.md passes; `dispute_reference=DSP-000001`

---

## Phase 9: US7 — Select Dispute Reason (US-DISPUTE-002, P1)

**Goal**: Reporter sets/updates reason code before investigation starts; `other` requires description ≥ 20 chars; locked at `under_investigation`.

**Independent Test**: PATCH reason on `raised` dispute → `reason_code` set; PATCH on `under_investigation` → 422 `DISPUTE_REASON_LOCKED`.

- [X] T054 [P] [US7] Create `app/Modules/Support/Infrastructure/Http/Requests/SelectDisputeReasonRequest.php` — validate `reason_code` (required, in DisputeReasonCode values), `reason_description` (required_if:reason_code,other, min:20)
- [X] T055 [P] [US7] Create domain event `app/Modules/Support/Domain/Events/DisputeReasonChangedEvent.php` — fields: `disputeId`, `reasonCode`, `isUpdate` (bool; true if reason was already set), `actorId`, `occurredAt`, `correlationId`
- [X] T056 [US7] Add `selectReason(string $disputeId, DisputeReasonCode $reasonCode, ?string $reasonDescription, string $actorId, string $correlationId): DisputeModel` to `DisputeService` — enforce actor is reporter; enforce 422 `DISPUTE_REASON_LOCKED` if `status=under_investigation`; update `reason_code` and `reason_description`; publish `DisputeReasonChangedEvent` via outbox; DB transaction
- [X] T057 [US7] Create `app/Modules/Support/Infrastructure/Http/Controllers/DisputeReasonController.php` — `__invoke()` calls `selectReason()`, returns `DisputeResource`; add route `PATCH /v1/disputes/{id}/reason`

**Checkpoint**: SC-3 step 2 from quickstart.md passes; `DISPUTE_REASON_LOCKED` guard confirmed

---

## Phase 10: US8 — Attach Evidence (US-DISPUTE-003, P1)

**Goal**: Up to 5 files; JPEG/PNG/WebP/PDF; max 10 MB each; first attachment transitions dispute to `evidence_submitted`; locked when `under_investigation`; signed URL endpoint.

**Independent Test**: Upload PNG → `DisputeEvidence` created, `dispute.status=evidence_submitted`; 6th upload → 422; >10 MB → 422; bad MIME → 422; on `under_investigation` → 422.

- [X] T058 [P] [US8] Create `app/Modules/Support/Infrastructure/Http/Requests/DisputeEvidenceUploadRequest.php` — validate `file` (required, file, mimes:jpeg,png,webp,pdf, max:10240 [KB])
- [X] T059 [P] [US8] Create `app/Modules/Support/Infrastructure/Http/Resources/DisputeEvidenceResource.php` — expose `id`, `dispute_id`, `file_type`, `original_filename`, `size_bytes`, `created_at`; `signed_url` included only when explicitly requested in detail context
- [X] T060 [P] [US8] Create domain event `app/Modules/Support/Domain/Events/DisputeEvidenceAttachedEvent.php` — fields: `disputeId`, `evidenceId`, `uploadedBy`, `occurredAt`, `correlationId`
- [X] T061 [P] [US8] Create domain exceptions `EvidenceLimitReachedException.php` and `EvidenceLockedException.php`; map to HTTP 422
- [X] T062 [US8] Create `app/Modules/Support/Application/Services/DisputeEvidenceService.php` — `attachEvidence(string $disputeId, UploadedFile $file, string $uploadedBy, string $idempotencyKey, string $correlationId): DisputeEvidenceModel`; use `IdempotencyServiceInterface`; enforce `!dispute->status->isTerminal()` and not `under_investigation` (422 `EVIDENCE_LOCKED`); `countForDispute <= 4` (422 if 5 already exist); resolve `EvidenceFileType` via `EvidenceFileType::fromMimeType($file->getMimeType())`; call `EvidenceStorageContract::store()`; create `DisputeEvidenceModel`; if first evidence, transition dispute to `evidence_submitted`; publish event via outbox; DB transaction
- [X] T063 [US8] Create `app/Modules/Support/Infrastructure/Http/Controllers/DisputeEvidenceController.php` — `store()` calls `attachEvidence()`, returns `DisputeEvidenceResource` HTTP 201; `signedUrl()` calls `EvidenceStorageContract::generateSignedUrl()`, returns `{signed_url, expires_at}`; add routes `POST /v1/disputes/{id}/evidence` and `GET /v1/disputes/{id}/evidence/{evidenceId}/signed-url`
- [X] T064 [US8] Write HTTP feature test `tests/Feature/Support/DisputeEvidenceTest.php` — upload PNG transitions status; 6th file; oversized; wrong MIME; `under_investigation` lock; idempotency
- [X] T065 [US8] Write unit test `tests/Unit/Support/EvidenceStorageTest.php` — mock Laravel disk; verify `store()` path `support-evidence/{disputeId}/{evidenceId}.png`; verify `generateSignedUrl()` returns URL and respects TTL

**Checkpoint**: SC-3 step 3 and SC-6 evidence limit from quickstart.md pass

---

## Phase 11: US9 — Investigate Dispute (US-DISPUTE-004, P1)

**Goal**: Support agent starts investigation; `status` → `under_investigation`; locks reason/evidence; `support.investigate` required.

**Independent Test**: Admin PATCHes `/investigate` → `status=under_investigation`, `investigator_id` set; second PATCH → 409; reporter PATCH → 403.

- [X] T066 [P] [US9] Create domain event `app/Modules/Support/Domain/Events/DisputeInvestigationStartedEvent.php` — fields: `disputeId`, `investigatorId`, `occurredAt`, `correlationId`
- [X] T067 [P] [US9] Create domain exception `DisputeAlreadyUnderInvestigationException.php`; map to HTTP 409
- [X] T068 [US9] Create `app/Modules/Support/Application/Services/DisputeInvestigationService.php` — `startInvestigation(string $disputeId, string $investigatorId, string $correlationId): DisputeModel`; enforce `status` not `under_investigation` (409); transition to `under_investigation`; set `investigator_id` and `investigation_started_at`; publish event via outbox; DB transaction
- [X] T069 [US9] Create `app/Modules/Support/Infrastructure/Http/Controllers/DisputeInvestigateController.php` — `__invoke()` checks `support.investigate`, calls `startInvestigation()`, returns `DisputeResource`; add route `PATCH /v1/disputes/{id}/investigate`
- [X] T070 [US9] Write HTTP feature test `tests/Feature/Support/DisputeInvestigateTest.php` — happy path; 409 on second call; 403 for reporter; `raised` (no evidence) still transitions

**Checkpoint**: SC-3 step 4 from quickstart.md passes

---

## Phase 12: US10 — Approve Dispute (US-DISPUTE-005, P1)

**Goal**: Privileged admin approves investigated dispute; optional maker-checker via config flag; `pending_confirmation` intermediate state; second approver must differ from first.

**Independent Test**: Admin PATCHes `/approve` with notes → `status=approved`; with maker-checker config flag → `status=pending_confirmation`; second admin confirms → `status=approved`; same actor confirms → 403.

- [X] T071 [P] [US10] Create `app/Modules/Support/Infrastructure/Http/Requests/ApproveDisputeRequest.php` — validate `resolution_notes` (required)
- [X] T072 [P] [US10] Create domain events `app/Modules/Support/Domain/Events/DisputeApprovedEvent.php` and `DisputePendingConfirmationEvent.php`
- [X] T073 [US10] Create `app/Modules/Support/Application/Services/DisputeResolutionService.php` — `approveDispute(string $disputeId, string $approverId, string $resolutionNotes, string $idempotencyKey, string $correlationId): DisputeModel`; enforce `status=under_investigation`; read `config('support.dispute.approve.require_second_approval', false)`; if `true` → `status=pending_confirmation`, `approved_by=$approverId`, emit `DisputePendingConfirmationEvent`; if `false` → `status=approved`, `approved_by=$approverId`, `approved_at=now()`, emit `DisputeApprovedEvent`; idempotent via `IdempotencyServiceInterface`; DB transaction
- [X] T074 [US10] Add `confirmApproval(string $disputeId, string $confirmerId, string $idempotencyKey, string $correlationId): DisputeModel` to `DisputeResolutionService` — enforce `status=pending_confirmation` (409 if not); enforce `confirmerId != approved_by` (403 same-actor); set `second_approver_id`, `second_approved_at`, `approved_at=now()`; transition to `approved`; emit `DisputeApprovedEvent`; DB transaction
- [X] T075 [US10] Create `app/Modules/Support/Infrastructure/Http/Controllers/DisputeApproveController.php` — `__invoke()` checks `dispute.approve`, calls `DisputeResolutionService::approveDispute()`, returns `DisputeResource`; add route `PATCH /v1/disputes/{id}/approve`
- [X] T075b [US10] Create `app/Modules/Support/Infrastructure/Http/Controllers/DisputeConfirmApprovalController.php` — `__invoke()` checks `dispute.approve`, calls `DisputeResolutionService::confirmApproval()`, returns `DisputeResource`; add route `PATCH /v1/disputes/{id}/confirm-approval`
- [X] T076 [US10] Write HTTP feature test `tests/Feature/Support/DisputeApproveTest.php` — happy path; maker-checker two-approver flow; same-actor confirm 403; wrong status 422; missing notes 422; idempotency replay

**Checkpoint**: SC-3 step 5 and SC-5 admin-only guard from quickstart.md pass

---

## Phase 13: US11 — Reject Dispute (US-DISPUTE-006, P1)

**Goal**: Privileged admin rejects investigated dispute with reason and notes; E17 notification sent to reporter; idempotent.

**Independent Test**: Admin PATCHes `/reject` → `status=rejected`; `DisputeRejectedEvent` emitted; E17 notification dispatched; approved dispute → 422.

- [X] T077 [P] [US11] Create `app/Modules/Support/Infrastructure/Http/Requests/RejectDisputeRequest.php` — validate `rejection_reason` (required, max:100), `rejection_notes` (required)
- [X] T078 [P] [US11] Create domain event `app/Modules/Support/Domain/Events/DisputeRejectedEvent.php` — fields: `disputeId`, `reporterId`, `rejectedById`, `rejectionReason`, `occurredAt`, `correlationId`
- [X] T079 [US11] Add `rejectDispute(string $disputeId, string $rejectorId, string $rejectionReason, string $rejectionNotes, string $idempotencyKey, string $correlationId): DisputeModel` to `DisputeResolutionService` — enforce `status=under_investigation`; set `rejected_by`, `rejected_at`, `rejection_reason`, `rejection_notes`; transition to `rejected`; emit `DisputeRejectedEvent` via outbox; register event listener `DisputeRejectedNotificationListener` calling `NotificationModuleContract::dispatch($reporterId, 'push', ['type'=>'dispute_rejected','reference'=>$dispute->dispute_reference,'reason'=>$rejectionReason])`; DB transaction
- [X] T080 [US11] Create `app/Modules/Support/Infrastructure/Http/Controllers/DisputeRejectController.php` — `__invoke()` checks `dispute.reject`, calls `rejectDispute()`, returns `DisputeResource`; add route `PATCH /v1/disputes/{id}/reject`
- [X] T081 [US11] Write HTTP feature test `tests/Feature/Support/DisputeRejectTest.php` — happy path; 403 non-admin; missing notes 422; approved dispute 422; notification listener fires; idempotency replay

**Checkpoint**: SC-3 rejection path confirmed; E17 notification dispatched to reporter

---

## Phase 14: US12 — Refund Disputed Transaction (US-DISPUTE-007, P1)

**Goal**: Financial refund via E07 double-entry; E21 fee reversal; atomic with status transition; full and partial; idempotent.

**Independent Test**: `POST /v1/disputes/{id}/refund` → balanced journal posted; `status=refunded`; duplicate idempotency key → same result, no duplicate journal; E07 failure → dispute remains `approved`; partial without justification → 422.

- [X] T082 [P] [US12] Create `app/Modules/Support/Infrastructure/Http/Requests/DisputeRefundRequest.php` — `refund_amount_minor` (optional, integer, min:1) and `partial_refund_justification` (required_with:refund_amount_minor); note: whether the supplied amount is truly partial (less than original transaction amount) is enforced in `DisputeRefundService::executeRefund()`, not in the FormRequest
- [X] T083 [P] [US12] Create domain event `app/Modules/Support/Domain/Events/DisputeRefundedEvent.php` — fields: `disputeId`, `transactionId`, `reporterId`, `refundedAmountMinor`, `currencyCode`, `ledgerEntryId`, `occurredAt`, `correlationId`
- [X] T084 [P] [US12] Create domain exceptions `DisputeNotApprovedException.php` and `DisputeRefundException.php`; map to HTTP 422 and HTTP 502 respectively
- [X] T085 [US12] Create `app/Modules/Support/Application/Services/DisputeRefundService.php` — `executeRefund(string $disputeId, ?int $refundAmountMinor, ?string $partialJustification, string $adminId, string $idempotencyKey, string $correlationId): DisputeModel`; enforce `status=approved` and `!refund_pending_confirmation` (422 if either fails); check `disputes.refund_idempotency_key` for replay (return if `refunded`); resolve amount from transaction if null; validate partial (amount ≤ original, justification required); **if `config('support.dispute.refund.require_second_approval', false)` is true:** set `refund_initiator_id=$adminId`, `refund_pending_confirmation=true`, `refund_idempotency_key=$idempotencyKey`, persist model, publish `DisputeRefundPendingConfirmationEvent` via outbox, DB transaction, return dispute without posting to ledger; **otherwise:** register `liability:disputes:{currencyCode}` account via `LedgerModuleContract::registerAccount()` (idempotent); call `LedgerModuleContract::postEntry(['idempotency_key'=>$idempotencyKey, 'entry_type'=>'dispute_refund', 'currency_code'=>$currency, 'correlation_id'=>$correlationId, 'lines'=>[['ledger_account_reference'=>'liability:disputes:'.$currency,'direction'=>'debit','amount'=>$amount],['ledger_account_reference'=>'asset:wallet:'.$reporterId.':'.$currency,'direction'=>'credit','amount'=>$amount]]])` storing returned journal ID; call `FeesModuleContract::reverseFee($txId, 'revenue:fees:'.$currency, 'asset:wallet:'.$reporterId.':'.$currency, Uuid::uuid5(Uuid::NAMESPACE_URL,$txId.':fee:reversal')->toString(), $correlationId)`; set `refund_idempotency_key`, `refunded_amount_minor`, `currency_code`, `partial_refund_justification`, `refund_ledger_entry_id`, `dispute_refunded_at`; transition to `refunded`; publish `DisputeRefundedEvent` via outbox; ALL in single DB transaction — rollback on any failure
- [X] T086 [US12] Create `app/Modules/Support/Infrastructure/Http/Controllers/DisputeRefundController.php` — `__invoke()` checks `dispute.refund`, calls `executeRefund()`, returns `DisputeResource`; add route `POST /v1/disputes/{id}/refund`
- [X] T087 [US12] Write HTTP feature test `tests/Feature/Support/DisputeRefundTest.php` — full refund happy path; partial with justification; partial without justification 422; non-approved status 422; idempotency key replay; E07 mock failure rolls back status
- [X] T088 [US12] Write unit test `tests/Unit/Support/DisputeRefundServiceTest.php` — mock `LedgerModuleContract`, `FeesModuleContract`, `TransactionModuleContract`; verify journal lines debit==credit amounts; verify `reverseFee` idempotency key is `$txId.':fee:reversal'`; verify no-op on duplicate `refund_idempotency_key`
- [X] T089 [US12] Write concurrency test `tests/Feature/Support/DisputeRefundConcurrencyTest.php` — two simultaneous `POST /refund` requests for same approved dispute; verify UNIQUE constraint on `refund_idempotency_key` prevents double posting; only one `DisputeRefunded` event emitted
- [X] T089a [P] [US12] Create domain event `app/Modules/Support/Domain/Events/DisputeRefundPendingConfirmationEvent.php` — fields: `disputeId`, `initiatorId`, `occurredAt`, `correlationId`; implements `IntegrationEventInterface`
- [X] T089b [US12] Add `confirmRefund(string $disputeId, string $confirmerId, string $idempotencyKey, string $correlationId): DisputeModel` to `DisputeRefundService` — enforce `status=approved` and `refund_pending_confirmation=true` (422 if either fails); enforce `confirmerId != refund_initiator_id` (403 same-actor); retrieve stored `refund_idempotency_key`; execute full ledger posting path identical to non-maker-checker path in T085 (registerAccount, postEntry, reverseFee); set `refund_pending_confirmation=false`, `refunded_amount_minor`, `refund_ledger_entry_id`, `dispute_refunded_at`, `refund_confirmer_id=$confirmerId`, `refund_confirmed_at=now()`; transition to `refunded`; publish `DisputeRefundedEvent` via outbox; ALL in single DB transaction — rollback on any failure
- [X] T089c [US12] Create `app/Modules/Support/Infrastructure/Http/Controllers/DisputeConfirmRefundController.php` — `__invoke()` checks `dispute.refund`, calls `DisputeRefundService::confirmRefund()`, returns `DisputeResource`; add route `PATCH /v1/disputes/{id}/confirm-refund`
- [X] T089d [US12] Add two-approver refund scenario to `tests/Feature/Support/DisputeRefundTest.php` — set `config(['support.dispute.refund.require_second_approval' => true])`; first admin `POST /refund` → `refund_pending_confirmation=true`, no ledger entry created; second admin `PATCH /confirm-refund` → `status=refunded`, journal posted, `DisputeRefundedEvent` emitted; same-actor confirm → 403; confirm on non-pending dispute → 422

**Checkpoint**: SC-3 steps 6–7 (refund + idempotency) from quickstart.md pass; ledger rollback test passes; maker-checker two-approver flow passes

---

## Phase 15: US13 — Escalate Dispute (US-DISPUTE-008, P1)

**Goal**: Reporter or admin sets `escalated=true` on active dispute; terminal disputes rejected; double-escalation rejected; E17 dispatch to escalation queue.

**Independent Test**: PATCH `/escalate` with reason → `escalated=true`, `escalated_at` set; status unchanged; second PATCH → 409; `refunded` dispute → 422.

- [X] T090 [P] [US13] Create `app/Modules/Support/Infrastructure/Http/Requests/EscalateDisputeRequest.php` — validate `escalation_reason` (required)
- [X] T091 [P] [US13] Create domain event `app/Modules/Support/Domain/Events/DisputeEscalatedEvent.php` — fields: `disputeId`, `escalatedBy`, `escalationReason`, `occurredAt`, `correlationId`
- [X] T092 [P] [US13] Create domain exceptions `DisputeAlreadyEscalatedException.php` and `DisputeAlreadyResolvedException.php`; map to HTTP 409 and HTTP 422
- [X] T093 [US13] Add `escalateDispute(string $disputeId, string $escalationReason, string $escalatedBy, string $correlationId): DisputeModel` to `DisputeService` — enforce actor is the dispute reporter OR holds `support.investigate` permission (assigned support agent) OR holds `dispute.escalate` admin permission; enforce `!dispute->status->isTerminal()` (422); enforce `!dispute->escalated` (409); set `escalated=true`, `escalated_at=now()`, `escalation_reason`; register `DisputeEscalatedNotificationListener` — if `config('support.escalation_queue_id')` is non-null, call `NotificationModuleContract::dispatch(config('support.escalation_queue_id'), 'push', ['type'=>'dispute_escalated','reference'=>$dispute->dispute_reference,'reason'=>$escalationReason])`; otherwise log a WARNING-level entry `"support.escalation_queue_id not configured; escalation notification skipped"`; publish event via outbox; DB transaction
- [X] T094 [US13] Create `app/Modules/Support/Infrastructure/Http/Controllers/DisputeEscalateController.php` — `__invoke()` calls `escalateDispute()`, returns `DisputeResource`; add route `PATCH /v1/disputes/{id}/escalate`

**Checkpoint**: SC-4 escalation scenarios from quickstart.md pass

---

## Phase 16: Polish & Cross-Cutting Concerns

**Purpose**: Module contract, OpenAPI, PHPStan, test coverage, and Docker validation.

- [X] T095 [P] Create `app/Modules/Support/Domain/Contracts/SupportModuleContract.php` — `hasActiveDispute(string $transactionId): bool`, `getDisputeReferenceForTransaction(string $transactionId): ?string`, `getTicketReferenceForDispute(string $disputeId): ?string`; implement in `SupportModuleService` (path: `app/Modules/Support/Application/Services/SupportModuleService.php`); bind in `SupportServiceProvider`
- [X] T096 [P] Copy `specs/022-support-dispute-management/contracts/support.yaml` to `openapi/v1/support.yaml`
- [X] T097 [P] Write HTTP feature test `tests/Feature/Support/SupportTicketAssignTest.php` — assign open ticket; re-assign; assign closed 422; invalid assignee 422; 403 without permission
- [X] T098 [P] Write HTTP feature test `tests/Feature/Support/SupportTicketStatusTest.php` — resolve with note; reopen from resolved; close from resolved; direct open→closed 422; missing note 422; permission guard
- [X] T099 [P] Write HTTP feature test `tests/Feature/Support/SupportTicketNoteTest.php` — admin internal note; customer cannot see internal; reporter adds note (forced public); note on closed ticket 422
- [X] T100 [P] Add `config/support.php` — configurable defaults: `dispute.approve.require_second_approval` (bool, default `false`), `dispute.refund.require_second_approval` (bool, default `false`), `escalation_queue_id` (uuid of escalation admin user, default null), `evidence.max_count` (int, default 5), `evidence.max_size_mb` (int, default 10)
- [X] T101 Run `./vendor/bin/phpstan analyse app/Modules/Support --level=6` and fix all type errors
- [X] T102 Run `php artisan test --filter Support` — all tests pass; fix any failures
- [X] T103 Run quickstart.md validation scenarios SC-1 through SC-6 against Docker environment and confirm all pass; update quickstart.md step numbers if any command needs correction

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 — BLOCKS all story phases
- **Phases 3–7 (US1–US5)**: Depend on Phase 2; US2→US1; US3→US2; US4→US3; US5→US1
- **Phases 8–15 (US6–US13)**: Depend on Phase 2; US7/US8/US13→US6; US9→US8; US10/US11→US9; US12→US10
- **Phase 16 (Polish)**: Depends on all story phases

### Story Dependency Graph

```
Phase 2 (Foundation)
  ├── US1 (Create Ticket) ← US2 (View) ← US3 (Assign) ← US4 (Status)
  │                                          └── US5 (Notes) ← US1
  └── US6 (Raise Dispute) ← US7 (Reason)
                           ├── US8 (Evidence) ← US9 (Investigate)
                           │                        ├── US10 (Approve) ← US12 (Refund)
                           │                        └── US11 (Reject)
                           └── US13 (Escalate)
```

### Parallel Opportunities per Phase

| Phase | Parallelisable Tasks |
|---|---|
| Phase 2 | T013–T018 (all 6 enums simultaneously); then T019–T022 (4 models simultaneously) |
| Phase 3 | T023–T025 simultaneously; then T026 after T025 |
| Phase 8 | T047–T050 simultaneously; T051 after; T052/T053 sequentially |
| Phase 10 | T058–T061 simultaneously; T062 after; T063–T065 after T062 |
| Phase 12 | T071–T072 simultaneously; T073–T074 after |
| Phase 16 | T095–T100 simultaneously |

---

## Implementation Strategy

### MVP Scope (Phases 1–3 + 8)

1. Phase 1 + 2 (Setup + Foundation)
2. Phase 3 (US1 — Create Ticket)
3. Phase 8 (US6 — Raise Dispute)
4. **VALIDATE**: Customer can open a ticket and raise a dispute
5. Deploy as MVP

### Incremental Delivery

| Sprint | Phases | Deliverable |
|---|---|---|
| 1 | 1 + 2 + 3 + 4 | Support ticket create & view |
| 2 | 5 + 6 + 7 | Assign, status transitions, notes |
| 3 | 8 + 9 + 10 | Raise dispute, reason, evidence |
| 4 | 11 + 12 + 13 | Investigate, approve, reject |
| 5 | 14 + 15 + 16 | Refund (financial), escalate, polish |

### Notes

- T010 partial unique index on `disputes.transaction_id` is the primary guard — do not skip
- T085 `DisputeRefundService` must be reviewed by a second developer before merge
- T089 concurrency test must pass before T096 (OpenAPI) to confirm financial safety
- Run `php artisan migrate:fresh --seed` between test cycles in Docker
