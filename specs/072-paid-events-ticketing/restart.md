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

## Latest restart checkpoint � 2026-10-28

- Branch: `main`. HEAD: `c091fe8f42580b1654348d90490918d9aad66d23`. No commit created.
- Preserve the entire worktree. P25 files changed include research/tasks/spec/plan/traceability, Events draft/workflow services, workflow contracts/deny implementations/provider registration, migration `2026_10_28_100003_add_event_review_workflow.php`, and `EventDraftFoundationTest.php`. Existing unrelated untracked `.claude` settings/worktree artifacts and unrelated repository modifications were not touched.
- Completed this turn: reconciled source/predecessor evidence; implemented internal versioned event review/publication core with default deny; applied local migration; fixed the observed audit action bug. Tests: 11 pass/0 fail/0 skip/41 assertions; Pint pass; scoped PHPStan app files 0 errors. See evidence addendum for command caveats.
- Remaining: P25 tasks and statuses are in `tasks.md`; exact blockers/owners/unblock actions are in research B-01�B-14 and decisions D-01�D-09/contracts C-01�C-07. P18 payout work is separate and remains stubbed under AD-14/AD-10/LC14. Unknown financial outcomes/liabilities must remain with the owning shared service; this P25 work created none.
- **Single next action with greatest dependency effect:** have Identity/Permissions and Events Security owners publish the versioned, reviewed P25 permission and scope matrix (B-01), including organiser membership, authoring, independent publication and price review, operator resume, cancellation, event/gate assignment, revocation and reviewer separation. Bindings must remain deny-all until that evidence exists. This unlocks safely accessible P25 authoring/review/API/UI and precedes inventory integration. Finance currency/payment readiness (B-03) remains a parallel gate to paid publication.
- Safe continuation after B-01: wire only approved permissions to the internal workflow and draft contracts; add scoped API/admin interface tests. Do not implement payment/admission/payout work based on permissions alone. Keep P24 and live processing disabled; no deployment or next package.

Verification correction (2026-10-28): final focused `EventDraftFoundationTest.php` is 12 passed / 0 failed / 0 skipped / 44 assertions, after adding the explicit production-readiness deny test and applying Pint. Scoped Pint passes; scoped app PHPStan reports 0 errors. Migration status shows P25 migration 100003 Ran in local batch 11; unrelated platform migrations remain pending. No full migration run or production operation occurred.

## B-01 matrix checkpoint � 2026-10-28

- Added `authorization-matrix.md` as a review draft only. It maps every requested event and gate action to proposed `{module}.{resource}.{action}` permission slugs and proposed scoped assignments; existing global roles remain customer/agent/admin. It distinguishes established architecture and recorded P25 user decisions from proposals and unresolved ownership/policy details.
- Recorded author self-review exclusion (current service only checks original creator; version editor provenance remains a gap), sales resumption by an explicitly authorized but not yet role-mapped operator, and immediate next-scan gate revocation under the selected commit-order rule.
- The focused request is A-01 through A-04 in the matrix. Identity/Security and Events approvals are required; Finance must approve price authority and is separate from B-03 currency/payment availability. P18/AD-14/AD-10/LC14 remain B-11 and separate.
- B-01 remains BLOCKED. No RBAC migration, role grant, API, or UI was created. No additional READY P25 implementation task remains; T022 is completed as a draft artifact.
- Branch `main`; HEAD remains `c091fe8f42580b1654348d90490918d9aad66d23`; no commit. Preserve unrelated worktree changes.

Matrix verification checkpoint: reran `vendor\\bin\\pest tests\\Feature\\Modules\\Events\\EventDraftFoundationTest.php` after preparing the B-01 matrix; 12 passed, 0 failed, 0 skipped, 44 assertions. `git diff --check` passed (only Git LF/CRLF notices). No application files changed for this matrix turn; no additional static analysis or migration run was needed. The broader `artisan test --filter` suite-construction failure remains as documented in the prior evidence addendum.

## Latest checkpoint � 2026-10-28 A-decisions / T024

- Branch `main`; HEAD `c091fe8f42580b1654348d90490918d9aad66d23`; no commit. Preserve all unrelated worktree changes.
- Recorded A-01/A-02/A-04 as product decisions; A-03 as accepted in principle. None is independent owner approval. Updated `spec.md`, research, tasks, matrix and traceability. See `authorization-matrix.md` for the exact proposed mapping and `authorization-dependency-proposal.md` for module ownership and the conditional E04 boundary.
- T024 implemented: migration `2026_10_28_100004_create_event_draft_version_contributors.php`, cumulative contributors copied into each new version, approval denied for any version contributor, event and Finance approvers must differ. This is P25 Events code; no Identity grants or live access.
- Verification: P25 Events feature file 14 pass / 0 fail / 0 skip / 53 assertions; scoped Pint pass; scoped application PHPStan 0 errors; local migration applied. Xdebug/coverage and old PHPStan/deprecated-config warnings are environmental. Broader `artisan test --filter` suite-construction failure from the earlier checkpoint remains distinct and unclaimed as passing.
- B-01 remains blocked pending the exact A-03 permission map/grant authority and independent Identity/Security plus Events approval. B-03 and B-11 are separate. A-02 implementation does not pass independent Security or Finance review. A-04 does not pass independent Privacy review.
- Re-entry: event allow/deny configuration by an authorised organiser subject to review, online exit, and counting returns only are established; only D-02 numeric limit is open.
- No separate E04 package was started. If Identity owners require Identity-owned scoped grants, the exact authorization needed is explicit user authorization to start the E04 scoped-authorization extension described in `authorization-dependency-proposal.md`. P25 permission mapping approval is still required before exposing P25 routes.

**Correction to the earlier B-01 checkpoint above:** its note that only the original creator was excluded and version editor provenance was a gap predates T024. T024 now records cumulative contributors for each event version and rejects every such contributor from event and Finance approval; the focused 14-test run verifies this slice. Independent review and approved permission mapping remain outstanding.

## Scoped authorization implementation checkpoint — 2026-10-28

- Branch/HEAD to verify at resume: `main` / pending local modifications, no commit.
- Implemented accepted P25 development permission catalogue and Events-owned scope authorization; schema migration `2026_10_28_100005_add_scoped_event_permissions_and_assignments.php` applied only to local development database. No global role assignments or initial resource assignments were created.
- Focused Events test file: 19 passed / 0 failed / 0 skipped / 97 assertions. Scoped Pint passed. PHPStan for `app/Modules/Events` passed with 0 errors (old-version/deprecated-config notices; Xdebug warnings).
- Assignment-management endpoints stay BLOCKED pending decision on central grant/revoke authority, bootstrap of first authority, self-escalation and authority revocation. See `assignment-authority-proposal.md`.
- T005/T011 admission remain blocked for ticket/credential/gate contracts, numeric re-entry maximum and real concurrency evidence. Refund request remains blocked for ticket/order ownership and eligibility contract. Aggregate report and admin/mobile/API remain absent. B-03, B-11, independent reviews stay separate.
- Next safe step: decide assignment grant/revoke authority proposal before any assignment write endpoint; independently obtain the numeric D-02 returns maximum and required ticket/refund/gate contracts. Keep all routes unavailable, production deny-by-default, P24/live payments disabled. No deployment or next package.

### Verification correction — final expanded scope suite

Final `EventDraftFoundationTest.php`: 19 passed / 0 failed / 0 skipped / 100 assertions. Scoped Pint passed. PHPStan `analyse app/Modules/Events` passed with 0 errors. Migration status: `2026_10_28_100005_add_scoped_event_permissions_and_assignments` batch 13 / Ran locally. An intermediate run found a PHPStan nullable-object assertion and a test expectation issue; both were fixed before the final pass. Test fixtures do not represent production grants. Assignment audit writes/immutability, admission race, ticket ownership and report privacy remain unverified or unimplemented.

## P25 assignment-authority checkpoint — 2026-10-28

- Branch `main`; HEAD `c091fe8f42580b1654348d90490918d9aad66d23`; no commit. Preserve all existing unrelated and uncommitted `.claude` and P25 changes.
- Implemented P25 assignment grant/revoke paths, explicitly delegated org/Finance assignment-admin authority, non-cascading authority revocation and issuer history, permission registration without role grants, two-approver bootstrap command, event gate registry, and exact-event gate-validation delegation. Routes/command registered; migration 100006 and 100007 ran locally in platform DB (batch 14). Bootstrap was not executed; no operational permissions/admins were created.
- Latest focused `EventDraftFoundationTest.php`: 26 passed / 0 failed / 0 skipped / 120 assertions. Pint passed. One interim test run failed because a fixture used a gate UUID not in the newly enforced registry; fixture fixed and final focused suite passed. PHPStan first run had 3 correlation-header typing errors; code fixed, final rerun pending. Xdebug emits log/coverage environment warnings.
- Remaining P25: event authoring/review API/UI; issuance/inventory/late capture; scanner/admission and true concurrent scan proof; refunds/order ownership; aggregate organiser report; settlement/payout; independent Security/Finance/Privacy reviews; D-02 return limit; B-03; B-11/P18. Reactivation stays blocked. P24/live processing disabled.
- Gate configuration API now exists, but this does not enable admission or demonstrate concurrent revocation ordering. Operational bootstrap awaits Identity-controlled permission designation and independently verified two-approver evidence; the platform stores an opaque approval reference and has no governance-reference verifier.
- Next safe step: complete final static analysis and repo diff review, then obtain the independent Identity/Security review evidence and explicit Identity provisioning process for any operational bootstrap. Continue only P25 READY tasks; do not execute bootstrap, enable sales, deploy, activate P24 or start another package.
Final verification addendum — 2026-10-28: `EventDraftFoundationTest.php` **26/0/0, 123 assertions**; Pint scoped check passed; PHPStan `analyse app/Modules/Events` passed with 0 errors. Routes expose gate list/create and validation-assignment grant, plus org/event assignment administration. Migration 100007 adds the authoritative gate registry. Bootstrap remains unexecuted and no operations are enabled. Final static/test checks supersede the earlier pending note above.
Final count correction — after bootstrap idempotency and permission-catalogue assertions, final focused result is **27 passed / 0 failed / 0 skipped / 136 assertions**. Pint and scoped PHPStan passed. This replaces earlier counts in this addendum.
Migration batch correction: migration `2026_10_28_100006_add_assignment_administrator_authority.php` is batch 14; gate registry migration `2026_10_28_100007_create_event_gate_registry.php` is batch 15 on the local development database. Both report Ran. No production database was migrated.
Uncommitted implementation files for this checkpoint include `EventAssignmentManagementService.php`, `ScopedEventAuthorization.php`, `EventAssignmentController.php`, `BootstrapEventAssignmentAuthority.php`, `EventPermissions.php`, Events provider/routes, migrations 100006/100007 and the expanded `EventDraftFoundationTest.php`; P25 specification/task/evidence/research/traceability/runbook/restart/matrix/proposal and managerial register records are updated. Prior unrelated P25 changes and `.claude` artifacts remain preserved.
2026-10-28 P25 continuation: added fail-closed bootstrap approval evidence verifier and regression coverage, draft/review API and admin authoring page. Exact code/test links and remaining review evidence are in `identity-security-review-index.md` and P25 `traceability.md`. Tests for this continuation pending at note creation; do not treat prior 27/136 result as covering these changes. Bootstrap was not run. P25 remains PARTIAL; B-03, D-02, B-11 and independent review remain open.

### Verification correction � 2026-10-28 continuation

- Current branch `main`; current HEAD remains `c091fe8f42580b1654348d90490918d9aad66d23`; no commit. Worktree contains the prior P25 and unrelated `.claude` changes; preserve them.
- Completed fail-closed bootstrap verifier and regression test, draft/review API, admin page, route navigation and review index. T008 authoring/review slice and T015 admin slice are implemented for development, not production-ready.
- Latest scoped Events tests: **28 passed / 0 failed / 0 skipped / 139 assertions**. Pint passed; Events PHPStan 0 errors; 16 Events routes registered. Scoped TypeScript check passed. Full project TypeScript remains blocked by stale generated `.next/types` imports for unrelated admin-portal roles. ESLint attempt was interrupted with no result. `git diff --check` passed.
- No migration, bootstrap, production grant, P24 activation, live payment, deployment or commit occurred.
- Remaining: endpoint-level authorization tests; named independent Identity/Security reviewer and signed evidence; Finance/Privacy review; D-02 numeric limit; B-03 currency/payment readiness; ticket/order/admission/attendance implementation and concurrency evidence; P18 AD-14/AD-10/LC14 and T049/T050/T051/T055/T056/T059/T060 for payout; separate broad test harness repair.
- Exact next safe step: appoint/record the independent Identity/Security reviewer and submit `identity-security-review-index.md` with the linked code, 28-test result, API authorization gaps and concurrency/audit evidence requirements. Keep bootstrap, publish/sales and financial paths disabled.

Relevant uncommitted P25 changes include `identity-security-review-index.md`; task/research/evidence/traceability/runbook/restart/register updates; Events assignment/workflow/auth services, bootstrap verifier contract/default denial, authoring controller/routes and migrations 100003�100007; and admin `/community/events` UI/navigation. Existing unrelated `.claude` worktree/settings and earlier P25 draft/authorization changes remain in the shared worktree and were preserved. No commit was created.

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
Restart: branch main; HEAD c091fe8f42580b1654348d90490918d9aad66d23. Keep the current uncommitted worktree. Exact next safe step: obtain the B-03 Finance/Payments contract owner decisions; independently route the prepared Identity/Security review handoff through the governance owner. Run T009 PostgreSQL concurrency verification before calling its foundation verified. Do not execute bootstrap, expose publication/sales, start P18, enable P24, deploy or commit solely for checkpointing.

### Final check details � 2026-10-28

The repository-supported full admin lint reports **30 ESLint errors in 7 files**: payment-bank creation/account pages and API operation editor; funeral collections page; campaign create/detail pages; and finance reconciliation exceptions. Errors are unused imports/variables and unassociated form labels. These are outside the changed P25 authoring/navigation files; touched campaign-list file has four nullable-string warnings but no errors. Full `npm.cmd run build` compiles its optimized bundle, then exits unsuccessfully during Next lint/type validation on these same repository errors. It is not a successful build.

After the build regenerated stale `.next/types` artifacts, `node node_modules/typescript/bin/tsc --noEmit` exited 0. Scoped P25 lint exited 0 with 4 warnings in campaign-list. The tracked `tsconfig.tsbuildinfo` was restored to its pre-checkout HEAD content after TypeScript verification. `git diff --check` exited 0; only line-ending normalization notices were printed.

The focused Events run after scheduler registration remains **31 passed / 0 failed / 0 skipped / 152 assertions**. Pint `--test` passed for EventDraftService, EventInventoryHoldService, expiry command, EventsServiceProvider and focused test. Events PHPStan exited successfully with **0 errors** (existing PHPStan old-version/deprecated-config notices). The command list confirms `events:expire-inventory-holds`; migration 100008 is recorded Ran on local `platform`. Do not interpret local schema state as deployment evidence.

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
Restart checkpoint: branch and HEAD must be verified on resume; latest known branch main, HEAD c091fe8f42580b1654348d90490918d9aad66d23. Exact next safe step: obtain Finance + Payments owner decisions/evidence for the B-03 choices, without activating publication. Keep production bootstrap, paid publication, live sales, deployment and P24 disabled.

## Restart checkpoint — B-03 development slice (2026-10-28)

Branch: `main`. HEAD at start/current recorded base: `c091fe8f42580b1654348d90490918d9aad66d23` (no commit created). The worktree contains substantial unrelated and prior uncommitted P25/admin work; preserve it. This continuation added Events currency assignment and lock files/migrations, Payment actor-free static eligibility files, focused tests, and B-03 task/research/evidence/proposal records.

Completed in this slice: event Finance currency assignment endpoint and service, registered permission without role grants, provenance/precision snapshot, first inventory-hold lock and drift review issue, organizer-edit preservation, actor-free typed method readiness service, publish/resume readiness recheck retained behind deny binding. Local migrations 100009 and 100010 applied only via targeted paths. Checks passed: Events feature 40/210; Payment unit 20/34; PostgreSQL concurrency harness four scenarios; scoped PHPStan 0 errors; Pint --test passes. General migration command failed before P25 at legacy duplicate identity.users; details in evidence.md.

Remaining: canonical module/purpose/country owner contracts; Payment order/purpose resolver and T010 order caller; MultiCurrency cross-connection precision-change lock/snapshot contract; readiness issue checks on publication/resume/new checkout; B-06 fee/tax/discount and total terms; T004 owner approval/version/recovery for payment-to-fulfilment threshold and late capture; T005 credential/ownership/transfer/refund and numeric D-02 limit; T006 cancellation/closure; P18 payout/settlement for T014; independent Identity/Security/Privacy/Finance review. No operational permission assignment or bootstrap ran.

Exact next safe step: route the proposed canonical context definitions and currency-change coordination contract to the established Events Product, Payments/ProviderRegistry and MultiCurrency owners. Keep the Events publication readiness binding deny-by-default until those contracts exist. Separately approve B-06 and T004 before implementing shared checkout/issuance. Do not run full legacy migrations against this populated database, execute bootstrap, enable paid publication/live sales, activate P24, deploy, start P18, or commit solely to write this checkpoint.

## Restart checkpoint — 2026-10-28 (P25 contract/verification continuation)

Branch `main`; HEAD `c091fe8f42580b1654348d90490918d9aad66d23`. No commit created. The working tree remains dirty with prior P25 work and unrelated admin/source changes; preserve all of it. This continuation changed only the P25 Events feature test and P25 evidence/task/research/runbook/proposal/register/restart records. No migration was run.

Completed: rechecked Payments identifier/country contracts, enumerated Events hold and absent order paths, drafted the bounded MultiCurrency coordination contract, verified currency drift failure evidence, and inspected the legacy Identity migration mismatch read-only. Latest scoped Events feature suite: 41 passed, 0 failed, 0 skipped, 220 assertions. Pint passed. Scoped Events PHPStan passed with tool-version/config notices. Previously recorded PostgreSQL independent-session hold harness passed four scenarios; it does not cover an independent MultiCurrency currency update racing a hold.

Remaining: Payments owner must register/approve canonical module/purpose and resolver ownership; Events + Payments must approve event country semantics; MultiCurrency + Events must implement and verify a shared currency-row serialization contract; Finance/Payments must decide existing-hold/pending-attempt disposition on currency drift. T010 must implement the first durable order and invoke the currency lock in its transaction. T004/B-06, D-02, P18/B-11 and independent review gates remain separate. Legacy database migration repair requires Identity owner/DBA review of every pending migration before any history reconciliation or DDL.

Exact next safe step: obtain the focused owner decisions/contracts in the attached approval table, starting with the canonical context owner and MultiCurrency coordination design, then implement the shared cross-module guard and PostgreSQL race tests before claiming that currency readiness is race-safe. Keep bootstrap, paid publication, checkout, sales, deployment, P24 and P18 disabled; do not execute the migration repair proposal.

## P25 CTX/MC/FIN checkpoint — 2026-10-28

Branch main; HEAD c091fe8f42580b1654348d90490918d9aad66d23; no commit made. Working tree remains dirty with prior P25 work and unrelated admin changes; preserved.

Completed for development: canonical identifier collision guard and allowlist compatibility checks; no event resolver until an order model exists; physical single venue-country record and validation; typed server-derived context; shared PostgreSQL currency-definition coordination via transaction-scoped advisory key and database trigger; first-hold currency/precision lock and drift review/pause behavior. Targeted MultiCurrency and Events migrations applied locally only. No Identity migration history repair.

Latest verification: Events 47/47, 253 assertions; registry 3/3, 6 assertions; eligibility 22/22, 38 assertions; separate-session PostgreSQL currency coordination three scenarios PASS; separate-session inventory four scenarios PASS; targeted Pint PASS; targeted PHPStan PASS. Evidence in evidence.md. Database race scripts that temporarily mutate BWP must run sequentially.

Remaining: T010 order creation and mandatory durable_order currency lock; production readiness/publication/checkout unavailable; B-06 fees/taxes/order totals; T004 issuance threshold and T005 credentials/refund/transfer; D-02 numeric re-entry limit; pending existing-hold/payment-attempt disposition on drift; T014 payout/B-11/P18 dependency; named independent reviewers and signed Security/Privacy/Finance findings; read-only Identity migration mismatch remediation decision.

Exact next safe step: continue only a P25 task that is policy-supported; before T010, resolve B-06 and T004/T005 contracts and call lockForFirstObligation(eventId, durable_order) atomically before order insertion. Keep production bootstrap, paid publication, checkout/sales, P24, deployment and P18 disabled. Do not edit migration history or claim independent review.

Caller compatibility follow-up: the legacy AdminFxManagement feature suite was attempted but is blocked by missing platform.configuration_change_requests (21/22 failures, 0 skips); no migration/history repair was made. Static inspection confirms all existing currency-lock callers still use the default platform connection. The existing Funeral Assistance path passes 6 tests / 48 assertions after registry collision protection.

Final policy handoff for continuation: business-policy-decision-packet.md contains the exact remaining B-06/T004/T005/D-02/FIN-01 questions, repository state mapping, proposals and external evidence owners. No new policy was approved here. Current focused AdminFx API regression remains NOT VERIFIED because its required platform.configuration_change_requests migration is Pending and the test errors before currency activation; no migration/history repair is authorized. The smallest coherent order-to-ticket approval set is listed at the end of that packet. Continue only after receiving the listed approvals/contracts; keep all activation gates closed.

# Restart checkpoint — P25 order/ticket scope — 2026-10-28

Branch: `main`. HEAD at start was `c091fe8f42580b1654348d90490918d9aad66d23`; no commit created. Worktree contained prior P25 and unrelated Admin/Payments/MultiCurrency changes; preserved. This continuation adds `AdmissionCredentialVault.php`, its focused unit test, and P25 task/research/traceability/evidence records only. No migration, route, order model, payment resolver, ticket table or operational grant was added.

Completed: recorded the user's initial product choices; implemented the isolated opaque credential generation/hash/encrypted-retrieval primitive; verified its focused test (2 passed, 9 assertions). Existing decisions remain distinct from independent review and financial/provider approvals.

Remaining: T010 needs approved fee/tax/rounding/quote contract inputs and Payments/Ledger verified journal-bound order evidence, including AD-09 external method behavior; Events must implement the durable order path and call `lockForFirstObligation(eventId, 'durable_order')` atomically before insertion. T011 needs per-entitlement durable ticket records and idempotent issuance, authenticated purchaser display, Identity step-up/recovery replacement with atomic revocation, and online scan implementation/concurrency evidence. Re-entry remains disabled. T013 late capture/refund/cancellation finances and currency-drift recovery remain pending Finance/Payments. Independent Security/Privacy/Finance review and appointed reviewers remain missing. B-11/P18 only gates payout (T014), not order or ticket issuance.

Verification this continuation: `vendor\\bin\\pest tests\\Unit\\Modules\\Events\\AdmissionCredentialVaultTest.php` — 2 passed, 0 failed, 0 skipped, 9 assertions; first run failed due missing Laravel facade bootstrap, corrected by using `Tests\\TestCase`, then passed. No other checks were run. Exact next safe step: Payments/Ledger owners define and test authoritative order-to-posted-journal evidence and AD-09 behavior; in parallel, Events may prepare the durable order/entitlement design behind deny-by-default integration, but must not activate checkout or issue tickets until the contract and remaining Finance terms are satisfied.

Production bootstrap, publication, checkout/sales, deployment and P24 remain disabled. Preserve the worktree; do not start P18 or another package automatically.

## Final continuation verification/status — 2026-10-28

Branch `main`; HEAD `c091fe8f42580b1654348d90490918d9aad66d23`; no commit. The worktree had pre-existing uncommitted P25, Admin, Payment and MultiCurrency work. This continuation added `AdmissionCredentialVault.php`, its unit test and P25 decision/task/evidence/traceability updates. Earlier P25 restart records remain historical; this is the current checkpoint.

Actual checks: focused vault Pest test 2 passed / 0 failed / 0 skipped / 9 assertions; existing `EventDraftFoundationTest.php` 47 passed / 0 failed / 0 skipped / 253 assertions; Pint `--test` for the vault/test passed; PHP syntax checks passed for both PHP files; targeted PHPStan reported 0 errors; `git diff --check` passed with existing line-ending warnings. Xdebug emitted environment notices. The first vault test attempt failed from missing Laravel facade bootstrap; the test now uses `Tests\\TestCase` and passes.

Remaining exact blockers and next step: no Events order or ticket model/path exists; Payments/Ledger must provide and test verifiable payment/order/organisation/purchaser/currency/amount/posted-journal evidence, external method accounting under AD-09, idempotent replay/query/reconciliation, and fulfilment acknowledgment before Events binds a resolver or issues. Finance/tax decisions remain pending for fee payer/schedule, tax treatment and rounding, cancellation/refund/late-capture and currency-drift disposition. Events must then implement a durable order path that atomically locks currency before insert, stores one ticket per entitlement, issues idempotently from verified evidence and supports authenticated purchaser retrieval/replacement. Re-entry remains disabled. Independent Security/Privacy/Finance reviewers are not identified/appointed; operational owner/SLA for unresolved financial cases remains to be established.

No migration, order schema, checkout, resolver binding, route, publication, live sale, bootstrap execution, P24 activation, deployment or P18 work occurred. Continue P25 only. Keep all activation gates closed.

## Payments/Ledger dependency handoff — 2026-10-28

Bounded proposal: `specs/072-paid-events-ticketing/payment-ledger-dependency-proposal.md`. This points to existing P08 `specs/057-ledger-ack-fulfilment`; do not create a duplicate package. Recommended dependency order: (1) Finance Lead supplies written AD-09 (and verify separate AD-08 wallet account configuration); (2) authorize and resume P08 with P06/P02 and Ledger owners for actual capture persistence, journal ID/state verification and typed internal confirmation evidence; (3) verify P11 delivery/replay checks; (4) continue P25 T010 durable order, currency-lock caller, consumer inbox and one-way fulfilment acknowledgement; (5) T011 per-ticket issuance/display/admission after evidence contract. This is a proposal, not implementation authorization.

Exact code gaps confirmed: Payment event carries no journal/accounting state; the main P08 path does not persist the returned journal ID to the attempt; captured actual amount is read for excess-capture detection but not persisted or emitted; Payment status contracts do not expose all evidence; Ledger lacks a scoped posted-journal evidence query; Events has no order resolver, consumer inbox or fulfillment ack API. AD-09 external attempts remain captured/`pending_ledger`, with no confirmation event. No contract or endpoint was changed in Payments/Ledger.

P25 work independently supported remains authoring/review, T009 holds/currency locking and credential primitive. Fee/tax/rounding gate order totals; Payments/Ledger evidence gates order-to-payment integration and issuance; refunds, cancellation, late capture and currency-drift recovery remain their separate Finance/service dependencies. P18 payout is not an order/issuance predecessor. Keep bootstrap, publication, checkout/sales, deployment and P24 disabled.

Current proposal checkpoint: branch `main`, HEAD `c091fe8f42580b1654348d90490918d9aad66d23`. Proposal/research/task/traceability/restart files are uncommitted; prior unrelated and P25 changes remain in the worktree. No Payments/Ledger/Platform code was modified and no package tests were run for this proposal. `git diff --check` reported no whitespace errors (only existing line-ending normalization warnings). Exact next safe step: authorize resumption of existing P08 with the bounded scope in `payment-ledger-dependency-proposal.md`; keep AD-09 bank implementation blocked until the Finance Lead supplies the written mapping/evidence. No activation or deployment.

## P08 dependency continuation checkpoint - 2026-10-28

P08 (`specs/057-ledger-ack-fulfilment`) was resumed with explicit authorization. Development implementation and test evidence are summarized in `p08-dependency-checkpoint.md` and `payment-ledger-dependency-proposal.md`. Branch `main`, HEAD `c091fe8f42580b1654348d90490918d9aad66d23`; no commit. Preserve extensive existing uncommitted P25/admin/multicurrency work and the newly added P08 changes. Next safe step remains schema-correct isolated verification of the additive P08 migration, acknowledgements and crash recovery; do not run migrations against the shared populated schema until its legacy Identity history mismatch is repaired through separately reviewed authorization. Then complete P08 PHPStan/quickstart and independent Payments/Ledger/Security review. P25 order creation and T004/T010 remain blocked; no resolver or issuance work was started. AD-08/AD-09, production bootstrap, publication, checkout/sales, deployment and P24 remain disabled.

## Dependency-chain checkpoint - 2026-10-28

Branch/HEAD: main / c091fe8f42580b1654348d90490918d9aad66d23 (no commit). Existing unrelated worktree changes preserved.

P08 development Wallet capture and Payment/Ledger confirmation evidence now work and have database-backed recovery tests. Wallet owns hold consumption and capture records; Ledger owns the debit journal; Payments verifies, persists capture evidence and publishes a confirmation outbox row. This unblocks technical development for supported internal Wallet evidence only. AD-08 is not approved, so operational Wallet capture remains disabled. AD-09 external methods remain pending_ledger.

P25 T008/T009 remain implemented and verified for development. There is no remaining independent P25 task whose price/issuance behavior can be safely implemented without creating unsupported financial semantics: T010 needs B-06 payable-total rules and the T004 issuance threshold/order binding; T011 durable entitlements need T010 and approved Identity step-up plus independent Privacy/Security review; T013 needs refund/cancellation policy; T014 alone depends on P18/B-11. The existing P11 design rejects a generic inbox when the durable domain effect can be the idempotency boundary. Do not add a separate inbox without a durable order/ticket effect.

Verification: latest focused run after Pint formatting: 75 pass / 0 fail / 0 skip / 411 assertions; P08 capture races pass; P25 inventory and currency coordination PostgreSQL races pass; targeted PHPStan level 8 reports 0 findings; changed-slice Pint check passes. Fresh isolated bootstrap plus migration repeat passed at 313 records. Full details and pending review/policy gates are in evidence.md and business-policy-decision-packet.md.

No production bootstrap, live mapping, paid publication, checkout, sales, deployment or P24 activation. No P18 work started. Next safe action is Finance/Payments approval of the remaining B-06 payable components and T004 evidence/issuance policy; then implement P25 order+deduplicated entitlement effect in dependency order. P18 is relevant only for T014.

Final count correction: combined regression after the SC-06/SC-07 additions and repair-exhaustion fix is **80 passed / 0 failed / 0 skipped / 441 assertions**. Pint checked 14 files; targeted changed-code PHPStan plus the new P08 feature tests reports 0 errors. Earlier 75-test checkpoint is superseded. P08 T028 still means the literal operator walkthrough, separate from its automated scenario acceptance; T026 full module PHPStan remains open at the recorded baseline.

Final combined suite correction - 2026-10-28: after adding persisted Payment confirmation outbox dispatch retry coverage, the P08/P11/P25 combined regression is **81 passed, 0 failed, 0 skipped, 446 assertions**. `WalletCapturePaymentIntegrationTest::test_durable_confirmation_outbox_delivers_after_payment_commit_when_dispatch_is_retried` asserts the outbox row remains pending and no notification exists after Payment commit, then dispatches that row in a later worker invocation and asserts one delivered event/notification. This is restart/re-dispatch evidence, not a forced OS process termination. The remaining consumer commit-before-ack flow needs a P25 durable order/entitlement effect.

## Final P25 approval handoff - 2026-10-28

Status remains PARTIAL; current branch/HEAD are `main` / `c091fe8f42580b1654348d90490918d9aad66d23` (no commit). Current slice: T008 authoring/review and T009 inventory/currency concurrency are implemented and verified for development. Credential vault primitive is unit tested. T010 order/checkout/issuance is not implemented; T011 durable per-ticket issuance/admission and T013 refund/cancellation remain incomplete. P18/B-11 is a T014 payout-only dependency.

Verification: P08 scenario walkthrough on disposable PostgreSQL passed 81 tests/446 assertions plus SC-04 repair scan 5 tests/8 assertions; AdminFxManagementTest passed 22 tests/118 assertions; full Payment/Ledger PHPStan is 485 findings matching latest baseline and changed-slice analysis is zero. See evidence and P08 verification-traceability for the initially wrong AdminFx shared-container invocation and successful disposable rerun. No legacy migration/reset occurred.

The approved T004 issuance invariant is settled, not awaiting re-approval. P08 development evidence is available. The implementation gap is an Events order binding to P08 evidence `purposeReference` and immutable order/purchaser/amount/currency, followed by a durable idempotent per-ticket effect and acknowledgement. Genuine recovery policies still undecided: late captured funds after hold expiry, failed/unknown refunds and cancellation financial disposition.

**Next permitted implementation slice after B-06 answers:** implement T010 authoritative order, immutable price/fee/tax snapshot, first-obligation currency lock, bound Payment quote/confirmation evidence, and atomic durable ticket issuance/ack for the supported test-only Wallet path. Required inputs: Product: fee payer per fee class and legal seller/merchant identity; Finance/Fees: approved versioned fee schedule, fee bases and rounding; qualified Tax/Legal: jurisdiction/classification/rates/effective dates/exemptions/tax base/display/collection/remittance evidence; Payments/Ledger: written confirmation of P08 evidence fields, posted-journal guarantee and applicable purpose/organisation scope. Independent Finance/Security/Privacy reviewers still need formal appointment and signed evidence. No recommendation is approval.

No paid publication, live checkout/sales, operational bootstrap, production grant, deployment, P18 or P24 activation.

## T010/T011 implementation checkpoint — 2026-10-28

Branch/HEAD: `main` / `c091fe8f42580b1654348d90490918d9aad66d23`; no commit. Preserve all pre-existing unrelated Admin, P08, Wallet, Payments, Ledger, MultiCurrency, P25 and Spec Kit worktree changes.

Implemented for development: typed immutable pricing boundary with fail-closed production default; authoritative purchaser/organisation/event/hold-bound order and line snapshots; quote TTL constrained by pricing and hold expiry; payable-component reconfirmation; first-order currency locking in the order transaction; valid-order-only Payment purpose resolver; customer-specific Payment eligibility; typed P08 confirmation verification; durable Events inbox/conflict records; atomic per-entitlement opaque credential issuance and hold consumption; P08 fulfilment acknowledgement only after ticket commit; safe retry after commit-before-ack. Contract: `contracts/order-ticket-pricing-and-issuance.md`.

Verification: isolated PostgreSQL 15.19 migrations 100012/100013 passed; focused P25 tests 7 passed / 0 failed / 0 skipped / 66 assertions; full Events feature regression 54 passed / 0 failed / 0 skipped / 319 assertions; separate-session PostgreSQL duplicate-order race passed with exactly one order; scoped changed-code PHPStan reported 0 errors. Exact commands/results are in `evidence.md`.

Remaining: B-06 fee bearer/schedule, seller and tax determination/calculation rules; AD-08 operational mapping and AD-09 external methods; Payments/Ledger independent acceptance of P08 evidence contract; late-capture/refund/cancellation disposition; gate admission and concurrent scanner authorization; Identity step-up credential replacement; independent Security/Privacy/Finance reviews; operational bootstrap and activation. The issuance invariant is already approved; no re-approval is needed. P18/B-11 is not a dependency for T010/T011.

Next safe step: complete final lint/route/migration status checks, then obtain outstanding B-06 owner inputs before any production pricing binding. Keep deny pricing/publication defaults. No production bootstrap, paid publication, checkout/sales activation, deployment, P18 or P24.


## T011 online-admission checkpoint — 2026-10-29

Branch/HEAD: `main` / `c091fe8f42580b1654348d90490918d9aad66d23` (no commit). Preserve all existing unrelated and uncommitted Admin, P08, Wallet, Payments, Ledger, MultiCurrency and P25 worktree changes.

**Completed for development:** purchaser ticket listing/credential display, exact event/gate online validation, single initial admission per ticket, atomic attendance/audit, Payments refund eligibility denial, aggregate-only organiser attendance, customer ticket page and step-up replacement flow, agent online scanner, admin aggregate view. Re-entry, transfer and offline remain unavailable.

**Verification completed:** full Events feature regression 61/0/0 (408 assertions); focused admission 8/0 (107 assertions); PostgreSQL separate-session admission/revocation/replacement races PASS; Pint 10 files PASS; customer and agent scoped Flutter analyze PASS; admin page lint PASS. Detailed commands and limits in `evidence.md`.

**Remaining:** independent Identity/Security and Privacy review evidence; Finance review and B-06 pricing/seller/tax inputs before any paid publication/sales; unresolved refund/cancellation/late-capture dispositions; production assignment/bootstrap governance. Development implementation is not activation approval. Keep production bootstrap, paid publication, checkout/sales, deployment and P24 disabled.

**Next safe step:** submit the code/evidence index to the established independent reviewers when governance appoints them; continue only non-activation work within P25 while owner decisions/contracts are pending. No reviewer appointment is recorded in this checkpoint.



Verification count correction (2026-10-29): after explicit cross-event and cross-organisation gate denial coverage, the final Events suite is 61 passed / 0 failed / 0 skipped / 424 assertions; targeted gate test is 1 passed / 27 assertions. The earlier 408 assertion count is superseded.


## P25 convergence checkpoint — 2026-10-29

Branch/HEAD: `main` / `c091fe8f42580b1654348d90490918d9aad66d23`; no commit. The worktree contains extensive pre-existing/uncommitted changes across Admin, P08, Payments, Wallet, Ledger, MultiCurrency, Platform, Identity and P25. They were preserved. The isolated DB used was `wallet_ecosystem_bootstrap_20261028`; only the additive Events migration `100016` was applied here.

Completed for development: T008/T009/T010/T011 code slices are present; admission credentials now consume step-up proof fingerprints; purchaser responses are private/no-store; Wallet success replay repairs only pending-ledger Wallet accounting; customer/agent UI checks added; acceptance matrix and traceability reconciled. See `evidence.md` for actual test commands/results.

Important verification gap: E2E-01 is NOT VERIFIED. Existing P25 issuance tests use test-only Payment evidence. P08 real capture tests do not create a P25 order or issue Events tickets. Do not claim these separate suites establish the complete requested journey. The first P08 outbox attempt found an old P25-labelled generic fixture without order/service auth; that fixture is now isolated under purpose `bill` and P08 integration rerun passes.

Remaining work by dependency: (1) implement/run E2E-01 with authorized test-only workflow/pricing/grants, real P08 evidence/outbox, real Events service actor, real inbox/ack, purchaser listing, online scan and aggregate report; (2) B-06 qualified inputs still deny production pricing; (3) late-capture/refund/cancellation disposition and D-02 numeric re-entry maximum remain unresolved; re-entry stays off; (4) P18/B-11 blocks payout only; (5) independent Security/Privacy/Finance review is pending and reviewer ownership is not established in project records.

Checks executed: Events 63/453, Payment replay unit 4/21, P08 Wallet/Ledger/Payment 6/63, PostgreSQL admission races PASS, Identity step-up 11/65, customer widget 1/1, agent widget 2/2, Admin accessibility 4/4, scoped PHPStan no errors, final Pint 10 PHP files PASS. The agent scoped analyzer is rerunning after its final brace cleanup; append the final result before continuing. No complete P25 E2E suite is claimed.

Exact next safe step: implement the dedicated P25 E2E-01 acceptance test/harness using existing services and test-only fixtures, then run it in the disposable PostgreSQL DB. Preserve production bootstrap, publication, checkout/sales, deployment, P18 and P24 disablement. Do not start another package.

Final analyzer correction (2026-10-29): agent scoped Flutter analyze now passes with no issues after adding braces to the changed scanner code. Agent widget test rerun after that edit: 2 passed. This supersedes the “rerunning” note above.

## P25 integrated journey checkpoint - 2026-10-30

Branch/HEAD: `main` / `c091fe8f42580b1654348d90490918d9aad66d23` (verified before this execution); no commit was created. The worktree already contained extensive unrelated and cross-package uncommitted changes; all were preserved. Current working changes include P25 test/evidence and existing Payment/Wallet/Events changes; inspect `git status --short` before further edits. No reset or cleanup outside the test's own scoped fixture rows occurred. The named disposable DB `wallet_ecosystem_bootstrap_20261028` only was used; the legacy development DB and its migration history were not touched.

Completed: E2E-01 development journey, including real order, supported Wallet capture, posted Ledger evidence, Payment outbox, Events inbox/tickets/ack, purchaser access, exact gate scan, attendance/audit and aggregate report. The trigger-injected commit-before-ack retry and exact duplicate outbox delivery produced no duplicate journal, tickets or acknowledgement. E2E-01 test passed 1/0/0, 60 assertions; focused regressions passed 73/0/0, 537 assertions; combined 74/0/0, 597 assertions. Pint for the new journey file passed. Detailed exact command and limits are in `evidence.md`.

Engineering status: E2E-01 PASS for the supported development Wallet path; P25 remains PARTIAL. Do not claim worker-process termination, external AD-09 integration, live provider certification, production pricing, Finance/tax approval, independent review or production readiness. Test-only publication/pricing/provider/capture configuration/operator assignments/service actor are not operational approvals.

Remaining: B-06 fee/tax/seller decisions; late-capture/refund/cancellation disposition; P18/B-11 payout/settlement; independent Security/Privacy/Finance review and reviewer appointment; operational bootstrap/grants and all production activation gates. Keep re-entry, transfer and offline admission unavailable. No publication, live checkout/sales, deployment, P18 or P24.

Exact next safe step: have Finance/Fees and qualified Tax/Legal provide the B-06 inputs recorded in `business-policy-decision-packet.md`; separately obtain independent review evidence. Continue P25 only after those owner inputs, and keep production bindings denied until the complete approval/activation process succeeds.

## P25 readiness handoff update - 2026-10-30

Current branch/HEAD remains `main` / `c091fe8f42580b1654348d90490918d9aad66d23`; extensive pre-existing uncommitted changes were preserved. See `git status --short`; no commit/reset was performed. `outstanding-decision-register.md` consolidates open decisions/evidence and reviewer requests. Settled product rules remain unchanged.

Latest compatibility-focused verification: Pint 2 PHP test files passed. P25IntegratedJourneyTest 1/0/0, 61 assertions; IdempotentSuccessReplayTest 4/0/0, 21; WalletPaymentAdapterTest 27/0/0, 81; WalletCapturePaymentIntegrationTest 6/0/0, 63. Total 38/0/0, 226 assertions. The initial adapter test attempt reported two stale one-call expectations; those were aligned with the Wallet same-reference idempotency contract and the complete suite then passed. No Flutter/browser or worker-process termination check ran. The previous checkpoint's Events 63/453 result was not rerun here.

Status remains PARTIAL. Open owner inputs are B-06 commercial/tax/rounding/seller, late capture/cancellation/refund disposition, and P18/B-11 predecessor evidence; independent review appointments and signed decisions are separate. Exact consequences and closed gates are in the register. Next available action after inputs: implement only the approved pricing/refund/cancellation/payout integration slices in dependency order, then obtain independent review evidence. Continue to keep production pricing/publication/sales/bootstrap/deployment/P18/P24 disabled.

## Accepted-policy implementation checkpoint — 2026-10-30

Branch/HEAD at task start: main / c091fe8f42580b1654348d90490918d9aad66d23. Worktree already contained extensive unrelated and cross-package uncommitted changes. This task added only P25 pricing contract/code/tests and P25 records; no commit, reset or legacy database operation was performed. Disposable database used for feature regressions: wallet_ecosystem_bootstrap_20261028, PostgreSQL 15.19, network p08-isolated-20261028_wallet_test.

Completed: T030 production pricing validation for immutable organiser fee schedule and tax snapshots; owner-accepted commercial/refund/cancellation direction recorded separately from specialist approval; four independent-review record templates prepared. Historical order snapshots are verified to remain stable after a later test-policy configuration change. Production pricing remains bound to deny implementation.

Remaining: actual fee schedule values/calculation authorization and verified seller/merchant legal relationship; qualified Tax/Legal matrix and examples; late-capture/refund/cancellation final dispositions and shared recovery evidence; B-11/P18 payout dependencies (AD-14, AD-10, LC14, P18 execution/ledger verification); independent Identity/Security/Privacy/Finance appointments and signed review; all operational bootstrap/publication/checkout/sales/production activation gates.

Tests executed: see evidence.md accepted-policy implementation section. The initial feature test attempt exposed an uncaptured variable in the new synthetic fixture (15 test errors); the fixture was corrected and the final rerun passed. Do not count the first run as a final test failure against application behavior. No operational or provider configuration was added. The next permitted action is to obtain the actual Finance/Fees schedule, seller verification, qualified Tax/Legal matrix and independent reviewer routing; after those inputs, implement only the bound pricing-service integration/authorization and rerun acceptance. Late-capture, refund/cancellation and payout remain separately gated.

Final verification/cancellation correction - 2026-10-30: after adding the non-test schedule cross-event denial test, final focused tests passed: EventPricingBreakdownTest 4/0/0, EventDraftFoundationTest 65/0/0 with 461 assertions, P25IntegratedJourneyTest 1/0/0 with 61 assertions; total 70/0/0 and 531 assertions. Pint passed 4 files. Scoped PHPStan emitted only config/tool notices for 5m35s and was interrupted before analysis completed; no static-analysis result is claimed.

Newly recorded exact gap: Events still has no cancellation lifecycle transition or durable notification flow, so cancellation-triggered sales closure/ticket invalidation is not implemented. That engineering slice is distinct from the unresolved financial refund/cost-allocation terms. Keep cancellation mutation unavailable until its authorized review and durable notification path are implemented.

Final pricing verification correction - 2026-10-30: after the quote immutability assertions were added, the focused disposable-PostgreSQL rerun passed Pint (4 files), EventPricingBreakdownTest (4 tests / 9 assertions), EventDraftFoundationTest (65 / 463), and P25IntegratedJourneyTest (1 / 61): 70 passed, 0 failed, 0 skipped, 533 assertions total. `git diff --check` passed for P25 documentation and scoped code/test paths. Scoped PHPStan was interrupted after 5m35s without an analysis result. No database migration, API/UI change, operational configuration, production grant, or activation occurred. Current branch/HEAD remains `main` / `c091fe8f42580b1654348d90490918d9aad66d23`; pre-existing cross-package worktree changes remain preserved. Next safe step: obtain the missing Finance/Fees values and qualified Tax/Legal matrix, verified seller evidence, and independent review appointments; implement no production binding until those gates pass. Cancellation lifecycle implementation remains a separate unimplemented P25 engineering task; late-capture/refund dispositions and P18 payout dependencies remain open.

## T030 verification and cancellation readiness checkpoint - 2026-10-30

Branch/HEAD: `main` / `c091fe8f42580b1654348d90490918d9aad66d23`. Existing extensive uncommitted cross-package changes were preserved; this slice changed only P25 pricing tests and P25 records. No commit, migration, legacy database operation or production configuration change occurred. No AGENTS.md was found at repository root, `C:\Projects`, or `C:\`; the constitution and installed `speckit-implement` instructions were read. No extension hook file exists. Existing requirements checklist remains reviewer-owned/read-only; earlier authorization to continue remains in force.

T030 development verification now passes: PHPStan 1.12/Larastan at configured level 8 reports no errors for EventPricingBreakdown, EventOrderService, EventPricingContract and EventPricingRequest; the command uses `--debug` so progress is visible. Pint `--test` passes 2 test files. EventPricingBreakdownTest: 6 passed / 13 assertions. Full EventDraftFoundationTest: 66 passed / 469 assertions. Synthetic pricing fixtures only. Exact command and coverage links are in evidence.md.

Cancellation is assessed but not implemented or exposed. Reusable: scoped cancellation permission identifiers, authored cancellation policy field, P11 same-database connection-aware outbox, and Notifications idempotent queue/delivery status. Missing: enforced request and separate reviewer transitions, cancellation state/audit, ticket invalidation plus purchaser-visible status, cancellation notification template/listener and delivery reconciliation, CANCEL-01 financial disposition, and Payments/Ledger refund execution/status/recovery. Do not manually set event/ticket/payment states. The details and validation checklist are in outstanding-decision-register.md.

Next safe action: obtain the recorded Finance/Fees schedule and authority, verified seller/merchant evidence, and qualified Tax/Legal matrix; separately approve cancellation financial disposition and supply the shared refund contract before implementing the cancellation transition. Appoint independent Identity/Security/Privacy/Finance reviewers through governance. Keep production pricing, publication, checkout/sales, bootstrap, deployment, P18 and P24 disabled.

Restart count correction - 2026-10-30: current T030 verification totals 72 passed / 0 failed / 0 skipped / 482 assertions: unit pricing 6/13 plus full Events feature 66/469. P25IntegratedJourneyTest was not rerun here and is not included; retain its separate prior E2E-01 evidence boundary. Scoped PHPStan level 8 completed with no errors; this supersedes the earlier interrupted result.

Final restart test-count correction - 2026-10-30: the effective-window test was expanded to future and expired dates for both fee and tax. Latest T030 totals: unit pricing 6 passed / 13 assertions; full Events feature 66 passed / 475 assertions; total 72/0/0 and 488 assertions. Targeted effective-window check 1/12 is included in that full suite. Pint 2 files and scoped PHPStan level 8 pass. P25IntegratedJourneyTest was not rerun.
