# P26 Acceptance Matrix

Status: **PARTIAL**. Development registration, online admission and aggregate reporting are verified. Operational approval, P25 cancellation/offline lifecycle and independent reviews remain open. This matrix does not grant publication or production activation.

| Requirement | Result | Evidence / remaining gate |
|---|---|---|
| FR-001 explicit paid/free/complimentary category types and zero-price separation | PASS | Additive P26 migration and `EventDraftService`; existing paid categories remain explicitly paid; authoring tests pass. |
| FR-002 capacity/quota, invitation scope and minimal attendee data | PARTIAL | Capacity, quota, per-person limit and recipient-bound records are implemented. Identity active-CUSTOMER lookup is integrated. Production complimentary-allocation authority/evidence remains missing; T006 stays denied. |
| FR-003 opaque, scoped, expirable, revocable, use-limited invitation | PASS for development | Hash lookup, encrypted authorized-issuer recovery, event/category/recipient/expiry/use checks, audit and customer identity contract. No production issue/use until T006 approval evidence exists. |
| FR-004 atomic validation, capacity allocation and entitlement | PASS for development | Event then category then invitation row locking; registration, ticket, counters and audit commit in one PostgreSQL transaction. T011 race cases passed. |
| FR-005 idempotent registration | PASS | Concurrent same-key requests and replay return one stored registration/ticket. Historical replay after later invitation revocation returns that same entitlement and is not a new redemption. |
| FR-006 no fabricated Payment/Wallet/Ledger/settlement/refund effects | PASS for tested development path | Persisted before/after counts for Payment intents/attempts/refunds, Wallet captures, Ledger journals and payout settlements unchanged by free registration and replay; no financial dependencies in service. |
| FR-007 purchaser-only My Tickets and existing gate controls | PASS for development | P25 purchaser ticket API and online gate service reused; customer registration widget and admin controls tested. Independent review remains open. |
| FR-008 only authoritative free source bypasses payment/refund checks | PASS | Ticket must match persisted registration/event/organisation/category/owner/source; paid payment/refund checks remain unchanged. |
| FR-009 separate free registrations, paid ticket sales, admissions and no-shows | PASS for development | Aggregate-only category report; tests cover pre/post-event count behavior and no identity/ticket leakage. Paid sales counts tickets, not revenue. |
| FR-010 no settlement/refund creation for free ticket | PASS for tested development path | Service has no payment/refund dependency; persisted financial counts remain unchanged. |
| FR-011 cancellation invalidation and notices | BLOCKED | P25 cancellation transition and ticket invalidation contract, notice template, durable retry and delivery/reconciliation evidence remain incomplete. No P26 terminal cancellation endpoint is exposed. |
| FR-012 offline admission | BLOCKED / unavailable | No approved P25 offline scanner policy, freshness/revocation, duplicate handling or reconciliation contract. API remains online-only and fail-closed. |
| FR-013 safe expiry/use/sold-out failures | PASS | Feature and PostgreSQL tests cover mismatch, expiry, exhaustion, revocation, sold-out and rollback; denied attempts do not write registration/ticket or financial rows. |
| FR-014 production activation denied by default | PASS - gate preserved | Complimentary approval contract is deny-bound; P25 publication/Finance and sales gates remain; production bootstrap/live processing/deployment and P24 stay disabled; Security/Privacy reviews remain pending. |
| E2E-01 integrated browser/device journey | PASS for isolated Chromium and documented native Customer/Agent scope | Chromium full journey remains verified. The 2026-11-03 same-run journey on `emulator-5554` passed real Customer challenge/PIN, free registration and purchaser ticket access; Agent login, wrong-gate denial, exact assigned-gate admission and repeat-scan denial. Same-run PostgreSQL counts were `2|2|1|1|1|0|0|0|0|0|0|0`: registrations, tickets, attendance, admission audit, free-issuance audit, Payment intents/attempts, Wallet captures, refunds, journals, payout records and settlements. All approvals/assignments are synthetic fixtures. Native camera capture and native Admin/aggregate-report UI were not exercised; the latter interactions remain covered by Chromium. |
| Remediation: Identity JWT/OpenSSL, Events Pint and customer Flutter diagnostics | PASS | Three scoped PHPStan findings and one Events Pint issue fixed; final scoped PHPStan/Pint pass. All 33 prior Flutter findings resolved; full `flutter analyze` has no issues. Exact before/after evidence in `evidence.md`. |

## Definition of Done

- Development data model, registration API and online admission/reporting slice: **PASS** for scoped development evidence.
- PostgreSQL allocation, revocation/redemption ordering, replay and rollback acceptance: **PASS**; see exact commands/results in `evidence.md`.
- Customer/admin component interactions and accessibility checks: **PASS** at widget/component scope. T023 isolated Chromium is **PASS**. Native Customer login/registration/My Tickets and Agent gate journey are **PASS** for the documented manual-entry scope.
- Isolated browser/native harness safety/setup: **PASS** for unique disposable DB creation, migrations, runtime DB identity, fixture guards and explicit emulator API routing. Full integrated Chromium journey and persisted assertions: **PASS**. Native Customer/Agent tests and same-run persisted Events/admission plus Payment, Wallet capture, refund, Ledger, payout and settlement assertions: **PASS**. Camera capture and native Admin/aggregate-report UI are not verified; Admin invitation/report screens are browser-verified.
- Cancellation notices and offline admission: **FAIL/BLOCKED** by named P25 policies/contracts.
- Production complimentary approval and free-only publication: **FAIL/BLOCKED** by missing approval authority/evidence and free-only Finance/publication decision.
- Independent Security/Privacy review and operational acceptance: **FAIL/PENDING**.
- P26 complete: **NO**; status remains **PARTIAL** because owner/specialist policy, lifecycle contract, independent review and activation criteria remain open.

## Agent continuation checks (2026-11-02)

| Criterion | Result | Evidence / remaining gate |
|---|---|---|
| Agent dashboard and notification API paths | PASS for development behavior | Dashboard implements the existing P032 contract with authenticated Agent ownership and permission checks; unconfigured threshold returns 503. Notification client follows the existing scoped inbox API. Dashboard tests: 4 passed/13 assertions; full Agent suite: 99 passed. |
| Full Agent static analysis | PASS | Before: 21 diagnostics. After: `flutter analyze --no-pub` reports no issues. |
| Android toolchain and native smoke | PARTIAL | Agent debug APK build passed with JDK 17/Gradle 9.3.1. Customer integration login/PIN smoke passed 2/2 on `emulator-5554`, using isolated P26 API at `10.0.2.2:8100`; authenticated app made a wallet request. No Agent gate UI or full Customer event journey ran natively. No SDK license acceptance command was executed. |
| New dashboard API Pint check | PASS | Locked Pint 1.30.5 succeeds with the isolated Laravel app’s `APP_CONFIG_CACHE` overridden for the formatter process. Three dashboard PHP files were formatted and `pint --test` passed; dashboard API regression passed 4 tests/13 assertions. No dependency/lock change. |

## 2026-11-03 project-owner decision and implementation delta

| Criterion | Current disposition | Evidence / remaining gate |
|---|---|---|
| T006 / FR-002, FR-003 | Development approval model accepted; production blocked | The contract is still a fail-closed void assertion without verified approval ID/version/provenance, authority lookup, per-allocation approval limits/validity or revocation evidence. Production binding remains `DenyComplimentaryAllocationApproval`. |
| T007 / FR-014 free-only publication | Development workflow implemented and focused-tested; production gate closed | Free-only means non-empty categories all typed `free` or `complimentary` and authoritative `price_minor === 0`; current event-version reviewer required; complimentary categories call the T006 contract; paid events retain distinct Finance/event review; publication pauses sales. The production readiness binding denies publication. Paid-category edit increments version, pauses sales and resets both approvals. Four focused tests passed (14 assertions). |
| T014 / FR-012 offline | Project-owner release deferral selected; formal scope-change acceptance and denial verification pending | Online-only remains enforced. Offline is not implemented; disconnected scanners are not claimed safe. Governance/release-owner scope-record evidence is still needed before marking the criterion excluded. |
| T015/T020 / FR-011 | Blocked | Project owner accepted containment for unknown financial outcomes. No terminal cancellation or cancellation notice endpoint is exposed until the P25 cancellation event, Payments/Ledger disposition, recipient/privacy/template and Notification delivery-reconciliation contracts are supplied. |
| T016/T024 | Pending | Governance is the appointing authority per project-owner response. Actual independent reviewer appointments, conflict records and signed Security/Privacy outcomes are absent. |
| E2E-01 strict same-run Wallet capture absence | PASS (2026-11-03) | Fresh guarded native Customer+Agent journey asserted `wallet.wallet_capture_records` together with Payment/Ledger/payout/settlement tables; all were zero. Exact counts and failed-run history are in `evidence.md`. |

## 2026-11-03 typed T006 verification delta

Project-owner decisions were applied to development workflow only. The typed evidence port rejects wrong organisation scope, insufficient quota, self-approval, expired and revoked evidence before registration writes. Tests verify approval snapshots on invitation, registration and publication; ordinary free allocations do not require T006. Fresh guarded PostgreSQL regression applied migration `2026_11_03_100001_add_complimentary_approval_snapshots.php`; CORS 3/52, MFA 10/70, Events 77/556 passed. Pint `--test` passed 10 changed PHP files. No live approval service, production grant, publication or sales activation was created.

T007 implementation boundary: `EventWorkflowService::publish` delegates publication eligibility to `EventPublicationReadiness`; the registered production implementation is deny-by-default. There is no authoritative organiser-verification evidence provider/contract in the current Events/Identity contracts. The accepted development rule's organiser-verification condition is therefore not production-implementable or operationally exercisable; only the internal zero-price/review/complimentary/paid-category transition logic is verified using test-only readiness fixtures. Keep the public publication path and live publication closed until the authoritative organiser-verification and Finance/release conditions are supplied.

Latest T023 reruns (2026-11-03): the full current-source Chromium journey passed 2/2 tests and persisted counts `3|3|1|1|3|5|0|0|0|0|0|0`. The same-run native Customer+Agent journey later passed on `emulator-5554` with persisted counts `2|2|1|1|1|0|0|0|0|0|0|0`, including zero Wallet captures. The harness uses a unique database, runtime identity guard and run-specific API port; successful-run resources were cleaned by exact name and earlier failed runs were preserved. Browser does not query Wallet capture records; the native assertion covers the free journey only.
