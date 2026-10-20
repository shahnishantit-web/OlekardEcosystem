# Feature Specification: Community Services Platform — Horizontal Foundation (E49)

**Feature Branch**: `049-community-services-horizontal`

**Created**: 2026-10-19

**Status**: Draft

**Source**: OleKard_Horizontal_Module_Detailed_Features v1.0 (17 October 2026)

**Epic path**: `specs/049-community-services-horizontal/`

**Downstream epics**: E50 Community Funeral Assistance · E51 Events & Ticketing · E52 Permitted Raffles & Fundraising

---

## Overview

The Horizontal Foundation provides 14 shared capabilities (HZ01–HZ14) reused across all three OleKard Community Services: Funeral Assistance, Events & Ticketing, and Permitted Raffles & Fundraising. Rather than each service duplicating identity, payments, notifications, audit and reporting, these concerns are implemented once and composed into each service module.

The platform serves multiple organisations (organisers, communities) and multiple consumer participants under a strict tenant-isolation model. All financial operations use the existing OleKard wallet/ledger foundation. All identity and RBAC reuse the existing OleKard authentication foundation.

---

## Roles and Access Model

| Role | Allowed Scope | Key Restrictions |
|---|---|---|
| Platform Admin | Verify organisations; configure platform policy; investigate operations | No unlogged data access; no unilateral release of controlled funds |
| Organiser Admin | Own organisation events, campaigns, staff and reports | No other organiser data; no platform policy changes |
| Community Representative | Assigned community, households and funeral requests | Beneficiary changes and payout approval separated from creation |
| Finance Approver | Review refunds, reconciliation and payouts | Cannot approve own request; scoped financial permissions |
| Consumer / Participant | Own profile, household access, purchases and contributions | No other household financial history or attendee lists |
| Gate Operator | Assigned event and gate; validation result and minimal ticket identity | No wallet, ledger, exports or unrestricted participant search |
| Sponsor / Partner | Approved branding and aggregated reports for linked events | No household records or participant-level data by default |
| Support Officer | Assigned cases and masked operational records | No payment marking; no secret access; no financial approval |

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Scoped Organisation Onboarding (Priority: P1)

A Platform Admin creates and verifies a new organiser. The organiser then becomes discoverable to consumers, can publish offerings and can configure staff access — all within a strict tenant boundary that prevents cross-organiser data leakage.

**Why this priority**: Without verified organisations and tenant isolation, no downstream service (funeral collections, events, raffles) can safely launch. This is the root of the trust model.

**Independent Test**: Register two organisations via admin, approve one, leave one pending. Confirm the approved one appears to consumers; confirm the pending one cannot publish paid offerings; confirm neither can access the other's data via URL or payload manipulation.

**Acceptance Scenarios**:

1. **Given** a new organisation in `draft` state, **When** a Platform Admin submits it for review and approves it, **Then** its status becomes `approved` and consumers can discover it.
2. **Given** an `approved` organisation, **When** its payout destination is changed, **Then** the destination enters a `pending verification` state and cannot be used for payouts until re-verified and separately approved.
3. **Given** a `suspended` organisation, **When** a consumer attempts a new purchase, **Then** the purchase is blocked; existing receipts, refunds and support remain accessible.
4. **Given** two approved organisations A and B, **When** an Organiser Admin of A attempts to access B's data by modifying a URL parameter or request payload, **Then** the server returns 403 with no data from B.

---

### User Story 2 — Common Order and Payment Lifecycle (Priority: P1)

A consumer selects a paid offering (ticket, contribution, raffle entry), reviews the total, pays, and receives an immutable receipt. The same orchestration layer handles double-submission prevention, pending/ambiguous states and late provider callbacks — so each downstream service gets idempotent financial behaviour for free.

**Why this priority**: Every paid service depends on this. Without it, downstream epics cannot safely handle payments.

**Independent Test**: Submit a payment intent, simulate a duplicate callback and a timeout. Confirm only one financial effect is recorded; confirm a timed-out intent does not issue an entitlement.

**Acceptance Scenarios**:

1. **Given** a consumer with an active order, **When** they tap "Pay" twice in quick succession, **Then** only one payment attempt is initiated and one financial posting is created.
2. **Given** a pending payment with unknown provider outcome, **When** the system receives a success callback, **Then** the payment is confirmed, the ledger is posted and the entitlement (ticket/obligation) is issued exactly once.
3. **Given** a confirmed payment, **When** the same provider callback arrives a second time, **Then** the system returns success (idempotent) and does not create a duplicate posting.
4. **Given** a payment attempt that times out, **When** the consumer returns to the app, **Then** the order status reflects `pending` or `failed` — never `paid` — and no entitlement has been issued.
5. **Given** an idempotency key already used for a different payload, **When** a new request arrives with the same key, **Then** the server rejects it with an appropriate conflict error.

---

### User Story 3 — Staff Invitation and MFA Enforcement (Priority: P1)

An Organiser Admin invites a staff member to a scoped role. The invited staff completes registration, is assigned a limited role and must use MFA for administrative actions. Sensitive financial actions require step-up authentication.

**Why this priority**: Admin access control and MFA are security prerequisites for any live service.

**Independent Test**: Invite a Gate Operator role, confirm they can validate tickets but are denied finance and ledger endpoints. Confirm MFA is required for sensitive financial approvals.

**Acceptance Scenarios**:

1. **Given** an Organiser Admin, **When** they invite a new staff member via email, **Then** the invitation is single-use, expires after the configured window and is bound to the intended recipient address.
2. **Given** an expired invitation link, **When** a recipient attempts to accept it, **Then** they receive a clear expiry message and the link cannot be reused.
3. **Given** a Gate Operator account, **When** they attempt to access any finance, ledger or export endpoint, **Then** all such requests return 403.
4. **Given** a Finance Approver initiating a high-value payout, **When** the step-up threshold is met, **Then** they are prompted for re-authentication before the action completes.
5. **Given** a revoked staff member, **When** they attempt any action with their last cached token, **Then** the server rejects the token on the next authorised request.

---

### User Story 4 — Consent Lifecycle and Data Rights (Priority: P2)

A consumer manages their consent preferences, including withdrawing marketing consent, requesting data export and submitting an account deletion request. The platform enforces these preferences across all services and organisers.

**Why this priority**: GDPR/privacy compliance is required before live consumer data is collected at scale.

**Independent Test**: Withdraw marketing consent; confirm no new marketing notifications are sent to that consumer; confirm operational transactional notifications still arrive.

**Acceptance Scenarios**:

1. **Given** a consumer with marketing consent opted in, **When** they withdraw marketing consent, **Then** future marketing notification jobs exclude them and do not expose them to any organiser's marketing.
2. **Given** a consumer who has submitted a deletion request, **When** the request is processed, **Then** only the approved retained records (financial audit, legal holds) remain with restricted access; all other personal data is removed or anonymised.
3. **Given** cross-organiser sponsor sharing, **When** it is attempted without an explicit approved purpose and consumer consent, **Then** the data sharing action is blocked.

---

### User Story 5 — Refund and Dispute Handling (Priority: P2)

A consumer requests a refund on a purchase. A Finance Approver reviews the request, verifies it does not exceed captured value minus prior refunds, approves it, and the compensating ledger entry is posted. A dispute hold prevents unsafe payout during the review period.

**Why this priority**: Refunds are required for event cancellations, collection errors and disputed charges — all common in live community services.

**Independent Test**: Submit two concurrent refund requests for the same order. Confirm only one succeeds and the total refunded does not exceed the captured amount.

**Acceptance Scenarios**:

1. **Given** two concurrent refund requests for the same order totalling more than the captured amount, **When** both are processed simultaneously, **Then** only the allowable amount is refunded; the excess is rejected or held for review.
2. **Given** an approved refund that the provider fails to process, **When** the failure is recorded, **Then** the refund displays as `failed` (not `completed`) and the reserved amount remains in the dispute reserve.
3. **Given** an active dispute hold on an order, **When** a payout request is submitted for the associated funds, **Then** the payout is blocked until the hold is resolved.
4. **Given** a Finance Approver reviewing their own refund request, **When** they attempt self-approval, **Then** the system rejects the approval with an explicit maker-checker violation error.

---

### User Story 6 — Asynchronous Reports and Exports (Priority: P2)

A Platform Admin or Organiser Admin requests a financial report export. The export is generated asynchronously, scoped to their organisation, uses formula-injection-safe CSV, is available via an expiring authenticated link and is audited.

**Why this priority**: Reporting and reconciliation are operational necessities; finance teams need accurate, scoped data without exposing other tenants' records.

**Independent Test**: Generate a report for Organisation A, confirm it contains only A's records; confirm the download link requires authentication; confirm the link expires after the configured window.

**Acceptance Scenarios**:

1. **Given** a report request for a specific cutoff date, **When** the export completes, **Then** the totals match the underlying ledger records for that cutoff.
2. **Given** a CSV export, **When** it contains any cell that begins with `=`, `+`, `-` or `@`, **Then** those characters are escaped or prefixed to neutralise formula injection.
3. **Given** an expired export download link, **When** a user attempts to access it, **Then** they receive a 401/403 and must request a new export.
4. **Given** a report request by an Organiser Admin of Organisation A, **When** the export is generated, **Then** it contains no data from Organisation B.

---

### User Story 7 — Notifications with Outbox Reliability (Priority: P2)

Transactional notifications (payment confirmed, ticket issued, collection reminder) are enqueued via a transactional outbox, retried with backoff, deduplicated and delivered via push, in-app, SMS or email. Notification failure never rolls back a confirmed financial action.

**Why this priority**: Consumers must receive receipts and status updates; unreliable notification cannot block financial confirmations.

**Independent Test**: Confirm a payment, simulate notification delivery failure. Confirm the payment remains confirmed; confirm the notification is retried; confirm no duplicate notification after successful delivery.

**Acceptance Scenarios**:

1. **Given** a confirmed contribution, **When** the push notification service is unavailable, **Then** the contribution remains confirmed and the notification is retried according to the backoff policy.
2. **Given** a duplicate notification job for the same event, **When** it is processed, **Then** the consumer receives at most one notification for that event.
3. **Given** an invalid or expired device token, **When** a push notification is attempted, **Then** the token is retired and no future pushes are attempted to that token.
4. **Given** outstanding contribution reminders, **When** a household has already contributed, **Then** no reminder is sent to that household.

---

### User Story 8 — Audit and Support Case Lifecycle (Priority: P2)

A consumer raises a support case from a transaction or ticket. A Support Officer manages the case with evidence, escalation and service timers. Audit records are append-only and cover all actor, scope, action, target and reason fields. Support staff cannot impersonate users without a separately approved workflow.

**Why this priority**: Support and audit are non-negotiable for financial and community-trust integrity.

**Independent Test**: Create a case, reassign it, resolve it; confirm the full conversation history is preserved; confirm a financial event in the audit log cannot be deleted by the acting operator.

**Acceptance Scenarios**:

1. **Given** a support case linked to a transaction, **When** the case is reassigned and resolved, **Then** the full conversation history and business reference are intact.
2. **Given** an audit record of a financial action, **When** the acting operator attempts to delete or modify it, **Then** the system rejects the attempt with an immutability error.
3. **Given** a Support Officer accessing a case, **When** they view associated transaction details, **Then** sensitive payment data (card numbers, full account numbers) is masked.

---

### User Story 9 — Partner Connector Isolation (Priority: P3)

A second payment or partner adapter is added through configuration and adapter code without modifying core domain logic. Contract tests verify equivalent create/query/callback behaviour. Production enablement requires sandbox certification.

**Why this priority**: The architecture must demonstrate that BTC and any future partner can be integrated without changing core code.

**Independent Test**: Add a stub second adapter; confirm core order/payment domain tests still pass without changes; confirm contract tests for both adapters exercise the same contract.

**Acceptance Scenarios**:

1. **Given** a new partner adapter registered in configuration, **When** an order is processed through it, **Then** the core domain records the same order/payment states as any other adapter.
2. **Given** an unsupported partner capability requested, **When** the system attempts to use it, **Then** the adapter fails explicitly with a documented error rather than silently producing wrong results.
3. **Given** core domain tests, **When** a second adapter is added, **Then** no core domain test file requires modification.

---

### User Story 10 — Approvals, Policy Versioning and Maker-Checker (Priority: P3)

A Platform Admin configures maker-checker thresholds. Payout approvals, high-value refunds and beneficiary destination changes all require independent approval. Policy versions are recorded against the consumer's purchase and changes do not silently alter completed transactions.

**Why this priority**: Financial governance and auditability of policy changes are required for live deployment.

**Independent Test**: Configure a maker-checker threshold; have the creator attempt self-approval; confirm rejection. Approve, then edit the record; confirm it returns to review.

**Acceptance Scenarios**:

1. **Given** a payout request submitted by Finance Approver X, **When** X attempts to approve their own request, **Then** the approval is rejected.
2. **Given** two simultaneous approval attempts for the same request, **When** both arrive concurrently, **Then** at most one execution occurs.
3. **Given** a payout approved and then edited, **When** the edit is saved, **Then** the payout returns to `pending review` and the prior approval is invalidated.
4. **Given** a consumer's purchase with policy version P1, **When** the policy is later updated to P2, **Then** the purchase record retains version P1 and is not silently altered.

---

### Edge Cases

- A consumer switching accounts (logout/login) must find all previously cached private data cleared from device storage.
- A network loss during a payment confirmation must never display the payment as completed before server confirmation is received.
- An upload document must be scanned for malware before any processing or storage in accessible state.
- An organisation's scoped export job must fail rather than return data belonging to another organisation.
- A notification payload must never include bereavement details, financial balances or OTPs.
- An organiser suspended mid-event must preserve existing receipts, refunds and support cases while blocking all new purchases.
- An invitation accepted on a different device from the one it was sent to must succeed (link follows the person, not the device).

---

## Requirements *(mandatory)*

### Functional Requirements

#### HZ01 — Identity and Secure Access
- **FR-HZ01-001**: The system MUST reuse the existing OleKard identity and RBAC foundation; no separate credential store may be created for community services.
- **FR-HZ01-002**: Administrative users MUST be enrolled in MFA; step-up authentication is required for sensitive financial actions at or above the configured threshold.
- **FR-HZ01-003**: Disabled or revoked accounts MUST lose access on the next server-side token validation; cached permissions MUST NOT persist past revocation.
- **FR-HZ01-004**: A Gate Operator role MUST be denied all finance, ledger and unrestricted participant-search endpoints at the API layer.
- **FR-HZ01-005**: All privileged activity (role assignments, revocations, elevated access) MUST be written to the append-only audit log.

#### HZ02 — Organisation Onboarding and Verification
- **FR-HZ02-001**: Organisation lifecycle states MUST be: `draft → submitted → under_review → changes_requested → approved → suspended → archived`; `rejected` and `cancelled` are explicit terminal states.
- **FR-HZ02-002**: An organisation in any state other than `approved` MUST NOT be able to publish a paid offering.
- **FR-HZ02-003**: Verification evidence (registration documents, identity records) MUST be stored as private, access-controlled files served through expiring authenticated links.
- **FR-HZ02-004**: Changing an organisation's payout destination MUST trigger a separate verification and approval workflow and MUST NOT become active until that workflow completes.
- **FR-HZ02-005**: Suspending an organisation MUST block new purchases while preserving consumer access to existing receipts, refunds and support cases.

#### HZ03 — Membership and Tenant Context
- **FR-HZ03-001**: Membership records MUST carry a distinct membership ID separate from person identity.
- **FR-HZ03-002**: Staff invitations MUST be single-use, expiring and bound to the intended recipient's identifier; expired invitations MUST fail safely.
- **FR-HZ03-003**: A membership in Organisation/Community A MUST grant zero access to Organisation/Community B data.
- **FR-HZ03-004**: Switching community or organisation context in a session MUST clear all previously cached private data for the prior context.
- **FR-HZ03-005**: Leaving a community MUST preserve historical contribution, receipt and audit records for that membership.

#### HZ04 — Consent and Participant Preferences
- **FR-HZ04-001**: Consent notices MUST be versioned; every consent capture MUST record notice version, timestamp, actor and capture source.
- **FR-HZ04-002**: Operational/transactional notification consent and marketing consent MUST be stored as separate purposes.
- **FR-HZ04-003**: Withdrawal of marketing consent MUST synchronously stop future marketing notification jobs for that consumer across all organisers.
- **FR-HZ04-004**: Cross-organiser data sharing MUST require an explicit approved purpose and, where required by applicable law, explicit consumer consent.
- **FR-HZ04-005**: Data deletion requests MUST remove or anonymise all personal data except records under an approved lawful retention exception; retained records MUST have restricted access.

#### HZ05 — Configurable Service Catalogue and Discovery
- **FR-HZ05-001**: Service capabilities (including raffle/fundraising) MUST be enabled per organisation and per jurisdiction; feature-flag disablement MUST be enforced at both UI and API layers.
- **FR-HZ05-002**: Consumers MUST see only approved public offerings and the private collections/communities they are entitled to view.
- **FR-HZ05-003**: Funeral/bereavement collection records MUST remain membership-restricted by default; a public campaign requires an explicit approved policy override.
- **FR-HZ05-004**: Category, copy, media and pricing changes MUST support effective-dated versioning so historical purchases reference the version active at time of purchase.

#### HZ06 — Common Order and Payment Orchestration
- **FR-HZ06-001**: Order, payment, entitlement and payout states MUST be maintained as separate state machines; a payment state change does NOT automatically transition entitlement state.
- **FR-HZ06-002**: All monetary totals MUST be computed server-side in integer minor units (e.g. thebe for BWP); floating-point arithmetic is prohibited for financial calculations.
- **FR-HZ06-003**: Every state-changing financial API MUST accept an `Idempotency-Key`; a repeated request with the same key and same payload MUST return the original result without re-executing; a repeated request with the same key but different payload MUST be rejected with a conflict error.
- **FR-HZ06-004**: Provider callback ambiguity (unknown, timeout, partial) MUST leave the order in `pending` state; the system MUST NOT treat an unknown outcome as `paid` or `failed`.
- **FR-HZ06-005**: No raw card, bank account or payment credential data MUST be stored at rest in the platform; tokenised or provider-held references only.

#### HZ07 — Receipts and Transaction History
- **FR-HZ07-001**: A receipt MUST be immutable once issued; it MUST contain: reference, timestamp (UTC), purpose, currency, amount (minor units), payer identity reference and provider reference.
- **FR-HZ07-002**: Reversals and refunds MUST append new ledger entries linked to the original; the original posting MUST NOT be edited.
- **FR-HZ07-003**: Receipt retrieval MUST be scoped to the authenticated consumer's own records; guessing or enumerating another consumer's receipt by reference MUST return 404 or 403.
- **FR-HZ07-004**: Admin export of receipts MUST apply field masking (e.g., partial card/account numbers), require explicit scope authorisation and generate an audit log entry for every export.

#### HZ08 — Refunds, Reversals and Dispute Handling
- **FR-HZ08-001**: The system MUST enforce that cumulative approved refunds for an order never exceed the captured payment value minus prior processed refunds; concurrent excess requests MUST be serialised and the overflow rejected.
- **FR-HZ08-002**: Refund operations MUST be idempotent and use compensating double-entry ledger postings.
- **FR-HZ08-003**: An active dispute hold MUST block any payout from the affected funds until the hold is resolved by an authorised approver.
- **FR-HZ08-004**: A failed refund MUST display status `failed` and MUST NOT display as `completed`; the reserved amount MUST remain in the dispute reserve or follow an approved exception workflow.
- **FR-HZ08-005**: Refund policies MUST be consumer-visible before payment (pre-purchase disclosure).

#### HZ09 — Notifications and Communication
- **FR-HZ09-001**: All transactional notifications MUST be dispatched via a durable transactional outbox with at-least-once delivery, retry with exponential backoff and a dead-letter queue for failures exceeding retry limits.
- **FR-HZ09-002**: Notification jobs MUST be deduplicated by event ID and delivery channel to prevent consumer spam.
- **FR-HZ09-003**: Notification success or failure MUST NOT affect the confirmed state of a financial transaction or entitlement.
- **FR-HZ09-004**: Notification payloads MUST NOT include bereavement details, financial balances, OTPs, access tokens or any sensitive personal data beyond the minimum needed to route the consumer to the relevant in-app record.
- **FR-HZ09-005**: Invalid or permanently failed device tokens MUST be retired and MUST NOT be retried after permanent failure is confirmed.

#### HZ10 — Dashboards, Reports and Exports
- **FR-HZ10-001**: All financial dashboard figures MUST derive from authoritative ledger-linked records with a displayed last-refresh timestamp.
- **FR-HZ10-002**: Gross, refunds, fees, net, liabilities, attendance and disbursements MUST be reported as separate line items; no two figures may be silently combined.
- **FR-HZ10-003**: Multi-currency totals MUST NOT be combined without explicit approved exchange rates and methodology.
- **FR-HZ10-004**: Report exports MUST be generated asynchronously, scoped to the requesting user's organisation, delivered via expiring authenticated download links and logged as an audit event.
- **FR-HZ10-005**: CSV exports MUST neutralise formula injection by escaping or prefixing cells that begin with `=`, `+`, `-` or `@`.

#### HZ11 — Support, Moderation and Audit
- **FR-HZ11-001**: Every audit record MUST capture: actor ID, organisation scope, action type, target entity ID/type, before-state summary, after-state summary, reason, UTC timestamp and correlation ID; secrets and raw credentials MUST NOT appear in audit records.
- **FR-HZ11-002**: Audit records MUST be append-only; no update or delete operation on audit records is permitted regardless of actor role.
- **FR-HZ11-003**: Uploaded support evidence documents MUST be scanned for malware before being made accessible; documents MUST be served through access-controlled expiring links.
- **FR-HZ11-004**: Support Officers MUST NOT be able to impersonate a consumer without a separately approved and audited elevated-access workflow.
- **FR-HZ11-005**: Case records MUST retain their business reference and full conversation thread history.

#### HZ12 — Partner Connectors and Configuration
- **FR-HZ12-001**: Partner/provider-specific logic (endpoints, field mapping, authentication protocol) MUST reside entirely within an adapter layer; the core order/payment domain MUST NOT contain partner-specific code.
- **FR-HZ12-002**: All partner credentials and secrets MUST be stored in a managed secret store; they MUST NOT be present in configuration files, environment variables checked into version control, or API responses.
- **FR-HZ12-003**: A partner adapter MUST be deployable and removable through configuration without modifying core domain code.
- **FR-HZ12-004**: Webhook payloads from partners MUST have their signatures verified against the raw request body, timestamp freshness and configured partner secret before any processing occurs.
- **FR-HZ12-005**: Webhook deduplication MUST use the provider's event ID; out-of-order events MUST be reconciled against the provider authoritative state before accepting state transitions.

#### HZ13 — Approvals and Policy Versioning
- **FR-HZ13-001**: All high-risk financial actions (beneficiary payout, high-value refund, beneficiary destination change) MUST require independent maker-checker approval; self-approval MUST be rejected.
- **FR-HZ13-002**: Concurrent approval attempts for the same request MUST be serialised; at most one execution MUST result.
- **FR-HZ13-003**: Editing an approved payout or refund request after approval MUST automatically return it to `pending_review` and invalidate the prior approval.
- **FR-HZ13-004**: Policy version changes MUST be recorded with version ID, effective date, author and reason; the version accepted at the time of a consumer's purchase MUST remain immutably linked to that purchase record.
- **FR-HZ13-005**: Emergency suspension actions MUST record a reason, generate an audit event and carry an expiry timestamp or a mandatory follow-up review date.

#### HZ14 — Shared Mobile and Admin Usability
- **FR-HZ14-001**: The consumer mobile app MUST provide bottom navigation with: Home, Community, Events, My Tickets, Activity and More; all navigation items MUST have accessible labels for screen readers.
- **FR-HZ14-002**: All admin forms MUST validate inputs on both client and server; server validation is authoritative.
- **FR-HZ14-003**: Money amounts MUST be displayed using locale-aware formatting consistent with the currency's standard decimal and grouping conventions; timezone-aware display is required for all event dates and deadlines.
- **FR-HZ14-004**: Network loss MUST never cause the UI to display a completed transaction, issued ticket or fulfilled obligation that has not been confirmed by the server.
- **FR-HZ14-005**: Logging out or switching authenticated user accounts MUST clear all private cached data (receipts, household records, tickets) from device storage.
- **FR-HZ14-006**: Core user journeys (payment, contribution, ticket presentation) MUST pass WCAG 2.2 AA keyboard and screen-reader checks.

---

### Key Entities

- **Organisation**: Legal or community entity; type (charity, event organiser, community group); verification state; payout destination reference; operating countries/jurisdictions; linked staff memberships.
- **StaffMembership**: Association of a person to an organisation with a scoped role, optional event/community scope, effective dates and revocation state; distinct from consumer profile.
- **StaffInvitation**: Single-use expiring token linking an organisation, intended recipient identifier, role and expiry; consumed on acceptance.
- **ConsentRecord**: Links person, notice (versioned), purpose, capture source, timestamp and optional withdrawal event; append-only.
- **ServiceCatalogue**: Per-organisation, per-jurisdiction enablement flags with effective-dated versions for each service type (funeral, events, raffles).
- **Order**: Purpose type, purpose ID, consumer, organisation scope, currency, line items (integer minor units), fees, idempotency key, request hash, hold expiry, state machine.
- **PaymentAttempt**: Links order, provider, external reference, amount, currency, state, timestamps; append-only; no raw card data.
- **Entitlement**: Abstract type extended by Ticket, ContributionObligation, RaffleEntry; links to order line; state machine; issuance idempotency key.
- **LedgerPosting**: Immutable double-entry record; debit account, credit account, amount (integer minor units), currency, reference, reversal link if applicable.
- **Receipt**: Immutable record linked to confirmed LedgerPosting; visible to the paying consumer.
- **RefundRequest**: Links order, requested amount, reason, requester, approver (separate from requester), state, compensating posting link.
- **DisputeHold**: Links order/payment, hold amount, reason, raised by, resolved by, resolution state.
- **NotificationJob**: Template ID + version, locale, recipient scope, deduplication key, delivery channel, outbox state, attempt log.
- **AuditEntry**: Append-only; actor, organisation scope, action, target entity, before summary, after summary, reason, UTC timestamp, correlation ID.
- **SupportCase**: Business reference, consumer, organisation scope, status, conversation thread (append-only), evidence attachments (access-controlled).
- **PartnerConnector**: Partner ID, adapter version, organisation mapping, capability manifest, credential reference (vault pointer), webhook config, sandbox flag, health state.
- **ApprovalRequest**: Type (payout/refund/destination-change/suspension), subject entity ID, submitter, assigned approver(s), state, policy version, serialisation lock.
- **PolicyVersion**: Policy type, version ID, effective date, author, reason, content hash; linked to purchases via accepted-version reference.
- **WebhookReceipt**: Raw payload hash, partner ID, provider event ID, received timestamp, processing state; deduplication anchor.

---

## Integration Contracts

| Boundary | Required Behaviour |
|---|---|
| Identity & RBAC (E01/E04) | Verify caller token, resolve membership scope and role, check session status, validate step-up evidence — no trust in client-provided role or tenant claims |
| Wallet / Ledger (E06/E07) | Initiate payment intent; query status; confirm capture; initiate refund; reserve funds; release reserve; initiate payout; query payout — integer minor units; idempotency key required for all mutations |
| Partner Adapters | Capability discovery; map external org/event identifiers; exchange approved event/sponsor data; export agreed aggregates; all unsupported capabilities must fail explicitly |
| Notifications (E17) | Enqueue via transactional outbox with template ID, version, locale, recipient reference, purpose, deduplication key; receive delivery outcome asynchronously |
| File Storage | Malware scan on upload; size/type validation; private-by-default ACL; access via expiring authenticated download URLs only |

### Internal Domain Events (published to event bus)

| Event | Payload minimum |
|---|---|
| `Payment.Confirmed` | event_id, schema_version, order_id, organisation_id, amount, currency, timestamp, correlation_id |
| `Payment.Reversed` | event_id, schema_version, order_id, organisation_id, reversal_amount, currency, timestamp, correlation_id |
| `Contribution.Recorded` | event_id, schema_version, obligation_id, household_id, collection_id, organisation_id, amount, currency, timestamp |
| `Ticket.Issued` | event_id, schema_version, entitlement_id, order_id, event_id (scoped), organisation_id, timestamp |
| `Ticket.Admitted` | event_id, schema_version, entitlement_id, gate_id, operator_id, timestamp, online_flag |
| `Collection.Closed` | event_id, schema_version, collection_id, community_id, organisation_id, timestamp |
| `Payout.Settled` | event_id, schema_version, payout_id, organisation_id, beneficiary_ref, amount, currency, timestamp |
| `Campaign.DrawCompleted` | event_id, schema_version, campaign_id, organisation_id, draw_ref, timestamp |

All events carry: `event_id`, `schema_version`, `occurred_at` (UTC ISO-8601), `aggregate_id`, `organisation_scope`, `correlation_id`, `causation_id`. Consumers MUST tolerate duplicates and replay without duplicate financial or entitlement effects.

---

## Data Invariants

- Every domain record MUST carry an enforceable `organisation_id` or `community_id` scope column; queries MUST filter by scope, not just display by scope.
- Monetary amounts MUST be stored as `bigint` (integer minor units) with an explicit `currency` column; no `decimal`/`float` for financial values.
- All primary keys are UUID v7 (time-sortable, opaque to clients).
- All timestamps stored as UTC; business timezone stored separately where display context requires it.
- Pagination tokens MUST be stable under concurrent inserts/updates (cursor-based, not offset-based for financial lists).
- Uploaded files: private by default; malware-scanned before accessible; size-limited (define per-type in configuration); type-validated; served exclusively via authorised expiring URLs.
- PII MUST be minimised in application logs, metrics and analytics exports; reference IDs used in traces, not personal details.

---

## Non-Functional Requirements

- **NFR-001 Availability**: 99.9% monthly uptime for core APIs and admin/mobile entry points, measured independently of declared maintenance windows.
- **NFR-002 Latency**: p95 server response ≤ 1 s for non-report read endpoints; ≤ 2 s for non-provider write endpoints at the agreed load.
- **NFR-003 Scale baseline**: Validate against 100,000 registered consumers, 1,000 organisations, 500 concurrent interactive users, 100 payment initiations/s and 100 scan requests/s (provider calls simulated; separate provider certification required).
- **NFR-004 Concurrency correctness**: Zero oversold inventory, duplicate household fulfilments, duplicate online admissions, duplicate ledger postings or duplicate payout effects under concurrent stress and retry tests.
- **NFR-005 Accessibility**: Core admin and mobile user journeys MUST meet WCAG 2.2 AA for keyboard navigation, focus management, screen reader labelling, colour contrast and motion preferences.
- **NFR-006 Recovery**: Proposed RPO ≤ 15 minutes, RTO ≤ 4 hours for platform recovery; recovery procedures include provider reconciliation and prevention of replayed external financial effects.
- **NFR-007 Security**: Threat model must cover tenant isolation, account takeover, duplicate admissions, payment spoofing, ticket theft, malicious uploads and internal abuse; controls include least privilege, MFA, safe session lifecycle, CSRF protection, input validation, parameterised queries, output escaping, rate limits, secure response headers and transport encryption.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An Organiser Admin cannot retrieve any data belonging to another organisation by any combination of URL manipulation, payload injection or API enumeration — validated by a suite of negative tenant-isolation tests.
- **SC-002**: Under a concurrency test submitting the same payment intent 10 times simultaneously, exactly one financial posting and exactly one receipt are created. Entitlement issuance (Ticket, ContributionObligation, RaffleEntry) is the responsibility of the first downstream epic that implements a concrete Entitlement subtype and is validated there.
- **SC-003**: Under a concurrency test submitting two refund requests simultaneously that together exceed the captured amount, the total refunded amount never exceeds the captured value.
- **SC-004**: A Gate Operator account is unable to reach any finance or ledger endpoint, confirmed by automated API permission tests.
- **SC-005**: 100% of transactional notifications are dispatched via the outbox; delivery failure does not roll back a single confirmed payment in smoke-test validation.
- **SC-006**: CSV exports produced by the system contain no unescaped formula-injection characters (`=`, `+`, `-`, `@` at cell start), validated by automated export tests.
- **SC-007**: Core payment, contribution and ticket journeys complete at p95 within 2 seconds end-to-end on the agreed test environment load profile.
- **SC-008**: Core admin and mobile journeys pass WCAG 2.2 AA automated checks and a manual screen-reader review.
- **SC-009**: Adding a second partner adapter (stub) requires zero changes to core domain files, confirmed by a contract-test run where the domain test suite passes without modification.

---

## Assumptions

- The existing OleKard Laravel/PHP backend, PostgreSQL database, Redis/Horizon queue and Next.js admin application are the implementation substrate; no new technology choices are introduced.
- The existing E01 identity, E04 RBAC, E06 wallet, E07 ledger and E17 notifications modules provide the required foundation contracts; this epic adds community-services-specific domain modules on top of them.
- The `community_services` PostgreSQL schema namespace is used for all tables introduced by E49; this maintains schema-per-module isolation consistent with the existing codebase.
- Initial currency support is BWP (Botswana Pula); multi-currency expansion is handled by E29 (Multi-Currency Wallet) and does not need to be re-architected here.
- Raffle features (E52) are delivered disabled by default at the platform level; the service catalogue feature flags (HZ05) enforce this at both UI and API.
- The "BTC" partner referenced in the source document is treated as one possible organiser, sponsor or vendor configured through the partner connector framework (HZ12); no BTC-specific logic is embedded in core modules.
- Statutory data retention periods for bereavement records, financial records and audit logs are NOT invented here; defaults remain at "no automatic purge" until business/legal owners confirm durations.
- Offline gate admission (EV09) is scoped to E51 (Events); this epic delivers the online scan path and the shared admission infrastructure only.
- WCAG 2.2 AA automated tooling (axe-core or equivalent) is available in the CI pipeline.
- PHPStan level 8 is the static analysis baseline for all new PHP code, consistent with existing epics.
- The `Entitlement` abstract entity and its concrete subtypes (Ticket for E51, ContributionObligation for E50, RaffleEntry for E52) are implemented by their respective downstream epics. E49 creates the `Order` and issues the `Receipt` but does not manage entitlement records; E50/E51/E52 each implement entitlement issuance using the E49 order and outbox infrastructure as the foundation.
