# P25 Restart Notes — 2026-10-27

- Branch `main`; HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit created. The shared worktree contains extensive unrelated modified, deleted and untracked changes, including P24. Preserve them.
- This package adds `spec.md`, `research.md`, draft `plan.md`, `tasks.md`, `checklists/requirements.md`, `traceability.md`, `flow-runbook.md`, `evidence.md` and these notes. The existing `specs/049-payment-delivery-register/spec.md` receives a P25 status update. No application files, migrations, APIs or UI are changed for P25.
- Installed feature, plan setup, task setup and prerequisite scripts ran; Windows required a process-level execution-policy override. `check-prerequisites` found this package. Restore the previous `.specify/feature.json` pointer to `specs/070-payment-history-detail` after documenting this package.
- P19 targeted receipt/history tests: 13 pass, 15 skip, 35 assertions; targeted customer Flutter checkout/history/receipt tests: 28 pass, 0 skip. No P25 concurrency or financial tests have run.
- Outstanding: approved hold expiry and issuance threshold; late-capture disposition; actor/tenant/gate, refund and credential policies; cancellation/completion/reserve/retention rules; P18 AD-14/AD-10/LC14 and runtime evidence; P19/P22/P23 remaining release checks. Unknown existing financial outcomes or liabilities must be reconciled through their owning systems; this package created none.
- Exact next safe step: obtain versioned, owner-approved policy/contract evidence, complete the Spec Kit specification and plan review, and follow the `speckit-implement` checklist gate before implementing only unblocked tasks. Keep P24 and live payment processing disabled. Do not start P26.

## Independent draft continuation

- Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`, no commit. User approved passing the unchecked-checklist confirmation for independently implementable scope; outstanding policy approvals were not granted. Existing worktree changes remain uncommitted.
- New files: `wallet-ecosystem/services/api/app/Modules/Events/{Application/EventDraftService.php,Domain/Contracts/EventDraftAuthorizer.php,Infrastructure/DenyEventDraftAuthorizer.php,Infrastructure/Migrations/2026_10_27_100001_create_event_drafts.php,Providers/EventsServiceProvider.php}` and `tests/Feature/Modules/Events/EventDraftFoundationTest.php`; provider entry added to existing `bootstrap/providers.php`. Local migration applied; no role assignments, P25 routes or production operation.
- Tests: P25 3 pass/17 assertions; P25 + P24/Identity gate 12 pass/45 assertions; P25 + P24 financial flow 9 pass/65 assertions. Scoped PHPStan 0 errors; Pint passes after formatting. Initial Pint failed formatting and was fixed. See `evidence.md` for environment/exit detail.
- Next: obtain approved authority and policy evidence listed in `research.md`, then implement dependent review/publication and finance flows after contract and checklist review. Verify cross-connection revocation races, API idempotency and durable audit forwarding before enabling access. No actual P25 financial operations or liabilities were created. P24 HTTP and live callback processing remain false. Do not start P26.

## Latest P25 resume — 2026-10-27

- Branch `main`; current HEAD remains `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit created. The shared worktree contains extensive existing unrelated modified/deleted/untracked work. Preserve it.
- Updated P25 artifacts: `research.md` now reconciles all five unchecked checklist items, with the missing requirement/type, prior decisions/evidence, exact blocker, affected tasks and independent work; it also contains consolidated decision IDs D-01–D-07 and required external artifacts C-01–C-07. `tasks.md` classifies all incomplete tasks, and keeps the five checklist items unchecked. `plan.md`, `spec.md`, `traceability.md`, `flow-runbook.md`, `evidence.md` and this file reflect the implementation and gates. Managerial register P25/R35 is PARTIAL.
- Completed T020: positive proposed event-wide order limit; organiser currency input ignored pending Finance assignment; explicit-offset event times matched to selected IANA timezone; DST ambiguity/gaps rejected; sales close by event start. Changed service, guarded migration and draft feature tests. The default authorizer remains deny-all; no route/UI/publication or checkout was added.
- Schema migration `2026_10_27_100002_add_draft_order_limit_and_optional_currency.php` applied locally; read-only migration status reports batch 10, `Ran`. Prior local DB already had nullable `max_tickets_per_order` and nullable `currency_code`; migration handles the pre-existing column safely. This is not production migration or financial acceptance.
- Verification run: focused Pest test **8 passed / 0 failed / 0 skipped, 29 assertions**; Pint **PASS**; scoped PHPStan **0 errors**. PHPStan emits old-version/deprecated-config notices. Xdebug log/coverage warnings are environmental. Initial Unix launcher attempt was invalid for PowerShell; corrected Windows `.bat` launchers were used. A first PHPStan attempt used the wrong provider path and failed before analysis; the corrected command passed.
- Completed/ready: T001–T003, T017–T020. Still BLOCKED: T004–T006, T009–T014; DEPENDENT: T008, T015–T016; T007 remains open for required review. Five unchecked items remain unchecked. Exact remaining decisions/contracts are in `research.md`.
- No last-ticket/scan concurrency, provider, end-to-end financial, API/UI or independent Security/Finance acceptance was run. No financial mutation, P24 activation, live processing, deployment or commit occurred.
- Exact next safe step: obtain approved/versioned artifacts D-01–D-07 and C-01–C-07 as applicable, and request independent Security/Finance review for the implemented draft slice. Continue only tasks that those artifacts unblock. Keep P24 and live processing disabled; do not start P26.

## Specification continuation

- Reused package `072-paid-events-ticketing` under the explicitly invoked `speckit-specify` workflow; no new package or branch. Draft acceptance scenarios and assumptions were clarified, and the task classification marks T017–T019 as already completed. The five unchecked review markers and three policy questions remain open.
- Added a forged-context regression to `tests/Feature/Modules/Events/EventDraftFoundationTest.php`. Latest combined P25/P24 tenant/readiness run: 10 pass, 35 assertions, zero skipped; scoped Pint passes. No new migration or production operation. Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`, no commit.
- Next safe step: obtain owner-approved policy and authority evidence in `research.md`, complete the pending plan/review gates and implement only tasks unblocked by those decisions. Existing local test fixtures and shared worktree changes remain; do not reset or discard them.

## Five-question clarification session

- User selected verified payment plus ledger recording, a centrally configured hold duration, reacquisition or approved refund after late capture, denial while a refund is pending, and event/gate-specific operator assignment. See `spec.md`; selections are not documented owner approvals. T004–T016 remain blocked or dependent as classified; checklist remains 2/7 checked.
- Remaining: numeric hold duration and expiry semantics, owner approval records, gate assignment/revocation authority, refund failure/cancellation restoration, transfer/replacement/re-entry/offline rules, cancellation/completion/reserve/retention, and AD-14/AD-10/LC14. No tests or financial operations ran during this session. Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit.
- Next safe step: obtain named decision-owner approval evidence and unresolved contracts, then resume clarification for remaining decisions. Keep P24 HTTP and live payment processing disabled; do not start P26.

## Published-event clarification continuation

- Current branch `main`; current HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit created. Existing shared-worktree changes remain uncommitted and must be preserved.
- Selected directions: after post-publication edit approval, keep sales paused until the approved version is published and an authorised operator explicitly resumes; existing orders retain purchase-time terms; reject capacity reductions below sold tickets plus active holds; every proposed post-publication edit pauses sales. No owner approval is inferred.
- No implementation or tests ran in this clarification continuation. T004–T016 classifications remain unchanged; T017–T019 remain completed. Requirements checklist remains 2/7 checked.
- Outstanding: authorised roles and publication/resumption checks; versioned order evidence; capacity accounting and real concurrency proof; all previously recorded financial/admission approvals, P18 decisions and contracts. P24 and live payment processing remain disabled.
- Exact next safe step: obtain versioned owner-approved authority and policy evidence, then update traceability/tasks and implement only tasks that evidence unblocks. Do not start P26 or deploy.

## Refund-capacity and publication visibility clarification

- Branch `main`, HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit. Preserve all existing uncommitted worktree changes.
- Selected directions: refunded capacity returns only after authoritative refund confirmation and credential revocation, only while the approved sales window is open and before event start; customer display is restricted to approved organiser/sponsor names and descriptions, with contact information only if explicitly public and approved; during edit review show the last approved version and hide drafts; sold-out/sales-closed events stay visible with checkout disabled.
- No tests or runtime operations ran. Task classifications remain unchanged; checklist remains 2/7. The choices do not satisfy owner approval, privacy review, refund contracts, authorization contracts or publication acceptance.
- Exact next safe step: obtain approved owner policy/contract evidence and security/privacy review, then trace it to tasks before implementing any newly unblocked scope. Keep P24 and live processing disabled; do not deploy or start P26.

## Event-time and schedule clarification

- Branch `main`, HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit. Preserve existing uncommitted changes.
- Selected directions: explicit event time zone for start/sales windows, displayed with the event-local time; reject ambiguous/nonexistent daylight-saving local times; keep tickets valid after approved rescheduling, separately from cancellation/refund; close ticket sales no later than event start.
- No tests or runtime operations ran. Classifications remain unchanged and checklist remains 2/7. Owner-approved time-zone, time validation, schedule-update/versioning, notification and sales contracts remain needed.
- Exact next safe step: obtain approved contract evidence and map it to requirements/tasks before implementation; maintain P24/live-payment disablement and do not deploy or start P26.

## Order, refund and inventory clarification

- Branch `main`, HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit. Preserve existing uncommitted changes.
- Selected directions: require an approved event-level maximum quantity across categories; deny refunds after confirmed admission except with separate authorised Finance approval; leave general refunds for unused/unadmitted tickets pending policy; restore checkout automatically when expired holds free capacity only if the approved event is on sale and no review/operator pause remains.
- No tests or runtime operations ran. Task classifications remain unchanged; checklist remains 2/7. Limit values, Finance reviewer permissions, refund contracts, and state-transition evidence remain outstanding.
- Exact next safe step: obtain versioned owner-approved refund, permission and inventory contracts; then map evidence to tasks and implement only unblocked work. P24/live processing remain disabled; do not deploy or start P26.

## Capacity and checkout clarification

- Branch `main`, HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit. Preserve existing uncommitted changes.
- Selected directions: enforce category and aggregate event limits; allocations are firm, cannot fall below sold plus active holds, and unallocated event capacity remains unavailable until authorised allocation; one event currency applies to all categories; display ticket price and confirmed Payments fees separately with total before confirmation.
- No tests or runtime operations ran. Classifications remain unchanged; checklist remains 2/7. Capacity values/allocation permissions, event currency, fee calculation and shared quote contracts and owner approvals remain outstanding.
- Exact next safe step: obtain approved capacity/allocation and Payments quote/fee evidence, then map to tasks before implementation. Keep P24/live processing disabled; do not deploy or start P26.

## Currency and fee clarification

- Branch `main`, HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit. Preserve existing uncommitted changes.
- Selected directions: authorised Finance/platform administrator assigns one supported event currency; lock it after first order or active hold; block paid-ticket publication/sales if no supported currency is assigned (no default). Display ticket price and confirmed fees separately with the total before confirmation. Fee responsibility and tax treatment remain pending approved Finance/Legal policy.
- No tests or runtime operations ran. Task classifications unchanged; checklist remains 2/7. Currency authority, supported list, permission, fee/tax treatment, Payments quote and financial approvals remain outstanding.
- Exact next safe step: obtain the Finance/Legal decision records and shared Payments contract evidence, then map approved evidence to tasks before implementation. Keep P24/live processing disabled; do not deploy or start P26.

## Ticket pricing and payment-method clarification

- Branch `main`, HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit. Preserve existing uncommitted changes.
- Selected: organiser proposes positive ticket prices; independent event reviewer approves publication and a separately authorised Finance reviewer approves prices; maximum order quantity follows event proposal/review; P25 excludes free tickets; no checkout if shared Payments lacks an enabled method for assigned currency.
- Outstanding: approval evidence, permission identifiers/separation controls, limit value, currency/method availability contract and shared Payments quote. Fee responsibility and taxes remain pending. No tests/runtime operations ran; checklist remains 2/7 and task classifications unchanged.
- Exact next safe step: obtain versioned Finance/Event authority and shared Payments method/quote contracts, then update traceability before implementation. Keep P24/live processing disabled; do not deploy or start P26.

## Checkout confirmation and customer identity clarification

- Branch `main`, HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit. Preserve existing uncommitted changes.
- Selected directions: if payable total changes, require fresh confirmation; quote/payment start expires with inventory hold; require authenticated active customer account before hold/checkout. Cumulative per-customer maximum and mandatory acknowledgement of event policies remain pending approved policies.
- No tests/runtime operations ran. Task classifications remain unchanged; checklist 2/7. Shared quote/identity contracts and legal/privacy decisions remain pending.
- Exact next safe step: obtain approved quote/identity contracts and Legal/Product policy decision, then trace and implement only unblocked work. Keep P24/live processing disabled; do not deploy or start P26.

## Ticket notification and cancellation clarification

- Branch `main`, HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit. Preserve existing uncommitted changes.
- Selected: issue/reschedule/cancellation notices use shared Notifications and customer preferences; scannable credentials stay in authenticated accounts and are omitted from external notices; cancelled event pages remain visible with sales disabled; a different authorised reviewer approves cancellation before status change and notices. Cancellation refund handling remains unresolved.
- No tests/runtime operations ran. Task classifications unchanged; checklist 2/7. Notification/security, cancellation, permission and approval contracts remain outstanding.
- Exact next safe step: obtain approved notification/credential/cancellation authority contracts and map evidence to requirements before implementation. Keep P24/live processing disabled; do not deploy or start P26.

## Gate privacy and cancelled-event lifecycle clarification

- Branch `main`, HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit. Preserve existing uncommitted changes.
- Selected: scan response shows valid/invalid plus category only; deny entry during online validation outages; re-entry limit counts returns only; exit record also requires online validation; cancelled-event reactivation remains pending policy.
- No tests/runtime operations ran. Task classifications unchanged; checklist 2/7. Privacy, scanner/availability, cancellation and lifecycle policies/contracts remain outstanding.
- Exact next safe step: obtain approved contracts and decisions, then map them to P25 tasks. Keep P24/live processing disabled; do not deploy or start P26.

## Clarification continuation

- User selected the earlier of configured duration or event start for hold expiry; an authorised organiser may configure duration, subject to approved authority. After a definitively rejected/cancelled refund, admission remains blocked pending review. A lost credential is replaced for the same entitlement after holder verification and revocation. Transfer and cancellation refund policies remain pending. No owner approval evidence, numeric duration, authority or verification contract was supplied.
- Checklist remains 2/7 checked; T004–T016 remain blocked or dependent. No tests or financial operations ran. Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`; no commit.
- Next: obtain owner-approved authority, verification, transfer, cancellation and finance decisions and contracts; then resume clarification and dependent implementation. Keep P24 HTTP and live payment processing disabled; do not start P26.

## Authority clarification continuation

- Five questions answered: organiser membership plus approved event-management permission; separate publication reviewer; any pre-publication edit invalidates approval; approved event-specific re-entry policy; organiser with approved gate-assignment permission assigns gate operators. No permission identifier, assignment/revocation contract, re-entry recording rule or owner approval evidence was provided.
- T004–T016 remain blocked or dependent; checklist remains 2/7 checked. No test, financial operation, production deployment or commit in this session. Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`.
- Next safe step: obtain owner-approved permission/authority and remaining policy evidence, including P18 AD-14/AD-10/LC14; resume clarification before implementing affected flows. Keep P24 and live payment processing disabled.

## Re-entry clarification continuation

- Five questions answered: one admission with separate permitted re-entry records; event chooses allow or deny; each re-entry requires a recorded exit; an approved event-specific limit applies; the next online scan after operator revocation is denied. Owner approvals, limit value, exit and assignment contracts, and in-progress scan handling remain open.
- Checklist remains 2/7 checked; T004–T016 remain blocked or dependent. No tests, financial operations, production deployment or commit in this session. Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`.
- Next: obtain approved authority and policy evidence, including P18 AD-14/AD-10/LC14, and resolve in-progress scan semantics before implementing affected flows. P24 and live payment processing remain disabled.

## Race and expiry clarification continuation

- Five questions answered: scan/revocation follows commit order; an expired hold releases capacity while payment remains unresolved; a released hold requires reacquisition even where verified capture predates expiry; changing duration affects new holds only; an earlier approved event start shortens affected holds and never extends them. These selections require owner approvals, transaction design and real concurrency tests.
- Checklist remains 2/7 checked; T004–T016 remain blocked or dependent. No tests, financial operations, production deployment or commit in this session. Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`.
- Next safe step: obtain the approval evidence and missing contracts in `research.md`, then resume clarification for remaining transfer, cancellation, gate and payout decisions. P24 and live payment processing remain disabled.

## Verification and release-scope clarification

- Five questions answered: holder verification remains pending; after a definitively rejected/cancelled refund a separately authorised support or finance reviewer may restore admission only if the shared payment record confirms no refund and the ticket remains eligible; initial scanning is online-only; an organiser with approved event-management authority configures the event re-entry limit subject to event review.
- Permission contracts, numeric limits and owner approval evidence remain outstanding. Checklist remains 2/7 checked; T004–T016 remain blocked or dependent. No tests, financial operations, production deployment or commit in this session. Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`.
- Next safe step: obtain approval evidence and outstanding contracts in `research.md`; resume clarification on remaining cancellation, transfer and payout decisions before implementing affected flows. P24 and live payment processing remain disabled.

## Payout and closure clarification

- Five questions answered: an authorised organiser requests payout after event completion and reconciliation, with unresolved liabilities resolved or covered by approved reserve; Finance reviews and approves separately; an authorised organiser marks event complete; completion timing remains pending; an authorised Finance operator sets the reserve under approved policy.
- Reserve amount/calculation, permission contracts, review evidence, P18 execution/settlement, AD-14/AD-10/LC14 and decision-owner approvals remain outstanding. Checklist remains 2/7 checked and T004–T016 remain blocked or dependent. No tests, financial operations, production deployment or commit in this session. Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`.
- Next safe step: obtain owner-approved reserve, completion, payout and accounting contracts plus other outstanding P25 evidence; then resume clarification and dependent implementation. P24 and live payment processing remain disabled.

## Settlement clarification continuation

- Five questions answered: bank proceeds require verified reconciled settlement; wait for all relevant bank proceeds; record later liabilities for Finance review without automatic debit; a different Finance reviewer approves payout from the reserve setter; Wallet-proceeds eligibility remains pending. Definitions, recovery, custody, permissions and owner approvals remain open.
- Checklist remains 2/7 checked and T004–T016 remain blocked or dependent. No tests, financial operations, production deployment or commit in this session. Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`.
- Next safe step: obtain Finance/Legal and architect approvals and unresolved contracts including AD-10, AD-14 and LC14; then continue clarification and dependent implementation. P24 and live payment processing remain disabled.

## Entitlement and post-publication edit clarification

- Five questions answered: attendee assignment remains pending; full order quantity must be reacquired or the approved refund process followed; an extra verified payment remains an open Finance liability with at most one ticket set; proposed post-publication edits pause new sales; rejected edits remain paused until authorised resumption. Permissions, resumption checks, owner approvals and financial contracts remain outstanding.
- Checklist remains 2/7 checked; T004–T016 remain blocked or dependent. No tests, financial operations, production deployment or commit in this session. Branch `main`, observed HEAD `a72ca74252e4ecb42e08b7a9db398491d04f4778`.
- Next safe step: obtain owner-approved assignment, event-change, refund and payout evidence and contracts, then continue clarification and dependent implementation. Keep P24 and live payment processing disabled.
