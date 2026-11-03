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
**Scale/Scope**: Complimentary allocations and invitation redemption for Events; cancellation/offline dependent paths remain blocked.

## Constitution Check

- **Source-of-truth and modular ownership**: PASS. Events owns allocation, registration, ticket entitlement, and admission state; Payments/Wallet/Ledger are not invoked.
- **Financial integrity**: PASS by explicit non-payment path and tests proving no payment/accounting records are written.
- **Identity/RBAC**: PASS only with existing permission plus event-scoped assignment enforcement. No fixture is production authorization.
- **Idempotency/concurrency**: Required. Registration, invitation usage, capacity, ticket and audit must commit atomically under the event/category locking convention; PostgreSQL race test is acceptance evidence.
- **Privacy**: Use purchaser scoping, credential privacy and aggregate-only organizer reporting from P25. Independent Privacy review remains open.
- **Compatibility**: Paid category/API behavior remains unchanged; ticket source must be explicit and paid evidence checks must continue for paid tickets.
- **Activation**: Remains fail-closed pending P25 gates, complimentary-approval authority, independent reviews, and approved offline policy.

## P25 Prerequisite Evidence and Classification

P25 source and predecessor artifacts were inspected before implementation. The exact source is `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx`; its embedded document text identifies “Version 1.1 | 19 October 2026”, corroborated by the checked-in extraction `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.txt`. LC08 is at lines 439–449 of that extraction. Current Git branch/HEAD at start: `main` / `c091fe8f42580b1654348d90490918d9aad66d23`; numerous unrelated uncommitted changes pre-existed and are preserved.

| Capability | Evidence | P26 consequence |
|---|---|---|
| Ticket credential, purchaser-scoped My Tickets, exact event/gate online validation, once-only admission and attendance audit | P25 `acceptance-matrix.md`, P25 `evidence.md` E2E-01, `EventAdmissionService`, P25 feature and PostgreSQL concurrency tests | READY to reuse for development; admission currently assumes payment for every ticket, requiring an explicit free-source branch. |
| Event/category inventory, row-locking holds, event capacity and event-scoped authorization | P25 inventory hold service and separate-session PostgreSQL tests | READY to reuse pattern; free registrations must atomically share the same capacity accounting. |
| Production pricing/publication/sales and independent Security/Privacy review | P25 acceptance matrix and review handoff | BLOCKED operationally; do not use P26 as a bypass. Development fixtures may support isolated tests. |
| Cancellation/revocation workflow and notices | P25 matrix/restart: cancellation workflow is not completed; shared notifications/outbox exist | BLOCKED for event cancellation notices until an authorised cancellation lifecycle and template/delivery evidence exist. Invitation revocation is independent but must not revoke an already issued ticket (LC08). |
| Offline admission | P25 has no approved offline mode and fails closed | BLOCKED; keep online-only. |
| Complimentary approval authority | LC08 says approved complimentary allocation but does not identify authority | BLOCKED for production approval evidence; implement configuration behind existing event assignment and use explicit dev fixtures only. |
| Free event vs paid event publication review requirements | Existing P25 publication requires event review and Finance price approval; LC08 does not define a distinct free-only approval route | BLOCKED to change publication gate; reuse current lifecycle and test fixtures, do not bypass Finance approval. |

## Design Decisions

1. Use separate Events-owned tables for invitations and free registrations, but reuse `event_tickets`, `event_attendance`, credential vault, and the gate API. This avoids manufacturing paid `event_orders` or Payment intents. Add an explicit entitlement source and nullable paid order references with a database check preventing ambiguous paid/free ownership.
2. Add explicit allocation kind to event categories (paid/free/complimentary) and a quota. Preserve paid rows as `paid` by migration backfill; never infer free from zero price. Zero-price free/complimentary rows are validated as zero and excluded from `EventOrderService` and price eligibility paths.
3. Serialize against paid holds and free registrations by locking in the same order: event row, category row, then invitation row. Count active paid holds plus issued paid quantity and free registrations/issued entitlements against category capacity. Maintain event row lock consistency with P25 and avoid independent counters that can drift.
4. An invitation token is opaque random secret; persist only a one-way hash. Bind to an authenticated actor when recipient-bound, event and allocation, validity interval, max uses, current uses, revoked timestamp. Do not expose recipient identity in validation errors.
5. Registration accepts only explicitly permitted minimal attendee data; identity/tenant derives from authenticated request context. Use `Idempotency-Key`; same key/body replays, changed body denies. Registration, invitation use, ticket and category capacity update/audit commit in one transaction.
6. Free entitlement tickets have `entitlement_source=free|complimentary`, `free_registration_id`, and no order/order-line/payment fields. Paid tickets retain their current order refs and require payment/refund eligibility checks at admission. Do not apply refund checks to free tickets.
7. Offline admission, re-entry, transfers, event cancellation workflow, cancellation notification and production complimentary approval remain blocked as specified. Do not invent them.
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
| B. Dashboard and inbox 404s | The Agent spec `032-agent-application` FR-007–009 and `contracts/agent-app-api.md` define `/v1/agent/dashboard`; implement it in AgentFloat using Identity-derived Agent ownership, existing wallet/transaction permissions, Ledger balance and completed float records. Point inbox clients to the existing recipient-scoped Notifications contract; never add placeholder success data. |
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
- T015 remains blocked for financial cancellation disposition; project owner accepted containment for unknown outcomes. T020 remains dependent on the shared cancellation and Notification contract.
- T016/T024 await Governance reviewer appointments and actual independent reviews. T023 still lacks the strict same-run Wallet-capture assertion because the emulator is offline; prior native evidence remains bounded to its original queries.
- The new API-only regression mode validates the guarded isolated DB and executes CORS, MFA and Events foundation tests without opening UI ports.

## 2026-11-03 plan checkpoint

- T006 development port: complete typed scope-bound evidence, snapshot migration, registration/publication integration, negative/positive tests. Production verifier/provider and authority evidence are external/blocked.
- T007 development workflow: implemented and tested. Production readiness remains deny-bound and all specialist/release gates remain closed.
- T014: project-owner selected online-only/offline deferral; do not mark excluded until the established release scope-change record and connectivity loss/retry evidence exist.
- T015/T020: retain containment and implement no terminal cancellation or notice without specialist P25 financial and Notification contracts.
- T016/T024: Governance must appoint actual independent reviewers; prepare package, but do not mark review complete.
- T023: rerun the strict same-run native capture assertion only after the emulator is responsive; do not wipe the AVD or remove retained test databases.
