# ADM-P1-018 — Fraud, Risk & AML Dashboard
Source: approved docs/admin-p1p2-epic-catalogue.md ADM-P1-018. All four stories mandatory. User authorized Codex + Spec Kit 2026-10-14.

## User stories and acceptance criteria
### US1 / US-P1-018-001 (P1)
AC1: With security.alerts.read, /security/dashboard shows Severity, Type, Entity, Created, Assigned To.
AC2: Filter Critical/High/Medium/Low and Open/In Review/Resolved.
### US2 / US-P1-018-002 (P1)
AC1: Clicking alert shows trigger details, affected transactions, entity history, risk score timeline.
AC2: Assign to self, add investigation notes, link related alerts.
AC3: Resolve false positive/confirmed fraud or escalate.
### US3 / US-P1-018-003 (P1)
AC1: Freeze Account from investigation creates real hold, emits ACCOUNT_FROZEN_AML and audit.
AC2: User notification if configured.
### US4 / US-P1-018-004 (P1)
AC1: With security.findings.read, /security/findings shows CVE ID if applicable, Severity, Component, Status, Discovered.
AC2: Mark Acknowledged, In Remediation, Resolved.

## Functional requirements
FR-001: Permissioned server-side reads and mutations; preserve authentication and MFA.
FR-002: Persistent, audited investigation actions and real evidence; never fabricate absent data.
FR-003: Preserve legacy risk APIs, findings suppression/counts, maker-checker, ledger and business behavior.
FR-004: Independent authenticated checker approves freeze; caller-entered UUID is not approval. Validate inputs, stale versions, terminal alerts and duplicates.
FR-005: Durable/idempotent security events and configured notifications. No wallet balance or ledger mutation.
FR-006: Accessible keyboard/labels, loading, error/retry, empty and pagination states.

## Clarification review — 2026-10-14
No critical business ambiguity. Approved intent unchanged. In Review maps to under_investigation, Resolved maps to terminal resolution states. Missing evidence shown as empty. Notification channels follow existing configuration. Draft assumptions about completed Admin APIs, invented timings and coverage targets removed. No new policy thresholds or broader rule management outside the four stories.

## Success criteria
All mandatory ACs demonstrated by automated tests and browser inspection; permission denial and durable actions tested; auth/MFA/risk/findings regressions checked. Any mandatory gap yields PARTIAL/BLOCKED.

## Dependencies and edge cases
E18 Risk, E26 Security, E17 Notifications, E19/E33 Admin, ADM-P1-009 transaction navigation. Reject self/duplicate links, stale decisions, forged checker/subject, repeat approvals. Serialize per alert. Preserve existing suppression and immutable financial data.
