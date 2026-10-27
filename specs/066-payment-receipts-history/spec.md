# Feature Specification: P19 — Receipts, Communication and Payment History APIs

**Feature Branch**: `066-payment-receipts-history`

**Created**: 2026-10-25

**Status**: Draft

**Source references**: FD11, LC15, MB07 (OleKard Shared Payment Architecture v1.1)

**Required predecessors**: P08 (Ledger Acknowledgement), P11 (Outbox/Inbox Delivery), P15 (Shared Refund Engine)

---

## Overview

P19 provides consumers, agents and administrators with a scoped, verifiable view of their payment activity and associated communications. It builds on the existing notification delivery (P11), ledger acknowledgement (P08) and refund tracking (P15) infrastructure — no new financial state is created. The module delivers:

- A **payment history API** showing original amounts, fees, refund status and domain references per payer/organisation
- **Transactional receipt notifications** triggered from confirmed payment events (idempotent, deduplicated)
- **Authorised receipt downloads** via time-limited, audited expiry links
- **Sanitised scoped exports** safe for spreadsheet consumption

Notification delivery failure never reverses a completed payment. Marketing consent is maintained separately from transactional communications.

---

## User Scenarios & Testing

### User Story 1 — Scoped Payment History Retrieval (Priority: P1)

A consumer, agent or admin operator queries their own (or their authorised scope of) payment transactions. Each record shows the original financial facts (amount, currency, fee snapshot, refund state) together with the business domain context (purpose type, domain reference, date, provider label). Pending refunds are clearly labelled as such; neither partial outcomes nor another payer's records are ever exposed.

**Why this priority**: The most fundamental consumer need after any payment is knowing what happened, how much was charged and what the current status of refunds is. Without this, all other features are unverifiable.

**Independent Test**: Call `GET /v1/payment/history` authenticated as Consumer A; assert only Consumer A's payment intents appear. Attempt the same call with Consumer B's ID; assert 403 or empty result. Verify that confirmed refunds show `refund_status = confirmed` and in-flight refunds show `refund_status = pending`.

**Acceptance Scenarios**:

1. **Given** Consumer A has completed three payments, **When** they query their payment history, **Then** all three records are returned with: original amount, currency, fee amount, provider label, purpose type, domain reference, payment date, payment status, and current refund status.
2. **Given** Consumer A has a pending refund on one payment, **When** they view history, **Then** that record shows `refund_status = pending` and the reserved refund amount — it does NOT show as already-credited.
3. **Given** Consumer A has a confirmed refund, **When** they view history, **Then** the refund amount and confirmation date appear; net amount reflects original minus refund.
4. **Given** Consumer B attempts to retrieve Consumer A's payment record by guessing its reference ID, **When** the API processes the request, **Then** it returns 404 or 403 — consumer A's records are never visible to consumer B through any reference pattern.
5. **Given** an admin operator with `payment.history.read.organisation` scope, **When** they query history filtered by organisation, **Then** only records belonging to that organisation appear; cross-organisation leakage is absent.
6. **Given** a payment intent has a pending or unknown provider status, **When** it appears in history, **Then** its status is shown as `processing` — it is not presented as confirmed or failed until resolved.

---

### User Story 2 — Receipt Retrieval and Authorised Download (Priority: P1)

A consumer retrieves a specific payment receipt showing all original financial details. They can request an authorised expiring download link for a PDF/structured receipt. The receipt is scoped to the authenticated user and does not expose another payer's data. Domain-specific tax invoices or entitlement certificates remain the responsibility of the domain module — P19 produces only the payment receipt.

**Why this priority**: Regulatory and practical requirement: consumers need a retrievable record of their payment as evidence of transaction. Download links must expire to prevent sharing of another party's financial data.

**Independent Test**: Call `GET /v1/payment/receipts/{intentId}` as the payer; assert receipt contains exact amounts from the ledger. Request a download token; use it within the expiry window to download; attempt to reuse after expiry and assert 410 Gone or 404.

**Acceptance Scenarios**:

1. **Given** a confirmed payment intent, **When** the payer calls `GET /v1/payment/receipts/{intentId}`, **Then** they receive: payment reference, provider label, original amount, currency, fee amount, net amount, date/time, purpose description (not full domain data), and refund summary.
2. **Given** a receipt request by a non-payer user, **When** the API processes the request, **Then** it returns 403 — the receipt is never served to any user other than the authenticated payer or an authorised admin reviewer.
3. **Given** the payer requests a downloadable receipt, **When** a download token is issued, **Then** the token expires after the configured window (default: 15 minutes) and can only be redeemed once.
4. **Given** a download token has expired, **When** a request is made with it, **Then** the API returns 410 Gone.
5. **Given** the receipt includes a pending refund, **When** it is downloaded, **Then** the refund section shows `Pending — amount reserved, not yet credited` rather than presenting it as a completed return.
6. **Given** a receipt is generated for a payment, **Then** the receipt data is derived from the ledger journal entries and fee snapshots — it is never computed from mutable fields that could diverge from the financial truth.

---

### User Story 3 — Transactional Receipt Notifications (Priority: P1)

Upon successful payment confirmation, the system sends a transactional receipt notification to the payer via the notification channel they have configured (push, SMS, email). The notification uses a versioned template, is triggered from the confirmed payment event via the existing outbox/delivery infrastructure (P11), and is deduplicated so duplicate events produce at most one notification per payment. Delivery failure does not reverse the payment. Marketing consent is not required for transactional notifications.

**Why this priority**: Transactional confirmation is an explicit regulatory requirement and consumer trust baseline. The architecture mandates notification failure must not undo financial finality.

**Independent Test**: Trigger a `payment.confirmed` event; assert exactly one notification delivery attempt per channel. Trigger the same event again (simulating replay); assert no second notification is sent. Simulate delivery failure; assert payment status remains `confirmed`.

**Acceptance Scenarios**:

1. **Given** a `payment.confirmed` event is published via the outbox, **When** the notification handler processes it, **Then** a receipt notification is dispatched to the payer's configured channel(s) with: amount, currency, provider label, reference and domain purpose summary.
2. **Given** the same `payment.confirmed` event is replayed (e.g., outbox retry), **When** the handler processes the duplicate, **Then** no second notification is sent — deduplication is enforced by event idempotency key.
3. **Given** the notification delivery service is temporarily unavailable, **When** it recovers, **Then** the undelivered receipt notification is retried through the existing delivery infrastructure; the payment status is unchanged throughout.
4. **Given** a payer has opted out of marketing communications, **When** they complete a payment, **Then** they still receive the transactional receipt notification — marketing opt-out does not suppress transactional notices.
5. **Given** a notification template is updated to a new version, **When** new payments are confirmed, **Then** the new template version is used; existing sent notifications retain the version they were sent with for audit purposes.
6. **Given** a refund is confirmed, **When** the `refund.confirmed` event is processed, **Then** a separate refund confirmation notification is dispatched to the payer; delivery failure does not reverse the refund.

---

### User Story 4 — Sanitised Scoped Exports (Priority: P2)

Finance and operations teams can request a scoped payment history export. The export is filtered by organisation, module, currency and date range and is bounded by the requester's authorised scope. Download access requires an authenticated expiring token. The export is spreadsheet-formula-safe (no leading `+`, `-`, `=`, `@` in unquoted fields) and labelled `provisional` when transactions are still pending reconciliation. Each export request is audited.

**Why this priority**: Needed for financial reporting, reconciliation and audit — but secondary to consumer receipt access and notification delivery.

**Independent Test**: Request an export as an organisation finance role; assert only that organisation's records appear. Confirm the downloaded CSV has no formula-injectable values in amount columns. Attempt download after token expiry; assert 410.

**Acceptance Scenarios**:

1. **Given** a finance operator requests a payment export for their organisation, **When** the export is generated, **Then** it contains: payment reference, date, amount, currency, fee, refund amount, net, provider, purpose type, domain reference, status — scoped to their organisation only.
2. **Given** the export includes transactions with unresolved reconciliation exceptions, **When** it is downloaded, **Then** those rows are labelled `provisional` and the file header includes a notice: "This report contains unreconciled transactions."
3. **Given** any text field in the export could begin with `=`, `+`, `-` or `@`, **When** the file is generated, **Then** those values are prefixed with a single quote or tab character to prevent formula injection in spreadsheet applications.
4. **Given** an export download token is issued, **When** the token is used, **Then** the access event is recorded in the audit log with: requester, scope applied, row count, export timestamp.
5. **Given** an export token expires, **When** a late download attempt is made, **Then** the API returns 410 Gone and the audit log records the failed attempt.
6. **Given** a platform finance operator requests a cross-organisation export, **When** they make the request, **Then** the system checks their `payment.export.read.platform` permission; users without this permission only see their own organisation's data.

---

### User Story 5 — Domain Deep Links and Mobile Activity (Priority: P2)

The mobile activity screen shows a consumer's payment history with purpose filters and domain-specific deep links. Each payment record shows the provider label, amount, fees, date, reference, refund status and a link to the business domain record (e.g., the funeral contribution, event ticket or raffle entry). Domain screens determine whether a refund can be requested — P19 does not initiate refunds, it only provides the status and domain navigation reference.

**Why this priority**: Consumer-facing mobile requirement from MB07; depends on US1 history API being available first.

**Independent Test**: Request history filtered by `purpose_type = funeral_assistance`; assert only records with that purpose type are returned. Assert each record includes a `domain_deep_link` field pointing to the domain record using the original `purpose_reference`. Assert the field does not expose another domain's data.

**Acceptance Scenarios**:

1. **Given** a consumer has payments across two domains (funeral assistance and events), **When** they filter history by `purpose_type = funeral_assistance`, **Then** only funeral assistance payments appear.
2. **Given** a payment record, **When** it is returned in the history API, **Then** it includes a `domain_deep_link` containing the opaque domain reference (e.g., `funeral_assistance://contribution/{purpose_reference}`), enabling the mobile app to navigate to the domain screen.
3. **Given** the domain module has been retired or its deep link is no longer valid, **When** the consumer views the payment, **Then** the financial record remains accessible; the `domain_deep_link` is marked `unavailable` rather than causing an error.
4. **Given** a refund is available for a payment, **When** the consumer views the record in mobile, **Then** the `refund_eligible` field is `true` and the domain deep link points to the domain-owned refund initiation screen; P19 does not initiate the refund directly.

---

### Edge Cases

- Consumer queries history before any payments exist → empty paginated list, not an error.
- Receipt download token redeemed after expiry → 410 Gone; re-requesting generates a fresh token.
- `payment.confirmed` event arrives out of order (before intent is visible in read DB) → history query returns the intent with status `processing` until consistent; read DB catch-up is not a financial error.
- Duplicate `payment.confirmed` events replayed for the same `idempotency_key` → at most one notification sent; idempotency enforced by existing P11 deduplication infrastructure.
- Payment intent in `pending` state (provider outcome unknown) → history shows `processing`; user is NOT told "failed" or "succeeded" until resolved.
- Export request for a date range spanning an ongoing reconciliation → rows with unresolved exceptions labelled `provisional`; export is downloadable.
- Consumer requests a receipt for a payment that failed before capture → 404; failed payment intents are not exposed as receipts.
- Account number or bank credential appears in any exportable field → must be redacted/masked; full account numbers never appear in exports.
- Marketing opt-out → transactional receipt notification is still sent; marketing opt-out does not suppress transactional communications.
- Receipt for a payout (P18) → payout receipts are out of scope for P19; payout operators use P18 endpoints.

---

## Requirements

### Functional Requirements

**History API**

- **FR-001**: The system MUST provide a paginated payment history API that returns records scoped to the authenticated payer's identity — records belonging to another payer are never returned.
- **FR-002**: Each history record MUST include: payment reference, purpose type, domain reference, provider label, original amount, currency code, fee amount, total charged amount (original + fee), payment date/time (UTC), current payment status, and current refund status.
- **FR-003**: Payment status values in the history MUST use the approved safe set: `processing`, `confirmed`, `failed`, `cancelled`, `refund_pending`, `refunded`; unknown provider outcomes MUST remain as `processing`, never prematurely resolved.
- **FR-004**: The history API MUST support filters: `purpose_type`, `status`, `date_from`, `date_to`, `currency_code`, and `per_page` (default 20, max 100).
- **FR-005**: Admin operators with `payment.history.read.organisation` permission MUST be able to query payment history scoped to a specific organisation; platform finance with `payment.history.read.platform` MUST be able to query across organisations.
- **FR-006**: Pending refunds MUST be displayed as `refund_status = pending` with the reserved refund amount; they MUST NOT be shown as confirmed credits until the refund is settled.
- **FR-007**: The history API MUST filter records by `purpose_type` to support domain-scoped mobile activity views.

**Receipt Retrieval and Download**

- **FR-008**: The system MUST provide a receipt endpoint `GET /v1/payment/receipts/{intentId}` accessible only by the authenticated payer or an authorised admin reviewer.
- **FR-009**: Receipt data MUST be derived from the immutable ledger journal entries and fee snapshots — never from mutable intent/attempt fields.
- **FR-010**: The system MUST provide a receipt download token endpoint that issues a single-use, time-limited (configurable; default 15 minutes) token for downloading a structured receipt.
- **FR-011**: Download tokens MUST expire after the configured window; expired tokens MUST return 410 Gone.
- **FR-012**: Full account numbers, bank credentials and private provider secrets MUST NOT appear in any receipt, history response or export.

**Transactional Receipt Notifications**

- **FR-013**: The system MUST subscribe to `payment.confirmed` events and dispatch a transactional receipt notification to the payer's configured channel(s) using a versioned notification template.
- **FR-014**: Receipt notification dispatch MUST be idempotent: replaying the same `payment.confirmed` event (same idempotency key) MUST NOT produce a second notification.
- **FR-015**: Notification delivery failure MUST NOT alter the payment status; failed delivery attempts are retried through the existing outbox/inbox delivery infrastructure.
- **FR-016**: Transactional receipt notifications MUST be sent regardless of marketing consent settings; they are classified as transactional, not promotional.
- **FR-017**: When a refund is confirmed (`refund.confirmed`), the system MUST dispatch a refund confirmation notification using a versioned template; delivery failure does not reverse the refund.
- **FR-018**: All sent notifications MUST record the template version used at send time for audit purposes.

**Scoped Exports**

- **FR-019**: Finance operators MUST be able to request a scoped payment export filtered by: organisation, module/purpose_type, currency, date range, and status.
- **FR-020**: Each export download MUST require an authenticated, single-use, expiring access token; the access event MUST be recorded in the audit log.
- **FR-021**: Export files MUST be spreadsheet-formula-safe: any value beginning with `=`, `+`, `-` or `@` in a text field MUST be sanitised to prevent formula injection.
- **FR-022**: Export files containing transactions with unresolved reconciliation exceptions MUST include a `provisional` label per affected row and a document header notice.
- **FR-023**: Cross-organisation exports MUST require `payment.export.read.platform` permission; organisation-scoped exports require `payment.export.read.organisation`.

**Domain Deep Links**

- **FR-024**: Each payment history record MUST include a `domain_deep_link` field containing the opaque purpose reference formatted for navigation by the business domain's mobile client.
- **FR-025**: If a domain module's deep link is no longer valid, the `domain_deep_link` field MUST be marked `unavailable`; the payment record remains accessible.
- **FR-026**: The `domain_deep_link` MUST NOT be constructed from user-supplied input; it is derived server-side from the validated `purpose_reference` stored at intent creation.

### Key Entities

- **PaymentHistoryRecord**: Read model aggregating PaymentIntent + FeeSnapshot + RefundRequest state + domain reference; not a new DB table — derived from existing tables.
- **ReceiptRecord**: Immutable view over ledger journal entries and fee snapshots for one confirmed PaymentIntent; cannot be edited after posting.
- **ReceiptDownloadToken**: Single-use expiring token (UUID v7), TTL configurable, linked to `payment_intent_id` and `requestor_id`; stored in payment schema.
- **PaymentExportRequest**: Audit record of export request: requester, scope, filters, row count, generated_at, token_expires_at, downloaded_at.
- **ReceiptNotificationDelivery**: Tracks outbox event → notification mapping for deduplication; stores event_idempotency_key to prevent duplicate sends.

---

## Success Criteria

### Measurable Outcomes

- **SC-001**: Payment history for a given payer is fully retrieved in under 500ms for up to 500 records with standard pagination.
- **SC-002**: Receipt retrieval for a confirmed payment returns accurate financial data that reconciles to ledger journal totals — zero discrepancies in test scenarios covering 100 varied transactions.
- **SC-003**: Receipt download tokens issued via the API expire after the configured window and are not reusable; 100% of expired-token requests correctly return 410.
- **SC-004**: A consumer cannot retrieve another payer's payment history or receipt by guessing a reference — verified by security test scenarios across all history/receipt endpoints.
- **SC-005**: Duplicate `payment.confirmed` events (same idempotency key replayed) produce at most one receipt notification — zero duplicate deliveries across 1,000 replayed events in load testing.
- **SC-006**: Notification delivery failure leaves payment status unchanged in 100% of failure-scenario tests.
- **SC-007**: Export files pass formula-injection safety validation: no cell beginning with `=`, `+`, `-`, `@` in unquoted text fields.
- **SC-008**: All export access events (including failed attempts) appear in the audit log within 5 seconds of the access attempt.
- **SC-009**: History responses correctly reflect pending refund states — no pending refund is shown as confirmed until the refund is settled, verified across all refund state transitions.

---

## Assumptions

- Existing notification delivery infrastructure (P11 — Outbox/Inbox/Push/SMS/Email) is available and functional; P19 adds new event subscriptions without altering that infrastructure.
- Existing audit service (`AuditServiceInterface`) records access events; P19 calls it without modifying its contract.
- Private file storage (used for evidence in P15/P18) can also serve download links; P19 reuses that mechanism for receipt PDF/CSV downloads.
- A `payment.confirmed` event is emitted by P08 (Ledger Acknowledgement); P19 subscribes to it without modifying the event schema.
- A `refund.confirmed` event is emitted by P15 (Shared Refund Engine); P19 subscribes to it.
- Fee snapshots are stored at intent creation time (P03/P04); P19 reads them as-is for receipt accuracy.
- The domain deep link format (`{purpose_type}://{purpose_reference}`) is defined by each business domain; P19 constructs it server-side but does not validate domain-specific routing rules.
- Receipt PDFs are generated as server-side structured documents; PDF rendering is handled by a library already in the project or a lightweight template-to-PDF approach.
- Marketing consent preferences are already stored in the notification/user profile module; P19 reads them only to confirm the transactional-vs-marketing classification, never to suppress transactional notices.
- Export files are CSV format with RFC 4180 quoting; spreadsheet formula safety is applied at field-write time.
- Payout receipts (P18) are out of scope for P19; payout-specific history is accessed via P18 endpoints.
- "Users cannot obtain another payer receipt through a guessed reference" — this is enforced server-side by ownership checks, not by obscuring IDs; UUID v7 IDs are not treated as a security control.
