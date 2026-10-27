# P25 Tasks — PARTIAL

**Prerequisites:** `spec.md`, `research.md`, blocked `plan.md`; do not mark completed from checked predecessor tasks alone.

**Classification, 2026-10-27:** The user explicitly approved proceeding past the unchecked-checklist confirmation for independent scope only. Checklist markers remain unchanged; missing policy approvals are not waived.

Clarification session: the user selected ledger-confirmed issuance, a centrally configured hold duration, late-capture reacquisition or approved refund, denial during pending refund, and event/gate-specific operator assignments. These are recorded in `spec.md`; the relevant owner approvals, numeric duration, contracts and remaining policy details are still missing. Classifications below remain unchanged.

Clarification continuation: the selected hold expiry is the earlier of the configured duration or event start, with authorised organiser configuration; a rejected/cancelled refund leaves admission blocked pending review; replacement of a lost credential requires holder verification and revocation of the old credential. Transfer and cancellation refund policies remain pending. Owner approvals and the specific authority and verification contracts remain outstanding; the classifications remain unchanged.

Authority continuation: the user selected active membership plus event-management permission for authoring, an independent publication reviewer, approval invalidation after any pre-publication edit, event-specific approved re-entry policy, and gate assignment by an organiser with a gate-assignment permission. Permission definitions, assignment/revocation contracts, re-entry recording and owner approval evidence remain outstanding. Classifications remain unchanged.

Re-entry continuation: selected one admission with separate re-entry records, an event choice to allow or deny re-entry, recorded exit before re-entry, an approved event-specific limit and denial of the next online scan after assignment revocation. No limit value, in-progress scan rule, authority contract or owner approval was supplied; T005/T011 remain blocked.

Race continuation: user selected commit-order handling for scan versus operator revocation; release of expired inventory while payment stays unresolved; reacquisition after any released hold even if capture preceded expiry; stable existing expiry on duration changes; and shortening holds when an approved schedule change moves event start earlier. Transaction design, owner approval and race tests remain outstanding. Classifications remain unchanged.

Verification continuation: holder verification remains pending; a separately authorised support or finance reviewer may restore eligibility only after an unsuccessful refund is verified and the ticket remains eligible; the selected initial release is online-only; an authorised organiser configures the event's re-entry limit subject to review. Permission contracts, limit value and owner approvals remain outstanding. Classifications remain unchanged.

Payout continuation: the selected model permits an authorised organiser to request payout after completion and reconciliation when liabilities are resolved or covered by an approved reserve; Finance reviews/approves payout and an authorised Finance operator sets the reserve under approved policy. An organiser with approved completion permission may mark the event complete; completion timing remains pending. No reserve calculation, permission contracts, owner approvals or P18 execution/settlement contracts were supplied. Classifications remain unchanged.

Settlement continuation: user selected excluding bank proceeds until provider settlement is verified and reconciled, waiting for all relevant bank proceeds, recording post-payout liabilities for authorised Finance review, and a different Finance reviewer for payout approval than the reserve setter. Wallet-proceeds eligibility remains pending. Definitions, recovery and permission contracts, owner approvals and P18 implementation remain outstanding; classifications remain unchanged.

Entitlement continuation: attendee assignment remains pending; full order quantity must be reacquired or the approved refund path used; extra verified payments stay open for Finance review with at most one ticket set; proposed post-publication edits pause new sales, and rejected edits remain paused until explicitly resumed by an authorised operator. Permissions, resumption checks, owner approvals and financial contracts remain outstanding; classifications remain unchanged.

| Task | Classification | Basis / dependency |
|---|---|---|
| T004 | BLOCKED | Selected direction exists, but approved numeric hold duration, Finance-approved issuance threshold and versioned Payments confirmation/refund/late-capture contracts are absent. See D-01/C-01/C-02 in research.md. |
| T005 | BLOCKED | Membership permission, event/gate assignment, online-only and redacted response directions are recorded. Permission IDs/evidence, holder-verification contract, numeric re-entry limit, and refund/credential state contracts remain absent. See D-02/C-03. |
| T006 | BLOCKED | Cancellation review direction exists; reactivation, cancellation refund, completion, reserve, settlement, payout and retention policies remain unresolved; P18 AD-14/AD-10/LC14 evidence is absent. See D-03/C-05. |
| T007 | BLOCKED | Checklist confirmation is waived for independent work only. Review of T020 can proceed; full review cannot pass without T004–T006 evidence and independent Security/Finance review. |
| T008 | DEPENDENT | Draft schema/domain validation is complete. Authoring API/UI, reviewer workflow and publication depend on authority contracts, Finance currency assignment, review evidence and established API/UI ownership (C-03/C-04). |
| T009 | BLOCKED | Atomic reservation is routine technical design using existing database conventions. Implementation needs approved duration, supported currency and fee/tax quote, availability and idempotency contracts (D-01/C-01/C-02). |
| T010 | BLOCKED | Verified success plus ledger recording is a selected direction, not Finance approval. Durable shared Payment confirmation/refund and ticket-entitlement contracts and fee/tax policy are missing (C-01/C-02). |
| T011 | BLOCKED | Status/category-only response and fail-closed online behavior are specified. Remaining blockers: permission/assignment/revocation and credential/refund contracts, numeric re-entry limit, retention evidence, and real concurrency/security verification (D-02/C-03/C-05). |
| T012 | BLOCKED | Initial offline admission is expressly not authorized; future implementation requires separate explicit approval, scanner threat model and reconciliation contract. |
| T013 | BLOCKED | Reacquire-or-approved-refund direction exists. Payments refund evidence/idempotency contract and approved cancellation/refund disposition remain absent (D-01/D-03, C-02/C-05). |
| T014 | BLOCKED | P18 execution/settlement remain explicit stubs; AD-14/AD-10/LC14 and payout eligibility/custody contracts remain unresolved (D-03/C-05). |
| T015 | DEPENDENT | Draft-only API/UI can be considered after identity/UX ownership and permission contracts are evidenced. Publication/customer/gate screens depend on T008–T011 service contracts; no routes currently exist. |
| T016 | DEPENDENT | End-to-end financial acceptance depends on T009–T015 and independent review; technical integration checks follow when those contracts/tasks are ready. |
| T017 | READY (completed) | Draft-only Events data and domain foundation are supported by LC07; use an injected, deny-by-default authorisation contract and no public endpoint. No publication, price quote or sales. |
| T018 | READY (completed) | Test draft validation, denied default authority, tenant scoping and absence of publication capability. |
| T019 | READY (completed) | Update traceability, flow, evidence and restart records for completed independent scope. |
| T020 | READY (implemented) | Draft-only constraints: positive event-wide order limit, Finance-owned currency boundary, explicit-offset timestamps validated against selected IANA zone, invalid/ambiguous local-time rejection, sales close by event start. |

Clarification continuation 2026-10-27: selected explicit authorised resumption after an approved post-publication version is published; existing orders keep purchase-time terms; reject capacity reductions below sold tickets plus active holds; and pause sales for every proposed post-publication edit, including non-commercial edits. These choices are not owner approvals. Operator permissions, publication workflow, capacity accounting/concurrency and versioned order evidence remain outstanding. T004–T016 classifications are unchanged; the checklist remains 2/7 checked.

Refund-capacity clarification 2026-10-27: confirmed refund plus credential revocation is required before returning capacity; return is allowed only while the approved sales window is open and before event start. Public-event clarification: customer pages show approved organiser/sponsor names and descriptions, with contact details only if explicitly public and approved; during edit review retain the last approved event page and hide the draft; retain sold-out/sales-closed pages with checkout disabled. These are selected directions, not approved privacy or domain contracts. T004–T016 classifications remain unchanged; the checklist remains 2/7 checked.

Event-time clarification 2026-10-27: use an explicitly selected event time zone for start/sales deadlines; display customer times with that zone label; reject ambiguous or nonexistent daylight-saving local times; keep issued tickets valid after an approved reschedule, with cancellation/refund separate; require sales to close at or before event start. These choices are not owner approvals. Time, display, schedule-update and validation contracts remain outstanding; T004–T016 classifications remain unchanged; checklist remains 2/7 checked.

Order/refund clarification 2026-10-27: multi-ticket orders require an approved per-event maximum applied across categories; refunds after confirmed admission are denied except by separate authorised Finance review; general refund eligibility for unused tickets remains pending. Expired-hold inventory restores checkout automatically only for an approved event on sale with available capacity and no review/operator pause. Limit, refund and state-transition approvals/contracts remain outstanding; task classifications remain unchanged and checklist remains 2/7.

Capacity/checkout clarification 2026-10-27: enforce per-category and aggregate event capacities; category caps are firm, reallocations cannot undercut sold tickets plus active holds, unallocated capacity is unavailable until authorised allocation; all categories share one event currency; show ticket price and confirmed Payments fees separately with total before confirmation. Values, allocation permissions, currency, fee and Payments contracts/approvals remain outstanding. T004–T016 classifications unchanged; checklist 2/7.

Currency/fees clarification 2026-10-27: authorised Finance/platform administrator selects the event currency; currency locks after first order or active hold; fee responsibility and tax handling remain pending approved Finance/Legal policy; paid-ticket publication/sales are blocked until supported currency is assigned, with no default. Permission, supported-currency, fee/tax and Payments quote contracts remain outstanding; T004–T016 classifications unchanged; checklist 2/7.

Pricing/payment-scope clarification 2026-10-27: organiser proposes positive ticket prices and a different event reviewer approves publication; Finance must separately approve ticket prices. P25 excludes zero-price tickets (separate P26 scope); ticket sales stay disabled when shared Payments has no enabled method for event currency. Pricing/Finance permissions, method-availability and Payments contracts remain unapproved. T004–T016 classifications unchanged; checklist 2/7.

Checkout/privacy clarification 2026-10-27: total changes require new customer confirmation; quote/payment initiation expires with the hold; authenticated active account required; cumulative per-customer cap and mandatory policy acknowledgement remain pending approved decisions. Contracts and owner/legal approvals remain outstanding. T004–T016 classifications unchanged; checklist 2/7.

Ticket-notification clarification 2026-10-27: issue, reschedule and cancellation notices use shared Notifications and customer preferences; scannable credentials stay in authenticated accounts and are excluded from external notices. Cancelled event pages remain visible with sales disabled; published cancellation requires a different authorised reviewer before status change/notices. Notification, credential, cancellation and authority contracts/approvals remain outstanding. T004–T016 classifications unchanged; checklist 2/7.

Gate/lifecycle clarification 2026-10-27: gate responses expose only valid/invalid and category; deny entry (and re-entry/exit state changes) when online validation is unavailable; re-entry limit counts returns only. Cancelled-event reactivation remains pending approved policy. Privacy, availability and lifecycle contracts remain outstanding; T004–T016 classifications unchanged; checklist 2/7.

- [X] T001 Verify architecture source path, SHA-256, version, LC07 and section 2; record `research.md`.
- [X] T002 Inspect P18/P19/P22/P23, Events, Payments, Wallet, ledger and E25 evidence; record limitations in `research.md`.
- [X] T003 Create package 072 via installed feature script; resolve plan/task templates and preserve prior active feature pointer.
- [ ] T004 Obtain versioned approved payment threshold, hold duration/expiry and late-capture policy. **BLOCKED**.
- [ ] T005 Obtain organisation and gate authority, refund-in-flight admission, transfer/replacement/re-entry and offline policies. **BLOCKED**.
- [ ] T006 Obtain cancellation/completion, reserve/settlement/payout eligibility, retention and AD-14/AD-10/LC14 evidence. **BLOCKED**.
- [ ] T007 Complete Spec Kit specification-quality review, planning and required implementation checklist gate after T004–T006; record owner approvals and contract versions.
- [ ] T008 [US1] Implement and test authorised event authoring, review and publication, including categories, capacity, terms, organisers and sponsors, after scope approval.
- [ ] T009 [US2] Implement atomic inventory hold and server-owned quote, with concurrent last-ticket tests, after T004/T007.
- [ ] T010 [US2] Implement shared checkout, durable confirmation and idempotent repairable ticket issuance; test duplicate/reordered notifications and failure after payment.
- [ ] T011 [US3] Implement scoped online gate admission and audit; prove at-most-once admission with real concurrency and cross-tenant security tests after T005/T007.
- [ ] T012 [US3] Implement offline admission only if separately approved, with manifest limits and reconciliation.
- [ ] T013 [US4] Implement approved late capture, refund and closure controls; preserve independent financial states.
- [ ] T014 [US4] Integrate organiser payout only after P18 execution/settlement and finance decisions are approved and verified.
- [ ] T015 Implement permitted admin, customer and gate interfaces; run API, privacy, accessibility, migration, static analysis and build checks.
- [ ] T016 Record end-to-end reconciliation and financial acceptance, update register and restart notes; retain disabled gates until release approval.
- [X] T017 [US1] Build draft-only Events schema and scoped application service with explicit fail-closed authorisation, category/capacity and supplied policy text; no HTTP, publication or payment integration.
- [X] T018 [US1] Run draft foundation migration and meaningful local security/validation tests and scoped static checks. Local migration, focused tests, PHPStan and Pint pass; no release acceptance is implied.
- [X] T019 Update P25 traceability, runbook, evidence, register and restart notes with results and remaining decision gates.
- [X] T020 [US1] Enforce draft-only constraints for timezone-safe timestamps, sales deadline, positive event-level order limit and Finance-owned currency assignment; add focused regression tests. No publication, hold, quote or sales capability.
