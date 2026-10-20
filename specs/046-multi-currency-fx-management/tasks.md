# ADM-P1-022 tasks
## Phase 1 — Governance and design
- [x] T001 Review approved catalogue/E29/constitution and existing related code; specify and clarify spec.md.
- [x] T002 Research reuse/provider/approval dependencies; plan, data model, contracts and quickstart.
## Phase 2 — Foundation
- [x] T003 Backend tests first in tests/Feature/Modules/MultiCurrency/AdminFxManagementTest.php; provider and regression boundaries.
- [x] T004 Add provenance migration, provider contract/unavailable adapter and DI; preserve rate decimal storage.
- [x] T005 Harden existing FxRateAdminService approval/idempotency/locking; approval gate cannot be bypassed by legacy routes.
## Phase 3 — US-P1-022-001
- [x] T006 Currency status E28 proposal/apply service and immutable precision safeguard, thin endpoints, list Base and pending request.
- [x] T007 Extend currencies.tsx with permission-aware approval controls, field labels and responsive states.
## Phase 4 — US-P1-022-002
- [x] T008 Manual/provider proposals and lock-aware provider ingestion with validated snapshot provenance and safe unconfigured failure.
- [x] T009 Extend fx-rates.tsx current table, percent spread, provider/manual mode, pending approvals and safe errors.
## Phase 5 — US-P1-022-003
- [x] T010 History endpoint pagination and 30-day active chart, accessible annotated UI history.
## Phase 6 — Verification and convergence
- [ ] T011 Backend/unit/related regression and static checks; resolve failures.
- [x] T012 Frontend interactions, type/lint, browser desktop/mobile visual verification.
- [x] T013 Read-only convergence; update story/AC/DoD/security evidence and file traceability, report actual blockers.
- [x] T014 Fix E28 draft version allocation under a per-key lock; repeated FX approvals exposed default-version collision. Validate repeated approvals regression.

## Phase 7: Convergence
- [ ] T015 [US-P1-022-002 / AC05] BLOCKED: obtain approved FX provider selection/configuration, implement and bind authenticated adapter, and verify live provider reversion and refresh without overriding manual locks. Current unavailable default must remain until configured.
- [ ] T016 [T011 / DoD] Repair broader E29 regression fixtures/permission expectations against current Wallet/Ledger and approved maker-checker contracts, then rerun financial regression. Current broad run has 66 failures (primarily obsolete wallet fixture); do not claim release regression PASS.
- [x] T017 [US-P1-022-002 / financial idempotency] Bind explicit effective dates and provider provenance to approved proposal/replay; use original proposed rate ID when provider refresh creates later history rows. Add conflicting effective-date replay coverage.

