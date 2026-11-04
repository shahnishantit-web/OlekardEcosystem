# Feature Specification: P26 Free invitation and complimentary event journey

**Feature Branch**: `1002-free-invitation-complimentary-events`  
**Created**: 2026-10-30  
**Status**: PARTIAL - development registration/admission/reporting implemented; operational activation and dependent lifecycle work blocked
**Input**: P26 prompt; managed source `docs/sources/OleKard_Managed_Prompts_v1.0.txt`; architecture LC08.

## User Scenarios & Testing

### User Story 1 — Configure complimentary capacity (Priority: P1)

An event organiser or authorised event operator configures a free category or a complimentary allocation, capacity, eligible recipient, validity window, redemption limit, and only the attendee fields needed to issue the entitlement. Invitation access is recipient-bound and cannot be used as a public authorization token.

**Why this priority**: The allocation and invitation rules are prerequisites for safe zero-price registration.

**Independent Test**: With development-only approved event fixtures, create an allocation and invitation, inspect stored scope and expiry, and prove an unrelated actor cannot redeem it.

**Acceptance Scenarios**:
1. **Given** an authorized event manager and an editable event, **When** they configure a free or complimentary allocation, **Then** the category is explicitly marked as zero-price and its quota cannot exceed the event/category capacity.
2. **Given** an invitation restricted to a recipient and usage limit, **When** another actor presents its token, **Then** redemption is denied without disclosing recipient or attendee data.
3. **Given** a paid category, **When** it is edited through existing paid authoring, **Then** its paid price validation and approval gates remain unchanged.

### User Story 2 — Register and issue a free entitlement (Priority: P1)

An eligible authenticated attendee opens a free event or redeems a valid invitation, reviews event terms, submits minimal attendee information, and receives a durable ticket in My Tickets. The registration allocates capacity atomically and creates no payment attempt, capture, ledger posting, settlement, or refund.

**Why this priority**: This is the core free-event outcome and must not fabricate financial activity.

**Independent Test**: Using a published development fixture, concurrently redeem invitations against the last capacity unit and assert no oversell, duplicate entitlement, or financial record.

**Acceptance Scenarios**:
1. **Given** a current published event with available capacity and an eligible free allocation, **When** an authenticated recipient registers, **Then** one free entitlement is committed with its source and invitation evidence.
2. **Given** an expired or exhausted invitation, **When** it is redeemed, **Then** the request fails safely and creates no registration, ticket, or financial record.
3. **Given** an event/category with no remaining capacity, **When** a free registration races for the final unit, **Then** at most one registration and entitlement consume that unit.
4. **Given** a retried request with the same idempotency key and request, **When** it is submitted again, **Then** the original result is returned; a conflicting reuse is denied.

### User Story 3 — Use existing ticket and gate journey (Priority: P2)

An attendee sees their free ticket in the existing authenticated My Tickets experience. An operator assigned to the exact event and gate validates it online through the existing gate controls. Admission is recorded independently for each entitlement and free tickets remain distinguishable from paid tickets.

**Why this priority**: Complimentary entitlements need the same auditable admission boundary as paid tickets.

**Independent Test**: Issue a test-only free entitlement, verify purchaser-only credential access, perform an authorized online scan, and inspect durable attendance and audit records.

**Acceptance Scenarios**:
1. **Given** a free ticket owned by the authenticated purchaser, **When** that purchaser opens My Tickets, **Then** the credential is displayed privately and no other actor can retrieve it.
2. **Given** an authorized operator assigned to the exact event and gate, **When** the operator scans the valid ticket online, **Then** the same status/category-only response and once-only attendance rules used by P25 apply.
3. **Given** offline scanning, re-entry, transfer, or a revoked entitlement, **When** a client or scanner requests the operation, **Then** the API fails closed unless an approved P25 policy explicitly enables it; currently offline, re-entry, and transfer are unavailable.

### User Story 4 — Reconcile free-event attendance (Priority: P2)

An organiser views aggregate free registrations, paid sales, unique admissions, and no-shows as separate measures. Reports expose no attendee names or ticket-level identities. Free entitlements do not enter settlement or refund creation.

**Why this priority**: Organisers need useful attendance outcomes without conflating zero-price participation and revenue.

**Independent Test**: Seed paid and free entitlement fixtures plus attendance records, query aggregate reporting, and verify separate counts and absence of identity fields.

**Acceptance Scenarios**:
1. **Given** paid and free entitlements for an event, **When** an authorized organiser requests the aggregate report, **Then** registrations, paid sales, unique admissions, and post-event no-shows are separately represented.
2. **Given** a free entitlement, **When** reports are generated, **Then** no settlement/refund transaction is created for it.
3. **Given** an event not yet ended, **When** attendance is reported, **Then** no-show count is not finalized or is explicitly reported as not yet available.

## Edge Cases

- Concurrent redemptions, simultaneous invitation revocation, expiry at the redemption boundary, and repeated idempotent requests.
- Recipient actor is inactive, belongs to another tenant, or does not match the recipient binding.
- Invitation use limit is reached while a second redemption is in progress.
- Event/category capacity is exhausted or event lifecycle/version changes during registration.
- Invitation is revoked after a ticket has already been issued. LC08 says this alone does not revoke a previously valid ticket.
- Event cancellation uses the shared Events-owned lifecycle. A reviewed effective cancellation blocks registration/admission, revokes tickets while preserving historical attendance, records paid-order recovery as pending, and writes a durable outbox event. Notice delivery remains dependent on an approved Notification template/channel/privacy/status contract; queued integration events are not delivery evidence.
- Online validation becomes unavailable: deny validation. Offline mode remains unavailable pending approved P25 policy.
- Report queried before the event ends; no-show is not yet a final outcome.

## Requirements

### Functional Requirements

- **FR-001**: Events MUST represent free and complimentary allocations explicitly and keep zero-price entitlements distinct from paid orders and revenue.
- **FR-002**: Authorised event operators MUST configure category or quota capacity, recipient eligibility, invitation expiry, redemption-use limits, and minimal attendee fields within the event's approved capacity.
- **FR-003**: An invitation MUST be recipient-bound when a recipient is specified, non-public as an authorization token, scope-bound to its event/allocation, expirable, revocable, and limited by its configured use count.
- **FR-004**: Events MUST validate event state, invitation eligibility, recipient, expiry, use count, and capacity and commit registration, invitation use, and one free domain entitlement atomically.
- **FR-005**: Repeated free-registration requests MUST be idempotent; conflicts MUST fail without duplicate registrations or tickets.
- **FR-006**: Free registration MUST NOT create a payment intent, captured payment, ledger posting, settlement, or refund. It MUST NOT represent zero price as evidence of capture.
- **FR-007**: Free entitlements MUST appear in the existing purchaser-scoped My Tickets flow and use existing online event/gate authorization, credential privacy, revocation, once-only attendance, and audit behavior.
- **FR-008**: Admission MUST distinguish a valid free entitlement from a paid ticket and MUST skip payment/refund eligibility lookup only for an authoritative free entitlement; all paid ticket checks remain intact.
- **FR-009**: Organiser reporting MUST separate free registrations, paid sales, unique admissions, and no-shows and MUST remain aggregate-only.
- **FR-010**: Zero-price entitlements MUST be excluded from settlement and refund creation.
- **FR-011**: Effective event cancellation MUST use the shared durable outbox/Notification path and MUST distinguish only delivery states supported by the Notification contract. P26 records the cancellation outbox event and consumer foundation; template, recipient/privacy, retry and delivery reconciliation remain required before notice delivery is complete.
- **FR-012**: This release uses online-only admission under the project-owner approved scope change P26-SC-014. Offline validation MUST remain unavailable and fail closed. This scope decision does not authorize production release or replace Principle 15 release acceptance.
- **FR-013**: Invitation expiry/use-limit and sold-out failures MUST not expose attendee/recipient lists and MUST leave no unintended registration, entitlement, or financial effects.
- **FR-014**: Operational activation MUST remain denied until the P25 publication, pricing, security/privacy and operational gates applicable to the event are satisfied.

### Key Entities

- **Complimentary allocation**: Event-scoped free category or quota, capacity, permitted audience, and registration-field policy.
- **Invitation**: Opaque recipient-scoped grant with event/allocation scope, validity, maximum uses, current use count, and revocation evidence.
- **Free registration**: Idempotent record binding event, organisation, allocation, authenticated attendee, invitation if required, and minimal attendee data.
- **Free entitlement**: One durable admission credential linked to a free registration and explicitly marked free/complimentary, without a payment order.
- **Attendance**: Existing per-entitlement admission record, independent from registration and revenue.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Concurrent attempts for the last available unit produce at most one registration and entitlement; evidence must use separate PostgreSQL sessions.
- **SC-002**: Every expired, exhausted, recipient-mismatched, revoked, or sold-out redemption attempt creates zero free entitlements and zero financial records.
- **SC-003**: Replaying one registration request yields exactly one registration and one entitlement.
- **SC-004**: Every admitted free entitlement has one traceable attendance/audit record, and a repeat scan does not add another admission.
- **SC-005**: Aggregate reports return free registrations, paid sales, unique admissions, and post-event no-shows separately and contain no attendee/ticket identities.
- **SC-006**: The free registration flow creates no Payment, Wallet, Ledger, settlement, or refund records.
- **SC-007 (implementation convergence evidence)**: A browser/device journey must exercise Admin invitation issue/revoke, ordinary free and invitation registration, purchaser-only My Tickets, exact event/gate online admission, repeat scan, and aggregate reporting against isolated test services. Component/widget tests and API feature tests do not alone satisfy this criterion. Use test fixtures only in a disposable environment; this criterion does not authorize production approval or activation.

## Assumptions

- Authentication, actor identity, event-scoped authorization, ticket credential vault, My Tickets, online gate validation, attendance, and aggregate-only privacy patterns are reused from P25.
- Development test fixtures may provide explicitly labelled event approvals, invitation grants, and assignments; fixtures are not production grants or approval evidence.
- Registrations require an authenticated actor. No anonymous attendee registration is introduced.
- Minimal attendee data is limited to authenticated actor binding and fields explicitly configured by the authorised event operator; no named-attendee identity checks are added at the gate.
- “Unique admissions” means admitted ticket entitlements counted once; “no-show” is measured only after the event end time for a valid issued entitlement without attendance.
- Settlement/refund states remain distinct and are not created for a zero-price entitlement.

## Unresolved Dependencies

- P25 is PARTIAL: production publication/sales are disabled and independent Security/Privacy review is pending. P26 has a development cancellation lifecycle and durable event/consumer foundation, while paid financial recovery and actual notice delivery remain blocked by their owner contracts. Offline admission is deferred for this release, subject to any required formal release acceptance.
- Browser/device convergence criterion SC-007 remains unverified until an isolated API/browser fixture bootstrap and P26-specific Playwright setup exist. See `browser-e2e-handoff.md`; never use the development API or existing tracked auth-state setup for the E2E run.
- The source requires an “approved complimentary allocation” but does not identify the approval authority or evidence. Production complimentary allocation approval remains blocked; development fixtures may exercise code only.
- P25 review/version publication currently requires Finance price approval. Whether a truly zero-price-only event may use a distinct non-financial approval path is not specified; do not bypass existing publication gates.

## 2026-11-03 project-owner decision addendum

- T006 development model accepted: organisation-scoped approval mapped to event/category/allocation, distinct requester and approver, verifiable approval ID/version/provenance, limits, validity and revocation. This is not Governance or independent specialist approval. The current production contract has no verifiable evidence payload/provider and remains deny-bound.
- T007 narrow free-only publication rule accepted for development and implemented in the internal Events workflow: all current categories must be explicitly free/complimentary and server-authoritatively zero priced; current event review is required; complimentary allocations use T006; paid categories retain separate Finance price approval; publication pauses sales; later paid-category changes return to full review. Production readiness remains denied and no workflow HTTP route was added.
- T014 online-only admission/offline deferral selected for the release by the project owner. The release-scope record and loss/retry verification remain pending; offline scanning is not implemented and remains unavailable.
- T015 cancellation direction accepted: requester and reviewer differ; a pending request does not pause activity; effective cancellation atomically blocks new registration/admission, preserves attendance/audit and tracks financial recovery separately. Events implementation is added. Refund execution/disposition remains blocked; no refunds or ticket issuance are triggered from unknown outcomes.
- Governance is the stated authority to appoint independent Security/Privacy reviewers; actual appointments and signed outcomes are still pending.

## 2026-11-03 decision application

The project owner accepted the scoped complimentary approval model and constrained free-only rule for development; selected online-only admission with offline deferred; accepted separate cancellation request/review and effective-event invalidation while preserving historical attendance; and accepted pending/reconciliation containment for unknown financial outcomes. Governance is the authority to appoint independent reviewers. These answers are project-owner direction only and do not constitute specialist Finance/Tax/Legal/Security/Privacy approval, formal release acceptance where required, actual reviewer appointment, or production activation. Implementation status and exact gates are in `tasks.md`, `acceptance-matrix.md`, and `evidence.md`.

### 2026-11-03 implementation reconciliation

- T006 includes an Events-owned approval request/approve/revoke path and a database evidence adapter, but real authority assignments remain absent because the operational bootstrap verifier is deny-by-default.
- T007 requires the typed `OrganiserVerificationContract`; the production adapter denies until the onboarding owner supplies purpose-specific, versioned evidence. Publication readiness also remains deny-by-default.
- T015 request/review/effective cancellation is implemented using an event-first lock, separate actors, ticket invalidation, preserved attendance, per-order pending financial cases and a transactional cancellation outbox event. No refund, ledger posting or financial completion is inferred.
- T020 notification intent processing is only a foundation. The shared template `events.event_cancelled`, approved recipients/channels/privacy, retry/escalation and delivery evidence have not been supplied; no notice delivery is claimed.

## 2026-11-04 confirmed owner decisions

Product-owner decisions: the Events database T006 approval workflow is the development source subject to Governance acceptance; organiser evidence must establish organisation identity, authorised representative and event-organising authority; P26 admission is online-only with offline deferred; cancellation requester/reviewer are different actors, pending requests leave activity available, effective cancellation prevents new use and preserves historical attendance; fixed OleKard terms require explicit organiser acceptance and reacceptance before affected paid capabilities resume after a new applicable version. These do not create production authority, specialist sign-off or independent review. See the acceptance matrix and remediation register for evidence and open gates.

## 2026-11-04 final development checkpoint

Project-owner decisions recorded in the current P26 decision register: T006 uses the Events database approval workflow for development subject to Governance acceptance; T007 requires the three-purpose organiser evidence and paid-event review/Finance/release controls; T014 is online-only for this release with offline deferred; cancellation requester and reviewer differ, pending requests do not pause activity, effective cancellation is atomic and attendance history persists; unknown paid outcomes remain pending; cancellation notice content/configuration remain owner-review drafts; Governance is the review appointment authority. The additional terms decision is to require organiser reacceptance before affected paid capabilities continue when a new approved terms version becomes applicable. These are project-owner decisions only, not specialist approval, real assignments, independent review or activation.

The final guarded API run passed against disposable database wallet_ecosystem_p26_e2e_20261104_08710f18: CORS 3/52, Identity MFA 10/70, Events foundation 81/636 and Events unit contracts 11/29. Separate PostgreSQL sessions verified last-capacity, invitation-use, idempotency, revoke/redemption, rollback/retry and the four T015 cancellation/registration/admission lock orderings. Each T015 loser was observed waiting on the winner�s database lock. Results and finance baselines are in evidence.md. Database was created and cleaned by the guarded runner only after success.

A full guarded Chromium journey then passed 2/2 Playwright tests against disposable database wallet_ecosystem_p26_e2e_20261104_5e26ee81. Persisted state matched 3 registrations, 3 tickets, 1 attendance, 1 admission audit, 3 free-issuance audits, 5 invitation audits, and zero payment intents, attempts, refunds, Ledger journals, payouts and settlement batches. The journey included Admin invitation issue/revocation, customer free registration/My Tickets, other-customer denial, gate assignment boundaries, wrong-gate denial, admission/repeat-scan behavior and aggregate reporting. Approval/publication/identity/operator fixtures were synthetic. This is browser development evidence, not production approval.

Admin focused tests passed: 2 suites/6 tests. Admin TypeScript passed with exit 0. Focused Admin lint passed with no warnings after nullable-string guards. Scoped PHPStan passed with 0 errors on the changed pricing, order and organiser-evidence production files; PHPStan�s existing version/deprecated-config notices remain. Locked Pint --test passed 30 changed Events PHP files with the process-local APP_CONFIG_CACHE override. git diff --check passed after the final documentation updates, subject only to existing CRLF conversion notices.

The existing same-run native Customer/Agent journey remains verified for Android online/manual credential entry: real challenge/PIN login, free registration, purchaser credential access, wrong-gate denial, exact-gate admission and repeat-scan denial, with zero financial records. Camera capture and native Admin/report interfaces remain outside that evidence. They were not rerun because the final changes were Admin assignment controls, P25 paid-pricing contract validation and server-side cancellation/approval contracts; the browser journey and API regression were rerun.

T006 development workflow and Events Admin assignment controls are implemented/tested; production operation still requires Governance acceptance and actual authorized assignment records. T007�s required organiser proof references and fail-closed checks are implemented/tested, but the production verifier remains deny-bound because no authoritative onboarding contract establishes evidence validity/freshness/revocation for this purpose. Free-only publication logic remains development-only; production publication readiness remains deny-bound.

The P25 pricing boundary now requires version/hash-bound OleKard acceptance evidence, scope and effective-date validation, and immutable order snapshots. P26 T028 adds a Platform terms registry, protected draft/approve/publish and organiser-acceptance API routes, immutable content hashes, typed approval and representative-verification contracts, and a current-acceptance query consumed by Events. No dedicated Admin/organiser UI was added. Production approval and representative providers remain deny-bound; approved terms content, role assignments and real evidence are absent. Actual fees/tax and paid checkout remain unavailable; no zero-fee/tax policy or production binding was added.

T015 free-event cancellation and tested races are verified. Paid recovery remains blocked by missing refund/cancellation/late-capture terms and accepted Payments/Ledger execution/status/reconciliation contracts. T020 transactional intent and recipient-scoped notification command deduplication are verified; cancellation template, channels/privacy policy, retry/escalation, real provider delivery evidence and operator acceptance remain pending. Queueing or dispatch is not delivery. T016/T024 remain pending genuine Governance-appointed independent reviewers, conflict records and signed version-bound outcomes. T014 is project-owner approved deferred for P26 scope; separate production release acceptance remains distinct. T023 Chromium is current-source PASS; its Android online/manual-entry scope is preserved from the prior same-run evidence; camera capture is not verified. T027 remains PASS from the recorded locked-Pint evidence.

Browser builds succeeded on the JavaScript target. Flutter�s optional Wasm dry-run reports existing dependency incompatibilities (flutter_secure_storage_web uses dart:html/dart:js_util; printing uses dart:ffi); Wasm is not a configured target. The Cupertino font warning also appeared; workspace app source has no CupertinoIcons reference and generated FontManifest contains MaterialIcons only, so no unused dependency was added. These are recorded as non-blocking target/dependency warnings, not silent passes.

Overall P26 remains PARTIAL. Production bootstrap, publication, sales, deployment, P18 execution and P24 activation remain disabled. No external messages, commits or pushes were made.

## Final acceptance reconciliation (2026-11-04)

The approved development behaviors and evidence are recorded in `acceptance-matrix.md` and `evidence.md`. LC08 v1.1 lines 439-449 requires an approved complimentary allocation but does not name an authority/evidence format; T006 development is complete, while Governance authority assignments and production verifier evidence remain blocked (Constitution Principle 2). P26-SC-014 is the Product Owner-approved online-only scope disposition for FR-012; no separate package approval procedure was found. Constitution Principle 15 requires separate release acceptance and security gates before production, without naming a particular approver. T028 has protected API entry points and no dedicated UI acceptance criterion in the approved plan. Remaining external records and exact acceptance tests are in the consolidated section of `remediation-register.md`. P26 remains PARTIAL; production bootstrap, publication, sales and deployment remain disabled.
