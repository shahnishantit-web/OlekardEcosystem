# Feature Specification: P22 — Shared Flutter Checkout and Recovery

**Feature Branch**: `069-flutter-checkout-recovery`

**Created**: 2026-10-26

**Status**: Draft

**Source references**: MB01 MB02 MB03 MB04 MB05 MB06 MB08; LC02 LC03 LC04

**Predecessor packages**: P06 (payment state engine), P09 (wallet adapter), P10 (callback ingress), P19 (receipts/history APIs)

---

## Overview

Mobile users (customers and agents) pay for goods, services, transfers, and bill payments inside a single consistent checkout experience. Today the checkout logic is duplicated across the customer and agent applications. When either app is updated, both copies must be kept in sync — a maintenance burden that has already caused divergence in error handling and recovery behaviour.

This package extracts checkout into one shared library that both apps import. The shared library covers:

- Displaying server-determined payment options, fees, recipient and restrictions
- Executing payment through Wallet, bank redirect, and bank SDK/app-switch flows
- Recovering a pending payment after an app restart, network loss, or phone lock during a redirect

The boundary is intentional: no payment secrets are stored on the device, no money movement is computed locally, and each consuming feature launches checkout with a single trusted reference rather than duplicating the checkout UI.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Single shared checkout entry point (Priority: P1)

A business feature (e.g., bill payment, top-up, transfer confirmation) needs to collect a payment. Instead of implementing its own payment UI, it passes a trusted payable reference to the shared checkout entry point and receives back a confirmed or failed result.

**Why this priority**: Every other user story depends on having one entry point. Duplicate checkout is the root cause of divergence and is the critical path risk.

**Independent Test**: Launch checkout from a mock payable reference; verify the payment options screen appears showing server-returned options, fees, and recipient; verify the calling feature receives a typed result after completion.

**Acceptance Scenarios**:

1. **Given** a business feature holds a confirmed payable/quote reference, **When** it opens the shared checkout, **Then** the user sees the payment summary (amount, currency, recipient, fee) sourced entirely from the server — the calling feature cannot alter these values.
2. **Given** the checkout completes (success or failure), **When** the result is returned, **Then** the calling feature receives a typed result containing intent ID and final status; it does NOT receive raw redirect parameters as proof of success.
3. **Given** two different calling features use the shared checkout, **When** both are active at the same time, **Then** each maintains independent state — one checkout does not affect the other.

---

### User Story 2 — Server-driven option display (Priority: P1)

A user sees only the payment options that the server says are available for the current amount, currency, and their account status. Restrictions (blocked country, minimum amount, KYC not passed) are shown alongside options so the user understands why a method is unavailable.

**Why this priority**: Without server-driven display the app could show options that fail on submission, degrading trust.

**Independent Test**: Serve a mock options response containing one Wallet option (with balance), one bank redirect option, and one option with a restriction message; verify all three appear correctly with the restriction reason visible.

**Acceptance Scenarios**:

1. **Given** the server returns a Wallet option, **When** the user views the checkout, **Then** the current Wallet balance is shown; if it is insufficient for the payment, the option is visually disabled and the shortfall is explained.
2. **Given** the server returns an option with a non-empty restrictions list, **When** the user views the checkout, **Then** the restriction reason is shown under the option; the user cannot select a restricted option.
3. **Given** the server returns fee information for an option, **When** the user selects that option, **Then** the fee breakdown (service fee, provider fee, total payable) is shown before the user confirms.
4. **Given** the options response changes (e.g., balance updated), **When** the user refreshes, **Then** the display reflects the latest server state — the app does not cache option availability.

---

### User Story 3 — Payment execution via typed next actions (Priority: P1)

After the user selects an option and confirms, the server returns a next action that tells the app exactly what to do: show a Wallet PIN confirmation, open a bank URL in an external browser, launch a bank SDK, or perform an app-switch. The app follows the instruction without guessing.

**Why this priority**: Typed next actions prevent the app from making assumptions about how each provider works, which is required for safe provider onboarding.

**Independent Test**: Confirm a payment against a mock server that returns a `redirect` next action; verify the app opens the correct URL; mock app-return; verify the app polls for status and does not treat the redirect URL parameters as payment proof.

**Acceptance Scenarios**:

1. **Given** the server returns a `wallet_pin` next action, **When** the user enters their PIN, **Then** the Wallet step-up verification is performed; on success the intent is captured; on wrong PIN the user is prompted to retry without creating a duplicate attempt.
2. **Given** the server returns a `redirect` next action with an allowlisted return destination, **When** the app opens the bank URL and the user returns, **Then** the app polls the backend for the authoritative status; it does NOT parse redirect URL parameters as payment proof.
3. **Given** a return URL that is NOT on the allowlist, **When** the checkout receives it, **Then** the checkout refuses to treat the return as successful and instead polls the backend; the disallowed URL is not opened or followed.
4. **Given** the server returns a `provider_unavailable` status during attempt initiation, **When** the user sees the error, **Then** the checkout returns to option selection; the failed provider is marked unavailable for this session; no duplicate attempt has been created.
5. **Given** the user selects option A, **When** they have already submitted attempt A and the attempt is in-flight or succeeded, **Then** the checkout blocks any attempt to switch to option B — provider replacement is prohibited for a live or completed attempt.

---

### User Story 4 — Restart and network-loss recovery (Priority: P1)

A user's app is closed mid-payment (phone locked, notification dismissed app, OS killed background process) or connectivity is lost after a bank redirect. On next launch, the app shows the correct state — not a stale screen, not an incorrect success, and not a silent failure.

**Why this priority**: Failure to recover is one of the highest-impact support call drivers. An incorrect recovery (showing success when the outcome is unknown) creates financial risk.

**Independent Test**: Simulate app restart with a persisted pending intent ID; verify the checkout reopens to "checking payment status" rather than options or a success screen; verify it polls backend and resolves to the correct terminal state.

**Acceptance Scenarios**:

1. **Given** the user's app restarts with a pending payment intent, **When** the checkout initialises, **Then** it immediately shows a "checking payment status" screen — not the option selection screen — and begins polling the backend.
2. **Given** the backend returns `pending` or `processing` status during recovery polling, **When** the user sees the recovery screen, **Then** the display says "payment is being processed" and continues polling; it does NOT show a success message.
3. **Given** the backend eventually returns a terminal status (succeeded / failed / cancelled / expired), **When** polling resolves, **Then** the user sees the correct terminal outcome screen; the pending record is cleared from local storage.
4. **Given** the app returns from a bank redirect and the backend returns an unknown/ambiguous outcome, **When** the user sees the screen, **Then** the display says "outcome unknown — do not retry" with a support reference; no retry button is shown until the backend confirms the intent can safely be retried.
5. **Given** network connectivity is lost during polling, **When** network is restored, **Then** polling resumes automatically from where it stopped; the user is not shown a fatal error.

---

### Edge Cases

- What happens when the payment options endpoint returns zero available options?
  — The checkout shows "no payment methods available" and allows the user to close without error.
- What happens when the intent creation call times out?
  — The app retries using the same idempotency key; if the second attempt also fails, it shows a retry-able error.
- What happens when the user opens the same payable reference twice concurrently (two tabs / two sessions)?
  — The first-caller's idempotency key is used; the server deduplicates; the second call receives the existing intent.
- What happens when the polling timeout is reached without a terminal status?
  — The checkout shows "we are still waiting for your bank — check back in a few minutes" with a support reference; it does NOT show success.
- What happens when step-up fails (wrong PIN, biometric failure)?
  — The user is shown the error and can retry up to the server-configured attempt limit; the attempt key is rotated between retries to prevent duplication.
- What happens if the bank redirect URL fails to open (device has no browser)?
  — The checkout shows a fallback message with the bank's direct contact details if available, or a generic "please open your bank app manually" instruction.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The checkout MUST be launchable from any business feature by providing a payable reference, currency code, and return route; the calling feature MUST NOT pass an amount — the server is the authoritative source.
- **FR-002**: The checkout MUST display payment options, fees, recipient summary, and restrictions exactly as returned by the server; it MUST NOT derive or modify these values on the device.
- **FR-003**: The checkout MUST render Wallet balance and indicate (visually distinct) when the Wallet option has insufficient balance for the current payment.
- **FR-004**: A restricted payment option MUST be displayed with its restriction reason; the user MUST NOT be able to select or proceed with a restricted option.
- **FR-005**: The checkout MUST perform Wallet step-up authentication (PIN or biometric) when the server returns a `wallet_pin` next action; the step-up credential MUST be passed to the server and MUST NOT be stored locally.
- **FR-006**: The checkout MUST open bank redirect URLs exactly as returned by the server; return destinations MUST be validated against a server-managed allowlist before any success signal is trusted.
- **FR-007**: The checkout MUST NOT treat redirect URL query parameters as payment confirmation; all payment outcomes MUST be confirmed by polling the backend status endpoint.
- **FR-008**: The checkout MUST persist the payment intent ID to local durable storage before opening a redirect, so that recovery is possible after an unexpected app restart.
- **FR-009**: On app launch or foreground resume with a persisted pending intent, the checkout MUST restore to a "checking status" state and poll the backend for the authoritative outcome.
- **FR-010**: The checkout MUST distinguish between three unresolved states: `unknown` (outcome not yet determined), `pending` (being processed), and `fulfilling` (payment captured, fulfilment in progress); each MUST have a distinct user-facing message.
- **FR-011**: Once an attempt has been submitted for an intent, the checkout MUST block any request to switch to a different payment provider for that intent.
- **FR-012**: Idempotency keys for intent creation and attempt initiation MUST be generated once per submission and reused on retry; a new idempotency key MUST be generated only when the previous attempt definitively failed (server returned a non-idempotent error).
- **FR-013**: The checkout MUST return a typed result to the calling feature containing: final status, intent ID (when available), and a boolean `succeeded` flag; the result MUST NOT include raw provider references or credentials.
- **FR-014**: The checkout library MUST be shared across customer and agent mobile applications; each application MUST NOT maintain its own duplicate checkout implementation.
- **FR-015**: The checkout MUST be usable as either a full-screen page or a bottom sheet, determined by the calling feature.

### Non-Functional Requirements

- **NFR-001**: The payment options screen MUST appear within 2 seconds of the user triggering checkout on a standard 4G connection.
- **NFR-002**: The checkout recovery flow MUST resume within 1 second of the app returning to the foreground.
- **NFR-003**: The checkout MUST NOT store payment credentials, provider secrets, or server-signed tokens in plaintext on the device.
- **NFR-004**: All checkout state transitions MUST be observable via structured logs; financial events (intent created, attempt submitted, outcome confirmed) MUST be auditable.
- **NFR-005**: The shared library MUST expose a stable public API; internal refactoring MUST NOT require changes in consuming applications.

---

## Success Criteria *(mandatory)*

- **SC-01**: A user can complete a Wallet payment end-to-end in under 60 seconds from checkout open to confirmed success.
- **SC-02**: A user redirected to their bank's website returns to the app and sees the correct payment outcome within 30 seconds of returning, without any manual action.
- **SC-03**: No tampered payment total (modified locally) can result in a charge being raised; 100% of charged amounts are determined server-side.
- **SC-04**: App restart during an in-flight bank redirect results in the user seeing "checking status" — never a false success screen — in 100% of tested cases.
- **SC-05**: Wallet insufficient-funds detection is shown before the user confirms payment, in 100% of cases where the wallet balance is below the required amount.
- **SC-06**: Both customer and agent applications successfully complete checkout flows using the shared library with no duplicate logic remaining in either application.
- **SC-07**: A provider outage or bank-side error returns the user to the option selection screen with a clear reason, without creating a duplicate attempt.
- **SC-08**: Recovery polling correctly identifies `unknown`, `pending`, and `fulfilling` outcomes as distinct states; users are never shown a misleading success or failure message for an unresolved outcome.

---

## Key Entities *(optional)*

| Entity | Description |
|--------|-------------|
| `PayableReference` | Server-issued reference to the thing being paid for (bill, transfer, top-up); trusted input to checkout |
| `PaymentIntent` | Server-created record of an authorised payment attempt; identified by UUID; immutable once created |
| `PaymentAttempt` | A single submission to a payment provider for a given intent; provider and idempotency key are fixed at submission |
| `PaymentOption` | Server-provided description of an available payment method for the current context; includes fee disclosure and restrictions |
| `NextAction` | Typed instruction from the server on how to proceed after attempt initiation (wallet_pin, redirect, sdk_launch, app_switch) |
| `CheckoutResult` | Typed value returned to the calling business feature; contains success flag, intent ID, and final status |
| `RecoveryRecord` | Durable local record of an in-flight intent ID enabling post-restart recovery |

---

## Assumptions

- The backend already provides `/v1/payment/options`, `/v1/payment/intents` (create), `/v1/payment/intents/{id}/attempts` (initiate), and `/v1/payment/intents/{id}/status` (poll) endpoints per P06.
- The Wallet adapter (P09) provides PIN/biometric step-up; the checkout delegates step-up to this service rather than implementing it locally.
- The return route allowlist is managed server-side and returned alongside the redirect next action; the client does not maintain its own allowlist.
- `fulfilling` state corresponds to a payment that has been captured by the provider but whose fulfilment (e.g., wallet credit) is still being processed; this is a non-terminal state from the user perspective.
- P10 (callback ingress) handles the server-side receipt of provider callbacks; the mobile client only polls — it does not receive push callbacks directly.
- The shared Flutter package will be located at `packages/shared-payment/` in the monorepo, following the existing `packages/design-system/` and `packages/shared-utils/` structure.

---

## Dependencies

- **P06** (payment state engine): Provides backend intent/attempt state machine and status endpoint — COMPLETE
- **P09** (Wallet payment adapter): Provides Wallet debit and step-up authentication — COMPLETE
- **P10** (callback ingress): Handles server-side bank callbacks; mobile polls after return — PARTIAL (framework complete; live activation BLOCKED on provider contracts)
- **P19** (receipts/history APIs): Receipt data available for post-payment confirmation display — COMPLETE

**Note on P10**: The mobile client only polls the status endpoint; it does not depend on the bank callback mechanism being live. The checkout recovery flow is safe to implement against P10 being in PARTIAL state.
