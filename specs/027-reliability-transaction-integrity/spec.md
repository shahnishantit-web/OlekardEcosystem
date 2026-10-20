# Feature Specification: E27 — Reliability & Transaction Integrity

**Feature Branch**: `027-reliability-transaction-integrity`

**Created**: 2026-10-02

**Status**: Tasks Ready

**Input**: User description: "E27 reliability-transaction-integrity"

## User Scenarios & Testing *(mandatory)*

<!--
  E27 is a hardening epic: the platform already has partial idempotency, retry, and
  correlation-ID infrastructure from earlier epics. This spec defines what "complete"
  looks like — every gap filled, every financial endpoint covered, operators able to
  inspect and recover from any failure.

  Original story IDs preserved in parentheses for traceability.
-->

### User Story 1 — Complete Financial Idempotency & Duplicate Prevention (Priority: P1)

As a **customer** and **financial system**, I want every state-changing financial request to be replay-safe and every inbound payment callback to be deduplicated, so that network retries, mobile app re-submissions, and provider double-sends can never produce a duplicate charge or credit on any wallet.

*Covers: US-RELIABILITY-001 (idempotency), US-RELIABILITY-002 (duplicate prevention), US-RELIABILITY-010 (duplicate callback)*

**Why this priority**: Duplicate financial transactions are the highest-severity correctness failure — they directly harm customers and violate financial regulatory requirements.

**Independent Test**: Submit the same funding request twice with an identical Idempotency-Key; confirm the second call returns the original response and no additional ledger entry is created. Replay a provider callback and confirm no second credit is applied.

**Acceptance Scenarios**:

1. **Given** a state-changing financial API request with an `Idempotency-Key` header, **When** the same key is submitted a second time with the same payload, **Then** the system returns the original response (HTTP 200) and creates no additional ledger entries.
2. **Given** a state-changing financial API request submitted with no `Idempotency-Key`, **When** the request reaches the API, **Then** it is rejected with HTTP 422 and a clear error message requiring the header.
3. **Given** an inbound payment provider callback carrying a provider reference ID, **When** an identical callback arrives a second time (provider retry or duplicate delivery), **Then** the second callback is acknowledged without re-executing the credit or debit.
4. **Given** an Idempotency-Key that was used more than the configured TTL period ago, **When** it is resubmitted, **Then** the system treats it as a new operation (the original record has expired and been purged).
5. **Given** a completed idempotency record, **When** the same key is submitted with a different payload, **Then** the request is rejected with HTTP 409 Conflict.

---

### User Story 2 — Transaction Atomicity & Configurable Timeouts (Priority: P1)

As a **wallet system**, I want all wallet balance changes to execute as all-or-nothing atomic operations bounded by a configurable timeout, so that hardware failures, network partitions, and concurrent operations can never leave a wallet in a partially-updated or permanently-locked state.

*Covers: US-RELIABILITY-003 (transaction timeout), US-RELIABILITY-007 (prevent partial wallet update), US-RELIABILITY-008 (guarantee ledger consistency)*

**Why this priority**: Partial wallet updates are unrecoverable without manual intervention and are a catastrophic data-integrity failure; atomicity is non-negotiable for financial correctness.

**Independent Test**: Inject a simulated failure midway through a ledger posting (after debit posted, before credit posted) and verify the debit is rolled back; confirm no net change to either wallet.

**Acceptance Scenarios**:

1. **Given** a wallet funding operation in progress, **When** a failure occurs after the debit entry is written but before the credit entry is written, **Then** both entries are rolled back and the wallets return to their pre-operation balances.
2. **Given** a financial operation that has not completed within the configured timeout period, **When** the timeout threshold is reached, **Then** the operation is terminated and the transaction is marked for recovery or reversal.
3. **Given** a scheduled ledger integrity scan, **When** it runs, **Then** it verifies that the sum of all debit entries equals the sum of all credit entries for every wallet; any discrepancy produces an alert audit event.
4. **Given** two concurrent transfer requests against the same wallet, **When** processed simultaneously, **Then** both complete correctly with consistent final balances — neither request is silently lost or double-applied.
5. **Given** timeout duration and transaction lock configuration, **When** an administrator updates them, **Then** the new values take effect without a service restart and are applied to all subsequent operations.

---

### User Story 3 — Interrupted Transaction Recovery (Priority: P2)

As a **customer**, I want interrupted transactions (caused by server restarts, network drops, or process crashes) to be automatically detected and safely resolved, so that I never see a charge without a corresponding credit or a credit without a corresponding charge remaining permanently on my account.

*Covers: US-RELIABILITY-004 (retry mechanism), US-RELIABILITY-006 (recover interrupted transaction)*

**Why this priority**: Orphaned transactions damage customer trust and require costly manual remediation; automated recovery is essential before production launch.

**Independent Test**: Simulate a process crash mid-operation and verify the scheduled orphan scanner detects the pending transaction and either completes or reverses it within the configured escalation window.

**Acceptance Scenarios**:

1. **Given** a transaction left in a pending (non-terminal) state after a process failure, **When** the configured orphan detection scan runs, **Then** the transaction is identified as an orphan within one scan interval.
2. **Given** a detected orphan transaction where the underlying operation can be safely confirmed, **When** the recovery process runs, **Then** the transaction is completed and the customer's wallet reflects the correct final balance.
3. **Given** a detected orphan transaction where safe completion is not possible, **When** the recovery process runs, **Then** the transaction is reversed: all associated ledger entries are compensated, the transaction is marked as reversed, and the customer is notified.
4. **Given** a transient external API failure (e.g., payment provider timeout), **When** a financial operation fails, **Then** it is automatically retried up to the configured maximum attempt count with exponential back-off; only after exhausting retries is it moved to the dead-letter store.
5. **Given** the maximum retry attempt count and back-off parameters, **When** an administrator changes them, **Then** the updated values apply to all subsequent retry schedules without service restart.

---

### User Story 4 — Dead-Letter Visibility & Operator Replay (Priority: P2)

As a **platform operator**, I want every failed operation that has exhausted retries to be captured and visible in an administrative interface with full context, and to be able to replay it with a single action, so that no customer funds are silently lost due to processing failures.

*Covers: US-RELIABILITY-005 (dead-letter queue), US-RELIABILITY-009 (detect orphan transaction)*

**Why this priority**: Invisible failures are worse than visible ones; operators must be able to see every stuck operation and act on it before it escalates into a customer complaint or regulatory incident.

**Independent Test**: Force a job to exhaust retries and confirm it appears in the admin dead-letter list with its full payload and failure reason; replay the item and confirm it executes and the item is marked as replayed.

**Acceptance Scenarios**:

1. **Given** an operation that has exhausted all retry attempts, **When** it is moved to the dead-letter store, **Then** it is immediately visible in the admin dead-letter interface with operation type, payload, failure reason, retry history, and timestamp.
2. **Given** a dead-letter item listed in the admin interface, **When** an operator triggers a replay, **Then** the operation re-executes idempotently and the dead-letter item is marked with the replay timestamp and operator identity.
3. **Given** a dead-letter item that was successfully replayed, **When** viewed in the admin interface, **Then** it shows "Replayed" status and can no longer be replayed again (preventing accidental double-replay).
4. **Given** the dead-letter admin list, **When** an operator filters by operation type, date range, or status (pending/replayed/dismissed), **Then** only matching items are displayed.
5. **Given** a replay action, **When** performed by any operator, **Then** an audit record is created with the operator identity, item ID, replay timestamp, and outcome.
6. **Given** a dead-letter item stored beyond the configured retention TTL, **When** the retention cleanup job runs, **Then** the item is purged; replayed items are retained for the standard audit retention period regardless.

---

### User Story 5 — Distributed Correlation ID Propagation (Priority: P3)

As a **developer and operations engineer**, I want every log entry, event, background job, and API response to carry a consistent correlation ID that links all steps of a single business transaction, so that any incident can be traced end-to-end in minutes without manually correlating multiple log streams.

*Covers: US-RELIABILITY-011 (distributed correlation ID)*

**Why this priority**: Correlation IDs are foundational to incident response; incomplete propagation means any production incident investigation is significantly more expensive and time-consuming.

**Independent Test**: Trigger a wallet transfer and verify that the originating HTTP request correlation ID appears in every log line, every emitted domain event, and every background job spawned by that transfer.

**Acceptance Scenarios**:

1. **Given** an inbound API request with an `X-Correlation-ID` header, **When** the request is processed, **Then** all log entries produced during that request carry the same correlation ID.
2. **Given** an inbound API request with no `X-Correlation-ID` header, **When** the request is received, **Then** a new correlation ID is generated and attached to the request context for all downstream log entries.
3. **Given** a background job spawned by a financial operation, **When** the job executes, **Then** its log entries carry the same correlation ID as the originating HTTP request that triggered the job.
4. **Given** any API response, **When** inspected, **Then** an `X-Correlation-ID` header is present carrying the same ID used throughout the request's log entries.
5. **Given** a domain event emitted during a financial operation, **When** the event is processed by a subscriber, **Then** the subscriber's processing logs carry the original correlation ID from the event payload.

---

### Edge Cases

- What if a duplicate Idempotency-Key expires and is reused for a genuinely new, different operation? The system must treat it as a new request — expiry signals the original contract has lapsed.
- How does recovery distinguish an orphan caused by a crash from a transaction intentionally left pending (e.g., awaiting manual approval)? Operations requiring approval must carry an explicit status that the orphan scanner excludes.
- What if a dead-letter item's payload references a user who has since been closed or a wallet that has been suspended? The replay must validate pre-conditions and fail gracefully, not silently apply to an invalid account.
- What if the ledger integrity scan detects a discrepancy that was caused by an in-flight transaction? The scan must treat transactions within the configured "in-flight window" as provisional and not raise a false alert.
- What if two operators attempt to replay the same dead-letter item simultaneously? The system must guarantee only one replay executes; the second attempt must be rejected with a clear message.
- What if the correlation ID exceeds the maximum header size or contains invalid characters? The system must sanitize or replace it rather than reject the request.

## Requirements *(mandatory)*

### Functional Requirements

**Financial Idempotency**

- **FR-001**: Every state-changing financial API endpoint MUST require an `Idempotency-Key` request header; requests missing this header MUST be rejected with HTTP 422 before any domain logic executes.
- **FR-002**: A completed idempotency record MUST store the response status code, response body, actor identity, and an expiry timestamp; duplicate requests within the TTL window MUST return the stored response.
- **FR-003**: Duplicate submissions of the same Idempotency-Key with a different payload MUST be rejected with HTTP 409 Conflict.
- **FR-004**: Idempotency records MUST be retained for a minimum of 24 hours and a maximum of a configurable TTL (default: 7 days); records exceeding TTL MUST be purged by a scheduled cleanup job.
- **FR-005**: Inbound external payment callbacks MUST be deduplicated using the provider-supplied reference identifier; duplicate callbacks MUST be acknowledged (HTTP 200) without re-executing any financial operation.

**Transaction Atomicity & Timeouts**

- **FR-006**: Every wallet balance change MUST execute within a single atomic database transaction; a failure at any step MUST roll back all associated ledger entries and wallet record changes.
- **FR-007**: Financial operations MUST complete within a configurable timeout period (default: 30 seconds); operations exceeding the timeout MUST be terminated and the transaction moved to a recovery-required state.
- **FR-008**: Concurrent financial operations on the same wallet MUST be processed in a mutually exclusive sequence; simultaneous requests on the same wallet MUST NOT produce inconsistent results, and any contention MUST be detected and automatically resolved by retrying one of the operations.
- **FR-009**: A scheduled ledger integrity scan MUST periodically verify that the sum of all debit entries equals the sum of all credit entries for each wallet; any imbalance MUST emit an alert-level audit event identifying the affected wallet(s).

**Interrupted Transaction Recovery**

- **FR-010**: A scheduled orphan detection scan MUST identify financial transactions remaining in a non-terminal state beyond a configurable orphan threshold (default: 5 minutes after expected completion).
- **FR-011**: Detected orphan transactions MUST be automatically escalated: completed if the underlying operation can be safely confirmed idempotently, or reversed via a compensating ledger entry if safe completion is not possible.
- **FR-012**: Failed financial operations MUST be retried automatically using exponential back-off up to a configurable maximum attempt count (default: 3); the retry schedule MUST be recorded with each attempt.
- **FR-013**: Operations that exhaust all retry attempts MUST be moved to the dead-letter store with their full payload, failure reason, and complete retry history.

**Dead-Letter Queue**

- **FR-014**: A dead-letter store MUST capture every operation that exhausted retries; each record MUST include: unique ID, operation type, payload, failure reason, retry count, created_at, replayed_at, replayed_by, status (pending/replayed/dismissed).
- **FR-015**: An administrative interface MUST allow operators holding the `operations.dead_letter.manage` permission to view, filter (by type, date range, status), replay, and dismiss dead-letter items.
- **FR-016**: Replaying a dead-letter item MUST be idempotent; if two operators attempt to replay the same item simultaneously, exactly one replay MUST succeed and the other MUST be rejected with an informative message; the replay action MUST be audit-logged with actor identity, item ID, and outcome.
- **FR-017**: Dead-letter items MUST be retained for a configurable period (default: 30 days); items in replayed or dismissed status MUST be retained for the standard audit log retention period regardless.

**Distributed Correlation ID**

- **FR-018**: Every inbound API request MUST produce a correlation ID: using the `X-Correlation-ID` request header value if provided, otherwise generating a new UUID v7; the ID MUST be attached to the request context for the full lifecycle.
- **FR-019**: Every API response MUST include the `X-Correlation-ID` response header carrying the request's correlation ID.
- **FR-020**: The correlation ID MUST be propagated into every background job, domain event, and log entry produced during the processing of the originating request; it MUST appear as a structured field in all log output.

**Configuration**

- **FR-021**: All configurable thresholds in this epic — idempotency TTL, operation timeout, orphan threshold, retry max attempts, retry backoff parameters, dead-letter TTL — MUST be centrally configurable through the Platform Configuration service without code deployment.

### Key Entities

- **IdempotencyRecord**: A completed financial API request keyed by `Idempotency-Key` — key hash, response status code, response body, actor ID, operation type, created_at, expires_at. Append-only; never mutated after creation.
- **RetrySchedule**: Per-operation-type retry configuration — operation_type, max_attempts, initial_delay_seconds, backoff_multiplier, max_delay_seconds; managed through Platform Configuration.
- **DeadLetterItem**: An operation that exhausted retries — unique ID, operation_type, payload (JSON), failure_reason, retry_count, retry_history (JSON array), created_at, replayed_at (nullable), replayed_by (nullable), dismissed_at (nullable), status (pending/replayed/dismissed).
- **OrphanTransaction**: A financial transaction detected as stuck — links to the source transaction record, pending_since, orphan_detected_at, escalation_deadline, resolution (recovered/reversed/escalated/in_progress).
- **LedgerIntegritySnapshot**: Result of a scheduled consistency scan — scan_at, wallets_checked, discrepancy_count, affected_wallet_ids (JSON), outcome (clean/discrepancy_found).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of state-changing financial API endpoints enforce Idempotency-Key validation; verified by an automated endpoint-coverage scan that maps every POST/PATCH/PUT route to its idempotency check.
- **SC-002**: Zero duplicate financial transactions succeed: re-submitting the same Idempotency-Key returns the original response and creates no additional ledger entries; verified by automated idempotency test scenarios across all financial endpoints.
- **SC-003**: A simulated process crash during a ledger posting leaves zero permanently stuck pending transactions; the orphan scanner detects all orphans within 2× the configured scan interval and resolves each within the escalation window.
- **SC-004**: 100% of dead-letter items are captured and visible in the admin interface; zero items are silently discarded; operator replay is available for 100% of pending dead-letter items.
- **SC-005**: The `X-Correlation-ID` header is present in 100% of API responses; the same correlation ID appears in all log entries produced by the originating request, including background job logs; verified by automated log-sampling assertion.
- **SC-006**: The ledger integrity scan runs at least once per hour; any debit/credit imbalance produces an alert audit event within one scan interval; zero imbalances exist in any seeded test scenario after recovery.
- **SC-007**: All configurable thresholds (idempotency TTL, timeout, retry counts, dead-letter TTL) are readable and writable via the Platform Configuration service and take effect within 60 seconds without a service restart.

## Assumptions

- The platform idempotency service (key storage and lookup) is partially implemented across earlier epics; E27 audits and completes its coverage, ensuring all financial endpoints — funding, transfer, withdrawal, bill payments, merchant payments — consistently enforce the Idempotency-Key requirement.
- All financial operations already use database transactions internally; E27 verifies this coverage exhaustively and enforces any missing transaction boundaries, particularly around timeout handling.
- Background job processing infrastructure (queues, workers, retries) is operational from the platform foundation; E27 adds structured dead-letter capture, admin visibility, and replay — not a new queue system.
- Correlation ID generation and request-lifecycle attachment is already partially present (E25 established the middleware pattern); E27 completes propagation into background jobs and domain events.
- The dead-letter admin interface is a new admin portal section; no existing dead-letter UI exists.
- Ledger integrity scans are new scheduled background jobs not yet present.
- Orphan detection is a new scheduled scan; the existing stuck-processing cleanup job covers outbox events but not financial transactions.
- Retry counts, timeout durations, and dead-letter TTL are currently hard-coded in various services; E27 moves them to the Platform Configuration service.
- Operations requiring explicit manual approval (e.g., large-value transfers pending compliance review) carry a `pending_approval` status that the orphan scanner is configured to exclude.
