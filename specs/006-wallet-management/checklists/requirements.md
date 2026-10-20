# Requirements Quality Checklist — E06 Wallet Management

This checklist is maintained by `/speckit-specify` and `/speckit-clarify`. Items are checked when the requirement quality criterion is satisfied.

---

## Completeness

- [x] All 17 SRS user stories (US-WALLET-001 through US-WALLET-017) are represented with expanded acceptance scenarios
- [x] Each user story has an independent test scenario
- [x] Each user story has at least 4 acceptance scenarios in Given/When/Then format
- [x] Functional requirements (FR-E06-001 through FR-E06-049) cover all user stories
- [x] Security requirements (SR-E06-001 through SR-E06-015) cover authentication, authorization, IDOR, audit, concurrency, and idempotency
- [x] Success criteria (SC-E06-001 through SC-E06-012) are measurable and testable
- [x] Concurrency requirements (CC-E06-001 through CC-E06-005) specify test obligations
- [x] Audit requirements cover all state-changing operations
- [x] UI/UX requirements specified for Consumer Flutter, Agent Flutter, and Admin Next.js surfaces
- [x] Edge cases (EC-E06-01 through EC-E06-12) cover key boundary and failure scenarios

## Financial Integrity (Constitution Principle 4)

- [x] No mutable balance fields on `CurrencyPocket` (FR-E06-016)
- [x] Available balance formula specified: `max(0, ledger_balance − held_amount)` (FR-E06-017)
- [x] Ledger balance derives from E07 LedgerServiceContract, not computed independently (FR-E06-018)
- [x] `SELECT FOR UPDATE` locking specified for concurrent debit prevention (FR-E06-025)
- [x] Idempotency required on all financial mutation endpoints (Section 13)
- [x] Concurrency tests mandated (CC-E06-005)
- [x] Wallet closure requires zero balance (FR-E06-036)
- [x] Hold lifecycle defined with release on settlement/cancellation/expiry (Section 11.4)

## TBD Governance (Constitution Principle 2)

- [x] All 8 TBDs documented with ID, description, and impact (Section 21)
- [x] TBDs will be seeded with `is_tbd = true`; none are hard-coded
- [x] USDT precision TBD-E06-001 documented; no code path hard-codes USDT precision
- [x] Wallet limits TBD-E06-004 documented; no limit values invented
- [x] Step-up authentication TBD-E06-002 documented; freeze/unfreeze proceeds without step-up until resolved

## Authorization (Constitution Principle 5)

- [x] All 10 wallet permissions defined and role assignments specified (FR-E06-041 to FR-E06-044)
- [x] Server-side authorization enforced via E04 middleware (SR-E06-002)
- [x] IDOR prevention: cross-user wallet access returns HTTP 404 (SR-E06-003)
- [x] Privileged operations (freeze, unfreeze, close) require elevated permissions (FR-E06-044)

## Module Boundaries (Constitution Principle 7)

- [x] `WalletModuleContract` interface defined with all required methods (FR-E06-046)
- [x] No direct DB access across module boundaries (SR-E06-014)
- [x] Integration boundary table specifies what E06 owns vs consumes (Section 18)
- [x] `NullWalletModule` update specified (FR-E06-047)
- [x] `wallet:expire-holds` scheduled command specified (FR-E06-048)
- [x] Architecture integrity test (no cross-module DB access) specified (FR-E06-049)

## API Contracts (Constitution Principle 8)

- [x] All 13 endpoints listed with method, path, description, required permission (Section 16.1)
- [x] All 13 error codes defined with HTTP status and description (Section 16.2)
- [x] Idempotency-Key requirements specified per endpoint (Section 13)
- [x] Response envelope follows platform convention
- [x] Pagination specified for paginated endpoints (Section 16.3)

## Event Reliability (Constitution Principle 9)

- [x] 9 wallet domain events defined (FR-E06-038)
- [x] Outbox pattern mandated (FR-E06-037)
- [x] Event payload schema specified (FR-E06-039)
- [x] Idempotent event delivery required (SC-E06-011)

## UX / Design System (Constitution Principle 10)

- [x] Consumer Flutter wallet overview screen specified (Section 17.1)
- [x] All wallet status states visually distinguishable (Section 17.1)
- [x] Agent wallet lookup specified (Section 17.2)
- [x] Admin wallet management list and detail specified (Section 17.3)
- [x] Accessibility requirements specified for Consumer (Semantics), Agent, and Admin surfaces (Sections 17.1, 17.3)

## Traceability

- [x] All user stories trace to functional requirements
- [x] All functional requirements trace to one or more user stories
- [x] Success criteria reference measurable outcomes for plan/tasks phases
- [x] Edge cases identified for each significant boundary condition

---

## Implementation Coverage — E06 Complete

All 49 Functional Requirements and 15 Security Requirements are covered by implementation tasks in `specs/006-wallet-management/tasks.md`.

| Requirement Range | Phases / Key Tasks |
|---|---|
| FR-E06-001 to FR-E06-008 (Wallet entity, lifecycle, ownership) | Phase 2–3 (T001–T049) |
| FR-E06-009 to FR-E06-015 (Currency pockets, currencies) | Phase 2, 6 (T022–T028, T061–T067) |
| FR-E06-016 to FR-E06-022 (Balance architecture, formula) | Phase 2, 9–12 (T006–T009, T087–T108) |
| FR-E06-023 to FR-E06-028 (Holds, release, expiry) | Phase 13 (T109–T118, T153–T155) |
| FR-E06-029 to FR-E06-036 (Status, limits, close) | Phase 7–8, 15, 19 (T069–T086, T146–T152) |
| FR-E06-037 to FR-E06-040 (Events, outbox, audit) | Phase 3–19 (all event tasks) |
| FR-E06-041 to FR-E06-049 (Auth, contracts, architecture) | Phase 2, 5, 20 (T006–T009, T055–T059, T157–T165) |
| SR-E06-001 to SR-E06-015 (Security requirements) | T158–T160, T046, T055–T056, T072, T074 |

**Implementation status**: COMPLETE/RELEASE READY — 166/166 tasks done.

## Open Items

*None at time of specification. See Section 21 (TBDs) for pending business decisions.*
