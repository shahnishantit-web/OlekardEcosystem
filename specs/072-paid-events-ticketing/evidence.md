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

## 2026-10-28 review/publication foundation and verification

**Status: PARTIAL; live journeys remain unavailable.** Reused the existing P25 package and all recorded user-selected behavior. Checklist remains 2/7 checked, 5/7 unchecked. No business decision, permission, privacy/finance review, external contract or production readiness is inferred from this work.

**Implemented:** internal version-locked review transitions (submit, independent event review, independent price review, publish, reject, explicit sales resume); publish snapshot and edit pause/preserve behavior; Finance currency and enabled-payment readiness seam; production bindings deny all. Migration `2026_10_28_100003_add_event_review_workflow.php` applied to local development DB. No HTTP routes/UI, checkout, holds, tickets, scans, refunds, settlement, payout or production migration was added.

**Checks actually run:**
- `vendor\\bin\\pest tests\\Feature\\Modules\\Events\\EventDraftFoundationTest.php`: PASS, 11 tests, 41 assertions, 0 skipped. Two intermediate runs failed on an audit-action key lookup; fixed before final passing run.
- Scoped Pint `--test`: PASS.
- Scoped PHPStan on changed application/contracts/infrastructure/provider files: PASS, 0 errors. When the test file was included, PHPStan reported one existing test-harness property type mismatch at `EventDraftFoundationTest::$connectionsToTransact` (`array<string>` vs `array<int,string|null>`); no application findings. PHPStan 1.12/deprecated-config and Xdebug warnings are environment/tool notices.
- `php artisan test --filter=EventDraftFoundationTest`: FAIL before scoped execution because the configured suite loaded an unrelated `ReceiptDownloadTokenServiceTest.php` and exited during suite construction. The direct file Pest invocation above is the valid scoped result.
- Migration command for `2026_10_28_100003...`: PASS locally; no production database was touched.

**Updated classifications:** T021 READY/implemented, internal workflow only. T008 PARTIALLY IMPLEMENTED, still blocked for accessible authoring/publication by B-01 permission mapping and B-03 currency/payment availability. T015 remains DEPENDENT; T009�T014 remain blocked/dependent per `tasks.md`. No task checkbox was marked complete based on approval to proceed.

**DoD:** source/predecessor reconciliation PASS; draft and internal review foundation PASS for tested slice; accessible authoring/publication FAIL (B-01/B-03/routes/UI); inventory/checkout/issuance FAIL (B-04�B-07); admission/concurrency FAIL (B-08/B-09/B-13); closure/refund/payout FAIL (B-10/B-11); independent Security/Finance review FAIL (B-12); no live/P24/deployment change PASS.

**Verification correction, 2026-10-28:** The final focused Pest run after adding default publication-readiness denial and formatting passes **12 tests / 44 assertions / 0 skipped**. Scoped Pint passed after formatting. Scoped application PHPStan passed with 0 errors; when test file included it flagged the pre-existing harness `$connectionsToTransact` type declaration only. `migrate:status --database=platform` shows `2026_10_28_100003_add_event_review_workflow` in batch 11 as Ran; unrelated platform migrations remain Pending, and no full migration batch was run. The earlier 11-test/41-assertion figure in this addendum is superseded by this correction.

B-01 matrix work verification: 12 focused Events tests passed (44 assertions, 0 skipped); `git diff --check` passed. These confirm the existing scoped Events foundation only, not RBAC matrix approval, identity scopes, concurrency, finance readiness or production authorization.

### Verification and status clarification

- The T024 focused implementation was verified with 14 passed, 0 failed, 0 skipped, and 53 assertions in `EventDraftFoundationTest.php`; scoped Pint passed; scoped application PHPStan reported 0 errors; the new contributor migration was applied locally. These results cover the contributor and separation-of-duties slice only.
- The scoped run does not establish Identity/Security/Privacy/Finance approval, permission mapping approval, admission concurrency, live payment readiness, or P25 end-to-end acceptance. The earlier broad `artisan test --filter` suite-construction failure remains a separate suite-harness issue and is not represented as a passing suite.
- The matrix uses one proposed gate permission, `events.gate.validate`, for online validation, entry, exit, and re-entry. A separate exit permission is not proposed. All proposed identifiers remain unregistered and denied.

### Final scoped-check verification correction — 2026-10-28

After expanding the delegation boundary assertions, the final focused `EventDraftFoundationTest.php` run is **19 passed, 0 failed, 0 skipped, 100 assertions**. Pint passed; PHPStan on `app/Modules/Events` passed with 0 errors; migration `2026_10_28_100005_add_scoped_event_permissions_and_assignments` is locally recorded as batch 13 / Ran. Earlier intermediate scoped runs had one expectation failure and one PHPStan type finding; both were corrected before these final passing results. The test run uses test-only permission substitutes and assignment fixtures, not production grants. No role-permission grants were inserted.

Limitations: no separate-session race test or gate endpoint; no ticket/order schema to verify refund ownership; no aggregate-report endpoint to test privacy fields; no assignment grant/revoke writer or bootstrap; no independent owner review. The assignment audit table exists, but its write and immutability enforcement path is not implemented.

## 2026-10-28 — assignment authority and gate write-path verification

Implemented the development-only Events assignment write path: Identity permission registration without role grants; org/event assignment grant and revoke; organisation/Finance administrator delegation and revocation; audited issuer-history access; explicit two-approver bootstrap command; event-owned gate registry; event-scoped gate-manager gate-validation assignment grant/revoke; provider route/command registration. All routes require authenticated Identity, and write routes use the existing identity-scoped idempotency middleware. No operational assignment authority was provisioned and the bootstrap command was not run.

Local migrations 100006 and 100007 ran in batch 14 (platform connection). Final focused `EventDraftFoundationTest.php`: 26 passed, 0 failed, 0 skipped, 120 assertions. Pint scoped check passed. An intermediate focused run failed one pre-existing fixture because the new gate registry correctly enforced its composite gate foreign key; the fixture was updated to create actual event gate records and the final run passed. An earlier PHPStan run found 3 correlation-header typing errors; the controller was fixed. Final PHPStan rerun is pending at this checkpoint. Xdebug coverage/log warnings are environment notices. Test fixtures do not represent production grants or bootstrap approval evidence.

Verification scope: tests cover idempotent duplicate grant and repeated revoke, issuer provenance preservation, no cascading revoke, post-revocation denial, Finance authority separation, self-assignment/self-bootstrap rejection, gate manager exact-event restrictions, gate-validation-only permission, and deny-by-default no-grant conditions. They do not establish real separate-session concurrency, admission outcomes, ticket ownership/refund, organiser report privacy, independent Security/Finance/Privacy review, approved governance reference validation, or production bootstrap readiness. P25 remains PARTIAL; five workflow review checklist items remain unchecked; P24/live processing unchanged.
Final verification correction: after adding event-scoped gate listing and cross-event revoke denial, the final scoped run is `EventDraftFoundationTest.php`: **26 passed / 0 failed / 0 skipped / 123 assertions**. Scoped PHPStan on `app/Modules/Events` passed with 0 errors; Pint scoped check passed. Migrations 100006 and 100007 are locally recorded as batch 14 / Ran. PHPStan emitted only existing old-version/deprecated-config notices; Xdebug emitted missing log/coverage warnings. One earlier PHPStan run failed on correlation-header typing and one later run on an inferred transaction result; both code issues were corrected before the final pass.
Final count correction — after bootstrap idempotency and permission-catalogue assertions, final focused result is **27 passed / 0 failed / 0 skipped / 136 assertions**. Pint and scoped PHPStan passed. This replaces earlier counts in this addendum.
Migration batch correction: migration `2026_10_28_100006_add_assignment_administrator_authority.php` is batch 14; gate registry migration `2026_10_28_100007_create_event_gate_registry.php` is batch 15 on the local development database. Both report Ran. No production database was migrated.

Final verification record (supersedes all interim counts above): **27 passed / 0 failed / 0 skipped / 136 assertions** for `EventDraftFoundationTest.php`; scoped Pint passed; scoped PHPStan passed with 0 errors. Migration 100006 is batch 14 and migration 100007 is batch 15. The final run includes bootstrap idempotency, management-permission no-role-grant checks, gate listing, and cross-event revoke denial.
## 2026-10-28 bootstrap and authoring continuation

- Bootstrap verifier added and bound to `DenyBootstrapApprovalEvidenceVerifier`. The service calls the verifier after all permission/membership checks and before inserting the administrator record or audit. No command/bootstrap was executed.
- Added test `test_bootstrap_reference_string_alone_cannot_create_operational_authority`; it uses valid test-only permissions/memberships and a reference string, expects 403, and asserts no administrator/audit rows. The positive bootstrap idempotency test explicitly injects a test-only verifier; this is not operational evidence.
- Added authenticated draft create/read/update and review/price approval/reject routes plus `/community/events` admin authoring/review workspace. There are deliberately no publish, sales, checkout, scanner, refund, attendance or payout actions in this UI/API addition.
- Results for these new changes are pending execution; do not reuse the prior 27/136 count as verification of this continuation.
- Prior broad `php artisan test --filter` failure occurred before scoped tests because unrelated `ReceiptDownloadTokenServiceTest.php` failed during suite construction. This remains a harness failure, distinct from focused test results.

### Verification result for 2026-10-28 continuation

- `vendor\bin\pest tests\Feature\Modules\Events\EventDraftFoundationTest.php`: **28 passed, 0 failed, 0 skipped; 139 assertions**. This includes the new reference-string-only bootstrap denial and no-write assertions. All positive bootstrap coverage uses a test-only verifier fixture.
- Pint on changed Events service/controller/routes/test files: **PASS**.
- `vendor\bin\phpstan analyse app/Modules/Events --no-progress`: **PASS, no errors**. Existing notices: outdated PHPStan 1.12 and deprecated iterable config; Xdebug log path unavailable.
- `php artisan route:list --path=v1/events --except-vendor`: **PASS, 16 routes registered**; no publish or sales-resumption route is registered.
- Admin scoped TypeScript check using a temporary config including the new page/navigation/route: **PASS**. Full repo `tsc --noEmit` remains **FAIL** on four pre-existing stale `.next/types` imports under `app/(admin)/admin-portal/roles`; it reported no diagnostics in the new P25 files.
- `git diff --check`: **PASS** (Git reports LF-to-CRLF normalization notices only).
- ESLint invocation for the two touched TSX files did not return output and was interrupted; it is **not counted as a pass**. No migration was needed or run. No bootstrap/production grant was run.

The unrelated broad `php artisan test --filter` suite-construction failure in `ReceiptDownloadTokenServiceTest` remains distinct from the successful focused Events suite.

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

### Final B-03 scoped verification — 2026-10-28

- Targeted migrations: `php artisan migrate --database=platform --path=app/Modules/Events/Infrastructure/Migrations/2026_10_28_100009_add_event_currency_assignment.php --force` PASS; equivalent command for migration `2026_10_28_100010_register_event_currency_permission.php` PASS. No global migration/reset was used.
- Focused Events suite: `vendor/bin/phpunit --no-coverage tests/Feature/Modules/Events/EventDraftFoundationTest.php` PASS, **40 passed / 0 failed / 0 skipped / 210 assertions**. Includes exact permission+assignment checks, currency edit preservation, first-hold lock persistence through expiry, and precision-drift fail/issue recording.
- Payment eligibility unit suite: `vendor/bin/phpunit --no-coverage app/Modules/Payment/Tests/Unit/EligibilityPipelineTest.php` PASS, **20 passed / 0 failed / 0 skipped / 34 assertions**. Includes actor-free static context.
- PostgreSQL concurrency harness: `php tests/Concurrency/P25InventoryHoldPostgresTest.php` PASS against PostgreSQL (database `wallet_ecosystem`); distinct backend PIDs. Last-capacity race leaves exactly 1 held unit and a currency lock; replay race stores one ID/row; expiry/release race preserves capacity; injected audit failure rolls back both hold and domain audit and capacity recovers. Four scenarios passed, no skipped scenarios.
- Scoped PHPStan command over changed Events/Payment implementation files: PASS, 0 errors. Repository PHPStan emits its existing old-version/deprecated-config notices.
- Pint `--test` over changed PHP source/tests/routes: PASS.
- A full `php artisan migrate --database=platform --force` was attempted and FAILED before reaching P25 due existing `identity.users` table when the legacy first migration attempted to recreate it. No destructive action followed. Targeted P25 migrations above succeeded.
- An earlier direct PHPUnit invocation without `--no-coverage` reported all 20 Payment tests passing but returned a runner warning because configured Xdebug coverage was unavailable. The final `--no-coverage` command passed cleanly. The Events suite also passed with `--no-coverage`.

These are development checks, not Finance/Security/Privacy approval, production bootstrap evidence, provider certification or release acceptance.

Final correction after drift availability guard (2026-10-28): when currency is inactive or precision changes, the failed hold rolls back first; Events then persists the review issue, domain audit record and `sales_state=paused` together under the event row lock. The precision-drift feature test now also asserts sales are paused. Final Events suite: 40 passed / 0 failed / 0 skipped / **211 assertions**. Final Payment eligibility unit suite, after adding shared event-currency/tenant-context validation: **21 passed / 0 failed / 0 skipped / 35 assertions**. Final scoped PHPStan: 0 errors; Pint --test: PASS. The PostgreSQL concurrency harness passed after adding the concurrent first-hold currency-lock assertion (4 scenarios, all passed).

Latest verification correction (2026-10-28): currency reassignment is also explicitly tested after the first-obligation lock and returns conflict without changing the stored assignment. Latest Events feature result is **40 passed / 0 failed / 0 skipped / 213 assertions**. Payment eligibility unit remains **21 passed / 0 failed / 0 skipped / 35 assertions**. The focused currency assignment, precision drift, availability pause, shared actor-free context, concurrency and static checks remain as recorded above.

### Currency-drift verification and contract gaps — 2026-10-28

Command from `wallet-ecosystem/services/api`: `vendor/bin/phpunit --no-coverage tests/Feature/Modules/Events/EventDraftFoundationTest.php` — **41 passed / 0 failed / 0 skipped / 220 assertions**. This latest run includes `test_inactive_currency_blocks_hold_and_persists_review_issue_audit_and_sales_pause` and expanded precision-change assertions. For both inactive currency and changed precision, the attempted hold returns 409, no hold row is created, a durable unresolved review issue exists, one `currency_review_required` domain audit row is committed, and active sales are paused. The hold attempt transaction rolls back before the separate issue/pause/audit transaction; the assertions establish the review record is not silently discarded with the failed hold. These are current-database feature checks, not cross-module race tests.

`vendor/bin/pint --test tests/Feature/Modules/Events/EventDraftFoundationTest.php` — PASS. `vendor/bin/phpstan analyse app/Modules/Events --no-progress` — PASS, no errors; the installed PHPStan emits old-version/deprecated-config notices, and PHP emits an Xdebug log-path warning. Prior real separate-session PostgreSQL concurrency harness remains PASS for its four inventory scenarios as recorded above. No test was run here that races a MultiCurrency status/precision write against an Events hold/order; that guarantee is NOT VERIFIED and the current services do not coordinate on a common row lock.

Currency path audit: only `EventInventoryHoldService::createHold` calls the first-obligation lock today. There is no durable Events order path, so T010 must add and test the order-lock caller before claiming OR-lock completeness. Refer to `research.md` for identifier, country and bounded MultiCurrency contract evidence.

Legacy migration investigation was read-only. Existing `identity.users` schema/index/FK objects coexist with all inspected Identity migrations marked Pending in platform migration history; exact origin and equivalence of all pending side effects are unknown. No migrations, DDL or migration-history changes were executed. Proposed non-destructive reconciliation is in `research.md`.

No provider/account enablement, operational grants/bootstrap, publication, checkout, sales, deployment, P24 activation, P18 work or live processing occurred. Independent Finance, Security, Privacy and Identity reviews remain unperformed.

## CTX/MC/FIN bounded verification — 2026-10-28

**Events feature suite:** vendor/bin/phpunit --no-coverage tests/Feature/Modules/Events/EventDraftFoundationTest.php — PASS, 47 tests, 253 assertions, 0 failures, 0 skips. Covers country scope/invalidation, actor-free context, and drift issue durability/sales pause.

**Payments units:** vendor/bin/phpunit --no-coverage app/Modules/Payment/Tests/Unit/BusinessPurposeResolverRegistryCollisionTest.php — PASS, 3 tests / 6 assertions; vendor/bin/phpunit --no-coverage app/Modules/Payment/Tests/Unit/EligibilityPipelineTest.php — PASS, 22 tests / 38 assertions. Resolver collision/idempotency and provider-account allowlist compatibility are covered.

**Real PostgreSQL scripts:** invoke directly using php; these are executable acceptance scripts, not PHPUnit test classes. php tests/Concurrency/P25CurrencyCoordinationPostgresTest.php — PASS, three race scenarios: writer-first precision change; hold-first with blocked currency deactivation and no lock cycle; writer rollback then hold retry/replay. Separate backend PIDs on the same database/server. Assertions confirm drift rejects new hold, persists issue and pauses sales; deactivation preserves committed hold; rollback leaves one idempotent hold. Supported lock order completed without deadlock; no synthetic deadlock-victim injection. php tests/Concurrency/P25InventoryHoldPostgresTest.php — PASS, four scenarios: last capacity; idempotent replay; expiry/release race; rollback/recovery. These scripts temporarily mutate shared local BWP state and must run sequentially, never against production.

**Static checks:** targeted Pint --test for changed PHP files PASS. Targeted PHPStan over Events, changed MultiCurrency coordinator/writer and changed Payments registry/readiness files PASS, 0 errors (existing old-version/deprecated-config/Xdebug notices). A preliminary targeted run found one resolver class-string typing issue; registration validation was fixed and the final targeted run passed. Broad Payment-module PHPStan is not clean because of unrelated existing findings and is not evidence of this scoped check.

**Migrations:** targeted local migrations 2026_10_28_001800_add_currency_definition_coordination and 2026_10_28_100011_add_event_location_scope applied. Identity migration history/schema was not repaired. Full legacy migration mismatch remains documented read-only.

This proves local development coordination only. It does not prove provider certification, independent Finance/Security/Privacy approval, pending-operation disposition, production readiness, T010 order locking, publication, checkout, sales, P24, deployment or bootstrap.

### Existing MultiCurrency caller regression limitation

Ran vendor/bin/phpunit --no-coverage tests/Feature/Modules/MultiCurrency/AdminFxManagementTest.php — FAIL, 22 tests / 27 assertions, 21 failures, 0 skips. Failures are repeated PostgreSQL SQLSTATE 42P01 because platform.configuration_change_requests is absent from this local populated database. Read-only migration evidence already records the schema/history mismatch; no missing table was created and no migration history or Identity data was changed. Existing AdminFxManagementService calls FxRateAdminService::lock(key) with its original one-argument shape, which remains compatible because the connection argument is optional. A separate existing funeral assistance feature suite passed 6/6 / 48 assertions, confirming the legitimate direct resolver registration path still works.

Verification refinement: writer-first precision race directly updated the currency row without calling the PHP lock helper; the database trigger acquired the shared advisory key and blocked the separate-session hold until commit. The hold then detected precision drift and persisted review/pause evidence. The hold-first case still uses the existing MultiCurrency service lock path. This verifies both the service helper and the raw-SQL trigger participate in the same local PostgreSQL protocol. Supported ordering completed without a deadlock; no artificial victim was induced.

### Decision-packet and AdminFx failure relationship

Prepared business-policy-decision-packet.md from repository contracts and existing P25 decisions; no policy implementation or migration was performed. It maps T004 proposals to PaymentAttemptStatus, PaymentIntentStatus, PaymentAccountingStatus, PaymentFulfilmentStatus, PaymentConfirmedEvent, Ledger JournalStatus, PaymentRefundStatus and RefundsPayment capability contracts.

AdminFxManagementTest is materially related to MC-01 because it tests the currency status maker/checker API and activation path that must coordinate with Events. Its 21 failures (22 tests total) are caused by absent platform.configuration_change_requests; migration 2026_10_02_000002_create_platform_configuration_change_requests is Pending in read-only migration status. These failures happen before they can establish currency-write compatibility, so mark that API path NOT VERIFIED. The raw SQL trigger race and the explicit MultiCurrency helper race are separately verified; they do not replace this missing API verification. Identity migration conflict remains read-only and unmodified.

# Order/ticket decision implementation — 2026-10-28

User product/design approvals are recorded in `tasks.md` and the P25 research decision log. They do not provide Finance/tax approval, provider capability evidence, independent review, or live authorization.

Implemented `AdmissionCredentialVault` as an isolated development primitive. It creates 32 random bytes encoded as an unpadded base64url credential (256 bits), SHA-256 lookup hash, and Laravel-encrypted recoverable display value. It supports verification and decrypting the same stored credential; it does not regenerate on display. No plaintext logging, API response, table, issuance route, ticket entitlement, scanner or payment path was added. Credential storage requires the encrypted value remain protected from serialization/logging and the plaintext be returned only to an authenticated purchaser after Identity access/step-up checks; those API controls are not yet implemented.

Command: `vendor\\bin\\pest tests\\Unit\\Modules\\Events\\AdmissionCredentialVaultTest.php` from `wallet-ecosystem/services/api` — **2 passed, 0 failed, 0 skipped; 9 assertions**. The first run failed because the isolated unit file had no Laravel facade bootstrap; the test was corrected to use the repository `Tests\\TestCase`, and the rerun passed. Existing Xdebug coverage/log configuration notices remain. No broader suite, migration, lint, static analysis or build was run in this continuation.

Integration blocker: no durable Events order model/path exists, and Payment's current event/status contract does not expose consumer-verifiable evidence binding all required P25 fields to a posted ledger journal. A future Payments/Ledger contract must provide that evidence (including AD-09 behavior), idempotent replay semantics and a verifiable order reference. Events then must persist one entitlement per ticket atomically/idempotently after verified evidence and use the T009 event-row/currency coordination protocol before the first durable order insert. No placeholder resolver or permissive fallback is acceptable.

## Sustained P08 dependency verification - 2026-10-28

P08 Wallet committed-capture capability and Payment integration are available for development. The final scoped API-container run on disposable PostgreSQL 15.19 reports **75 passed, 0 failed, 0 skipped, 411 assertions** across Wallet capture, Wallet-to-Ledger-to-Payment recovery, acknowledgement, P11 replay, Events authoring/hold foundation, credential primitive and Payment confirmation listener. Separate PostgreSQL session tests passed for Wallet capture-vs-release and capture-vs-expiry; P25 inventory and currency-writer/hold races also passed. The clean bootstrap workflow created required schemas and two complete migration runs finished with 313 rows. Changed Wallet/Payment/Ledger PHPStan level 8: **0 findings** at 1G memory; Pint check: **12 files pass**.

Wallet/Ledger/Payment fault injection used actual PostgreSQL triggers at the boundaries after Ledger commit, during Wallet capture evidence persistence, during Payment evidence persistence and during Payment outbox insert. Recovery reused the stable Ledger idempotency key; the one-journal/one-capture-record/outbox assertions passed. These were controlled SQL exceptions, not process termination. The Payment outbox is durable and replay was tested through P11, but a killed worker specifically after Payment commit and before first dispatch was not injected. Events consumer/inbox commit-before-ack recovery is not implemented because no authoritative P25 order exists.

No AD-08 operational account mapping or AD-09 external mapping was supplied; the implementation's test account configuration is explicitly test-only and production capture remains disabled. No independent Finance, Security, Privacy or Identity review evidence exists. P25 B-06 and T004 decisions remain outstanding; P18/B-11 affects only organiser payout T014. Existing AdminFxManagementTest remains 21 failures of 22 from absent platform.configuration_change_requests in the legacy local schema; no schema/migration-history repair was attempted. Full Payment/Ledger PHPStan baseline remains unclosed.

## Final verification result - 2026-10-28

After the repair-exhaustion fix and the new SC-06/SC-07 tests, the combined P08/P11/P25 regression command completed **80 passed, 0 failed, 0 skipped, 441 assertions**. Pint check: 14 changed-slice files pass. Targeted PHPStan on changed Wallet/Payments/Ledger source plus the new P08 feature tests: 0 errors with 1G memory. Real PostgreSQL Wallet capture/release/expiry races and P25 inventory/currency coordination races passed. `git diff --check` found no whitespace errors; it printed only repository CRLF normalization warnings. These results supersede the earlier 75-test scoped count in this record.

Final combined suite correction - 2026-10-28: after adding persisted Payment confirmation outbox dispatch retry coverage, the P08/P11/P25 combined regression is **81 passed, 0 failed, 0 skipped, 446 assertions**. `WalletCapturePaymentIntegrationTest::test_durable_confirmation_outbox_delivers_after_payment_commit_when_dispatch_is_retried` asserts the outbox row remains pending and no notification exists after Payment commit, then dispatches that row in a later worker invocation and asserts one delivered event/notification. This is restart/re-dispatch evidence, not a forced OS process termination. The remaining consumer commit-before-ack flow needs a P25 durable order/entitlement effect.

## Final engineering closeout - 2026-10-28

P08 SC-01-SC-07 developer walkthrough: PASS by mapped current automated scenarios on disposable PostgreSQL 15.19 using test-only mappings (81/0/0, 446 assertions; SC-04 repair scan separately 5/0/0, 8 assertions). This is not independent operator acceptance. `AdminFxManagementTest`: 22/22 passed, 118 assertions, on schema-correct disposable PostgreSQL; the older 21-failure record was the shared legacy schema missing `platform.configuration_change_requests`. One initial runner selection inherited that legacy DB setting; the test's DatabaseTransactions rolled back configured test writes, and no migration/reset ran. Full Payment/Ledger PHPStan rerun: 485 findings, same latest baseline; targeted changed-slice analysis 0. Exact commands/results in P08 verification-traceability.

T004 status correction: approved issuance invariant remains accepted. P08 development evidence mechanism is implemented; missing operational mappings do not mean otherwise. T010 still needs Events authoritative order/purchaser/amount/currency binding to `purposeReference`, an order-path currency lock, and durable Events deduplication/entitlement before acknowledgement. Late capture, refund failure/unknown, unused-ticket refunds and cancellation financial treatment remain undecided. B-06 owner answer form and arithmetic boundary examples are in `business-policy-decision-packet.md`.

P25 remains PARTIAL. Engineering acceptance: T008/T009 dev slices verified; credential vault primitive unit tested; no production order/ticket implementation. Business inputs outstanding: fee payer/schedule, seller entity, qualified tax determination and fee/tax calculation rules. Independent Finance/Security/Privacy review remains outstanding. Operational restrictions unchanged: no bootstrap execution, publication, checkout/sales activation, production access, deployment, P18 or P24.

## T010/T011 durable order and issuance checkpoint — 2026-10-28

Environment: isolated disposable PostgreSQL database `wallet_ecosystem_bootstrap_20261028`, PostgreSQL 15.19, on the previously authorized disposable Docker network. No shared development database was used.

- Migrations `2026_10_28_100012_create_event_order_ticket_tables.php` and `2026_10_28_100013_add_event_payment_inbox_conflicts.php`: applied successfully to the isolated database; 678 ms and 163 ms respectively.
- Focused P25 tests: `php artisan test tests/Feature/Modules/Events/EventDraftFoundationTest.php --filter='pricing_unavailable|order_locks|verified_capture_after|ticket_transaction_rolls_back|ticket_commit_before_ack|quote_requires_new_confirmation|order_bound_payment_evidence_mismatch' --no-ansi` — 7 passed, 0 failed, 0 skipped, 66 assertions.
- Full Events regression: `php artisan test tests/Feature/Modules/Events/EventDraftFoundationTest.php --no-ansi` — 54 passed, 0 failed, 0 skipped, 319 assertions. Xdebug coverage was unavailable; test results are unaffected.
- Real PostgreSQL order idempotency: `php tests/Concurrency/P25OrderIdempotencyPostgresTest.php` — PASS; two independent PHP sessions raced the same order idempotency key; exactly one order persisted with durable-order currency lock.
- Scoped PHPStan, 1G process-local memory: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress --error-format=table app/Modules/Events/Application/EventPaymentConfirmationConsumer.php app/Modules/Events/Application/EventOrderService.php app/Modules/Events/Infrastructure/PaidEventTicketPurposeResolver.php app/Modules/Payment/Application/PaymentOrchestrationService.php app/Modules/Payment/Domain/ValueObjects/EnrichedPayableObligation.php` — no errors. Existing full-module 485-finding baseline was not rerun or changed in this slice.

Pricing fixtures and P08 typed evidence in Events feature tests are test-only; they do not establish Finance approval, AD-08/AD-09 mapping, provider certification or live readiness. Late capture remains preserved and blocked for reconciliation without issuance/refund. Independent Security/Privacy/Finance review remains pending.
- Final changed-slice Pint: `vendor/bin/pint --test` over EventOrderService, EventPaymentConfirmationConsumer, resolver, Events provider, Payment orchestration/value object, the two T010 migrations, and the Events feature/concurrency tests — passed.
- Isolated migration status: ran `php artisan migrate:status` in the disposable Docker runner with explicit isolated DB variables — both T010 migrations show `Ran` (batches 2 and 3).
- Route registration: `php artisan route:list --path=v1/events` in the same isolated runner — 21 Events routes registered, including quote, order, payment-intent and purchaser order retrieval routes.


## T011 online admission verification — 2026-10-29

Environment: disposable isolated PostgreSQL database `wallet_ecosystem_bootstrap_20261028`, Docker network `p08-isolated-20261028_wallet_test`; no legacy development DB schema or migration history was modified.

- Focused admission feature filter: 8 passed, 0 failed; 107 assertions. Covered initial/repeated scans, permission/assignment denial, wrong gate, revoked ticket, purchaser ownership, aggregate privacy, unknown/pending/refunded outcomes, step-up replacement, audit rollback and independent per-ticket admission.
- Full Events regression: `php artisan test tests/Feature/Modules/Events --no-ansi` — 61 passed, 0 failed, 0 skipped; 408 assertions. Xdebug coverage driver unavailable (coverage only).
- Real PostgreSQL separate-session concurrency: `php tests/Concurrency/P25AdmissionConcurrencyPostgresTest.php` — PASS. Two worker sessions racing the same ticket yielded exactly one admission and one audit. Scan/revoke race serialized: the observed run revoked first and the scan was denied (403); invariant verified that admission occurred before revocation or was absent after it. Credential replacement/scan race serialized; replacement won and the old credential was denied.
- `vendor/bin/pint --test` over 10 changed PHP files — PASS.
- Customer Flutter scoped analyze — PASS, no issues (3 files). Agent Flutter scoped analyze — PASS, no issues (3 files).
- Admin scoped lint: `npm.cmd run lint -- --file src/modules/community/pages/event-authoring.tsx` — PASS, no warnings/errors. Initial `npm` PowerShell shim invocation was blocked by execution policy; reran through the project command shim successfully.

The concurrency harness uses explicit test fixtures for principal/permission/step-up and seeded issued tickets; actual database locks, assignments/revocation, tickets, attendance/audit and refund query execute against PostgreSQL. This is development verification, not production grants or independent Security/Privacy/Finance review. No production pricing, publication, live sales, bootstrap, deployment, P18 or P24 activation occurred.


- Scoped changed-code PHPStan: `vendor/bin/phpstan analyse --memory-limit=1G --no-progress --error-format=table app/Modules/Events/Application/EventAdmissionService.php app/Modules/Events/Infrastructure/Http/Controllers/EventAdmissionController.php app/Modules/Payment/Application/Services/PaymentRefundAdmissionEligibilityService.php` — PASS, no errors after refining the admission result type. PHPStan 1.12 emitted existing configuration/version deprecation notices only. A narrower final pass on `EventAdmissionService.php` also passed.
- Final style check after the type refinement: `vendor/bin/pint --test app/Modules/Events/Application/EventAdmissionService.php` — PASS, 1 file.


Verification correction (2026-10-29): after adding explicit cross-event and cross-organisation gate-ID denial assertions, the targeted gate admission feature test passed (1 passed, 27 assertions) and the complete Events suite passed **61 passed, 0 failed, 0 skipped, 424 assertions**. The previous 408 assertion count above predates those additional assertions. The changed test file also passes Pint. The separate-session PostgreSQL harness was rerun and passed as recorded above.

- Final PostgreSQL concurrency rerun after the service typing/refactor and cross-scope test additions: PASS; exactly one of two separate-session scans admitted the ticket, exactly one attendance/audit persisted; the observed assignment-revocation race denied the scan after revocation, and credential replacement serialized before scan so the old credential was denied.

## P25 engineering convergence verification — 2026-10-29

Environment: isolated disposable PostgreSQL 15.19 database `wallet_ecosystem_bootstrap_20261028` on `p08-isolated-20261028_wallet_test`. The additive migration `2026_10_28_100016_add_step_up_replay_fingerprint.php` was applied with `php artisan migrate --database=platform --path=app/Modules/Events/Infrastructure/Migrations/2026_10_28_100016_add_step_up_replay_fingerprint.php --force` and reported DONE. No legacy development database or migration history was targeted.

Verification actually executed:

- `vendor/bin/phpunit tests/Feature/Modules/Events/EventDraftFoundationTest.php --no-coverage`: **63 passed, 0 failed, 0 skipped; 453 assertions**. Covers feature/API boundaries, denied no-store route access, ticket ownership, status/category-only admission, refund blocking, credential replacement replay, post-admission replacement denial, and route absence for offline/re-entry/transfer.
- `vendor/bin/phpunit app/Modules/Payment/Tests/Unit/IdempotentSuccessReplayTest.php --no-coverage`: **4 passed, 0 failed, 0 skipped; 21 assertions**. New case verifies a succeeded Wallet attempt with `pending_ledger` re-enters idempotent capture/journal acknowledgement repair. Already posted and external attempts remain no-op.
- `php tests/Concurrency/P25AdmissionConcurrencyPostgresTest.php`: **PASS**, separate PostgreSQL worker sessions. Two concurrent scans produced exactly one admission and one attendance audit. A simultaneous scan/revoke test observed revocation win and scan 403. A simultaneous scan/replacement test observed replacement win and old credential denial. Explicit serialized orders verified scan→revoke keeps an admission committed before revocation, scan→replacement is denied with 409 after admission, and replacement→scan denies the old credential. Two concurrent replacements consumed one proof: one rotated, one 409. These outcomes establish the tested orderings, not every possible runtime schedule.
- `vendor/bin/phpunit tests/Feature/Modules/Payment/WalletCapturePaymentIntegrationTest.php --no-coverage`: **6 passed, 0 failed, 0 skipped; 63 assertions**. This is real Wallet/Ledger/Payment capture, journal, persistence/outbox and recovery coverage. It does not deliver a P25 order to the Events consumer.
- Identity `StepUpSecurityTest.php`: **11 passed, 0 failed, 65 assertions** for signature/audience, scoped token, actor/session binding and challenge behavior. Events adds per-ticket proof fingerprint replay protection. No independent Identity/Security approval is claimed.
- Customer Flutter ticket widget: **1 passed**; customer scoped `flutter analyze` after formatting: **no issues (2 items)**.
- Agent Flutter event gate widget: **2 passed** (offline fail-closed and online request body/response privacy); agent scoped `flutter analyze` is being rerun after the final test edit and result will be recorded below.
- Admin community accessibility Jest suite: **4 passed** including EventAuthoringPage. Scoped ESLint on the Events Admin page and test file exited **0**.
- Scoped PHPStan, 1 GB process-local memory, over changed Payment/Events implementation files: **no errors**. PHPStan emitted existing old-version/deprecated-config notices. Full-module baseline was not rerun or claimed clean.
- Pint initially found 2 style issues in changed Payment/Event tests; repository Pint fixed them. Final Pint result is recorded after rerun below.

The `WalletCapturePaymentIntegrationTest` first exposed a stale generic fixture labelled `paid_event_ticket` with a random, nonexistent order. The real Events consumer correctly rejected delivery for lack of configured Events service identity/order. That generic P08 test now uses purpose `bill`, so it continues to test P08 generic capture/outbox without claiming P25 fulfillment; rerun passed as shown above. This is not a substitute for E2E-01.

Credential/privacy checks: the customer interceptor logs path and sanitized headers only, never request body; AnalyticsService remains NoOp. Agent validation sends the credential in POST JSON; its UI test confirms the URL excludes it and only status/category are displayed. Attendance/audit tests confirm the credential is not in audit payloads. Purchaser ticket/order responses set private/no-store and are owner-filtered. These source and test checks do not replace independent Privacy review.

## E2E-01 result and gap

**NOT VERIFIED.** No single integrated PostgreSQL journey was executed from authorised P25 event workflow through real P08 Payment evidence/outbox delivery into the real Events consumer and acknowledgement, then purchaser credential access, real gate validation and aggregate report. Existing P25 issuance feature tests use test-only confirmation-contract evidence; they are component tests and are not counted as integrated Payment-to-Events evidence. Existing P08 Wallet capture tests use real Wallet/Ledger/Payment services but no authoritative P25 order and do not issue tickets. The missing acceptance artifact is specified as E2E-01 in `acceptance-matrix.md`.

No production pricing, publication, live sales, production bootstrap, deployment, P18 or P24 activation occurred. Test-only pricing, capture destination/account configuration, operational grants, and service actor fixtures remain test evidence only. Independent Security, Privacy and Finance review remains pending.

Final check addendum (2026-10-29): agent `flutter analyze lib/modules/events/presentation/screens/event_gate_scan_screen.dart test/modules/events/event_gate_scan_screen_test.dart` — **no issues**; rerun widget test after brace cleanup — **2 passed**. Customer scoped analysis — **no issues**; customer widget test — **1 passed**. Final `vendor/bin/pint --test` across the changed PHP implementation/tests — **10 files PASS**. Scoped PHPStan across changed Payment/Events implementation — **no errors**. Admin scoped ESLint exited 0; community accessibility Jest — **4 passed**.

Spec Kit workflow note: installed `speckit-implement` instructions were consulted. The repository prerequisite script and `.specify/feature.json` currently resolve to `specs/070-payment-history-detail` (P70), not P25. To avoid mutating another package, this convergence updated the existing P25 artifact directory and did not run a task-generation command against P70. P25 requirements checklist markers remain unchanged.

Final repository hygiene: `git diff --check` completed without whitespace errors. Git emitted existing/configured LF↔CRLF normalization warnings for modified worktree files; no source was normalized as part of this check.

## Integrated P25 development journey - E2E-01 - 2026-10-30

Result: PASS for the supported development Wallet flow. Database: explicitly isolated `wallet_ecosystem_bootstrap_20261028`, PostgreSQL 15.19, Docker network `p08-isolated-20261028_wallet_test`. The test asserts both `platform` and `community_services` resolve to `P25_DISPOSABLE_DATABASE` and require PostgreSQL before writes. The legacy development database was not used.

Executed command (repository root; API source bind-mounted into the container):

```powershell
docker run --rm --network p08-isolated-20261028_wallet_test `
  -v C:/Projects/OleKardEcosystem/wallet-ecosystem/services/api:/var/www/html `
  -w /var/www/html `
  -e DB_CONNECTION=platform -e DB_HOST=p08-isolated-20261028-postgres-1 `
  -e DB_PORT=5432 -e DB_DATABASE=wallet_ecosystem_bootstrap_20261028 `
  -e DB_USERNAME=wallet_app -e DB_PASSWORD=test -e APP_ENV=testing `
  -e AUDIT_HMAC_KEY=test-hmac-key-for-phpunit-only `
  -e P25_DISPOSABLE_DATABASE=wallet_ecosystem_bootstrap_20261028 `
  -e REDIS_HOST=p08-isolated-20261028-redis-1 -e REDIS_PORT=6379 `
  wallet-ecosystem-api:latest sh -lc `
  'vendor/bin/pint --test tests/Feature/Modules/Events/P25IntegratedJourneyTest.php && vendor/bin/phpunit tests/Feature/Modules/Events/P25IntegratedJourneyTest.php --no-coverage'
```

Pint PASS (1 file). PHPUnit: 1 passed, 0 failed, 0 skipped; 60 assertions. The same container/environment ran:

```sh
vendor/bin/phpunit app/Modules/Payment/Tests/Unit/IdempotentSuccessReplayTest.php --no-coverage
vendor/bin/phpunit tests/Feature/Modules/Payment/WalletCapturePaymentIntegrationTest.php --no-coverage
vendor/bin/phpunit tests/Feature/Modules/Events/EventDraftFoundationTest.php --no-coverage
```

Results respectively: 4/0/0 tests, 21 assertions; 6/0/0, 63 assertions; 63/0/0, 453 assertions. Combined focused scope: 74 passed, 0 failed, 0 skipped; 597 assertions. These are not the whole repository suite.

### Boundaries exercised

1. Created actors/organisation and a P25 event via the real Events draft service. The test fixture directly marks its version published because production publication readiness is denied. Bound explicitly test-only pricing (including nonzero fee/tax fixture), Payment provider configuration/account, Wallet capture destination/mapping, operator permissions/assignment, and Events service actor. Provider flags are restored and scoped fixture rows are cleaned up.
2. Created a real inventory hold, typed quote and durable Events order; asserted immutable order/purchaser/purpose binding and currency lock.
3. Created a real Payment intent with the Events purpose resolver. Initiated the supported Wallet path through PaymentOrchestrationService and WalletPaymentAdapter. Wallet committed capture, Ledger posted the debit journal, Payments verified posted journal and captured amount, then persisted accounting evidence and real payment.confirmed outbox record. Persisted-state checks asserted captured amount/currency, one Wallet capture, one payment idempotency journal and one outbox event.
4. Dispatched the durable confirmation through OutboxDispatcherJob/event bus and real Events consumer. A PostgreSQL trigger rejected the fulfilment-ack insert after Events committed inbox and two ticket credentials. Verified inbox processed/ack pending, two durable tickets, and failed outbox attempt. Removed the trigger and retried dispatch; verified one ack, delivered outbox, fulfilled Payment intent, unchanged ticket and journal counts. Redelivered the exact outbox event and verified no duplicate tickets, journal or ack.
5. Purchaser API returned two unique per-ticket credentials with private/no-store; a different customer could not access a ticket. An operator with registered permission and exact event/gate assignments admitted one ticket. Repeat scan returned already_admitted. Asserted durable attendance and one audit row with correct operator/gate, status/category-only validation response, aggregate category-only report, and no credential in audit or outbox payload.

Actual services exercised: Wallet, Ledger, Payment evidence verification/outbox, Events consumer/inbox/ack, purchaser API, gate authorization/admission and aggregate report. No provider/bank API was invoked or simulated. Test inputs stand in for Finance pricing authority, provider/capture operational configuration, actor authentication/assignment provisioning, paid publication, and service identity authorization. They are not Finance/Identity/Operations approvals or production grants. The commit-before-ack interruption is a PostgreSQL trigger exception at the actual Payment ack insert, not process termination. It verifies this retry path, not all crash schedules or external-provider paths.

### Integration fixes

- Payment retry for an already-succeeded Wallet attempt repairs a pending-ledger intent to succeeded before completing Wallet capture/Ledger acknowledgement, allowing real confirmation only after verified posting.
- Payment intent creation supplies its required stable reference derived from the generated intent ID.
- WalletPaymentAdapter implements the typed refund-status query as unresolved pending; it does not infer a refund outcome.
- Test gate role slug respects the Identity schema limit; fixture cleanup guards UUID predicates until rows exist.

E2E-01 closes the single-run integration gap for supported Wallet development. It does not verify AD-09 external payments, production pricing/fees/tax, paid publication, bootstrap, production grants, process-kill recovery, all concurrency schedules, refunds/late capture/cancellation/payout, or independent Security/Privacy/Finance acceptance. Those gates remain open; live processing, deployment, P18 and P24 were not enabled.

Workspace check for this checkpoint: `git diff --check` exited 0. Git reported existing/configured line-ending normalization warnings and a permission warning reading the user's global ignore file; no files were reset or committed. Current branch/HEAD and the extensive pre-existing worktree are recorded in `restart.md`.

## P25 readiness review and compatibility regressions - 2026-10-30

Decision status: the new `outstanding-decision-register.md` consolidates open B-06, late-capture, cancellation/refund, P18/B-11, and independent-review questions with owners by role, requirement impact, required evidence, consequences and closed gates. It marks previously approved product direction as settled and does not widen E2E-01 beyond the supported development Wallet path. It is an owner-facing draft and has not been sent.

Coverage review and focused additions:
- Payment success retry is covered by `IdempotentSuccessReplayTest` (4 tests / 21 assertions) and the integrated E2E path, which verifies persisted succeeded intent, posted accounting, outbox, tickets and acknowledgement after a real Wallet attempt.
- Payment intent reference had no persisted-value assertion. `P25IntegratedJourneyTest` now asserts the generated reference stored on the Payment intent is the stable UUID-derived value. The integrated test passes with 61 assertions.
- Wallet refund-status compatibility had no direct test. `WalletPaymentAdapterTest` now asserts the query returns `pending`, with no provider reference and `olekard_wallet`, rather than an invented terminal status.
- Two existing adapter retry tests expected `createHold()` to be called once, contrary to the WalletModuleContract same-transaction-reference idempotency contract. Their assertions now require two same-reference calls returning the same hold. No production behavior was changed for this adjustment.

Latest commands, executed in the named disposable PostgreSQL container/environment:

```sh
vendor/bin/pint --test tests/Feature/Modules/Events/P25IntegratedJourneyTest.php tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php
vendor/bin/phpunit tests/Feature/Modules/Events/P25IntegratedJourneyTest.php --no-coverage
vendor/bin/phpunit app/Modules/Payment/Tests/Unit/IdempotentSuccessReplayTest.php --no-coverage
vendor/bin/phpunit tests/Unit/Modules/Payment/Adapters/WalletPaymentAdapterTest.php --no-coverage
vendor/bin/phpunit tests/Feature/Modules/Payment/WalletCapturePaymentIntegrationTest.php --no-coverage
```

Latest result: Pint **PASS, 2 files**; PHPUnit respectively **1/0/0 tests, 61 assertions; 4/0/0, 21; 27/0/0, 81; 6/0/0, 63**. Total latest focused execution: **38 passed, 0 failed, 0 skipped; 226 assertions**. The first adapter-suite attempt before correcting the two stale unit expectations failed those two tests (27 tests, 2 failures); the corrected rerun passed all 27. This was a test-expectation mismatch, not evidence of a duplicate Wallet hold: the actual integration and Wallet contract use same-reference idempotency.

Scope limits remain: no Flutter/browser interaction was exercised by E2E-01; no worker process was terminated; no AD-09 external provider was simulated; no production readiness or independent review was established. Broader P25 Events suite 63/453 was recorded in the prior checkpoint, not rerun in this compatibility-focused execution.

## Accepted-policy pricing implementation verification - 2026-10-30

Scope: P25 T030 only; project-owner accepted direction is not independent Finance/Tax/Legal, Security, Privacy or regulatory approval. Branch main, HEAD at task start c091fe8f42580b1654348d90490918d9aad66d23. Existing worktree changes were preserved. Feature runs used only disposable PostgreSQL 15.19 database wallet_ecosystem_bootstrap_20261028 on Docker network p08-isolated-20261028_wallet_test. No legacy development database, migration history, production config or live grants were modified.

Implementation: EventPricingBreakdown now stores the organiser platform fee and its versioned/effective/currency/scope/calculation snapshot separately from customer-payable fees and total; production validation rejects a separate customer-payable booking fee absent a separately approved policy, missing or mismatched fee/tax snapshot, missing seller verification, and floating-point values. EventOrderService checks fee schedule currency, event/organisation scope and current effective period. The immutable JSON quote/order snapshot preserves schedule and tax versions/evidence. Existing test-only pricing remains explicitly test-only and bypasses the production-only contract checks.

Final checks:
- Pint formatting/check: PASS, 4 PHP files.
- EventPricingBreakdownTest: PASS, 4 tests, 9 assertions.
- EventDraftFoundationTest: PASS, 64 tests, 0 failures, 0 skipped, 458 assertions. Includes historical order snapshot immutability after switching test-only fee/tax versions and amounts, production pricing denial, late-capture containment, and order/ticket regressions.
- P25IntegratedJourneyTest: PASS, 1 test, 0 failures, 0 skipped, 61 assertions. E2E-01 remains limited to the documented test-only development Wallet path.
- Combined executed final test count: 69 passed, 0 failed, 0 skipped, 528 assertions. No PHPUnit warnings in the final run.

Reproducible command environment: docker run --rm --network p08-isolated-20261028_wallet_test -v C:/Projects/OleKardEcosystem/wallet-ecosystem/services/api:/var/www/html -w /var/www/html -e DB_CONNECTION=platform -e DB_HOST=p08-isolated-20261028-postgres-1 -e DB_PORT=5432 -e DB_DATABASE=wallet_ecosystem_bootstrap_20261028 -e DB_USERNAME=wallet_app -e DB_PASSWORD=test -e APP_ENV=testing -e AUDIT_HMAC_KEY=test-hmac-key-for-phpunit-only -e P25_DISPOSABLE_DATABASE=wallet_ecosystem_bootstrap_20261028 -e REDIS_HOST=p08-isolated-20261028-redis-1 -e REDIS_PORT=6379 wallet-ecosystem-api:latest sh -lc '<pint --test for the four files>; vendor/bin/phpunit tests/Unit/Modules/Events/EventPricingBreakdownTest.php --no-coverage; vendor/bin/phpunit tests/Feature/Modules/Events/EventDraftFoundationTest.php --no-coverage; vendor/bin/phpunit tests/Feature/Modules/Events/P25IntegratedJourneyTest.php --no-coverage'.

The first EventDraftFoundationTest attempt before correction had 15 errors because the new synthetic pricing fixture did not capture four test parameters in its anonymous class. The fixture was corrected; the final focused run above is green. No application behavior was altered to accommodate the fixture.

PHPStan for EventOrderService.php and EventPricingBreakdown.php was started with level/config from phpstan.neon and a 1G process memory limit; its final result is recorded below after completion. No full-module baseline claim is made.

Limits: synthetic values validate DTO shape only and are not real fees, rates, seller evidence, tax policy, Finance approval or production configuration. This work does not execute organiser proceeds deductions, Ledger postings, refunds, cancellation, late-capture recovery, settlement, payout or external-provider processing. P25 remains PARTIAL and production pricing/publication/checkout/sales remain disabled.

Verification count correction - 2026-10-30: The final rerun after adding the non-test wrong-event fee-scope guard test is Pint PASS (4 files); EventPricingBreakdownTest 4/0/0, 9 assertions; EventDraftFoundationTest 65/0/0, 461 assertions; P25IntegratedJourneyTest 1/0/0, 61 assertions. Combined final execution: 70 passed, 0 failed, 0 skipped, 531 assertions. This supersedes the earlier 64-test / 458-assertion feature count in this section.

PHPStan limitation: scoped command `vendor/bin/phpstan analyse app/Modules/Events/Domain/ValueObjects/EventPricingBreakdown.php app/Modules/Events/Application/EventOrderService.php --no-progress --memory-limit=1G` emitted PHPStan 1.12/deprecated-configuration notices and Laravel permission-registration logs but no analysis result after 5 minutes 35 seconds. It was interrupted; exit code 1 reflects the interruption, not an analysis finding. No PHPStan pass/finding count is claimed. Pint and execution of the changed PHP code passed.

Final verification correction - 2026-10-30: after extending the historical snapshot test to assert quote immutability and idempotent quote retry, reran the complete focused set in the disposable PostgreSQL environment. Pint passed for 4 files. EventPricingBreakdownTest passed 4/0/0 tests with 9 assertions; EventDraftFoundationTest passed 65/0/0 with 463 assertions; P25IntegratedJourneyTest passed 1/0/0 with 61 assertions. Combined: 70 passed, 0 failed, 0 skipped, 533 assertions. This supersedes the prior 531-assertion count. Environment and E2E-01 limits above are unchanged. Scoped PHPStan remains interrupted with no result.

## T030 static-analysis close and cancellation readiness - 2026-10-30

Repository PHPStan configuration is `wallet-ecosystem/services/api/phpstan.neon`: PHPStan 1.12/Larastan, level 8, app paths. Previous no-progress execution gave only Laravel permission registration logs and was stopped at 5m35 with no result; that was not a static-analysis pass. The explicit `--debug` run confirmed slow application/bootstrap analysis rather than a reported code failure and completed normally. It emitted the existing PHPStan deprecation notice for `checkMissingIterableValueType`, then analyzed the four scoped application files and returned `[OK] No errors` (exit 0). No rule, ignore or baseline was changed.

Exact command in the disposable API container:

```sh
vendor/bin/phpstan analyse --configuration=phpstan.neon --debug app/Modules/Events/Domain/ValueObjects/EventPricingBreakdown.php app/Modules/Events/Application/EventOrderService.php app/Modules/Events/Domain/Contracts/EventPricingContract.php app/Modules/Events/Domain/ValueObjects/EventPricingRequest.php --memory-limit=1G --error-format=table
```

Additional pricing verification after extending the test fixtures: Pint `--test` passed 2 files; EventPricingBreakdownTest passed 6 tests / 13 assertions; the full EventDraftFoundationTest passed 66 tests / 469 assertions. It covers explicit denial for unapproved pricing, unverified seller, schedule currency mismatch and future fee/tax effective windows; the complete feature suite still covers wrong event scope, missing production pricing, no quote/order/payment intent on deny, synthetic organiser fee separation, no unapproved customer fee, decimal-float rejection, changed-component reconfirmation, and immutable stored quote/order snapshots after test policy updates. The fixtures use no production schedule or tax values. P25IntegratedJourneyTest was not rerun in this verification slice; its prior E2E-01 evidence remains limited to the supported backend development Wallet path.

Cancellation inspection found a shared-database P11 `publishOnConnection` facility and an idempotent Notification service whose actual delivery is asynchronous, but no Events cancellation transition/routes, purchaser cancellation status, cancellation-specific notification template/listener, or Payment refund execution/status/recovery contract. Existing event cancellation-policy text and `CANCEL_REQUEST`/`CANCEL_APPROVE` permission constants are not enforcement. No cancellation code was enabled because the approved cancellation outcome must also invalidate tickets and initiate financially correct recovery, which cannot be done before CANCEL-01 and the shared refund contract. Exact readiness and next action are in `outstanding-decision-register.md`.

Exact PHPUnit/Pint commands for the final T030 verification slice (executed in the documented disposable API/PostgreSQL container):

```sh
vendor/bin/pint --test tests/Unit/Modules/Events/EventPricingBreakdownTest.php tests/Feature/Modules/Events/EventDraftFoundationTest.php
vendor/bin/phpunit tests/Unit/Modules/Events/EventPricingBreakdownTest.php --no-coverage
vendor/bin/phpunit tests/Feature/Modules/Events/EventDraftFoundationTest.php --filter=non_test_pricing_outside_fee_or_tax_effective_window --no-coverage
vendor/bin/phpunit tests/Feature/Modules/Events/EventDraftFoundationTest.php --no-coverage
```

Results: Pint PASS (2 files); unit 6 passed / 13 assertions; new effective-window case 1 passed / 6 assertions (also included in the full feature run); full feature suite 66 passed / 469 assertions. All final executed tests passed; no skipped tests.

Final count/scope correction - 2026-10-30: the latest complete T030 test run in this slice totals 72 passed / 0 failed / 0 skipped / 482 assertions (EventPricingBreakdownTest 6/13; EventDraftFoundationTest 66/469). P25IntegratedJourneyTest was not rerun and is not included. The scoped PHPStan command above completed with exit 0 and no errors, superseding earlier historical notes that PHPStan was interrupted. E2E-01 evidence remains separately limited to the previously documented development Wallet backend journey.

Expanded effective-window coverage correction - 2026-10-30: fee and tax tests now cover both future `effective_from` and past `effective_until`. Final focused reruns: effective-window case 1 passed / 12 assertions; EventPricingBreakdownTest 6 / 13; full EventDraftFoundationTest 66 / 475; Pint 2 files passed. Unique test total for this slice: 72 passed / 0 failed / 0 skipped / 488 assertions (the focused effective-window rerun is included in the full suite and must not be double-counted). PHPStan level 8 remains PASS as documented.

## 2026-11-04 project-owner terms-transition decision

The Product Owner confirmed this rule for development: when a newly approved OleKard terms version becomes applicable, the organiser must explicitly reaccept before affected paid-event capabilities continue. Existing orders retain the immutable pricing/terms snapshot applied when created; a later terms version does not rewrite historical orders. This is project-owner direction only and does not approve terms content, fee/tax/refund policy, a Finance/legal publisher, organiser-authority source or production use.

Current implementation requires a complete current-version/hash-bound acceptance snapshot in production pricing and validates organisation/event scope and effective dates before order creation; the accepted evidence is carried into immutable order pricing snapshots. P26 T028 now supplies a Platform terms-version registry and protected API lifecycle, plus typed approval and representative evidence boundaries. The production pricing contract and both evidence providers remain deny-by-default. Finance/Governance must supply approved values, effective dates and role assignments, an authoritative approval verifier, and Identity/onboarding must supply current actor-bound organiser evidence. No dedicated Admin/organiser UI was added. Missing or mismatched acceptance blocks affected paid capability.

P26 T028 final shared-boundary regression (2026-11-04): guarded isolated PostgreSQL run wallet_ecosystem_p26_e2e_20261104_19d56b05 passed Events 87/663, including terms registry acceptance and the Platform advisory-lock conflict test; separate-session P26 race harness passed. Quotes, order confirmation and payment-intent creation now serialize against terms publication through the Platform guard, while keeping separate transaction ownership. This is development verification, not Finance approval or production pricing readiness. PHPStan for the changed terms slice timed out and remains unverified. See P26 evidence.md.
P26 T028 final verification update (2026-11-04): repository-configured scoped PHPStan completed with zero errors for the changed terms/Events production slice after an initial timeout was investigated and two Identity event-listener typing findings were corrected. Final guarded Events regression passed 87 tests/665 assertions; the shared Platform publication guard is acquired by Events quote, order-confirmation and payment-intent paths. A separate PostgreSQL session verifies conflicting lock behavior. Platform and Events remain separate transaction owners; no global atomicity or cross-request crash-race claim is made. Details are in P26 evidence.md. Approved production terms/providers/role assignments remain outstanding.

P26 terms guard follow-up (2026-11-04): added isolated PostgreSQL exception-path coverage proving the Platform session advisory lock is released when an Events guarded callback throws. Run `wallet_ecosystem_p26_e2e_20261104_f3692812`: terms 7/30, CORS 3/52, Identity MFA 10/70, unit contracts 11/29, P26 separate-session race harness passed; locked Pint passed the new test. This supplements, but does not extend, the shared lock guarantee to global transaction atomicity or process-termination evidence. P25 paid activation remains gated on approved terms and specialist contracts.
