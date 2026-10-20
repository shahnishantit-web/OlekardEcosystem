# Feature Specification: E22 — Support & Dispute Management

**Feature Branch**: `022-support-dispute-management`

**Created**: 2026-09-28

**Status**: Draft

## Overview

The Support & Dispute Management module provides the full customer care and financial-dispute lifecycle for the OleKard wallet ecosystem. It enables customers and agents to create support tickets for any platform issue, and to raise formal disputes against specific transactions they believe are erroneous, unauthorized, or undelivered. Support agents (admin users) can triage, assign, investigate, and resolve both support cases and disputes. Approved disputes trigger a compensating financial refund through the E07 Ledger, maintaining double-entry integrity.

This module directly supports Constitution Principle 3 (configurable escalation policies and SLA thresholds), Principle 4 (dispute refund must use a compensating ledger entry, never direct balance mutation), Principle 5 (step-up auth for privileged dispute approval), and Principle 13 (full audit trail for all support and dispute operations).

## Actors

- **Customer** — Raises support requests and transaction disputes from the mobile app; attaches evidence; tracks case status
- **Agent** — Raises support requests (for self or on behalf of a customer) from the agent app; attaches evidence
- **Support Agent (Admin)** — Triages incoming tickets; assigns cases to agents; adds investigation notes; updates ticket and dispute status; investigates disputes
- **Operations Admin** — Privileged role that approves or rejects disputes; authorizes refunds; subject to maker-checker workflow where configured
- **Platform (System)** — Emits events on state changes; posts compensating ledger entries for approved dispute refunds; enforces SLA alerting

## User Scenarios & Testing

### User Story 1 — Create Support Request (US-SUPPORT-001, Priority: P1)

A customer or agent submits a support request describing their issue. The requester provides a subject, description, category (e.g., `account`, `transaction`, `kyc`, `general`), and optional priority hint. The system assigns a human-readable ticket number (e.g., `TKT-000123`), sets initial status to `open`, and emits a `SupportTicketCreated` event. The requester receives the ticket ID and number in the response.

**Why this priority**: The support ticket is the root entity for all follow-on stories — it must exist before it can be viewed, assigned, or have notes added.

**Independent Test**: A customer POSTs a support request with subject "Transfer not received" and category `transaction`; a ticket is returned with a UUID v7 ID, a sequential ticket number, and `status = open`; a `SupportTicketCreated` audit event is emitted.

**Acceptance Scenarios**:
1. **Given** an authenticated customer, **When** they POST `/v1/support/tickets` with `subject`, `description`, `category = transaction`, **Then** a `SupportTicket` record is created with `status = open`, a unique ticket number, `reporter_id = customer.id`, and the response returns the ticket ID, number, and status.
2. **Given** an authenticated agent, **When** they POST a support request with `category = float`, **Then** the ticket is created with `reporter_type = agent`; the agent's ID is set as `reporter_id`.
3. **Given** a support request with missing `subject` or `description`, **When** submitted, **Then** a 422 Unprocessable Entity is returned with `VALIDATION_ERROR` listing each missing field.
4. **Given** a support request with an invalid `category` value, **When** submitted, **Then** a 422 is returned with `INVALID_CATEGORY`.
5. **Given** an unauthenticated actor, **When** they attempt to create a ticket, **Then** a 401 Unauthorized is returned.
6. **Given** the same request is submitted twice with the same `Idempotency-Key`, **When** the second request arrives, **Then** the original ticket is returned without creating a duplicate; the response is identical to the first.
7. **Given** a ticket is created successfully, **When** the event bus processes the result, **Then** a `SupportTicketCreated` audit event is emitted containing `ticket_id`, `reporter_id`, `category`, `occurred_at`, `correlation_id`.

**API**: `POST /v1/support/tickets`

**DB Entities**: SupportTicket

---

### User Story 2 — View Support Requests (US-SUPPORT-002, Priority: P1)

Customers and agents see only their own tickets. Support agents and admins see all tickets with filtering and pagination. The response includes ticket number, subject, category, status, assignee name, created date, and last-updated date. Pagination uses cursor-based navigation to support large result sets.

**Why this priority**: Without viewing, there is no feedback loop for reporters and no work queue for support agents.

**Independent Test**: A customer who has two open tickets GETs `/v1/support/tickets`; the response lists exactly their two tickets; a second customer cannot retrieve the first customer's tickets.

**Acceptance Scenarios**:
1. **Given** a customer with two tickets, **When** they GET `/v1/support/tickets`, **Then** the response contains exactly those two tickets; each entry includes `id`, `ticket_number`, `subject`, `category`, `status`, `created_at`.
2. **Given** a support agent, **When** they GET `/v1/support/tickets`, **Then** all tickets across all reporters are returned, with optional filters: `status`, `category`, `assignee_id`, `reporter_id`, `date_from`, `date_to`.
3. **Given** a customer attempts to GET `/v1/support/tickets/{id}` for a ticket belonging to another customer, **Then** a 403 Forbidden is returned.
4. **Given** a support agent GETs `/v1/support/tickets/{id}` for any ticket, **Then** the full ticket detail is returned, including all notes (non-internal notes to customers; all notes to agents).
5. **Given** a filter `status=open` is applied, **When** a support agent queries, **Then** only tickets with `status = open` are returned.
6. **Given** a page size of 20 and 50 tickets match the query, **When** the first page is fetched, **Then** the response includes 20 tickets and a `next_cursor` value; the next page returns the subsequent 20.

**API**: `GET /v1/support/tickets`, `GET /v1/support/tickets/{id}`

**DB Entities**: SupportTicket, TicketNote

---

### User Story 3 — Assign Support Request (US-SUPPORT-003, Priority: P1)

A support manager or senior support agent assigns a ticket to a specific support agent. Assignment changes the ticket's `assignee_id` and transitions the status from `open` to `in_progress`. A note is auto-appended recording who assigned the ticket to whom. Only support agents with the `support.assign` permission may assign tickets.

**Why this priority**: Unassigned tickets cannot be systematically progressed; assignment is the prerequisite for in-progress investigation.

**Independent Test**: A support manager PATCHes `/v1/support/tickets/{id}/assign` with `assignee_id`; the ticket's `status` transitions to `in_progress` and `assignee_id` is set; an internal note "Assigned to [agent name] by [manager name]" is created; a `SupportTicketAssigned` audit event is emitted.

**Acceptance Scenarios**:
1. **Given** a ticket with `status = open` and no assignee, **When** a support manager PATCHes `/v1/support/tickets/{id}/assign` with a valid `assignee_id`, **Then** `assignee_id` is set, `status` transitions to `in_progress`, an internal note records the assignment, and a `SupportTicketAssigned` event is emitted.
2. **Given** a ticket already assigned to agent A, **When** re-assigned to agent B, **Then** `assignee_id` is updated, an internal note records "Re-assigned from [A] to [B]", and a `SupportTicketReassigned` event is emitted.
3. **Given** a ticket with `status = closed`, **When** a support manager attempts to assign it, **Then** a 422 is returned with `TICKET_ALREADY_CLOSED`.
4. **Given** an actor without the `support.assign` permission, **When** they attempt to assign a ticket, **Then** a 403 Forbidden is returned.
5. **Given** an `assignee_id` that does not correspond to a valid support agent, **When** submitted, **Then** a 422 is returned with `INVALID_ASSIGNEE`.

**API**: `PATCH /v1/support/tickets/{id}/assign`

**DB Entities**: SupportTicket, TicketNote

---

### User Story 4 — Update Support Status (US-SUPPORT-004, Priority: P1)

A support agent or reporter updates the lifecycle status of a ticket. The allowed transitions are: `open → in_progress`, `in_progress → resolved`, `resolved → closed`, `resolved → open` (reopened), `closed → open` (reopened). Status transitions are validated against the allowed state machine. Resolved and closed transitions require a resolution note.

**Why this priority**: Status management drives SLA tracking, reporter notification, and case closure.

**Independent Test**: A support agent PATCHes status to `resolved` with a resolution note; the ticket's `status` transitions correctly; a `SupportTicketResolved` event is emitted; the `resolved_at` timestamp is set.

**Acceptance Scenarios**:
1. **Given** a ticket with `status = in_progress`, **When** a support agent PATCHes status to `resolved` with `resolution_note`, **Then** `status = resolved`, `resolved_at = now()`, an internal note is created, and a `SupportTicketResolved` event is emitted.
2. **Given** a ticket with `status = resolved`, **When** the reporter PATCHes status to `open` (reopening), **Then** `status = open`, `resolved_at` is cleared, a note "Reopened by [reporter]" is auto-appended, and a `SupportTicketReopened` event is emitted.
3. **Given** a ticket with `status = open`, **When** a status transition to `closed` is attempted directly (skipping `resolved`), **Then** a 422 is returned with `INVALID_STATUS_TRANSITION`.
4. **Given** a resolved or closed transition with no `resolution_note`, **When** submitted, **Then** a 422 is returned with `RESOLUTION_NOTE_REQUIRED`.
5. **Given** an actor who is neither the ticket's assignee, the reporter, nor a support manager, **When** they attempt to update status, **Then** a 403 Forbidden is returned.

**API**: `PATCH /v1/support/tickets/{id}/status`

**DB Entities**: SupportTicket, TicketNote

---

### User Story 5 — Add Support Notes (US-SUPPORT-005, Priority: P1)

Support agents and reporters add notes to a ticket to document progress, request clarification, or provide updates. Notes are either public (visible to the reporter) or internal (visible only to support agents). The reporter is notified (via E17 Notifications) when a public note is added by a support agent.

**Why this priority**: Notes are the primary communication channel within a ticket; without them, investigations have no traceable history.

**Independent Test**: A support agent adds an internal note to a ticket; the note is stored with `is_internal = true`; the reporter's GET request for ticket detail does not include the internal note; adding a public note triggers an E17 notification to the reporter.

**Acceptance Scenarios**:
1. **Given** a support agent, **When** they POST `/v1/support/tickets/{id}/notes` with `content` and `is_internal = true`, **Then** a `TicketNote` record is created with `is_internal = true`, `author_id = agent.id`, and the response returns the note ID.
2. **Given** a reporter (customer or agent), **When** they GET `/v1/support/tickets/{id}`, **Then** only notes with `is_internal = false` are included; internal notes are excluded.
3. **Given** a support agent adds a public note (`is_internal = false`), **When** the note is saved, **Then** a `TicketNoteAdded` event is emitted; the E17 notification pipeline dispatches a notification to the reporter (if notification preferences permit).
4. **Given** a reporter adds a note, **When** they POST a note, **Then** the note is saved with `is_internal = false`; reporters cannot set `is_internal = true`.
5. **Given** a ticket with `status = closed`, **When** a note is posted, **Then** a 422 is returned with `TICKET_IS_CLOSED`.
6. **Given** an actor with no relationship to the ticket (not reporter, not assigned support agent, not support manager), **When** they attempt to add a note, **Then** a 403 Forbidden is returned.

**API**: `POST /v1/support/tickets/{id}/notes`

**DB Entities**: TicketNote

---

### User Story 6 — Raise Transaction Dispute (US-DISPUTE-001, Priority: P1)

A customer or agent raises a formal dispute against a specific completed transaction they believe is erroneous, unauthorized, or undelivered. The dispute is linked to the originating transaction ID and optionally to an existing support ticket. A dispute may only be raised for transactions in a terminal state (`completed`, `failed`); pending transactions cannot be disputed. Only one active dispute may exist per transaction.

**Why this priority**: The dispute entry is the root entity for the entire dispute lifecycle — no investigation, evidence, or resolution can proceed without it.

**Independent Test**: A customer POSTs `/v1/disputes` with a valid `transaction_id`; a `Dispute` record is created with `status = raised`, linked to the transaction; a `DisputeRaised` audit event is emitted; a second dispute for the same transaction is rejected with `DISPUTE_ALREADY_EXISTS`.

**Acceptance Scenarios**:
1. **Given** a completed transaction belonging to the customer, **When** the customer POSTs `/v1/disputes` with `transaction_id` and `description`, **Then** a `Dispute` record is created with `status = raised`, `reporter_id = customer.id`, a dispute reference number, and the response returns the dispute ID and reference.
2. **Given** an agent, **When** they raise a dispute for a transaction on their float account, **Then** the dispute is created with `reporter_type = agent`.
3. **Given** a transaction in `pending` status, **When** a dispute is raised, **Then** a 422 is returned with `TRANSACTION_NOT_DISPUTABLE` — only terminal-state transactions may be disputed.
4. **Given** an active dispute already exists for the same `transaction_id`, **When** a second dispute is submitted, **Then** a 409 Conflict is returned with `DISPUTE_ALREADY_EXISTS`.
5. **Given** a `transaction_id` that does not belong to the authenticated user, **When** they attempt to raise a dispute, **Then** a 403 Forbidden is returned.
6. **Given** the same request is submitted twice with the same `Idempotency-Key`, **When** the second request arrives, **Then** the original dispute is returned without creating a duplicate.
7. **Given** a dispute is raised successfully, **When** the event bus processes the result, **Then** a `DisputeRaised` event is emitted containing `dispute_id`, `transaction_id`, `reporter_id`, `occurred_at`, `correlation_id`.

**API**: `POST /v1/disputes`

**DB Entities**: Dispute

---

### User Story 7 — Select Dispute Reason (US-DISPUTE-002, Priority: P1)

After raising a dispute, the reporter selects or updates the reason code that best describes their claim. Reason codes are a platform-configurable enum: `unauthorized_transaction`, `incorrect_amount`, `transaction_not_received`, `duplicate_transaction`, `service_not_delivered`, `technical_error`, `other`. A dispute may have its reason updated until it enters `under_investigation` status.

**Why this priority**: The reason code drives investigation priority, routing, and SLA classification; it must be captured before investigation begins.

**Independent Test**: A customer PATCHes `/v1/disputes/{id}/reason` with `reason_code = unauthorized_transaction`; the dispute's `reason_code` is updated; an audit event records the change; a dispute under investigation rejects the update.

**Acceptance Scenarios**:
1. **Given** a dispute with `status = raised`, **When** the reporter PATCHes `/v1/disputes/{id}/reason` with a valid `reason_code`, **Then** the dispute's `reason_code` is set and a `DisputeReasonSelected` audit event is emitted.
2. **Given** a dispute with `status = evidence_submitted`, **When** the reporter PATCHes the reason, **Then** the reason is updated (status permits it); a `DisputeReasonUpdated` event is emitted.
3. **Given** a dispute with `status = under_investigation`, **When** a reason update is attempted, **Then** a 422 is returned with `DISPUTE_REASON_LOCKED` — reason cannot change once investigation starts.
4. **Given** an invalid `reason_code` value, **When** submitted, **Then** a 422 is returned with `INVALID_REASON_CODE`.
5. **Given** an actor who is not the dispute reporter, **When** they attempt to update the reason, **Then** a 403 Forbidden is returned.

**API**: `PATCH /v1/disputes/{id}/reason`

**DB Entities**: Dispute

---

### User Story 8 — Attach Evidence (US-DISPUTE-003, Priority: P1)

The reporter (customer or agent) attaches supporting files to a dispute. Accepted types are images (JPEG, PNG, WebP) and PDFs, each up to 10 MB, with a maximum of 5 attachments per dispute. Evidence may be attached while the dispute is in `raised` or `evidence_submitted` status; once under investigation, no new evidence may be added without explicit support agent unlock. Attaching at least one piece of evidence transitions the dispute to `evidence_submitted`.

**Why this priority**: Evidence is the prerequisite for investigation; without it, the majority of disputes cannot be evaluated.

**Independent Test**: A customer POSTs a PNG file to `/v1/disputes/{id}/evidence`; a `DisputeEvidence` record is created; the dispute status transitions from `raised` to `evidence_submitted`; a sixth attachment is rejected with `EVIDENCE_LIMIT_REACHED`.

**Acceptance Scenarios**:
1. **Given** a dispute with `status = raised` and no evidence, **When** the reporter POSTs a valid PNG to `/v1/disputes/{id}/evidence`, **Then** a `DisputeEvidence` record is created, the dispute `status` transitions to `evidence_submitted`, and a `DisputeEvidenceAttached` event is emitted.
2. **Given** 5 evidence attachments already exist, **When** a sixth is submitted, **Then** a 422 is returned with `EVIDENCE_LIMIT_REACHED`.
3. **Given** a file exceeding 10 MB, **When** submitted, **Then** a 422 is returned with `FILE_TOO_LARGE`.
4. **Given** a file type not in the allowed list (e.g., `.exe`), **When** submitted, **Then** a 422 is returned with `UNSUPPORTED_FILE_TYPE`.
5. **Given** a dispute with `status = under_investigation`, **When** the reporter attempts to attach evidence, **Then** a 422 is returned with `EVIDENCE_LOCKED` — a support agent must explicitly unlock evidence submission.
6. **Given** an actor who is not the dispute reporter, **When** they attempt to attach evidence, **Then** a 403 Forbidden is returned.
7. **Given** the same file is submitted twice with the same `Idempotency-Key`, **When** the second request arrives, **Then** no duplicate evidence record is created.

**API**: `POST /v1/disputes/{id}/evidence` (multipart/form-data)

**DB Entities**: DisputeEvidence

---

### User Story 9 — Investigate Dispute (US-DISPUTE-004, Priority: P1)

A support agent formally opens an investigation on a dispute, transitioning it from `evidence_submitted` (or `raised`) to `under_investigation`. The agent reviews the linked transaction, evidence files, and notes. The agent can add internal investigation notes, review the reporter's transaction history, and flag the dispute for escalation or resolution. Only users with `support.investigate` permission may open an investigation.

**Why this priority**: The investigation state gates the decision stories (approve/reject) — it must precede them and locks the dispute from further reporter modification.

**Independent Test**: A support agent PATCHes `/v1/disputes/{id}/investigate`; the dispute transitions to `under_investigation`; the reporter can no longer modify the reason or attach evidence; a `DisputeInvestigationStarted` audit event is emitted.

**Acceptance Scenarios**:
1. **Given** a dispute with `status = evidence_submitted`, **When** a support agent PATCHes `/v1/disputes/{id}/investigate`, **Then** the dispute transitions to `under_investigation`, `investigator_id = agent.id`, `investigation_started_at = now()`, and a `DisputeInvestigationStarted` audit event is emitted.
2. **Given** a dispute with `status = raised` (no evidence attached), **When** a support agent opens investigation, **Then** the status transitions to `under_investigation` (evidence is optional for admin-initiated investigations).
3. **Given** a dispute already in `under_investigation`, **When** a second agent attempts to open investigation, **Then** a 409 is returned with `DISPUTE_ALREADY_UNDER_INVESTIGATION`.
4. **Given** an actor without `support.investigate` permission, **When** they attempt to open investigation, **Then** a 403 Forbidden is returned.
5. **Given** a dispute under investigation, **When** a support agent adds an internal investigation note via the notes endpoint, **Then** the note is stored with `is_internal = true` and linked to the dispute's investigation log.

**API**: `PATCH /v1/disputes/{id}/investigate`

**DB Entities**: Dispute

---

### User Story 10 — Approve Dispute (US-DISPUTE-005, Priority: P1)

An operations admin approves a dispute that is under investigation. Approval marks the dispute as `approved`, records the approver's ID and resolution notes, and prepares the case for the refund step (US-DISPUTE-007). Where maker-checker is configured for the `dispute.approve` action, the approval must be confirmed by a second privileged admin before it takes effect. Approval does not automatically trigger a refund — the refund is a separate explicit action.

**Why this priority**: Approval is a privileged financial decision that gates the refund; it requires maker-checker control and an audit trail.

**Independent Test**: An operations admin PATCHes `/v1/disputes/{id}/approve` with resolution notes; the dispute transitions to `approved`; `approved_by` and `approved_at` are set; a `DisputeApproved` audit event is emitted; an actor without `dispute.approve` permission receives a 403.

**Acceptance Scenarios**:
1. **Given** a dispute with `status = under_investigation`, **When** an operations admin PATCHes `/v1/disputes/{id}/approve` with `resolution_notes`, **Then** `status = approved`, `approved_by = admin.id`, `approved_at = now()`, and a `DisputeApproved` audit event is emitted.
2. **Given** maker-checker is enabled for `dispute.approve`, **When** the first admin approves, **Then** the dispute enters `pending_confirmation` status; a second admin must confirm before `approved` is set.
3. **Given** an actor without `dispute.approve` permission, **When** they attempt approval, **Then** a 403 Forbidden is returned.
4. **Given** a dispute with `status = rejected`, **When** an approval is attempted, **Then** a 422 is returned with `INVALID_STATUS_TRANSITION`.
5. **Given** a dispute in `approved` status, **When** a duplicate approval request arrives with the same `Idempotency-Key`, **Then** the existing `approved` state is returned without any state mutation.
6. **Given** resolution notes are absent, **When** submitted, **Then** a 422 is returned with `RESOLUTION_NOTES_REQUIRED`.

**API**: `PATCH /v1/disputes/{id}/approve`

**DB Entities**: Dispute

---

### User Story 11 — Reject Dispute (US-DISPUTE-006, Priority: P1)

An operations admin rejects a dispute that is under investigation. Rejection marks the dispute as `rejected`, records the rejecting admin's ID, rejection reason code, and rejection notes. The reporter is notified of the rejection outcome via E17 Notifications. Rejected disputes cannot be directly re-opened, but the reporter may create a new escalation support ticket.

**Why this priority**: Rejection is the terminal negative outcome for the dispute lifecycle and must be clearly communicated to the reporter.

**Independent Test**: An operations admin PATCHes `/v1/disputes/{id}/reject` with a rejection reason and notes; the dispute transitions to `rejected`; `rejected_by`, `rejected_at`, and `rejection_reason` are set; a `DisputeRejected` audit event and a reporter notification are emitted.

**Acceptance Scenarios**:
1. **Given** a dispute with `status = under_investigation`, **When** an admin PATCHes `/v1/disputes/{id}/reject` with `rejection_reason` and `rejection_notes`, **Then** `status = rejected`, `rejected_by = admin.id`, `rejected_at = now()`, and a `DisputeRejected` audit event is emitted.
2. **Given** a dispute is rejected, **When** the event is processed, **Then** a `DisputeRejected` integration event triggers an E17 notification to the reporter with the rejection reason visible to them (internal notes excluded).
3. **Given** an actor without `dispute.reject` permission, **When** they attempt rejection, **Then** a 403 Forbidden is returned.
4. **Given** a dispute in `approved` status, **When** a rejection is attempted, **Then** a 422 is returned with `INVALID_STATUS_TRANSITION`.
5. **Given** rejection notes are absent, **When** submitted, **Then** a 422 is returned with `REJECTION_NOTES_REQUIRED`.
6. **Given** a valid `Idempotency-Key` is provided and the rejection has already completed, **When** the same request is resubmitted, **Then** the existing `rejected` state is returned without re-triggering the notification.

**API**: `PATCH /v1/disputes/{id}/reject`

**DB Entities**: Dispute

---

### User Story 12 — Refund Disputed Transaction (US-DISPUTE-007, Priority: P1)

An operations admin executes the financial refund for an approved dispute. The refund amount defaults to the original disputed transaction amount but may be set to a partial amount with explicit justification. The refund is posted as a compensating double-entry journal through the E07 Ledger: debit the `liability:disputes:{currency}` account and credit the reporter's wallet. The `Dispute` record transitions to `refunded` and a `Fee` reversal is triggered if the original transaction incurred a fee (via E21 `FeeModuleContract::reverseFee`). The entire operation is atomic and idempotent.

**Why this priority**: The refund is the financial resolution step — it directly affects wallet balances and must meet full ledger integrity and idempotency requirements.

**Independent Test**: An admin POSTs `/v1/disputes/{id}/refund` with the default amount; a balanced journal entry (debit `liability:disputes:BWP`, credit `wallet:{customer_id}`) is posted via E07; the `Dispute` transitions to `refunded`; `dispute_refunded_at` is set; a `DisputeRefunded` audit event is emitted; submitting the same request again with the same `Idempotency-Key` returns the original response without double-posting.

**Acceptance Scenarios**:
1. **Given** a dispute with `status = approved`, **When** an admin POSTs `/v1/disputes/{id}/refund` with no amount override, **Then** the full disputed transaction amount is refunded: a journal entry is posted (debit `liability:disputes:{currency}`, credit `wallet:{reporter_id}`), `status = refunded`, `refunded_amount_minor` is set, and a `DisputeRefunded` audit event is emitted.
2. **Given** a partial refund amount less than the original transaction amount is specified with `justification`, **When** submitted, **Then** only the specified amount is credited; `refunded_amount_minor` records the partial amount; `partial_refund_justification` is stored.
3. **Given** a partial refund with no `justification`, **When** submitted, **Then** a 422 is returned with `PARTIAL_REFUND_JUSTIFICATION_REQUIRED`.
4. **Given** a dispute with `status = rejected`, **When** a refund is attempted, **Then** a 422 is returned with `DISPUTE_NOT_APPROVED`.
5. **Given** the same `Idempotency-Key` is presented for a refund that has already been posted, **When** the duplicate request arrives, **Then** the existing `refunded` state is returned; no duplicate journal entry is created (UNIQUE constraint on `disputes.refund_idempotency_key`).
6. **Given** the E07 ledger post fails (e.g., insufficient liability account balance), **When** the failure occurs, **Then** the dispute status does NOT transition to `refunded`; the entire operation rolls back; no partial state is persisted.
7. **Given** the original disputed transaction had a fee posted by E21, **When** the refund completes, **Then** `FeeModuleContract::reverseFee(transactionId)` is called, posting a compensating fee reversal journal entry; the `Fee` record transitions to `status = reversed`.
8. **Given** maker-checker is configured for `dispute.refund`, **When** the first admin submits the refund, **Then** the refund enters `pending_confirmation`; a second admin must confirm before the ledger posting occurs.

**API**: `POST /v1/disputes/{id}/refund`

**DB Entities**: Dispute

---

### User Story 13 — Escalate Dispute (US-DISPUTE-008, Priority: P1)

A reporter or support agent escalates an unresolved dispute to a senior support tier or management. Escalation is allowed from `raised`, `evidence_submitted`, or `under_investigation` states. Escalation sets a flag on the dispute, records the escalation reason and timestamp, increases the dispute priority, and triggers an SLA-breach alert or notification to the assigned escalation queue. An escalated dispute follows the same approval/rejection/refund workflow but with elevated urgency.

**Why this priority**: Escalation is the safety valve for disputes that are not being progressed; without it, reporters have no recourse against stalled cases.

**Independent Test**: A reporter PATCHes `/v1/disputes/{id}/escalate` with a reason; `escalated = true`, `escalated_at = now()`, `escalated_reason` are set; a `DisputeEscalated` audit event is emitted; the escalation notification fires to the escalation queue; a dispute already in a terminal state rejects the escalation.

**Acceptance Scenarios**:
1. **Given** a dispute with `status = evidence_submitted`, **When** the reporter PATCHes `/v1/disputes/{id}/escalate` with `escalation_reason`, **Then** `escalated = true`, `escalated_at = now()`, `escalation_reason` is stored, and a `DisputeEscalated` audit event is emitted.
2. **Given** a support agent escalates a dispute under investigation, **When** they PATCH `/v1/disputes/{id}/escalate`, **Then** the escalation is recorded; the dispute remains in `under_investigation` but the priority flag is elevated; E17 triggers a notification to the escalation management queue.
3. **Given** a dispute with `status = approved`, `rejected`, or `refunded`, **When** escalation is attempted, **Then** a 422 is returned with `DISPUTE_ALREADY_RESOLVED`.
4. **Given** an actor who is neither the reporter, the ticket's assigned support agent, nor a support manager, **When** they attempt to escalate, **Then** a 403 Forbidden is returned.
5. **Given** `escalation_reason` is absent, **When** submitted, **Then** a 422 is returned with `ESCALATION_REASON_REQUIRED`.
6. **Given** a dispute is already escalated (`escalated = true`), **When** escalation is attempted again, **Then** a 409 is returned with `DISPUTE_ALREADY_ESCALATED`.

**API**: `PATCH /v1/disputes/{id}/escalate`

**DB Entities**: Dispute

---

### Edge Cases

- A support ticket linked to an active dispute cannot be closed until the dispute reaches a terminal state (`approved`, `rejected`, `refunded`).
- A transaction with a disputed status does not prevent the account holder from performing other transactions, unless a separate freeze is applied by E18 (Fraud/Risk).
- A partially-refunded dispute may not receive an additional refund posting — the dispute is terminal once `status = refunded`.
- Evidence files are stored server-side and must not be publicly accessible URLs; access requires an authenticated signed URL with a short TTL.
- Dispute reason `other` requires a free-text description of at least 20 characters.
- If a ticket's reporter account is deactivated, the ticket remains open and is managed by support agents; no new notes from that reporter are accepted.

---

## Data Model

### SupportTicket
| Field | Type | Notes |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `ticket_number` | varchar(20) | Human-readable sequential reference e.g. `TKT-000001`; unique |
| `subject` | varchar(255) | Required |
| `description` | text | Required |
| `category` | enum | `account`, `transaction`, `kyc`, `float`, `commission`, `technical`, `general` |
| `priority` | enum | `low`, `medium`, `high`, `critical`; default `medium` |
| `status` | enum | `open`, `in_progress`, `resolved`, `closed` |
| `reporter_id` | UUID | FK to user (customer or agent) |
| `reporter_type` | enum | `customer`, `agent` |
| `assignee_id` | UUID | Nullable FK to admin user (support agent) |
| `dispute_id` | UUID | Nullable FK to Dispute if the ticket is linked to a dispute |
| `resolution_note` | text | Nullable; required on resolve/close transitions |
| `resolved_at` | timestamptz | Nullable |
| `closed_at` | timestamptz | Nullable |
| `idempotency_key` | varchar(255) | Unique; for create idempotency |
| `created_at`, `updated_at` | timestamptz | |

### TicketNote
| Field | Type | Notes |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `ticket_id` | UUID | FK to SupportTicket |
| `author_id` | UUID | FK to user (customer, agent, or admin) |
| `author_type` | enum | `customer`, `agent`, `admin` |
| `content` | text | Required |
| `is_internal` | boolean | `true` = visible only to support agents; `false` = visible to reporter; default `false` |
| `created_at` | timestamptz | |

### Dispute
| Field | Type | Notes |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `dispute_reference` | varchar(20) | Human-readable sequential reference e.g. `DSP-000001`; unique |
| `transaction_id` | UUID | FK to originating transaction; UNIQUE (one active dispute per transaction) |
| `ticket_id` | UUID | Nullable FK to SupportTicket |
| `reporter_id` | UUID | FK to user (customer or agent) |
| `reporter_type` | enum | `customer`, `agent` |
| `reason_code` | enum | `unauthorized_transaction`, `incorrect_amount`, `transaction_not_received`, `duplicate_transaction`, `service_not_delivered`, `technical_error`, `other` |
| `reason_description` | text | Nullable; required when `reason_code = other` |
| `description` | text | Reporter description of the dispute |
| `status` | enum | `raised`, `evidence_submitted`, `under_investigation`, `pending_confirmation`, `approved`, `rejected`, `refunded` |
| `escalated` | boolean | Default `false` |
| `escalated_at` | timestamptz | Nullable |
| `escalation_reason` | text | Nullable |
| `investigator_id` | UUID | Nullable FK to admin user |
| `investigation_started_at` | timestamptz | Nullable |
| `approved_by` | UUID | Nullable FK to admin user |
| `approved_at` | timestamptz | Nullable |
| `rejected_by` | UUID | Nullable FK to admin user |
| `rejected_at` | timestamptz | Nullable |
| `rejection_reason` | varchar(100) | Nullable; required on rejection |
| `rejection_notes` | text | Nullable |
| `resolution_notes` | text | Nullable |
| `refunded_amount_minor` | bigint | Nullable; amount refunded in minor units |
| `partial_refund_justification` | text | Nullable; required if refunded_amount_minor < original transaction amount |
| `refund_ledger_entry_id` | UUID | Nullable FK to LedgerEntry (E07) |
| `refund_idempotency_key` | varchar(255) | Unique; prevents duplicate refund posting |
| `dispute_refunded_at` | timestamptz | Nullable |
| `created_at`, `updated_at` | timestamptz | |

Unique partial index: `(transaction_id)` WHERE `status NOT IN ('rejected', 'refunded')` — one active dispute per transaction.

### DisputeEvidence
| Field | Type | Notes |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `dispute_id` | UUID | FK to Dispute |
| `uploaded_by` | UUID | FK to reporter (customer or agent) |
| `file_key` | varchar(500) | Server-side storage key; not a public URL |
| `file_type` | enum | `image_jpeg`, `image_png`, `image_webp`, `pdf` |
| `original_filename` | varchar(255) | |
| `size_bytes` | bigint | File size; max 10,485,760 (10 MB) |
| `idempotency_key` | varchar(255) | Unique; prevents duplicate uploads |
| `created_at` | timestamptz | |

---

## Non-Functional Requirements

- **Idempotency**: `POST /v1/support/tickets`, `POST /v1/disputes`, `POST /v1/disputes/{id}/evidence`, `POST /v1/disputes/{id}/refund` require `Idempotency-Key` header (Constitution P8).
- **Financial Atomicity**: Dispute refund ledger posting (US-DISPUTE-007) must be atomic; partial state (dispute marked refunded without ledger entry, or ledger entry without dispute record) is prohibited (Constitution P4).
- **Audit**: All support ticket and dispute state transitions emit audit events with actor, timestamp, and correlation ID (Constitution P13). Privileged dispute approval, rejection, and refund events are additionally written to the platform audit log.
- **Authorization**: Support ticket access is scoped to the reporter (own tickets only) and support agents (all tickets). Dispute access is scoped to the reporter and support agents. Approval, rejection, and refund require `dispute.approve`, `dispute.reject`, and `dispute.refund` permissions respectively (Constitution P5).
- **Evidence storage**: Evidence file keys must not be served as direct public URLs; a signed URL endpoint with a short TTL (≤ 15 minutes) must be used for retrieval.
- **Event reliability**: `DisputeRaised`, `DisputeApproved`, `DisputeRejected`, `DisputeRefunded`, and `SupportTicketCreated` events use the outbox pattern for reliable downstream delivery (Constitution P9).
- **Notifications**: `DisputeRejected` and public ticket note addition trigger E17 notifications to the reporter (Constitution P10).
- **Performance**: List endpoints (`GET /v1/support/tickets`, `GET /v1/disputes`) must return first-page results within 500ms at the 95th percentile.

---

## Dependencies

| Epic | Dependency |
|---|---|
| E04 | `support.create`, `support.assign`, `support.investigate`, `support.resolve`, `dispute.raise`, `dispute.approve`, `dispute.reject`, `dispute.refund`, `dispute.escalate` permissions |
| E07 | `LedgerModuleContract::postEntry(entry_type='dispute_refund')` for refund posting; `JournalEntryType::DisputeRefund` enum |
| E11 | Transaction status and amount lookup for dispute eligibility and refund amount validation |
| E17 | `NotificationModuleContract` for dispute rejection and ticket note notifications to reporters |
| E21 | `FeeModuleContract::reverseFee(transactionId)` called when refunded transaction had an E21 fee posted |
| E18 | E18 Fraud/Risk may place a hold that affects disputable transaction eligibility |
| E01 | Bearer token authentication for all endpoints |

---

## Constitution Check

| Principle | Status | Notes |
|---|---|---|
| P2 — TBD Governance | PASS | No business values are hard-coded; SLA thresholds, escalation tiers, and evidence limits are configurable via E28 Configuration Management |
| P3 — Configuration Over Hard-Coding | PASS | Evidence count limits, refund approval thresholds, and maker-checker toggles are admin-configurable |
| P4 — Financial Integrity | PASS | US-DISPUTE-007 uses E07 LedgerModuleContract double-entry; refund is atomic with status transition; UNIQUE constraint on `disputes.refund_idempotency_key` prevents duplicate posting; partial posting is prohibited |
| P5 — Identity and Authorization | PASS | All dispute decision endpoints require privileged `dispute.approve` / `dispute.reject` / `dispute.refund` permissions; maker-checker workflow enforced where configured |
| P8 — API Contracts | PASS | State-changing endpoints require `Idempotency-Key`; responses use standard envelope; UUID v7 identifiers; versioned `/v1` contracts |
| P9 — Event Reliability | PASS | `DisputeRaised`, `DisputeApproved`, `DisputeRejected`, `DisputeRefunded` use outbox pattern for reliable integration event delivery |
| P12 — Test-First Quality | PASS | Each story defines acceptance criteria, independent test, and multiple Given/When/Then scenarios |
| P13 — Observability | PASS | All state transitions emit audit events; privileged financial actions additionally logged to platform audit trail; correlation IDs on all events |
| P14 — No Silent Architecture Changes | PASS | Uses approved stack (Laravel/PostgreSQL/Redis); uses E07 LedgerModuleContract and E21 FeeModuleContract as designed |

---

## Epic-level Definition of Done

- All 13 stories implemented and passing their acceptance criteria.
- Support ticket state machine transitions (`open → in_progress → resolved → closed`, and reopen) are enforced and tested.
- Dispute state machine transitions (`raised → evidence_submitted → under_investigation → approved/rejected → refunded`) are enforced; invalid transitions are rejected.
- Dispute refund (US-DISPUTE-007) posts a balanced double-entry journal via E07; atomic with dispute status transition; duplicate post prevention tested.
- E21 `FeeModuleContract::reverseFee` is called for refunded transactions that had a fee; fee reversal is idempotent.
- Evidence upload is limited to 5 files per dispute, 10 MB each; invalid types and sizes are rejected.
- Maker-checker workflow for dispute approval and refund is configurable and tested.
- All privileged operations (approve, reject, refund) enforce `dispute.*` permissions; unauthorized attempts receive 403.
- Reporters only see their own tickets and disputes; cross-reporter access returns 403.
- All `Idempotency-Key` flows tested: create ticket, raise dispute, attach evidence, refund.
- `DisputeRejected` and public ticket note events trigger E17 notifications.
- Evidence files are served only via signed URLs; direct file key access returns 403.
- OpenAPI documentation covers all 13 story endpoints.
- PHPStan level-6 clean.
- All tests passing in CI.
