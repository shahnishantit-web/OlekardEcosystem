# P25 Tasks — PARTIAL

**Prerequisites:** `spec.md`, `research.md`, partial `plan.md`; use the 2026-10-28 blocker ledger for current task state. Do not infer predecessor readiness from checked boxes.

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

| Task | Current state | Exact blocker/dependency |
|---|---|---|
| T004 | NOT VERIFIED | Selected expiry, threshold and late-capture directions are recorded; Payments already emits `PaymentConfirmedEvent` after durable ledger posting. What remains is owner approval evidence for P25's policy use, not a missing generic Payment event contract. See B-05/B-07/B-12. |
| T005 | BLOCKED | Exact permission IDs and assignment/revocation contract (B-01), numeric return allowance and holder/refund/transfer decisions (B-09) remain unresolved. Redacted response and online-only choices are resolved. |
| T006 | BLOCKED | Cancellation refund/reactivation/completion/reserve/settlement/retention decisions (B-09/B-10) and P18 payout evidence (B-11) remain unresolved. |
| T007 | NOT VERIFIED | Independent Security and Finance review has not been performed or recorded (B-12); approval to pass the checklist confirmation is not review evidence. |
| T008 | PARTIALLY IMPLEMENTED; BLOCKED for usable access/publication | Draft and versioned review/publication core now exist, including independent organiser/reviewer checks, Finance price approval, current-version checks, immutable published snapshot, post-publication edit pause and explicit resume. New migration/test: 2026_10_28_100003 and EventDraftFoundationTest. Still no production permission identifiers/authorizer, Finance currency/payment-method readiness contract, routes or admin/mobile interface (B-01/B-02/B-03). All production bindings deny. |
| T009 | NOT IMPLEMENTED; DEPENDENT on T008 | Atomic holds and inventory accounting are absent (B-04). Hold rules are sufficiently specified; no global numeric default is needed. Must reference a published version and deny when no authorized duration is configured. |
| T010 | NOT IMPLEMENTED; DEPENDENT on T009 | P25 purpose resolver, order reference, idempotent confirmation consumer and ticket issuance are absent (B-05). Fee/tax decision blocks payable quotes (B-06); generic payment/ledger event contract exists. |
| T011 | NOT IMPLEMENTED; DEPENDENT on T010 | Online admission endpoint/credential state/concurrency implementation absent (B-08); real authority and credential/refund evidence depend on B-01/B-09. Privacy/offline requirements are specified. |
| T012 | BLOCKED / OUT OF INITIAL RELEASE | The user selected online-only initial admission; no offline feature may be implemented absent separate explicit approval and reconciliation contract. |
| T013 | BLOCKED; NOT IMPLEMENTED | Reacquire-or-refund direction is selected, but no P25 order/hold state exists and unused-ticket refund eligibility/evidence remain unresolved (B-04/B-07/B-09). |
| T014 | BLOCKED | P18 execution/query and settlement services are explicit throwing stubs; AD-14/AD-10/LC14 and P18 T059/T060 evidence remain open (B-11). |
| T015 | NOT IMPLEMENTED; DEPENDENT | No P25 admin/customer/gate interfaces or routes exist. Draft UI/API requires T008/B-01; checkout requires T009/T010/B-03/B-06; gate UI requires T011. P22/P23 checks are integration verification debt, not backend prerequisites (B-14). |
| T016 | NOT VERIFIED; DEPENDENT | No P25 cross-flow/concurrency/security/finance acceptance evidence exists because T008–T015 implementation is incomplete (B-13); independent review is separately open (B-12). |
| T017 | READY (completed) | Draft-only Events data and domain foundation are supported by LC07; use an injected, deny-by-default authorisation contract and no public endpoint. No publication, price quote or sales. |
| T018 | READY (completed) | Test draft validation, denied default authority, tenant scoping and absence of publication capability. |
| T019 | READY (completed) | Update traceability, flow, evidence and restart records for completed independent scope. |
| T020 | READY (implemented) | Draft-only constraints: positive event-wide order limit, Finance-owned currency boundary, explicit-offset timestamps validated against selected IANA zone, invalid/ambiguous local-time rejection, sales close by event start. |
| T021 | READY (implemented; internal only) | Implemented version-locked event review/publication state transitions and snapshot preservation behind deny-by-default authority/readiness contracts. No public API/UI or paid activation. Tests: EventDraftFoundationTest. |

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
- [ ] T004 Link owner approval evidence for the selected payment-plus-ledger issuance threshold, organizer-configured hold expiry and late-capture disposition; record the approved policy version. The generic durable Payment confirmation event already exists; no arbitrary global hold duration is required. **NOT VERIFIED**.
- [ ] T005 Obtain the Identity/Permissions contract and approved policy for event/gate scope, holder verification, re-entry count value, refund/transfer eligibility and recovery. Online-only and status/category-only scan decisions are already recorded. **BLOCKED**.
- [ ] T006 Obtain approved cancellation/refund/reactivation/completion/reserve/retention policy and P18 AD-14/AD-10/LC14 owner evidence. **BLOCKED**.
- [ ] T007 Complete independent Security and Finance reviews and record reviewer evidence/findings; retain the five unchecked Spec Kit review items until their specific criteria pass. **NOT VERIFIED**.
- [ ] T008 [US1] Implement and test authorised event authoring, review and publication, including categories, capacity, terms, organisers and sponsors, after scope approval.
- [ ] T009 [US2] Implement atomic inventory holds and server-owned category-capacity accounting after a published event state exists; use no hold if organizer duration is unset. Add independent PostgreSQL last-ticket/expiry concurrency tests. Implement payment quote only after supported currency/method and fee/tax rules are available (B-03/B-06).
- [ ] T010 [US2] Implement shared checkout, durable confirmation and idempotent repairable ticket issuance; test duplicate/reordered notifications and failure after payment.
- [ ] T011 [US3] Implement scoped online gate admission and audit; prove at-most-once admission with real concurrency and cross-tenant security tests after T005/T007.
- [ ] T012 [US3] Offline admission remains outside the initial online-only release; implement only after separate explicit approval, threat model, manifest limits and reconciliation contract. **BLOCKED**.
- [ ] T013 [US4] Implement approved late capture, refund and closure controls; preserve independent financial states.
- [ ] T014 [US4] Integrate organiser payout only after P18 execution/settlement and finance decisions are approved and verified.
- [ ] T015 Implement permitted admin, customer and gate interfaces; run API, privacy, accessibility, migration, static analysis and build checks.
- [ ] T016 Record end-to-end reconciliation and financial acceptance, update register and restart notes; retain disabled gates until release approval.
- [X] T017 [US1] Build draft-only Events schema and scoped application service with explicit fail-closed authorisation, category/capacity and supplied policy text; no HTTP, publication or payment integration.
- [X] T018 [US1] Run draft foundation migration and meaningful local security/validation tests and scoped static checks. Local migration, focused tests, PHPStan and Pint pass; no release acceptance is implied.
- [X] T019 Update P25 traceability, runbook, evidence, register and restart notes with results and remaining decision gates.
- [X] T020 [US1] Enforce draft-only constraints for timezone-safe timestamps, sales deadline, positive event-level order limit and Finance-owned currency assignment; add focused regression tests. No publication, hold, quote or sales capability.

| T022 | READY (completed; review draft only) | Prepared `authorization-matrix.md` from approved Identity/RBAC implementation and recorded P25 decisions. This does not approve permissions, clear B-01, or enable endpoints; T008 remains blocked pending owner review. |

**B-01 matrix checkpoint, 2026-10-28:** permission matrix is prepared for review; it is not approved. No remaining P25 task is READY for further implementation without the scoped authority decision or already-dependent services. See `research.md` and `authorization-matrix.md`.

## Additional task

| Task | Classification | Result |
|---|---|---|
| T022 | READY / completed | B-01 permission matrix prepared for owner review; it does not approve permissions or clear B-01. |

## Additional P25 tasks

| Task | Classification | Result |
|---|---|---|
| T023 | READY / completed (assessment only) | Identified Identity/E04 global RBAC versus Events-owned resource scope; prepared conditional bounded E04 dependency proposal. No E04 package started. |
| T024 | READY / implemented | Added cumulative contributor provenance per event version and enforced that contributors cannot approve event or Finance review; enforced distinct event and Finance approvers. Production authorizers remain deny-by-default. |

### 2026-10-28 decision/classification correction

A-01/A-02/A-04 are accepted product decisions, not independent Identity/Security/Finance/Privacy approvals. A-03 is accepted in principle, with exact mapping and grant/revoke authority still awaiting review. T005 stays BLOCKED pending that mapping/contracts and other admission evidence; T008 stays blocked for public access; T007 stays NOT VERIFIED pending independent reviews. Re-entry-only counting and organiser configuration subject to event review are settled; only the numeric return limit remains unresolved. Do not mark policy checklist items complete.

## 2026-10-28 implementation update — scoped authorization

The user accepted the proposed P25 permission mapping for development. Both existing authorization contracts are now backed by Events resource-scope checks plus Identity's existing `hasPermission` coarse check. No E04 scoped-grant extension was needed: the existing Identity contract is sufficient for coarse permission evaluation, and Events owns the resource assignments. No global role grants or assignment-management endpoints were added.

| Task | Updated classification | Evidence / remaining blocker |
|---|---|---|
| T005 | PARTIALLY IMPLEMENTED; admission remains BLOCKED | Events now has organisation/event/gate scope checks; exact gate revocation is queried live and row-locked inside the caller's admission transaction. No ticket/credential/scan API, gate registry, real scan/revoke concurrency test, or numeric return maximum exists. Re-entry stays unavailable without its configured limit. |
| T008 | PARTIALLY IMPLEMENTED; accessible publication remains BLOCKED | All accepted `events.*` slugs are registered in Identity without role grants; draft and workflow authorizers require coarse permission plus exact Events assignment. Draft read/update also require active membership. No event-authoring/review UI/API; publication readiness remains deny-by-default pending B-03. |
| T007 | NOT VERIFIED | Independent Security and Finance reviews/evidence remain outstanding; the user's development direction is not review approval. |
| T011 | AUTHORIZATION FOUNDATION IMPLEMENTED; admission NOT IMPLEMENTED | Exact event/gate checks and revocation query exist. Admission persistence and credential contract are absent; numeric re-entry maximum D-02 remains open; concurrency is not verified with separate DB sessions. |
| T015 | PARTIAL / DEPENDENT | Assignment-management and gate configuration APIs exist; event authoring/review API, admin/mobile UI, ticket purchase and scanner/admission flows do not. Scanner UI/API depends on T011. |
| T016 | NOT VERIFIED / DEPENDENT | Focused scope tests pass; cross-flow admission, ticket ownership, reporting privacy, full security and financial acceptance are not established. |
| T022 | READY / IMPLEMENTED for development | `authorization-matrix.md` mapping accepted for development; permission IDs registered; no default grants. Independent owner reviews remain gates. |
| T025 | READY / IMPLEMENTED | Events-owned assignment schema and read-side authorization service; creation requires org-scoped grant; event actions require exact event grant; gate validation requires exact gate/event assignment and transaction lock. |
| T026 | READY / IMPLEMENTED | Tests cover permission-only denial, assignment-only denial, cross-event/org/gate denial, revocation, gate-delegation limit, contributor/reviewer separation, and no global role grants. |
| T027 | READY / IMPLEMENTED (development path) | Events-owned org/event assignment grant/revoke services and endpoints, administrator delegation/revocation, issuer-history lookup/audit, idempotency and explicit bootstrap command are implemented. No assignment administrators or permission role grants were seeded. Operational bootstrap remains blocked pending Identity-controlled permission designation and approved evidence. |
| T028 | BLOCKED / NOT IMPLEMENTED | Refund request needs authoritative ticket/order ownership and approved refund eligibility, and must call shared Payments. Those ticket and refund contracts/data are not implemented in P25. Organiser payout remains blocked by B-11/P18. Aggregate attendance endpoint is absent; no attendee-level data is exposed. |
| T029 | READY / IMPLEMENTED (development path) | Added Events-owned gate registry with event/organisation binding, event-scoped gate-manager authorization, and gate-validation grant/revoke limited to the manager's exact assigned event and the validation permission. No admission or scanner flow was enabled. |

The 2026-10-28 migration `2026_10_28_100005_add_scoped_event_permissions_and_assignments.php` was applied locally. It creates Events assignment/audit tables and registers the 17 accepted action permissions in Identity; it creates zero role-permission grants. Assignment-administration permissions and provenance tables are added by migration 100006. Test fixture rows are test-only.

Re-entry: organiser configuration and return-only counting remain established; only D-02's numeric maximum is unanswered. No count threshold or default was invented. B-03 Finance currency/payment readiness, B-11 payout/settlement dependencies, and independent reviews remain separate.

Verification correction 2026-10-28: after adding grant-boundary cases, final P25 scoped suite is 19 passed / 0 failed / 0 skipped / 100 assertions; Pint passed; PHPStan on Events app 0 errors; migration 100005 ran locally in batch 13. These checks do not prove the unimplemented assignment authority writer, separate-session gate concurrency, ticket ownership/refund, aggregate report privacy, or independent review.

### 2026-10-28 assignment authority implementation

Product/design authority decisions from the latest user direction are accepted for development, not independent Security/Finance approval. T027 is implemented for organisation and event assignments only. Finance assignment grants require a separately designated Finance assignment administrator; non-Finance grants require the organisation authority. Both require the Identity coarse permission and active organisation designation. Self-assignment and self-bootstrap are rejected. Delegating/revoking administrator authority takes effect on the next write; issuer history supports audited identification and separately authorized revocation of grants, with no cascade.

The bootstrap command requires two distinct active organisation members with the explicit bootstrap-approval permission, an active target member with grant/revoke permissions, an explicitly authorized operator, an approval reference and an unused organisation/authority slot. No fixture or default administrator is seeded. Command execution is not operationally approved; no production permission grants or approval records exist. Gate delegation remains BLOCKED under T029 until an authoritative gate registry is implemented. Checklist remains 2/7 checked; this work does not clear independent review, live finance or admission criteria.


Correction to the preceding classification text: T029's earlier BLOCKED wording described the pre-implementation gate-registry gap and is superseded by the READY / IMPLEMENTED row above. Migration 100007 and gate manager APIs have now supplied that registry; admission and scanner work remain incomplete. Final focused verification replaces earlier test counts: 27 passed / 0 failed / 0 skipped / 136 assertions.
## 2026-10-28 continuation � bootstrap evidence, review package and authoring surface

| Task | Current classification | Evidence / next gate |
|---|---|---|
| T007 | READY FOR INDEPENDENT REVIEW; NOT VERIFIED | `identity-security-review-index.md` links code/tests and lists reviewer questions/acceptance. No designated named Identity/Security reviewer or appointment procedure is recorded. Governance owner must identify one and record signed findings. Finance and Privacy review remain separately unperformed. |
| T008 | PARTIALLY IMPLEMENTED / NOT VERIFIED | Added authenticated create/read/update, submit-for-review, Finance-price-review, event-review and rejection routes and an admin draft/review workspace. Existing Event-scoped permissions/assignments and membership checks remain authoritative. Publication and sales routes are intentionally absent; B-03 prevents a usable paid publication path. Route/API authorization tests are still needed. |
| T015 | PARTIAL | Admin authoring/review page and API are present. No customer publication, checkout, scanner, attendance or payout UI is enabled. Aggregate attendance cannot be implemented until authoritative attendance records exist (T011). |
| Bootstrap | BLOCKED / FAIL CLOSED | A configured `DenyBootstrapApprovalEvidenceVerifier` now rejects bootstrap before any authority/audit insert. Test-only positive verifier fixture is explicitly isolated in `EventDraftFoundationTest`; production bootstrap was not run. Enable only after a governance-controlled verifier and approved approver authority exist. |
| T009�T014, T016 | BLOCKED / DEPENDENT / NOT VERIFIED | Previously recorded contracts remain open. B-03 payment-readiness, D-02 numeric return maximum, T011 admission/attendance storage and B-11/P18 payout are not resolved by the authoring implementation. |

Do not interpret task classifications, product decisions or test fixtures as independent Security, Privacy or Finance approval.

## P25 checkpoint � authoring/review, inventory foundation and continuation (2026-10-28)

Status remains **PARTIAL**. This checkpoint records development implementation only. Independent Identity/Security/Privacy/Finance review is NOT COMPLETE. Operational bootstrap, paid publication, sales, deployment and P24 activation remain disabled.

### Development slice checkpoint

| Slice | Status | Evidence |
|---|---|---|
| Draft authoring and versioned review workflow (T008) | IMPLEMENTED FOR DEVELOPMENT; scoped verification PASS | Authenticated Events API routes for create/read/update, submit, Finance price review, event review and rejection; scoped authorizers; admin `/community/events` workspace. Publication route/service readiness remains unavailable in production bindings. See `EventAuthoringController.php`, `EventWorkflowService.php`, `routes/modules/events.php`, admin `event-authoring.tsx`, focused test file and review index. |
| Inventory hold foundation (T009) | IMPLEMENTED FOR DEVELOPMENT; NOT VERIFIED for concurrency | Server-side active-published-version and sales-state checks; explicit positive configured hold duration, capped at event start; unset duration denies; row-locked event/category capacity calculation, expiry release, idempotency, audit and scheduled expiry command. No customer hold endpoint, checkout or activation. Separate-session PostgreSQL last-ticket race is still not tested. |
| Admin authoring page | Scoped checks PASS; workspace-wide lint/build FAIL for unrelated source errors | Exact scoped lint succeeds with warnings. Full `tsc --noEmit` passes after Next regenerated stale `.next/types`. Full lint/build report pre-existing ESLint errors in payment-bank and collections files; see `evidence.md`. |
| Independent review and operational activation | NOT COMPLETE / DENY-BY-DEFAULT | Handoff and review index prepared only. No reviewer appointed in project records, no signed findings, no production bootstrap or grants. |

Focused verification: `vendor\\bin\\pest tests\\Feature\\Modules\\Events\\EventDraftFoundationTest.php` **31 passed / 0 failed / 0 skipped / 152 assertions**. `vendor\\bin\\pint --test` for the touched Events PHP files PASS. `vendor\\bin\\phpstan analyse app\\Modules\\Events --no-progress` PASS, 0 errors (tool emits old-version/deprecated-config notices). Events expiry command is registered. Migration `2026_10_28_100008_add_inventory_holds.php` is Ran only on local `platform` DB; this is not deployment evidence. No live provider, customer, ledger or payout behavior is claimed.

### Remaining P25 work and immediate dependency

| Task | Current classification | Immediate dependency / unblock |
|---|---|---|
| T004 payment-confirmation issuance threshold and late-capture disposition | AWAITING SPECIFIC PRODUCT/OWNER DECISION | Evidence must define which shared Payment + ledger state authorizes entitlement issuance, and the approved outcome after capture once a hold expired. Obtain versioned owner policy and consumer recovery contract. This is separate from P18 payout. |
| T005 holder, credential, refund/transfer and numeric re-entry policy | AWAITING SPECIFIC PRODUCT DECISION / CONTRACT | Ticket/order ownership model, credential lifecycle and refund/transfer eligibility are absent. D-02 blocks the re-entry branch only: decide the numeric maximum returns; �returns only� count semantics remain settled. Identity and Privacy contracts/review are also required. |
| T006 event cancellation/completion, refund/reserve and closure | AWAITING SPECIFIC PRODUCT DECISION; payout sub-scope AWAITING P18 | Cancellation/reactivation, unused-ticket refund, reserve reconciliation and completion policy remain open. Payout acceptance alone depends on P18; do not make inventory, issuance or single-entry admission wait on P18. |
| T007 independent security/finance/privacy review | AWAITING INDEPENDENT REVIEW | Governance must identify/appoint authorized independent reviewers and store signed disposition against the reviewed source tree. Handoff is prepared, not sent. |
| T008 authoring/review/publication | AUTHORING/REVIEW SLICE IMPLEMENTED; publication AWAITING B-03 + independent review | Admin/API authoring and review development slice is checked below. Publish remains absent/denied until Finance currency and Payments event-readiness contract exists; no operational grants. |
| T009 inventory holds | IMPLEMENTED; AWAITING VERIFICATION | Run independent-session PostgreSQL race tests for last-ticket reservation, expiry-vs-reserve, edit-vs-hold and idempotent replay. No P18 dependency. Hold duration behavior is already specified; no global/default duration is introduced. |
| T010 checkout and ticket issuance | AWAITING B-03 CONTRACT + B-06 FEES/TAX + T004 threshold | Define server-authoritative event currency and event-level method readiness, Finance fee/tax terms, canonical ticket purpose and issuance state; then implement shared Payments intent/confirmation consumption. P18 payout is not a dependency. |
| T011 online admission and attendance | DEPENDENT on T010 credential/entitlement model; AWAITING D-02 only for re-entry | First-entry work depends on credential issuance, approved privacy/security rules and real scan concurrency; return counting needs D-02 numeric maximum. Online-only fail-closed decisions are established. No direct P18 dependency. |
| T012 offline admission | AWAITING EXPLICIT PRODUCT/THREAT-MODEL APPROVAL | Separate offline authorization, manifest/expiry bounds, duplicate-risk and reconciliation contract; offline remains outside initial release. |
| T013 refunds, cancellation and late capture | AWAITING PRODUCT DECISION + SHARED PAYMENTS REFUND CONTRACT | Define unused/unadmitted eligibility, cancellation compensation, capture/expiry resolution and admission/refund ordering. Shared Payments must provide approved refund behavior. No provider endpoints invented. |
| T014 organiser payout | AWAITING P18 + B-11 | P18 T049/T050/T051 execution/query/retry route (AD-14); T055/T056 authoritative settlement evidence and ledger mapping (AD-10/LC14); T059/T060 verification remain required. No other P25 flow is blocked by this payout dependency. |
| T015 interfaces/reporting | Authoring admin surface IMPLEMENTED; remaining surfaces DEPENDENT | Customer checkout waits on T010; scanner/gate interface and attendance records wait on T011; aggregate-only organiser report waits on authoritative attendance records. No customer-facing admission/purchase behavior exposed. |
| T016 acceptance/reconciliation | AWAITING VERIFICATION + INDEPENDENT REVIEW | Depends on flow-specific checks above; payout subsection alone waits for P18. Existing suite-construction issue remains separate from focused passing tests. |

**READY tasks remaining: none.** T009 implementation is present but its required concurrency evidence is outstanding (NOT VERIFIED). Other work has a specific decision, contract, predecessor implementation or prerequisite task. The smallest useful next implementation unlock is the B-03 owner contract for Finance-assigned event currency plus actor-free Payments readiness; that enables paid-publication eligibility design. T010 still additionally needs B-06 and T004. D-02 only unlocks re-entry behavior, not single-entry admission. P18 is the next package only for T014 payout, and is not started here.

### B-03 contract proposal

`b03-payment-readiness-contract-proposal.md` is a source-based proposal, not an approved contract. Existing Finance/MultiCurrency currency records expose active code, name, symbol and decimal precision; existing shared Payments checkout `OptionsQuery` is actor-specific and amount/purpose/context scoped; ProviderRegistry evaluates provider/account lifecycle, configured currencies/methods, organisation/module/purpose/country, amount bounds and capabilities. Configuration is not provider certification.

Proposed additions are (1) Finance-authorized versioned event currency assignment with provenance and first-hold/order lock, and (2) typed actor-free event-level readiness over registered event/organisation/country/ticket-purpose/currency/price context with configuration versions and an owner-approved freshness rule. This only answers whether an event may be published; it is not a promise that each customer can pay. At checkout, Events builds an authoritative `OptionsQuery` for the authenticated customer and exact amount, where wallet balance can be considered. Business approvals and provider certification evidence are enumerated in the proposal. No provider capabilities, slugs, currency or credentials were fabricated.

### Review handoff

`independent-review-handoff.md` and `identity-security-review-index.md` form a review package, not an appointment or approval. Requested competencies: Identity/RBAC, application security, database/concurrency/audit engineering, Privacy, plus separate Finance review. Project records do not identify a named reviewer or appointing authority. Acceptance requires independent review of exact scoped grants, delegation and revocation, bootstrap verification, concurrency/audit evidence, data minimization, and signed findings. Automated/self-review does not satisfy it.

### Admin workspace diagnostics

Repository-supported `npm.cmd run lint` exits 1 for existing ESLint errors across payment-bank form pages and collections pages. P25 `event-authoring.tsx`, navigation and `/community/events` route files have no lint errors; scoped lint exits 0 with four existing nullable-string warnings in `campaign-list.tsx`. `npm.cmd run build` compiles successfully then fails during Next lint/type stage on the same workspace ESLint errors (unused imports/variables and unassociated form labels in bank/collections pages). Generated `.next` route types were stale and referred to removed role pages; the normal build regenerated them. `node node_modules/typescript/bin/tsc --noEmit` then exits 0. Preserve `tsconfig.tsbuildinfo`�s pre-existing tracked state when finishing. Whole-app lint remains a failed check; do not call the admin build successful.

### Source and traceability

- Approved source/reference and decisions: P25 `research.md` and `spec.md` (LC07/section 2; D-01�D-09 and C-01�C-07; product decisions A-01�A-04 and the later recorded clarifications).
- T008/T009 code: API `app/Modules/Events/Application/EventDraftService.php`, `EventWorkflowService.php`, `EventInventoryHoldService.php`; controllers under `app/Modules/Events/Infrastructure/Http/Controllers`; `routes/modules/events.php`; migration `2026_10_28_100008_add_inventory_holds.php`; command `app/Modules/Events/Console/ExpireEventInventoryHolds.php`; admin `src/modules/community/pages/event-authoring.tsx`, `app/(admin)/community/events/page.tsx`, navigation `src/lib/navigation/nav-items.ts`.
- Automated evidence: `tests/Feature/Modules/Events/EventDraftFoundationTest.php`; precise results and commands in `evidence.md`.
- Finance/Payments readiness gap: `b03-payment-readiness-contract-proposal.md`; review request: `independent-review-handoff.md` and `identity-security-review-index.md`.

No production bootstrap, role/assignment grant, paid publication, live sales, live provider, P24 activation, deployment or commit occurred.

## Verification continuation � 2026-10-28 (supersedes prior T008/T009 verification status)

### T009 real PostgreSQL concurrency result

Command: from `wallet-ecosystem/services/api`, set `APP_ENV=testing` and run `php tests/Concurrency/P25InventoryHoldPostgresTest.php`. PASS against PostgreSQL 15.19, local database `wallet_ecosystem` / `community_services` schema. The runner uses separate PHP worker processes; each reports `pg_backend_pid()`, and the parent asserts distinct backend PIDs before releasing a start barrier. It cleans its UUID-scoped fixture rows and temporary trigger/function in `finally` blocks. The test-only `AuditServiceInterface` no-op prevents unrelated platform audit writes; the real Events hold and `event_draft_audit` writes remain PostgreSQL-backed.

- Last-unit race: two independent sessions, exactly one held quantity and one HTTP 409; available invariant is not oversubscribed.
- Same actor/key/payload race: both calls return the same hold ID, one durable row; a changed payload on that key is HTTP 409.
- Expiry/release race: independent expiry worker and new reservation race; prior hold becomes `expired`, one replacement remains held, active quantity equals capacity (one).
- Rollback/recovery: a temporary PostgreSQL trigger rejects the Events audit insert after the hold insert. The transaction leaves zero hold rows and zero domain audit rows; after removing the trigger, the same one unit can be reserved.

This verifies the implemented hold transaction under the tested PostgreSQL/runtime and recorded hold rules. It does not test production traffic, payment/issuance, hold duration approval artifacts, publication readiness, or independent review. No SQLite, sequential-only substitute, or mock DB was used for the concurrency result.

### T008 authoring/review route verification

Command: `vendor\\bin\\pest tests\\Feature\\Modules\\Events\\EventDraftFoundationTest.php` � **36 passed / 0 failed / 0 skipped / 181 assertions**. Added route-level coverage for unauthenticated 401, permission without assignment, assignment without permission, cross-org and cross-event scope, contributor review denial, event/Finance reviewer distinction, invalid lifecycle state, stale version 409 and successful authorized submit/review/price-approval requests with test-only fixtures. Denial tests compare event rows and audit counts to prove no unintended mutation. The HTTP success paths exercise the existing production scoped authorizer classes with the test Identity permission contract; they do not create operational grants.

A first suite run exposed a test-only controller/authorizer instance cached after the unauthenticated request. Tests now bind the scoped authorizer before that request, and the permission-without-authority case uses a mutable test permission closure matching the controller's already-resolved Identity contract. Final complete focused run passes. T008 authoring/review endpoint slice is VERIFIED for development; publication and sales remain absent/denied pending B-03 and independent approvals.

Pint on the changed route and concurrency test files: PASS. PHP syntax checks: PASS for all three new/changed test files. `vendor\\bin\\phpstan analyse app\\Modules\\Events --no-progress`: PASS, 0 errors (existing PHPStan version/config and Xdebug notices remain). Existing whole-admin lint/build debt from the previous checkpoint is unchanged and not included here.

### B-03 decision review update

`b03-payment-readiness-contract-proposal.md` now includes the concrete proposed DTO, actor-free request/result, existing EligibilityReason values, proposed aggregate codes, invalidation conditions, no-cache recommendation, exact business choices and external evidence checklist. It remains a proposal. Current status: no event currency assignment API/record or Payments actor-free readiness contract exists; checkout `OptionsQuery` stays actor-specific. Owner decisions still required for every-category versus any-category readiness, whether wallet-only configuration counts, and currency deactivation/precision change after lock. Finance/Payments must supply canonical purpose/module and country-source contracts. Provider onboarding/certification artifacts are still absent.

### Updated classifications

| Task | Classification after this verification |
|---|---|
| T008 | Authoring/review route slice VERIFIED for development; full publication and sales scope awaits B-03 and independent review. |
| T009 | Inventory hold concurrency acceptance VERIFIED against real PostgreSQL in the scoped harness; full T009 remains partial where payment quote/checkouts depend on B-03/B-06. |
| T010 | Still awaiting B-03 + B-06 + T004; no P18 dependency. |
| T011 | Still dependent on T010 credentials and independent Privacy/Security review; D-02 gates re-entry only; no P18 dependency. |
| T014 | Still awaits P18 T049/T050/T051/T055/T056 and T059/T060 plus AD-14/AD-10/LC14. P18 remains payout-only dependency. |
| T007/T016 review portions | Await actual independent reviewer appointment and signed findings. No reviewer evidence was produced. |

No production bootstrap, currency assignment, publication, sales, payment, P24, deployment, P18 implementation or operational grant was performed.

## P25 B-03 implementation continuation — 2026-10-28

Implemented for development: Finance-scoped currency assignment with exact permission+event assignment+membership checks, issuer/provenance and precision snapshot, immutable lock on the first inventory hold (durable-order hook documented), actor-free static method coverage evaluator, per-category checks and Wallet-only signalling. Draft updates preserve currency assignment. Currency precision/inactive drift blocks a new hold and creates an operator-review issue. Events publication readiness remains bound to the deny implementation; no real event readiness or checkout API is exposed because canonical purpose/module/country context is absent.

Migrations 100009 and 100010 were applied using targeted `php artisan migrate --database=platform --path=... --force` commands. A general `migrate --database=platform --force` was attempted and failed before P25 migrations on legacy migration 2026_08_21_001000 because populated local identity.users already exists. No data was dropped or reset. Local targeted migrations are not deployment evidence.

P25 inventory regression added currency snapshot lock persistence through expiry, edit preservation, permission-without-assignment and assignment-without-permission denial, authorized test fixture assignment, and changed-precision issue recording. PostgreSQL concurrency harness uses distinct backend processes; it asserts one capacity unit and one durable currency lock on concurrent first holds.

T009 status: IMPLEMENTED + VERIFIED in the local PostgreSQL harness. T008 authoring/review remains verified for development; publication is deny-by-default. T010 remains BLOCKED on canonical Payments context, B-06 and T004 approvals. No P18, P24, live processing, operational assignment, bootstrap, publication, deployment or production readiness.

Verification correction 2026-10-28: latest Events suite 40 passed / 0 failed / 0 skipped / 211 assertions; latest Payment eligibility suite 21/35; four PostgreSQL concurrency scenarios passed including the first currency lock. Drift detection now persists operator issue/audit and pauses sales after rolling back the attempted hold. Scoped PHPStan and Pint pass. See evidence.md.

Latest count correction: Events 40/213 (0 failures, 0 skips); Payment eligibility 21/35 (0 failures, 0 skips). Test also proves locked event currency assignment cannot be replaced.

### P25 current continuation classifications — 2026-10-28

- **T009 inventory hold:** implementation and scoped verification are complete for development. `EventDraftFoundationTest.php` passes the inactive-currency and precision-drift rejection paths, with durable issue, paused sales and domain-audit assertions. Previously recorded real separate-session PostgreSQL inventory races pass (last capacity, replay, expiry/release and rollback/recovery). The separate MultiCurrency currency-update-vs-hold race remains NOT VERIFIED because the modules do not share a transactional currency lock. Do not represent T009 as proving that cross-module race.
- **T010 durable checkout/order:** NOT IMPLEMENTED / BLOCKED. There is no Events order creation path. Make first durable order call `lockForFirstObligation(..., 'durable_order')` atomically before order insertion a mandatory T010 criterion. Also awaits canonical context, B-06 and T004; no P18 dependency.
- **T008 publication:** authoring/review slice remains implemented for development; event country source and canonical Payment context are not established, so paid publication remains denied.
- Other task classifications remain as recorded above: D-02 numeric re-entry remains a specific decision; T014 payout awaits P18/B-11; independent reviews remain pending.

Country, canonical identifiers, cross-module locking, pending monetary-record disposition and migration repair are documented in `research.md`; do not check their acceptance boxes on the basis of this record.

### Superseding implementation status — 2026-10-28 (CTX/MC/FIN scope)

T008/T009 readiness is updated by the accepted CTX/MC/FIN development direction and evidence appended in evidence.md. Currency race verification is present using separate PostgreSQL backend sessions: writer-first precision mutation, hold-first/deactivation mutation, writer rollback with blocked hold retry and identical idempotency replay all passed. The supported lock ordering completed without deadlock; no synthetic deadlock-victim test was used because supported paths have no lock cycle. Transaction retry remains configured for retryable PostgreSQL deadlocks/serialization failures.

T009 remains development-only; T010 order creation remains NOT IMPLEMENTED and MUST atomically invoke lockForFirstObligation(eventId, durable_order) before inserting its first durable order. No paid_event_ticket resolver, production readiness binding, publication, checkout, payment initiation or sales activation was added. Earlier notes saying the currency update/hold race was unverified are superseded by this checkpoint's real PostgreSQL evidence.

### Explicit scope status matrix after CTX/MC/FIN verification

| Task/scope | Status | Immediate remaining dependency |
|---|---|---|
| T008 authoring/review routes and admin authoring | IMPLEMENTED + VERIFIED for development | Independent Identity/Security review; publication remains denied |
| T009 inventory holds, first-hold currency lock, retry/idempotency | IMPLEMENTED + VERIFIED for development on local PostgreSQL | Does not include checkout, orders, or payment initiation |
| CTX-01/CTX-02/MC-01/FIN-01 bounded coordination | IMPLEMENTED + VERIFIED for development | Cross-database extraction unsupported; final disposition of pre-existing attempts/holds still needs Finance/Payments policy |
| T010 durable order / checkout / ticket issuance | NOT IMPLEMENTED; dependent | B-06 price/fee/tax contract and T004 issuance/late-capture policy; any order insert must lock currency atomically |
| T011 ticket credentials / admission | BLOCKED | T004 durable payment threshold, T005 credential/ownership/transfer rules and D-02 numeric return cap; independent Security/Privacy review |
| T013 refund/cancellation/late capture | BLOCKED | T004/T005 decisions and shared Payments refund contract |
| T014 organiser payout | BLOCKED | P18 settlement/payout contracts and B-11; no other P25 scope depends on it |
| T016 independent acceptance | NOT VERIFIED | Named independent reviewers and signed Security, Privacy and Finance findings |

No T010 order or event resolver exists. Publication/readiness integration, checkout, new payment initiation, live sales, production bootstrap, deployment and P24 remain disabled.

Final business-policy decision handoff: see business-policy-decision-packet.md for one consolidated, unapproved recommendation set. Do not mark any policy checklist complete from packet preparation. T010 remains dependent on B-06/T004; T011 re-entry remains unavailable pending D-02; FIN-01 hold/attempt disposition requires Finance/Payments agreement. Local AdminFx writer-path verification is still blocked by pending platform change-request migration; see evidence.md.

## Product decisions and order/ticket continuation — 2026-10-28

The user approved the following initial-scope product/design choices for development. They are not Finance/tax approval, provider evidence, independent review, or live readiness: discounts and transfers excluded; re-entry disabled (the proposed 0–5 limit is not approved); configured Payments quote TTL remains 300 seconds and never outlives/authorizes an expired inventory hold; renewed confirmation is required after any payable component or total changes, retaining prior quote and confirmation evidence; each admission entitlement gets a distinct opaque random credential with at least 128-bit entropy, no personal/payment data, and online validation; ticket ownership binds to the authenticated purchaser and authoritative order without named-attendee checks; credential replacement requires approved Identity step-up/recovery and atomically revokes the old credential.

Credential display must recover the same stored secret, never mint a new one on view. Development implementation uses Laravel `Crypt` encrypted storage material plus a SHA-256 lookup hash and random 32-byte base64url secret. The primitive is not an issuance endpoint or payment authorization. No order/ticket schema, order path, credential retrieval route, scanner endpoint or production binding was added because the authoritative order and Payment/Ledger evidence contracts are absent.

| Task | Current classification | Exact status / immediate dependency |
|---|---|---|
| T010 | BLOCKED integration; independent contract design established | No Events order model/path exists. Before order creation, implement exact server-derived price/currency/fee quote and customer confirmation under B-06; atomically call `lockForFirstObligation(eventId, 'durable_order')` before the first durable order insert. Payments/Ledger must provide verifiable evidence binding payment, order reference, organisation, purchaser, currency, amount and posted journal, including the external-method/AD-09 path. No Events resolver may bind before that model and contract exist. |
| T011 | Credential primitive IMPLEMENTED + UNIT-TESTED; entitlement/admission flow BLOCKED/DEPENDENT | `AdmissionCredentialVault` creates a unique 32-byte random opaque secret, SHA-256 lookup hash and encrypted display value. Actual per-entitlement durable storage and initial issuance depend on T010 and its verified payment evidence. Authenticated purchaser display/retrieval, step-up replacement and atomic rotation require the ticket persistence/API plus Identity step-up/recovery integration and independent Security/Privacy review. Entry is online-only; re-entry remains disabled and no 0–5 range is approved. |
| T004 | BLOCKED on owning Payments/Ledger evidence | Required contract must prove the posted-journal relationship and exact binding fields for each accepted payment method; current generic event/status surface does not provide sufficient consumer-verifiable evidence for the proposed P25 gate. Keep unknown/timed-out unresolved; no issuance on redirect, callback alone or `succeeded` alone. |
| T013 | BLOCKED on Finance policy and shared refund contract | Unused-ticket refund eligibility, cancellation financial treatment, late-capture disposition, and failed/unknown refund recovery remain unapproved. Do not infer a no-refund policy from absent capability. |
| D-02 | RE-ENTRY DISABLED | The numeric range/default proposal is explicitly not approved. Single-entry scope can proceed independently; do not expose exit/re-entry return flow. |
| B-06 | PARTIALLY RESOLVED / REMAINS BLOCKED | Discounts are excluded for initial scope and the configured 300-second quote TTL is accepted. Fee payer/schedule, qualified tax treatment and calculation/rounding remain pending. Renewed confirmation after any payable component or total changes is approved product direction. |

The credential primitive is the only newly implemented independent order/ticket component in this continuation. Do not claim T010/T011 end-to-end completion. Bootstrap, publication, checkout, sales, deployment and P24 remain disabled.

## Payments/Ledger predecessor dependency — 2026-10-28

This assessment supersedes any earlier task note implying the generic Payment confirmation event alone is sufficient for P25. T004/T010 remain BLOCKED for integrated ticket issuance pending the existing P08 continuation and its owner contracts; exact evidence mapping, missing code, AD-09 treatment, acceptance tests and dependency ordering are in `payment-ledger-dependency-proposal.md`. P08 is the existing owning package; do not create a duplicate. T011's isolated credential primitive remains independently tested, while durable per-entitlement issuance depends on T010. This does not block T008/T009 or other approved P25 work; P18/B-11 remains payout-only.

## P08 dependency checkpoint - 2026-10-28

P08 is the owning package and its bounded evidence/acknowledgement scope is now implemented for development. See `p08-dependency-checkpoint.md`, `payment-ledger-dependency-proposal.md`, and `../057-ledger-ack-fulfilment/tasks.md`. This does not implement P25 orders or ticket issuance. T004 is partially unblocked for supported paths, but P25 order binding, AD-08 verification and AD-09 Finance/provider evidence remain required. T010 remains blocked by the missing Events order model and unresolved B-06 financial inputs. P08 tests pass; schema-backed tests and full PHPStan remain incomplete. No live behavior was enabled.

## Sustained dependency-chain execution checkpoint - 2026-10-28

P08's development Wallet capture path and typed Payment/Ledger confirmation evidence are now implemented and verified on the isolated PostgreSQL environment documented in P08. This supersedes stale P25 statements that the supported Wallet capture mechanism itself was absent. It does not approve AD-08, provide AD-09 provider mappings, or enable production Wallet capture.

| P25 task | Current classification | Exact remaining dependency |
|---|---|---|
| T008 authoring/review | IMPLEMENTED + VERIFIED for development | Independent Identity/Security review; paid publication remains deny-by-default pending B-03 readiness and independent gates. |
| T009 inventory/currency coordination | IMPLEMENTED + VERIFIED for development | PostgreSQL separate-session capacity, expiry/release, rollback and currency-update races passed. This is not checkout/order verification. |
| T010 order, checkout and issuance | BLOCKED; policy/contract integration absent | B-06 still lacks fee payer/schedule, tax applicability/disclosure, rounding and authoritative payable-total rules. T004 still needs approved issuance threshold/late-capture rules plus evidence bound to an authoritative P25 order. No Events order model or inbox handler exists. P08 provides typed evidence/ack contracts but no order-reference binding. P18 is not a dependency. |
| T011 per-ticket entitlement and admission | PARTIAL primitive / BLOCKED integration | Credential vault is implemented and unit-tested; durable entitlement/ownership/retrieval/rotation and online scans require T010/order records, Identity step-up integration, approved Privacy/Security review and service behavior. Re-entry is disabled by the recorded product decision. |
| T013 cancellation/refund/late capture | BLOCKED | Finance product policy and shared Payments refund/recovery semantics remain unresolved; no no-refund fallback is permitted. |
| T014 settlement/organiser payout | BLOCKED on predecessor only | P18 payout/settlement capabilities and B-11 approval; this does not block T010/T011. |
| Independent acceptance | NOT VERIFIED | Appointed Identity/Security, Privacy and Finance reviewers and signed evidence are absent. Product decisions do not satisfy these reviews. |

P11's approved design rejects a generic inbox table where the domain effect already has a suitable unique constraint. P25 should deduplicate atomically with the durable ticket entitlement/order effect, rather than introduce a second non-atomic generic inbox. Since the authoritative order/price and T004 financial decisions remain unavailable, that Events transaction has no valid effect to commit and no inbox/issuance code was added. This is a specific dependency, not missing P18 work.

Latest run: combined scoped P08/P11/P25 suite 75 passed, 0 failed, 0 skipped, 411 assertions; P08 Wallet capture versus release/expiry races passed with distinct PostgreSQL sessions; P25 inventory and currency-coordination scripts passed with separate sessions; changed Wallet/Payment/Ledger PHPStan level 8: 0 findings. Bootstrap and full migrations were run twice on a fresh disposable database (313 migration records). No publication, order, paid checkout, live sales, production bootstrap, deployment or P24 activation occurred.

Final P08 verification correction (2026-10-28): P08 SC-01-SC-07 developer walkthrough passed in disposable PostgreSQL (81 passed / 0 failed / 0 skipped / 446 assertions; SC-04 scan 5/0/0, 8 assertions). P08 T028 is complete for this test-backed developer walkthrough, not independent operator acceptance. P08 T026 remains open: full Payment/Ledger PHPStan is 485 findings, matching the latest baseline; targeted changed slice is 0 findings.
Status clarification: P08 T028 belongs to specs/057-ledger-ack-fulfilment/tasks.md. Its developer walkthrough is now executed and recorded; no P25 T028 status is changed.

## Final verification and dependency reconciliation - 2026-10-28

| Area/task | Current status | Exact next dependency |
|---|---|---|
| T008 authoring/review | IMPLEMENTED + DEVELOPMENT VERIFIED | Independent Identity/Security review and approved operational assignment/bootstrap evidence before activation. |
| T009 inventory and currency coordination | IMPLEMENTED + DEVELOPMENT VERIFIED | Real PostgreSQL hold/inventory/currency races passed. Order-path currency lock remains a mandatory T010 acceptance test because no order insert path exists. |
| T010 order, quote, checkout and issuance | NOT IMPLEMENTED; BLOCKED on B-06 inputs, then READY for the bounded development slice | Product answers fee bearer and seller identity; Finance/Fees supplies versioned fee schedule/rules; qualified Tax/Legal supplies tax matrix. Then implement authoritative order snapshots and order-bound confirmation verification using P08 evidence. First hold/order currency lock is atomic. Preserve no-fee/no-tax assumptions as unavailable; no resolver before a complete authoritative order. |
| T011 durable entitlement, credential retrieval/replacement and online admission | Credential primitive IMPLEMENTED + UNIT VERIFIED; remaining flow DEPENDENT on T010 | Durable per-ticket entitlement and admission concurrency/authorization, then independent Security/Privacy review; replacement needs Identity step-up/recovery contract. Re-entry remains disabled. |
| T013 cancellation/refunds/late capture | BLOCKED on remaining product/Finance and Payments refund recovery decisions/contracts | Late capture after expired hold, unused-ticket refund eligibility, cancellation finances, and refund unknown/failure recovery. Do not infer no-refund behavior. |
| T014 settlement / organiser payout | BLOCKED on P18 and B-11 only | P18 shared settlement/payout capabilities plus B-11 approved eligibility. Not a dependency for T010/T011. |
| Independent acceptance | NOT VERIFIED | Reviewer appointment and signed Identity/Security/Privacy/Finance evidence must come from the established governance owners; none is inferred here. |

P08's approved P25 issuance threshold is not reopened. P08 now verifies development Wallet capture, exact captured amount/currency, posted Ledger journal correspondence, Payment evidence/outbox and scoped evidence query. The remaining T004 implementation gap is P25 order binding and Events durable deduplication/entitlement/acknowledgement; late-capture/refund disposition remains a separate undecided policy. P08/AdminFx results and B-06 decision form are in `evidence.md` and `business-policy-decision-packet.md`.

Next implementation slice when B-06 inputs are supplied: T010 authoritative order + immutable monetary snapshot + order/purpose binding + first-obligation currency lock + confirmation consumer and durable per-ticket issuance/ack, starting with test-only supported Wallet mapping. Exact owner inputs: Product�fee payer and legal seller decision; Finance/Fees�approved immutable schedule/version and fee basis; qualified Tax/Legal�jurisdiction/tax matrix and calculation rules; Payments/Ledger�written field/guarantee contract for P08 evidence and supported journal semantics. Independent reviewer appointments/evidence remain separate. No production activation, P18, P24 or deployment.

## T010/T011 implementation checkpoint — 2026-10-28 (supersedes older status rows above)

| Task | Current development status | Acceptance evidence and remaining gate |
|---|---|---|
| T010 typed pricing, authoritative order and first-order currency lock | IMPLEMENTED; DEVELOPMENT VERIFIED; production binding denied | Typed EventPricingContract, default deny implementation, order/quote migrations and routes. Test-only pricing confirms immutable nonzero fee/tax snapshots; default production contract denies quote/order. Separate-session PostgreSQL idempotency race passes. B-06/seller/Finance evidence prevents production binding; publication and paid sales stay disabled. |
| T010 P08-bound payment initiation | IMPLEMENTED for supported contract path; DEVELOPMENT VERIFIED | Purpose resolver resolves only an authoritative order; customer-specific eligibility and hold expiry are checked. P08 typed evidence is consumed. Operational account mapping and independent Finance review remain separate. |
| T011 durable, idempotent per-ticket issuance and post-commit fulfilment acknowledgement | IMPLEMENTED for approved on-time evidence path; DEVELOPMENT VERIFIED | Events inbox/conflict evidence, per-entitlement opaque credentials, atomic ticket/hold/order transaction and retry after commit-before-ack are covered by Events tests. Does not complete gate admission, concurrent scan authorization, replacement/step-up, transfer, cancellation or refund policy. |
| T011 late capture / unknown or contradictory outcomes | BLOCKED by unresolved financial disposition | Verified facts are retained and order is blocked for reconciliation; no refund/failure/issuance is invented. Requires approved late-capture/refund-failure policy and owning Payments/Finance evidence. |
| T011 admission and credential replacement | NOT IMPLEMENTED / DEPENDENT | Requires gate-validation authorization/concurrency, Identity step-up/recovery contract for rotation, and independent Security/Privacy review. Re-entry remains disabled. |

See `contracts/order-ticket-pricing-and-issuance.md` and the T010/T011 checkpoint in `evidence.md` and `restart.md`.


## T011 online admission checkpoint — 2026-10-29 (supersedes prior admission rows)

| Scope | Status | Evidence / remaining gate |
|---|---|---|
| Purchaser ticket listing and credential retrieval | IMPLEMENTED; DEVELOPMENT VERIFIED | Authenticated purchaser-only APIs return that actor's valid tickets; another actor receives no credential. Credential material is encrypted at rest and excluded from audit/log/analytics payloads. Customer app shows each ticket QR and supports Identity step-up replacement. |
| Online gate validation and attendance | IMPLEMENTED; DEVELOPMENT VERIFIED | Requires registered permission plus active exact event/gate assignment. One admission per ticket; attendance and audit insert atomically. Response contains status and ticket category only. |
| Refund eligibility gate | IMPLEMENTED; DEVELOPMENT VERIFIED | Uses Payments-owned typed refund eligibility query. Unknown, active/pending and funds-returned states deny admission. No new refund states or financial outcomes are inferred. |
| Organiser attendance reporting | IMPLEMENTED; DEVELOPMENT VERIFIED | Event-scoped permission and assignment; grouped counts by category only. No attendee identity, ticket ID or scan record exposed. Admin UI renders only aggregate rows. |
| Replacement, re-entry, transfer, offline | Replacement implemented against existing Identity step-up evidence; re-entry/transfer/offline remain unavailable | Replacement rotates credential atomically; old credential is invalid and replacement requires owner + Identity step-up. Re-entry remains disabled by product decision; transfer and offline admission are unavailable. Independent Security/Privacy acceptance is still pending. |
| Activation | DENY BY DEFAULT | Pricing, publication, live checkout/sales, operational assignment bootstrap, deployment and P24 remain disabled. |




Verification count correction (2026-10-29): final Events feature regression after cross-event and cross-organisation gate denial tests is 61 passed / 0 failed / 0 skipped / 424 assertions. The earlier 408 assertion count is superseded.


# P25 convergence reconciliation — 2026-10-29 (supersedes stale task status rows)

Use `acceptance-matrix.md` as the current cross-requirement view. Earlier task lines that call T010/T011 wholly unimplemented, or claim admission UI is absent, are stale and superseded by this section. The five review checklist items remain unchecked; permission to proceed was not approval of them.

| Task | Current engineering state | Immediate remaining gap |
|---|---|---|
| T004 — authoritative issuance evidence | Payments/Ledger evidence contract is implemented for the supported test Wallet path; P25 order binding exists in Events. The integrated real P08 outbox-to-Events journey is NOT VERIFIED. | Run E2E-01 with real Payment evidence, real Events service authorization and real consumer. Keep unsupported external/AD-09 paths unresolved. |
| T005 — online admission and credential lifecycle | Implemented and PostgreSQL development verified; Identity contract suite and customer/agent UI checks pass. | Independent Identity/Security/Privacy review. Re-entry remains disabled because numeric D-02 limit is not approved. |
| T006 — cancellation/refund/completion/settlement | Partial state separation exists; financial workflow not implemented. | Refund/cancellation policy and shared contracts; settlement/payout remains P18/B-11. |
| T007 — independent review | Review index and evidence package prepared; review not complete. | Appointed independent Security/Privacy and Finance reviewers with signed evidence; no appointment exists in current records. |
| T008 — authoring/review/API/UI | Implemented for development; scoped API tests and Admin accessibility test pass. | Production grants/readiness, production publication controls and full Admin workflow acceptance are not verified. |
| T009 — inventory/currency | Implemented and verified with real separate-session PostgreSQL tests (prior evidence in `evidence.md`). | Cross-module currency writer API acceptance limitations remain independently tracked; no live activation. |
| T010 — typed price/order/quote/currency lock | Implemented and development verified, with deny-by-default production pricing. | B-06 prevents operational pricing; E2E-01 still needed to verify order-to-payment integration. This is not blocked by P18. |
| T011 — durable issuance/purchaser retrieval/admission | Implemented for development; unit/feature/concurrency and mobile UI checks pass. | Real P08-to-Events end-to-end consumer/ack recovery remains NOT VERIFIED; independent reviews pending. |
| T012 — offline admission | Explicitly excluded from initial release. | No implementation; requires new explicit approval/specification to change scope. |
| T013 — late capture/refunds/cancellation | Containment implemented: verified late capture is preserved and blocked for reconciliation without ticket/refund. Final disposition is not implemented. | Product/Finance policy plus shared Payments refund/recovery capability and verification. |
| T014 — settlement/organiser payout | Not implemented; P18/B-11 dependency. | P18 payout/settlement capabilities and approved B-11 evidence. Does not block T010/T011. |
| T015 — interfaces | Customer tickets, agent online scanner and Admin authoring/aggregate attendance interfaces exist. Widget/a11y checks pass. | Full interactive Admin workflow and actual real-service journey acceptance remain open; offline controls fail closed. |
| T016 — cross-flow acceptance | Partial verification exists by component; package acceptance remains PARTIAL. | E2E-01 and independent reviews; late capture/refund/payout remain blocked as above. |

## Convergence implementation checkpoint

- Added additive Events migration `2026_10_28_100016_add_step_up_replay_fingerprint.php`; it records a SHA-256 fingerprint (never the raw step-up token) and uniquely consumes one action-bound proof per ticket. Same-key retry remains idempotent; replay under another key is rejected.
- Purchaser ticket list/detail and purchaser order retrieval send `Cache-Control: private, no-store` and `Pragma: no-cache`.
- Payment success replay now re-enters Wallet capture/Ledger acknowledgement repair only when the attempt is already succeeded, the intent remains `pending_ledger`, and the attempt owns a Wallet hold. Posted accounting and unsupported external attempts remain no-op.
- Agent scanner client now has an injectable API provider for widget verification. Customer and agent screen tests cover purchaser credential display, admitted-ticket replacement control, offline denial, credential in POST body/path privacy and response minimization.
- Current verification and exact counts are in the 2026-10-29 convergence section of `evidence.md`. The complete integrated real Payment-to-Events end-to-end run was not achieved; no fixture-based confirmation is represented as integrated evidence.

Production bootstrap, paid publication, checkout/sales, deployment, P18 and P24 remain disabled.

## E2E-01 integrated journey status - 2026-10-30 (supersedes prior NOT VERIFIED entry)

| Task | Current development state | Evidence and remaining gates |
|---|---|---|
| T004 / E2E-01 supported Wallet payment confirmation | IMPLEMENTED AND VERIFIED FOR DEVELOPMENT | Real P25 order -> Wallet capture -> posted Ledger journal -> Payment evidence/outbox -> Events consumer/ack passed on isolated PostgreSQL. AD-09 external paths remain pending and are not covered. |
| T010 order and payment integration | IMPLEMENTED AND VERIFIED FOR DEVELOPMENT | Actual test-only pricing, hold/order, first-order currency lock, Payment intent and outbox flow passed. B-06 prevents production pricing/publication/sales. |
| T011 durable issuance and purchaser access | IMPLEMENTED AND VERIFIED FOR DEVELOPMENT | Two ticket entitlements persisted; ack failure after Events commit recovered by retry; duplicate outbox redelivery created no duplicate financial effects, tickets or ack. Purchaser ownership/cache behavior passed. |
| T011 online admission and aggregate reporting | IMPLEMENTED AND VERIFIED FOR DEVELOPMENT | Exact event/gate assignment admitted one ticket, repeat scan did not duplicate admission; attendance/audit persisted and aggregate report omitted identities. Independent Security/Privacy review remains pending. |
| T016 integrated development acceptance | E2E-01 PASS; package remains PARTIAL | One integrated test: 60 assertions; focused regressions: 73 tests/537 assertions additionally recorded with E2E-01 total below. Not whole-suite or production acceptance. |

Focused exact results for this execution: P25IntegratedJourneyTest 1/0/0 tests, 60 assertions; IdempotentSuccessReplayTest 4/0/0, 21 assertions; WalletCapturePaymentIntegrationTest 6/0/0, 63 assertions; EventDraftFoundationTest 63/0/0, 453 assertions. Aggregate: 74 passed, 0 failed, 0 skipped; 597 assertions. Pint --test for the integrated test passed (1 file).

Production pricing, paid publication, checkout/sales, production bootstrap, deployment, P18 and P24 remain disabled. No production grant was created. Independent Security/Privacy/Finance review is not complete. Late capture, refund/cancellation, B-06 and payout remain subject to their separate pending policies/dependencies.

## P25 readiness-review reconciliation - 2026-10-30

The consolidated open decision/evidence register is `outstanding-decision-register.md`; detailed B-06/T004/T005 choices remain in `business-policy-decision-packet.md`. No settled choice was reopened and no recommendation was approved. E2E-01 remains verified only for the documented development Wallet path. Coverage additions assert persisted Payment intent reference and Wallet refund query status; success retry is covered by replay and integrated tests. Adapter retry tests were corrected to assert documented same-reference Wallet idempotency. Latest focused result: Pint 2 files pass; PHPUnit 38 passed, 0 failed, 0 skipped; 226 assertions. P25 remains PARTIAL; all production pricing/publication/sales/bootstrap/deployment/P18/P24 gates remain closed.

## Project-owner acceptance and implementation reconciliation — 2026-10-30

Owner acceptance records the policy direction only. It does not change independent specialist review or activation gates. Earlier T010/T011 development implementation status remains as recorded in the 2026-10-30 acceptance matrix.

| Task | Current classification | Acceptance evidence / immediate remaining dependency |
|---|---|---|
| T030 | IMPLEMENTED AND VERIFIED for development contract validation | Non-test Events pricing now requires versioned organiser fee schedule and tax policy snapshots, separates organiser platform fees from customer payable total, rejects unapproved customer booking fees and binary floating point, and validates schedule scope/currency/effective period. Tests: EventPricingBreakdownTest 4/4; EventDraftFoundationTest 64/64 including historical order snapshot immutability; P25IntegratedJourneyTest 1/1 test-only Wallet journey. Production configuration and actual specialist-approved values remain absent; no production pricing is enabled. |
| T031 | DOCUMENTATION PREPARED; independent review NOT VERIFIED | Four unassigned Identity, Security, Privacy and Finance review record templates are in independent-review-handoff.md. Exact appointing authority/reviewer identities and signed outcomes remain a governance dependency; no review is claimed complete. |
| T004/T010/T011 | DEVELOPMENT IMPLEMENTED; E2E-01 verified only for the documented test Wallet path | Existing order/payment/ledger/ticket journey preserved. External AD-09 providers, worker termination and browser/mobile integrated end-to-end remain outside E2E-01 evidence. |
| T013 | PARTIAL containment; final financial workflows BLOCKED | Owner accepted no automatic late-capture issuance and shared-service ownership. Refund deadlines/amounts, fee/tax refundability, provider charges, cancellation cost allocation, and unknown/failure disposition still require Product/Finance/Payments evidence and implementation. |
| T014 | BLOCKED on P18/B-11 | AD-14 Architect decision; AD-10 Finance Lead + Legal custody evidence; LC14 Ledger account/journal contract plus eligible-funds/reserve policy; P18 implementation and verification. This blocks payout only. |

Spec Kit checklist markers remain unchanged. No decision/checklist is marked satisfied from owner acceptance alone.

T013 implementation clarification - 2026-10-30: verified late-capture containment is implemented (blocked_reconciliation, no ticket/refund). The newly accepted cancellation direction is not fully implemented: Events currently has no cancellation transition that closes sales, invalidates tickets and durably records/dispatches notices. This is an implementation gap distinct from pending refund amounts, deadlines, fee/tax return and cancellation cost allocation. Keep cancellation mutation unavailable until the authorized review and durable Notifications path are implemented and tested; do not use an unavailable refund feature as a no-refund policy.

### T030 final verification correction - 2026-10-30
The final rerun after quote snapshot/idempotent retry assertions passed: EventPricingBreakdownTest 4 tests / 9 assertions; EventDraftFoundationTest 65 / 463; P25IntegratedJourneyTest 1 / 61; Pint 4 PHP files. Total 70 passed, 0 failed, 0 skipped, 533 assertions. This supersedes prior T030 test totals above. Production pricing, publication and sales remain disabled; specialist approvals and actual values are still pending.

### T030 final verification and cancellation readiness - 2026-10-30
T030 development validation is complete: EventPricingBreakdownTest 6/6 (13 assertions), EventDraftFoundationTest 66/66 (469 assertions), scoped PHPStan level 8 reports no errors, and Pint --test passes for the two test files. Tests use synthetic policy values only. See the final evidence section and traceability correction. Production pricing inputs and specialist approvals remain absent.

Cancellation assessment: NO cancellation lifecycle code is newly implemented. Existing cancellation policy text and scoped CANCEL_REQUEST/CANCEL_APPROVE permission identifiers are not enforcement or a workflow. A published cancellation must close sales, invalidate tickets, be reviewed by a different authorized person, and deliver notices; Events has no request/review transition or route, ticket state/customer view does not reflect event cancellation, and no cancellation Notification template/listener exists. Shared P11 connection-aware outbox is reusable. Final cancellation remains blocked by CANCEL-01 financial disposition and the missing Payments refund execution/status/recovery contract. Keep all cancellation state mutation and notices unavailable until these pieces can meet the same acceptance flow.

Final T030 count/scope correction - 2026-10-30: this verification run is 72 passed / 0 failed / 0 skipped / 482 assertions (unit pricing 6/13; full Events feature suite 66/469). Pint passed 2 test files; level-8 scoped PHPStan passed for four application files. P25IntegratedJourneyTest was not rerun and is not part of these counts; its prior E2E-01 evidence is separate and remains limited to the development Wallet backend path. This supersedes earlier 70-test totals for T030.

Expanded effective-window test correction - 2026-10-30: final counts are EventPricingBreakdownTest 6/13 and full EventDraftFoundationTest 66/475, totaling 72 passed / 0 failed / 0 skipped / 488 assertions. The targeted future/expired fee/tax-window test passes 1/12 and is included in the full feature suite. Pint passes 2 files and scoped PHPStan level 8 passes. P25IntegratedJourneyTest was not rerun in this slice.
