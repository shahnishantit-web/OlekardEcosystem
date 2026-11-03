# P26 Research and Task Classification

**Date**: 2026-10-30  
**Package**: P26 — Free invitation and complimentary event journey  
**Source**: `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx`, verified embedded version 1.1 (19 October 2026); extracted text `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.txt`, LC08 lines 439–449. Managed prompt: `docs/sources/OleKard_Managed_Prompts_v1.0.txt`, P26 section.

## Readiness Audit

The existing P25 artifacts were inspected (`specs/072-paid-events-ticketing/acceptance-matrix.md`, `evidence.md`, `restart.md`, `tasks.md`, and `flow-runbook.md`) alongside actual Events services, routes, migrations, tests, P11 outbox and Notification service contracts. P25 is PARTIAL, not production-ready. E2E-01 is limited to the documented development Wallet path and is not evidence of production publication or external provider behavior.

| P26 task/requirement | Class | Existing evidence that satisfies part | Exact remaining dependency/blocker | Independent work |
|---|---|---|---|---|
| T001/T002 additive category model and paid compatibility | IMPLEMENTED+VERIFIED | Events owns event categories and existing paid ticket model | None for development; production publication remains gated by P25 | Preserve `paid` backfill and use explicit `free`/`complimentary` types; migration and feature suite passed. |
| T003/T004 invitation issue/revoke, recipient, expiry, use count and audit | IMPLEMENTED+VERIFIED | P25 event-scoped permission/assignment and Events audit patterns; Identity now exposes typed `findActiveCustomerActorId` and Events uses it | Complimentary-allocation approval authority/evidence remains absent; production approval contract still deny-bound | Identity returns only actor ID for active CUSTOMER; tests cover missing, inactive, wrong-role and unavailable results. No direct Identity table query from Events. |
| T005 invitation denial and issued-ticket invariants | IMPLEMENTED+VERIFIED | LC08 requires safe expiry/use failures and says invitation revocation does not revoke an issued ticket | None for T005 development semantics; T006 operational approval remains independent | Feature and separate-session PostgreSQL tests verify mismatch/expiry/revocation/exhaustion and issued ticket remains valid. |
| T006 complimentary allocation authority | BLOCKED; deny binding implemented | LC08 says allocation must be approved but does not specify the authority or evidence contract | Business/governance owner and verifiable approval artifact are not identified | Production issue and registration call a typed contract bound to 503 deny; tests override only with labelled fixtures. |
| T007 free-only publication | BLOCKED | Existing P25 review and Finance price approval are the only established lifecycle gates | No approved free-only publication/review exception or authority | Keep free-only publication denied; do not treat a paid category in a mixed event as approval for the free category. |
| T008-T011 atomic free registration, idempotency and invitation concurrency | IMPLEMENTED+VERIFIED | P25 event/category locking, existing credential vault, LC08 zero-payment boundary | No remaining T011 development blocker; production approval gates are separate | Separate-session PostgreSQL tests cover last capacity, invitation use count, same-key replay, rollback/retry, revoke-first, redeem-first, synchronized competing race, and replay after later revocation. |
| T012/T013 purchaser ticket access and online admission | IMPLEMENTED+VERIFIED FOR DEVELOPMENT | P25 purchaser-scoped My Tickets, exact event/gate permission+assignment and attendance/audit | Independent Security/Privacy review is pending | Free source bypasses Payment/refund lookup only after persisted registration/ticket correspondence; paid checks are preserved. |
| T014 offline admission | BLOCKED | P25 online-only fail-closed behavior | No approved offline policy, scanner manifest/revocation or reconciliation contract | Keep offline validation unavailable. |
| T015/T020 cancellation, ticket revocation and notices | BLOCKED | Shared notification/outbox exists; P25 event cancellation lifecycle is not complete | Missing approved cancellation transition, ticket invalidation contract, notice template and delivered-status reconciliation | Invitation revocation is separate and audited; no cancellation/revocation notice is claimed delivered. |
| T016 independent Security/Privacy review | BLOCKED | Review index can be prepared from implementation/evidence | No independent reviewer appointment/evidence in repository records | Do not self-approve; retain activation gate. |
| T017/T018 aggregate reporting | IMPLEMENTED+VERIFIED FOR DEVELOPMENT | P25 aggregate-only privacy boundary; event-end timestamp is authoritative | No additional dependency for implemented counts; report is not a revenue/settlement view | Counts and identity absence are tested. |
| T019 interfaces; T021/T022 operations and verification | IMPLEMENTED+SCOPED VERIFIED | Existing Admin/customer app conventions and reused My Tickets/gate controls | No browser E2E; UI is a bounded development surface | Scoped lint/analyze/widget tests passed; runbook and evidence are recorded. |

## Source Interpretation

- LC08 distinguishes free and complimentary source types and states that a zero-price registration is not a captured payment.
- “Approved complimentary allocation” is an external approval prerequisite; LC08 gives no role, evidence object, or permission name for that approval. No operational authority is inferred.
- Invitations are eligibility grants, not public authorization tokens. Expiry/use failures must not disclose attendee lists.
- Revoking an invitation does not by itself revoke an already-issued ticket.
- Event cancellation is described as revoking entitlements and notifying registrants without a zero-value refund. P25’s cancellation workflow is incomplete, so this acceptance remains blocked pending the owning lifecycle implementation.
- The source allows only an approved offline mode; P25 currently has none. P26 remains online-only.
- Free registrations, paid revenue, admissions, and no-shows are separate measures; no settlement/refund is generated for free entitlements.

## P25 Implementation Evidence and Limits

- `EventInventoryHoldService` locks event then category and counts held quantity plus `issued_quantity`; free registration must participate in this same invariant, not create parallel capacity.
- `event_tickets` is the existing domain credential/attendance anchor, but currently requires `order_id`/`order_line_id`. P26 needs an explicit mutually-exclusive free-registration source without fake paid orders.
- `EventAdmissionService` validates exact active gate and assignment, locks ticket, writes attendance and audit atomically, and returns only validation status/category. It currently resolves a Payment intent and refund eligibility for every ticket; branch only for authoritative free entitlements.
- Purchaser ticket views are actor-scoped and no-store; re-use them for free tickets.
- Current aggregate P25 report is identity-free and category grouped, but only counts admission.
- Existing offline/re-entry/transfer controls remain unavailable. No new policy is inferred.
- P25 uses production deny bindings for pricing/publication and has pending independent Security/Privacy review. P26 test fixtures do not alter those gates.

## Implementation and Verification Update — 2026-10-30

- P26 was created with the installed Spec Kit feature scripts as `specs/1002-free-invitation-complimentary-events`; `spec.md`, `plan.md`, `tasks.md`, `data-model.md` and `contracts/free-registration.md` reflect LC08 ownership and the recorded P25 dependencies. Task classifications were made before code changes and are now reconciled in `tasks.md`.
- The accessible source is exactly `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx`; internal document text identifies Version 1.1 dated 19 October 2026. The extracted copy confirms LC08 at lines 439–449. It was not replaced with another version.
- The supported schema bootstrap and full migration chain completed on isolated PostgreSQL 15.19 database `wallet_ecosystem_p26_clean_20261030`; P26 migration `2026_10_30_100001_add_free_event_entitlements` ran successfully. No legacy development database or migration history was changed.
- The first complete `EventDraftFoundationTest` run exposed a timestamp-comparison issue in invitation redemption and the updated aggregate report expectation. Both were corrected. The final Events feature suite passed **71/71 tests (511 assertions)**; exact commands and the earlier in-scope fixes are in `evidence.md`.
- Scoped PHPStan initially identified a nullable invitation row in revocation and an incorrect schema-builder query in the rollback guard. Both were fixed; the final repository-configured Events analysis completed 62 files with zero errors. No baseline or ignore was changed.
- A real separate-session PostgreSQL run verified last-unit capacity, invitation-use limit, same-request replay, rollback/retry, explicit revoke-first and redeem-first orders, a synchronized competing race, and same-key replay after later revocation. Each worker reported a distinct PostgreSQL backend PID. The observed competing race serialized redemption then revocation; both legal serial orders were asserted explicitly.
- Identity-owned typed active-CUSTOMER lookup is implemented and bound to Events. It returns the requested actor ID only for an active CUSTOMER; missing/inactive/wrong-role returns null and infrastructure failure yields a generic fail-closed 503. Feature tests use the real Identity implementation against isolated PostgreSQL. Complimentary-allocation approval remains independently deny-bound.
- Complimentary invitation issue and registration also use a typed `ComplimentaryAllocationApprovalContract`, bound in production to a 503 deny implementation. This prevents a mixed paid/free event from making an unapproved free category registrable. Tests override it only with explicitly development-only fixtures.
- P25 dependency status remains PARTIAL: cancellation notices require the P25 cancellation/ticket-revocation lifecycle and durable notification delivery evidence; offline scans remain unavailable; free-only publication still has no approved Finance review exception. Independent Security/Privacy review is pending and no reviewer is appointed in repository records.
- Customer free-registration and My Tickets surfaces are development UI only. The form reuses its idempotency key for retries with unchanged values and resets after a field edit. Admin supports invitation issue/revoke; revocation clears the displayed secret. Admin interaction/axe and Flutter widget tests passed; no browser/device end-to-end run was performed.
- Existing production gates remain unchanged: no production bootstrap, complimentary approval grant, publication, paid/live sales, deployment, P18 or P24 activation.

## Technical Decisions (not policy approvals)

1. Free registrations are Events-owned and committed in the existing `community_services` PostgreSQL transaction.
2. Event row then category row then invitation row form the lock order, matching P25 inventory behavior and adding invitation use limits to the same transaction.
3. A category's allocation type is explicit; existing categories backfill to `paid`. A zero price is not sufficient to classify an entitlement as free.
4. An issued ticket is explicitly either paid (order/order-line source) or free/complimentary (free-registration source). There is no ambiguous or payment-free paid ticket shape.
5. Free registration does not lock event currency because it creates no monetary obligation.
6. No notification is reported as delivered merely because it is queued. Cancellation notice acceptance remains blocked until P25 lifecycle and delivery status are available.

## Remaining Approval / Evidence

| Item / exact decision or contract request | Responsible role (named owner not established) | Affected scope and gate |
|---|---|---|
| T006: Who is authorized to approve a complimentary allocation? Is approval scoped to event, category/quota, recipient, or allocation; what verifiable evidence object records it; what validity/limits/revocation and separation-of-duties rules apply? Provide the authoritative approver and verification contract. | Project governance / Events product owner; Identity or approval-service owner for the verifiable contract | Production invitation issue/redemption and complimentary registration. `ComplimentaryAllocationApprovalContract` stays deny-bound (503). |
| T007: May a free-only event use a non-Finance publication route? If so, identify the required event review, approving role, evidence, version binding, and the rule preventing a later paid-category conversion from bypassing P25 price approval. | Events product owner plus established Finance approver | Free-only paid/publication lifecycle. Current P25 Finance price-approval and publication gates remain mandatory. |
| T014: Is offline admission approved for this scope? If approved, specify signed credential/device trust, event/gate/operator assignment freshness, stale manifests, revocation propagation, duplicate-scan risk, reconciliation, audit and fail-closed conditions. | Events product owner with Security and Operations reviewers | Offline scanner behavior. P26 remains online-only and offline requests unavailable. |
| T015/T020: Provide the P25 cancellation contract defining request/review authority and separation, cancellation state transition/version, ticket invalidation moment, handling of admitted/unknown financial cases, durable notice event/template, retry/deduplication and delivery-status query/reconciliation. Confirm this scope is usable by free registrations without creating refunds or ledger entries. | P25 Events owner and Notification operational owner; Finance/Payments/Ledger owners for any monetary disposition | Event cancellation, ticket invalidation and notices. No P26 terminal cancellation endpoint or delivered-notification claim is exposed. Invitation revocation remains independent. |
| T016: Appoint independent Security and Privacy reviewers and provide review records tied to the implementation commit/worktree diff, evidence versions, scope/exclusions, findings/remediation, outcome/date and sign-off. | Independent Security and Privacy review owners; appointment not established in repository records | Operational approval of invitation, credential, gate-admission, attendance and aggregate-report controls. Review/activation gate remains open. |

## Spec Kit Workflow Notes

The installed `speckit-specify`, `speckit-plan`, and `speckit-tasks` skills and PowerShell setup scripts are used. The repository's `.specify/feature.json` previously pointed to feature 070; the installed `create-new-feature.ps1` selected the next available feature number, `1002`, and now points to `specs/1002-free-invitation-complimentary-events`. There is no `.specify/extensions.yml` hook file. `check-prerequisites.ps1` was stale for the earlier pointer and was not used to overwrite another package.

## Latest Convergence Evidence (2026-10-30)

- T011 classification is now `IMPLEMENTED+VERIFIED`: the PostgreSQL harness covers explicit revocation-first and redemption-first orders, one synchronized competing race, replay after a later revoke, audit-transaction rollback and retry. See `evidence.md` for persisted state assertions and the actual observed race winner. This supersedes earlier statements that revoke/redemption coverage was missing.
- Identity contract dependency is resolved for development: `IdentityModuleContract::findActiveCustomerActorId` checks the authoritative Identity repository and returns only active CUSTOMER actor IDs. Events consumes it through `IdentityInvitationRecipientVerifier`; no direct cross-module database read. Missing/inactive/wrong-role and service-unavailable behavior are covered. This does not resolve complimentary allocation approval (T006).
- Admin invitation issue/revoke component test passed 1/1, community accessibility tests passed 4/4, TypeScript and targeted lint passed. Customer free-registration widget passed 2/2 and scoped analyzer passed. No browser/device E2E is claimed.
- Exact changed-file Pint passed. Broader Events-directory Pint found a style issue in `ConfiguredEventsPaymentServiceActorProvider.php`; it was not changed as unrelated scope.
- Combined scoped PHPStan across Events and the touched Identity contract/implementation reports 3 findings in existing Identity JWT/OpenSSL code (`IdentityModule.php` lines 273, 283, 287). They are outside the new lookup method; no suppression or baseline change was made. Full customer analysis remains 33 findings outside the P26 event slice.
- Current isolated DB `wallet_ecosystem_p26_review_20261030` uses PostgreSQL 15.19; schema bootstrap and full migration chain passed. P26 focused feature tests passed 72 tests/519 assertions; T011 passed all 7 scenarios. Exact commands and limits are in `evidence.md`.
- Operational blockers remain T006/T007/T014/T015/T016/T020. No production grants, publication, cancellation endpoint, offline admission, independent review or activation was introduced.

## Remediation continuation (2026-10-30)

- Reproduced and fixed all 3 prior Identity PHPStan findings in `IdentityModule::parseJwt`: validate the non-empty JWT and key material, check `openssl_pkey_get_private` before details extraction, validate the extracted public PEM, and retain the existing ES256 parser/signature/validity constraints. Malformed-key and empty-token tests fail closed. Current scoped PHPStan across Events and Identity has no errors; no ignores or baseline changes.
- Reproduced and fixed the single Events Pint finding in `ConfiguredEventsPaymentServiceActorProvider.php` with formatter-only changes. Exact file and full Events Pint checks pass.
- Reproduced the full customer Flutter baseline of 33 findings, corrected deprecated Firebase Messaging calls using resolved v17 APIs (`register`/`onRegistered`) and resolved the remaining imports, const, async-context and local-name findings. Final full `flutter analyze`: no issues (86.5 seconds). No dependency upgrade or lint suppression.
- Isolated regression runs: Identity service-token/security suite **17 passed / 45 assertions**; Events foundation suite **89 passed / 565 assertions** on `wallet_ecosystem_p26_review_20261030` via the disposable P08 Docker network. Customer auth/login plus event/transaction widget suites **50 passed** (7 auth/login + 43 event/transaction tests). Existing T011 separate-session PostgreSQL evidence remains 7 scenarios passed; this remediation made no registration/locking changes.
- Browser/device journey remains **NOT VERIFIED**. Chrome/Edge binaries and Playwright 1.64 are present, but Playwright browser inventory fails with `EPERM` reading `%LOCALAPPDATA%\\ms-playwright\\.links`; `adb` is unavailable. Local port 8000 is the development API and Playwright's global setup logs into it and overwrites the existing auth file, so that path was not used. See `browser-e2e-handoff.md` for the safe test harness prerequisites.
- `remediation-register.md` now enumerates each prior finding and the concrete T006/T007/T014/T015/T016/T020 decisions/contracts. `independent-review-handoff.md` is ready for appointment; no reviewer was appointed or sign-off claimed.
- T006 complimentary approval, T007 free-only publication, T014 offline mode, T015 shared cancellation, T020 notices and T016 independent reviews remain gated. P25 policy evidence and actual contract limitations are linked; no partial cancellation endpoint or notice delivery claim was introduced.


## T023 isolated harness reconciliation (2026-10-30)

Seven runner invocations in this continuation (six reached Playwright) created separate dated databases on the approved isolated P08 PostgreSQL/Redis network. All reached migrations and the runtime database identity assertion; there were 0 successful UI journeys. Two runs timed out on browser Admin credential submission; later attempts reached the login/MFA stage. Exact-origin preflight and invalid-token MFA 401 CORS probes passed. The latest valid MFA call returned HTTP 200 but no Access-Control-Allow-Origin, and Chromium reported CORS failure at v1/auth/mfa/verify. This is a successful-response CORS gap, not an MFA credential rejection. The customer/gate/report journeys and final database assertions have not run. Native Android remains unavailable because adb is absent. The new harness is development-only and creates no production approval or grant. See browser-e2e-handoff.md, evidence.md and remediation-register.md.

### 2026-10-31 continuation: successful MFA CORS resolved; Customer PIN browser boundary

The successful-MFA CORS diagnosis was reproduced and corrected without changing the configured origin allowlist: `chillerlan/php-authenticator` now resolves to 5.4.1, eliminating PHP 8.4 deprecation bytes emitted before headers by the previous 5.0.0 version. Central CORS regression tests and the real MFA feature flow assert exact allowed-origin headers and `Vary: Origin`; preflight, password login, invalid MFA and successful MFA all pass in the isolated runtime. Chromium completes Admin login and Events authoring. The journey then reaches the Customer challenge and PIN sheet but does not issue `/v1/customer/auth/login/verify` after Playwright types six characters into Flutter Web's proxy input. This remains a concrete Flutter Web/headless input-event bridge gap; no customer registration or downstream assertion has run. Full evidence and exact database name are in `evidence.md` and `browser-e2e-handoff.md`.
# 2026-11-02 Agent API and verification findings

- The dashboard path was a missing implementation, not an undefined requirement: P032 (`specs/032-agent-application/contracts/agent-app-api.md`, FR-007–009) specifies `GET /v1/agent/dashboard`. AgentFloat now owns the route and derives the Agent from authenticated Identity context. It requires existing own-float and transaction-view permissions and uses Ledger balance plus completed float transactions. No client-selected Agent identity is trusted. An unresolved/TBD low-float threshold returns 503 instead of invented status/metrics. The isolated API feature suite passed 4 tests/13 assertions.
- Inbox and unread-count paths were client-contract drift: the existing shared API is `/v1/notifications/inbox` and `/v1/notifications/inbox/unread-count`, with PATCH for read state. Agent DTO/repository mapping now follows those server contracts; the full Agent test suite passes 99 tests.
- Current full Agent Flutter analysis changed from the previously reported 21 diagnostics to no issues. This does not close the P26 governance blockers.
- Native readiness attempt: Pixel_10 eventually booted and `flutter doctor -v` lists `emulator-5554`, Android 17/API 37. Android license status is unknown; no license agreement was accepted. Existing Customer smoke defaults to dev API port 8000 and Agent Android wrapper/settings files are absent, so native P26 verification remains open.
- Isolated T023 rerun passed 2/2 Playwright tests and final DB assertions. The exact results and build-warning dispositions are in `evidence.md`.
- Pint could not run in the isolated image because its PHAR bootstrap fails to resolve `Illuminate\\Auth\\AuthServiceProvider`; PHP syntax checks and API feature tests pass, but formatting analysis of the new dashboard API remains unverified.

## 2026-11-03 project-owner answers and T007 follow-up

- The user answered the outstanding P26 question batch: T006 model accepted for development with production denied; T007 narrow free-only development rule approved; T014 online-only/defer offline selected; T015 unknown cancellation finances remain blocked pending specialist contract; Governance appoints independent reviewers. Record authority precisely as project-owner direction, not Finance/Tax/Legal/Security/Privacy or regulatory approval.
- T007 now has a bounded Events-owned service implementation and regression coverage. It bypasses Finance price approval only for a non-empty current event version where every category is explicitly `free`/`complimentary` and server-side zero-priced. It retains current event review; complimentary allocations call T006; all paid categories still require separate event/Finance approvers. Publication pauses sales. Production readiness remains deny-bound, and there is no workflow HTTP route.
- T006 production evidence remains unavailable: `ComplimentaryAllocationApprovalContract` is currently a scoped void assertion with no returned approval identity/version/provenance and no authoritative approval provider. Existing test fixtures are synthetic only.
- The isolated API-only verification mode uses the same guarded unique database, schema bootstrap, migration and test image path. Latest clean run: CORS 3/52, MFA 10/70, Events foundation 76/533. Locked Pint 1.30.5 passed on four changed PHP files after formatting. See `evidence.md` for historical pre-fix failures and boundaries.
- T014 is selected by the project owner but remains unchecked until the repository's scope-change authority records the release exclusion and online loss/retry denial is verified. T015/T020 await specialist P25 lifecycle/Payments/Ledger/Notification contracts. T016/T024 await actual Governance appointments and signed independent reviews.
- Android remains blocked by the single test emulator reporting offline after ADB server restart. No AVD wipe, license acceptance or retained DB cleanup occurred. The strict same-run Wallet-capture assertion remains unverified; prior native evidence is preserved at its original boundary.

## 2026-11-03 project-owner answers and T006 engineering delta

Recorded answers, with authority scope limited to the project owner:
- T006 accepts the organisation/event/category/allocation approval model for development, with separate requester/approver and evidence ID/version/provenance, allocation limit, validity and revocation. This does not appoint a Governance approver, define numeric global limits or authorize production binding.
- T007 accepts the narrow development-only free-only rule already described in the task/acceptance records. Finance and release authorization remain independent.
- T014 selects online-only admission/offline deferral for the release. Formal scope-change record and connectivity-loss/retry evidence are still required before exclusion closure.
- T015 accepts continued containment for unresolved cancellation finances. No automatic issuance/refund or terminal cancellation for unknown outcomes.
- Governance is identified as the authority that appoints independent reviewers; no actual appointment is recorded.

T006 development implementation now uses a typed evidence contract and immutable snapshots; production remains deny-bound. No further project-owner answer is needed for these accepted directions. Specialist contracts and real Governance appointment evidence remain outstanding.

T007 acceptance limitation: the verified code covers the development transition rules only. Organiser verification is an accepted requirement, but no authoritative Events/Identity evidence contract/provider exists; production `EventPublicationReadiness` remains deny-by-default. Finance/release gates also remain independent. The implementation has not exposed a production publish endpoint.
