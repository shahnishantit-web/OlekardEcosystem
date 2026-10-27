# P25 Source and Prerequisite Research

## Source verification

Original accessible source: `C:\Users\BAPS\Downloads\OleKard_Shared_Payment_Architecture.docx`. Repository copy: `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx`. Both SHA-256 `B5D07562EE2497C726AABEFE18782ABFFC094A39CED8D0CF3E16AA0F2CFF7837`; the internal title states version 1.1, dated 19 October 2026. Extracted directly from `word/document.xml`; LC07 is in section 7. Section 2 assigns event order/capacity/admission to Events, money to Payments, sponsor terms to Events and connector infrastructure to E25. LC07 states the agreed threshold and approved policies but does not provide their concrete decisions.

## Implementation evidence

| Dependency | Observed implementation | Readiness |
|---|---|---|
| Events | Initial inspection found no `app/Modules/Events`. Legacy community admin pages reference event/ticket endpoints, but are not evidence of an approved Events backend. A draft-only Events foundation has since been added; production authorizer denies access. | Partial; gate scope and payment contract absent |
| P18 | Beneficiary/reservation services exist. `PayoutExecutionService` and `PayoutSettlementService` throw explicit `LogicException`; ADR-E48-003 AD-14 unresolved, AD-10 awaiting legal/finance evidence, LC14 unconfirmed. | Blocked for execution and settlement |
| P19 | Receipt/history services exist. P24 continuation recorded 13 unit passes and 15 skips; database/listener acceptance remains open. | Partial verification |
| P22 | Shared checkout implementation exists; `specs/069-flutter-checkout-recovery/tasks.md` T048–T051/T054 remain open. Prior scoped tests are not provider certification. | Partial |
| P23 | History and receipt mobile implementation exists; `specs/070-payment-history-detail/tasks.md` T083/T090/T091 remain open. | Partial |
| Payments | `BusinessPurposeResolverRegistry`, payment orchestration, refund service and confirmation events exist. No registered P25 Events resolver or approved issuance contract identified. | Reusable foundation; Events integration unverified |
| Wallet/ledger | Existing bounded modules own balances and journals. No new posting or balance mutation is warranted for P25. | Reuse contracts after approval |
| E25 | Existing `Integration` module and webhook infrastructure are common infrastructure, not P25's Events domain. | Reuse existing verified infrastructure |

## Blocking evidence

The source does not specify hold duration, issuance threshold, late-capture disposition, refund-in-flight admission, gate scopes, transfer/replacement, cancellation or payout eligibility. No approved decision records establishing these P25-specific policies were located. Identity's reviewed organisation registry implementation does not itself certify the provenance of an organisation or grant gate permissions. AD-14/AD-10/LC14 require their named architect/finance/legal owners. No independent acceptance, provider certification or production readiness is inferred.

## Workflow

Installed Spec Kit 1.1.3.dev0; no `.specify/extensions.yml` found. `create-new-feature.ps1` created package 072 and `setup-plan.ps1`/`setup-tasks.ps1` resolved templates. On 2026-10-27 the user expressly approved proceeding past the unchecked-checklist confirmation for independently implementable scope; markers remain unchanged. The overall plan and policy decisions remain unresolved.

## Classification and decision record

Every incomplete task is classified in `tasks.md`. Independent LC07 draft authoring data and structural category validation are READY only behind a deny-by-default authorisation interface; this creates no inferred permission or live UI. Publication/approval and customer/gate interfaces need an approved actor and policy model. Inventory, issuance, scan, refunds and payout remain BLOCKED.

| Decision question | Affected | Options / recommendation | Owner | Independent work |
|---|---|---|---|---|
| What exact hold lifetime/expiry, confirmation and accounting threshold, and late-capture reacquire-or-refund disposition apply? | FR-003/004/006; T004/T009/T010/T013 | Options require product and finance review; recommendation: explicit versioned policy and unresolved handling until verified, with no chosen duration or disposition. | Product and Finance, names not established | Draft data and validation only. |
| Who may create/review/publish per organisation, and how are gate/event/operator scopes and revoked/refunded/refund-in-flight, replacement, transfer, re-entry and offline scans handled? | FR-002/005/008; T005/T008/T011/T012/T015 | Recommendation: approved versioned authority and policies, online scope enforced server-side, offline separately approved; no grant inferred. | Security and Events product, named approver not established | Deny-by-default draft authorisation boundary. |
| Which cancellation/completion, reserve and payout eligibility, retention and settlement rules apply; who holds custody and owns the payout contract and LC14 ledger mapping? | FR-006/007/008; T006/T013/T014/T016 | Recommendation: keep completion, settlement and payout separate; do not select a ledger mapping or payout mechanism. | ADR-E48-003: architect for AD-14, Finance Lead and Legal for AD-10; LC14 owner not confirmed | Draft-only data, no money movement. |

Clarification 2026-10-27: the user selected verified payment success plus confirmed ledger recording as the intended ticket issuance threshold. Finance-owner approval evidence was not supplied; T004 and T010 remain blocked, and the other decisions in the first row remain open.

## Five unchecked checklist items: exact reconciliation (2026-10-27)

The five unchecked markers in `checklists/requirements.md` are unchanged. The user's authorization permits independent implementation only; it does not satisfy policy approvals or independent review.

| Checklist item | Exact unmet requirement and type | Existing decision/evidence that satisfies part | Precise remaining blocker | Affected tasks | Independent work |
|---|---|---|---|---|---|
| Payment/hold/late capture | Approved numeric duration, Finance-approved issuance threshold, versioned expiry/confirmation/refund contracts, plus last-ticket and late-capture verification. Business decision + external contract + verification. | User selected verified payment success plus ledger recording; centrally configured duration with earlier-of-duration/event-start expiry; release unresolved holds; atomic full reacquisition or approved refund. Draft validates time/order limits. | No owner approval record or numeric duration; no Payments event-ordering/refund evidence contract; no two-session PostgreSQL last-ticket/expiry race evidence. Routine transaction design can follow repository locking/idempotency patterns once contract exists. | T004, T009, T010, T013, T016 | Draft constraints T020, existing shared Payment contract inspection, tests unrelated to money/holds. |
| Organisation/reviewer/tenant/gate permissions and admission | Versioned permission IDs and authoritative assignment/revocation evidence, credential and refund-state contract, holder verification, numeric return limit; run cross-tenant/out-of-scope and concurrent scan tests. External contract + evidence + one numeric business decision + verification. | User chose membership+event-management permission, independent publication reviewer, event/gate assignment, commit-order revocation; scan output only valid/invalid + category; entry/exit fail closed offline; re-entry limit counts returns only. Deny-all authorizer and tenant boundary are tested. | Identity/authorization contract and owner-approved role assignment/revocation evidence; return limit value; authoritative credential/refund state API; online transaction/concurrency proof; retention/audit approval. Privacy/offline choices themselves are resolved, not blockers. | T005, T008, T011, T015, T016 | Draft-only structural authoring, type-safe service validation and isolated tests. |
| Refund, transfer, replacement, cancellation and offline policies | Approved refund eligibility/exception and cancellation disposition; transfer rule; holder-verification/replacement procedure; cancelled-event reactivation; separate approval and recovery for any future offline mode. Business decisions + external contracts. | Refund pending denies admission; failed/cancelled refund stays blocked pending separately authorized review; admitted-ticket refund denied except separate Finance review; replacement revokes prior credential; cancelled page visible, sales disabled, separate cancellation reviewer; initial release online-only. | Unused-ticket refund eligibility, transfer, cancellation refund, reactivation and holder-verification method remain undecided; Payments refund/evidence and Notifications/credential contracts missing. Offline is explicitly excluded pending distinct approval and scanner/reconciliation design. | T005, T006, T011, T012, T013, T015 | Draft foundation and already specified online-only privacy/status contracts can be documented/tested without exposing scanner endpoints. |
| Reserve, settlement, payout and retention; P18 | Approved reserve method/amount, payout eligibility and relevant-proceeds definition, Wallet proceeds eligibility, recovery/retention; verified P18 AD-14/AD-10/LC14 implementation and evidence. Business decisions + external contract + external owner evidence. | User selected separation of completion/settlement/payout, Finance reserve and different Finance approver, no automatic debit, wait for verified/reconciled bank proceeds. P18 service inspection confirms explicit execution/settlement stubs. | P18 execution and settlement contracts are absent (AD-14 architect; AD-10 Finance/Legal; LC14 owner not confirmed); no Wallet payout eligibility/custody contract; reserve/liability and retention policy records missing. | T006, T014, T016 | Events draft authoring/validation, no-money state separation documentation. |
| Plan and independent security/finance review | Completed plan/spec quality review and documented independent Security/Finance review of actual implementation, policy boundaries and recovery evidence. Review/verification. | Technical plan dependency order and T020 narrow design now documented; focused tests/static checks/local migration have evidence. | Review has not occurred; no approval record. Financial concurrency, API/UI and policy acceptance are unavailable until upstream gates clear. | T007, T016 | Review the T017–T020 draft foundation independently; run scoped static and regression checks. Do not mark this checklist item complete without the review record. |

## Consolidated unresolved business decisions

Recommendations are proposals only; none is approved by this record. Owner is shown only where the source/repository names one.

| ID / exact question | Options; recommendation (proposal) | Affected tasks | Decision owner |
|---|---|---|---|
| D-01 What numeric hold duration may an authorized organiser configure, and what exact event defines hold/quote expiry and completion when payment status is unknown? | Options: set fixed maximum; permit a bounded organiser-selected duration; no holds. Recommend a centrally approved bound, deny holds absent configuration, retain selected earlier-of-duration/event-start behavior, and leave unknown payments unresolved. | T004, T009, T010, T013 | Events Product and Finance; named approver not established |
| D-02 What per-event maximum return count applies to re-entry? | Options: zero; a finite approved count; no limit. Recommend an explicit reviewed finite value; preserve that initial entry does not count. | T005, T011 | Events Product; named owner not established |
| D-03 Which holder verification method permits lost-ticket replacement, and what unused-ticket refund, transfer and cancellation refund outcomes apply? | Options must be selected by Security/Events/Finance/Legal. Recommend versioned procedures, old credential revoked before replacement, no inferred transfer/refund eligibility. | T005, T006, T011, T013 | Security, Events, Finance/Legal; named approvers not established |
| D-04 May an approved cancelled event be reactivated, and what completion timing/criteria define event completion? | Options: no reactivation/new event only; reviewed reactivation; define a bounded completion window/criteria. Recommend keep reactivation disabled and require explicit approved completion criteria. | T006, T008, T013, T014 | Events Product; named owner not established |
| D-05 What reserve calculation and liability coverage qualify payout; which bank proceeds are “relevant,” and are Wallet-origin proceeds eligible? | Options require Finance/Legal/Wallet decisions; recommend payout only after verified reconciliation and approved reserve, with unresolved items kept ineligible; do not infer Wallet treatment. | T006, T014, T016 | Finance Lead/Legal; Wallet owner not established |
| D-06 Who bears transaction fees and how are taxes calculated/displayed for paid event tickets? | Options: customer-paid, organiser-paid, split, or no paid launch until resolved; tax options require jurisdictional advice. Recommend keep checkout and paid publication disabled until one approved fee rule and tax contract exist. | T008–T010, T015 | Finance/Legal; named approver not established |
| D-07 What authority/retention approvals are required for organisation membership, authoring, publication, cancellation, gate assignment/revocation, refund recovery and audit evidence? | Options: map to existing permission identifiers/assignment service, or approve new ones; recommendation: reuse existing Identity/Permissions authorities and separate high-impact reviewers, with deny-by-default. | T005, T008, T011, T015, T016 | Security/Identity and Events; named approver not established |

## Required external artifacts/contracts (not clarification questions)

| ID / artifact required | Scope affected | Producer/owner evidence | Independent work that remains possible |
|---|---|---|---|
| C-01 Versioned Payments quote, enabled-method/currency availability, idempotency, authoritative confirmation/ledger threshold, event ordering and refund receipt contract | T004, T009, T010, T013 | Payments module owner; owner not named in current evidence | Draft authoring and non-financial validation. |
| C-02 Approved fee and tax policy plus authoritative quote components | T008–T010, T015 | Finance and Legal approval; named individuals not established | Keep all paid checkout disabled. |
| C-03 Identity/Permissions organisation membership, permission IDs, reviewer separation, event/gate assignment, revocation, customer active status and holder verification contracts | T005, T008, T011, T015 | Identity/Security module owner and approval evidence; named owner not established | Deny-by-default draft internals; no public surface. |
| C-04 API/UI ownership and approval evidence for Events admin/mobile surfaces; currency assignment contract and supported-currency list | T008, T015 | Events product/admin/mobile owners and Finance/Platform for currency; named individuals not established | Draft domain validations and internal service tests. |
| C-05 P18 settlement/execution, reserve, custody and payout contracts, including AD-14, AD-10 and LC14 artifacts | T006, T014, T016 | Architect for AD-14; Finance Lead and Legal for AD-10; LC14 owner not confirmed | Event completion remains separate; no payout implementation. |
| C-06 Refund/credential/Notifications evidence and audit retention schedule, including status-only scanner response | T005, T006, T011, T013, T015 | Payments, Identity/Security, Notifications and Privacy owners; not established | Test only the documented fail-closed/redaction behavior in isolated service tests. |
| C-07 Independent security and finance review record, plus real PostgreSQL concurrency results | T007, T009–T011, T016 | Independent Security/Finance reviewers and test environment owner; not established | Continue T020-level draft and static checks; do not claim financial acceptance. |

Clarification 2026-10-27: the user selected a centrally configured, approved hold duration with fail-closed behavior when none is configured. No duration, expiry semantics or owner approval evidence was supplied; T004 and T009 remain blocked.

Clarification 2026-10-27: the user selected atomic capacity reacquisition after a late verified payment, or the approved refund process if capacity is unavailable. Owner approval, applicable refund contract and race-handling evidence remain outstanding; T004, T010 and T013 remain blocked.

Clarification 2026-10-27: the user selected denial of admission while a refund is pending; restoration after a failed or cancelled refund requires a separately approved rule. Owner approval and restoration semantics are not yet evidenced; T005 and T011 remain blocked.

Clarification 2026-10-27: the user selected explicit assignments of authorised gate operators to specific events and gates. No owner approval, assignment/revocation contract or permission evidence was supplied; T005 and T011 remain blocked. Five clarification questions were answered in this session; further unresolved decisions remain deferred and are listed in the specification.

Clarification continuation 2026-10-27: an authorised event organiser may configure the centrally managed hold duration; the hold expires at the earlier of that duration or event start, and no configured approved duration means no hold. The numeric duration, organiser authorisation contract and owner approval evidence remain outstanding. T004/T009 remain blocked.

Clarification continuation 2026-10-27: the user selected continued admission denial after a definitively rejected or cancelled refund until authorised review. Reviewer authority, available review outcomes and owner approval evidence remain outstanding; T005/T011 remain blocked.

Clarification continuation 2026-10-27: the user selected holder verification, revocation of the old lost credential and replacement for the same entitlement. Holder-verification method, concurrency/revocation contract and owner approval evidence remain outstanding; T005/T011 remain blocked.

Clarification continuation 2026-10-27: the user chose to leave paid-ticket transfer policy pending. T005 and transfer functionality remain blocked until an approved policy and contract exist.

Clarification continuation 2026-10-27: the user chose to leave event cancellation refund policy pending. T006/T013 remain blocked until Events and Finance approve a policy and applicable shared refund contract. Five questions were answered in this continuation; further questions are deferred.

Authority clarification 2026-10-27: the user selected active organisation membership plus an approved event-management permission for event authoring. A permission identifier, assignment and revocation contract and owner approval evidence were not supplied. The existing deny-all authorizer remains bound; T005/T008 remain blocked.

Authority clarification 2026-10-27: the user selected review by a different authorised person before publication. Reviewer permission and scope and the decision-owner approval record remain unresolved; T005/T008 remain blocked.

Authority clarification 2026-10-27: the user selected that any edit after approval and before publication clears approval and requires another review. Owner approval evidence remains outstanding; T005/T008 remain blocked.

Authority clarification 2026-10-27: the user selected an approved, published event-specific re-entry policy. Allowed options and owner approval evidence for the subsequently selected recording approach remain outstanding; T005/T011 remain blocked.

Authority clarification 2026-10-27: the user selected an organiser with an approved gate-assignment permission as the person who assigns operators. Permission identifiers, assignment/revocation controls, authority contracts and owner approval evidence remain outstanding; T005/T011 remain blocked. Five questions were answered in this authority continuation; remaining decisions are deferred.

Re-entry clarification 2026-10-27: the user selected one admission record plus a separate record for each permitted re-entry. Approved re-entry options, owner approval evidence and the admission contract remain outstanding; T005/T011 remain blocked.

Re-entry clarification 2026-10-27: the user selected an event organiser choice between allowed and denied, subject to approval, with no default supplied. Policy-owner approval and admission contracts remain outstanding; T005/T011 remain blocked.

Re-entry clarification 2026-10-27: the user selected a recorded exit before each permitted re-entry. The exit-recording contract, concurrency semantics and owner approval evidence remain outstanding; T005/T011 remain blocked.

Re-entry clarification 2026-10-27: the user selected an approved event-specific re-entry limit. No limit value, configuration authority or owner approval evidence was supplied; T005/T011 remain blocked.

Re-entry clarification 2026-10-27: the user selected denial of the next online scan after gate-assignment revocation. The revocation and concurrency contracts and owner approval evidence remain unresolved; a subsequent answer selects commit-order treatment of an in-progress scan. Five questions were answered in this re-entry continuation; T005/T011 remain blocked.

Race clarification 2026-10-27: for concurrent scan and revocation, the user selected commit order: an admission committed first may stand; a revocation committed first causes denial. This is a selected rule, not an approved contract or concurrency proof. Transaction design, owner approval and real race tests remain outstanding; T005/T011 remain blocked.

Race clarification 2026-10-27: the user selected releasing an expired hold while a payment remains pending or unknown; no ticket is issued until verified, and later verified success follows selected reacquisition or approved refund. Expiry/payment race semantics, owner approval and provider evidence remain outstanding; T004/T009/T010/T013 remain blocked.

Race clarification 2026-10-27: the user selected atomic reacquisition after a released hold even when later verification establishes capture before expiry; otherwise follow the approved refund process. Owner approval and refund/concurrency contracts remain outstanding; T004/T009/T010/T013 remain blocked.

Race clarification 2026-10-27: the user selected retaining existing hold expiries when the configured duration changes and applying the new duration only to new holds. A subsequent answer selects a shortening exception for an earlier approved event start. Owner approval evidence remains outstanding; T004/T009 remain blocked.

Race clarification 2026-10-27: for an approved schedule change moving the event start earlier, the user selected shortening affected holds to the new start and never extending existing holds. Schedule approval authority, implementation/concurrency evidence and owner approvals remain outstanding. Five race/expiry questions were answered in this session; T004/T009/T010/T011/T013 remain blocked.

Verification clarification 2026-10-27: the user chose to leave holder verification for lost-credential replacement pending. An approved verification method and authority contract remain necessary; T005/T011 remain blocked.

Verification clarification 2026-10-27: after a definitively rejected or cancelled refund, the user selected that an authorised reviewer may restore admission eligibility only when the shared payment record confirms no refund occurred and the ticket is otherwise eligible. Reviewer authority, authoritative evidence contract and owner approval remain outstanding; T005/T011 remain blocked.

Verification clarification 2026-10-27: the user selected a separately authorised support or finance reviewer for that decision. Reviewer permission scope, assignment/revocation contract and owner approval remain outstanding; T005/T011 remain blocked.

Verification clarification 2026-10-27: the user selected online-only scanning for the initial P25 release. No offline functionality is authorised for that release; any later offline capability requires separate explicit approval and specification. Owner approval evidence remains outstanding; T011 remains blocked on the other admission prerequisites and T012 remains blocked.

Verification clarification 2026-10-27: the user selected configuration of the event's re-entry limit by an organiser with approved event-management authority, subject to event review. No limit value, permission contract or owner approval evidence was supplied. Five questions were answered in this verification continuation; T005/T011 remain blocked.

Payout clarification 2026-10-27: the user selected an authorised organiser as payout requester, with separate finance review and approval. Request permission, eligibility, P18 execution/settlement contracts and owner approval evidence were not supplied; T006/T014 remain blocked.

Payout clarification 2026-10-27: the user selected request submission after event completion and reconciliation, with unresolved liabilities resolved or covered by an approved reserve. Reserve definition, reconciliation evidence, permission, P18 contracts and owner approval remain outstanding; T006/T014 remain blocked.

Payout clarification 2026-10-27: the user selected an organiser with an approved event-completion permission to mark an event complete. Permission assignment/revocation and owner approval evidence remain outstanding; T006/T013 remain blocked.

Payout clarification 2026-10-27: the user chose to leave event-completion timing pending. T006/T013 remain blocked until the Events owner approves timing and completion rules.

Payout clarification 2026-10-27: the user selected an authorised Finance operator to set the reserve under an approved policy. No reserve amount, calculation, permission, review process or owner approval evidence was supplied. Five payout/closure questions were answered in this session; T006/T013/T014 remain blocked.

Settlement clarification 2026-10-27: the user selected excluding external bank-payment proceeds until provider settlement is verified and reconciled. Finance approval, settlement evidence and P18 contracts remain outstanding; no rule for other payment methods is inferred. T006/T014 remain blocked.

Settlement clarification 2026-10-27: the user selected waiting until all relevant event bank proceeds settle and reconcile before payout, rather than requesting payout of a cleared portion. The definition of relevant proceeds, Finance approval and settlement/P18 contracts remain outstanding; no rule for other payment methods is inferred. T006/T014 remain blocked.

Settlement clarification 2026-10-27: the user selected recording a liability discovered after payout settlement as open and referring it to Finance for authorised review and recovery. No automatic debit or reversal was authorised; recovery rules and owner approval remain outstanding. T006/T013/T014 remain blocked.

Settlement clarification 2026-10-27: the user selected a different authorised Finance reviewer to approve payout from the operator who sets the reserve. Reviewer permission, separation controls and owner approval evidence remain outstanding; T006/T014 remain blocked.

Settlement clarification 2026-10-27: the user chose to leave Wallet-payment proceeds eligibility pending. AD-10 custody, reconciliation and Finance/Legal approval remain outstanding; no Wallet settlement rule or payout action is authorised. Five settlement questions were answered in this session; T006/T014 remain blocked.

Entitlement clarification 2026-10-27: the user chose to leave multi-ticket attendee assignment pending. Ticket ownership, attendee-data/privacy rules and owner approval remain outstanding; T005/T010/T011 remain blocked.

Entitlement clarification 2026-10-27: the user selected atomic reacquisition of the full order quantity or the approved refund process when only part is available. No partial issuance or partial refund is authorised by this selection. Owner approval and the refund/concurrency contracts remain outstanding; T004/T009/T010/T013 remain blocked.

Entitlement clarification 2026-10-27: the user selected at most one ticket set for multiple verified payments linked to one order; any extra payment remains an unresolved liability for Finance review. No automatic refund or extra entitlement is authorised. Owner approval and financial/recovery contracts remain outstanding; T010/T013 remain blocked.

Entitlement clarification 2026-10-27: the user selected pausing new sales during review of a proposed post-publication edit. Handling of the current published version and owner approval evidence remain outstanding; this selection does not cancel existing payment or ticket states. A subsequent answer selects explicit authorised resumption after rejection. T005/T008/T009 remain blocked.

Entitlement clarification 2026-10-27: if the proposed edit is rejected, the user selected keeping sales paused until an authorised operator explicitly resumes them. Operator permission, resumption checks and owner approval evidence remain outstanding. Five entitlement questions were answered in this session; T005/T008/T009 remain blocked.

Published-event clarification 2026-10-27: after a proposed post-publication edit is approved, the user selected keeping sales paused until the approved version is published and an authorised operator explicitly resumes them. Operator permission, publication/resumption checks and owner approval evidence remain outstanding; T005/T008/T009 remain blocked.

Published-event clarification 2026-10-27: the user selected retaining purchase-time terms for existing ticket orders when an approved event edit changes ticket prices, categories, capacity or entry policies; the new version applies to future sales. This does not settle cancellation, refund, transfer or admission eligibility. Versioned order evidence and owner approval remain outstanding; T005/T008/T009/T010 remain blocked.

Published-event clarification 2026-10-27: the user selected rejecting an approved capacity reduction below existing tickets plus active holds. Capacity accounting, transactional/concurrency proof and owner approval remain outstanding; T005/T008/T009 remain blocked.

Published-event clarification 2026-10-27: the user selected pausing new sales during review of every proposed post-publication edit, including non-commercial detail changes. Owner approval and workflow evidence remain outstanding; T005/T008/T009 remain blocked. Five questions were answered in this continuation; additional decisions remain deferred.

Refund-capacity clarification 2026-10-27: the user selected returning a refunded ticket to saleable capacity only after the shared payment record confirms the refund and the ticket credential is revoked. The authoritative refund evidence contract, atomic inventory update and owner approval remain outstanding; T009/T010/T013 remain blocked.

Refund-capacity clarification 2026-10-27: the user selected returning that capacity only while the approved sales window remains open and before event start. Sales-window/time validation, atomic inventory update and owner approval remain outstanding; T009/T010/T013 remain blocked.

Event-time clarification 2026-10-27: the user selected an explicitly chosen event time zone governing event start and sales-window times, evaluated consistently across devices. Time-zone data/display contracts and owner approval remain outstanding; T004/T009/T015 remain blocked.

Event-time clarification 2026-10-27: the user selected displaying customer event times in the event's selected zone and showing its label. Display contract and owner approval remain outstanding; T004/T009/T015 remain blocked.

Event-time clarification 2026-10-27: the user selected rejecting ambiguous or nonexistent local event times around daylight-saving transitions rather than automatically resolving them. Validation contract and owner approval remain outstanding; T004/T009/T015 remain blocked.

Schedule-change clarification 2026-10-27: the user selected keeping issued tickets valid for an approved rescheduled event; cancellation and refund remain separate policies. Owner approval and ticket-version/update evidence remain outstanding; T005/T008/T011/T013 remain blocked.

Event-time clarification 2026-10-27: the user selected requiring the approved ticket sales window to close at or before event start. Validation contract and owner approval remain outstanding; T004/T009/T015 remain blocked. Five questions were answered in this continuation; remaining decisions remain deferred.

Order-limit clarification 2026-10-27: the user selected requiring an approved event-specific maximum order quantity before multi-ticket orders are accepted, and denying orders above that limit. Limit value, configuration authority and owner approval remain outstanding; T009/T010/T015 remain blocked.

Admission/refund clarification 2026-10-27: the user selected denying refund requests after confirmed ticket admission, with any exception requiring separately approved review. Shared refund contract, exception authority and owner approval remain outstanding; T005/T013 remain blocked.

Admission/refund clarification 2026-10-27: the user left customer-initiated refund eligibility for unused, unadmitted tickets pending an approved refund policy; no general refund action is authorised. T005/T013 remain blocked.

Admission/refund clarification 2026-10-27: the user selected a separately authorised Finance reviewer to approve exceptions to the post-admission refund denial. Reviewer permission, separation controls and owner approval remain outstanding; T005/T013 remain blocked.

Order-limit clarification 2026-10-27: the user selected one event-level per-order maximum applied to the combined quantity across all ticket categories. Limit value, configuration authority and owner approval remain outstanding; T009/T010/T015 remain blocked.

Capacity-model clarification 2026-10-27: the user selected per-category inventory limits plus one aggregate event-capacity limit. Values, allocation rules and owner approval remain outstanding; T009/T010 remain blocked.

Capacity-model clarification 2026-10-27: the user selected treating per-category limits as firm; selling above a category limit requires an authorised capacity reallocation. Reallocation permission, concurrency semantics and owner approval remain outstanding; T009/T010 remain blocked.

Capacity-model clarification 2026-10-27: the user selected rejecting a category reallocation that would place that category below sold tickets plus active holds, regardless of aggregate capacity. Atomic allocation/concurrency proof and owner approval remain outstanding; T009/T010 remain blocked.

Capacity-model clarification 2026-10-27: the user selected allowing category capacity totals below the aggregate event capacity, with the unallocated remainder unavailable until an authorised organiser assigns it. Allocation permission and owner approval remain outstanding; T009/T010 remain blocked.

Currency clarification 2026-10-27: the user selected one event-level currency for all category prices and orders. The actual currency selection, Payments quote contract and owner approval remain outstanding; T009/T010 remain blocked.

Checkout-display clarification 2026-10-27: the user selected presenting ticket price and confirmed Payments fees separately, with the total shown before customer confirmation. Fee calculation/display contract and owner approval remain outstanding; T010/T015 remain blocked. Five questions were answered in this continuation; capacity values and allocation, order-limit, refund, and currency decisions remain outstanding.

Currency-authority clarification 2026-10-27: the user selected an authorised Finance or platform administrator assigning the single event currency. Supported-currency list, permission contract and owner approval remain outstanding; T005/T008/T009/T010 remain blocked.

Ticket-pricing clarification 2026-10-27: the user selected an authorised organiser proposing ticket prices, with a different authorised reviewer approving them before publication. Finance approval evidence and both permission contracts remain outstanding; T005/T008/T009 remain blocked.

Order-limit clarification 2026-10-27: the user selected an authorised organiser proposing the maximum tickets-per-order limit, with a different authorised reviewer approving it before publication. Limit value, permission contracts and owner approval remain outstanding; T005/T008/T009 remain blocked.

Ticket-pricing clarification 2026-10-27: the user selected requiring an authorised Finance reviewer to approve ticket prices, in addition to independent event-publication approval. Permission identifiers, reviewer separation and owner approval remain outstanding; T005/T008/T009 remain blocked.

Scope-boundary clarification 2026-10-27: the user selected requiring positive paid-ticket prices in P25 and placing zero-price/complimentary tickets in the separate P26 journey. P26 remains unstarted and its requirements/approval remain separate; no implementation or permission is implied.

Payment-availability clarification 2026-10-27: the user selected keeping sales disabled when shared Payments has no enabled method for the event currency; no conversion or fallback is authorised. Availability contract and owner approval remain outstanding; T008/T009/T010 remain blocked.

Checkout-confirmation clarification 2026-10-27: the user selected requiring new customer confirmation when a presented payable total changes. Shared quote version/expiry and confirmation contracts and owner approval remain outstanding; T010/T015 remain blocked.

Checkout-confirmation clarification 2026-10-27: the user selected expiring the quote and blocking new payment attempts at inventory-hold expiry; any later verified capture follows the selected late-capture path. Shared hold/quote/idempotency contract and owner approval remain outstanding; T009/T010/T013 remain blocked.

Checkout-identity clarification 2026-10-27: the user selected requiring an authenticated active customer account before creating a hold or starting paid checkout. Identity/account-status contract and owner approval remain outstanding; T009/T010/T015 remain blocked.

Order-limit clarification 2026-10-27: the user left any cumulative per-customer limit across separate orders pending approved policy. The selected per-order maximum does not imply a customer-level cap; T009/T010 remain blocked pending limit/authority evidence.

Checkout-policy clarification 2026-10-27: the user left mandatory acknowledgement of published event policies pending approved Legal/Product policy; publishing terms does not itself establish consent. T010/T015 remain blocked pending decision and evidence.

Ticket-notification clarification 2026-10-27: the user selected using shared Notifications for ticket issuance and reschedule notices, respecting the customer's enabled channel preferences. Notification contract and owner approval remain outstanding; T010/T015 remain blocked.

Ticket-notification clarification 2026-10-27: the user selected using shared Notifications and existing customer preferences for event-cancellation notices as well. This does not decide cancellation refunds or financial handling. Notification contract and owner approval remain outstanding; T006/T015 remain blocked.

Credential-delivery clarification 2026-10-27: the user selected storing scannable credentials in the authenticated customer account and excluding codes from email/SMS/push notices. Credential storage and notification security contracts and owner approval remain outstanding; T010/T015 remain blocked.

Cancellation-visibility clarification 2026-10-27: the user selected keeping the approved event page visible with cancelled status and sales disabled. This does not decide refund or other financial handling. Status/display contract and owner approval remain outstanding; T006/T015 remain blocked.

Cancellation-approval clarification 2026-10-27: the user selected requiring a different authorised reviewer before a published event is marked cancelled and customer notices are sent. Permission, separation, notification and owner approval contracts remain outstanding; cancellation refunds remain unresolved; T006/T008/T015 remain blocked. Five questions were answered in this continuation; further decisions remain deferred.

Gate-privacy clarification 2026-10-27: the user selected showing gate operators only valid/invalid status and ticket category after scan, omitting customer/payment data. Scan response and privacy contracts and owner approval remain outstanding; T005/T011 remain blocked.

Gate-availability clarification 2026-10-27: the user selected denying admission when online validation is unavailable, allowing retry after connectivity returns, and no local override/offline path. Availability/scan contracts and owner approval remain outstanding; T005/T011 remain blocked.

Cancelled-event clarification 2026-10-27: the user left reactivation of a cancelled published event pending approved lifecycle policy. No reactivation behavior is authorised; cancellation/refund and lifecycle contracts remain outstanding; T006/T008 remain blocked. Five questions were answered in this continuation; remaining policies stay deferred.

Gate re-entry clarification 2026-10-27: the user selected counting only returns toward the approved re-entry limit; initial admission is separate. Limit value, admission contract and owner approval remain outstanding; T005/T011 remain blocked.

Gate re-entry clarification 2026-10-27: the user selected online-only exit recording; attendees may leave while offline but may not re-enter until the exit record is made online. Exit/scan availability contract and owner approval remain outstanding; T005/T011 remain blocked.

Currency-authority clarification 2026-10-27: the user selected locking event currency after the first ticket order or active hold. State enforcement and owner approval remain outstanding; T008/T009/T010 remain blocked.

Fee-responsibility clarification 2026-10-27: the user left responsibility for displayed Payments fees pending an approved Finance policy; neither customer billing nor organiser-proceeds deduction is authorised. T009/T010/T014 remain blocked.

Tax clarification 2026-10-27: the user left ticket tax handling pending approved Finance/Legal policy and a tax-calculation contract; no tax amount or inclusion rule is inferred. T009/T010/T014 remain blocked.

Currency-authority clarification 2026-10-27: the user selected blocking paid-ticket publication and sales until an authorised Finance/platform administrator assigns a supported event currency; no default is authorised. Supported currency list, permission contract and owner approval remain outstanding; T005/T008/T009/T010 remain blocked. Five questions were answered in this continuation; unresolved fee, tax, pricing and financial decisions remain deferred.

Inventory-state clarification 2026-10-27: the user selected automatically enabling checkout after hold expiry releases inventory when the approved event remains on sale and capacity is available. Existing review/operator pauses continue to take precedence, consistent with the earlier post-publication edit rule. State-transition contract and owner approval remain outstanding; T009/T010/T015 remain blocked. Five questions were answered in this continuation; other policy decisions remain deferred.

Publication privacy clarification 2026-10-27: the user selected showing only approved organiser/sponsor names and descriptions to customers; contact details additionally require explicit public marking and approval. Approval and privacy/data-handling contracts remain outstanding; T005/T008/T015 remain blocked.

Published-version clarification 2026-10-27: while a proposed edit is under review and sales are paused, the user selected keeping the last approved version visible with sales paused and withholding the draft. Customer-state/publication contracts and owner approval remain outstanding; T005/T008/T015 remain blocked.

Published-event visibility clarification 2026-10-27: the user selected retaining the approved event page with a closed or sold-out status and disabling checkout when the sales window closes or capacity sells out before event start. Status/publication contracts and owner approval remain outstanding; T005/T008/T015 remain blocked.
