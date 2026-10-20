# Feature Specification: E15 — Service & Bill Payments

**Feature Branch**: `015-service-bill-payments`

**Created**: 2026-09-24

**Status**: Implemented

## Overview

The Service & Bill Payments feature enables wallet-holding customers to pay for essential services — utilities (electricity, water), telecommunications (mobile top-up, internet), and subscriptions (TV, streaming) — directly from their OleKard wallet. The flow is: discover an available service, validate the customer's account reference with the provider, confirm the fee, debit the wallet atomically, submit the request to the provider, handle the outcome, and generate a verifiable receipt.

The platform acts as a trusted intermediary between the customer wallet and external service providers. This is the first consumer payment category beyond wallet-to-wallet transfers, and it establishes the patterns (provider integration, pre-authorization validation, atomic debit + submission, outcome processing) that will be reused in subsequent payment epics.

## Clarifications

### Session 2026-09-24

- Q: When the system validates a customer's account reference with a service provider, does the provider also return the customer's current outstanding balance or bill amount, or does validation only confirm that the reference is valid and return the account name? → A: Validation returns account name + valid/invalid status only; the customer manually enters the payment amount.
- Q: When does the `ServiceTransaction` record get created — before the wallet debit (capturing the customer's intent), or only after the wallet debit succeeds? → A: Record created in `initiated` status before any wallet debit; transitions to `pending_provider` after the debit succeeds.
- Q: Does this epic include the admin-facing endpoints to create, update, and deactivate services in the service catalog, or is service catalog management deferred to the E19 Admin Portal epic? → A: Admin CRUD (create, update, deactivate service + fee config) is in scope for this epic; the admin UI surface is deferred to E19.
- Q: When a payment is in `timeout_pending_review`, what is the expected resolution mechanism — admin-manual, automated re-query, or both? → A: Automated background re-query on a schedule; if still unresolved after a configurable number of retries, an operations admin manually forces the outcome (success or failure) via an API action.
- Q: Does the service payment flow require step-up authentication (PIN or MFA) for all payments, or only for payments that meet or exceed the platform-wide configurable threshold? → A: Threshold-based only — step-up is required at or above the platform-configured threshold (currently BWP 10,000), consistent with Constitution Principle 5; small everyday payments below the threshold proceed without step-up.

## Actors

- **Customer** — Authenticated wallet holder who initiates and pays for services from their wallet balance
- **Operations Admin** — Creates, updates, and deactivates services in the catalog; configures fee structures and provider mappings per service; monitors transaction volume and failure rates via API (admin UI surface is E19)
- **External Provider** — The biller or utility that validates customer references and processes payment requests, returning success or failure confirmation
- **Platform (System)** — Orchestrates the payment flow: validates input, debits wallet, submits to provider, processes outcomes, emits events, and generates receipts

## User Scenarios & Testing

### User Story 0 — Admin Service Catalog Management (Priority: P0)

An operations admin defines the services customers can pay for: the service name, category, required input fields (e.g., "meter number — 10 digits"), fee structure (flat or percentage), accepted amount range, and the provider it connects to. Admins can also deactivate a service to remove it from the customer-visible catalog without deleting its historical transaction records.

**Why this priority**: Blocking prerequisite — the customer-facing discovery and payment flows have no data without at least one configured active service.

**Independent Test**: An admin creates an electricity service with a flat BWP 2.00 fee; the service appears in the active catalog; updating the fee updates it for future payments only; deactivating removes it from the customer-visible list while preserving past transactions.

**Acceptance Scenarios**:

1. **Given** an authorized admin, **When** they create a service with name, category, required input fields, fee type, fee value, and accepted amount range, **Then** the service is persisted in `active` status, an audit event is emitted with before/after state, and the service appears in the customer-visible catalog.
2. **Given** an active service, **When** an admin updates the fee structure or amount range, **Then** the change applies to future payments only; existing `pending_provider` or `completed` transactions retain the fee at the time they were initiated; an audit event is emitted.
3. **Given** an active service, **When** an admin deactivates it, **Then** the service transitions to `inactive`; it is excluded from customer discovery results; payment initiation for this service is rejected with `SERVICE_NOT_AVAILABLE`; historical transactions are unaffected.
4. **Given** a missing required field (name, category, or fee value), **When** an admin submits the create/update request, **Then** the request is rejected with field-level validation errors.
5. **Given** an unauthorized actor (non-admin), **When** they attempt to create, update, or deactivate a service, **Then** the request is rejected with `FORBIDDEN`.
6. **Given** a deactivated service, **When** an admin reactivates it, **Then** it returns to `active` status and reappears in the customer-visible catalog.

---

### User Story 1 — Service Discovery (Priority: P1)

Maps to: US-SERVICE-001 (View available services), US-SERVICE-002 (Search services), US-SERVICE-003 (View service details)

A customer opens the payments section and sees a categorized list of available services. They can browse by category (Utilities, Telecoms, TV, etc.) or search by name to find a specific service. Selecting a service reveals its details: what account reference field to enter, the fee structure, and the accepted payment amount range.

**Why this priority**: Prerequisite for all payment flows — customers cannot pay for a service they cannot discover.

**Independent Test**: An authenticated customer requests the service list, searches for "electricity", and views the required input fields, fee structure, and payment range for the matched service. An unauthenticated request is rejected with `UNAUTHORIZED`.

**Acceptance Scenarios**:

1. **Given** multiple active services, **When** an authenticated customer requests the service list, **Then** only active services are returned with their category, name, required input fields, and fee summary; inactive or disabled services are excluded.
2. **Given** services in multiple categories, **When** a customer filters by category "UTILITIES", **Then** only utility services are returned.
3. **Given** a search term "Elec", **When** a customer searches, **Then** services with "Elec" in their name or description are returned; an empty match returns an empty list, not an error.
4. **Given** a valid service ID, **When** a customer requests service details, **Then** the response includes the service name, category, required input fields with formats (e.g., 10-digit meter number), fee structure, minimum and maximum payment amounts, and supported currencies.
5. **Given** an unauthenticated request, **When** any service discovery endpoint is called, **Then** the request is rejected with `UNAUTHORIZED`.
6. **Given** a service ID that does not exist, **When** requested, **Then** a `SERVICE_NOT_FOUND` error is returned.

---

### User Story 2 — Pre-Payment Validation (Priority: P1)

Maps to: US-SERVICE-005 (Validate customer reference), US-SERVICE-006 (Calculate service fee)

Before paying, the customer enters their account reference with the provider (meter number, phone number, or account ID). The system validates this reference with the provider to confirm it is valid and active, and returns the account holder name for visual confirmation. The customer then requests a fee quote for the amount they intend to pay before committing to the transaction.

**Why this priority**: Validation prevents payments to wrong or invalid accounts; fee display allows informed customer consent before any wallet debit occurs.

**Independent Test**: A customer submits a valid meter number for an electricity service; the system validates with the provider and returns the account holder name. A fee calculation for BWP 100 returns the service fee (e.g., BWP 2.00) and total payable (BWP 102.00).

**Acceptance Scenarios**:

1. **Given** a valid customer reference for an active service, **When** the customer submits it for validation, **Then** the system contacts the provider, receives confirmation, and returns the validated account name and reference; no outstanding balance or bill amount is returned — the customer enters the payment amount manually; the validated account name returned by this call must be supplied by the client in the payment initiation request; no server-side session storage of the validation result is used.
2. **Given** an invalid customer reference (unknown account), **When** submitted, **Then** the provider rejects it and the system returns `INVALID_CUSTOMER_REFERENCE`; no payment may proceed using this reference.
3. **Given** a provider validation request that times out within the system limit, **When** the timeout expires, **Then** the system returns `PROVIDER_VALIDATION_TIMEOUT`; no payment proceeds; the customer may retry.
4. **Given** a validated reference and a payment amount within the allowed range, **When** a fee calculation is requested, **Then** the system returns the base amount, fee amount, total payable, and currency.
5. **Given** an amount below the service minimum or above the service maximum, **When** a fee calculation is requested, **Then** the system returns `AMOUNT_OUT_OF_RANGE` with the allowed minimum and maximum.
6. **Given** an unauthenticated or unauthorized request, **When** validation or fee calculation is called, **Then** the request is rejected.

---

### User Story 3 — Payment Initiation and Execution (Priority: P1)

Maps to: US-SERVICE-004 (Initiate service payment), US-SERVICE-007 (Debit wallet), US-SERVICE-008 (Submit provider request)

The customer confirms the payment details and authorizes the transaction. A `ServiceTransaction` record is first created in `initiated` status (before any money moves), capturing the customer's intent and the idempotency key. The system then debits the customer's wallet (principal + fee) with a balanced ledger entry and submits the payment to the provider, transitioning the transaction to `pending_provider`. No partial outcome is permitted: if the wallet debit fails, the transaction remains `initiated` and the customer is notified; if the provider submission fails after the debit, compensating entries restore the customer's balance and the transaction transitions to `failed`.

**Why this priority**: Core financial flow — this is the money movement step. Financial integrity (atomic debit + idempotency) is non-negotiable.

**Independent Test**: A customer with sufficient balance initiates a BWP 100 service payment with a valid `Idempotency-Key`; a `ServiceTransaction` is created in `initiated` status; the wallet balance is then debited by principal + fee; a balanced ledger journal is created; the transaction transitions to `pending_provider` status with a unique transaction reference.

**Acceptance Scenarios**:

1. **Given** a customer with sufficient balance and a valid validated reference, **When** they initiate a service payment with an `Idempotency-Key`, **Then** a `ServiceTransaction` is first created in `initiated` status; the wallet is debited (principal + fee); a balanced ledger journal is created (debits equal credits); the transaction transitions to `pending_provider`; a `ServicePaymentInitiated` event is emitted; and a transaction reference is returned.
2. **Given** a customer with insufficient balance, **When** they attempt to initiate a service payment, **Then** a `ServiceTransaction` is created in `initiated` status; the request fails with `INSUFFICIENT_BALANCE`; no ledger entry is created, no provider request is submitted, and the transaction remains in `initiated` status (abandonable after a configurable timeout).
3. **Given** the same `Idempotency-Key` submitted twice, **When** the second request arrives, **Then** the system returns the original response without creating a duplicate transaction or additional wallet debit.
4. **Given** a payment where the wallet debit succeeds but the provider submission fails, **When** the failure is detected, **Then** a compensating ledger entry reverses the debit, the transaction transitions to `failed`, the customer's balance is restored, and a `ServicePaymentFailed` event is emitted.
5. **Given** a suspended or inactive customer wallet, **When** a payment is initiated, **Then** the request is rejected with `WALLET_NOT_ACTIVE`; no debit occurs.
6. **Given** an actor attempting to initiate a payment from another user's wallet, **When** the request is submitted, **Then** it is rejected with `FORBIDDEN`.
7. **Given** a service that has been deactivated between reference validation and payment initiation, **When** the payment is initiated, **Then** the request fails with `SERVICE_NOT_AVAILABLE`; no debit occurs.
8. **Given** a payment amount at or above the platform step-up threshold, **When** the customer initiates payment, **Then** the system requires a valid step-up authentication token before the wallet is debited; a missing or invalid token returns `STEP_UP_REQUIRED` and no debit occurs.

---

### User Story 4 — Provider Response Processing (Priority: P1)

Maps to: US-SERVICE-009 (Handle provider success), US-SERVICE-010 (Handle provider failure)

After the provider processes the payment, it returns a success or failure response. On success the transaction is marked complete, the provider's confirmation reference is stored, and receipt generation is triggered. On failure the wallet debit is reversed via a compensating entry, the transaction is marked failed, and the customer is notified. Both outcomes must be processed idempotently to guard against duplicate callbacks.

**Why this priority**: Completes the financial loop — without outcome processing, payments remain in `pending_provider` indefinitely and wallet debits are unrecoverable without manual intervention.

**Independent Test**: A `pending_provider` ServiceTransaction receives a provider success response; the transaction transitions to `completed`, the provider confirmation reference is stored, a receipt is generated, and the balanced ledger journals remain intact.

**Acceptance Scenarios**:

1. **Given** a `pending_provider` transaction, **When** a provider success response arrives with a valid provider reference, **Then** the transaction transitions to `completed`; the provider reference is stored; a `ServicePaymentCompleted` event is emitted; receipt generation is triggered.
2. **Given** a `pending_provider` transaction, **When** a provider failure response arrives, **Then** a compensating ledger entry is posted reversing the wallet debit (compensating journal is also balanced); the transaction transitions to `failed`; a `ServicePaymentFailed` event is emitted; the customer's balance is fully restored.
3. **Given** a duplicate provider success callback for an already-`completed` transaction, **When** it arrives, **Then** the system accepts it idempotently without re-processing; no duplicate ledger entry or event is created.
4. **Given** a provider response referencing an unknown transaction, **When** it arrives, **Then** the system rejects it with `TRANSACTION_NOT_FOUND` and the event is logged for investigation.
5. **Given** a provider response that arrives neither success nor failure (ambiguous/malformed), **When** received, **Then** the system treats it as a failure and applies the compensating entry; the transaction is flagged for manual review.
6. **Given** no provider response within the configured timeout window, **When** the window expires, **Then** the system transitions the transaction to `timeout_pending_review` and begins an automated background re-query schedule; with each re-query, if the provider returns a definitive answer the system processes it as success or failure accordingly; if the provider remains unresponsive after a configurable number of re-query attempts, the transaction stays in `timeout_pending_review` for operations team resolution.
7. **Given** a transaction in `timeout_pending_review` that the provider cannot resolve automatically, **When** an authorized admin forces a success or failure outcome via the admin resolution API, **Then** the transaction is processed accordingly (success: completes the transaction and generates a receipt; failure: posts compensating entry, restores balance, marks failed); the admin action is audit-logged with actor, rationale, and outcome.

---

### User Story 5 — Receipt Generation (Priority: P1)

Maps to: US-SERVICE-011 (Generate service receipt)

On successful payment completion, the system generates a receipt containing all the information the customer needs to prove payment to the service provider: the unique transaction reference, service name, customer account reference, provider confirmation reference, amount paid (principal + fee), and timestamp. The receipt is retrievable at any time from transaction history.

**Why this priority**: Regulatory and trust requirement — customers need verifiable proof of payment for utility and service payments; receipts may be required by providers as proof of settlement.

**Independent Test**: A completed service transaction has a retrievable receipt with all required fields and a globally unique reference number. Re-fetching the receipt returns the same data without regeneration.

**Acceptance Scenarios**:

1. **Given** a completed service transaction, **When** the customer requests the receipt, **Then** the response includes: unique receipt reference, transaction reference, service name, customer account reference, provider confirmation reference, amount paid (principal), fee amount, total charged, currency, payment timestamp, and status `completed`.
2. **Given** a completed transaction, **When** the receipt is requested multiple times, **Then** the same receipt is returned without regeneration or modification.
3. **Given** a failed or `pending_provider` transaction, **When** a receipt is requested, **Then** the system returns `RECEIPT_NOT_AVAILABLE` with the current transaction status.
4. **Given** another authenticated customer attempting to view a receipt for a transaction that is not theirs, **When** they request the receipt, **Then** the request is rejected with `FORBIDDEN`.

---

## Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-E15-000 | Authorized admins must be able to create, update, and deactivate services in the catalog via API; service CRUD operations must be auditable with before/after state |
| FR-E15-001 | The system must maintain a configurable catalog of active services, each with name, category, required input fields, fee structure (flat or percentage), and accepted amount range |
| FR-E15-002 | Customer account references must be validated with the service provider before a payment may be initiated; validation confirms the reference is active and returns the account holder name only — no outstanding bill amount is returned by the provider |
| FR-E15-003 | The fee and total payable amount must be presented to the customer before any wallet debit occurs |
| FR-E15-004 | Wallet debits for service payments must use double-entry ledger entries; ledger journals must balance (debits equal credits) |
| FR-E15-005 | State-changing service payment operations must accept and enforce an `Idempotency-Key`; duplicate keys must replay the original response without creating duplicate transactions |
| FR-E15-006 | Provider submission failure after wallet debit must immediately trigger a compensating ledger entry restoring the customer balance |
| FR-E15-007 | Provider success and failure responses must be processed idempotently — duplicate callbacks must not produce duplicate ledger entries |
| FR-E15-008 | Concurrent payment attempts from the same wallet for the same service reference and idempotency key must not result in double-debits |
| FR-E15-009 | Each completed service payment must produce a receipt with a globally unique reference number, retrievable at any time |
| FR-E15-010 | All service payment domain events must be published via the reliable outbox pattern for durable event delivery |
| FR-E15-011 | Authorization is enforced server-side: only the wallet owner may initiate a payment from their own wallet |
| FR-E15-012 | Service payment operations must be auditable: actor, operation, timestamp, correlation ID, and outcome must be recorded |
| FR-E15-013 | Transactions in `timeout_pending_review` must be automatically re-queried on a configurable schedule; after a configurable retry limit is reached, an authorized admin must be able to force a success or failure outcome via a dedicated resolution API action, which must itself be audit-logged |
| FR-E15-014 | Service payments at or above the platform-configured step-up threshold (default BWP 10,000) must require step-up authentication (MFA/PIN confirmation) before the wallet debit proceeds; payments below the threshold proceed without step-up; the threshold is runtime-configurable via Platform Configuration and must not be hard-coded |

## Success Criteria

| ID | Criterion | Target |
|----|-----------|--------|
| SC-E15-001 | Service catalog and search return results to an authenticated customer | Under 2 seconds for up to 500 active services |
| SC-E15-002 | Customer reference validation completes (including provider round-trip) | Under 5 seconds for online providers |
| SC-E15-003 | Service payment initiation completes (wallet debit confirmed, transaction persisted) | Under 3 seconds from submission |
| SC-E15-004 | Duplicate payment prevention | Zero additional wallet debits for idempotent re-submissions of the same `Idempotency-Key` |
| SC-E15-005 | Ledger journal integrity | 100% of service payment journals have debit sum equal to credit sum |
| SC-E15-006 | Balance recovery on failure | 100% of provider-failed or submission-failed payments result in a compensating entry fully restoring the customer balance |
| SC-E15-007 | Receipt availability | 100% of completed payments have a retrievable receipt within 1 second of the provider success response |

## Edge Cases

1. **Provider offline during validation** — Validation request times out; system returns `PROVIDER_VALIDATION_TIMEOUT`; no payment may proceed; the customer may retry.
2. **Service deactivated between validation and payment** — System re-checks service status at payment initiation; returns `SERVICE_NOT_AVAILABLE`; no wallet debit occurs.
3. **Provider goes offline after wallet debit** — Provider submission fails; compensating entry restores the customer's balance immediately; transaction marked `failed`.
4. **Ambiguous provider response** — Any response that is not a definitive success is treated as failure; compensating entry applied; transaction flagged for manual review.
5. **Concurrent duplicate payments (race-condition concurrency test)** — Database-level unique constraint on idempotency key prevents parallel duplicate processing; first succeeds, second returns the original response. This edge case MUST have an explicit concurrency test (Constitution Principle 4).
6. **Provider returns "already processed"** — Treated as idempotent success; no duplicate journal created; transaction marked completed if not already.
7. **Amount changes between fee quote and payment initiation** — System recalculates fee at initiation; if fee has changed, returns `FEE_CHANGED` with the new fee for customer confirmation before proceeding.
8. **Provider status unresolved after timeout** — Transaction transitions to `timeout_pending_review`; automated re-query runs on a configurable schedule (e.g., every 5 minutes, up to N attempts); on definitive provider response the normal success/failure path applies; if re-queries are exhausted, an admin manually forces the outcome via a dedicated API action; wallet debit is held (not reversed) until a resolution is confirmed.
9. **Stale `initiated` transaction** — A transaction stuck in `initiated` (e.g., customer abandoned or system crash before debit) is automatically transitioned to `abandoned` after a configurable timeout; no wallet or ledger effect occurs.

## Assumptions

1. Service provider selection and integration protocols remain TBD (Constitution Principle 2); the platform implements a provider abstraction layer; specific provider contracts are resolved separately.
2. Services are configured by an admin (not auto-discovered from provider systems); admin tools to create and manage the service catalog are part of this epic.
3. Fee structure per service is flat-amount or percentage; dynamic fee negotiation at the time of payment is out of scope.
4. Provider responses are primarily synchronous (within the HTTP request window); delayed/async responses arrive via a separate callback and are handled by the outcome processing stories.
5. All service payment amounts are in the customer wallet's primary currency (BWP per E06 defaults); multi-currency support is out of scope.
6. Service payment transaction records are surfaced in the existing E11 Transaction Management history; a separate service transaction history endpoint is not required.

## Out of Scope

- Admin UI screens for service catalog management (deferred to E19 Admin Portal; this epic delivers the backend API only)
- Service provider catalog auto-discovery from provider APIs
- Scheduled or recurring bill payments
- Direct debit or payment mandate authorization
- Multi-currency service payments (deferred to E29)
- CSV/PDF receipt export (deferred to E24 Reporting & Analytics)
- Service payment refunds and disputes (handled by E22 Support & Dispute Management)
- Agent-initiated service payments on behalf of a customer (deferred to agent operations scope)
