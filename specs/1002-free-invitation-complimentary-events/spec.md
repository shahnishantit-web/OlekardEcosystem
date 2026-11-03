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
- Event cancellation requires entitlement revocation and notice; P25 currently lacks a completed cancellation/notice workflow, so this part remains dependent until the shared lifecycle is available or an approved bounded path is established.
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
- **FR-011**: Cancellation/revocation notifications MUST use the shared durable notification path and MUST distinguish queued from delivered status. P25 cancellation/revocation workflow dependency must be met before asserting completion.
- **FR-012**: Offline admission MUST remain unavailable until P25 supplies an approved offline policy and its controls/evidence.
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

- P25 is PARTIAL: production publication/sales are disabled; independent Security/Privacy review is pending; cancellation/revocation notice workflow and approved offline policy are not complete. P26 development can proceed with fixtures and online-only controls, while production use and dependent cancellation/offline behavior remain blocked.
- Browser/device convergence criterion SC-007 remains unverified until an isolated API/browser fixture bootstrap and P26-specific Playwright setup exist. See `browser-e2e-handoff.md`; never use the development API or existing tracked auth-state setup for the E2E run.
- The source requires an “approved complimentary allocation” but does not identify the approval authority or evidence. Production complimentary allocation approval remains blocked; development fixtures may exercise code only.
- P25 review/version publication currently requires Finance price approval. Whether a truly zero-price-only event may use a distinct non-financial approval path is not specified; do not bypass existing publication gates.

## 2026-11-03 project-owner decision addendum

- T006 development model accepted: organisation-scoped approval mapped to event/category/allocation, distinct requester and approver, verifiable approval ID/version/provenance, limits, validity and revocation. This is not Governance or independent specialist approval. The current production contract has no verifiable evidence payload/provider and remains deny-bound.
- T007 narrow free-only publication rule accepted for development and implemented in the internal Events workflow: all current categories must be explicitly free/complimentary and server-authoritatively zero priced; current event review is required; complimentary allocations use T006; paid categories retain separate Finance price approval; publication pauses sales; later paid-category changes return to full review. Production readiness remains denied and no workflow HTTP route was added.
- T014 online-only admission/offline deferral selected for the release by the project owner. The release-scope record and loss/retry verification remain pending; offline scanning is not implemented and remains unavailable.
- T015 cancellation finance containment accepted. Unknown financial outcomes remain blocked; no terminal cancellation/refund/issuance behavior is added until specialist contracts are available.
- Governance is the stated authority to appoint independent Security/Privacy reviewers; actual appointments and signed outcomes are still pending.

## 2026-11-03 decision application

The project owner accepted the scoped complimentary approval model for development only; accepted the narrow free-only publication rule for development only; selected online-only admission with offline deferred for this release; and accepted continued containment for unknown cancellation financial outcomes. Governance is the authority to appoint independent reviewers. These answers are recorded as project-owner direction and do not constitute specialist Finance/Tax/Legal/Security/Privacy approval, release governance acceptance, actual reviewer appointment, or production activation. Implementation status and exact gates are in `tasks.md`, `acceptance-matrix.md`, and `evidence.md`.
