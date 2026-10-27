# Feature Specification: P23 — Payment History, Detail and Transaction Management

**Feature Branch**: `070-payment-history-detail`

**Created**: 2026-10-26

**Status**: Draft

**Input**: P23 — Shared activity filters, transaction detail, receipt access, domain deep links, refund routing, support from transaction, private cache security, and accessibility states for Flutter mobile apps.

**Required predecessors**: P19 (Receipts, Communication and Payment History APIs — E66), P22 (Shared Flutter Checkout — E69)

**Source references**: MB07, MB08; LC11, LC15

---

## Overview

P23 upgrades the existing rudimentary transaction list and detail screens in both the customer and agent Flutter apps to consume the P19 payment history APIs. The mobile user gains a filterable activity feed, a rich transaction detail view that distinguishes capture/accounting/fulfilment/refund status, safe one-tap receipt download, domain deep links that navigate to the originating business record, a guided refund-request flow (routed through eligibility APIs), and a support ticket launcher anchored to a stable payment reference. All private financial data is evicted from the device cache on logout or account switch. Every state — loading, offline-stale, denied, pending, failure and service unavailable — is accessible to users relying on large text and screen readers.

---

## User Scenarios & Testing

### User Story 1 — Filterable Payment Activity Feed (Priority: P1)

A customer or agent opens the activity screen and sees their payment history sourced from P19's `/v1/payment/history` endpoint. They can filter by date range, payment status and purpose type. Pending items are visually distinguished from confirmed ones. The list is paginated and works offline (showing the last cached page with a stale-data indicator).

**Why this priority**: The activity feed is the first thing a user checks after a payment. Replacing the old transaction list with the P19-backed history is the foundation for all other P23 features.

**Independent Test**: Launch the activity screen in a test harness with a mock P19 history response containing items with mixed statuses. Verify all items render with the correct status badge. Apply a status filter; verify only matching items are shown. Simulate an offline state; verify the previously loaded page is shown with a stale indicator rather than an error.

**Acceptance Scenarios**:

1. **Given** a customer has three confirmed and one pending payment, **When** they open the activity screen, **Then** all four items appear with distinct confirmed/pending visual treatment.
2. **Given** the user applies a filter for `status = pending`, **When** the list refreshes, **Then** only pending items are shown; confirmed items are absent.
3. **Given** the user applies a date range filter for the last 7 days, **When** the list refreshes, **Then** only payments within that range are shown.
4. **Given** a payment has `refund_status = pending`, **When** it appears in the list, **Then** a "Refund pending" label is visible; it is NOT presented as credit already received.
5. **Given** a payment has `refund_status = confirmed`, **When** it appears in the list, **Then** a "Refunded" label appears with the refund amount.
6. **Given** the device is offline and a previous list was cached, **When** the user opens the activity screen, **Then** the cached list is displayed with a stale-data banner; no error replaces the content.
7. **Given** the device is offline and no cache exists, **When** the user opens the activity screen, **Then** an offline empty state is shown (not a loading spinner or unhandled error).

---

### User Story 2 — Detailed Transaction View with Status Distinction (Priority: P1)

The user taps a history item and sees a detail screen that clearly separates capture status (did the provider charge?), accounting status (is the ledger entry confirmed?), service readiness (has the domain fulfilled the entitlement?), and refund status (is there a pending or confirmed return?). Domain deep links are present and navigable. Each status row has an accessible label.

**Why this priority**: The P19 API exposes multiple independent status dimensions. Displaying them as a single ambiguous "status" field (as the old screen did) causes support contacts and confusion. This story makes the financial picture unambiguous.

**Independent Test**: Navigate to the detail screen for a payment that has `payment_status = confirmed`, `accounting_status = posted`, `service_status = fulfilled`, and `refund_status = none`. Verify each dimension is rendered as a labelled row. Navigate to a payment with `refund_status = pending`; verify the refund row shows "Pending — amount reserved, not yet credited."

**Acceptance Scenarios**:

1. **Given** a confirmed payment with no refund, **When** the user views the detail screen, **Then** they see: capture status (provider charge confirmed), accounting status (ledger posted), service readiness (domain fulfilled), and refund status (none) — each as a distinct, labelled row.
2. **Given** a payment with `refund_status = pending`, **When** the user views the detail, **Then** the refund section shows "Pending — amount reserved, not yet credited" — it does NOT indicate a completed return.
3. **Given** a payment with `refund_status = confirmed`, **When** the user views the detail, **Then** the refund amount, confirmation date and net amount post-refund are all shown.
4. **Given** the payment intent has a `domain_deep_link` value, **When** the user taps it, **Then** they are navigated to the originating domain record within the app; if the domain is retired the link is disabled with an explanation.
5. **Given** a deep link that corresponds to a domain record the user is not authorised to view, **When** the user taps it, **Then** the navigation is denied and an appropriate message is shown — no other user's data is exposed.
6. **Given** a screen-reader is active, **When** the user navigates the detail screen, **Then** every status row is announced with its label and value; interactive elements have accessible names.

---

### User Story 3 — Safe Receipt Access and Download (Priority: P1)

The user requests a receipt from the transaction detail view. The receipt is loaded from P19's `GET /v1/payment/receipts/{intentId}` endpoint, scoped to the authenticated user. A one-tap download requests a time-limited expiring token and opens the receipt PDF/structured file in the device's document viewer. Attempting to share or use an expired token fails gracefully.

**Why this priority**: Receipt retrieval is a regulatory and practical requirement. P19 defined the API; P23 exposes it in the UI. Without this, users cannot produce payment evidence.

**Independent Test**: Tap "View Receipt" on a confirmed payment. Verify the receipt screen shows data from the P19 receipts endpoint. Tap "Download". Verify a download token is requested and the file is opened. Mock the token as expired; verify a graceful "link expired" message is shown rather than an unhandled error.

**Acceptance Scenarios**:

1. **Given** a confirmed payment, **When** the user taps "View Receipt", **Then** a receipt screen shows: payment reference, provider label, original amount, currency, fee amount, net amount, date/time, purpose description, and refund summary.
2. **Given** the user is not the payer of a payment intent (another payer's reference guessed), **When** they attempt to view the receipt, **Then** they receive a "Not found" or "Access denied" message — another payer's receipt is never rendered.
3. **Given** the user taps "Download", **When** a download token is issued, **Then** the file opens within the token's validity window; the app uses the token exactly once.
4. **Given** a download token has expired, **When** the user attempts the download, **Then** a "Receipt link expired — tap to refresh" message is shown; a fresh token can be requested.
5. **Given** the receipt includes a pending refund, **When** it is downloaded, **Then** the refund section shows "Pending — amount reserved, not yet credited" — not a completed return.
6. **Given** the receipt screen is active, **Then** the device screenshot / screen-recording protection is active (screenshot guard enabled for the receipt view).

---

### User Story 4 — Refund Request via Eligibility Flow (Priority: P2)

From a transaction detail the user sees whether a refund is available for that payment. If eligible, they initiate a refund request. The system routes the request through the domain's eligibility APIs; the UI never hard-codes eligibility rules. The user sees the status update (pending) immediately and receives a notification when the outcome is determined.

**Why this priority**: Refund request is a natural extension of transaction detail. It must go through eligibility APIs — the UI must never grant or deny on its own judgement.

**Independent Test**: For a payment with a server-confirmed `refund_eligible = true` flag, verify the "Request Refund" button is present and enabled. For `refund_eligible = false`, verify the button is absent or disabled with an explanation. Submit a refund request and verify the transaction status updates to `refund_status = pending`.

**Acceptance Scenarios**:

1. **Given** a payment intent where the P19 API returns `refund_eligible = true`, **When** the user views the detail, **Then** a "Request Refund" action is present.
2. **Given** a payment intent where `refund_eligible = false`, **When** the user views the detail, **Then** no refund action is available; if the API provides an ineligibility reason, it is shown.
3. **Given** the user submits a refund request, **When** the API accepts it, **Then** the transaction detail immediately shows `refund_status = pending`; the UI does not present it as confirmed until the API returns `refund_status = confirmed`.
4. **Given** the user submits a refund request and the eligibility API returns an error, **When** the UI processes the response, **Then** an error state is shown with a retry option; the original transaction data is unchanged.
5. **Given** a refund request is already in `pending` status, **When** the user views the detail, **Then** the "Request Refund" action is replaced with "Refund in progress" — duplicate submission is prevented.

---

### User Story 5 — Support Ticket from Transaction (Priority: P2)

From any transaction detail, the user can open a pre-filled support contact form that includes the payment reference, amount and a stable deep-link to the transaction. Safe file attachments (receipt PDF, screenshots with PII redacted) can be added. The support reference remains usable even if the transaction is later refunded or cancelled.

**Why this priority**: Support escalation from transaction context is a key consumer trust feature. Having a stable, server-issued reference prevents ambiguity in agent-support handoffs.

**Independent Test**: Tap "Get Help" on a transaction detail screen. Verify the pre-filled form includes the payment reference and masked amount. Attach a file. Submit the form. Verify the resulting support ticket includes the stable payment reference. Verify no full bank credentials, PINs or raw provider references appear in the submission payload.

**Acceptance Scenarios**:

1. **Given** a user on any transaction detail, **When** they tap "Get Help", **Then** a support form opens pre-filled with: masked payment reference, amount, currency, and date — no raw provider reference, PIN, or bank credentials.
2. **Given** the user attaches a file, **When** the form is submitted, **Then** only the authorised file types (PDF, PNG, JPEG; max size as configured) are accepted; unsupported types are rejected with an inline error.
3. **Given** the support ticket is submitted successfully, **When** the user receives confirmation, **Then** a stable support reference is shown that can be used in all future correspondence.
4. **Given** the same transaction is later refunded or cancelled, **When** the support ticket is referenced, **Then** the original payment reference remains valid and returns the payment record — the support link is stable.
5. **Given** the user navigates away from the support form before submitting, **When** they return to the transaction, **Then** the partial form is discarded (no accidental submission of half-complete data).

---

### User Story 6 — Private Cache Security and Account-Switch Eviction (Priority: P1)

When the user logs out or switches accounts, all locally cached payment history, transaction details and downloaded receipt references are cleared. The cache is encrypted at rest using the device keystore. A subsequent user on the same device cannot see the previous user's financial data.

**Why this priority**: Payment data is highly sensitive. P23 adds a local cache for offline support (US1) and receipt access (US3). Without explicit eviction, a shared device or account-switch scenario leaks one user's financial records to another.

**Independent Test**: Log in as User A, load the activity feed (data cached). Log out. Log in as User B. Navigate to the activity feed. Verify no data from User A is visible. Verify the cache storage directory/keys were cleared during logout.

**Acceptance Scenarios**:

1. **Given** User A has loaded their payment history (data in cache), **When** User A logs out, **Then** all cached payment history, transaction detail and receipt reference data are securely erased from device storage.
2. **Given** User A's data was cleared on logout, **When** User B logs in on the same device, **Then** User B sees only their own data — no residual records from User A.
3. **Given** the device is locked and another user physically obtains it, **When** they access the cache storage directly, **Then** the data is encrypted and cannot be read without the authenticated user's credentials.
4. **Given** a session token expires mid-use, **When** the app detects the expiry, **Then** cached financial data is evicted in the same operation as the session clear; no financial data is retrievable after session expiry.

---

### User Story 7 — Accessibility and Degraded-State Handling (Priority: P1)

Every screen in P23 (list, detail, receipt, refund, support) provides distinct accessible states for: loading (announced to screen reader), offline-stale (with stale indicator and manual refresh), access denied (with explanation, not blank), pending (distinct from confirmed and failed), failure (with retry action), and service unavailable (graceful message, no crash). Large-text (system font scale ≥2×) layouts remain usable; interactive targets meet minimum touch area.

**Why this priority**: Principle 11 (Accessibility) is non-negotiable. Constitution §P11 requires keyboard/focus/screen-reader/contrast/motion compliance. P23 introduces significant financial UI that must meet these standards.

**Independent Test**: Enable TalkBack/VoiceOver. Navigate to a loading transaction list. Verify a "Loading your transactions" announcement occurs. Switch to offline; verify "Showing offline data — last updated [time]" is announced. Navigate to a denied receipt; verify "Access denied" is announced with an explanation. Set system font scale to 2.5×; verify no text is clipped or overflows on any P23 screen.

**Acceptance Scenarios**:

1. **Given** a screen reader is active and the activity list is loading, **When** the screen renders, **Then** a live region or status announcement conveys the loading state — no silent spinner.
2. **Given** offline data is displayed, **When** a screen reader traverses the list, **Then** the stale-data indicator is announced (e.g., "Showing offline data, last updated 10 minutes ago") before the list items.
3. **Given** the user has insufficient permission to view a receipt, **When** the denied state renders, **Then** a clear, screen-reader-accessible explanation is shown (not a blank page or HTTP error code).
4. **Given** a refund is in `pending` state, **When** a screen reader announces it, **Then** the announcement says "Refund pending — not yet credited" (not "Refunded").
5. **Given** a service is temporarily unavailable, **When** the screen renders, **Then** a graceful "Service temporarily unavailable — try again later" view is shown with a retry button; no stack trace or raw error is displayed.
6. **Given** the system font scale is set to 2.5×, **When** any P23 screen is rendered, **Then** all text is fully readable without overflow or clipping; all interactive touch targets are at least 44×44 dp.

---

### Edge Cases

- What happens when a `domain_deep_link` points to a domain the app does not have a registered route for? → Show "Domain unavailable" inline message; do not crash.
- What happens when a `domain_deep_link` is marked `status: unavailable, reason: domain_retired`? → Display a greyed-out link with "Service no longer available" tooltip; no navigation attempt.
- What happens when a receipt download token is reused (second attempt)? → Server returns 410 Gone; app shows "Receipt link expired — tap to refresh" with a new-token CTA.
- What happens when the P19 history API returns a payment with both `payment_status = processing` and `refund_status = pending`? → Both statuses are shown independently; no single combined label is used.
- What happens when the attachment in the support form exceeds the maximum configured size? → Inline validation error before submission; form is not submitted.
- What happens when the user navigates to a transaction detail via a deep link while unauthenticated? → Redirect to login; after login, deep link destination is restored.
- What happens when account switch happens while a receipt download is in progress? → Download is cancelled; downloaded bytes are discarded; cache is cleared.

---

## Requirements

### Functional Requirements

- **FR-001**: The activity feed MUST consume the P19 `GET /v1/payment/history` API and display each item with: provider label, original amount, currency, payment status, and refund status.
- **FR-002**: The activity feed MUST support client-side filter controls for date range, payment status, and purpose type; filters MUST be applied as query parameters to the P19 API (not post-filtered locally).
- **FR-003**: The activity feed MUST display a stale-data indicator when showing cached data offline; the indicator MUST include the last-updated time.
- **FR-004**: The transaction detail screen MUST display capture status, accounting/ledger status, service readiness status, and refund status as independent, labelled rows — they MUST NOT be merged into a single status field.
- **FR-005**: The transaction detail screen MUST display the `domain_deep_link` from the P19 response; if the deep link is operable, tapping it MUST navigate to the originating domain record within the app.
- **FR-006**: A `domain_deep_link` with `status: unavailable` MUST be shown as disabled with a human-readable reason; no navigation attempt is made.
- **FR-007**: A user MUST NOT be able to view or deep-link to another user's transaction detail or receipt; the app MUST enforce this by relying on server-returned data only — never by constructing or guessing reference IDs.
- **FR-008**: The receipt screen MUST load data from `GET /v1/payment/receipts/{intentId}` and display: payment reference, provider label, original amount, currency, fee, net amount, date, purpose description, and refund summary.
- **FR-009**: The receipt screen MUST enable screenshot/screen-recording protection for the duration of its display.
- **FR-010**: Tapping "Download" on the receipt screen MUST request a single-use expiring token from the P19 receipts download endpoint; the file MUST be opened in the device document viewer within the token validity window; each token MUST be used at most once.
- **FR-011**: An expired or invalid download token MUST produce a "Receipt link expired" message with an option to request a fresh token; no unhandled error or raw HTTP status code is displayed.
- **FR-012**: The refund eligibility state (`refund_eligible`) MUST be sourced from the P19 API response; the app MUST NOT compute or infer eligibility locally.
- **FR-013**: When `refund_eligible = true`, a "Request Refund" action MUST be shown; when `false`, it MUST be absent or disabled with the server-provided ineligibility reason (if any).
- **FR-014**: Submitting a refund request MUST set the local display to `refund_status = pending` immediately; the confirmed state MUST only be displayed after the API confirms `refund_status = confirmed`.
- **FR-015**: An existing `refund_status = pending` record MUST disable the "Request Refund" action to prevent duplicate submissions.
- **FR-016**: The support ticket form MUST be pre-filled with the payment reference (masked), amount, currency and date; it MUST NOT pre-populate raw provider references, PINs, or bank credentials.
- **FR-017**: Attachments in the support form MUST be validated client-side for type (PDF, PNG, JPEG) and size (maximum as configured) before submission.
- **FR-018**: The support reference returned after submission MUST be displayed and stable — it MUST remain valid even if the underlying payment is later refunded or cancelled.
- **FR-019**: On logout or account switch, ALL locally cached payment history, transaction details, downloaded receipt references and support drafts MUST be securely evicted from device storage.
- **FR-020**: Cached payment data MUST be encrypted at rest using the platform's secure storage mechanism (device keystore or equivalent).
- **FR-021**: Every screen in P23 MUST provide distinct, accessible states for: loading, offline-stale, access denied, pending, confirmed, failure, and service unavailable.
- **FR-022**: All P23 screens MUST remain fully usable at system font scale ≥ 2×; all interactive touch targets MUST be at least 44×44 dp.
- **FR-023**: Screen-reader users MUST receive meaningful announcements for all P23 status states, including stale-data indicators, refund pending vs. confirmed, and access denied.

### Key Entities

- **PaymentHistoryItem**: A single entry in the activity feed — sourced from P19 API; includes payment reference, purpose type/reference, domain deep link, provider label, amounts, statuses (payment, refund), date.
- **PaymentHistoryItem (detail view)**: The same `PaymentHistoryItem` entity is used for both the list feed and the detail screen; the detail screen reads all optional fields (capture status, accounting/ledger status, service readiness status, refund eligibility flag, ineligibility reason, support reference) that are populated only in the detail response. No separate `PaymentDetail` class exists in the implementation.
- **PaymentReceipt**: Structured receipt data for a confirmed payment — amounts from ledger, fee snapshot, refund summary; rendered from P19 receipts endpoint.
- **ReceiptDownloadToken**: Single-use, expiring token that authorises a one-time download of a receipt file; issued by P19 endpoint.
- **RefundRequest**: A user-initiated refund attempt; associated with a payment reference; routes through domain eligibility API; result is `pending` or `failed`.
- **SupportTicket**: Pre-filled contact form anchored to a payment reference; carries a stable support reference after submission.
- **PaymentHistoryFilter**: Client-side filter state — date range, status, purpose type — applied as API query parameters.
- **PaymentCacheEntry**: Encrypted at-rest cache record for a history item or detail; evicted on logout/account switch.

---

## Success Criteria

### Measurable Outcomes

- **SC-001**: A customer can navigate from the activity feed to a transaction detail and back within 3 taps; the round trip completes in under 2 seconds on a 4G connection.
- **SC-002**: Applying a filter (date, status, or type) updates the activity list in under 1.5 seconds; the filtered result accurately excludes items outside the criteria.
- **SC-003**: A confirmed payment's refund status is displayed distinctly from its payment status on both the list and detail screens; a screen-reader user can determine the difference without visual context.
- **SC-004**: A user can view and download a payment receipt within 3 taps from the transaction detail; download initiates within 2 seconds of tapping "Download".
- **SC-005**: Expired receipt download tokens produce a "Receipt link expired" message with a refresh CTA — no unhandled error or blank screen occurs in this scenario.
- **SC-006**: After logout or account switch, a subsequent user on the same device sees no financial data from the previous session; verified by navigating to the activity feed immediately after login.
- **SC-007**: All P23 screens render correctly at 2× and 2.5× system font scale with no text clipping or overflow in any state (loading, data, error, offline).
- **SC-008**: A screen reader user can complete the refund request flow without visual context — all interactive elements have accessible names and all state changes are announced.
- **SC-009**: The support form can be opened, pre-filled data verified, and a ticket submitted within 5 taps from the transaction detail screen.
- **SC-010**: Another payer's receipt or transaction cannot be accessed via deep link guessing; the server returns 403/404 and the app displays an access-denied state — no data leakage occurs.

---

## Assumptions

- The P19 backend APIs (`GET /v1/payment/history`, `GET /v1/payment/receipts/{id}`, refund eligibility endpoint) are live and accessible from the Flutter apps following E66 delivery.
- The P22 shared package (`packages/shared-payment`) is available; P23 extends the existing transactions module — it does not replace the checkout flow built in P22.
- Both customer and agent apps have existing `transactions/` modules (list + detail screens); P23 upgrades these to consume P19 APIs — not a from-scratch build.
- Agent transaction history shows float and commission-related payments; the same P19 API supports agent-scoped queries with `actor_type = agent` scope.
- Domain deep-link routing uses the app's existing GoRouter configuration; new routes for domain records are registered by their respective domain modules.
- Screenshot guard (`ScreenshotGuard`) exists in the customer app's `core/security/` layer (already used on the existing transaction detail screen).
- Secure encrypted local cache is implemented using `flutter_secure_storage` (already in the app's dependency tree from session management in E03).
- Support ticket submission uses the existing dispute/support module API introduced in E22; P23 does not build a new support backend.
- Marketing notification preferences are out of scope for P23 (as stated in the brief); transactional receipt notifications are already handled by P19/E66.
- The `refund_eligible` flag and ineligibility reason are returned by the P19 payment detail endpoint; P23 does not call a separate eligibility service — it reads the flag from the history/detail response.
