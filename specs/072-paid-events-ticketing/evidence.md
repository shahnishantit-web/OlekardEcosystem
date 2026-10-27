# P25 Evidence — 2026-10-27

**Status: PARTIAL.** A deny-by-default draft-only Events foundation is implemented locally. The initial inspection record below is historical; the continuation at the end supersedes its implementation status. No P25 API, UI, provider integration, production action or financial acceptance is claimed.

## Inspection

Verified v1.1 source and identical original/repository-copy SHA-256, LC07 and §2 ownership as recorded in `research.md`. P18 execution/settlement are explicit stubs. P19/P22/P23 have reusable implementations with incomplete runtime or test evidence. No P25 Events module or approved P25 policy set was found. Existing generic community event pages reference legacy endpoints; they are not proof of a working ticket/admission backend.

## Workflow and checks

| Check | Result |
|---|---|
| Installed `create-new-feature.ps1 -Number 72` | PASS after invoking PowerShell with process execution-policy override; package 072 created. Direct invocation first failed due Windows execution policy. No branch created. |
| `setup-plan.ps1 -Json`, `setup-tasks.ps1 -Json` | PASS; templates resolved. Plan remains draft because required decisions are unresolved. |
| `check-prerequisites.ps1 -Json -RequireTasks -IncludeTasks` | PASS for file discovery; not an approval of the plan or policies. |
| Requirements checklist | FAIL: 2 checked, 5 unchecked; `speckit-implement` treats unchecked items as a read-only approval gate. User also requires the missing policy evidence itself. |
| Targeted P19 receipt/history Pest tests | 13 PASS, 0 FAIL, 15 SKIP, 35 assertions; skipped tests are explicit database/listener placeholders. Application logging on stderr can yield PowerShell exit 1 despite Pest pass/skip summary. |
| Customer Flutter checkout, history and receipt tests | 28 PASS, 0 FAIL, 0 SKIP; tests use development mock providers and do not exercise P25 or certify a bank. |

No P25 concurrency, issuance, gate, migration, API, E2E, financial, build or security tests were run because no P25 code or approved executable contract exists. The shared Flutter/mock checks are not P25 acceptance.

## Definition of Done

| Criterion | Verdict | Evidence or gap |
|---|---|---|
| Source and predecessor evidence | PASS | Verified source; inspected services, contracts, task gaps and prior evidence. |
| Approved policies and workflow review | FAIL | Three grouped questions in `spec.md`; checklist 5 unchecked; plan gate unresolved. |
| Authoring, review, publication and UIs | FAIL | No approved P25 implementation. |
| Inventory, shared checkout, repairable issuance | FAIL | Policy threshold/expiry/late capture absent; no P25 concurrency or integration evidence. |
| Scoped online admission and privacy | FAIL | Gate authority and revocation/refund rules missing; no concurrency tests. |
| Late capture, refund, settlement and payout | FAIL | Policy and P18 AD-14/AD-10/LC14 remain unapproved; no disbursement evidence. |
| Independent state/ownership boundaries | PARTIAL | Documented in specification; runtime implementation not verified. |
| No production deployment or live activation | PASS | No live operation or P25 enablement performed. |

Next permitted action: obtain and link the owner-approved policy/authority decisions and P18 financial evidence, complete the Spec Kit review gate, then implement the independently approved tasks in dependency order. Do not treat an override or mock success as policy approval.

## Independent Events draft continuation — 2026-10-27

The user explicitly approved proceeding past the unchecked-checklist confirmation for independent work, without approving any outstanding policy or enabling P25. T004–T016 are individually classified in `tasks.md`; T017–T019 cover the ready foundation. Review checklist markers were not changed.

- Added `EventsServiceProvider`, `EventDraftService`, `EventDraftAuthorizer` and deny implementation. Identity membership and a separate authorizer are both required. The default authorizer denies everyone, including reviewed organisation members. There is no endpoint, approval, publication, quote, checkout, inventory hold, ticket, admission or payout implementation. Test authorizer is explicitly test-only.
- Local migration `2026_10_27_100001_create_event_drafts.php` creates event draft, category and state-only audit tables in `community_services`; it ran successfully in local PostgreSQL. It makes no financial or balance changes. Drafts store supplied title, organiser, sponsor names, category prices/capacities, time zone, sales/event windows and cancellation/transfer/entry text; this is unapproved draft data, not a policy decision. Writes and audit row are transactional; edits lock the draft and compare a version. Category total cannot exceed event capacity. Platform AuditService receives state-only forwarding after commit; its failure swallowing/replay limitation is existing technical debt.
- New P25 feature tests: **3 pass, 0 fail, 0 skip, 17 assertions**, covering default denial, real membership/tenant/revocation, test-only authorizer, version conflicts, capacity rollback and private audit contents. Combined with P24/Identity gate tests: **12 pass, 45 assertions**. P24 financial flow plus P25 draft: **9 pass, 65 assertions**. Direct PHPUnit output says `OK`; PowerShell reports exit 1 due application debug logs on stderr.
- Scoped PHPStan for four new application/contract/provider files: **0 errors**. Pint initially reported two formatting failures; after formatting, scoped Pint **PASS**. No Flutter/admin build was needed for this backend-only change; no P25 UI was changed. Full suite and two-process tests were not run.
- Runtime config confirmed `community_funeral.http_enabled=false` and `payment.live_callback_processing_enabled=false`. No P25 HTTP route is registered. No production deployment or P18 stub modification occurred.

**Remaining acceptance:** approved organisation authorizer and roles; cross-connection revocation/write race and central audit recovery; real purchase/admission concurrency; lifecycle policies, P18 finance contracts and release review. Draft creation is an internal method without a public idempotency contract; do not expose it until access and request replay are specified. Existing draft policy fields are input only, not published terms. See `research.md` for decision questions, owners and independent work.

### Current DoD verdicts

| Criterion | Verdict | Current evidence |
|---|---|---|
| Verified source, task classification and independent draft foundation | PASS | T001–T003/T017–T019; local draft, tenant and version tests. |
| Authoring authority, review, publication and UI | FAIL | Draft-only internal service denies access by default; authority and interfaces unapproved. |
| Last-ticket concurrency and shared checkout | FAIL | No hold/quote implementation or approved expiry and pricing policy. |
| Confirmation, issuance and replay recovery | FAIL | No issuance implementation or approved payment/accounting threshold. |
| Scoped admission and scan concurrency | FAIL | No approved gate/refund/credential policy or scan implementation. |
| Late capture and financial closure | FAIL | Reacquire/refund rules and P18 AD-14/AD-10/LC14 remain open. |
| Ownership and independent financial states | PARTIAL | No financial writes or integrations added; end-to-end evidence absent. |
| No live processing or production deployment | PASS | Runtime flags false; no P25 route or deployment. |

## Specification and scope continuation

The explicitly invoked `speckit-specify` workflow was applied to the existing package rather than creating a second P25. The spec now states draft acceptance scenarios, assumptions and that Events owns the price while Payments owns the financial quote. The existing checklist retains its five unresolved review markers; no policy was approved by the user's checklist override. No extension hook file was present.

Added a regression test that supplies forged organisation, creator and version fields to draft creation and verifies server-selected values remain authoritative. Combined P25 draft, P24 tenant and P24 readiness PHPUnit run: **10 passed, 0 failed, 0 skipped, 35 assertions**; PowerShell reports exit 1 because application debug logging is emitted on stderr. Scoped Pint: **PASS**. Local configuration still shows P24 HTTP and live callback processing disabled. No P25 HTTP route, provider call, production operation, migration or build was added in this continuation.

## Clarification session

Five questions were asked and answered in `spec.md`: payment/ledger threshold, centrally configured hold duration, late-capture reacquisition/refund, pending-refund admission denial, and per-event/per-gate operator assignment. These are user-selected directions; no owner approval record, hold duration, assignment contract, refund contract or financial acceptance was supplied. The requirements checklist remains 2/7 checked and 5/7 unchecked; no marker was toggled. No tests or runtime operations were performed in the clarification session. The remaining decisions are explicitly deferred in the specification and research record.

Five further questions were answered: hold expiry capped at event start, manual admission review after a rejected/cancelled refund, replacement of a lost credential after verification and revocation, and transfer and cancellation-refund policies both left pending. No owner approvals or contracts were supplied. Checklist remains 2/7; no tests, live operations or deployment occurred in this continuation.

Five authority questions were subsequently answered: active membership plus an event-management permission for authoring; an independent publication reviewer; re-review after any pre-publication edit; an approved event-specific re-entry policy; and gate assignment by an organiser with a gate-assignment permission. These are selected directions, not approved permission assignments or policy evidence. Checklist remains 2/7 checked and 5/7 unchecked; no tests or runtime operations were performed in this session.

Five re-entry/revocation questions were answered: one admission with separate permitted re-entries, event choice of allow/deny, recorded exit before re-entry, an approved per-event limit, and denial on the next online scan after operator assignment revocation. The limit value, in-progress scan behavior, authority contracts and owner approvals remain outstanding. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five race/expiry questions were answered: commit order for scan/revocation; release an expired hold while payment remains unresolved; reacquire after release even if capture preceded expiry; preserve existing expiry on duration changes; and shorten holds to an earlier approved event start without extending them. Owner approval, transaction contracts and actual concurrency evidence remain outstanding. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five verification and release-scope questions were answered: holder verification remains pending; a separately authorised support or finance reviewer may restore admission after verified unsuccessful refund and ticket eligibility checks; initial P25 scanning is online-only; and an authorised organiser configures the event re-entry limit subject to review. No permission contracts, numeric limit or owner approvals were supplied. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five payout/closure questions were answered: an authorised organiser requests payout after completion and reconciliation, with liabilities resolved or covered by an approved reserve; Finance separately reviews and approves; an authorised organiser marks completion; completion timing remains pending; and an authorised Finance operator sets the reserve under approved policy. These are selected directions, not approval evidence or an executable payout contract. No reserve calculation, permissions or P18 decisions were supplied. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five settlement questions were answered: external bank proceeds are excluded until verified and reconciled, all relevant bank proceeds must settle before payout, later liabilities remain open for authorised Finance review, a different Finance reviewer approves payout from the reserve setter, and Wallet-proceeds eligibility remains pending. No owner approvals, payout/custody contracts or recovery rules were supplied. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five entitlement/edit questions were answered: attendee assignment remains pending; released multi-ticket orders require full reacquisition or the approved refund process; extra verified payments remain unresolved for Finance with at most one ticket set; proposed edits pause new sales; rejected edits remain paused until authorised resumption. Owner approvals, permission/resumption and financial contracts remain outstanding. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five published-event questions were answered: after an approved edit, sales stay paused until publication and explicit authorised resumption; existing ticket orders retain purchase-time terms; capacity reductions below sold tickets plus active holds are rejected; and every proposed post-publication edit pauses sales, including non-commercial changes. These are selected directions, not owner approvals. Workflow permissions, versioned order evidence, capacity accounting and concurrency proof remain outstanding. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five refund-capacity/public-display questions were answered: returned capacity requires refund confirmation plus credential revocation and is permitted only before event start while sales remain open; customer-visible organiser/sponsor data is restricted to approved names/descriptions and explicitly public, approved contact details; during edit review the last approved event remains visible and the draft is withheld; and sold-out or sales-closed events remain visible with checkout disabled. These are selected directions, not owner-approved policies. Refund, authorization, privacy and publication contracts remain outstanding. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five event-time/schedule questions were answered: an explicit event time zone governs event/sales deadlines and is shown with customer times; ambiguous or invalid daylight-saving local times are rejected; existing tickets remain valid after approved rescheduling; and sales close no later than event start. The owner-approved time-zone, validation, notification/versioning and publication contracts remain outstanding. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five order/refund/inventory questions were answered: require a per-event maximum order quantity across all ticket categories; deny refunds after confirmed admission except through a separately authorised Finance review; leave general refund eligibility for unused/unadmitted tickets pending; and make checkout available again after expired holds release capacity when the approved event remains on sale, except while a review or operator pause is active. The limit value, refund contract/exception permission and inventory status transition remain unapproved. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five capacity/checkout questions were answered: category and aggregate event caps both apply; category allocations are firm and cannot go below sold tickets plus active holds; unallocated capacity is not sold until authorised assignment; one currency applies per event; and ticket price plus confirmed Payments fees are shown with total before confirmation. Capacity values, allocation permissions, currency and fee contracts/approvals remain outstanding. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five currency/fee questions were answered: authorised Finance/platform administrator assigns the single event currency; it locks after the first order or active hold; fee payer and tax policy remain pending; paid-ticket publication/sales are blocked without an assigned supported currency, with no default. Finance/Legal approvals, supported-currency list and Permissions/Payments contracts remain outstanding. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five pricing/payment-scope questions were answered: organiser proposes positive ticket prices; independent event review and separate Finance price approval apply; zero-price tickets are outside P25; and sales remain disabled without an enabled shared Payments method for the event currency. Pricing permissions, method-availability contract and owner approvals remain pending. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five checkout-confirmation questions were answered: require fresh confirmation if payable total changes; expire quotes/payment-start with the hold; require an authenticated active customer account; leave cumulative per-customer quantity limit and mandatory policy acknowledgement pending approved policy. Shared quote, identity, privacy/legal and financial contracts remain outstanding. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five ticket-notification/cancellation questions were answered: ticket issue, reschedule and cancellation notices use shared Notifications and customer preferences; external notices omit scannable credentials; cancelled event pages remain visible with sales disabled; a different authorised reviewer approves cancellation before status change and notices. Notification, credential, cancellation, permission and approval contracts remain open; refund policy remains unresolved. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

Five gate/lifecycle questions were answered: scanner shows only status/category; admission fails closed without online validation; re-entry limit counts returns only; exit records also require online validation; cancelled-event reactivation remains pending approved lifecycle policy. Privacy, availability, scan and lifecycle contracts/approval remain outstanding. Checklist remains 2/7 checked; no tests or runtime operations were performed in this session.

## Resume verification — 2026-10-27

- READY technical implementation T020: draft input now requires a positive event-wide proposed order limit; organizer-provided currency is discarded so only a separate Finance/platform authority can assign it; event and sales timestamps require explicit offsets consistent with the selected IANA zone; invalid/ambiguous DST values and sales close after event start are rejected. No publication, HTTP, inventory, checkout, payment, ticket, gate or payout behavior was added.
- Migration `2026_10_27_100002_add_draft_order_limit_and_optional_currency.php` is guarded for pre-existing columns. Local schema inspection found `max_tickets_per_order` already existed as nullable integer and `currency_code` was nullable; targeted migration applied successfully without duplicating the pre-existing order column. This is local schema evidence only, not deployment or acceptance.
- Read-only `php artisan migrate:status --path=...100002... --database=platform` confirmed the migration is recorded as **[10] Ran** in the local environment.
- `pest.bat tests/Feature/Modules/Events/EventDraftFoundationTest.php`: **8 passed, 0 failed, 0 skipped; 29 assertions**. Cases cover default denial, membership/tenant/revocation, capacity/version, forged ownership/version, sales deadline, invalid offset/DST gap, ambiguous DST, and positive order limit.
- `pint.bat --test` on EventDraftService, authorizer, new migration and focused test: **PASS** after Pint formatted EventDraftService. Initial format check found formatting differences and was corrected.
- Scoped PHPStan on EventDraftService, EventDraftAuthorizer and EventsServiceProvider: **0 errors** (PHPStan 1.x reports old-version and deprecated-config notices). The first scoped invocation used a mistaken provider path and failed before analysis; the corrected invocation passed. Xdebug emits a non-fatal warning because `c:/wamp64/logs/xdebug.log` is not writable; Pest also reports coverage mode warning. Tests completed with exit 0.
- Unix-style launcher attempt from repository root was invalid in PowerShell; rerun used the API service's `.bat` launchers. No P25 last-ticket or scan concurrency, integration, UI, or financial acceptance tests were run because those tasks remain contract-blocked.
- Task state: T017–T020 READY and implemented; T004–T006, T009–T014 BLOCKED as detailed in `tasks.md`; T008/T015/T016 DEPENDENT; T012 explicitly unapproved. Checklist remains **2 checked, 5 unchecked**. No live payment flow, P24 activation, deployment or commit occurred.
