# Feature Specification: E32 — Agent Application

**Feature Branch**: `032-agent-application`

**Created**: 2026-10-05

**Status**: Draft

**Backlog stories**: US-AGAPP-001 – US-AGAPP-019 (19 stories from the approved 538-story backlog)

## Overview

The Agent Application is a mobile app for field agents who operate cash-in and cash-out points on behalf of customers. Agents accept physical cash from customers and load funds into their wallets (cash-in), and pay out physical cash to customers who withdraw from their wallets (cash-out). Agents also register new customers, initiate identity verification, and manage their own float wallet and commission earnings.

The application must function reliably on mid-range Android and iOS devices in areas with intermittent mobile data coverage.

---

## User Scenarios & Testing

### US-AG-001 — Agent Authentication & PIN Management (P0)
*Backlog: US-AGAPP-001 (login), US-AGAPP-002 (logout), US-AGAPP-003 (PIN reset)*

An agent signs in to the app using their registered phone number and 6-digit PIN. On supported devices the agent may use biometric authentication (fingerprint or face recognition) in place of PIN entry after the initial login. The agent can log out at any time, which clears all cached session data. If the PIN is forgotten, the agent can reset it via an OTP sent to their registered phone number.

**Why this priority**: No agent functionality is accessible without authentication. Secure identity management is a compliance requirement.

**Independent Test**: An agent with valid credentials can open the app, authenticate, reach the dashboard, and log out. A forgotten-PIN flow sends an OTP, accepts a new PIN, and the new PIN works on the next login.

**Acceptance Scenarios**:

1. **Given** a registered agent with a valid phone and PIN, **When** they enter their phone number and PIN, **Then** they are granted access to the agent dashboard within 5 seconds.
2. **Given** an agent with 3 consecutive failed PIN attempts, **When** they fail a 4th time, **Then** the account is temporarily locked for a configurable cooldown period and a lockout message is shown.
3. **Given** biometric authentication is enabled on the device, **When** the agent opens the app after a fresh session, **Then** the device biometric prompt is shown first and falls back to PIN if biometric fails.
4. **Given** an authenticated agent, **When** they tap "Log Out", **Then** the session is invalidated server-side, all locally cached auth tokens are cleared, and the login screen is shown.
5. **Given** an agent who forgot their PIN, **When** they tap "Forgot PIN" and complete the OTP verification, **Then** they can set a new PIN and immediately log in with it.
6. **Given** the app has been backgrounded for more than 5 minutes (configurable), **When** the agent returns to the app, **Then** re-authentication is required before any operation is allowed.

---

### US-AG-002 — Agent Dashboard (P0)
*Backlog: US-AGAPP-004 (view float), US-AGAPP-005 (view daily transactions)*

After login, the agent sees a dashboard showing their current float balance, today's transaction count and total volume, quick-action shortcuts to the main functions (Cash-In, Cash-Out, Register Customer, Transactions), and any unread notification badges.

**Why this priority**: The dashboard is the entry point for all agent operations. Float visibility is critical for operational decisions.

**Independent Test**: After login, the dashboard displays the agent's float balance, today's transaction summary, and at least 4 shortcut tiles that navigate to the correct screens.

**Acceptance Scenarios**:

1. **Given** an authenticated agent, **When** the dashboard loads, **Then** the current float balance is displayed prominently and formatted in the agent's operating currency.
2. **Given** the dashboard is visible, **When** a cash-in or cash-out completes, **Then** the float balance and today's totals update automatically within 10 seconds without a full page reload.
3. **Given** the agent's float balance falls below a configurable low-float threshold, **When** the dashboard loads, **Then** a distinct warning indicator is shown and a "Request Float Top-Up" shortcut is surfaced.
4. **Given** the device is offline, **When** the dashboard loads, **Then** the last-known float balance is shown with a "last updated" timestamp and an offline indicator; cash-in/cash-out shortcuts are disabled.

---

### US-AG-003 — Cash-In (Deposit Money for Customer) (P0)
*Backlog: US-AGAPP-011, US-AGAPP-007*

An agent accepts physical cash from a customer and deposits an equivalent amount into the customer's wallet. The agent searches for the customer by phone number or wallet number, confirms the customer's identity, enters the amount received, reviews a fee summary, authorizes with their PIN, and receives a digital receipt on success. The agent's float wallet decreases by the deposited amount plus fees.

**Why this priority**: Cash-in is the primary revenue-generating operation for agents.

**Independent Test**: An agent can search for a customer, enter a valid amount, authorize with PIN, and the customer's wallet increases while the agent's float decreases. A receipt is generated.

**Acceptance Scenarios**:

1. **Given** a valid customer phone number, **When** the agent searches, **Then** the customer's name and masked wallet number are shown for identity confirmation within 3 seconds.
2. **Given** the agent enters a valid amount, **When** they tap "Review", **Then** a confirmation screen shows: customer name, amount, fee, net credited to customer wallet, and the agent float debit.
3. **Given** the agent confirms and enters their PIN, **When** the transaction is submitted, **Then** the customer's wallet is credited, the agent's float is debited, a reference number is shown, and a receipt can be shared.
4. **Given** the agent submits twice with the same idempotency key, **When** the second submission arrives, **Then** the original result is returned without creating a duplicate transaction.
5. **Given** the agent's float balance is insufficient for the requested amount, **When** they tap "Review", **Then** an error is shown indicating insufficient float and the transaction is blocked.
6. **Given** the amount exceeds the step-up threshold (configurable, default 10,000 BWP equivalent), **When** the agent confirms, **Then** an additional step-up PIN challenge is required before the transaction is processed.

---

### US-AG-004 — Cash-Out (Withdrawal from Customer Wallet) (P0)
*Backlog: US-AGAPP-012, US-AGAPP-007*

An agent pays out physical cash to a customer who is withdrawing from their wallet. The customer presents a withdrawal pickup code or QR code. The agent scans or enters the code, confirms the amount and customer identity, authorizes with their PIN, pays out the cash, and receives a receipt. The agent's float wallet increases by the withdrawn amount minus fees.

**Why this priority**: Cash-out is a high-volume daily operation and is critical to agent liquidity management.

**Independent Test**: An agent can scan/enter a pickup code, review the amount, authorize with PIN, and the transaction completes with a receipt. Agent float increases.

**Acceptance Scenarios**:

1. **Given** a valid pickup code, **When** the agent enters or scans it, **Then** the customer name, masked wallet number, and amount to pay out are shown within 3 seconds.
2. **Given** the agent confirms and enters their PIN, **When** the transaction is submitted, **Then** the customer's wallet is debited, the agent's float is credited, and a receipt is generated.
3. **Given** a pickup code that has already been used, **When** the agent scans it, **Then** an error is shown indicating the code has been redeemed.
4. **Given** a pickup code that has expired, **When** the agent scans it, **Then** an expiry error is shown.
5. **Given** the amount exceeds the step-up threshold, **When** confirmed, **Then** a step-up PIN challenge is required.

---

### US-AG-005 — Customer Onboarding (P0)
*Backlog: US-AGAPP-008 (register customer), US-AGAPP-009 (initiate KYC), US-AGAPP-010 (view customer status)*

An agent registers new customers who do not yet have an account. The agent captures the customer's personal details (name, phone, date of birth, national ID number), submits the registration on their behalf, and initiates the KYC identity verification process by capturing a photo of the customer's identity document. The agent can also look up existing customers to check their KYC and account status.

**Why this priority**: Customer acquisition is a core agent mandate in the network expansion strategy.

**Independent Test**: An agent registers a new customer with valid details, initiates KYC with an ID document photo, and the new customer account is created with pending-KYC status.

**Acceptance Scenarios**:

1. **Given** the agent enters valid customer details (name, phone, DOB, national ID), **When** they submit the registration, **Then** a new customer account is created with pending-KYC status and a wallet is provisioned.
2. **Given** a customer account already exists with the entered phone number, **When** the agent submits, **Then** a "Customer already registered" message is shown with their account status.
3. **Given** a newly registered customer, **When** the agent taps "Initiate KYC", **Then** the agent can capture the ID document front (and optionally back) and submit it for review.
4. **Given** an existing customer's phone number, **When** the agent searches, **Then** the customer's name, account status, and KYC status are shown.
5. **Given** the device camera is unavailable for KYC capture, **When** the agent tries to initiate KYC, **Then** a helpful error is shown explaining the camera requirement.

---

### US-AG-006 — Transaction History (P0)
*Backlog: US-AGAPP-013 (view history), US-AGAPP-014 (generate receipt)*

An agent views a scrollable, filterable list of all transactions they have processed. Tapping a transaction shows the full details. The agent can generate and share a receipt for any past transaction (share via SMS, messaging apps, or print).

**Why this priority**: Transaction history is essential for dispute resolution, daily reconciliation, and customer service.

**Independent Test**: An agent can view their transaction list, filter by type and date, tap a transaction to see details, and share a receipt.

**Acceptance Scenarios**:

1. **Given** the transactions screen is open, **When** it loads, **Then** all agent transactions are shown in reverse chronological order, paginated.
2. **Given** the agent applies a type filter (Cash-In / Cash-Out / Float), **When** the filter is applied, **Then** only matching transactions are shown.
3. **Given** the agent taps a transaction, **When** the detail screen opens, **Then** reference number, type, customer name, amount, fee, net, and timestamp are shown.
4. **Given** the agent taps "Share Receipt" on any completed transaction, **When** the receipt is generated, **Then** a printable/shareable format is produced with transaction details, agent name, and timestamp.
5. **Given** the app is offline, **When** the agent views previously loaded transactions, **Then** the cached list is shown with an offline indicator.

---

### US-AG-007 — Float Management (P1)
*Backlog: US-AGAPP-004 (view float), US-AGAPP-015 (view float), US-AGAPP-016 (request float funding), US-AGAPP-017 (view float history)*

An agent monitors their float wallet balance and requests additional float from the head office or supervisor when it runs low. The agent can view float transaction history (top-ups received, debits from cash-outs, credits from cash-ins).

**Why this priority**: Float management is operational but not blocking for single transactions; low-float requests prevent service disruptions.

**Independent Test**: An agent can view float balance and history, and submit a float top-up request that creates a pending request visible to the administrator.

**Acceptance Scenarios**:

1. **Given** the float management screen is open, **When** it loads, **Then** the current float balance, the last top-up date, and a mini transaction history are shown.
2. **Given** the agent taps "Request Float Top-Up", **When** they enter an amount and submit, **Then** a funding request is created with pending status, an acknowledgement reference is shown, and the agent's supervisor is notified.
3. **Given** a float request is already pending, **When** the agent tries to submit another request, **Then** a warning is shown that a request is already in review; the agent can submit a follow-up with a note.
4. **Given** the float history screen is open, **When** filtered by date range, **Then** only matching entries are shown with type labels (cash-in credit, cash-out debit, top-up).

---

### US-AG-008 — Commission Tracking (P1)
*Backlog: US-AGAPP-006 (view commissions), US-AGAPP-018 (view commission), US-AGAPP-019 (view commission history)*

An agent views their earned commission balance and a breakdown of commissions earned per transaction type. A commission history screen shows all commission entries with dates, amounts, and associated transaction references.

**Why this priority**: Commission visibility directly affects agent motivation and transparency in the agent network.

**Independent Test**: An agent can view their total commission balance and a list of commission entries with transaction references.

**Acceptance Scenarios**:

1. **Given** the commissions screen is open, **When** it loads, **Then** the total earned commission balance is displayed, broken down by transaction type (cash-in, cash-out, registration).
2. **Given** the agent views commission history, **When** it loads, **Then** all commission entries are listed with: date, type, amount, rate applied, and linked transaction reference.
3. **Given** the agent filters commission history by type or date range, **When** the filter is applied, **Then** only matching entries are shown.
4. **Given** a commission is credited after a cash-in/cash-out, **When** the agent refreshes the commissions screen, **Then** the new entry appears and the balance is updated.

---

### US-AG-009 — Notifications & Security Settings (P2)
An agent receives push notifications for significant events: float top-up approved/rejected, commission credited, system alerts, and account security events. The agent can manage their notification preferences, change their PIN, and view trusted devices.

**Why this priority**: Notifications keep agents informed of time-sensitive operational events; security settings ensure compliance.

**Independent Test**: An agent receives a push notification when a float top-up request is processed, and can successfully change their PIN.

**Acceptance Scenarios**:

1. **Given** a float top-up request is approved by admin, **When** the approval is processed, **Then** the agent receives a push notification with the approved amount.
2. **Given** the agent taps a notification, **When** it opens, **Then** they are navigated to the relevant screen (e.g., float screen for a top-up, transactions for a commission).
3. **Given** the agent opens security settings, **When** they change their PIN, **Then** the current PIN is required, the new PIN is set server-side, and all other sessions are invalidated.

---

## Functional Requirements

### Authentication & Session

- **FR-001**: Agent authentication requires a registered phone number and 6-digit PIN. PIN must be hashed before transmission (bcrypt). Plaintext PIN must never be logged.
- **FR-002**: Biometric authentication (fingerprint, face recognition) is an optional additive layer. When enabled, biometric is attempted first; PIN entry is always available as fallback.
- **FR-003**: Failed PIN attempts are counted server-side. After a configurable number of consecutive failures (default 5), the account is temporarily locked for a configurable duration. The remaining attempt count is shown when attempts_remaining < 3.
- **FR-004**: Authenticated sessions expire after a configurable idle timeout. The app must re-authenticate the agent when the app is foregrounded after being backgrounded for more than 5 minutes (configurable).
- **FR-005**: Logout must invalidate the session server-side and clear all locally stored authentication tokens. Logout must succeed even if the network is unavailable (local clear with a best-effort server call).
- **FR-006**: PIN reset uses an OTP sent to the agent's registered phone number. The OTP is valid for a configurable duration (default 10 minutes). A new PIN must be confirmed by entering it twice.

### Dashboard & Float Display

- **FR-007**: The agent dashboard displays: current float balance (formatted in the operating currency), today's transaction count and total volume (cash-in and cash-out separately), and shortcut tiles for Cash-In, Cash-Out, Register Customer, and View Transactions.
- **FR-008**: A low-float warning indicator is shown when the float balance falls below a configurable threshold. The "Request Float Top-Up" action is prominently surfaced when the warning is active.
- **FR-009**: Float balance displayed on the dashboard must reflect the server-side balance. The last-updated timestamp is shown alongside the balance.

### Cash-In & Cash-Out

- **FR-010**: Cash-in requires the agent to search for a customer by phone number or wallet number before entering an amount.
- **FR-011**: Before submitting a cash-in or cash-out, the agent must review a confirmation screen showing: customer name, amount, applicable fee, net amount credited/debited, and the agent float impact.
- **FR-012**: All cash-in and cash-out submissions must include a client-generated idempotency key. A 409 response must be handled idempotently — the original result is returned without surfacing an error.
- **FR-013**: Cash-in and cash-out are blocked when the agent is offline. An explicit error dialog is shown; no offline queuing of financial mutations.
- **FR-014**: Agent PIN authorization is required for every cash-in and cash-out transaction.
- **FR-015**: For transaction amounts exceeding the step-up threshold (configurable; default 10,000 BWP equivalent), a step-up authentication challenge is required in addition to the transaction PIN.
- **FR-016**: A digital receipt is generated on every successful cash-in and cash-out. The receipt includes: reference number, transaction type, customer name, amount, fee, net, agent name, agent location code, timestamp, and a QR code linking to the transaction record.
- **FR-017**: Receipts can be shared via the device native share sheet (SMS, messaging apps, print).

### Customer Onboarding

- **FR-018**: Customer registration by agent requires: first name, last name, phone number, date of birth, and national ID number. All fields are mandatory.
- **FR-019**: Phone number uniqueness is enforced server-side. A clear "already registered" message is shown if the phone number is already in use.
- **FR-020**: KYC initiation requires capture of the customer's national ID document (front face, back face optional). Documents are submitted to the KYC service for automated review.
- **FR-021**: After submitting a registration, the agent can immediately view the new customer's account and KYC status.

### Transaction History

- **FR-022**: The transaction history screen shows all agent-processed transactions in reverse chronological order. The list supports pagination.
- **FR-023**: Transaction history supports filtering by type (Cash-In, Cash-Out, Float Top-Up) and by date range.
- **FR-024**: Receipt generation is available for any completed transaction from the detail screen.

### Float Management

- **FR-025**: The float management screen shows the current float balance, total debits and credits for the current calendar month, and a paginated float transaction history.
- **FR-026**: Float top-up requests specify a desired amount and an optional note. The request is submitted to the head office/supervisor for approval. Only one pending request is allowed at a time unless the prior request has been resolved.
- **FR-027**: The float history shows all float movements: cash-in credits, cash-out debits, and top-up receipts, with timestamps and reference numbers.

### Commissions

- **FR-028**: The commission screen shows the agent's total earned commission balance and a breakdown by transaction type (cash-in, cash-out, customer registration).
- **FR-029**: Commission history is paginated and supports filtering by type and date range. Each entry shows: date, type, rate applied, earned amount, and the linked transaction reference.

### Offline & Connectivity

- **FR-030**: The app detects connectivity status. An offline banner is shown when the device is not connected to the internet.
- **FR-031**: Read-only screens (transaction history, commission history, float history) serve cached data when offline, clearly indicating the data may not be current.
- **FR-032**: Financial write operations (cash-in, cash-out, customer registration, float requests) are blocked when offline and show a clear error message. No background queuing of financial mutations.

### Security & Privacy

- **FR-033**: Screenshot prevention is active on all screens showing financial data, customer PII, or agent credentials.
- **FR-034**: PIN values, OTPs, and authentication tokens must never appear in application logs, crash reports, or analytics events.
- **FR-035**: All financial confirmation screens require agent PIN authorization.

### Accessibility & Design

- **FR-036**: All interactive elements must have accessible labels. Minimum touch target size is 48×48 dp.
- **FR-037**: The application follows the shared design token system (colors, typography, spacing) established for the wallet ecosystem.
- **FR-038**: Critical financial and status states (success, error, pending, offline, locked) must be visually distinct and not rely on color alone.

---

## Success Criteria

| ID | Criterion | Measurement |
|----|-----------|-------------|
| SC-01 | Agent authentication completes successfully | Agent reaches dashboard within 5 seconds of entering valid credentials |
| SC-02 | Cash-in transaction completes end-to-end | Customer wallet credited and agent float debited within 10 seconds; receipt generated |
| SC-03 | Cash-out transaction completes end-to-end | Customer wallet debited and agent float credited within 10 seconds; receipt generated |
| SC-04 | Customer registration | New account with pending-KYC status created within 15 seconds |
| SC-05 | Idempotency validated | Submitting the same cash-in/cash-out twice returns the same result without a duplicate transaction |
| SC-06 | Offline resilience | App remains usable for read operations when offline; all financial writes are clearly rejected |
| SC-07 | Float top-up request | Submitted float request appears as pending in the admin portal within 30 seconds |
| SC-08 | Receipt sharing | Receipt generated and native share sheet opens within 3 seconds of tapping "Share" |
| SC-09 | PIN change | New PIN accepted within 5 seconds; old PIN rejected on next login attempt |
| SC-10 | Dashboard performance | Dashboard loads with current float balance within 5 seconds of login on a standard 3G connection |

---

## Key Entities

| Entity | Description |
|--------|-------------|
| Agent | Field agent with credentials, PIN, float wallet, commission account, and linked location |
| AgentSession | Active session with expiry, device ID, and push token |
| FloatWallet | Agent's operational wallet used to fund cash-in and receive cash-out credits |
| FloatTopUpRequest | Request from agent to head office for additional float; has pending/approved/rejected states |
| CashInTransaction | Customer deposit facilitated by agent; creates ledger entries for customer wallet and agent float |
| CashOutTransaction | Customer withdrawal facilitated by agent; creates ledger entries for customer wallet and agent float |
| Commission | Earnings credited to agent per transaction; linked to source transaction and commission rule |
| Receipt | Immutable snapshot of transaction details for sharing; linked to source transaction |
| Customer | Wallet customer registered or serviced by agent |
| KycSubmission | Identity document submission created by agent on behalf of customer |

---

## Edge Cases

- **Agent with zero float**: Cash-out must be blocked; dashboard must show the zero balance prominently with a top-up prompt.
- **Customer wallet suspended**: Cash-in to a suspended wallet must fail with a clear, actionable error message (not a generic error).
- **Duplicate receipt sharing**: Generating a receipt for an already-receipted transaction returns the existing receipt without creating a duplicate entry.
- **Large cash-in (step-up threshold crossed)**: Step-up challenge fires during the flow after the PIN step; the original pending request must be retained so the agent doesn't need to re-enter data.
- **OTP expiry during PIN reset**: If the OTP expires before the agent submits the new PIN, the OTP entry screen shows an expiry message and "Resend OTP" becomes available.
- **Concurrent cash-out redemption**: Two agents attempting to redeem the same pickup code simultaneously — only the first succeeds; the second receives a "code already redeemed" error.
- **KYC document upload failure**: If the document upload fails (large file, network drop), the agent is shown a retry option without losing the captured image.
- **Float request with pending approval**: Submitting a second float top-up request while one is pending shows a warning and asks the agent to confirm the follow-up request.

---

## Out of Scope

- Agent-to-agent transfers (not in the approved backlog for E32)
- Agent salary management or payroll features
- Multi-currency agent float (handled by E29; the agent operates in their configured operating currency)
- Customer wallet-to-wallet transfers by the agent on behalf of customers (customers do this themselves in E31)
- Admin portal agent management screens (handled by E12 and E19)
- Offline data synchronization or transaction queuing for financial mutations

---

## Dependencies

- **E01** (Authentication): Agent login/logout/PIN reset APIs
- **E08** (Wallet Funding): Cash-in API for agent-facilitated deposits
- **E10** (Withdrawal): Cash-out API with pickup code redemption
- **E12** (Agent Management): Agent profile, status, and device management APIs
- **E13** (Agent Float Management): Float balance, history, and top-up request APIs
- **E14** (Agent Commission): Commission balance and history APIs
- **E05** (KYC): KYC initiation API for agent-assisted identity verification
- **E03** (Device & Session Management): Device registration and session management
- **E17** (Notifications): Push notification delivery for float and commission events

---

## Assumptions

- The Agent Application is a Flutter mobile app for iOS and Android (consistent with Principle 10 and E31 architecture).
- Agents authenticate using the same centralized auth service as customers, with the "agent" role context.
- The operating currency for each agent is determined by their account configuration; the app does not hard-code any specific currency.
- Receipt generation produces a visual document (image or PDF) that can be shared via the device native share sheet.
- The step-up authentication threshold is backend-configurable and defaults to 10,000 BWP equivalent (per constitution Principle 5).
- QR code scanning for customer lookup and cash-out pickup codes uses the device camera.
- Float top-up approval is managed by supervisors/admins through the Admin Portal (E19/E12); the agent app only submits the request and views its status.
- Push notifications are delivered via FCM (Android) and APNs (iOS), consistent with E17 and E31.
- The agent app shares the same design token system and component patterns established by E31; no separate design language is introduced.
