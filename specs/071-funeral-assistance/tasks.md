# Tasks: P24 — Funeral Assistance Shared Payment Journey

**Input**: Design documents in `specs/071-funeral-assistance/`  
**Predecessor gates**: P18 PARTIAL, P19 implemented, P22 PARTIAL, P23 PARTIAL; see `research.md`.  
**Source**: LC06, FD02, FD07, FD10 in verified architecture v1.1.  
**Implementation note**: In-scope unblocked work proceeds. P18 payout execution/settlement and late-payment disposition remain blocked; do not claim those flows complete.

## Phase 1 — Source and contract gates

- [X] T001 Verify source document path, v1.1 identity/date, copied-source SHA-256 and LC06/FD02/FD07/FD10 sections; record in `research.md`.
- [X] T002 Inspect constitution, no-AGENTS status, installed Spec Kit v1.1.3.dev0, current pointer, P18/P19/P22/P23 implementation and evidence; record contract gates in `research.md`.
- [X] T003 Create P24 package via installed `create-new-feature.ps1`, then run installed `setup-plan.ps1` and `setup-tasks.ps1`; retain and restore the prior P23 feature pointer after P24 artifacts are complete.

## Phase 2 — Foundation and security

- [X] T004 Add `CommunityFuneral` service provider, routes, permission catalogue/seeding, local audit contract and module registration. Foundation audit forwarding remains a release check.
- [X] T005 Add reversible community-owned migration for communities, households, representatives, funeral requests, snapshots, obligations, confirmation/late-review references and closure records; apply only in the scoped test/local database.
- [X] T006 [P] Add typed models/value objects/enums/repositories in `app/Modules/CommunityFuneral/`; use community_services connection and never write Payment/Wallet/Ledger tables.
- [X] T007 [P] Add server-side tenant-scope resolver and actor-to-household authorization; deny when authoritative tenant membership cannot be established. Identity reviewed actor-to-organisation registry and P24 binding now pass local tests; approved registry evidence/governance, two-process race and complete household API acceptance remain open.
- [X] T008 Add `community.funeral.*` permissions and admin/customer route authorization; audit privileged transitions without logging PII/evidence.
- [X] T009 [P] Add API/OpenAPI contract and request/response resources under `specs/071-funeral-assistance/contracts/` and API docs.

## Phase 3 — US1: Household register and representatives

- [X] T010 [P] Write API tests for tenant-scoped community/household creation and unverified initial representative status. Covered in `FuneralTenantHttpTest` and `FuneralRepresentativeHttpTest`.
- [X] T011 Implement household and representative lifecycle service/controller; store only opaque restricted evidence reference.
- [X] T012 Implement reviewer verification/revocation with actor separation and audit history.
- [X] T013 Add customer own-household obligations/read authorization tests; cross-household and cross-community IDs must be denied.

## Phase 4 — US2: Funeral request, independent review, beneficiary control

- [X] T014 [P] Write API tests for request review, verified organisation beneficiary and beneficiary-edit approval invalidation in `FuneralCollectionReviewTest.php`.
- [X] T015 Implement request creation and minimal evidence-reference handling.
- [X] T016 Implement independent review and revalidation of P18 beneficiary status/organisation at activation.
- [X] T017 Make every beneficiary change clear reviewer/approval and force a fresh review; no silent carry-over.

## Phase 5 — US3: Activation snapshot and obligation creation

- [X] T018 [P] Write database concurrency test: repeated/concurrent activation creates one snapshot and exactly one obligation per eligible household.
- [X] T019 Implement transaction-locked activation, immutable snapshot digest/version, explicit exemptions, and unique collection/household obligations.
- [X] T020 Enforce server-owned BWP 2,000 minor default and reject client amount override; do not implement mandatory debt or extra donation.
- [X] T021 Register `FuneralObligationResolver` for `funeral_contribution` with shared Payment; validate actor, active collection, obligation, tenant, currency, amount and eligibility.
- [X] T022 Send private non-marketing eligibility notifications through the existing Notification inbox and P24 outbox; verify per-household privacy. Queue operations remain a release check.

## Phase 6 — US4: Shared checkout and once-only fulfilment

- [X] T023 [P] Write event replay/concurrency test proving one payment receipt reference and one fulfilment per obligation.
- [X] T024 Consume durable `payment.confirmed` after accounting acknowledgement; lock collection/obligation and persist idempotently. Runtime acceptance remains blocked by P18 test schema.
- [X] T025 Keep intent, attempt/capture, accounting, fulfilment, fee, refund, settlement and payout states separate; link P19 receipt/history only.
- [X] T026 Add customer mobile “Contribute” entry point that launches the existing P22 shared checkout with the server-returned payable reference.
- [X] T027 Add P22/P23 navigation/deep link and pending/confirmed/refunded state copy without implementing duplicate checkout or receipt code.

## Phase 7 — US5: Close, late capture, reconciliation and aggregate report

- [X] T028 [P] Write late confirmation after closure test; ensure `late_review`, open liability, no automatic fulfil/refund.
- [X] T029 Implement close cutoff: disallow new intents and retain existing Payment intents/attempts for status recovery.
- [X] T030 Implement late-review quarantine; do not resolve without approved capture cutoff evidence/policy.
- [X] T031 Implement aggregate report with shared gross/fee/refund references and separately labelled net, settlement, payout and unresolved liability fields; no household identifiers. Runtime finance acceptance pending.
- [X] T032 Implement archive guard; unresolved late/payment/refund/reconciliation/payout liabilities block archive unless an approved carry-forward reference is recorded. Runtime finance acceptance pending.
- [X] T033 [P] Add report privacy, aggregate reconciliation and archive-guard tests.
- [X] T034 Add admin UI for funeral requests, eligibility review, collection close, late-review queue and aggregate report in existing Community admin shell.

## Phase 8 — US6: Family payout (BLOCKED)

- [ ] T035 Verify P18 `PayoutExecutionService` and `PayoutSettlementService` implementation, AD-14, AD-10, LC14 approvals and execution/settlement tests. **BLOCKED** by current explicit stubs and unresolved decisions.
- [ ] T036 Only after T035: invoke shared P18 payout request and approval API, preserve liability until provider and ledger evidence confirms settlement. Do not add a local adapter, bank API, wallet debit or journal.

## Phase 9 — Verification and release record

- [X] T037 Run scoped CommunityFuneral feature/unit tests; report counts and actual concurrency environment. Local PostgreSQL tests passed; two-process concurrency not run.
- [X] T038 Run targeted P19/P22/P23 contract/regression tests; record unresolved Docker/performance gates separately. P19 final-class test setup repaired: 13 pass and 15 skip; 28 Flutter checkout/history/receipt checks pass. Skipped database cases, runtime and performance gates remain open; see `evidence.md`.
- [X] T039 Run PHPStan/static checks on new CommunityFuneral files and API/OpenAPI validation. PHPStan passes; OpenAPI validation pending.
- [X] T040 Complete flow runbook, source→story→task→code→test traceability and evidence log; record incomplete runtime acceptance explicitly.
- [X] T041 Update managerial register `specs/049-payment-delivery-register/spec.md` for P18 and P24 with verified status, gates and next action.
- [X] T042 Write `restart.md` with branch/commit, exact changed files, executed tests, remaining operational decisions and next permitted action.
- [X] T043 Restore `.specify/feature.json` to the prior active P23 pointer; leave P24 package independently addressable.

## Dependencies

```text
T001–T003 → T004–T009 → US1 T010–T013 → US2 T014–T017
→ US3 T018–T022 → US4 T023–T027 → US5 T028–T034
US6 T035 BLOCKED → T036 only after finance/architecture approvals
All unblocked scope → T037–T043
```

## Definition of Done

- Unblocked household, review, snapshot/obligation, shared checkout, once-only fulfilment, close/report/archive code and meaningful tests are complete.
- Duplicate/concurrent delegates cannot create duplicate obligations or fulfilment; beneficiary edit invalidates approval; late capture stays controlled and unresolved.
- Payment, accounting, fulfilment, refund, settlement and payout remain independent.
- P18 disbursement and late-payment policy are explicitly BLOCKED until approved external decisions and evidence exist.
- No production deployment or live processing enablement occurs.

## Phase 10: Convergence

- [X] T044 [CRITICAL] Fix transaction test execution and notice delivery status assertion in FuneralAssistanceFlowTest per Constitution 12 (contradicts)
- [X] T045 [CRITICAL] Implement two-process concurrency tests for activation snapshot creation and payment confirmation replay per Constitution 4 (missing)
- [X] T046 Implement representative revocation endpoint, service logic, and audit transitions per FR-001, US1/AC3 (missing)
- [X] T047 Implement customer mobile collection view, obligation fetch, and P22 shared checkout launch per US4/AC1, plan: Mobile UI (missing)
- [X] T048 Implement customer mobile navigation, deep linking, and payment status copy per US4/AC3, plan: Mobile UI (missing)
- [X] T049 Implement Next.js admin UI pages for funeral collection review, activation, closure, and aggregate report per US5, plan: Admin UI (missing)
- [X] T050 Add typed domain models, enums, value objects, and repositories to CommunityFuneral module per plan: Architecture, T006 (missing)
- [X] T051 Write feature HTTP tests for representative verification/revocation and customer obligation read authorization per US1/AC2-AC3, T010, T013 (missing)
- [X] T052 Write feature HTTP tests for collection review, verified beneficiary, and beneficiary-edit invalidation per US2/AC1-AC2, T014 (missing)
- [X] T053 Write feature tests for post-close late confirmation quarantine, aggregate report privacy, and archive carry-forward gating per US5/AC1-AC5, T028, T033 (missing)
- [X] T054 Refactor controller requests/responses to typed FormRequests and JsonResources, and validate OpenAPI schema per Constitution 8, plan: Data/API/UI scope (partial)
- [X] T055 Replace hard-coded contribution amount and currency with configuration-driven defaults per Constitution 3, FR-006, SC-002 (partial)
- [X] T056 Execute targeted P19/P22/P23 regression test suites and fix route:list controller blocker per plan: Verified predecessor contracts, T038 (partial)
- [X] T057 Write automated HTTP test for payout-request 503 error contract and open liability preservation per US6, FR-013, T035, T036 (missing)
