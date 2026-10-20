# E31 — Customer Application

**Feature Directory**: `specs/031-customer-application/`

**Created**: 2026-10-05

**Status**: Draft

## Overview

The Customer Application is a Flutter-based mobile app (iOS and Android) through which a registered wallet holder manages their financial life: authenticating securely, viewing their balance and transaction history, moving money, paying for services, managing their profile and security settings, and raising support tickets or disputes. All financial operations delegate to the backend APIs built in E01–E30; the app is the presentation and interaction layer only.

**Primary actor:** Customer — a verified wallet holder who has completed identity verification.

---

## User Stories

### User Story US-CUST-001 — Register (Priority: P0)

A new user creates a wallet account through the mobile app so they can start transacting.

**Why this priority**: Registration is the entry-point for every other capability. Zero registered users means zero value.

**Independent Test**: A new phone number completes registration end-to-end (phone verification → profile → PIN set) and receives a wallet with zero balance confirmed on the home screen.

**Acceptance Scenarios**:

1. **Given** a user opens the app for the first time, **When** they choose to register, **Then** they are guided through: phone number entry → OTP verification → profile details → PIN creation → home screen.
2. **Given** a phone number already registered, **When** a user tries to register with it, **Then** they receive a clear error and are directed to the login or forgot-PIN flow.
3. **Given** invalid or incomplete form fields, **When** the user tries to proceed, **Then** inline validation messages appear next to each failing field.
4. **Given** registration succeeds, **When** the user lands on the home screen, **Then** their wallet balance shows 0.00 and their name is displayed.
5. **Given** the OTP expires before entry, **When** the user tries to verify, **Then** they can request a new OTP and the expired one is rejected.

---

### User Story US-CUST-002 — Login (Priority: P0)

A returning customer authenticates with their phone number and PIN to access their account.

**Why this priority**: Every returning-user session depends on login.

**Independent Test**: A registered user enters their phone number and PIN, lands on the home screen, and their balance is visible.

**Acceptance Scenarios**:

1. **Given** a registered user opens the app, **When** they enter their phone number and correct PIN, **Then** they are authenticated and land on the home screen within 3 seconds.
2. **Given** a user has enabled biometric authentication, **When** the app opens, **Then** biometric prompt appears first and falls back to PIN on failure.
3. **Given** an incorrect PIN, **When** entered up to the configured limit, **Then** a lockout countdown is shown; after the limit the account is temporarily locked.
4. **Given** a previously authenticated session that has not expired, **When** the user reopens the app within the session timeout, **Then** biometric or PIN unlocks the session without full re-authentication.
5. **Given** an authentication failure, **When** the backend returns an error, **Then** the user sees a user-friendly message without exposing technical details.

---

### User Story US-CUST-003 — Logout (Priority: P0)

A customer ends their session explicitly, clearing local credentials so another person cannot access the account.

**Why this priority**: Session security is a baseline requirement.

**Independent Test**: After logout, reopening the app shows the login screen and no cached sensitive data.

**Acceptance Scenarios**:

1. **Given** an authenticated user, **When** they tap "Logout", **Then** their session token is invalidated server-side and the app returns to the login screen.
2. **Given** logout is triggered, **When** the operation completes, **Then** no wallet balance, transaction data, or profile PII remains in local app storage.
3. **Given** no internet connection at logout, **When** the user initiates logout, **Then** the local session is cleared and the login screen shown, with the server-side revocation retried when connectivity resumes.

---

### User Story US-CUST-004 — Reset PIN (Priority: P0)

A customer who has forgotten or wants to change their PIN can recover access securely via OTP verification.

**Why this priority**: Without PIN recovery, locked-out users permanently lose account access.

**Independent Test**: A user who enters the wrong PIN three times can recover access using their phone OTP and set a new PIN.

**Acceptance Scenarios**:

1. **Given** a user who cannot remember their PIN, **When** they choose "Forgot PIN", **Then** an OTP is sent to their registered phone number.
2. **Given** the OTP is verified, **When** the user sets a new PIN, **Then** the old PIN is invalidated and the new PIN works on next login.
3. **Given** the new PIN matches the old PIN, **When** submitted, **Then** the app rejects it with a message that the PIN must differ from the previous one.
4. **Given** PIN reset is complete, **When** any active sessions existed, **Then** all other sessions are invalidated for security.

---

### User Story US-CUST-005 — Manage Devices (Priority: P0)

A customer can view all devices where their account is active and revoke access from devices they no longer use or recognize.

**Why this priority**: Device management is essential for account security and compliance.

**Independent Test**: A user can view a list of registered devices, see the last-active timestamp, and remove a device; after removal, that device requires fresh authentication.

**Acceptance Scenarios**:

1. **Given** an authenticated user navigates to security settings, **When** they view "My Devices", **Then** they see each registered device with: name, OS, last-seen timestamp, and a revoke option.
2. **Given** a user revokes a device, **When** confirmed, **Then** that device's session is invalidated and it must re-authenticate.
3. **Given** a user tries to revoke the current device, **When** they confirm, **Then** they are warned that they will be logged out and, upon confirmation, the session ends.

---

### User Story US-CUST-006 — View Wallet Balance (Priority: P0)

A customer can instantly see their current wallet balance on the home screen.

**Why this priority**: Balance visibility is the most-accessed screen in any wallet app.

**Independent Test**: After a funding transaction, the updated balance appears on the home screen within 5 seconds without requiring a manual refresh.

**Acceptance Scenarios**:

1. **Given** an authenticated user is on the home screen, **When** the screen loads, **Then** their current wallet balance is shown prominently in their account currency.
2. **Given** a transaction completes, **When** the user returns to the home screen, **Then** the balance reflects the updated amount.
3. **Given** the user has insufficient funds for a pending action, **When** the balance is low, **Then** a clear visual indicator highlights the balance.
4. **Given** no network connection, **When** the home screen loads, **Then** the last-cached balance is shown with an "offline" indicator.

---

### User Story US-CUST-007 — View Recent Transactions (Priority: P0)

A customer can review their transaction history to track spending and verify activity.

**Why this priority**: Transaction visibility is core to financial trust and dispute prevention.

**Independent Test**: After performing a send and a receive, both appear in the transaction list with correct amounts, counterparties, and timestamps.

**Acceptance Scenarios**:

1. **Given** an authenticated user views their transaction list, **When** the screen loads, **Then** the 20 most recent transactions appear, each showing: type, amount, counterparty, date, and status.
2. **Given** a user scrolls to the bottom of the visible transactions, **When** more history exists, **Then** additional transactions load automatically (pagination).
3. **Given** a user taps a transaction, **When** the detail screen opens, **Then** they see the full transaction record including reference number, fee (if any), and current status.
4. **Given** a user wants to filter, **When** they choose a date range or transaction type, **Then** only matching transactions are shown.

---

### User Story US-CUST-008 — View Shortcuts (Priority: P0)

A customer can access their most-used actions from the home screen without navigating menus.

**Why this priority**: Shortcuts reduce task time for high-frequency actions like sending money or paying bills.

**Independent Test**: A user can initiate a Send Money flow from a home-screen shortcut in two taps.

**Acceptance Scenarios**:

1. **Given** an authenticated user views the home screen, **When** it loads, **Then** shortcut tiles are visible for: Send Money, Add Money, Pay Bills, and Receive Money.
2. **Given** a user taps a shortcut, **When** the action screen opens, **Then** the appropriate flow launches directly without additional navigation steps.
3. **Given** a feature is unavailable (e.g., account not verified), **When** the user taps its shortcut, **Then** they see a contextual explanation and action to resolve the prerequisite.

---

### User Story US-CUST-009 — View Notifications (Priority: P0)

A customer receives and can review in-app notifications about their account activity, security alerts, and promotions.

**Why this priority**: Notifications drive awareness of account activity and security events.

**Independent Test**: After a money transfer, a push notification arrives and the in-app notification center shows the same event with a read/unread indicator.

**Acceptance Scenarios**:

1. **Given** a transaction occurs on the customer's account, **When** it completes, **Then** a push notification is delivered and appears in the in-app notification centre.
2. **Given** a user taps a notification in the notification centre, **When** the detail opens, **Then** tapping the action link (if present) deep-links to the relevant screen (e.g., transaction detail).
3. **Given** a user has unread notifications, **When** they view the notification list, **Then** unread items are visually distinguished and a badge count appears on the notification icon.
4. **Given** a user marks all as read, **When** the action completes, **Then** the badge count clears.

---

### User Story US-CUST-010 — View Wallet (Priority: P0)

A customer can view a detailed view of their wallet, including its full identifier, linked cards, and account details needed for receiving funds.

**Why this priority**: Customers need their wallet details to share with others or link external accounts.

**Independent Test**: A user can copy their wallet number from the wallet detail screen and use it as a receive address.

**Acceptance Scenarios**:

1. **Given** an authenticated user navigates to their wallet, **When** the screen loads, **Then** they see: wallet number, account name, balance, currency, and wallet status.
2. **Given** a user wants to share their wallet number, **When** they tap "Copy" or "Share", **Then** the wallet number is copied to clipboard or shared via the device share sheet.
3. **Given** a user taps "Receive Money", **When** the receive screen opens, **Then** the app navigates to the Receive Money screen (US-CUST-013) where a QR code encoding their wallet number is displayed for scanning by a sender.

---

### User Story US-CUST-011 — Add Money (Priority: P0)

A customer funds their wallet using a bank card, bank transfer, or agent cash-in.

**Why this priority**: Without funding, no transactions are possible. This is the first financial action.

**Independent Test**: A customer selects "Add Money", chooses bank card, enters a valid amount, confirms, and their wallet balance increases by the funded amount.

**Acceptance Scenarios**:

1. **Given** an authenticated user chooses to add money, **When** they select a funding method and enter an amount, **Then** they see a confirmation screen showing: amount, method, and any applicable fees before confirming.
2. **Given** the user confirms a funding transaction, **When** processing completes successfully, **Then** the wallet balance updates and a success screen is shown with a transaction reference.
3. **Given** the funding provider returns a failure, **When** the transaction fails, **Then** no debit is made, the user sees a clear failure message, and they can retry or choose a different method.
4. **Given** a duplicate request is submitted (same Idempotency-Key), **When** the backend receives it, **Then** only one funding transaction is processed and the same result is returned.
5. **Given** a minimum or maximum funding limit is exceeded, **When** the user enters the amount, **Then** inline validation prevents submission with a clear message stating the allowed range.

---

### User Story US-CUST-012 — Send Money (Priority: P0)

A customer transfers funds from their wallet to another wallet holder identified by their phone number or wallet number.

**Why this priority**: Peer-to-peer transfer is the core value proposition of a mobile wallet.

**Independent Test**: A customer sends BWP 50 to a valid recipient wallet number; the sender's balance decreases and the recipient's balance increases by BWP 50 (minus any fee shown at confirmation).

**Acceptance Scenarios**:

1. **Given** a user initiates "Send Money", **When** they enter a recipient's phone or wallet number, **Then** the system validates the recipient exists and displays their name for confirmation.
2. **Given** a recipient is confirmed and an amount entered, **When** the user taps "Review", **Then** they see: recipient name, amount, fee, net debit, and a PIN entry to authorize.
3. **Given** the correct PIN is entered, **When** the transfer is submitted, **Then** the sender's balance decreases and the transaction appears immediately in their history.
4. **Given** insufficient balance for the requested amount plus fee, **When** the user tries to send, **Then** submission is blocked with a clear insufficient-funds message.
5. **Given** the transfer exceeds a configured daily limit, **When** the user tries to send, **Then** they are informed of the limit and the remaining daily allowance.

---

### User Story US-CUST-013 — Receive Money (Priority: P0)

A customer can display their receive address (QR or wallet number) so that another user can send money to them.

**Why this priority**: Receiving is the counterpart to sending; completing the transfer loop.

**Independent Test**: A user opens the Receive Money screen; a QR code is displayed and scanning it from another device populates the sender's recipient field.

**Acceptance Scenarios**:

1. **Given** a user opens "Receive Money", **When** the screen loads, **Then** a QR code encoding their wallet number is prominently displayed alongside the wallet number in text.
2. **Given** a user wants to share their receive details, **When** they tap "Share", **Then** the wallet number and/or QR image can be shared via the device's native share sheet.
3. **Given** an incoming transfer is received, **When** it posts to the user's wallet, **Then** a push notification is delivered and the balance updates when the user next views the home screen.

---

### User Story US-CUST-014 — Withdraw Money (Priority: P0)

A customer withdraws funds from their wallet to a bank account or via an agent cash-out.

**Why this priority**: Withdrawal completes the money-out lifecycle and is required for utility and trust.

**Independent Test**: A customer requests a withdrawal of BWP 100 to their linked bank account; the wallet balance decreases immediately and a pending withdrawal record appears.

**Acceptance Scenarios**:

1. **Given** a user chooses "Withdraw Money", **When** they select a method (bank transfer or agent cash-out) and enter an amount, **Then** they see the amount, fee, net proceeds, and estimated settlement time before confirming.
2. **Given** the user confirms with their PIN, **When** the withdrawal is processed, **Then** the wallet balance is debited immediately, a withdrawal record with status "Processing" appears, and the user is notified of final settlement.
3. **Given** the withdrawal provider returns a failure, **When** this occurs, **Then** the wallet balance is restored (or the debit is reversed) and the user sees a clear failure message.
4. **Given** minimum balance requirements must be maintained, **When** the requested withdrawal would breach the minimum, **Then** the user is informed and the amount field is capped.

---

### User Story US-CUST-015 — Browse Services (Priority: P1)

A customer browses the catalog of available bill-payment and utility services so they can find a service to pay.

**Why this priority**: Service payments are a key driver of app engagement and fee revenue, but depend on a funded, verified wallet (P0 stories).

**Independent Test**: A user opens the Services tab; a list of service categories (e.g., Electricity, Water, Airtime) is displayed and tapping a category shows providers within it.

**Acceptance Scenarios**:

1. **Given** an authenticated user navigates to "Services", **When** the screen loads, **Then** service categories are shown (e.g., Utilities, Airtime, Education) with icons and names.
2. **Given** a user selects a category, **When** the provider list loads, **Then** providers within that category are shown with their logos and names.
3. **Given** a user searches for a service by name, **When** results return, **Then** matching services are shown regardless of category.

---

### User Story US-CUST-016 — Purchase Service (Priority: P1)

A customer pays for a service (electricity, airtime, water) using their wallet balance.

**Why this priority**: Service purchases generate transaction fees and fulfil a key promise of the wallet.

**Independent Test**: A customer selects Airtime, enters a phone number and BWP 20, confirms with PIN; the wallet balance decreases and a confirmation token (e.g., airtime PIN) is displayed.

**Acceptance Scenarios**:

1. **Given** a user selects a service provider, **When** the payment form loads, **Then** the required fields for that service (e.g., meter number, phone number) are shown with appropriate labels and input constraints.
2. **Given** all fields are valid, **When** the user taps "Review", **Then** they see the biller, service reference, amount, fee, and a PIN authorization prompt.
3. **Given** payment is confirmed and succeeds, **When** the provider acknowledges, **Then** the wallet balance updates, a success screen shows the confirmation reference/token, and the transaction appears in history.
4. **Given** the provider fails to process the payment, **When** this is detected, **Then** the wallet is not debited and the user sees a failure message with a retry option.

---

### User Story US-CUST-017 — View Service History (Priority: P1)

A customer reviews their past service payment history to track utility payments and retrieve confirmation tokens.

**Why this priority**: History allows reimbursement, dispute evidence, and repeat-payment convenience.

**Independent Test**: After purchasing airtime, the service payment appears in the service history with the provider name, amount, date, and token.

**Acceptance Scenarios**:

1. **Given** a user views service history, **When** the screen loads, **Then** past payments are listed in reverse-chronological order with: provider, service type, amount, date, and status.
2. **Given** a user taps a history entry, **When** the detail opens, **Then** they see the full payment record including confirmation reference or token, amount paid, fee, and status.
3. **Given** a user filters by date or service type, **When** the filter is applied, **Then** only matching records are shown.

---

### User Story US-CUST-018 — View Profile (Priority: P0)

A customer can see their personal information on file, including their name, phone number, and KYC verification status.

**Why this priority**: Customers must be able to verify their own information is correct for trust and compliance.

**Independent Test**: An authenticated user navigates to Profile; their registered name, phone number, and KYC status are displayed accurately.

**Acceptance Scenarios**:

1. **Given** a user navigates to "Profile", **When** the screen loads, **Then** they see: full name, phone number (partially masked), email (if set), KYC status, and account creation date.
2. **Given** a user is not yet KYC-verified, **When** viewing their profile, **Then** a prompt to complete KYC is shown with the expected completion benefit.
3. **Given** KYC verification is approved, **When** the user views their profile, **Then** a verification badge or label is displayed.

---

### User Story US-CUST-019 — Edit Profile (Priority: P0)

A customer can update editable profile fields such as email address and display name.

**Why this priority**: Users must be able to keep their contact and display information current.

**Independent Test**: A user updates their email address; the change is saved and reflected immediately on the profile screen.

**Acceptance Scenarios**:

1. **Given** a user taps "Edit Profile", **When** the form loads, **Then** editable fields (e.g., display name, email) are shown pre-filled; non-editable fields (phone number) are clearly marked as read-only.
2. **Given** a user submits valid changes, **When** the save completes, **Then** the profile screen reflects the updated values and a success notification is shown.
3. **Given** a user enters an email address already associated with another account, **When** they try to save, **Then** an error message indicates the email is not available.

---

### User Story US-CUST-020 — Manage Security (Priority: P0)

A customer controls their security settings, including enabling/disabling biometric authentication and changing their PIN.

**Why this priority**: Security configuration is a prerequisite for trust and is required by compliance.

**Independent Test**: A user enables biometric login; on the next app open, the fingerprint/face prompt appears before the PIN screen.

**Acceptance Scenarios**:

1. **Given** a user navigates to security settings, **When** the screen loads, **Then** options are shown for: Change PIN, Enable/Disable Biometric Login, and View Active Sessions.
2. **Given** a user chooses "Change PIN", **When** they enter their current PIN and provide a new one, **Then** the PIN is updated and all other sessions are invalidated.
3. **Given** a user enables biometric authentication, **When** the device supports it, **Then** the next login presents the biometric prompt with PIN as fallback.
4. **Given** a user disables biometric authentication, **When** confirmed, **Then** subsequent logins require PIN entry.

---

### User Story US-CUST-021 — Contact Support (Priority: P1)

A customer can raise a general support inquiry through the app and track its response.

**Why this priority**: Support access is required for trust and regulatory compliance; depends on authentication (P0).

**Independent Test**: A user submits a support inquiry; a ticket is created with a reference number, and the user can view the ticket in their support history.

**Acceptance Scenarios**:

1. **Given** a user navigates to "Support", **When** they select "New Inquiry", **Then** a form is shown for: category selection, description, and optional screenshot attachment.
2. **Given** the inquiry is submitted, **When** the ticket is created, **Then** the user sees a confirmation with a ticket reference number and estimated response time.
3. **Given** a support agent responds, **When** the response is available, **Then** a push notification alerts the user and the ticket detail shows the response.

---

### User Story US-CUST-022 — Raise Dispute (Priority: P1)

A customer can formally dispute a transaction they did not authorize or believe was processed incorrectly.

**Why this priority**: Dispute capability is a regulatory requirement for financial services.

**Independent Test**: A user selects a transaction, raises a dispute with a reason, and receives a dispute reference number; the transaction is flagged in the history view.

**Acceptance Scenarios**:

1. **Given** a user views a transaction detail, **When** they tap "Dispute this transaction", **Then** a dispute form is pre-filled with the transaction reference and prompts for a reason category and description.
2. **Given** a dispute is submitted, **When** processing completes, **Then** the transaction is marked "Disputed" in the history and the user receives a dispute reference number.
3. **Given** the same transaction already has an open dispute, **When** a user tries to raise another, **Then** they are shown the existing dispute reference and blocked from duplicating it.

---

### User Story US-CUST-023 — Track Dispute (Priority: P1)

A customer can check the current status of their open and resolved disputes.

**Why this priority**: Dispute tracking closes the support loop and reduces inbound support contacts.

**Independent Test**: After raising a dispute, the user can navigate to "My Disputes", see the dispute status, and receive a notification when the status changes.

**Acceptance Scenarios**:

1. **Given** a user navigates to "My Disputes", **When** the list loads, **Then** each dispute shows: reference number, linked transaction, status (Open/In Review/Resolved), and date raised.
2. **Given** an admin updates a dispute status, **When** the change is made, **Then** the customer receives a push notification and the dispute detail reflects the new status.
3. **Given** a dispute is resolved, **When** the user views it, **Then** the resolution outcome (approved/rejected and reason) is visible.

---

### Edge Cases

- What happens when the app is backgrounded during a financial transaction confirmation? (Answer: transaction state is preserved; on resume, the in-progress screen is restored or the result is fetched and shown.)
- How does the app behave when the device clock is significantly skewed? (Answer: JWTs fail verification; the user sees a session-expired screen with guidance to sync device time.)
- What happens if push notifications are denied at the OS level? (Answer: in-app notification badge and notification centre still function; no silent degradation of financial alerts.)
- How are very large transaction amounts formatted? (Answer: locale-aware currency formatting with no truncation of the integer portion.)
- What happens if a user has multiple wallets (multi-currency)? (Answer: wallet selector is presented; screens default to the primary wallet.)

---

## Requirements

### Functional Requirements

**Authentication & Session**

- **FR-001**: The app MUST allow a new user to register a wallet account by verifying their phone number via OTP and completing a profile setup and PIN creation flow.
- **FR-002**: The app MUST authenticate returning users via phone number and PIN, with an optional biometric shortcut (fingerprint or face ID) on supported devices.
- **FR-003**: The app MUST enforce a configurable PIN attempt lockout policy; after the configured number of consecutive failures the account is temporarily locked.
- **FR-004**: The app MUST allow a locked-out or forgetful user to reset their PIN using an OTP sent to their registered phone number.
- **FR-005**: The app MUST allow explicit logout, which invalidates the server-side session and clears all locally cached sensitive data.
- **FR-006**: The app MUST display registered devices and allow the user to revoke device sessions individually.
- **FR-007**: The app MUST allow the user to enable or disable biometric authentication from security settings.
- **FR-008**: When the app is backgrounded for longer than the configured timeout, subsequent access MUST require PIN or biometric re-authentication.

**Home & Navigation**

- **FR-009**: The home screen MUST display the customer's current wallet balance prominently, refreshing when the screen is brought into focus.
- **FR-010**: The home screen MUST show a list of the most recent transactions with type, amount, counterparty, and status.
- **FR-011**: The home screen MUST provide shortcut tiles for the most common actions: Send Money, Add Money, Pay Bills, and Receive Money.
- **FR-012**: The app MUST include an in-app notification centre where all received notifications are listed with read/unread state and badge count.
- **FR-013**: The app MUST show a wallet detail screen containing the wallet number, QR code for receiving, and account name.
- **FR-014**: The app MUST support deep-linking from push notifications to the relevant in-app screen.

**Financial Transactions**

- **FR-015**: The app MUST support wallet funding via at least one payment method (bank card or bank transfer); the available methods are driven by backend configuration.
- **FR-016**: The app MUST show a pre-confirmation summary (amount, fee, net debit) before any financial transaction is executed; the customer authorizes with their PIN.
- **FR-017**: The app MUST support wallet-to-wallet transfers, with recipient lookup by phone number or wallet number and counterparty name confirmation before PIN authorization.
- **FR-018**: The app MUST display a receive-money QR code and wallet number that can be shared via the device share sheet.
- **FR-019**: The app MUST support wallet withdrawals to a linked bank account or via agent cash-out; the available methods are driven by backend configuration.
- **FR-020**: All financial mutations submitted from the app MUST include an idempotency key to prevent duplicate processing.
- **FR-021**: The app MUST enforce client-side display of configured transaction limits (minimum, maximum, daily limit) and show the remaining daily allowance.
- **FR-022**: After a financial transaction, the app MUST show a success or failure result screen with a transaction reference, and the transaction MUST appear in the history.

**Services**

- **FR-023**: The app MUST present a catalog of available bill-payment and utility service providers, organized by category, driven by backend configuration.
- **FR-024**: The app MUST support service payment by accepting service-specific input fields (meter number, phone number, etc.), showing a confirmation summary, and requiring PIN authorization.
- **FR-025**: The app MUST display service payment history with confirmation references and tokens.

**Profile & Security**

- **FR-026**: The profile screen MUST display the customer's name, phone number (partially masked), email (if set), KYC verification status, and account creation date.
- **FR-027**: Editable profile fields (display name, email) MUST be updatable from the app; non-editable fields (phone number) MUST be visually locked.
- **FR-028**: The app MUST allow the customer to change their PIN by verifying the current PIN and entering a new one; PIN change MUST invalidate all other sessions.

**Support & Disputes**

- **FR-029**: The app MUST allow a customer to submit a general support inquiry with category, description, and optional attachment; the response is tracked under a reference number.
- **FR-030**: The app MUST allow a customer to raise a formal dispute on any transaction directly from the transaction detail screen.
- **FR-031**: The app MUST provide a dispute tracking screen showing all open and resolved disputes with their current status and resolution outcome.

**Cross-Cutting**

- **FR-032**: The app MUST display user-friendly error messages for all failure scenarios without exposing technical error codes or stack traces.
- **FR-033**: When connectivity is unavailable, the app MUST show a clear offline indicator and display cached data where available; write operations MUST be clearly rejected with a retry option (no offline queuing of financial mutations per Assumptions).
- **FR-034**: All screens containing financial or personally identifiable information MUST prevent screenshots and screen recordings on iOS and Android.
- **FR-035**: The app MUST meet the accessibility requirements defined in Principle 11: keyboard navigation (external keyboard), screen-reader labels, sufficient contrast, and scalable text.
- **FR-036**: Sensitive values (PIN, OTP, card numbers) MUST never appear in app logs, crash reports, or analytics events.

### Key Entities

- **Customer**: Registered, KYC-verified wallet holder; authenticated by phone number and PIN.
- **Wallet**: The customer's e-money account with a balance, currency, and unique wallet number.
- **Transaction**: A financial event (fund, send, receive, withdraw, service payment) with amount, status, fee, and reference.
- **Notification**: An in-app or push message triggered by account events (transactions, security changes, support responses).
- **Device**: A registered mobile device associated with the customer's account; has session state.
- **SupportTicket**: A general inquiry raised by the customer; has reference number, status, and threaded responses.
- **Dispute**: A formal transaction dispute; has reference number, status, and resolution outcome.

---

## Success Criteria

### Measurable Outcomes

- **SC-001**: A new user completes registration (phone verification → profile → PIN) in under 3 minutes on a standard mobile connection.
- **SC-002**: A returning user is authenticated (PIN or biometric) and lands on the home screen within 3 seconds of launching the app.
- **SC-003**: 95% of financial transactions submitted through the app complete successfully (no UI or connectivity failure on the customer side) within 10 seconds.
- **SC-004**: The home screen displays the latest balance within 2 seconds of coming into focus with an active connection.
- **SC-005**: 100% of financial confirmation screens display the correct fee and net amount before the customer authorizes; zero fee surprises post-transaction.
- **SC-006**: A customer who has lost their PIN can regain access via OTP reset in under 5 minutes.
- **SC-007**: All screens pass WCAG 2.1 AA contrast requirements; interactive elements are reachable via external keyboard and labeled for screen readers.
- **SC-008**: The app does not display or log any plaintext PIN, OTP, or card number in test or production environments (verified by security scan).
- **SC-009**: After a financial transaction, the transaction appears in the customer's history within 5 seconds without requiring a manual refresh.
- **SC-010**: The app remains usable in offline mode for all read-only screens (balance, history, profile) using cached data, with a clear offline indicator.

---

## Assumptions

- All backend APIs (authentication, wallet, transactions, KYC, notifications, services, support, disputes) are implemented and stable from E01–E30.
- The Flutter app is built to run on iOS 14+ and Android 8+.
- Push notification infrastructure (FCM/APNs) is available through the notification service (E17).
- Biometric authentication (fingerprint/face ID) is a device-level capability; availability is detected at runtime.
- Currency formatting follows the locale and currency code returned by the wallet API (no hardcoded symbols). Currency examples in user story descriptions (e.g., BWP, KES) are illustrative only; the actual currency is API-driven per each customer's wallet configuration.
- The app operates in online mode for all financial write operations; no offline queuing of financial mutations.
- OTP delivery relies on the SMS notification channel from E17; delivery time is assumed to be under 60 seconds.
- The design system tokens (colors, typography, spacing) are provided by the shared Flutter design package.
- Step-up authentication thresholds (for high-value transactions) are enforced by the backend; the app only handles the step-up challenge prompt when the backend requests it.
- Multi-currency wallets are handled via a wallet selector; the primary wallet is pre-selected.
