# P25 Source → Requirement → Story → Task → Evidence

| Source | Requirement | Story / task | Code / test status |
|---|---|---|---|
| §2 ownership; LC07 1 | FR-001/002/010 | US1 / T005–T008, T017–T020 | Draft-only Events service/schema exists, defaults to deny, has no route/publication/UI. T020 validates timezone-safe timestamps, sales deadline, positive proposed order limit and Finance-owned currency boundary. Publication and approved authority remain blocked. |
| LC07 2–4; FD02/FD07; E48 payment API/events | FR-003/004/010 | US2 / T004, T009–T010 | Shared Payment intent/quote and durable ledger-confirmed event exist; no P25 inventory/order/resolver/fulfilment exists. T009–T010 are not implemented and depend on published state, fee/tax decision, and P25 order identity (B-03–B-07). |
| LC07 5–6 | FR-005/008 | US3 / T005, T011–T012 | Scanner must return status/category only and fail closed offline; these choices are specified. Gate authority/credential rules remain open (B-01/B-09); endpoint, ticket/scan records and concurrency proof are not implemented (B-08/B-13). |
| LC07 7 and exceptions; FD10/LC13/LC14 | FR-006/007 | US4 / T004, T006, T013–T014 | FD10/LC14 high-level flow is in verified architecture v1.1; no P25 closure/payout implementation. P18 execution/settlement are explicit stubs and AD-14/AD-10/detailed LC14 journal mapping remain open (B-10/B-11). |
| LC07 completion evidence; constitution | FR-008/009 | All / T015–T016 | No end-to-end verification, release or certification claimed. |

Actual inspected files and predecessor gaps are in `research.md`; workflow and test evidence in `evidence.md`.

Continuation: LC07 draft event authoring, categories, organiser/sponsor and policy text → FR-001/002 → US1/T017–T018 → `app/Modules/Events/Application/EventDraftService.php`, `EventDraftAuthorizer`, deny binding and `2026_10_27_100001_create_event_drafts.php` → `tests/Feature/Modules/Events/EventDraftFoundationTest.php` (local tests). The default binding denies every caller and no route, approval or publication exists; approved access remains T005/T008. The table's original Events-module statement records the pre-implementation inspection.

T020 continuation: FR-002/003/004/010 → US1 acceptance 1–6 → `EventDraftService::fields()` plus migration `2026_10_27_100002_add_draft_order_limit_and_optional_currency.php` → `EventDraftFoundationTest` timestamp/order-limit/currency-boundary cases. Currency is nullable in draft and organizer-supplied currency is discarded; this is not Finance assignment or paid-event readiness. See `evidence.md` for migration and test output. Current blocker ledger D-01–D-09, C-01–C-07 and B-01–B-14 is in `research.md`.

The draft test additionally verifies that caller-supplied organisation, creator and version fields cannot override the authenticated service context. This is scoped draft evidence, not purchase, admission or finance acceptance.

2026-10-28 implementation trace: LC07 / FR-002 / US1 / T008,T021 ? `EventWorkflowService.php`, `EventWorkflowAuthorizer.php`, `EventPublicationReadiness.php`, deny-by-default implementations and `2026_10_28_100003_add_event_review_workflow.php` ? `EventDraftFoundationTest.php` (publication requires independent event/price review and current version; production default denies; snapshot preserved and sales paused after edits). Migration ran locally. Accessible API/admin/mobile publication remains blocked by B-01/B-03; T015 is not implemented. No inventory, payment, issuance or admission claims.

## Current review/publication implementation status

The statement above predates T021. Review/publication state transitions are now implemented internally behind deny-by-default authorizer and readiness contracts; the old statement that these transitions are absent is historical. No endpoints/UI are registered. See current task matrix and 2026-10-28 traceability addendum.

2026-10-28 B-01 source-to-matrix trace: �2/LC07; D-01�D-09; C-01�C-07; approved E04 Identity global RBAC and organisation membership contracts ? B-01 / FR-002, FR-005, FR-006, FR-008 ? T022 ? `authorization-matrix.md`. Existing `PermissionResolverService`, `RequirePermissionMiddleware`, `AuthorizationGuard`, `OrganisationMembershipAuthority`, `ReviewedOrganisationMembershipService`, and `EventWorkflowService` are cited there. All proposed P25 permission slugs remain unregistered; no role grants were added. B-01 remains blocked pending the focused approvals and Identity/Security contract.

2026-10-28 decision/code trace: A-02 / FR-002 / T024 ? `2026_10_28_100004_create_event_draft_version_contributors.php`, `EventDraftService::recordVersionContributors`, `EventWorkflowService::requireNonContributor` and distinct reviewer checks ? `EventDraftFoundationTest::all_version_contributors_are_excluded_and_approvers_must_be_distinct` and `finance_price_approval_can_follow_event_review_when_approvers_are_distinct`. Local migration applied; tests verify historical contributors carry forward to each version and approvals remain version-scoped.

A-01/A-03 trace: D-07 / B-01 / T022-T023 ? `authorization-matrix.md` and `authorization-dependency-proposal.md`. Existing Identity `hasPermission` remains global; no Events permission slug is registered and no grant is issued. The proposal recommends Events-owned scope state and identifies conditional E04 authorization if Identity owners require generic scoped grants. B-01 remains BLOCKED.

A-04 trace: FR-008 / T015-T016 ? aggregate-only organiser report direction recorded in matrix/spec; no reporting endpoint exists, no individual attendee or scan data exposed. Independent Privacy review remains required.

2026-10-28 scoped authorization trace: A-01/A-03, accepted development permission mapping / FR-002, FR-005 / T005,T008,T011,T022,T025,T026 -> `Domain/Permissions/EventPermissions.php`, `Application/ScopedEventAuthorization.php`, scoped draft/workflow authorizers, updated service contracts and provider binding, migration `2026_10_28_100005_add_scoped_event_permissions_and_assignments.php` -> `EventDraftFoundationTest.php` (permission+assignment both required, org creation scope, cross-event/tenant/gate denial, live revocation, delegation boundary, ungranted catalogue). Migration creates no role grants. Gate lock must be used inside caller's scan transaction; no gate endpoint or concurrent two-session proof exists.

Ticket ownership/refund and aggregate report trace: A-04/FR-008 / T010,T013,T015,T016 -> no implementation yet. Ticket/order ownership and approved refund-eligibility contracts are absent; Events cannot perform refunds. No report endpoint exists; aggregate-only remains the required output constraint. These are not counted as test passes.

## Assignment authority and gate write-path trace — 2026-10-28

| Requirement / decision | Task | Code / migration / endpoint | Verification / status |
|---|---|---|---|
| A-01/A-03 development permission + exact resource grant; explicit org and separate Finance assignment authorities; no auto role grants | T027 | `EventPermissions::management()`, `EventAssignmentManagementService`, migration `2026_10_28_100006_add_assignment_administrator_authority.php`; `POST /v1/events/organisations/{organisationId}/assignments`, `POST /v1/events/{eventId}/assignments`, `DELETE /v1/events/assignments/{assignmentId}` | `EventDraftFoundationTest` permission/authority separation, retry/revoke/audit; permission catalog has no role bindings. Product decision is not independent review. |
| A-02/A-03 no self assignment/escalation, two distinct bootstrap approvers, immediate authority revocation without cascading grants | T027 | `delegateAdministrator`, `revokeAdministrator`, `bootstrapAdministrator`; command `events:bootstrap-assignment-authority`; migrations 100006 | Tests deny self/duplicate approvers and verify prior grant remains active with issuer provenance. Command not executed; approval reference is opaque and operational evidence remains outstanding. |
| Gate manager may configure gates and delegate only validation within exact event/gate | T029 | `ScopedEventAuthorization::lockEventAssignment`, `EventAssignmentManagementService::{createGate,grantGateValidation,revokeAssignment}`, migration `2026_10_28_100007_create_event_gate_registry.php`; `POST /v1/events/{eventId}/gates`, `POST /v1/events/gates/{gateId}/validation-assignments` | Tests exact-event denial, gate grant/revoke and self-denial. No scan/admission endpoint or real concurrent scan test. |
| B-03 currency/payment readiness; B-11 payout/settlement; independent Security/Finance/Privacy review | T007/T008/T014/T016 | Separate boundaries unchanged | NOT VERIFIED/BLOCKED; no claim of approval or live activation. |

Focused result for this update: `EventDraftFoundationTest.php` 26 passed / 0 failed / 0 skipped / 120 assertions. Local migrations 100006 and 100007 applied on the development platform DB. See `evidence.md` for the intermediate fixture failure and test limits.
Final verification correction — 2026-10-28: the latest focused run after gate listing and cross-event revoke coverage is **26 passed / 0 failed / 0 skipped / 123 assertions**. Scoped Pint passed and PHPStan on `app/Modules/Events` reports 0 errors. Migrations 100006/100007 are local batch 14. Xdebug and old/deprecated PHPStan notices are environmental/tooling notices. No production grants or bootstrap were performed.
Final count correction — after bootstrap idempotency and permission-catalogue assertions, final focused result is **27 passed / 0 failed / 0 skipped / 136 assertions**. Pint and scoped PHPStan passed. This replaces earlier counts in this addendum.
Migration batch correction: migration `2026_10_28_100006_add_assignment_administrator_authority.php` is batch 14; gate registry migration `2026_10_28_100007_create_event_gate_registry.php` is batch 15 on the local development database. Both report Ran. No production database was migrated.

Final authoritative count/status: 27 passed / 0 failed / 0 skipped / 136 assertions; scoped Pint and PHPStan passed. Migration 100006 ran in local batch 14; 100007 ran in local batch 15. Earlier 26/120 and 26/123 entries are intermediate snapshots and are superseded by this result.
## Bootstrap fail-closed and authoring/review API trace � 2026-10-28

| Requirement | Task | Code / surface | Evidence/status |
|---|---|---|---|
| Bootstrap must validate durable governance evidence, not trust a supplied reference string | T027/T007 | `BootstrapApprovalEvidenceVerifier`, production binding `DenyBootstrapApprovalEvidenceVerifier`, `EventAssignmentManagementService::bootstrapAdministrator`; `events:bootstrap-assignment-authority` | New regression verifies a nonempty reference with otherwise valid fixture permissions/membership yields 403 and no administrator/audit rows. Positive verifier is test-only. Operational execution remains blocked. |
| Authoring and review need scoped authenticated API/admin interface | T008/T015 | `EventAuthoringController`; `POST /v1/events/organisations/{organisationId}/drafts`, `GET/PUT /v1/events/{eventId}`, review/price-approval/reject routes; admin `/community/events` | Development route/UI. No publish, resume-sales, checkout, scan, refund or payout endpoint was added. Scoped API authorization and UI type checks remain to be run. |
| B-03 paid publication readiness | T008�T010 | `EventPublicationReadiness` remains bound to `DenyEventPublicationReadiness`; finance-owned currency remains null | Blocked pending Finance/MultiCurrency/Payments contract; no paid publication or sales route. |
| D-02 return maximum and admission | T005/T011 | Return-only semantics recorded; no admission persistence/API/UI | Numeric maximum/configuration rule remains a business decision; no re-entry enabled. |
| B-11 organiser payout | T006/T014/T016 | P18 `PayoutExecutionService`, query job and settlement service remain stubs; execute/confirm routes return 501 | P25 depends on P18 AD-14, AD-10, LC14 and T049/T050/T051/T055/T056 plus relevant verification. |
| Independent Identity/Security review | T007 | `identity-security-review-index.md` | Prepared, not approved. No named reviewer/appointment record found. |

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

### B-03 implementation evidence — 2026-10-28

Source decisions: current P25 accepted B-03 development direction; prior approved Events currency boundary and A-01/A-02 scoped authorization decisions. Code/test links:

- Finance currency permission and assignment: `app/Modules/Events/Domain/Permissions/EventPermissions.php`; `app/Modules/Events/Application/EventAssignmentManagementService.php`; `app/Modules/Events/Application/EventCurrencyAssignmentService.php`; `app/Modules/Events/Infrastructure/Http/Controllers/EventCurrencyController.php`; `routes/modules/events.php`; migrations `2026_10_28_100009_add_event_currency_assignment.php` and `...100010_register_event_currency_permission.php`.
- First-obligation locking and currency drift: `EventCurrencyAssignmentService::lockForFirstObligation`; `EventInventoryHoldService::createHold`; `EventDraftService::fields` preserves Finance currency during editor updates. Test evidence: `EventDraftFoundationTest::test_first_hold_locks_finance_currency_precision_and_expiry_does_not_unlock_it`, `...test_event_editor_update_preserves_finance_assigned_currency_snapshot`, `...test_currency_assignment_requires_finance_permission_and_exact_event_assignment`, and `...test_precision_change_blocks_hold_and_persists_operator_review_issue`.
- Actor-free static eligibility: `Payment/Domain/ValueObjects/StaticEligibilityContext.php`, `EventStaticReadinessQuery.php`, `Payment/Application/Services/EligibilityPipeline.php`, `EventStaticPaymentReadiness.php`; test `EligibilityPipelineTest::test_actor_free_static_context_uses_the_same_configured_eligibility_rules`.
- Real concurrency: `tests/Concurrency/P25InventoryHoldPostgresTest.php` and worker. Distinct PostgreSQL backend PIDs; last-capacity/first-currency-lock, same idempotency replay, expiry/release and rollback/recovery scenarios.

Verification commands/results for the final rerun will be appended after execution. Failure of general migrations on duplicate legacy identity.users is recorded separately from successful targeted P25 migration application.

## B-03 source-to-test traceability continuation — 2026-10-28

| Requirement | Implementation | Verification / evidence | Status |
|---|---|---|---|
| Finance-authorized event currency with provenance and precision | EventPermissions `CURRENCY_ASSIGN`; `EventAssignmentManagementService` Finance authority classification; `EventCurrencyAssignmentService`; `EventCurrencyController`; routes; migrations 100009/100010. | Feature test denies permission-only and assignment-only, accepts both only with active membership and fixture grant; asserts snapshot fields. | Implemented for development; independent review and production authority pending. |
| Lock currency/precision on first hold, never unlock on expiry | `EventCurrencyAssignmentService::lockForFirstObligation`; called inside `EventInventoryHoldService` transaction after capacity/sales checks; event row locked. | Feature lock persists through expiration; separate-session PG last-capacity race verifies one persisted first-hold lock. | Hold path implemented/verified; durable-order call is unavailable until T010 order flow. |
| Currency drift fails new holds and creates operator issue without altering existing money | `CurrencyReviewRequired`; issue persisted after hold rollback in `EventCurrencyAssignmentService::recordReviewIssue`. | Feature test changes current precision in transaction and asserts HTTP 409, no hold, persisted `CURRENCY_PRECISION_CHANGED` issue. | Implemented/verified for hold; readiness/publication/resumption/new checkout use remains blocked. |
| Actor-free category static readiness and wallet-only label | `StaticEligibilityContext`, `EventStaticReadinessQuery`, `EligibilityPipeline`, `EventStaticPaymentReadiness`. | Payment unit verifies eligibility context has no actor and applies existing static checks. | Reusable service implemented; no production Events binding until canonical IDs/country source are approved. |
| Every-category coverage; customer checkout independent | One typed context per category price; result exposes category coverage and configuration; current `OptionsQuery` remains customer actor-specific. | Current unit test covers actor-free pipeline only; no end-to-end repository/result test or customer checkout integration exists. | Partial; end-to-end readiness and checkout blocked by canonical context/T010/B-06. |
| Fees, taxes, discount totals excluded | No fee/tax logic added to static evaluator. | Scope documented in B-03 proposal/research. | B-06 decision and Finance/Legal approval remain open. |
| Publication and sales resumption re-evaluate exact approved snapshot | `EventWorkflowService` calls readiness at publish and resume; production binding is still `DenyEventPublicationReadiness`. | Existing Events route/workflow tests and scoped suite; no public publish/resume endpoint or positive production readiness implementation. | Deny-by-default; unavailable by design pending contracts/review. |

Final B-03 verification correction: precision drift also transitions existing event availability to `paused` within the persisted review issue/audit transaction after the attempted hold rolls back. Feature test asserts this state. Final tests: Events 40/211; Payment actor-free eligibility 21/35; PostgreSQL concurrency harness four scenarios pass with distinct sessions; scoped PHPStan and Pint pass.

Latest test count correction: Events 40/213; Payment eligibility 21/35; no failures/skips. Added locked-currency reassignment denial assertion.

## Currency coordination traceability — 2026-10-28

| Requirement | Implementation | Verification | Status |
|---|---|---|---|
| CTX-01 identifiers and resolver ownership | PaymentContextIdentifiers; BusinessPurposeResolverRegistry; no Events resolver binding | Registry collision and EligibilityPipeline units | Verified for development; resolver absent until authoritative order exists |
| CTX-02 event-country source and scope | EventVenueCountryCode; EventDraftService; EventPaymentReadinessQueryFactory; migration 100011 | Events country/context feature cases | Verified for development; no publication binding |
| MC-01 shared currency status/precision coordination | CurrencyDefinitionCoordinator/Guard; FxRateAdminService; migration 001800 trigger; Events assignment and hold services | Separate-session P25CurrencyCoordinationPostgresTest plus inventory concurrency harness | Local PostgreSQL races verified; extracted/cross-database deployment unsupported |
| FIN-01 interim drift containment | CurrencyReviewRequired; durable issue/pause after hold rollback | Inactive/precision drift feature cases and currency race | New holds denied, issue durable, active sales paused; existing operation disposition unresolved |
| First durable order lock | lockForFirstObligation(... durable_order); no order path exists | Mandatory T010 acceptance criterion | NOT IMPLEMENTED; no order coverage claimed |

All reviews remain pending. See evidence.md for commands, counts and limitations.

P25 final business-policy decision packet is at specs/072-paid-events-ticketing/business-policy-decision-packet.md. It separates recommendations from settled decisions, finance/tax/provider evidence and independent review. It maps T004 to actual Payments/Ledger contracts and records AdminFx writer-path verification as blocked by the missing pending platform change-request migration; see P25 evidence.md. No implementation, migration repair or activation occurred in this packet-preparation step.

# Order/ticket product approvals and credential primitive — 2026-10-28

| Requirement / decision | Task | Implementation / evidence | Boundary |
|---|---|---|---|
| Opaque random credential, >=128-bit entropy, no PII/payment payload; hash for lookup and same secret recoverable for authenticated display | T011 | `wallet-ecosystem/services/api/app/Modules/Events/Application/AdmissionCredentialVault.php`; `tests/Unit/Modules/Events/AdmissionCredentialVaultTest.php` (2 tests, 9 assertions) | Primitive only. No entitlement table, order, issuance caller, retrieval API or scanner is implemented. |
| Distinct credential per admission entitlement; ownership tied to authenticated purchaser/order; no named-attendee checks | T010/T011 | Approved product direction recorded in `tasks.md` and `research.md` | Persistence and ownership enforcement await authoritative order model and Payments/Ledger contract. |
| Idempotent durable issuance only after payment/order/org/payer/currency/amount/journal evidence; no redirect/callback/succeeded-only/unknown issuance | T004/T010 | Approved invariant recorded in `tasks.md`; actual shared event/status limitations and required contract are described in `research.md` and `business-policy-decision-packet.md` | No resolver or issuance integration; owning Payments/Ledger change and acceptance tests remain required. |
| Confirmation evidence retained and quote re-confirmed after any payable component/total change; 300-second quote TTL; expired hold cannot be authorized | T010 | Product direction recorded in `tasks.md` and `research.md` | Quote/order storage and checkout remain blocked by fee/tax and shared payment contracts. |
| Replacement requires approved Identity step-up/recovery and atomic old-credential revocation | T011 | Decision recorded in `tasks.md` and `research.md`; existing `ActorStepUpVerifierContract` identified | No ticket persistence/replacement route; integration and independent review remain pending. |

# Payments/Ledger bounded dependency trace — 2026-10-28

| P25 acceptance criterion | Owning package/module | Actual gap / proposal link |
|---|---|---|
| Verify intent, attempt, Events order reference, organisation, purchaser, captured amount/currency and posted journal before ticketing | T004/T010; Payments P08 + P06; Ledger contract | `payment-ledger-dependency-proposal.md` source map. Current event/status omits journal/accounting data; actual captured amount is not persisted in the P08 success event path; current Ledger contract cannot verify a posted matching journal. |
| Durable confirmation delivery/replay and no duplicate issuance | T010/T011; Platform P11 and Events | Existing outbox/retry/dead-letter/replay is reusable; no Events consumer inbox/dedup or ticket issuance transaction exists. |
| Acknowledge completed ticket fulfillment without merging state machines | T010/T011; Payments P08 API + Events | No internal fulfilment-ack contract; P11 listener does not change fulfilment state. Add one-way idempotent acknowledgement after durable Events ticket storage, preserve terminal-state rules, keep Events issuance state independent. |
| Enable external/bank confirmation | T004/T010; Finance AD-09 + Payments/Ledger P08 | Explicitly blocked: AD-09 unresolved and current code leaves external attempts `pending_ledger` without confirmed event. Require signed Finance mapping, implementation, contract tests and provider evidence for live claims. |

No changes were made to Payment, Ledger, P08, P11 or P18 source/packages as part of this proposal.

## Current dependency trace - 2026-10-28

| Requirement | Code | Verification | Current status |
|---|---|---|---|
| Wallet consumes a valid hold once and binds capture to a posted debit journal | Wallet `WalletCaptureService`, `WalletCaptureContract`, `wallet_capture_records`; Ledger `PostedJournalEvidence` | `WalletCommittedCaptureTest`; `P08WalletCapturePostgresTest.php` | Development verified; operational AD-08 mapping/review missing |
| Wallet capture recovery before Payment evidence persistence | Payment `PaymentOrchestrationService`; stable Ledger idempotency key | `WalletCapturePaymentIntegrationTest` trigger fault cases | Verified with actual PG rows and controlled exceptions |
| Payment capture evidence, accounting state and confirmation outbox are coherent | Payment attempt captured amount/currency/journal columns; `payment.confirmed` outbox | Wallet integration and Ledger acknowledgement DB tests | Verified for Wallet path; external AD-09 path remains pending |
| Same-reference fulfilment acknowledgement is idempotent and scoped | `PaymentInternalConfirmationContract`; acknowledgement record/state transition | `PaymentFulfilmentAcknowledgementDatabaseTest` | 6 PG-backed tests passed; Events durability callback not built |
| P11 delivery/replay and notification dedupe | Outbox/replay route; `PaymentConfirmedListener`; NotificationService unique event key | `OutboxReplayFeatureTest`; `PaymentConfirmedListenerDedupTest` | Passed; replay authority remains platform-wide |
| P25 order-to-ticket acceptance | No authoritative order model/consumer yet | Credential vault primitive passes, but no order transaction test | Blocked by B-06/T004; P18 does not block it |

## Final handoff links - 2026-10-28

- T008/T009 implementation and PostgreSQL evidence: `tasks.md`, `evidence.md`, `research.md`.
- T004 approved issuance invariant, P08 field/evidence contract and exact remaining order-binding/consumer gaps: `business-policy-decision-packet.md` section "T004 invariant reconciliation"; implementation references are in P08 `verification-traceability.md`.
- B-06 remaining answer form, owners, approval evidence and illustrative calculation boundaries: `business-policy-decision-packet.md` section "B-06 concrete answer form".
- P08 walkthrough, AdminFx run and PHPStan baseline: `../057-ledger-ack-fulfilment/quickstart.md` and `../057-ledger-ack-fulfilment/verification-traceability.md`.
- Next T010 slice and required owner inputs: `restart.md` and `tasks.md` final reconciliation.

## T010/T011 durable order and issuance trace — 2026-10-28

| Requirement | Implementation | Verification | Status |
|---|---|---|---|
| Typed immutable pricing; no production fallback | Events `EventPricingContract`, typed pricing value objects, `DenyEventPricingContract`; `EventOrderService` | Default-deny and nonzero test-only fee/tax snapshot scenarios in `EventDraftFoundationTest` | Development implemented; production binding denied pending B-06 and seller/Finance evidence |
| Durable order binds purchaser, organisation, event, lines, hold, quote and server price; first-order currency lock is transactional | `Events/Application/EventOrderService.php`; migration `2026_10_28_100012_create_event_order_ticket_tables.php` | Immutable snapshot, mismatched purchaser, changed-component confirmation, separate-session PostgreSQL idempotency test | Development verified |
| Purpose resolver only accepts authoritative order | `Events/Infrastructure/PaidEventTicketPurposeResolver.php`; Payment orchestration checks context and expiry | P25 Events resolver/order tests | Development verified using test configuration |
| Posted P08 evidence bound to order and exact financial fields gates issuance | `Events/Application/EventPaymentConfirmationConsumer.php`; P08 `PaymentInternalConfirmationContract` | mismatch denial, duplicate/out-of-order replay, verified capture, expired-hold late-capture quarantine | Supported contract path verified with typed test evidence; no live provider certification |
| Per-ticket durable issuance precedes fulfilment acknowledgement; retry cannot duplicate ticket | `event_payment_inbox`, `event_payment_inbox_conflicts`, `event_tickets`; consumer transaction then P08 ack | forced ticket-insert rollback; commit-before-ack retry; multiple entitlements receive independent credentials | Development verified on isolated PostgreSQL 15.19 |
| Production pricing/sales activation | deny pricing binding and existing publication-readiness deny binding | default pricing denial test | Disabled; not approved |

See `contracts/order-ticket-pricing-and-issuance.md` for state, API and recovery contract.


## T011 online-admission trace — 2026-10-29

| Requirement | Implementation | Verification |
|---|---|---|
| Authenticated purchaser ticket access; secret not retrievable across actors | `EventAdmissionController`, `EventAdmissionService::ticketsForPurchaser`, `ticketForPurchaser`; customer `CustomerEventTicketsScreen` | Events feature test: purchaser credential access and aggregate-report privacy; full Events suite below |
| Permission + exact event/gate assignment; safe status/category response | `EventAdmissionController::validate`, `EventAdmissionService::validateOnline`, `ScopedEventAuthorization` | Events authorization tests; separate-session PostgreSQL scan/revoke race |
| One durable initial admission per entitlement; audit atomicity | `event_attendance`, `event_attendance_audit`, migration `2026_10_28_100014` | Concurrent scan race exactly one attendance; rollback feature test |
| Refund-ineligible/unknown states denied using shared owner contract | Payment `PaymentRefundAdmissionEligibilityContract` and service | Unknown/pending/refunded state feature tests |
| Aggregate-only organiser reporting | Attendance aggregate API and admin event-authoring view | Privacy feature test and admin scoped lint |
| Step-up credential rotation | `ticket_credential_audit`, migration `2026_10_28_100015`; customer step-up flow | Credential replacement feature test; race against scan on PostgreSQL |



## P25 convergence traceability — 2026-10-29

| Requirement / task | Source / decision | Code/API/UI | Verification |
|---|---|---|---|
| FR-005 / T011 online gate validation, privacy-safe response, exact event/gate scope | `spec.md` FR-005; A-01/A-04 and recorded online-only policy | `EventAdmissionService::validateOnline`, `ScopedEventAuthorization`, `EventAdmissionController::validate`, Events route `POST /v1/events/{eventId}/gates/{gateId}/validate`, agent `EventGateScanScreen` | `EventDraftFoundationTest` 63/453; `P25AdmissionConcurrencyPostgresTest.php` separate-session barrier cases; agent widget 2 tests / customer widget 1 test. |
| T011 assignment revocation ordering | FR-005 commit-order rule; A-01 and gate authority | Gate assignment locked during authorization in `ScopedEventAuthorization`; `EventAssignmentManagementService` revocation; gate route | PG harness confirms simultaneous run, explicit scan→revoke and revoke→scan order. Scan after revoke returns 403; a scan committed first remains recorded. |
| T011 credential replacement, ownership and proof replay | Approved opaque 128-bit+ credential, purchaser binding, Identity step-up/recovery | `AdmissionCredentialVault`, `EventAdmissionService::replaceCredential`, migration `2026_10_28_100016_add_step_up_replay_fingerprint.php`, replacement route | Identity StepUpSecurityTest 11/65; Events replacement/ownership tests; separate-session replacement/scan and concurrent replacement tests. Replacement after admission returns 409; consumed proof cannot be replayed under another idempotency key. |
| FR-008 credential cache/log/audit privacy | A-04; FR-008 | `EventAdmissionController::privateResponse`, `EventOrderController::show`, customer `ApiClient::LoggingInterceptor`, NoOp analytics, attendance audit schema | Events HTTP cache header/ownership tests, customer and agent widgets; source inspection confirms no URL credential and no request body logging. |
| FR-003/T009 inventory/currency concurrency | CTX-02, MC-01, FR-003 | `EventInventoryHoldService`, `EventCurrencyAssignmentService`, `CurrencyDefinitionCoordinator` and relevant migrations | Existing P25 real PostgreSQL hold/currency/order race evidence in earlier `evidence.md` sections; separate-session assertions, no SQLite/mock DB substituted. |
| FR-004/T010 order/pricing | B-03 development direction, B-06 pending | `EventOrderService`, typed `EventPricingContract`, `PaidEventTicketPurposeResolver`, order/quote routes | Events 63/453 feature suite includes test-pricing and fail-closed production binding. Full real Payment integration remains NOT VERIFIED; see E2E-01. |
| T004 P08 evidence and recovery | Accepted issuance invariant; P08 typed evidence contract | `PaymentConfirmationEvidenceService`, `PaymentOrchestrationService`, `EventPaymentConfirmationConsumer` | P08 actual Wallet/Ledger/Payment integration 6/63; Payment replay unit 4/21; P25 consumer feature tests use test-only confirmation contract and do not claim integrated acceptance. |
| FR-006 / T013 late capture | Existing no-invention boundary; finance policy pending | `EventPaymentConfirmationConsumer` preserves verified facts in blocked reconciliation without ticket/refund | Events test covers blocked reconciliation using a controlled typed evidence fixture. Real wallet capture is tested separately. Final disposition and combined E2E remain open. |
| US1 / T008 authoring/review UI | FR-002 and approved scoped permission model | Events authoring/review API and Admin `EventAuthoringPage` | Events suite 63/453; Admin community a11y suite 4/4; scoped ESLint exit 0. Publication/sales production readiness remains denied. |
| US2 / T010-T011 complete paid journey | FR-001/003/004/006; P08 contract | Events order, P08 capture/outbox, Events consumer and customer ticket UI | NOT VERIFIED as one integrated run. Component evidence is intentionally not promoted to end-to-end evidence. E2E-01 is the next engineering acceptance task. |
| US3 / T011 admission journey | FR-005 | Customer ticket screen, agent online scanner, aggregate Admin report | Customer 1/1 widget, agent 2/2 widgets, Admin a11y 4/4; PostgreSQL concurrency and Events suite pass. Independent review pending. |
| US4 / T014 event completion and payout | FR-007; B-11/P18 | No payout execution behavior introduced | BLOCKED on P18/B-11. Completion never implies settlement or payout. |

Current cross-requirement status and exact remaining actions: `acceptance-matrix.md`.

## E2E-01 integrated development journey trace - 2026-10-30

| Criterion/task | Integrated code boundary | Evidence | Result and limits |
|---|---|---|---|
| FR-001 / T004 / T010 order-bound confirmation | EventOrderService -> Payments purpose resolver/PaymentOrchestrationService -> WalletPaymentAdapter -> WalletCaptureService -> Ledger posted journal verifier -> Payment confirmation outbox | `tests/Feature/Modules/Events/P25IntegratedJourneyTest.php`; persisted order, captured amount/currency, capture record, posted journal, outbox assertions | PASS for supported Wallet development path. Test-only pricing/account config; no AD-09 external path. |
| T010/T011 confirmation delivery and issuance | OutboxDispatcherJob -> real Events consumer -> durable inbox/ticket transaction -> Payments fulfilment acknowledgement | Same test; trigger failure at ack insert after Events commit, replay, exact outbox redelivery | PASS for tested failure/retry and duplicate delivery; no worker process kill claim. No duplicate charge/journal/ticket/ack. |
| US2 purchaser credential boundary | Customer ticket list/detail APIs | Same test; private/no-store, separate purchaser denial, unique credentials per ticket | PASS for tested actors and records. |
| FR-005 / US3 gate and attendance | EventAdmissionService and event/gate scoped assignments | Same test; permission + exact active assignments, repeated scan, persisted attendance/audit | PASS for one admission and repeat. Two-session race proof remains in P25AdmissionConcurrencyPostgresTest evidence. |
| A-04 organiser reporting privacy | Aggregate attendance endpoint | Same test; only category and count returned | PASS for aggregate payload assertion. Independent Privacy/Security review remains open. |

Earlier E2E-01 rows stating NOT VERIFIED are historical and superseded by this trace. Engineering evidence is not production readiness or independent review.

## P25 readiness decision/evidence trace - 2026-10-30

| Decision/evidence area | Existing source | Requirement impact / current handling | Verification or gate |
|---|---|---|---|
| B-06 fees, seller, tax, rounding, disclosure | `business-policy-decision-packet.md` B-06; consolidated `outstanding-decision-register.md` | FR-002/004/010; T008/T010/T015. Product choices for discounts, TTL and reconfirmation stay settled; no approved commercial/tax calculation contract exists. | Production pricing, publication and sales denied. |
| Late capture, cancellation, refunds | `business-policy-decision-packet.md` T004/T005; consolidated register | FR-003/005/006/007; T009/T010/T011/T013. Preserve verified facts and fail closed for unknowns; final financial disposition unavailable. | Refund/cancellation/late-capture actions stay gated pending policy and shared contract. |
| P18 / B-11 | P25 `research.md` B-11/C-05; P18 `specs/065-beneficiary-payout-lifecycle`; consolidated register | FR-007/US4; T014 and payout portion of T006/T016 only. P18 execution/query/retry (T049/T050/T051), settlement evidence/ledger mapping (T055/T056), and P18 verification (T059/T060) remain prerequisites. | No payout/settlement behavior. Does not block order, ticket issuance, inventory, or admission. |
| Independent Identity/Security/Privacy/Finance acceptance | `identity-security-review-index.md`; `independent-review-handoff.md`; consolidated register | FR-002/005/008 and T007/T016. E2E/test fixtures are engineering evidence only. Reviewer appointment owner is not established. | No activation or production approval. |
| E2E compatibility fixes | `tests/Feature/Modules/Events/P25IntegratedJourneyTest.php`; Payment replay and adapter tests | T004/T010/T011. Retry status and persisted intent reference; refund status remains pending absent provider evidence. | Latest focused run: 38/0/0, 226 assertions; details in `evidence.md`. |

UI interaction and process-termination checks remain explicitly unverified and are listed as independent development checks in the decision register; no broader E2E claim is made.

## Accepted-policy implementation trace — 2026-10-30

| Owner direction / requirement | Story/task | Implementation | Verification / status |
|---|---|---|---|
| B-06 organiser pays OleKard platform fee; no customer booking fee without a separate approval; persist immutable fee calculation | FR-004, T010, T030 | EventPricingBreakdown separates organiser_platform_fee_minor and organiser_fee_schedule_snapshot from customer-payable fees_minor/total_minor; EventOrderService checks schedule version, event/organisation scope, currency and effective period; immutable JSON quote/order snapshots reused. | EventPricingBreakdownTest verifies strict required metadata and rejection of customer booking fee; historical order snapshot feature test verifies a later test-policy change cannot rewrite the order. Finance values/accounting remain unapproved. |
| Approved tax matrix and decimal-only money | FR-004, T010, T030 | Non-test pricing requires effective/versioned tax snapshot with jurisdiction, ticket/service-fee treatment, rates or explicit exemptions, taxable bases/order, precision/rounding, disclosure and invoice responsibility; float values rejected. | Synthetic contract tests only; no tax/legal determination or production rate. |
| Late capture exception without automatic issue | FR-006, T004/T013 | EventPaymentConfirmationConsumer retains verified late capture as blocked_reconciliation, with no ticket or refund. | Existing late-capture test and E2E-01 development path. Final recovery/refund disposition remains blocked. |
| Independent review | T007/T031, FR-008 | Four blank review records added in independent-review-handoff.md; reviewer, role, tree/evidence version, findings, remediation, outcome, date and sign-off fields included. | Prepared only; appointments and independent outcomes pending. |
| P18 payout | FR-007, T014 | Existing P18 stubs remain unchanged; P25 event completion does not authorize settlement/payout. | B-11 remains blocked by AD-14, AD-10, LC14 and P18 T049/T050/T051/T055/T056/T059/T060. |

## T030 verification correction - 2026-10-30
Requirement links remain FR-002/FR-004/FR-010 -> T030 -> EventPricingBreakdown and EventOrderService -> EventPricingBreakdownTest and EventDraftFoundationTest. Final focused execution: 70 passed / 0 failed / 0 skipped / 533 assertions, including P25IntegratedJourneyTest (Wallet test-only path); Pint 4 files passed. Detailed environment and limits are in evidence.md. Specialist approvals and operational activation are not satisfied by these tests.

## T030 final verification and B-06/cancellation traceability - 2026-10-30
FR-004/FR-010 -> T030 -> `EventPricingBreakdown` and `EventOrderService` -> `EventPricingBreakdownTest` and `EventDraftFoundationTest`. Added explicit denial coverage for unapproved policy, unverified seller, fee-schedule currency mismatch and future effective windows; the existing suite covers organiser fee exclusion, customer fee denial, missing snapshots, float rejection, wrong event scope and immutable historical quote/order snapshots. Final results are recorded in `evidence.md`.

FR-006/FR-008 -> T006/T013 -> cancellation request/review, event/ticket state, P11 outbox and Notifications integration. Readiness inspection found existing cancellation policy text, cancel permission identifiers and reusable connection-aware P11 outbox; no complete workflow, purchaser cancellation view or cancellation Notification type/template/listener exists. CANCEL-01 and a shared Payments refund execution/status/recovery contract remain immediate dependencies. No cancellation route was added; P25 remains PARTIAL.

Final run count correction - 2026-10-30: current verification for the added T030 cases is EventPricingBreakdownTest 6/13 and EventDraftFoundationTest 66/469, totaling 72 passed / 0 failed / 0 skipped / 482 assertions; Pint passed 2 test files and scoped PHPStan passed. P25IntegratedJourneyTest was not rerun in this slice and is not included in these totals. Its separate prior E2E-01 evidence remains development Wallet backend only.

Expanded effective-date traceability correction - 2026-10-30: fee and tax effective-period denial now has future-start and expired-end cases. Final T030 run: 72 passed / 0 failed / 0 skipped / 488 assertions (unit 6/13, feature 66/475), Pint 2 files PASS, PHPStan level 8 PASS. The targeted effective-window test is 1/12 and is included in the feature total. The integrated journey was not rerun.
