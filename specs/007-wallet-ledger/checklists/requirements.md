# Requirements Quality Checklist — E07 Wallet Ledger

This checklist is maintained by `/speckit-specify` and `/speckit-clarify`. Items are checked when the requirement quality criterion is satisfied.

---

## Completeness

- [x] All 14 SRS user stories (US-LEDGER-001 through US-LEDGER-014) are represented with expanded acceptance scenarios
- [x] Each user story has an independent test scenario
- [x] Each user story has at least 4 acceptance scenarios in Given/When/Then format
- [x] Functional requirements (FR-E07-001 through FR-E07-047) cover all user stories
- [x] Security requirements (SR-E07-001 through SR-E07-010) cover authentication, authorization, IDOR, audit, concurrency, and idempotency
- [x] Success criteria (SC-E07-001 through SC-E07-012) are measurable and testable
- [x] Concurrency requirements (CC-E07-001 through CC-E07-004) specify test obligations
- [x] Audit requirements cover all state-changing operations
- [x] Edge cases (EC-E07-01 through EC-E07-10) cover key boundary and failure scenarios

## Financial Integrity (Constitution Principle 4)

- [x] No mutable balance fields on `LedgerAccount` — balance derived from journal lines (FR-E07-024)
- [x] Balance formula specified: SUM(credits) − SUM(debits) for normal-credit accounts (FR-E07-020, FR-E07-021)
- [x] Balanced-journal invariant enforced: SUM(debits) == SUM(credits) per journal (FR-E07-009)
- [x] Partial postings prohibited — atomic commit of all lines (FR-E07-011)
- [x] `JournalLine` immutability specified at application and DB layer (FR-E07-012)
- [x] Reversal via compensating entry specified — no mutation of original (FR-E07-028)
- [x] Idempotency required on all mutation endpoints (FR-E07-025, FR-E07-026)
- [x] Concurrency tests mandated (CC-E07-004)

## TBD Governance (Constitution Principle 2)

- [x] All 7 TBDs documented with ID, description, and impact (Section 22)
- [x] TBD-E07-001 (system accounts) documented; no system account reference hard-coded (FR-E07-005)
- [x] TBD-E07-002 (account type for customer pockets) documented; spec defaults to liability/credit consistent with E06
- [x] TBD-E07-003 (running balance materialization) documented; no balance column on LedgerAccount (FR-E07-024)
- [x] TBD-E07-004 (imbalance detection cadence) documented; command registered but not scheduled hard
- [x] TBD-E07-005 (reversal authorization) documented; proceeds with single permission guard until resolved

## Authorization (Constitution Principle 5)

- [x] All 5 ledger permissions defined with role assignments (Section 15)
- [x] Server-side authorization enforced via E04 middleware (SR-E07-002)
- [x] IDOR prevention: cross-account access returns HTTP 404 (SR-E07-003)
- [x] Reversal requires elevated `ledger.entry.reverse` permission (SR-E07-006, FR-E07-030)
- [x] Customers/Agents have no direct ledger API permissions (Section 5, Section 15)

## Module Boundaries (Constitution Principle 7)

- [x] `LedgerModuleContract` interface defined with all required methods (FR-E07-042, FR-E07-043)
- [x] No direct DB access across module boundaries (SR-E07-007 context, FR-E07-046)
- [x] `NullLedgerModule` update specified (FR-E07-043)
- [x] Architecture integrity test specified (FR-E07-046)
- [x] E06 stub removal specified (FR-E07-045)

## API Contracts (Constitution Principle 8)

- [x] All 11 endpoints listed with method, path, description, permission (Section 14.1)
- [x] All 10 error codes defined with HTTP status and description (Section 14.2)
- [x] Idempotency-Key requirements specified per endpoint (FR-E07-025)
- [x] Response envelope follows platform convention
- [x] Pagination specified for all paginated endpoints (Section 14.3)

## Event Reliability (Constitution Principle 9)

- [x] 4 ledger domain events defined (FR-E07-039)
- [x] Outbox pattern mandated (FR-E07-039)
- [x] Event payload schema specified (FR-E07-040)
- [x] Idempotent event delivery required (SC-E07-009)

## Traceability

- [x] All user stories trace to functional requirements
- [x] All functional requirements trace to one or more user stories
- [x] Success criteria reference measurable outcomes
- [x] Edge cases identified for each significant boundary condition
- [x] Integration boundaries table explicitly separates E07-owned vs. consumed concerns

---

## Open Items

*None at time of specification. See Section 22 (TBDs) for pending business decisions.*

---

## Post-Implementation Verification (2026-09-17)

All 90 tasks (T001–T090 including T031a) completed. Success criteria verified:

| SC | Description | Coverage |
|----|-------------|---------|
| SC-E07-001 | Zero unbalanced journal entries | `BalancedJournalEnforcementTest`, `UnbalancedJournalRejectionTest`, `ledger:check-balance` |
| SC-E07-002 | Atomic commit of balanced journal sets | `BalancedJournalEnforcementTest` (atomicity), DB transaction wrapping |
| SC-E07-003 | Idempotent posting via concurrency tests | `ConcurrentIdempotentPostTest`, `PostDebitJournalTest` (idempotency replay) |
| SC-E07-004 | Balance formula: SUM(credits)−SUM(debits) | `CalculateBalanceTest` (scenario a: 11000) |
| SC-E07-005 | Immutable journal lines | `JournalLineImmutabilityTest`, `LedgerImmutabilityHttpTest` |
| SC-E07-006 | No currency-specific code paths | `MultiCurrencyPostingTest` (6 currencies) |
| SC-E07-007 | Concurrency: idempotent + distinct | `ConcurrentIdempotentPostTest`, `ConcurrentDistinctPostTest`, `ConcurrentBalanceQueryTest` |
| SC-E07-008 | E06 integration reflects real balance | `WalletLedgerIntegrationTest` |
| SC-E07-009 | Outbox pattern for domain events | `PostDebitJournalTest`, `ReverseJournalTest`, `RegisterAccountTest` |
| SC-E07-010 | Architecture boundary enforced | `LedgerBoundaryTest` |
| SC-E07-011 | E06 stub removed | `phpunit.xml` verified; `LedgerServiceProvider` binds `LedgerService` |
| SC-E07-012 | Metrics emitted | `LedgerPostingService`, `LedgerBalanceService`, `LedgerReverseService`, `CheckLedgerBalanceCommand` |
