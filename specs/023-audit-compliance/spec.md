# Feature Specification: E23 — Audit & Compliance

**Feature Branch**: `023-audit-compliance`

**Created**: 2026-09-28

**Status**: Draft

**Input**: User description: "E23 audit-compliance"

## Overview

The Audit & Compliance module provides the platform-wide audit trail and compliance management capabilities for the OleKard wallet ecosystem. It captures every significant platform event as an immutable, structured record; maintains a compliance status and history for each user; enables compliance officers to generate regulatory reports and Suspicious Activity Reports (SARs); and enforces configurable data retention policies.

This module fulfils the constitution's mandate that privileged, security, and financial actions are audited. All other modules write audit events through the contract exposed by this module. The audit trail is append-only by design — no actor, including administrators, may modify or delete a written record.

## Actors

- **Platform (System)** — The authoritative source of audit events; writes events atomically with the operations they describe.
- **Compliance Officer (Admin)** — Queries audit logs, retrieves compliance status/history, and generates compliance reports and SARs.
- **Operations Admin** — Manages data retention rules; all changes to rules are themselves audited.
- **Security System** — Detects and records security events (brute-force, suspicious login, device revocation).

## User Scenarios & Testing

### User Story 1 — Record Platform Events (Priority: P1)

Every significant action on the platform — authentication, authorization decisions, wallet operations, financial transactions, KYC submissions, administrative changes, system configuration changes, and security incidents — is captured as a structured, timestamped audit event the moment it occurs. The event record identifies who performed the action, what was done, when it happened, and the outcome. Audit events are written automatically at the point of action, within the same transactional boundary as the operation they describe.

**Why this priority**: Audit event recording is the foundational capability of this module. Without it, immutability enforcement, compliance tracking, and reporting are impossible. It maps directly to the constitution's mandate to audit all privileged, security, and financial actions (Principle 5).

**Independent Test**: Can be fully tested by triggering each of the 8 event categories (authentication, authorization, wallet, transaction, KYC, admin, configuration change, security) and verifying that a structured, timestamped record appears in the audit log with the correct actor, action, outcome, and correlation ID.

**Acceptance Scenarios**:

1. **Given** a user successfully authenticates, **When** the authentication completes, **Then** an audit event is recorded containing the actor ID, outcome (success), timestamp, source address, and correlation ID.
2. **Given** a permission check denies access, **When** the authorization decision is made, **Then** an audit event is recorded containing the actor ID, resource, required permission, outcome (denied), and correlation ID.
3. **Given** a wallet operation occurs (creation, status change), **When** the operation completes or fails, **Then** an audit event is recorded with the wallet ID, operation type, actor, outcome, and correlation ID.
4. **Given** a financial transaction occurs (transfer, funding, withdrawal, bill payment), **When** the transaction completes or is rejected, **Then** an audit event is recorded with the transaction ID, amount, currency, type, outcome, and correlation ID.
5. **Given** a KYC workflow step completes (submission, document upload, status change, verification outcome), **When** the step is finalized, **Then** an audit event is recorded with the subject user ID, KYC stage, outcome, actor, and correlation ID.
6. **Given** an administrator performs a privileged action (user status change, dispute approval, role assignment), **When** the action completes, **Then** an audit event is recorded with the admin ID, action type, target entity, before-state summary, and after-state summary.
7. **Given** a system configuration value is changed (fee rate, limit, policy toggle), **When** the change is saved, **Then** an audit event is recorded containing the configuration key, previous value, new value, changed-by actor, and timestamp.
8. **Given** a security event is detected (brute-force lockout, suspicious login, device revocation, failed MFA), **When** the event is identified, **Then** an audit event is recorded with the actor, threat category, severity, and correlation ID.
9. **Given** an actor without write-access permission attempts to record an audit event directly via the API, **When** the request is submitted, **Then** the system returns 403 Forbidden and no audit event is created.

---

### User Story 2 — Enforce Audit Immutability (Priority: P2)

Once an audit event is recorded, its content can never be changed, overwritten, or deleted — not by administrators, not by automated processes, and not by the system itself. The audit trail is append-only. Any attempt to update or delete an existing audit record is rejected with an error, and the attempt itself is recorded as a security event.

**Why this priority**: An audit trail that can be modified is not a reliable audit trail. Immutability is the property that makes records legally defensible and regulatorily compliant. This story enforces the guarantee that all records produced by US1 remain trustworthy.

**Independent Test**: Can be fully tested by recording an audit event, then attempting to update or delete it via any API endpoint, confirming rejection, and verifying a security event was recorded for the attempted tampering.

**Acceptance Scenarios**:

1. **Given** an existing audit event, **When** an actor attempts to update any field of that record, **Then** the system returns an error, the record remains unchanged, and a tamper-attempt security event is recorded.
2. **Given** an existing audit event, **When** an actor with admin privileges attempts to delete the record, **Then** the system returns 403 Forbidden, the record is not deleted, and a tamper-attempt security event is recorded.
3. **Given** any actor (including a system process), **When** they attempt a bulk delete or truncate on audit records, **Then** the operation is blocked at the persistence layer.
4. **Given** a compliance officer retrieves an audit event, **When** the response is returned, **Then** the record includes an integrity verification value over its content, allowing later confirmation that it has not been altered.

---

### User Story 3 — Maintain Compliance Status & History (Priority: P3)

The compliance module maintains a current compliance status for each user — combining their KYC tier, AML risk level, and any active restrictions — and preserves an immutable chronological history of every status change. Compliance officers can retrieve the current status of any user and trace the full timeline of how that status evolved: what changed, when, and what triggered the change.

**Why this priority**: Compliance status governs access to financial services (transaction limits, wallet features). Regulators require a complete audit history of compliance decisions. This story builds on audit event recording (US1) to provide the compliance-specific view.

**Independent Test**: Can be tested by changing a user's KYC status, querying their current compliance status (confirms updated), querying their compliance history (confirms the change event appears in order), and verifying the original status event also appears.

**Acceptance Scenarios**:

1. **Given** a user's KYC level changes (e.g., unverified → tier-1 verified), **When** the change occurs, **Then** the user's compliance status record is updated and a history entry is appended with the new status, timestamp, source system, and triggering event reference.
2. **Given** a compliance officer queries the current compliance status of a user, **When** the request is made with `compliance.read` permission, **Then** the response returns the current KYC tier, AML risk level, active restrictions, and the timestamp of the last status change.
3. **Given** a compliance officer queries the compliance history for a user, **When** the query is made, **Then** a chronological list of all status changes is returned, each entry showing what changed, before and after values, timestamp, and the triggering source.
4. **Given** an actor without `compliance.read` permission queries compliance status, **When** the request is submitted, **Then** the system returns 403 Forbidden and no data is returned.
5. **Given** a compliance officer queries transaction history for compliance purposes, **When** the query is submitted with date range and optional user filters, **Then** a paginated list of compliance-relevant transactions is returned, each with transaction ID, type, amount, currency, date, and outcome.

---

### User Story 4 — Generate Compliance Reports and SARs (Priority: P4)

Compliance officers can generate structured compliance reports covering a specified time period, subject scope (per user, per transaction type, or platform-wide), and report type (audit summary, KYC snapshot, transaction volume). They can also generate Suspicious Activity Reports (SARs) for specific users or transactions that triggered AML alerts, with the SAR containing the required regulatory fields and a summary of flagged activity.

**Why this priority**: Report generation consumes the data collected by US1–US3. It represents the platform's external-facing compliance obligation (regulatory reporting). It requires stable, immutable source data.

**Independent Test**: Can be tested by generating a compliance report for a date range and verifying it contains audit event counts, user statistics, and transaction summaries matching the underlying records; and generating a SAR for a flagged user and verifying it contains all required fields and references to the source audit events.

**Acceptance Scenarios**:

1. **Given** a compliance officer requests a compliance report, **When** they specify report type, date range, and optional filters, **Then** the report is generated, the officer is notified when ready, and the report contains audit event summary, KYC status distribution, and transaction statistics for the period.
2. **Given** a generated compliance report, **When** the officer retrieves it via `GET /v1/compliance/reports/{id}`, **Then** the full `report_data` payload is returned as structured JSON containing audit event counts, KYC distributions, and transaction statistics, with each aggregate referencing the underlying audit event categories. File-based export (CSV, PDF) is a future enhancement out of scope for E23.
3. **Given** a compliance officer generates a SAR for a specific user, **When** the request is submitted with subject user ID and reason, **Then** the SAR is created containing: subject identity, flagged transaction list, AML alert history, risk score timeline, and a narrative summary.
4. **Given** an actor without report-generation permission attempts to generate a report, **When** the request is submitted, **Then** the system returns 403 Forbidden and no report is created.
5. **Given** a report generation request is submitted twice with the same idempotency key, **When** the second request arrives, **Then** the system returns the previously generated report rather than creating a duplicate.

---

### User Story 5 — Manage Data Retention Rules (Priority: P5)

Administrators can define and manage data retention policies that govern how long different categories of audit and compliance data are stored before being archived or purged. Retention rules are configurable per data category (e.g., authentication events: 2 years; financial transaction events: 7 years; KYC events: 10 years). The platform enforces retention policies automatically on a schedule, and any modification to a retention rule is itself audited.

**Why this priority**: Retention policies protect the platform from unbounded storage growth while ensuring minimum legal retention periods are met. Configurable admin-managed retention is required by the constitution (Principle 12).

**Independent Test**: Can be tested by defining a retention rule for a test category, running the enforcement process, verifying records beyond the policy period are archived/purged, and verifying records within the window are retained.

**Acceptance Scenarios**:

1. **Given** an administrator with retention-configuration permission, **When** they create a retention rule specifying category, minimum retention period, and post-retention action (archive or purge), **Then** the rule is saved and a configuration change audit event is recorded.
2. **Given** an existing retention rule, **When** an administrator updates the retention period, **Then** the change is saved with an effective-from date, the previous value is preserved in history, and a configuration change audit event is recorded.
3. **Given** the scheduled retention enforcement process runs, **When** it identifies records exceeding their category's retention period, **Then** records are archived or purged per the rule action, and a summary audit event is recorded for the enforcement run.
4. **Given** a retention rule specifies a minimum legal period (e.g., 7 years for financial events), **When** the enforcement process runs, **Then** no records within that minimum window are purged, regardless of other configuration.
5. **Given** an actor without retention-configuration permission attempts to modify a retention rule, **When** the request is submitted, **Then** the system returns 403 Forbidden and no change occurs.

---

### Edge Cases

- What happens when an audit event write fails (e.g., storage unavailable)? The failed write must be retried via the outbox pattern; the source operation is not rolled back solely because of an audit write failure.
- What happens when compliance status change events arrive out of order? History entries are stored in event-occurrence order (by event timestamp), not arrival order; out-of-order entries are flagged in the history record.
- What happens when a SAR is generated for a user with no AML alerts? The system produces a structured SAR with an explicit "no alerts found" narrative rather than returning an error.
- What happens when a retention rule period is shortened retroactively? Records that would be immediately due for purge under the new rule are not purged until the next scheduled enforcement run, giving administrators a review window.
- What happens when two concurrent processes record audit events for the same correlation ID? Each event gets a unique ID and both are stored; audit events are additive and deduplication is not performed.
- What happens when the audit module itself is unavailable? Source modules must buffer events in the outbox and deliver them when the audit store is restored; no events are silently dropped.

## Requirements

### Functional Requirements

- **FR-001**: The system MUST record a structured audit event for every authentication attempt, capturing actor, outcome, timestamp, source address, and correlation ID.
- **FR-002**: The system MUST record a structured audit event for every authorization decision, capturing actor, resource, required permission, outcome, and correlation ID.
- **FR-003**: The system MUST record a structured audit event for every wallet operation (create, activate, suspend, close), capturing wallet ID, operation type, actor, outcome, and correlation ID.
- **FR-004**: The system MUST record a structured audit event for every financial transaction lifecycle event (initiated, completed, failed, reversed), capturing transaction ID, type, amount, currency, actor, outcome, and correlation ID.
- **FR-005**: The system MUST record a structured audit event for every KYC workflow step (submission, document upload, verification, status change), capturing subject user ID, KYC stage, decision, actor, and correlation ID.
- **FR-006**: The system MUST record a structured audit event for every privileged administrative action, capturing admin actor ID, action type, target entity ID, before-state summary, after-state summary, and correlation ID.
- **FR-007**: The system MUST record a structured audit event for every system configuration change, capturing configuration key, previous value, new value, changed-by actor, and timestamp.
- **FR-008**: The system MUST record a structured audit event for every security event (brute-force lockout, device revocation, suspicious login, failed MFA), capturing actor, threat category, severity, and correlation ID.
- **FR-009**: Audit event records MUST be append-only; no update or delete operation on an existing audit record is permitted through any interface.
- **FR-010**: Any attempt to modify or delete an audit record MUST be rejected and the attempt MUST itself be recorded as a security event.
- **FR-011**: Each audit event MUST carry an integrity verification value over its content, enabling later confirmation that the record has not been altered.
- **FR-012**: The system MUST maintain a current compliance status record for each user, reflecting their KYC tier, AML risk level, and any active restrictions.
- **FR-013**: The system MUST append an immutable compliance history entry each time a user's compliance status changes, preserving before-state, after-state, timestamp, and triggering event reference.
- **FR-014**: The system MUST provide a queryable compliance-oriented view of transaction history, filterable by user, date range, transaction type, and outcome.
- **FR-015**: The system MUST support generating compliance reports covering audit event summaries, KYC status distributions, and transaction statistics for a specified period.
- **FR-016**: The system MUST support generating Suspicious Activity Reports for specified subjects, containing all required regulatory fields and a summary of flagged activity.
- **FR-017**: Compliance report generation MUST be idempotent on a caller-supplied idempotency key.
- **FR-018**: The system MUST allow authorized administrators to define retention rules per audit data category, specifying minimum retention period and post-retention action (archive or purge).
- **FR-019**: The system MUST enforce retention rules automatically on a scheduled basis, archiving or purging records that exceed their category's retention period.
- **FR-020**: Retention enforcement MUST never purge records within a category's minimum retention window, regardless of other configuration.
- **FR-021**: All access to audit logs and compliance data MUST be authorized server-side via role-based permissions (`audit.read`, `compliance.read`, `compliance.report`, `compliance.config`).
- **FR-022**: All modifications to retention rules MUST themselves be recorded as configuration change audit events.

### Key Entities

- **AuditEvent**: A single immutable record of something that happened on the platform — who did what, when, with what outcome, and with what correlation context. Carries an integrity verification value.
- **ComplianceStatus**: A user's current compliance profile — KYC tier, AML risk level, active restrictions, and reference to the last compliance decision.
- **ComplianceHistoryEntry**: An immutable record of one change to a user's compliance status — before-state, after-state, timestamp, and triggering event reference.
- **ComplianceReport**: A generated summary report covering audit and compliance data for a specified period; stored for later retrieval and download.
- **SuspiciousActivityReport (SAR)**: A structured regulatory filing for a specific subject, containing the required fields for submission to a financial intelligence unit.
- **RetentionPolicy**: A configurable rule governing how long records in a specific audit data category are retained before being archived or purged.

## Success Criteria

### Measurable Outcomes

- **SC-001**: The E23 Audit module provides a verified write contract (`AuditModuleContract::record()`) and a read API that returns 100% of events submitted through that contract. Verification that all 8 source event categories are instrumented across E01–E22 is a cross-epic acceptance gate tracked outside this spec; E23's acceptance criterion is that every call to `AuditModuleContract::record()` produces a retrievable, immutable audit event with correct fields.
- **SC-002**: Zero successful modifications or deletions of existing audit records succeed through any interface — enforced at both the application and persistence layer.
- **SC-003**: Current compliance status for any user can be retrieved in under 300 milliseconds under normal platform load.
- **SC-004**: Compliance history for any user returns a complete change timeline with no gaps for all events recorded since module activation.
- **SC-005**: Compliance report generation completes within 60 seconds for reports covering up to 30 days of data.
- **SC-006**: Suspicious Activity Reports contain all fields required by the applicable financial intelligence unit regulatory standard.
- **SC-007**: Data retention enforcement runs on schedule and produces an audit summary confirming records processed — with zero records purged within their minimum retention window.
- **SC-008**: All audit and compliance API endpoints enforce permission checks; actors without the required permission receive a denial response with no data leakage.

## Assumptions

- Audit events are written by the source module at the point of the operation (not by E23 in isolation); E23 exposes the write contract that all other modules call.
- The outbox pattern (established in prior epics) is the delivery mechanism for audit events that must survive source-operation failures.
- The minimum legal retention periods for each data category will be provided by the compliance team and configured via the admin interface; they are not hard-coded in this spec.
- SAR content requirements follow international financial intelligence unit standards; jurisdiction-specific regulatory deviations are a future enhancement out of scope here.
- Compliance report generation is asynchronous for larger date ranges; for short ranges (≤24 hours) synchronous generation is acceptable.
- AML risk scores and alert data fed into SARs originate from E18 (Fraud Risk & AML); E23 reads from that module's output.
- KYC tier data originates from E05 (KYC & Identity Verification); E23 reads from that module's compliance status contract.
- Existing user identity and permission infrastructure (E01, E04) is in place and provides actor resolution for all audit event fields.
- SAR generation (US4) depends on `IdentityModuleContract::getIdentitySnapshot()` being implemented in E01. Until that implementation ships, SARs will be created with `status=incomplete` and `subject_identity_snapshot.status=snapshot_unavailable`. SC-006 cannot be fully met until the E01 implementation is complete.
