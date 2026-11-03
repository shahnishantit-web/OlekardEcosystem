# P25 Implementation Plan — PARTIAL / REVIEW GATES OPEN

**Source:** `spec.md`, `research.md`; architecture v1.1 LC07 and section 2.  
**Workflow gate:** the installed workflow's review/approval gates remain open for policy-dependent flows. The independent technical slice below is implemented; it is not authority to enable payment or admission journeys.

The user explicitly authorised proceeding past the unchecked-checklist confirmation for independent scope. Draft-only schema and validation now exist behind a deny-by-default authority, including positive order limit validation, Finance-owned currency assignment boundary, and strict zoned-time/sales-window validation (T017–T020). This does not approve unresolved decisions or complete the plan gate.

## Technical Context

Existing Laravel modular monolith, PostgreSQL, shared Payment/Wallet/Ledger/E25, Next.js and Flutter. Events is a separate business domain. Tenant and operator scope, admission and financial policies are unresolved; no new provider, role or accounting contract is inferred.

## Constitution Check

| Principle | Status | Evidence or gap |
|---|---|---|
| Source truth and TBD governance | PARTIAL | User-selected directions are recorded; approved numerical/business decisions and owner evidence are itemized in `research.md`. |
| Financial integrity and event reliability | BLOCKED | No live financial flow added; hold/confirmation/refund contracts and P18 payout stubs remain gates. |
| Identity, zero trust and audit | PARTIAL | Default authorizer denies all; runtime permission identifiers/contracts and independent security/retention evidence remain missing. |
| Module ownership and compatibility | DESIGN PASS | Shared Payments, Wallet, Ledger and E25 preserved; no duplicate integration. |
| Test, release and operations | PARTIAL | Focused draft tests and local migration pass. Last-ticket/scan concurrency, API/UI, integrated finance, and independent review remain open. |

## Dependency Order

1. Obtain and record the B-01 Identity/Permissions contract and B-03 Finance currency/payment-method guard. Meanwhile, draft-only/internal domain work may continue behind deny-by-default authorizers; keep the five review checklist items unchanged.
2. Implement B-02 event review/publication version state and admin authoring surface using decisions already in FR-002: independent reviewer, finance price approval, approval invalidation, preserved last-approved snapshot, pause on every post-publication edit, and explicit authorized resumption. Do not enable paid publication until B-03 and B-06 are satisfied.
3. Implement B-04 atomic category/event inventory holds against the published version, using earlier-of-configured-duration/event-start expiry and no hold when configuration is absent. Use database transactions/row locks and real PostgreSQL concurrency tests; no new business default is required.
4. Implement B-05 shared checkout integration: Events resolver derives amount/tenant/customer/order server-side; shared Payments creates the intent and durable ledger-confirmed event; Events consumes the at-least-once outbox idempotently and repairs ticket issue without a new charge. Enable payable quote only after fee/tax and method gates are approved.
5. Implement B-08 online-only admission after ticket credentials exist, with scoped authorization, status/category-only result, fail-closed connectivity, commit-order revocation and atomic one-time initial admission. Do not implement offline admission.
6. Implement late capture/refund only when unused-ticket refund eligibility and shared refund evidence are approved; preserve unknown outcomes. Event completion stays distinct from settlement and payout.
7. Resume P18 for P25 payout only after AD-14, AD-10 and LC14 are resolved and P18 T049/T050/T055 plus required P18 runtime evidence are complete. Add admin/mobile/gate integration and independent finance/security review once APIs exist.

## Transaction and Recovery Design Questions

Database row locks, uniqueness constraints, transactional outbox/inbox, idempotent replay and commit-order handling follow existing repository conventions. Selected hold and scan behaviors are already recorded in FR-003/005/006. The unresolved gates are specific: role/assignment authority (B-01), currency/option eligibility and fee/tax policy (B-03/B-06), unused-ticket refund and credential rules (B-07/B-09), P18 custody/payout decisions (B-11), and reviewer/concurrency evidence (B-12/B-13). Do not relabel these routine technical designs as business-policy blockers.

## Independent implementation completed

T020 is a narrow draft-validation implementation. UTC instants must be supplied with an explicit offset matching the chosen IANA zone; ambiguous/nonexistent daylight-saving times are rejected, event end follows start, and sales close by start. The service requires a positive proposed event-level order maximum and discards organizer-supplied currency (Finance/platform assignment remains a separate future capability). This does not implement the authoring UI/API, publication, currency assignment, inventory, quote, checkout or any ticketing flow. Details and verification are in `evidence.md`; remaining policy/contract gates are in `research.md`.

## 2026-10-28 implementation update

T021 implements the internal version-checked review/publication workflow from decisions already recorded: organiser submission, independent publication and Finance price approval, current-version publication checks, approved snapshot retention, post-publication edit pause/re-review and explicit sales resume. Production bindings for `EventWorkflowAuthorizer` and `EventPublicationReadiness` deny by default. B-02's core state transition design is now implemented; B-01 permission evidence, B-03 currency/payment readiness and route/admin/mobile surfaces remain outstanding. No route or sale is enabled. See `tasks.md`, `research.md` blocker addendum and `evidence.md` for current tests and environment.

## Implementation checkpoint — CTX/MC/FIN (2026-10-28)

The bounded implementation adopts the accepted Events/Payments context and MultiCurrency coordination direction. Currency locks use one PostgreSQL primary/database and a shared transaction-scoped advisory key; a DB trigger makes direct status/precision mutation paths participate. Events acquires and reads under the same transaction used for its inventory hold. The first durable order remains a required future T010 integration. Scope and test evidence are in research.md and evidence.md; publication/readiness binding stays deny-by-default.

## Convergence plan reconciliation — 2026-10-29

The earlier dependency-order plan is superseded for completed development slices by `tasks.md` and `acceptance-matrix.md`. T008–T011 development implementation exists; the next engineering step is E2E-01 (one actual real-service PostgreSQL journey), not a new product clarification cycle. B-06 remains an operational pricing blocker; late capture/refund/cancellation and D-02 numeric re-entry remain policy blockers; P18/B-11 remains payout-only. Independent Finance/Security/Privacy review and production activation are separate gates. Preserve the five unchecked checklist markers and deny-by-default bindings.

## Accepted-policy implementation slice — 2026-10-30

T030 implements a backward-compatible typed extension of the Events pricing snapshot: organiser platform-fee schedule and amount remain distinct from customer payable fees/total; production pricing validates version, effective dates, currency, organisation/event scope, fixed/percentage component metadata, fee payer and approved evidence references; production tax snapshots require jurisdiction/classification, separate ticket/service-fee treatment, rates or explicit exemptions, taxable bases, calculation order, precision, rounding, disclosure and invoice responsibility. Monetary amounts use integer minor units and rule values are decimal strings. Events does not compute the unresolved Finance/Tax formula or deduct organiser proceeds. Synthetic tests are technical fixtures only.

Production behavior remains unavailable until the authoritative pricing provider supplies verified seller and specialist-approved schedule/tax evidence. T013 late-capture/refund/cancellation final disposition remains blocked; T014 remains dependent on P18/B-11 only. Independent review templates are prepared, but no reviewer is appointed and no review is complete.
