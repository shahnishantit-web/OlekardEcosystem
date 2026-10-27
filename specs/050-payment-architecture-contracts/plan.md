# Implementation Plan: P01 — Architecture Contracts and Accounting Decisions

**Branch**: `050-payment-architecture-contracts` | **Date**: 2026-10-21 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/050-payment-architecture-contracts/spec.md`

## Summary

Produce four authoritative architecture documents that unblock all downstream P02–P11 engineering work: a module boundary register (30 modules, owned tables, permitted interfaces), a contract register (semantic invariants for the 5 cross-module interfaces), a financial vocabulary glossary (8 financial lifecycle terms), and ADR-E48-003 (accounting decision register). All outputs are documentation in `docs/architecture/`; no code, migrations, or provider execution.

## Technical Context

**Language/Version**: Markdown documentation; analysis of PHP 8.4 / Laravel source

**Primary Dependencies**: Existing repository evidence — ADR-E48-001, ADR-E48-002, 5 Payment domain contracts, WalletPaymentAdapter, 30 module migration files

**Storage**: `docs/architecture/` and `docs/architecture/decisions/` (existing ADR location)

**Testing**: Peer review protocol — architect + finance reviewer sign-off per SC-01–SC-06 in spec

**Target Platform**: Repository documentation (Markdown); consumed by engineers during P02–P11

**Project Type**: Architecture documentation package (design-only)

**Performance Goals**: N/A — documents must be complete, not performant

**Constraints**:
- Must not contradict ADR-E48-001 or ADR-E48-002
- Unresolved decisions must be recorded with named owner and blocking condition, not invented
- Architecture document v1.1 (`OleKard_Shared_Payment_Architecture.docx`) not in repository — decisions from FD05/FD06/FD10 are AWAITING_DOCUMENT
- E25 ↔ Payment adapter boundary remains UNRESOLVED (DEC-06 from P00)

**Scale/Scope**: 30 module entries, 5 contract definitions, 8 vocabulary terms, 15 accounting decisions

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Gate | Status |
|-----------|------|--------|
| P1 — Source-of-Truth | Register must derive decisions from existing ADRs + repository evidence; no silent changes | PASS — all decisions sourced from ADR-E48-001/002 and code inspection |
| P2 — TBD Governance | Unresolved decisions MUST be marked UNRESOLVED with owner; not invented | PASS — 8 accounting decisions explicitly AWAITING_DOCUMENT |
| P4 — Financial Integrity | Vocabulary glossary must state double-entry requirement; no direct balance mutation in any described pattern | PASS — double-entry mandated in all wallet payment flows |
| P7 — Domain Boundaries | Module boundary register is the primary deliverable — must respect extraction-ready architecture | PASS — register documents per-module ownership |
| P8 — API Contracts | Contract register defines approved cross-module interfaces with invariants | PASS — 5 contracts with pre/post-conditions |
| P14 — No Silent Architecture Changes | ADR-E48-003 is additive to ADR-E48-001/002; payout boundary recorded as UNRESOLVED pending an ADR | PASS — no technology changes |

**Constitution Check: PASS.** No violations.

## Project Structure

### Documentation (this feature)

```text
specs/050-payment-architecture-contracts/
├── plan.md                          # This file
├── research.md                      # Phase 0: repository findings
├── data-model.md                    # Phase 1: register entry schemas
├── contracts/
│   ├── module-boundary-register.md  # 30-module boundary table
│   ├── contract-register.md         # 5 interface contracts with invariants
│   └── financial-vocabulary.md      # 8-term financial lifecycle glossary
├── quickstart.md                    # Peer review verification protocol
└── tasks.md                         # /speckit-tasks output (not yet generated)
```

### Source Code (repository root)

```text
docs/architecture/
├── decisions/
│   ├── ADR-E48-001-payment-module-ownership.md           # Existing — accepted
│   ├── ADR-E48-002-wallet-payment-adapter-delegation.md  # Existing — accepted
│   └── ADR-E48-003-accounting-decisions.md               # NEW — P01 deliverable
├── payment-module-boundary-register.md                   # NEW — P01 deliverable
├── payment-contract-register.md                          # NEW — P01 deliverable
└── payment-financial-vocabulary.md                       # NEW — P01 deliverable
```

**Structure Decision**: Documents live alongside the existing ADRs in `docs/architecture/`. The spec's `contracts/` folder holds the draft/working content; `tasks.md` will instruct `/speckit-implement` to write the final forms to `docs/architecture/`. This mirrors the existing ADR-E48-001/002 location and keeps all architecture decisions discoverable from one path.

## Complexity Tracking

No violations. No complexity tracking required.
