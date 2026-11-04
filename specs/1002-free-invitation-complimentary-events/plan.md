# Implementation Plan: P26 Free invitation and complimentary event journey

**Branch**: `1002-free-invitation-complimentary-events` | **Date**: 2026-10-30 | **Spec**: `spec.md`

## Summary

Extend the existing Events ticket entitlement model with explicitly free/complimentary registrations, recipient-bound invitations, atomic capacity allocation, idempotent issuance, purchaser ticket access, online admission, and aggregate reporting. Do not call Payments, Wallet, Ledger, provider adapters, settlement, or refund services for zero-price entitlements. Reuse P25 ticket/gate/authentication infrastructure and preserve paid ticket behavior.

## Technical Context

**Language/Version**: PHP 8.3 (API), TypeScript/React Native (admin/customer UI only if current conventions support an independent slice)  
**Primary Dependencies**: Existing Laravel API, PostgreSQL `community_services`, Identity scoped permissions/assignments, Events ticket credential vault and admission, shared P11 outbox/notification delivery  
**Storage**: PostgreSQL; migrations are additive. Existing event ticket and attendance tables are reused.  
**Testing**: PHPUnit; real separate-session PostgreSQL race tests required for invitation/capacity allocation; existing P25 admission tests/regressions.  
**Target Platform**: API service, customer ticket views and authorised event admin surfaces.  
**Project Type**: Existing modular monolith plus clients.  
**Performance Goals**: Capacity allocation is serialized per event/category; no oversell under concurrent registrations.  
**Constraints**: No financial side effects for zero price; no production fallback or grants; P25 production publication/sales, bootstrap, P18/P24 remain disabled. Existing paid categories/orders are backward compatible. No unapproved offline behavior.  
**Scale/Scope**: Complimentary allocations and invitation redemption for Events; online-only admission is the accepted release direction; effective cancellation lifecycle is implemented while paid financial recovery and notice delivery remain dependent.

## Constitution Check

- **Source-of-truth and modular ownership**: PASS. Events owns allocation, registration, ticket entitlement, and admission state; Payments/Wallet/Ledger are not invoked.
- **Financial integrity**: PASS by explicit non-payment path and tests proving no payment/accounting records are written.
- **Identity/RBAC**: PASS only with existing permission plus event-scoped assignment enforcement. No fixture is production authorization.
- **Idempotency/concurrency**: Required. Registration, invitation usage, capacity, ticket and audit must commit atomically under the event/category locking convention; PostgreSQL race test is acceptance evidence.
- **Privacy**: Use purchaser scoping, credential privacy and aggregate-only organizer reporting from P25. Independent Privacy review remains open.
- **Compatibility**: Paid category/API behavior remains unchanged; ticket source must be explicit and paid evidence checks must continue for paid tickets.
- **Activation**: Remains fail-closed pending P25 gates, complimentary-approval authority, independent reviews, and approved offline policy.

## P25 Prerequisite Evidence and Classification

P25 source and predecessor artifacts were inspected before implementation. The exact source is `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx`; its embedded document text identifies ‚ÄúVersion 1.1 | 19 October 2026‚Äù, corroborated by the checked-in extraction `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.txt`. LC08 is at lines 439‚Äì449 of that extraction. Current Git branch/HEAD at start: `main` / `c091fe8f42580b1654348d90490918d9aad66d23`; numerous unrelated uncommitted changes pre-existed and are preserved.

| Capability | Evidence | P26 consequence |
|---|---|---|
| Ticket credential, purchaser-scoped My Tickets, exact event/gate online validation, once-only admission and attendance audit | P25 `acceptance-matrix.md`, P25 `evidence.md` E2E-01, `EventAdmissionService`, P25 feature and PostgreSQL concurrency tests | READY to reuse for development; admission currently assumes payment for every ticket, requiring an explicit free-source branch. |
| Event/category inventory, row-locking holds, event capacity and event-scoped authorization | P25 inventory hold service and separate-session PostgreSQL tests | READY to reuse pattern; free registrations must atomically share the same capacity accounting. |
| Production pricing/publication/sales and independent Security/Privacy review | P25 acceptance matrix and review handoff | BLOCKED operationally; do not use P26 as a bypass. Development fixtures may support isolated tests. |
| Cancellation/revocation workflow and notices | P25 matrix/restart: Events owns ticket lifecycle/admission; shared outbox/Notification exists | Events request/review/effective lifecycle and transactional cancellation event are implemented. Notice delivery remains blocked until the approved template, recipient/privacy, retry and delivery-status contract exists. Invitation revocation remains separate and does not revoke committed tickets (LC08). |
| Offline admission | P25 has no approved offline mode and fails closed | BLOCKED; keep online-only. |
| Complimentary approval authority | LC08 requires approved complimentary allocation; owner accepted scoped development model | Events-owned typed approval request/approval/revocation and evidence read path implemented with exact assignments; production use still requires actual Governance authority assignment and evidence. |
| Free event vs paid event publication review requirements | Existing P25 publication requires event review and Finance price approval; LC08 does not define a distinct free-only approval route | BLOCKED to change publication gate; reuse current lifecycle and test fixtures, do not bypass Finance approval. |

## Design Decisions

1. Use separate Events-owned tables for invitations and free registrations, but reuse `event_tickets`, `event_attendance`, credential vault, and the gate API. This avoids manufacturing paid `event_orders` or Payment intents. Add an explicit entitlement source and nullable paid order references with a database check preventing ambiguous paid/free ownership.
2. Add explicit allocation kind to event categories (paid/free/complimentary) and a quota. Preserve paid rows as `paid` by migration backfill; never infer free from zero price. Zero-price free/complimentary rows are validated as zero and excluded from `EventOrderService` and price eligibility paths.
3. Serialize against paid holds and free registrations by locking in the same order: event row, category row, then invitation row. Count active paid holds plus issued paid quantity and free registrations/issued entitlements against category capacity. Maintain event row lock consistency with P25 and avoid independent counters that can drift.
4. An invitation token is opaque random secret; persist only a one-way hash. Bind to an authenticated actor when recipient-bound, event and allocation, validity interval, max uses, current uses, revoked timestamp. Do not expose recipient identity in validation errors.
5. Registration accepts only explicitly permitted minimal attendee data; identity/tenant derives from authenticated request context. Use `Idempotency-Key`; same key/body replays, changed body denies. Registration, invitation use, ticket and category capacity update/audit commit in one transaction.
6. Free entitlement tickets have `entitlement_source=free|complimentary`, `free_registration_id`, and no order/order-line/payment fields. Paid tickets retain their current order refs and require payment/refund eligibility checks at admission. Do not apply refund checks to free tickets.
7. Offline admission remains deferred and fail-closed; re-entry/transfers remain unavailable. Effective event cancellation is implemented, while financial recovery and cancellation-notice delivery remain gated by shared specialist contracts.
8. Complimentary invitation issue and registration require a typed organisation/event/category approval contract. Bind production to deny-by-default until the authority and evidence source are established; only development fixtures may permit tests.

## Implementation Phases

### Phase 0: Research and design

- Reconcile LC08 and P25 predecessor evidence; classify tasks READY/BLOCKED/DEPENDENT before code changes.
- Define additive data model, exact invariants, API contract, traceability and focused runbook.

### Phase 1: Data and backend foundation

- Add category allocation kind/quota and free registration/invitation/ticket source schema with safe existing-row backfill.
- Implement scope-checked free allocation/invitation authoring, recipient-bound invitation creation/revocation and safe admin representation.
- Implement atomic invitation redemption/registration/free entitlement path. No Payments or Ledger calls.

### Phase 2: Tickets, admission and reporting

- Adapt ticket purchaser listing and online admission to accept only authoritative free entitlements without payment lookup; preserve paid checks.
- Add aggregate free registration, paid sales, unique admission, and post-event no-show metrics; no identity fields.
- Reuse existing interfaces where present; test API behavior before client wiring.

### Phase 3: Verification and handoff

- Run isolated migration checks, relevant PHPUnit tests, actual PostgreSQL separate-session races, static analysis/lint where supported.
- Update traceability, acceptance matrix, flow runbook, managerial register and restart notes.
- Record blocked cancellation notices/offline/production activation and independent reviews.

### Phase 4: Convergence remediation (authorized continuation)

- Resolve the reported Identity JWT/OpenSSL PHPStan findings without changing ES256 or claim-validation behavior; test malformed key and empty token paths.
- Resolve the reported Events Pint finding with formatting-only edits.
- Resolve each reproduced customer Flutter analyzer finding without rule suppression or dependency-wide upgrades; run full analysis and focused auth/event/transaction regressions.
- Prepare Security/Privacy review records and the exact T006/T007/T014/T015/T020 decision/contract requests; preserve deny-by-default operational bindings.
- Execute SC-007 only against isolated services and test fixtures. If safe authorization fixtures and a P26 Playwright setup are unavailable, keep E2E acceptance open and document the exact next harness work in `browser-e2e-handoff.md`.

## Risks and Mitigations

- **Capacity over-allocation across paid holds and free registrations**: shared event/category row-lock order and one authoritative capacity computation; PostgreSQL concurrency tests.
- **Payment bypass through forged source**: server-derived source and database consistency check; paid ticket source cannot omit its payment order references.
- **Invitation leakage/replay**: opaque hashed token, recipient binding, locked use count, idempotent registration, generic denial response.
- **Paid regressions**: explicit allocation type, backfill existing categories to paid, preserve paid service/confirmation contract tests.
- **Operational bypass of pending P25 gates**: deny production bindings; test fixtures are visibly development-only; no publication or sales toggles changed.

## Open Items

- Production complimentary-allocation approval authority/evidence is not identified by LC08.
- The existing P25 event publication review requires Finance price approval; no approved exception for free-only events exists.
- P25 cancellation/revocation notice flow and approved offline policy are incomplete. P26 must not claim those dependent flows are complete.
- Independent Security and Privacy reviews for reused ticket/admission controls remain outstanding.

## Authorized continuation workplan (2026-11-02)

| Workstream | Evidence-based disposition / next work |
|---|---|
| A. Agent analysis findings | Resolve the 21 diagnostics reproduced by full `flutter analyze`: unused field/helper/imports, async BuildContext use, missing `const`, undeclared Mockito test dependency, and deprecated Color access. Run the full Agent suite and analysis. |
| B. Dashboard and inbox 404s | The Agent spec `032-agent-application` FR-007‚Äì009 and `contracts/agent-app-api.md` define `/v1/agent/dashboard`; implement it in AgentFloat using Identity-derived Agent ownership, existing wallet/transaction permissions, Ledger balance and completed float records. Point inbox clients to the existing recipient-scoped Notifications contract; never add placeholder success data. |
| C. Font/build warnings | Check actual Cupertino icon use and supported web target. Keep only supported JavaScript build unless project configuration requires Wasm. Make any color-environment change process-local and restore the invoking shell setting. |
| D. Native verification | Check SDK environment and `adb`; run device integration only if a configured emulator/device is available. Otherwise preserve browser evidence and leave native criteria explicitly unverified with invocation prerequisites. |
| E. T006/T007 | Refresh concrete approval requests for complimentary allocation authority/evidence and a distinct free-only publication rule. Keep production deny binding and Finance publication gate closed until real approval evidence exists. |
| F. T014 | Present online-only release deferral versus bounded offline admission policy; keep offline fail-closed until an authorised decision and scanner/security contract exist. |
| G. T015/T020 | Reuse P25 cancellation/Payments/Ledger and Notifications ownership. Implement no terminal cancellation or notice path until the shared state/recovery contract, review authority, template/recipient and delivery evidence are sufficient. Preserve invitation revocation semantics. |
| H. T016/T024 | Refresh Security/Privacy handoff against current worktree diff and evidence. Internal fixes do not close independent review; appointment and signed outcome remain pending. |

The supported development notification/dashboard fixes are separate from complimentary approval, free-only publication, offline, cancellation, independent-review and activation decisions.

## 2026-11-03 execution delta

- T006 model and T007 free-only direction are project-owner accepted for development. T007's internal workflow change and focused regression tests are complete; production publication remains deny-bound. T006's authoritative approval evidence provider is still missing.
- T014 is selected as online-only/offline deferred for this release; record the exclusion through scope governance and verify loss/retry denial before closing its acceptance criterion.
- T015 has an Events-owned development lifecycle; paid financial disposition remains blocked by Finance/Payments/Ledger contracts. T020 has a durable cancellation event/consumer foundation but remains blocked for template, channel/recipient/privacy and delivery reconciliation rules.
- T016/T024 await Governance reviewer appointments and actual independent reviews. T023 still lacks the strict same-run Wallet-capture assertion because the emulator is offline; prior native evidence remains bounded to its original queries.
- The new API-only regression mode validates the guarded isolated DB and executes CORS, MFA and Events foundation tests without opening UI ports.

## 2026-11-03 plan checkpoint

- T006 development port: complete typed scope-bound evidence, snapshot migration, registration/publication integration, negative/positive tests. Production verifier/provider and authority evidence are external/blocked.
- T007 development workflow: implemented and tested. Production readiness remains deny-bound and all specialist/release gates remain closed.
- T014: project-owner selected online-only/offline deferral; do not mark excluded until the established release scope-change record and connectivity loss/retry evidence exist.
- T015/T020: retain containment and implement no terminal cancellation or notice without specialist P25 financial and Notification contracts.
- T016/T024: Governance must appoint actual independent reviewers; prepare package, but do not mark review complete.
- T023: rerun the strict same-run native capture assertion only after the emulator is responsive; do not wipe the AVD or remove retained test databases.

## 2026-11-04 implementation plan delta

- Treat the Events database approval workflow as the project-owner-selected T006 development source; retain Governance/bootstrap evidence as an operational gate.
- Keep T007 organiser verification deny-by-default until the onboarding source proves the three purpose-specific facts and inherited freshness/revocation/Finance/release controls.
- Require production pricing providers to supply immutable OleKard terms acceptance evidence bound to the event and organisation; after an approved terms change, require reacceptance before paid capabilities continue. Preserve order snapshots.
- Draft cancellation notice content and recovery with the existing Notification channel/retry/state mechanisms. Do not register or send until owners approve.

## 2026-11-04 final development checkpoint

Project-owner decisions recorded in the current P26 decision register: T006 uses the Events database approval workflow for development subject to Governance acceptance; T007 requires the three-purpose organiser evidence and paid-event review/Finance/release controls; T014 is online-only for this release with offline deferred; cancellation requester and reviewer differ, pending requests do not pause activity, effective cancellation is atomic and attendance history persists; unknown paid outcomes remain pending; cancellation notice content/configuration remain owner-review drafts; Governance is the review appointment authority. The additional terms decision is to require organiser reacceptance before affected paid capabilities continue when a new approved terms version becomes applicable. These are project-owner decisions only, not specialist approval, real assignments, independent review or activation.

The final guarded API run passed against disposable database wallet_ecosystem_p26_e2e_20261104_08710f18: CORS 3/52, Identity MFA 10/70, Events foundation 81/636 and Events unit contracts 11/29. Separate PostgreSQL sessions verified last-capacity, invitation-use, idempotency, revoke/redemption, rollback/retry and the four T015 cancellation/registration/admission lock orderings. Each T015 loser was observed waiting on the winnerís database lock. Results and finance baselines are in evidence.md. Database was created and cleaned by the guarded runner only after success.

A full guarded Chromium journey then passed 2/2 Playwright tests against disposable database wallet_ecosystem_p26_e2e_20261104_5e26ee81. Persisted state matched 3 registrations, 3 tickets, 1 attendance, 1 admission audit, 3 free-issuance audits, 5 invitation audits, and zero payment intents, attempts, refunds, Ledger journals, payouts and settlement batches. The journey included Admin invitation issue/revocation, customer free registration/My Tickets, other-customer denial, gate assignment boundaries, wrong-gate denial, admission/repeat-scan behavior and aggregate reporting. Approval/publication/identity/operator fixtures were synthetic. This is browser development evidence, not production approval.

Admin focused tests passed: 2 suites/6 tests. Admin TypeScript passed with exit 0. Focused Admin lint passed with no warnings after nullable-string guards. Scoped PHPStan passed with 0 errors on the changed pricing, order and organiser-evidence production files; PHPStanís existing version/deprecated-config notices remain. Locked Pint --test passed 30 changed Events PHP files with the process-local APP_CONFIG_CACHE override. git diff --check passed after the final documentation updates, subject only to existing CRLF conversion notices.

The existing same-run native Customer/Agent journey remains verified for Android online/manual credential entry: real challenge/PIN login, free registration, purchaser credential access, wrong-gate denial, exact-gate admission and repeat-scan denial, with zero financial records. Camera capture and native Admin/report interfaces remain outside that evidence. They were not rerun because the final changes were Admin assignment controls, P25 paid-pricing contract validation and server-side cancellation/approval contracts; the browser journey and API regression were rerun.

T006 development workflow and Events Admin assignment controls are implemented/tested; production operation still requires Governance acceptance and actual authorized assignment records. T007ís required organiser proof references and fail-closed checks are implemented/tested, but the production verifier remains deny-bound because no authoritative onboarding contract establishes evidence validity/freshness/revocation for this purpose. Free-only publication logic remains development-only; production publication readiness remains deny-bound.

The P25 pricing boundary now requires version/hash-bound OleKard acceptance evidence, scope and effective-date validation, and immutable order snapshots. P26 T028 adds a Platform terms registry, protected draft/approve/publish and organiser-acceptance API routes, immutable content hashes, typed approval and representative-verification contracts, and a current-acceptance query consumed by Events. No dedicated Admin/organiser UI was added. Production approval and representative providers remain deny-bound; approved terms content, role assignments and real evidence are absent. Actual fees/tax and paid checkout remain unavailable; no zero-fee/tax policy or production binding was added.

T015 free-event cancellation and tested races are verified. Paid recovery remains blocked by missing refund/cancellation/late-capture terms and accepted Payments/Ledger execution/status/reconciliation contracts. T020 transactional intent and recipient-scoped notification command deduplication are verified; cancellation template, channels/privacy policy, retry/escalation, real provider delivery evidence and operator acceptance remain pending. Queueing or dispatch is not delivery. T016/T024 remain pending genuine Governance-appointed independent reviewers, conflict records and signed version-bound outcomes. T014 is project-owner approved deferred for P26 scope; separate production release acceptance remains distinct. T023 Chromium is current-source PASS; its Android online/manual-entry scope is preserved from the prior same-run evidence; camera capture is not verified. T027 remains PASS from the recorded locked-Pint evidence.

Browser builds succeeded on the JavaScript target. Flutterís optional Wasm dry-run reports existing dependency incompatibilities (flutter_secure_storage_web uses dart:html/dart:js_util; printing uses dart:ffi); Wasm is not a configured target. The Cupertino font warning also appeared; workspace app source has no CupertinoIcons reference and generated FontManifest contains MaterialIcons only, so no unused dependency was added. These are recorded as non-blocking target/dependency warnings, not silent passes.

Overall P26 remains PARTIAL. Production bootstrap, publication, sales, deployment, P18 execution and P24 activation remain disabled. No external messages, commits or pushes were made.

## Final authority and closure reconciliation (2026-11-04)

T028's current accepted development scope is the protected Platform API plus Events integration; the checked-in plan/acceptance criteria do not require a dedicated terms UI. The scoped PHPStan timeout was superseded by a completed zero-error run. The terms guard now has successful-path lock-conflict and callback-exception release tests; no process-termination test or global cross-service atomicity claim is made. T014 is formally dispositioned for P26 scope by P26-SC-014; only Constitution Principle 15 release acceptance remains for a release, with its actual approver not specified in repository policy. The precise external owner records, governing sources, role authorities and closure evidence are in the consolidated acceptance request at the end of `remediation-register.md`.
