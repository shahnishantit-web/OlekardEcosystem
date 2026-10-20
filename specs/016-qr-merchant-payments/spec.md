# Feature Specification: E16 — QR & Merchant Payments

**Feature Branch**: `016-qr-merchant-payments`

**Created**: 2026-09-25

**Status**: Draft

## Overview

QR & Merchant Payments enables customers to pay registered merchants by scanning a QR code displayed at the point of sale. The merchant can present a **static QR** (persistent code with no pre-set amount — customer enters how much to pay) or a **dynamic QR** (one-time code encoding a specific amount and optional reference, valid for a configurable window). The system validates the merchant, shows the customer a clear payment summary (merchant name, amount, fee), then executes the payment as an atomic triple-leg double-entry ledger journal — debiting the customer's wallet (principal + fee), crediting the merchant's wallet (principal), and crediting the platform fee wallet (fee); all three legs are balanced and committed atomically. A receipt is generated for the customer, and the merchant is notified of the successful payment.

This is the first merchant-facing payment category in the ecosystem. It builds on E06 Wallet Management, E07 Wallet Ledger, and the financial integrity patterns established by E09 Wallet-to-Wallet Transfer. It introduces the Merchant entity, the QR code lifecycle, and sets the pattern for future merchant-initiated payment channels.

## Clarifications

### Session 2026-09-25

- Q: Does E16 cover both merchant-presented QR (merchant displays QR, customer scans) and customer-presented QR (customer displays QR, merchant scans/charges), or only one direction? → A: Merchant-presented QR only (MPQR) — the merchant generates and displays the QR; the customer scans and pays. Customer-presented QR is out of scope.
- Q: Is merchant registration and KYC part of E16 scope, or does E16 assume merchants already exist in the system from prior epics? → A: E16 assumes active, verified merchants already exist (onboarded via E05 KYC, E04 Roles, and E12 processes); E16 does not include merchant registration — it consumes the Merchant entity.
- Q: Should QR payload signatures use a platform-wide HMAC key or per-merchant signing keys? → A: Platform-wide HMAC key with key rotation support; existing dynamic QRs are invalidated on rotation (they expire naturally); static QRs must be re-generated after rotation.
- Q: If the first customer who begins confirming a dynamic QR fails or abandons, should the QR unlock for another customer? → A: Yes — single-success model: the QR is unlocked (returns to `active`) if the payment fails or the customer abandons; only a *completed* payment permanently marks the QR as `used`. The reservation window is configurable to prevent indefinite locking on abandonment.
- Q: Can a single merchant have multiple simultaneously active static QR codes, or is each merchant limited to one? → A: Unlimited active static QRs per merchant; each may carry an optional merchant-supplied label (e.g., "Counter 1", "Table 2") to distinguish codes at the point of sale; no uniqueness constraint is enforced on active static QRs per merchant.

## Actors

- **Merchant** — A registered business with an active merchant wallet; generates static and dynamic QR codes; receives funds from customer QR payments; is notified on payment completion
- **Customer** — Authenticated wallet holder who scans a merchant QR code and authorizes the payment
- **Platform (System)** — Validates merchants and QR payloads, executes the atomic debit/credit ledger transaction, generates receipts, and emits payment events for downstream notification delivery

## User Scenarios & Testing

### User Story 0 — QR Code Generation & Management (Priority: P0)

A merchant needs a way to accept QR payments. They can generate a **static QR** (a persistent code tied to their merchant wallet — no amount; the customer enters the amount at scan time) or a **dynamic QR** (a one-time code encoding a specific amount and an optional merchant reference, valid for a runtime-configurable window). Merchants can view their active QR codes and deactivate any code they no longer want to accept.

**Why this priority**: Blocking prerequisite — without a QR code to present, no payment flow can begin. Every subsequent user story depends on at least one active QR code existing.

**Independent Test**: An authenticated merchant generates a static QR; the system returns a payload encoding the merchant ID with no amount. The same merchant generates a dynamic QR for BWP 50.00; the payload encodes the amount and an expiry timestamp. Scanning the dynamic QR before expiry resolves correctly; attempting to scan the same dynamic QR after a second payment attempt returns `QR_ALREADY_USED`.

**Acceptance Scenarios**:

1. **Given** an authenticated merchant with an active merchant wallet, **When** they request a static QR, **Then** the system generates a persistent, signed QR payload encoding their merchant ID and currency; the QR has no expiry; it is stored with status `active` and is retrievable at any time.
2. **Given** an authenticated merchant, **When** they request a dynamic QR specifying an amount (and optional merchant reference), **Then** the system generates a one-time signed QR payload encoding the merchant ID, amount, currency, a unique nonce, and an expiry timestamp; the QR is stored with status `active`; the expiry window is controlled by a runtime-configurable platform setting.
3. **Given** a dynamic QR that has passed its expiry time, **When** a customer submits it for scan resolution, **Then** the system returns `QR_EXPIRED`; no payment proceeds; the QR transitions to `expired` status.
4. **Given** a dynamic QR that has already been used for a completed payment, **When** any actor submits it again, **Then** the system returns `QR_ALREADY_USED`; no duplicate payment proceeds.
5. **Given** an active QR code, **When** the merchant deactivates it, **Then** the QR transitions to `inactive` status; subsequent scan attempts return `QR_NOT_ACTIVE`.
6. **Given** an unauthenticated actor or a non-merchant actor, **When** they attempt to generate a QR, **Then** the request is rejected with `FORBIDDEN`; no QR code is created.

---

### User Story 1 — QR Scan & Payment Discovery (Priority: P1)

Maps to: US-QR-002, US-QR-003, US-QR-004

A customer opens their wallet app and scans a merchant QR code. The app decodes the payload and submits it to the platform. The platform validates the QR signature, confirms the merchant exists and their wallet is active, and returns the payment details: merchant display name, amount (if dynamic), currency, calculated fee, and total payable. The customer reviews this information on a clear confirmation screen before deciding whether to pay.

**Why this priority**: The scan-and-display step is the entry point for every payment; the customer must see trustworthy, verified merchant information and a clear fee breakdown before any money moves.

**Independent Test**: A customer submits a valid static QR payload; the system returns the merchant display name and currency; the customer enters BWP 20.00; the system returns the fee and total. A customer submits a valid dynamic QR; the system returns the pre-set amount, fee, and total without requiring amount input. A customer submits an expired or inactive QR; the system returns a clear error.

**Acceptance Scenarios**:

1. **Given** a valid static QR payload, **When** a customer submits it for resolution, **Then** the system returns the merchant's display name, wallet currency, and `amount_required: true` (customer must enter an amount); the merchant's wallet is verified active.
2. **Given** a valid dynamic QR payload, **When** a customer submits it for resolution, **Then** the system returns the merchant's display name, the pre-set amount, currency, calculated fee, and total payable; no amount input is required from the customer.
3. **Given** a QR payload referencing a merchant that is inactive, suspended, or does not exist, **When** submitted, **Then** the system returns `MERCHANT_NOT_ACTIVE`; no payment details are displayed.
4. **Given** a static QR and a customer-entered payment amount, **When** the customer requests a fee quote, **Then** the system returns the applicable fee (from the platform fee schedule), the total payable, and the currency; if the amount is outside the allowed range, the system returns `AMOUNT_OUT_OF_RANGE`.
5. **Given** a QR payload with an invalid or unrecognizable signature, **When** submitted, **Then** the system returns `INVALID_QR_PAYLOAD`; no payment details are returned; the attempt is audit-logged.
6. **Given** an unauthenticated request, **When** any scan or discovery endpoint is called, **Then** the request is rejected with `UNAUTHORIZED`.

---

### User Story 2 — QR Payment Execution (Priority: P1)

Maps to: US-QR-005, US-QR-006, US-QR-007

The customer reviews the payment summary and confirms. The system creates a `QRPaymentTransaction` record in `initiated` status (capturing the customer's intent before any money moves), then executes the payment atomically: debit the customer's wallet (principal + fee) and credit the merchant's wallet (principal) in one balanced double-entry ledger journal. No partial outcome is permitted — if the wallet debit fails, no merchant credit occurs; if any failure occurs after the debit, a compensating entry immediately restores the customer's full balance. An `Idempotency-Key` is required to prevent duplicate charges.

**Why this priority**: This is the financial core. Atomic execution and ledger integrity are non-negotiable.

**Independent Test**: A customer with sufficient balance confirms a BWP 50.00 QR payment; the customer wallet is debited BWP 50.00 + fee; the merchant wallet is credited BWP 50.00; a balanced ledger journal is created; the `QRPaymentTransaction` is marked `completed`; re-submitting the same `Idempotency-Key` returns the original transaction without a second debit.

**Acceptance Scenarios**:

1. **Given** a customer with sufficient balance confirms a QR payment with an `Idempotency-Key`, **When** they submit the confirmation, **Then** a `QRPaymentTransaction` is first created in `initiated` status; the customer wallet is debited (principal + fee); the merchant wallet is credited (principal); the balanced ledger journal is created (debits equal credits); the transaction transitions to `completed`; a `QRPaymentCompleted` event is emitted.
2. **Given** a customer with insufficient balance, **When** they attempt to confirm a QR payment, **Then** a `QRPaymentTransaction` is created in `initiated` status; the request fails with `INSUFFICIENT_BALANCE`; no ledger entry is created; no merchant credit occurs; the customer's balance is unchanged.
3. **Given** the same `Idempotency-Key` submitted twice, **When** the second request arrives, **Then** the system returns the original transaction response without creating a duplicate debit or merchant credit.
4. **Given** a payment amount at or above the platform-configured step-up threshold, **When** the customer confirms, **Then** the system requires a valid step-up authentication token before debiting the wallet; a missing or invalid token returns `STEP_UP_REQUIRED`; no debit occurs.
5. **Given** an actor attempting to confirm a payment from a wallet that is not theirs, **When** the request is submitted, **Then** it is rejected with `FORBIDDEN`; no debit occurs.
6. **Given** a dynamic QR that expires between scan and confirmation, **When** the customer confirms, **Then** the request fails with `QR_EXPIRED`; no debit occurs.
7. **Given** a merchant wallet that is suspended between the scan and the customer's confirmation, **When** the customer confirms, **Then** the request fails with `MERCHANT_NOT_ACTIVE`; no debit occurs; the customer's balance is unchanged.
8. **Given** concurrent QR payment confirmation requests with the same `Idempotency-Key` from the same customer wallet, **When** both arrive simultaneously, **Then** exactly one payment is processed; the second returns the original response; no double-debit or double-credit occurs.
9. **Given** a dynamic QR in `reserved` status (a first customer began confirming but their payment failed due to insufficient balance), **When** the first customer's payment fails, **Then** the QR returns to `active` status; a different customer may then successfully confirm payment against it.
10. **Given** a dynamic QR in `reserved` status where the reserving customer abandons without completing the flow, **When** the configurable reservation window expires, **Then** the QR automatically returns to `active` status; subsequent confirmation attempts by any customer are permitted.

---

### User Story 3 — Receipt Generation & Merchant Notification (Priority: P1)

Maps to: US-QR-008, US-QR-009

On successful payment completion, an immutable receipt is created for the customer containing all information needed to verify the payment: merchant name, amount paid, fee, total debited, currency, merchant reference (if any), and a unique receipt reference. A `QRPaymentCompleted` event is emitted so that E17 Notifications can deliver a real-time notification to the merchant. The receipt is retrievable from the customer's payment history at any time.

**Why this priority**: Customers need verifiable proof of payment; merchants need real-time confirmation so they can release goods or services at the point of sale. Both are critical to merchant trust in the platform.

**Independent Test**: A completed `QRPaymentTransaction` has a retrievable receipt with all required fields and a unique receipt reference. Re-fetching returns identical data without regeneration. A pending or failed transaction returns `RECEIPT_NOT_AVAILABLE`.

**Acceptance Scenarios**:

1. **Given** a completed QR payment, **When** the customer requests the receipt, **Then** the response includes: unique receipt reference, merchant display name, amount paid (principal), fee charged, total debited, currency, merchant reference (if any), payment timestamp, and status `completed`.
2. **Given** a completed payment, **When** the receipt is requested multiple times, **Then** the same immutable receipt is returned without regeneration or modification.
3. **Given** a pending or failed QR payment, **When** a receipt is requested, **Then** the system returns `RECEIPT_NOT_AVAILABLE` with the current transaction status.
4. **Given** a completed QR payment, **When** the transaction is finalized, **Then** a `QRPaymentCompleted` event is emitted containing the merchant ID, amount, and payment reference, enabling E17 Notifications to deliver a real-time push or SMS notification to the merchant's registered devices.
5. **Given** a customer attempting to view a receipt for a transaction that is not theirs, **When** they request it, **Then** the request returns `NOT_FOUND`; ownership is enforced by scoping the transaction lookup to the authenticated customer's wallet ID.

---

### Edge Cases

1. **QR payload tampered** — HMAC signature verification fails; system returns `INVALID_QR_PAYLOAD`; the attempt is audit-logged for security review; no payment proceeds.
2. **Merchant wallet suspended mid-payment** — Merchant wallet is deactivated between scan and confirmation; re-validation at confirmation time catches this and returns `MERCHANT_NOT_ACTIVE`; no debit occurs.
3. **Race condition on dynamic QR (two customers scan simultaneously)** — Two customers scan the same dynamic QR before either confirms. At the moment the first customer submits a confirmation, the QR transitions to `reserved` status for a configurable reservation window. If the first customer's payment completes successfully, the QR transitions to `used` — the second customer's confirmation attempt returns `QR_ALREADY_USED`. If the first customer's payment fails or the reservation window expires without a completed payment, the QR returns to `active` and the second customer (or any other) may then confirm payment against it.
4. **Amount mismatch between scan and confirm** — If a customer sends a different amount at confirmation than was returned at scan resolution, the server re-validates against the QR record; a mismatch returns `QR_PAYLOAD_MISMATCH`; no debit occurs.
5. **Customer wallet currency differs from merchant wallet currency** — Rejected with `CURRENCY_MISMATCH`; multi-currency QR payments are out of scope (deferred to E29).
6. **Step-up token expired between generation and submission** — Token validation fails at the payment confirmation step; system returns `STEP_UP_TOKEN_EXPIRED`; customer must re-authenticate before proceeding; no debit occurs.
7. **Amount zero or negative** — Rejected at input validation with a field-level error; no QR lookup or merchant validation performed.
8. **Receipt creation failure after successful payment** — Receipt creation is atomic with the payment: both the debit/credit journal post and the receipt write are committed inside the same database transaction. If receipt creation fails, the entire transaction rolls back, preserving financial integrity; no partial debit without a receipt can occur; no compensating entry is needed.

---

## Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-E16-000 | The system must support two QR code types: static (persistent, no pre-set amount) and dynamic (one-time use, pre-set amount, configurable expiry) |
| FR-E16-001 | A dynamic QR code must expire after a runtime-configurable time window; expired codes must be rejected at scan and confirmation time |
| FR-E16-002 | A dynamic QR code must be usable for at most one completed payment; when a confirmation attempt begins, the QR transitions to `reserved` for a configurable reservation window; if the payment fails or the reservation window expires without a completed payment, the QR returns to `active`; only a completed payment permanently marks the QR as `used`; a subsequent confirmation attempt against a `used` QR must be rejected with `QR_ALREADY_USED` |
| FR-E16-003 | QR payload integrity must be verifiable via a server-side signature; unrecognized or tampered payloads must be rejected and audit-logged |
| FR-E16-004 | The system must validate the merchant's identity and wallet status at both scan time and payment confirmation time |
| FR-E16-005 | QR payment execution must use a balanced triple-leg double-entry ledger journal: debit customer wallet (principal + fee), credit merchant wallet (principal), and credit the platform fee wallet (fee) — all three legs committed atomically; debits must equal credits |
| FR-E16-006 | State-changing QR payment operations must accept and enforce an `Idempotency-Key`; duplicate keys must replay the original response without creating duplicate transactions, debits, or credits |
| FR-E16-007 | Concurrent payment attempts with the same `Idempotency-Key` must not result in double-debits; concurrency must be explicitly tested (Constitution Principle 4) |
| FR-E16-008 | QR payments at or above the platform-configured step-up authentication threshold must require step-up before the wallet is debited; the threshold must not be hard-coded |
| FR-E16-009 | Only the wallet owner may initiate a QR payment from their wallet; authorization is enforced server-side |
| FR-E16-010 | Each completed QR payment must produce an immutable receipt with a globally unique reference, retrievable at any time |
| FR-E16-011 | All QR payment domain events must be published via the reliable outbox pattern for durable event delivery |
| FR-E16-012 | QR payment and QR management operations must be auditable: actor, operation, timestamp, correlation ID, and outcome must be recorded |
| FR-E16-013 | Customer and merchant wallet currencies must match; currency-mismatched QR payments must be rejected |
| FR-E16-014 | QR payload signatures must use a platform-wide HMAC key; on key rotation, dynamic QRs are invalidated at their natural expiry and static QRs must be re-generated; the signing key must never be embedded in QR payloads |
| FR-E16-015 | A merchant may have unlimited simultaneously active static QR codes; each static QR may optionally carry a merchant-supplied label to distinguish it at the point of sale; no uniqueness constraint is enforced on active static QRs per merchant |

## Key Entities

- **Merchant** — A registered business with a display name, category, merchant wallet reference, active status, and optional logo; consumed from existing merchant records (created by prior epics)
- **QRCode** — A generated code with type (static/dynamic), merchant ID, optional merchant-supplied label (e.g., "Counter 1"), optional pre-set amount, currency, HMAC-signed payload, unique nonce, status (`active` → `reserved` → `used` | `active`; also `expired` or `inactive`), optional expiry timestamp, optional reservation expiry, and optional merchant reference. A merchant may have unlimited simultaneously active static QR codes. Dynamic QRs move to `reserved` on first confirmation attempt and return to `active` on payment failure or reservation timeout; only a completed payment sets status to `used`
- **QRPaymentTransaction** — An executed or in-progress QR payment with customer wallet reference, merchant wallet reference, QR code reference, amount, fee, currency, status (initiated/completed/failed), journal ID, idempotency key, and correlation ID
- **QRPaymentReceipt** — An immutable record created on payment completion: unique receipt reference, merchant display name, amount, fee, total debited, currency, merchant reference (if any), issued_at

## Success Criteria

| ID | Criterion | Target |
|----|-----------|--------|
| SC-E16-001 | QR code generation (static or dynamic) returns a valid, scannable payload | Under 1 second from request |
| SC-E16-002 | QR scan resolution (merchant validation + payment detail retrieval) completes | Under 2 seconds end-to-end |
| SC-E16-003 | QR payment execution completes (debit confirmed, merchant credited, transaction persisted) | Under 3 seconds from confirmation |
| SC-E16-004 | Duplicate payment prevention | Zero additional debits for idempotent re-submissions of the same `Idempotency-Key` |
| SC-E16-005 | Ledger journal integrity | 100% of QR payment journals have debit sum equal to credit sum |
| SC-E16-006 | Receipt availability | 100% of completed QR payments have a retrievable receipt; timing is covered by SC-E16-003 (receipt is committed atomically with payment) |

## Assumptions

1. Merchants already exist as verified entities in the platform (onboarded via E05 KYC, E04 Roles, and relevant agent/merchant processes); E16 consumes the Merchant record and does not include merchant registration.
2. QR payloads are signed with an HMAC using a single platform-wide secret key (not per-merchant). On key rotation, existing dynamic QRs are invalidated (they expire naturally within their TTL); static QRs must be re-generated by merchants after a key rotation event.
3. Merchant notification delivery (push, SMS) is handled by E17 Notifications; E16 emits the `QRPaymentCompleted` event only — it does not send notifications directly.
4. Both customer and merchant wallets are denominated in the same currency (BWP default); multi-currency QR payments are deferred to E29.
5. QR payment fees use the platform-wide fee schedule configured via E21 Fees & Pricing; merchant-specific fee overrides are out of scope for E16.
6. QR payments are surfaced in the unified E11 Transaction Management history; a separate QR payment history endpoint is not required.
7. Only merchant-presented QR (MPQR) is in scope: the merchant generates and displays the QR code; the customer scans and pays. Customer-presented QR (CPQR) is out of scope.

## Out of Scope

- Merchant onboarding, registration, and KYC (covered by E05, E04, E12)
- Admin UI for merchant management (deferred to E19 Admin Portal)
- Multi-currency QR payments (deferred to E29)
- Customer-presented QR (CPQR) — deferred to a future epic
- Scheduled or recurring QR payments
- QR payment refunds and disputes (deferred to E22 Support & Dispute Management)
- Merchant settlement and payout (deferred to E20 Reconciliation & Settlement)
- QR code printing or PDF export
- Agent-intermediated QR payments on behalf of a customer
- Cross-platform QR acceptance (third-party payment network QR codes)
