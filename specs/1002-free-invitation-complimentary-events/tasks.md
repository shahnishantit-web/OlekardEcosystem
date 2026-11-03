# P26 Tasks: Free invitation and complimentary event journey

**Input**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `contracts/free-registration.md`  
**Status**: PARTIAL — development implementation is verified for scoped registration, online admission and reporting; production approval, cancellation/offline policy and independent review remain open.
**Status rule**: a checked task records engineering evidence only; it does not establish operational approval or production activation.

## Phase 1: Foundation — READY

- [x] T001 [IMPLEMENTED+VERIFIED] Additive category allocation, invitation, free registration and ticket-source migration; clean PostgreSQL migration chain passed.
- [x] T002 [IMPLEMENTED+VERIFIED] Paid compatibility and explicit free/complimentary zero-price/quota validation; feature suite passed.
- [x] T003 [IMPLEMENTED+VERIFIED] Invitation issue/revoke routes require event-scoped authority; issue uses the new Identity-owned typed active-CUSTOMER lookup. No token-list API exists. Complimentary-allocation approval remains a separate production deny gate (T006).

## Phase 2: US1 — Configure complimentary capacity — READY for development; production approval BLOCKED

- [x] T004 [IMPLEMENTED+VERIFIED FOR DEVELOPMENT] Hashed and encrypted-at-rest recipient-bound invitations, expiry/use limits, event-scoped issue/revoke and audit; production recipient verification uses Identity, while actual issue/use remains blocked by missing complimentary-allocation approval (T006).
- [x] T005 [IMPLEMENTED+VERIFIED] Recipient mismatch, expiry, exhaustion, revocation, safe failure and preservation of already-issued tickets covered by Events feature tests.
- [ ] T006 [DEVELOPMENT EVIDENCE PORT IMPLEMENTED; PRODUCTION AUTHORITY BLOCKED] Project owner accepted the scoped model for development. Typed evidence now validates exact organisation/event/category scope, requester/approver separation, approval ID/version/provenance, quota, validity and revocation; invitation, registration and publication snapshots persist the evidence. Production remains bound to `DenyComplimentaryAllocationApproval` because the authoritative Governance-owned verifier/provider and operational authority evidence are absent.
- [ ] T007 [DEVELOPMENT RULE IMPLEMENTED; PRODUCTION GATE CLOSED] Project owner accepted a free-only development publication rule. EventWorkflowService allows a current-version event review without Finance price approval only when all categories are explicitly free/complimentary at authoritative zero price; complimentary categories still require T006 approval; paid categories retain distinct event/Finance approvals. Successful publication leaves sales paused. Production EventPublicationReadiness remains deny-bound; no live publication route or binding was enabled.

## Phase 3: US2 — Register and issue free entitlement — READY

- [x] T008 [IMPLEMENTED+VERIFIED] Atomic idempotent registration, invitation use/capacity, and one Events-owned free entitlement; no payment or fake capture.
- [x] T009 [IMPLEMENTED+VERIFIED] Authenticated API derives actor from Identity context and accepts no amount, currency, tenant or payment outcome from the client.
- [x] T010 [IMPLEMENTED+VERIFIED] Feature tests cover duplicate request, expired/used/mismatched invitation, sold-out capacity, per-person limit, no financial effects, admission and reporting.
- [x] T011 [IMPLEMENTED+VERIFIED] Separate-session PostgreSQL tests cover last-capacity, invitation use-limit, same-request replay, injected rollback/retry, explicit revocation-first and redemption-first serial orders, concurrent revocation/redemption, and replay after later revocation. Event-row-first lock serializes both operations; successful registration is not retroactively invalidated.

## Phase 4: US3 — My Tickets and online admission — READY for development; reviews outstanding

- [x] T012 [IMPLEMENTED+VERIFIED FOR DEVELOPMENT] Reused purchaser-scoped My Tickets and exact online gate admission; only persisted, registration-linked free tickets bypass payment/refund lookup.
- [x] T013 [IMPLEMENTED+VERIFIED] Free ownership, status/category-only response, attendance/audit, no refund lookup for free tickets and paid regression coverage passed.
- [ ] T014 [DEFERRED FOR THIS RELEASE BY PROJECT-OWNER SCOPE DECISION] P26 uses online-only gate validation; offline validation remains denied. This is a release-scope decision, not offline implementation or Security/Operations approval. Keep the criterion unchecked until repository scope-change governance accepts the exclusion and online loss/retry denial evidence is recorded.
- [ ] T015 [BLOCKED BY SPECIALIST CONTRACT] Project owner accepted containment: no automatic issuance/refund or terminal cancellation for unknown financial outcomes. Finance/Payments/Ledger must define unused-ticket eligibility/deadlines, fees/tax/provider charges, cancellation-cost allocation and late-capture recovery before paid-event cancellation can transition terminally.
- [ ] T016 [PENDING GOVERNANCE APPOINTMENT AND INDEPENDENT REVIEW] Project owner states Governance appoints independent Security and Privacy reviewers. No actual appointments, independence/conflict records or signed reviews are present.

## Phase 5: US4 — Aggregate reporting — READY for development

- [x] T017 [IMPLEMENTED+VERIFIED FOR DEVELOPMENT] Aggregate report adds free registrations, paid ticket sales, unique admissions and event-end no-shows, without identities.
- [x] T018 [IMPLEMENTED+VERIFIED] Tests cover aggregate-only fields, pre-event no-show finalization and post-event free-registration/admission/no-show counts.

## Phase 6: Interfaces, operations and verification — DEPENDENT / READY portions

- [x] T019 [IMPLEMENTED+COMPONENT VERIFIED] Admin issue/revoke invitation controls and customer registration form; customer retries reuse their idempotency key for unchanged input. Focused Admin interaction/a11y and customer widget tests pass; no browser/device end-to-end journey is claimed.
- [ ] T020 [BLOCKED BY T015 AND NOTIFICATION CONTRACT] No authoritative cancellation event, approved recipient/template/privacy contract or delivery reconciliation evidence is available. Do not emit cancellation notices or claim delivery from queue state.
- [x] T021 [IMPLEMENTED] Flow/operator runbook added for invitation issue/revoke, free registration, admission and aggregate reporting.
- [x] T022 [IMPLEMENTED+VERIFIED FOR EXECUTED CHECKS] Clean isolated migration, Events/Identity tests, T011 concurrency evidence, Events/Identity PHPStan, Events Pint, full Customer Flutter analysis, focused Flutter auth/transaction/event tests, and Admin component/a11y/lint/type checks passed. The 3 Identity PHPStan findings, Events Pint issue and 33 Flutter findings are resolved. T023 browser journey is separately verified; governance/P25 lifecycle and native-device gates remain open.

## Phase 7: Remediation and journey evidence

- [x] T023 [IMPLEMENTED+VERIFIED — ISOLATED CHROMIUM AND NATIVE SCOPE] The guarded Chromium journey remains verified. The 2026-11-03 same-run native journey passed Customer challenge/PIN login, free registration and purchaser tickets, plus Agent login, wrong-gate denial, exact-gate admission and repeat-scan denial. Its persisted assertion included Wallet captures and all requested payment/ledger/payout tables; see latest evidence. Synthetic fixture boundaries remain. Camera capture and native Admin/aggregate-report UI were not exercised.
- [ ] T024 [REVIEW PACKAGE PREPARED; GOVERNANCE APPOINTMENT AND SIGN-OFF PENDING] Governance is identified by the project owner as the appointing authority, but no actual reviewer names/appointments, independence records, findings or signed outcomes are present.

## 2026-11-03 typed T006 and current-source regression

- [x] T006-D [DEVELOPMENT CONTRACT FOUNDATION IMPLEMENTED+VERIFIED] Introduced typed `ComplimentaryAllocationApprovalEvidence`; registration/invitation and free-only publication validate scope, non-self approval, quota, validity and revocation. Approval evidence snapshots persist on invitations, registrations and the versioned publication snapshot. Ordinary `free` categories do not invoke T006. These are contract/test fixtures only; no Governance authority/provider, operational grant or production binding was created.
- [x] T007-D [DEVELOPMENT WORKFLOW IMPLEMENTED+VERIFIED] Project-owner accepted development rule is implemented in the internal workflow service; current event review and zero-price checks remain, complimentary categories require T006 evidence, paid categories retain distinct event and Finance review, and publication leaves sales paused. Paid-category edits invalidate approvals. Production readiness still denies publication.
- Verification: the guarded `run-p26-browser-e2e.ps1 -EventsFoundationRegression` applied the additive snapshot migration and passed CORS 3/52, Identity MFA 10/70, Events foundation 77/556. Pint `--test` passed the 10 changed PHP files. Local PHP 8.3 was rejected by Composer's PHP >=8.4.1 platform check; scoped PHPStan under PHP 8.4.15 was still running at checkpoint.
- Open gates: T006 production authority/verifier and independent review; T007 organiser verification and Finance/release/production gate; T014 formal scope-change evidence and any required connectivity-loss evidence; T015/T020 specialist lifecycle/financial/notification contract; T016/T024 actual Governance appointments and signed independent reviews. T023's documented browser and native criteria are verified; camera capture and native Admin/aggregate-report screens remain outside the executed evidence. No production or P24 gate changed.

## Dependency order

T001 → T002/T003 → T004/T005 → T008/T009/T010/T011 → T012/T013 → T017/T018 → T019 → T021/T022. T006/T007/T014/T015/T016/T020 remain blocked as described and do not prevent the independent zero-price registration and online-admission implementation.

## Traceability

- FR-001/002/003 → T001–T007 → invitation/allocation tables, Events authoring/invitation APIs, Identity active-CUSTOMER contract, T005/T011; T006/T007 remain blocked by their separate approval decisions.
- FR-004/005/006/013 → T008–T011 → registration/ticket tables and atomic registration service, T010/T011.
- FR-007/008/012 → T012–T016 → existing ticket/admission path, T013; offline and cancellation gates remain blocked.
- FR-009/010 → T017–T018 → aggregate report, T018.
- FR-011 → T015/T020 (blocked by P25 cancellation/notification lifecycle).
- FR-014 → T006/T007/T016 plus existing P25 deny bindings; no production activation.

## Execution notes

- T001–T005, T008–T013, T017–T019, T021–T023 have scoped development evidence. T011 has separate-session PostgreSQL evidence for revocation/redemption race orderings and replay-after-revocation. T023 full isolated Chromium journey and final PostgreSQL assertions are verified; native-device evidence and the independent/operational gates remain separate.
- Test fixtures are development-only and do not satisfy grants, complimentary approval, independent review, or activation gates.
- Latest finding dispositions, exact commands and all verification counts are in `evidence.md` and `remediation-register.md`. Identity/Events combined PHPStan, T027 Pint, full Customer/Agent Flutter analysis, T023 Chromium and the scoped native Customer/Agent event journey are verified. Independent review, unresolved approvals/lifecycle contracts and operational activation remain open.
- No deployment, live publication/sales, bootstrap, P18, or P24 work is in scope.

## 2026-11-02 continuation dispositions

- [x] T025 [IMPLEMENTED+VERIFIED] Resolve the Agent Flutter analyzer findings and correct dashboard/inbox API contracts. The full Agent analyzer is clean; Agent tests pass 99 tests. The dashboard endpoint is implemented from the existing P032 contract with Identity-derived ownership and existing permissions; unconfigured threshold state fails closed. Notification clients use the existing recipient-scoped inbox API and DTO.
- [x] T026 [IMPLEMENTED+VERIFIED] Isolated T023 Chromium journey rerun after the Agent API changes. Focused Customer PIN login and the Admin/customer/Agent journey passed (2/2 Playwright tests); final PostgreSQL counts matched 3 registrations, 3 tickets, 1 attendance, 1 admission audit, 3 free-issuance audits, 5 invitation audits and zero financial records. Native Android remains unverified.
- [x] T027 [IMPLEMENTED+VERIFIED] Pint 1.30.5 runs when the isolated API container’s app-specific `APP_CONFIG_CACHE` is overridden for the Pint process. Formatted the Agent dashboard controller/route/test and `pint --test` passed all 3 files; dashboard regression passed 4 tests/13 assertions. No dependency or lockfile change.

## 2026-11-02 native scaffolding continuation (superseded by latest verification below)

- Agent Android project lacked Gradle settings, wrapper, wrapper properties and Gradle properties in both available repository-history snapshots. Restored those files from the working Customer project and aligned Agent Kotlin/Java toolchain DSL while preserving its application ID, manifest, Firebase configuration and minSdk. Wrapper version check passed (Gradle 9.3.1, JDK 17).
- At this checkpoint, native APK build and native journey were not verified. The subsequent verification below supersedes that status.

The 2026-11-03 project-owner decisions supersede earlier unanswered-decision text below. T006 has an accepted development model but lacks verifiable approval evidence/provider; T007 development workflow is implemented while production readiness remains deny-bound; T014 is proposed as a release exclusion pending scope-change governance; T015/T020 remain blocked by specialist financial/lifecycle/notification contracts; T016/T024 await Governance appointments and actual independent reviews. T023's latest strict same-run Wallet-capture assertion remains unverified due the offline emulator.

## 2026-11-02 native verification continuation

- Agent Android debug APK build: **PASS**, `flutter build apk --debug --dart-define=API_BASE_URL=http://10.0.2.2:8100`; output `wallet-ecosystem/apps/agent/build/app/outputs/flutter-apk/app-debug.apk`. It used the existing JDK 17/Gradle 9.3.1 toolchain and Agent-only `printing` compileSdk 36 override. The build emitted AGP/Kotlin plugin and Java 8 dependency deprecation warnings, not build errors.
- Customer native login/PIN integration: **PASS, 2 tests**, run by `run-p26-browser-e2e.ps1 -NativeCustomerLogin` on `emulator-5554` against the runner-verified isolated API URL `http://10.0.2.2:8100`. It obtained a real challenge, submitted `/v1/customer/auth/login/verify`, reached authenticated state and made the expected wallet request. The initial run failed because its selector typed PIN text into the phone field; the test now selects the numeric PIN `TextField`. This was a test-selector defect; no application authentication behavior was changed.
- Full isolated Chromium journey in the same successful runner invocation: **PASS, 2 Playwright tests**. PostgreSQL final assertions were `3|3|1|1|3|5|0|0|0|0|0|0` (registrations, tickets, attendance, admission audits, free-issuance audits, invitation audits, payment intents, attempts, refunds, journals, payout records, settlement batches). The runner removed only the successful per-run database.
- Customer `flutter analyze --no-pub`: **PASS**, no issues (226.5s). Agent `flutter analyze --no-pub`: **PASS**, no issues (254.4s). `git diff --check`: **PASS**, with line-ending conversion notices on existing mixed-ending files.
- Android SDK license acceptance was not invoked. The existing emulator was discoverable (`emulator-5554`, state `device`); the build and test used installed SDK components. No Agent gate UI/full native event journey was executed, so T023 native coverage remains partial.

## 2026-11-02 full native event journey continuation (supersedes native-not-run statements above)

- [x] T023 native Customer/Agent journey: **PASS** with `.\specs\1002-free-invitation-complimentary-events\run-p26-browser-e2e.ps1 -NativeOnly -NativeEventJourney`. The runner created and verified disposable database `wallet_ecosystem_p26_e2e_20261102_b645e29f`, routed both Android apps only to `http://10.0.2.2:8100`, and removed only that successful per-run database.
- Customer native UI: authenticated through the real challenge/PIN flow, opened Events registration, submitted one free registration and viewed My Tickets/credential screen. Agent native UI: authenticated, opened Event Gate, manually entered fixture credential, saw wrong-gate denial, admitted at the assigned gate, then received repeat-scan denial. The credential entry used the existing supported manual-entry control; camera capture was not exercised. Setup identities, publication/approval state, assignments and the second ticket were synthetic database fixtures; test PINs/secrets are not recorded here.
- Two integration tests passed (one Customer journey and one Agent gate journey). Persisted end-state was `2|2|1|1|1|0|0|0|0|0|0` for registrations, tickets, attendance, admission audit, free-issuance audit, payment intents, payment attempts, refunds, journals, payout records and settlement batches. This includes one seeded registration/ticket plus the Customer-created registration/ticket; only the seeded ticket was admitted once. No duplicate admission or financial effect occurred.
- Full `flutter analyze --no-pub`: Customer **0 issues** (248.7s), Agent **0 issues** (192.8s). Runner CORS and MFA suites passed **3 tests/52 assertions** and **10 tests/70 assertions**. Native build emitted non-fatal Kotlin Gradle Plugin migration warnings. `git diff --check` passed; Git emitted existing line-ending conversion notices.
- T023 native scope is verified for login, registration, purchaser access, exact-gate admission, wrong-gate denial and repeat scan. Native camera capture, native organizer aggregate-report UI and native Admin invitation UI were not run; aggregate/Admin UI remain covered by the isolated Chromium journey. Android SDK licenses were not accepted or changed.
- T006/T007/T014/T015/T016/T020/T024 remain blocked or pending for their already documented policy, shared-contract and independent-review reasons. Production bootstrap, publication, sales, deployment, P18 and P24 remain disabled.

- Final continuation update: scoped PHPStan under PHP 8.4.15 passes Events with 0 errors after correcting the E2E fixture provider's undefined success constant. Final isolated suite after that source correction passes CORS 3/52, MFA 10/70, Events 77/556. Pint --test passes 11 touched PHP paths. The local PHP 8.3 and cache-inherited Larastan attempts are superseded setup failures, not current source findings.

T007 remaining exact dependency: current `EventPublicationReadiness` production binding denies all publication and no owner contract supplies authoritative organiser verification evidence. The internal service tests establish zero-price/category/review behavior only with explicit fixtures. Do not expose production publication; an Events/Identity onboarding owner must define the authoritative verification source and the Events readiness implementation must consume it, with evidence-bound tests, before this acceptance criterion can pass.

T023 rerun passed current-source full Chromium journey (2/2 tests) using the dynamically selected isolated API port. Persisted state matched the documented 3-registration/3-ticket counts and zero Payment/refund/Ledger/payout/settlement records. No retained container/database was removed. The strict native Wallet-capture assertion is still open; emulator `emulator-5554` is offline. Do not equate browser free-flow financial assertions to the native capture query.

## 2026-11-03 native same-run capture verification (supersedes open T023 capture note)

- Project owner approved a non-wiping restart of the existing `Pixel_10` test emulator. ADB then reported `emulator-5554` as `device`, `getprop sys.boot_completed` returned `1`, and `flutter devices --machine` identified Android 17/API 37. No AVD data was wiped and no SDK licenses were accepted.
- Exact harness command: ` .\specs\1002-free-invitation-complimentary-events\run-p26-browser-e2e.ps1 -NativeOnly -NativeEventJourney`. Fresh isolated database `wallet_ecosystem_p26_e2e_20261103_d3fc5751` was checked against the runner naming guard and runtime database identity endpoint before migrations. The runner selected a per-run API port and both apps used its emulator bridge endpoint; neither used the shared development API at port 8000.
- Customer native UI passed real phone challenge/PIN verification, free registration, My Tickets and credential display. Agent native UI passed login, wrong-gate denial, exact assigned-gate admission, and repeat-scan denial using the supported manual-entry field. Camera capture was not tested.
- Same-run persisted counts passed exactly: `2|2|1|1|1|0|0|0|0|0|0|0` = registrations, tickets, attendance, admission audit, free-issuance audit, Payment intents, Payment attempts, Wallet captures, refunds, Ledger journals, payout records and settlement batches. The two registrations/tickets are one seeded fixture plus one Customer UI registration; one admission/audit is for the seeded ticket. All financial/capture counts are zero.
- The runner removed only this successful run's API container/image and exact-name disposable database. Earlier failed run databases remain preserved. One earlier native run failed because Agent test startup was not awaited; the test now awaits `app.main()`. A following Customer challenge attempt lacked a challenge but the latest run completed the real verify request and passed; no persistent backend defect was reproduced.
- Post-fix full analysis: `flutter analyze` in `wallet-ecosystem/apps/customer` — PASS, 0 issues (232.2s); in `wallet-ecosystem/apps/agent` — PASS, 0 issues (217.5s). Existing nonfatal Gradle/Kotlin plugin and Java native-access warnings remain. T023 is verified for its documented native online/manual-entry scope; T026 remains partial overall due T006/T007/T014/T015/T020 and independent review gates.
