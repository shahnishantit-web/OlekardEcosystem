# P25 Flow and Operational Boundaries â€” Review Draft

No P25 flow is enabled. A draft-only backend foundation exists with no HTTP routes; the default organiser authorizer denies every caller. The following identifies the evidence needed to complete each flow; it does not define missing policy.

| Flow / trigger | Prerequisites and owners | Success evidence | Failure and recovery / operator action |
|---|---|---|---|
| Author and publish | Approved organiser, reviewer, organisation scope and published policies; admin owns authoring, Identity authority. | Approved version, category capacities, sales window and audit. | Keep draft/unpublished on missing review; correct and re-review after changes. |
| Hold and checkout | Published event, approved hold expiry, available capacity and server quote; mobile selects, Events owns allocation, Payments owns financial execution. | One bounded reservation, linked quote and shared Payment intent. | Unknown outcomes remain unresolved; query the same intent; do not create a replacement charge. Expiry and late capture require approved policy. |
| Confirm and issue | Approved verified-payment/accounting threshold and durable confirmation; Payments owns financial state, Events owns entitlements. | Shared payment and journal references linked to one ticket set. | Persist an unresolved issuance exception; replay same confirmed reference through an idempotent handler after failure. Never post a second charge or journal. Exact transaction/outbox boundary requires design review. |
| Online admission | Authorised tenant, event, gate and operator; Events verifies valid credential under approved refund/revocation policy. | One admission and auditable scan attempts. | Deny out-of-scope/used/revoked; investigate without exposing ticket details. Concurrency acceptance required. |
| Offline admission | Explicitly approved device/manifest, expiry and revocation policy. | Reconciled device logs and exception record. | Disconnected devices cannot guarantee global single admission; reconcile conflicts on reconnect. No offline feature approved here. |
| Close and payout | Approved cancellation/completion policy, resolved refunds/disputes, reserve reconciliation, finance approval and verified P18. | Separate event completion, settlement and payout records with ledger and provider evidence. | Preserve open liabilities and unknown payout; do not settle solely because event is complete. Escalate to finance; no local bank calls or journal invention. |

## Required flow behavior already selected

- Gate validation returns only `valid/invalid` and ticket category. It never returns customer or payment fields. Entry and exit state updates require online validation and fail closed on connectivity/service failure; a person may physically leave during an outage, but cannot re-enter until an online exit record exists. Re-entry allowances count returns only, not initial entry. These requirements do not remove the remaining permission, credential, limit-value, retention or concurrency gates in `research.md`.
- A cancelled event remains visible with sales disabled. Reactivation remains blocked pending approved lifecycle policy. Do not add a reactivation command or infer that a new publication review alone authorizes reactivation.
- Finance-owned currency cannot be submitted by the organiser. Until supported currency, fee/tax rules and a shared Payments quote contract are available, no paid checkout or charge is exposed.

## Operator runbook boundary

No P25 routes or live journeys are registered. Do not enable an event from draft records. If draft validation fails, correct the draft and resubmit against the current version; the default authorizer currently denies every caller, so access failures require a reviewed authority implementation, not a local bypass. For unknown payments, refunds, settlement or payout, leave financial state unresolved and use the relevant shared Payments/P18 evidence procedures; do not issue tickets, release payout or call a bank directly. Offline gate attempts are denied and retried online. Checklist approvals and support contacts are tracked in `research.md` C-01â€“C-07 and must be recorded before affected flows are enabled.

## Draft foundation currently implemented

An approved future caller may submit a draft and supplied category capacities/prices, event/sales times and policy text after Identity membership and a separate approved organiser authorizer are both satisfied. Currently the authorizer denies all actors. Create and update are transactional on `community_services`; edits compare a version and replace categories atomically; the sum of category capacities cannot exceed event capacity. A state-only audit row is written in the same transaction and forwarded to Platform AuditService after commit. Stored policy text is unapproved input, never a published policy or proof of authorisation. No purchase, credential, admission, payout or financial event exists in this foundation. Platform audit replay and cross-connection revocation-race acceptance remain release checks.

## 2026-10-28 workflow capability boundary

Internal review transitions now exist behind deny-by-default production contracts. Submission and every approval/publication/resume transition lock the event record and check the submitted version. Publication stores the approved version snapshot only after independent event review and price approval plus a Finance/payment-readiness check. A proposed edit increments version, pauses sales and invalidates approvals while retaining the last published snapshot. An explicit resume checks that the published snapshot is still inside its approved sales window. There is no registered HTTP route; only tests inject permissive authority/readiness contracts. Do not issue access until B-01 is approved and bound. Do not expose paid publication without B-03 and B-06. This internal foundation does not implement public-page visibility, redacted mobile/admin presentation or live sales.

## Authorization enforcement and blocked assignment management

Draft creation requires an active actor and organisation, the coarse `events.event.create` permission, and an explicit organisation-scoped assignment. Draft read/update additionally require active membership, their respective coarse permission, and an exact event assignment. Submit, event review, Finance price approval, publish and sales resume require the action permission and exact event assignment; workflow state/version checks and A-02 separation remain in `EventWorkflowService`.

Gate validation requires `events.gate.validate`, an active assignment for the exact organisation/event/gate tuple, and a surrounding admission transaction; the assignment row is locked through that transaction. Revoke is visible to the next authorization query. The admission endpoint and real concurrency verification are not implemented yet. Gate assignment management cannot delegate any permission other than validation and rejects self-assignment. Grant/revoke endpoints remain disabled until `assignment-authority-proposal.md` is decided.

Do not accept permission or scope supplied by clients as proof. Resolve actor from authenticated context, derive organisation from the event, and check the stored assignment on every protected operation. Payout and refund remain calls to shared Payments/P18 services only after eligibility contracts are available; no Events balance or ledger mutation is allowed.

## 2026-10-28 assignment administration and gate configuration

**Assignment administration (development endpoints):** A designated organisation assignment administrator with `events.assignment.grant` or `events.assignment.revoke` may manage non-Finance organisation/event grants only for its organisation. A separately designated Finance assignment administrator is required for Finance permissions. Every target must remain an active member of the event organisation. The authenticated actor comes from Identity middleware; organisation is derived from the event or designated administrator record. Writes are idempotent and append a transaction-local attributed audit row; shared Platform audit is forwarded after commit. Repeated active grants return the existing row; repeated revokes return the already revoked row. Revoking an administrator blocks their next write but does not revoke prior grants. Use issuer-history lookup to identify those grants, then revoke each through the normal authorized endpoint. No cascade exists.

**Bootstrap command:** `events:bootstrap-assignment-authority` requires explicit operator permission and active membership, two distinct active member approvers holding `events.assignment.bootstrap.approve`, target membership plus grant/revoke permissions, authority type and a unique approval reference. Operator cannot be the target. Do not execute until Identity has explicitly provisioned the required permissions and an independent operator has verified the approval reference and approver authority. The current platform stores an opaque reference and cannot verify its governance record. No defaults or development fixtures may be promoted to operational authority.

**Gate configuration:** an event-scoped `events.gate.assignment.manage` assignment plus that Identity permission allows a manager to register a named gate and grant/revoke only `events.gate.validate` for that gate within the same event. Self-assignment is denied. The write locks the manager's event assignment and target gate in the same transaction; exact-scope queries make revocation effective for later checks. Gate authorization is not ticket admission: no scan endpoint, ticket credential or re-entry limit is implemented by this path. Unknown gates fail closed.

Global admin status or organisation membership alone grants no Events action. Product/design decisions establish only development behavior and do not constitute independent owner review or production approval.
Gate managers may list active/inactive gate records for their exact event through `GET /v1/events/{eventId}/gates`; the result contains only gate ID, event ID, name and status. This is configuration metadata, not ticket or attendee data.
The gate list currently contains only gates created in active status; this package has no gate-retirement endpoint. Listing does not expose operator, ticket, attendee, or scan information.
## 2026-10-28 authoring/review and bootstrap operational correction

This section supersedes earlier statements that no P25 authoring routes exist and that an operator can validate bootstrap from an opaque reference.

### Draft authoring and internal review (development-only API/UI)

- **Trigger/prerequisites:** an authenticated organisation member with the appropriate Identity permission and exact Events assignment opens `/community/events`; the caller provides the organisation/event identifier. Draft creation additionally needs `events.event.create` and an organisation-scoped create assignment. Read/update/submit/review actions require their matching `events.*` permission and event-scoped assignment. Finance price approval additionally requires a separate Finance authority grant. Membership or global admin alone is not sufficient.
- **States/owners:** Events owns draft content, categories, version, contributor history and internal review state. Identity supplies authenticated actor and coarse permission. Events assignment tables supply resource scope. Finance owns price approval. Every edit creates a new version and invalidates approval; version mismatch returns conflict.
- **Success path:** author creates/edits draft through authenticated API; submits exact version; non-contributor event reviewer approves/rejects; distinct non-contributor Finance approver may approve prices. Admin interface shows current draft/review state. Publication and sales operations are not exposed.
- **Failure/recovery:** missing permission/assignment or membership returns denial; fix authority only through the approved assignment workflow. Version conflict requires reload and explicit resubmission. Validation errors require correction and new version. Audit/state changes stay within the owning service transaction. No customer event page, ticket, charge or entitlement is produced.
- **Admin/mobile/foundation:** admin owns draft/review UI; customer mobile has no P25 public-event or ticket surface; foundation services provide Identity and Events authorization/audit. No mobile integration is claimed.
- **Completion evidence:** `EventDraftFoundationTest.php` (28 passed, 139 assertions), route list (16 routes), Pint/PHPStan and scoped admin TypeScript check in `evidence.md`. API authorization integration tests remain a review gap.

### First assignment-administrator bootstrap

The CLI command delegates to `EventAssignmentManagementService`, whose production verifier binding is `DenyBootstrapApprovalEvidenceVerifier`. The opaque reference and two actor IDs are recorded only in a test fixture if a test verifier is explicitly injected. Operational invocation returns 403 before administrator/audit inserts. Do not run it until a governance-controlled verifier can validate organisation, target, authority, two approvers, approver authority and durable approval reference; then require the recorded two-person approvals. No production grant or bootstrap is evidenced.

## P25 checkpoint — authoring/review, inventory foundation and continuation (2026-10-28)

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
| T005 holder, credential, refund/transfer and numeric re-entry policy | AWAITING SPECIFIC PRODUCT DECISION / CONTRACT | Ticket/order ownership model, credential lifecycle and refund/transfer eligibility are absent. D-02 blocks the re-entry branch only: decide the numeric maximum returns; “returns only” count semantics remain settled. Identity and Privacy contracts/review are also required. |
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

Repository-supported `npm.cmd run lint` exits 1 for existing ESLint errors across payment-bank form pages and collections pages. P25 `event-authoring.tsx`, navigation and `/community/events` route files have no lint errors; scoped lint exits 0 with four existing nullable-string warnings in `campaign-list.tsx`. `npm.cmd run build` compiles successfully then fails during Next lint/type stage on the same workspace ESLint errors (unused imports/variables and unassociated form labels in bank/collections pages). Generated `.next` route types were stale and referred to removed role pages; the normal build regenerated them. `node node_modules/typescript/bin/tsc --noEmit` then exits 0. Preserve `tsconfig.tsbuildinfo`’s pre-existing tracked state when finishing. Whole-app lint remains a failed check; do not call the admin build successful.

### Source and traceability

- Approved source/reference and decisions: P25 `research.md` and `spec.md` (LC07/section 2; D-01–D-09 and C-01–C-07; product decisions A-01–A-04 and the later recorded clarifications).
- T008/T009 code: API `app/Modules/Events/Application/EventDraftService.php`, `EventWorkflowService.php`, `EventInventoryHoldService.php`; controllers under `app/Modules/Events/Infrastructure/Http/Controllers`; `routes/modules/events.php`; migration `2026_10_28_100008_add_inventory_holds.php`; command `app/Modules/Events/Console/ExpireEventInventoryHolds.php`; admin `src/modules/community/pages/event-authoring.tsx`, `app/(admin)/community/events/page.tsx`, navigation `src/lib/navigation/nav-items.ts`.
- Automated evidence: `tests/Feature/Modules/Events/EventDraftFoundationTest.php`; precise results and commands in `evidence.md`.
- Finance/Payments readiness gap: `b03-payment-readiness-contract-proposal.md`; review request: `independent-review-handoff.md` and `identity-security-review-index.md`.

No production bootstrap, role/assignment grant, paid publication, live sales, live provider, P24 activation, deployment or commit occurred.

### Inventory hold PostgreSQL race verification procedure

From `wallet-ecosystem/services/api`, run `$env:APP_ENV='testing'; php tests/Concurrency/P25InventoryHoldPostgresTest.php` in PowerShell. The script requires `community_services` on PostgreSQL, verifies distinct `pg_backend_pid()` values for each race pair, releases workers through a filesystem barrier, checks invariants and removes its unique test fixtures. It does not run on SQLite and must not be pointed at production. Current verified output and limits are recorded in `evidence.md`.

## B-03 operational flow update â€” 2026-10-28

Currency assignment starts from an authenticated Finance-authorized event operator. Events derives actor identity from Identity middleware, checks current organisation membership, `events.currency.assign` and the exact active event assignment, reads code/precision/timestamp from MultiCurrency, and records the assignment plus domain audit transactionally. Approval-reference text is provenance only; it does not substitute for assignment authority or independent Finance review. No UI or bootstrap path creates an operational grant.

On inventory hold, Events locks the event row, validates that the published version is current, checks sale window/configured expiry/category capacity, rereads the authoritative currency state, and records the first currency lock in the same transaction as the hold. If currency is absent/inactive or current precision differs, the transaction creates no hold and no money mutation; after rollback, an operator-review issue is persisted and the request fails with conflict. Expiration releases inventory only; it never unlocks the event currency. Operators must investigate the issue and obtain authorized review; this slice does not rewrite/cancel/refund existing orders or determine pending-attempt disposition.

Before a durable P25 order is eventually inserted, its creator must call `lockForFirstObligation(eventId, 'durable_order')` under the same event transaction and row lock. There is no T010 order caller yet. MultiCurrency and Events currently lack a shared lock/version contract for a precision/deactivation update racing between currency read and event lock. Until that cross-module contract exists, the concurrent currency-definition update guarantee remains explicitly unverified; the implementation only rechecks on each request and fails closed on observed drift.

Actor-free static readiness evaluates one context per currently sellable category amount and requires a configured method on every category. It reports configuration only, and wallet-only when no other method class is configured. It does not query customer balance. Checkout later must recompute its exact authoritative amount with authenticated customer `OptionsQuery`. This integration remains unavailable until Payments registers the proposed module/purpose and Events establishes its approved country source. The production Events readiness binding denies publication/resumption meanwhile.

## Coordination and country contract addendum â€” 2026-10-28

Country checks currently consume a caller-supplied country code; the Payments account restriction list is a deny-list, while provider `supported_countries` is metadata not used by `EligibilityPipeline`. None proves event venue, organiser, merchant-account or customer country. Proposed initial scope is one-country physical events using an Events-owned approved venue-country field. Online, hybrid/multi-country and post-commit country changes stay blocked pending owner rules. See `research.md` for the scenario decision table and exact evidence.

MultiCurrency remains authoritative for currency activation and definition; Events owns event assignment/snapshot and fails closed on detected drift. The current hold check is not serialized with independent currency writes: MultiCurrency status updates use a platform connection/advisory lock, while Events checks through its own transaction and another connection. Contract proposal requires a shared transaction-scoped currency row guard, all writer/migration participation, deterministic lock order, durable Events review issue/pause/audit after rejected-operation rollback, and separate PostgreSQL race tests. Pending payment attempts and holds require an explicit Finance/Payments disposition; no automatic compensation is introduced.

## Currency drift operations update â€” 2026-10-28

For inactive or precision-drift hold rejection, the attempted hold transaction rolls back first. Events then commits a durable event_currency_review_issues record, pauses active sales and writes domain audit evidence. Existing holds and monetary snapshots remain unchanged. New holds fail closed. Incoming payment facts continue through shared Payments; this slice does not initiate capture/refund/cancel/issuance or choose disposition for pending attempts or existing holds. Operator review must follow the approved Finance/Payments recovery policy once supplied; no automatic resume is available.

Currency writer and hold concurrency is guarded by a shared PostgreSQL transaction-scoped advisory key. If a writer commits before a hold, the hold rereads and rejects drift; if a hold has the shared key first, the writer waits until that transaction ends. If the writer rolls back, the blocked hold retries against committed state using its original idempotency key. Cross-database or extracted-module operation is unsupported unless the same coordination protocol is implemented.

## T010/T011 order, payment evidence and durable issuance â€” 2026-10-28

**Trigger/prerequisites:** An authenticated purchaser requests a quote/order for an existing event and valid inventory holds. Pricing must come from the typed contract. Production default denies while B-06/seller evidence is absent; development tests use an explicit test-only fixture. Order creation requires renewed confirmation if a payable component changes and commits the event currency lock with the first durable order.

**State owners:** Events owns quote/order, holds, inbox, ticket entitlements and fulfilment state. Payments owns payment intent/attempt and confirmation evidence. Ledger owns posted-journal evidence. Wallet remains authoritative for balances. Events never infers payment state or ledger posting from callback payloads.

**Success:** Payment confirmation triggers an authorized P08 evidence query. Events verifies order/purchaser/organisation/currency/amount and posted journal, then atomically commits inbox deduplication, one credential per entitlement, hold consumption and issued state. Only after commit does Events acknowledge P08 fulfilment. Customer reads tickets using authenticated purchaser/order ownership.

**Failure/recovery:** Missing evidence is retained as `awaiting_evidence` and retried/query-reconciled. Contradictory evidence, expired hold, late capture or currency drift is retained as a blocked reconciliation case; do not issue a ticket or infer failure/refund. Failed ticket transaction rolls back inbox/entitlement changes so delivery can retry. A crash after ticket commit but before acknowledgement is repaired by redelivery; the durable inbox/order effect prevents another credential or charge, then acknowledgement is retried. Payments and Events transactions are separate; no cross-service atomicity is claimed.

**Operator runbook:** Inspect Events order/inbox and P08 evidence query; compare immutable order snapshots with authoritative intent and posted journal. Restore the evidence path and replay only via existing outbox/replay for transient missing evidence. For late capture, mismatch or currency drift, preserve evidence and follow separately approved Finance/Payments disposition; this code does not refund, cancel, recapture or issue. Confirm P08 acknowledgement only after durable ticket rows. Keep publication/sales disabled while production pricing/review gates are closed.

**Completion evidence:** `EventDraftFoundationTest.php` and `P25OrderIdempotencyPostgresTest.php` are listed in `evidence.md`. Development tests are not operational or independent acceptance.


## Online ticket admission and credential recovery (development scope)

**Trigger/prerequisites:** Purchaser opens My Event Tickets, or an assigned gate operator scans a credential while online. Customer ticket retrieval requires authenticated purchaser ownership. Gate validation requires the applicable registered permission and an active assignment to the exact event and gate. Event must be in its currently published version. Re-entry, transfers and offline operation are unavailable.

**State owners:** Events owns ticket entitlement, credential hash/encrypted retrieval, admission state, attendance and credential-rotation audit. Identity verifies step-up evidence for replacement. Payments owns refund eligibility facts. No client or provider assertion changes these states.

**Gate success:** Online validation locks the active gate/assignment and ticket in a single PostgreSQL transaction, checks Events eligibility plus Payments refund eligibility, then writes one attendance row and its audit row. The ticket row's unique attendance constraint prevents a second admission. Result contains only `admitted` or `already_admitted` plus category. Unknown, active/pending or returned-funds refund status, revoked ticket, stale event version, inactive assignment or invalid credential is denied.

**Recovery/failure:** Transaction rollback leaves neither attendance nor audit. Retry after an already committed admission returns already_admitted without another record. Gate assignment revocation serializes against scans; the result reflects which transaction acquired/committed authority first. No offline queue is provided. Escalate contradictory Payments evidence to the existing Payments reconciliation process; do not mark it refunded/failed locally.

**Credential retrieval/replacement:** Customer retrieves the existing encrypted credential; opening the ticket does not regenerate it. Replacement requires owner authentication and Identity actor/session/scope-bound step-up, rotates credential material and audit atomically, and invalidates the old hash. If step-up cannot be verified, replacement is denied. Do not place credential data in logs, audit details or analytics.

**Organiser reporting:** Use the event-scoped aggregate endpoint. It returns counts by ticket category only. Do not export or infer named attendees or individual scans. Independent Privacy/Security review remains required before activation.

**Operator checks:** Confirm scanner connectivity and operator exact gate assignment before use. If validation returns denied, do not admit manually; resolve assignment/ticket/payment eligibility through authorized operational procedures. Record only approved operational evidence; never copy QR secrets into tickets or incident logs. This development flow is not enabled for live events.



## P25 online admission and credential recovery â€” convergence update 2026-10-29

**Trigger/prerequisites:** An authenticated purchaser retrieves only their own issued ticket. A gate operator must have both the registered `events.gate.validate` permission and an active assignment to the exact event and gate; online service connectivity is mandatory. Re-entry, transfer and offline validation are unavailable.

**State and transaction order:** Events owns ticket credential hash/encrypted display value, ticket status, attendance and security audit. Admission transaction locks the active gate, then validates/locks the exact scoped assignment, then locks the matching ticket and checks event publication version, ticket eligibility and Payments refund eligibility. Attendance row, attendance audit and ticket result commit together. PostgreSQL unique constraints and the locked ticket serialize concurrent scans. Revocation uses the assignment row lock: if revocation commits first, scan is denied; if scan commits first, its attendance remains. Credential rotation locks the ticket and checks attendance before replacing credential hash and encrypted value; it commits the rotation audit in the same Events transaction. If scan commits first, replacement fails because history exists. If replacement commits first, the old credential no longer resolves. The tested concurrent outcomes and both sequential orderings are in `evidence.md`; they do not prove untested schedules/runtime failures.

**Step-up and recovery:** Customer obtains Identity step-up for `event_ticket.credential.replace:{ticketId}`. Identity verifies signed token, audience/scope, actor subject, session, `nbf` and expiry. Events stores only a SHA-256 token fingerprint under a unique `(ticket_id, fingerprint)` constraint. Same idempotency-key retries are stable; another key cannot reuse the proof for that ticket. Rotation invalidates the old secret atomically and cannot occur after admission. Ticket display decrypts the existing secret; it never regenerates on page open. Independent Identity/Security review remains required.

**Failure/recovery:** Offline UI disables scanner and validation; there is no offline API route. Cross-scope, revoked, refunded/unknown eligibility, replaced, malformed or duplicate credentials deny or return already-admitted status without exposing identity/payment fields. Unknown refund state fails closed. Customer credentials are served with private/no-store headers. The scanner sends credential only in POST body; application logs omit body and analytics is no-op. If attendance or audit write fails, the transaction rolls back both.

**Responsibilities and operator runbook:** Customer app displays owner-scoped credential and admission status. Agent UI validates online and displays only result/category. Admin attendance view is aggregate-only. Operators revoke assignments through the authorised Events assignment path; then verify a new online scan is denied and inspect existing attendance/audit without attempting to erase it. For credential compromise, require fresh Identity step-up and use the idempotent replacement route; do not rotate an admitted credential. Refund/cancellation/late-capture operators must follow separately approved Payments/Finance disposition and must not use scanner or ticket mutation to invent one.

**Completion evidence:** Events feature suite, actual separate-session PG admission race harness, Identity step-up suite, and customer/agent widgets listed in `evidence.md`. This is scoped development evidence, not independent Security/Privacy acceptance or activation evidence.

## Explicit remaining flow runbook: paid order to ticket

The typed order, pricing, currency lock, P08 evidence consumer and ticket issuance components exist, but a single integrated actual Payment-to-Events journey has not been run. Until E2E-01 passes, keep production checkout unavailable. In the disposable environment use only labelled test pricing/account/assignment/service identity, real Payment/Wallet/Ledger services, real outbox delivery and actual P25 order binding. Verify duplicate and commit-before-ack replay without another charge or ticket. Do not use mocked confirmation data for that acceptance run. B-06 still blocks production pricing; independent review and production grants remain separate.

## Integrated development journey runbook - E2E-01 - 2026-10-30

The isolated PostgreSQL integrated test now exercises the development journey using the actual Wallet, Ledger, Payment evidence/outbox and Events inbox/ack services. The authoritative run command, environment, persisted-state assertions and recovery fault boundary are recorded in `evidence.md` under E2E-01.

Use only the named disposable database and test-only pricing, provider/capture mapping, event publication, service identity and operator assignment. The test includes real hold/order/currency lock, supported Wallet capture, posted journal verification, outbox delivery, durable ticket issuance, purchaser ownership, exact gate admission, attendance/audit and aggregate report. A trigger fails the Payment acknowledgement insert after Events commits. Remove the trigger and replay the outbox; verify one ack and no duplicate journal, ticket or admission. Do not treat this trigger exception as process-kill coverage.

Never copy fixture pricing, provider configuration, assignments or the Events service actor to an operational environment. For any actual event, production pricing/readiness, publication, operational grants, independent reviews and existing activation gates must be satisfied. If delivery is unknown, query Payments and Events durable state and replay through the existing outbox; do not create a replacement charge/order or manually issue tickets. External provider paths, refund/cancellation/late-capture decisions and payouts are outside this verified flow.

## Policy and evidence gates reference - 2026-10-30

Use `outstanding-decision-register.md` for the owner questions and required evidence before changing production pricing, cancellation/refund disposition or payout. Preserve the settled operational behavior: unknown financial outcomes remain unresolved; no refund, capture, cancellation, ticket issuance or payout is initiated as a workaround. The E2E-01 operator-development command and fault boundary are in `evidence.md`; it uses the isolated disposable database and test-only configuration only. E2E-01 is not a Flutter/browser walkthrough, worker termination test, provider certification, independent review or production runbook acceptance.

## Pricing configuration and exception runbook update â€” 2026-10-30

For a quote attempt, Events reads the approved server-side event, organisation, physical venue country, assigned currency/precision, current hold and category quantities. The bound EventPricingContract must return seller evidence, an effective organiser fee schedule scoped to that event or organisation, and the complete approved tax-policy snapshot. Events rejects missing/stale/mismatched evidence, any unapproved customer-payable booking fee, or binary floating-point values before persisting a quote/order. Customer total excludes the organiser platform fee. The fee and tax snapshots are persisted on the quote/order and cannot be edited after confirmation. Do not repair an unavailable policy by inserting zero fees/tax or copying a test fixture. Operator evidence is the safe pricing-unavailable result and absence of new quote/order/payment-intent rows.

On late verified capture after hold expiry, the consumer preserves the financial fact and moves the order/inbox to blocked_reconciliation; it creates no ticket and starts no refund. The operator must use the approved shared Payments/Ledger reconciliation process and await an authorized recovery decision. Refund/cancellation terms and final disposition are still pending; never infer that unavailable refund means a no-refund policy.

Organiser fee proceeds deduction, settlement and payout require the approved shared accounting/payout path. Keep it unavailable while B-11/P18 is unresolved. Project-owner acceptance is not independent Finance/Tax/Legal approval. See outstanding-decision-register.md for evidence owners and gates.

## Event cancellation status
Cancellation is not operationally available. The authoring cancellation-policy text is not an executable recovery policy. Do not manually change `workflow_state`, `sales_state`, ticket status or Payment/Refund state to simulate cancellation. Before implementation is exposed, obtain the CANCEL-01 financial policy and Payments/Ledger refund recovery contract; then add event-scoped request/different-person review, atomic sales closure and ticket invalidation, a P11 outbox event, purchaser notices through Notifications, and a way to distinguish queued/attempted/delivered/failed notice status. The outbox dispatch status is not proof of customer delivery. Cancelled-event reactivation remains denied.
