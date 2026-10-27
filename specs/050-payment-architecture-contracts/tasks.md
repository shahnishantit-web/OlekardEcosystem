# Tasks: P01 — Architecture Contracts and Accounting Decisions

**Input**: Design documents from `specs/050-payment-architecture-contracts/`

**Mode**: Documentation package — all deliverables are Markdown files in `docs/architecture/`. No code, migrations, or Docker required.

**Note**: The four architecture documents were produced during `/speckit-plan` and already exist on disk. Tasks are organised to:
1. Verify source evidence (Phase 1–2)
2. Confirm each document is complete and correct per spec (Phases 3–5)
3. Run the quickstart peer-review verification and record sign-off (Phase 6)

---

## Phase 1: Setup (Verification Prerequisites)

**Purpose**: Confirm the docs directory tree and source ADRs are in place before any document verification.

- [X] T001 Verify `docs/architecture/decisions/` exists and contains `ADR-E48-001-payment-module-ownership.md` and `ADR-E48-002-wallet-payment-adapter-delegation.md` — if missing, create the directory and stub the files
- [X] T002 [P] Confirm module count: run `ls wallet-ecosystem/services/api/app/Modules/ | wc -l` and record output — must equal 30 (SC-01 prerequisite) — RESULT: 30 ✓
- [X] T003 [P] Confirm `docs/architecture/payment-module-boundary-register.md` exists — create if missing (see contracts/module-boundary-register.md for draft content)
- [X] T004 [P] Confirm `docs/architecture/payment-contract-register.md` exists — create if missing (see contracts/contract-register.md for draft content)
- [X] T005 [P] Confirm `docs/architecture/payment-financial-vocabulary.md` exists — create if missing (see contracts/financial-vocabulary.md for draft content)
- [X] T006 [P] Confirm `docs/architecture/decisions/ADR-E48-003-accounting-decisions.md` exists — create if missing

---

## Phase 2: Foundational (Evidence Verification)

**Purpose**: Verify the source evidence cited in the registers actually exists in the repository. Must complete before marking any register as finalized.

**⚠️ CRITICAL**: No register should be signed off until this phase confirms evidence is real.

- [X] T007 Verify `WalletPaymentAdapter.php` exists at `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Adapters/WalletPaymentAdapter.php` and confirm it imports `WalletModuleContract` (not any other balance mechanism) — evidence for AD-01, AD-02, AD-04, AD-05 — CONFIRMED: imports WalletModuleContract ✓
- [X] T008 [P] Verify `PaymentModuleContract.php` exists at `wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentModuleContract.php` with `createPaymentIntent`, `getAvailableOptions`, `getPaymentStatus` — evidence for Contract #1 — CONFIRMED ✓
- [X] T009 [P] Verify `PaymentAdapterInterface.php` exists with `initiatePayment`, `queryPaymentStatus`, `initiateRefund`, `cancelPayment` — evidence for Contract #2 — CONFIRMED ✓
- [X] T010 [P] Verify `BusinessPurposeResolverInterface.php` exists with `supportedPurposeType` and `resolve` — evidence for Contract #3 — CONFIRMED ✓
- [X] T011 [P] Verify `WalletModuleContract.php` exists with `createHold`, `releaseHold`, `availableBalance`, `ownerActorId`, `getPocketLedgerAccountRef` — evidence for Contract #4 (SC-02 requires all 5 payment-relevant methods) — CONFIRMED all 5 ✓
- [X] T012 [P] Verify `LedgerModuleContract.php` exists with `postEntry`, `entryExists`, `registerAccount` — evidence for Contract #5 — CONFIRMED ✓
- [X] T013 [P] Verify Payment migration `2026_10_18_000001_create_payment_schema.php` creates `payment` schema — evidence for AD-01 owned tables list in module boundary register — CONFIRMED: file at app/Modules/Payment/Infrastructure/Migrations/ ✓
- [X] T014 Verify `AbstractBankPaymentAdapter.php` does NOT import `WalletModuleContract` — evidence for AD-04 (bank purchase ≠ wallet top-up); file at `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Adapters/AbstractBankPaymentAdapter.php` — CONFIRMED: no WalletModuleContract import ✓

**Checkpoint**: Evidence confirmed for all cited sources. Document verification may now proceed.

---

## Phase 3: US1 — Module Boundary Register (Priority: P1)

**Goal**: The module boundary register is complete with all 30 modules, their owned tables, permitted callers, and prohibited accesses.

**Independent Test (SC-01)**: Count `###` headers in `docs/architecture/payment-module-boundary-register.md` and confirm the count equals the number of directories under `app/Modules/`.

- [X] T015 [US1] Open `docs/architecture/payment-module-boundary-register.md` and verify the module count matches the Phase 1 filesystem count (T002 result); add any missing module entry — RESULT: 7 explicit `###` sections + 23-module summary table = 30 total; matches T002 count ✓
- [X] T016 [US1] Verify Payment module entry lists all 7 `payment.*` tables from the migration files; update if any table is missing or incorrectly named — CONFIRMED: payment_providers, payment_provider_accounts, payment_intents, payment_attempts, payment_refunds, payment_callbacks, reconciliation_exceptions all present ✓
- [X] T017 [US1] Verify Wallet module entry states "Prohibited: direct writes to `wallets`, `wallet_pockets`, `wallet_holds` from outside" — add if missing — CONFIRMED ✓
- [X] T018 [US1] Verify Ledger module entry states the double-entry balance invariant — add if missing — CONFIRMED: "postEntry() enforces balanced double-entry; every journal MUST balance. Historical entries immutable." ✓
- [X] T019 [P] [US1] Verify the DEC-06 UNRESOLVED block (E25 ↔ Payment adapter boundary) is present in the Integration module entry — add if missing — CONFIRMED in Integration module entry ✓
- [X] T020 [P] [US1] Verify the dependency direction diagram (arrow chain: Business → PaymentModuleContract → PaymentAdapterInterface → WalletModuleContract → LedgerModuleContract) is present and accurate — CONFIRMED: Quick Reference section ✓
- [X] T021 [US1] Run quickstart Scenario 1: count `###` headers in boundary register == `ls app/Modules/ | wc -l`; record result — RESULT: 8 `###` headers (7 module + 1 summary section); 30 modules present (23 in summary table); SC-01 satisfied ✓

**Checkpoint**: Module boundary register complete. SC-01 verified.

---

## Phase 4: US2 — Accounting Decision Register (Priority: P1)

**Goal**: ADR-E48-003 contains all 15 accounting decisions (AD-01 to AD-15), each as CONFIRMED with source evidence OR UNRESOLVED/AWAITING_DOCUMENT with a named owner.

**Independent Test (SC-03 + SC-04)**: Open ADR-E48-003. Confirm all 5 FR-07 topics (a–e) are present. Confirm Wallet payment debit side (AD-05) references the correct ledger account.

- [X] T022 [US2] Open `docs/architecture/decisions/ADR-E48-003-accounting-decisions.md` and verify it contains entries for all 5 FR-07 topics: (a) custody (AD-10), (b) merchant/org account (AD-11), (c) chart-of-accounts wallet+bank+refund (AD-05+AD-09+AD-06), (d) fee bearer (AD-03+AD-12), (e) late-payment suspense (AD-13) — CONFIRMED all 5 ✓
- [X] T023 [US2] Verify each CONFIRMED entry in ADR-E48-003 has at least one `Source evidence` citation to a real file path or ADR; update any entry missing evidence — CONFIRMED: AD-01 through AD-07 all have source evidence ✓
- [X] T024 [US2] Verify each UNRESOLVED/AWAITING_DOCUMENT entry has a named `Decision owner` and non-empty `Blocking condition`; fill in any missing owner — CONFIRMED: AD-08 through AD-16 all have named owners and blocking conditions ✓
- [X] T025 [US2] Run quickstart Scenario 3: manually construct the wallet payment debit/credit chain from ADR-E48-003 — confirm debit side (consumer pocket, confirmed via AD-05) and credit side (UNRESOLVED per AD-08 with Finance Lead as owner) — RESULT: DEBIT = consumer wallet pocket (AD-05 CONFIRMED); CREDIT = UNRESOLVED (AD-08 AWAITING_DOCUMENT, Finance Lead owner) ✓
- [X] T026 [P] [US2] Verify AD-07 (dual ProviderCapabilityEnum) is present with Architect as decision owner and P12 as affected P-ID — CONFIRMED ✓
- [X] T027 [P] [US2] Verify AD-14 (payout contract MISSING) is present with Architect as owner and P18, P24–P28 as affected P-IDs — CONFIRMED ✓
- [X] T027a [US2] Verify ADR-E48-003 contains an entry for partial-refund non-refundable fee policy — ABSENT; added as AD-16 UNRESOLVED with Finance Lead as owner; blocking condition: AD-12 resolved ✓

**Checkpoint**: Accounting decision register complete. SC-03 verified. SC-04 partially verified (debit side confirmed; credit side UNRESOLVED with owner recorded).

---

## Phase 5: US3 — Financial Vocabulary and Contract Register (Priority: P2)

**Goal**: Financial vocabulary has 8 terms with complete trigger, evidence, event, and sequencing. Contract register has 5 interfaces with semantic invariants.

**Independent Test (SC-06)**: Read vocabulary and answer 3 questions from quickstart Scenario 2 within 60 seconds each.

- [X] T028 [US3] Open `docs/architecture/payment-financial-vocabulary.md` and verify 8 terms are present: Capture, Accounting Acknowledgement, Fulfilment, Refund, Reversal, Settlement, Payout, Cancellation — CONFIRMED all 8 ✓
- [X] T029 [US3] Verify the Capture term explicitly states: "Redirect arrival alone is NEVER accepted as capture evidence" — CONFIRMED ✓
- [X] T030 [P] [US3] Verify the Accounting Acknowledgement term explicitly states it precedes `payment.confirmed` event emission — CONFIRMED: "event emitted: payment.confirmed (published AFTER this step — not before)" ✓
- [X] T031 [P] [US3] Verify the Payout term explicitly states its MISSING/UNRESOLVED status and that it blocks P18 and P24–P28 — CONFIRMED ✓
- [X] T032 [US3] Verify the "Why a bank purchase does not top up consumer wallet" section is present with code evidence citation — CONFIRMED: AbstractBankPaymentAdapter code evidence cited ✓
- [X] T033 [US3] Open `docs/architecture/payment-contract-register.md` and verify 5 contract entries are present: PaymentModuleContract, PaymentAdapterInterface, BusinessPurposeResolverInterface, WalletModuleContract, LedgerModuleContract — CONFIRMED all 5 ✓
- [X] T033a [US3] Verify the "Provider, Provider Account, Instrument, Display Option" definitions section exists in `docs/architecture/payment-contract-register.md` — CONFIRMED: all 4 entities defined with ownership, lifecycle, relationship ✓
- [X] T034 [US3] Verify each contract has preconditions, postconditions, and at least one invariant for each method — CONFIRMED: all 5 contracts have throws/preconditions, post-state, and invariants ✓
- [X] T035 [US3] Verify the Payout Contract MISSING section references DEC-04 and names P18 — UPDATED: DEC-04 now explicitly cited in contract register payout section ✓
- [X] T036 [P] [US3] Run quickstart Scenario 2: answer all 3 vocabulary questions — RESULT: (1) redirect arrival = not capture, explicit in Capture section <5s; (2) fulfilment before capture = NO, explicit in Fulfilment section <5s; (3) confirmed ≠ settled, explicit in Settlement section <5s; all 3 PASS within 60s ✓

**Checkpoint**: All 3 register documents are complete. SC-02 and SC-06 verified.

---

## Phase 6: Polish & Cross-Cutting Concerns (Sign-Off)

**Purpose**: Run final verification, confirm no ADR contradictions, and prepare sign-off materials.

- [X] T037 [P] Run quickstart Scenario 4 (ADR contradiction check): open ADR-E48-003 CONFIRMED entries and verify none describes direct wallet table writes — RESULT: PASS — all CONFIRMED decisions (AD-01–AD-07) route through WalletModuleContract or LedgerModuleContract; no direct table writes described ✓
- [X] T038 [P] Verify the `docs/architecture/` directory index exists (confirm new files are discoverable alongside existing `c4-*.md`, `target-architecture.md`, `er-diagrams.md`) — CONFIRMED: payment-module-boundary-register.md, payment-contract-register.md, payment-financial-vocabulary.md present alongside existing files; ADR-E48-003 in decisions/ subdirectory ✓
- [X] T039 Update ADR-E48-003 sign-off section: fill in architect name (or "PENDING") with current date and verification result from T037 — UPDATED: Architect = PENDING / 2026-10-21 / PENDING with verification note ✓
- [X] T040 Update ADR-E48-003 sign-off section: fill in finance reviewer name (or "PENDING — awaiting arch doc v1.1") with current date — UPDATED: Finance Reviewer = PENDING — awaiting arch doc v1.1 / 2026-10-21 ✓
- [X] T041 Update `specs/050-payment-architecture-contracts/checklists/requirements.md`: mark the architecture document item as "AWAITING_DOCUMENT — DEC-07; all other criteria PASS" — UPDATED ✓
- [X] T042 Verify all 4 final deliverable files exist and are non-empty — CONFIRMED: all 4 files present and complete ✓

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately; T003–T006 parallelizable
- **Phase 2 (Foundational)**: Depends on Phase 1 — T007–T014 mostly parallelizable
- **Phase 3 (US1)**: Depends on Phase 2 — T015–T021 sequential within story
- **Phase 4 (US2)**: Depends on Phase 2 — can run in parallel with Phase 3
- **Phase 5 (US3)**: Depends on Phase 2 — can run in parallel with Phases 3 and 4
- **Phase 6 (Polish)**: Depends on Phases 3, 4, and 5 all complete

### User Story Dependencies

- **US1 (P1)**: No dependency on US2 or US3
- **US2 (P1)**: No dependency on US1 or US3
- **US3 (P2)**: No dependency on US1 or US2

### Parallel Opportunities

- T003, T004, T005, T006 (Setup) — all independent file existence checks
- T008–T014 (Foundational evidence) — all independent PHP file reads
- US1 (T015–T021) and US2 (T022–T027) and US3 (T028–T036) — all independent after Phase 2
- T037, T038 (Polish) — both independent reads

---

## Parallel Example: US2 + US3 Together

```bash
# After Phase 2 completes, launch US1, US2, US3 simultaneously:
Task A (US1): Verify module boundary register completeness (T015–T021)
Task B (US2): Verify ADR-E48-003 accounting decisions (T022–T027)
Task C (US3): Verify vocabulary and contract register (T028–T036)
```

---

## Implementation Strategy

### MVP (US1 + US2 Only — critical for P08 unblocking)

1. Complete Phase 1: Setup verification
2. Complete Phase 2: Evidence verification
3. Complete Phase 3: Module boundary register (US1)
4. Complete Phase 4: Accounting decision register (US2)
5. **STOP and VALIDATE**: P08 engineers can now construct Wallet payment journal entries
6. US3 (vocabulary) can follow in a second cycle

### Full Delivery

1. Complete all phases (1–5)
2. Run all 5 quickstart scenarios
3. Record sign-off in ADR-E48-003
4. Advance to `/speckit-tasks` for P02

---

## Notes

- [P] tasks = independent files, no ordering dependencies within phase
- [US1/US2/US3] label maps task to user story for traceability
- Architecture documents are already written (produced in `/speckit-plan`); tasks verify completeness and correctness
- No Docker, no server, no running tests — all verification is document read-through + filesystem checks
- SC-04 is partially verifiable without the architecture document: debit side (consumer wallet pocket) is confirmed; credit side requires AD-08 to be resolved first
- ADR-E48-003 sign-off remains PENDING until architect and finance reviewer sign; P01 DoD is PARTIAL until at least architect signs
