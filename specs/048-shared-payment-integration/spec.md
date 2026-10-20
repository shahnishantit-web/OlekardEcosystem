# Feature Specification: Shared Payment Integration and Orchestration

**Feature Branch**: `048-shared-payment-integration`

**Created**: 2026-10-18

**Status**: Draft

**Epic**: E48

**Authority**: Constitution Principles 1–15; ADR-008 (Provider Adapter Architecture); OKI-ARCH-2026-003 §4, §5, §6, §9, §11, §15

**ADRs Required Before Implementation**:
- ADR-E48-001: Shared Payment Module Ownership and Boundaries
- ADR-E48-002: Wallet Payment Adapter Delegation Pattern

**Additive Extension of**: E1000 Provider Adapter Foundation (abstract contracts reused, not modified)

---

## 1. Problem Statement

OleKard is expanding into horizontal modules — Community Funeral Assistance, Event Ticketing, Raffle and Lottery, Fundraising Campaigns, and future community commerce features. Each of these modules requires consumers to make a payment. Today, no single shared payment layer exists: every new module would independently build its own provider integration, callback handling, state management, refund logic, and reconciliation — creating fragmentation, duplication, and financial risk.

Additionally, OleKard Wallet already exists as a funding source for transfers and withdrawals, but it is not exposed as a payment option through a shared interface. Consumers expect to pay using their OleKard Wallet, bank payments, or any other provider the platform approves — through a single, consistent checkout experience.

This feature delivers the **Shared Payment Integration and Orchestration module**: `app/Modules/Payment`. It is the sole layer through which all horizontal business modules initiate, track, and refund payments. It supports OleKard Wallet and any number of external providers (banks, MNOs, payment gateways) without coupling business modules to provider specifics. Providers are controlled by server-side configuration — never hard-coded in mobile clients.

---

## 2. Scope

E48 covers:

### Covered
- **S01** — Architecture assessment: document current payment landscape; produce ADR-E48-001 and ADR-E48-002
- **S02** — Core payment domain: `PaymentIntent`, `PaymentAttempt`, `PaymentRefund` entities; full status enumerations; `BusinessPurposeResolverInterface` contract; `payment.*` PostgreSQL schema
- **S03** — Provider registry: `PaymentProvider`, `PaymentProviderAccount` entities; `PaymentAdapterInterface`; capability model; server-side option discovery service
- **S04** — Admin configuration UI: provider CRUD, credential rotation, health display, capability flags, approval workflow for high-risk changes
- **S05** — Payment orchestration: `PaymentOrchestrationService`; intent and attempt state machines; idempotency; concurrency locks; timeout jobs
- **S06** — OleKard Wallet payment adapter: delegates to existing `WalletModuleContract` and `LedgerModuleContract`; never duplicates wallet domain logic
- **S07** — External bank adapter framework: `AbstractBankPaymentAdapter`; `MockBankPaymentAdapter` (dev-only, production-blocked); documentation gap tracking for real banks
- **S08** — First mock bank integration: fully documented mock flow for dev/test; real bank integration blocked pending documentation
- **S09** — Shared consumer checkout: Flutter `PaymentCheckoutSheet` widget; server-driven option list; status polling; no hard-coded providers
- **S10** — Callback processing: raw callback persistence; async queue processing; replay protection; outbox events (`payment.confirmed`, `payment.failed`, `payment.reversed`, `refund.confirmed`)
- **S11** — Refunds and reconciliation: `RefundService`; cumulative over-refund prevention; reconciliation exception queue; admin investigation UI
- **S12** — Horizontal module integration: `PaymentModuleContract` interface; reference integration with stub or existing module; event-driven fulfilment pattern
- **S13** — Security and operational readiness: RBAC; tenant isolation; MFA for high-risk actions; circuit breakers; audit; log redaction; PHPStan level 8
- **S14** — Migration, regression, and release: migration checklist; preserved E08/E09/E10/E15/E16 contracts; OpenAPI `payment.yaml`; operational runbooks

### Not Covered
- Modifications to E06 Wallet domain, E07 Ledger domain, E08 Funding module, or any existing payment module — this epic is additive only
- Introduction of a second ledger — all financial postings use the existing `LedgerModuleContract`
- Live integration with any real bank — marked BLOCKED until bank documentation and access credentials are provided and reviewed
- FX conversion — multi-currency is handled by E29; this module records currency but does not convert
- Payout flows (bank disbursement to external accounts) — incoming payment processing only
- Card-present or POS terminal flows

---

## 3. Goals

1. Give every horizontal business module a single, provider-agnostic payment interface so no module needs to build its own provider logic.
2. Make OleKard Wallet a first-class payment option, delegating to the existing wallet domain without duplicating its logic.
3. Make adding a new payment provider require only a new adapter and admin configuration — zero changes to business modules.
4. Enforce financial safety: no duplicate financial effects, no float arithmetic, idempotent state changes, immutable financial history.
5. Give consumers a single, consistent checkout experience across all horizontal modules and all approved providers.
6. Block all mock and development adapters from activating in production environments.
7. Provide operational tools: reconciliation exception management, provider health monitoring, runbooks.

---

## 4. Non-Goals

- E48 does NOT modify or replace the Wallet, Ledger, Funding, Transfer, Withdrawal, QR, or Service modules.
- E48 does NOT introduce a direct wallet balance mutation path.
- E48 does NOT implement live bank integrations (Bank 1, Bank 2, Bank 3 are placeholder slots; adapters are BLOCKED for live use).
- E48 does NOT implement payout, disbursement, or agent float top-up through this module.
- E48 does NOT implement FX rates or cross-currency conversion.
- E48 does NOT implement the fulfilment logic of horizontal modules (ticket issuance, contribution recording, raffle entry allocation) — those modules listen to payment events.

---

## 5. Architecture Decisions Required

### ADR-E48-001: Shared Payment Module Ownership and Boundaries

**Decision**: Create `App\Modules\Payment` as the authoritative module for all cross-module payment orchestration. No other module (business or infrastructure) may own payment intent lifecycle, provider selection, attempt state, or refund orchestration.

**Rationale**: Without a single owner, payment state becomes fragmented across modules. Idempotency, duplicate prevention, and reconciliation cannot be enforced consistently.

**Constraints**:
- `App\Modules\Payment` MUST NOT duplicate wallet, ledger, or existing funding logic.
- Business modules MUST call `PaymentModuleContract` — they MUST NOT depend on Payment domain internals.
- Provider-specific code MUST live in adapter classes, never in the orchestration layer.

### ADR-E48-002: Wallet Payment Adapter Delegation Pattern

**Decision**: OleKard Wallet is exposed as a payment provider through `WalletPaymentAdapter`, which implements `PaymentAdapterInterface` and delegates ALL wallet operations to `WalletModuleContract` and `LedgerModuleContract`.

**Rationale**: The wallet domain already owns balance calculation, holds, reservations, and ledger postings. Duplicating these in the Payment module would create a second source of financial truth — a critical integrity violation per Constitution Principle 4.

**Constraints**:
- `WalletPaymentAdapter` MUST call `WalletModuleContract::createHold()` for reservations, never manipulate balance directly.
- All ledger postings MUST go through `LedgerModuleContract` — no direct DB writes to ledger tables.
- `WalletModuleContract` interface MUST NOT be modified by this epic. If a new method is required, it must be an additive extension with its own ADR.

---

## 6. User Scenarios & Testing *(mandatory)*

### Story S01 — Repository Assessment and Architecture Decisions (Priority: P1)

An engineering team must document the current payment landscape, identify reusable components, and produce two ADRs before any implementation begins. Without this, subsequent stories risk duplicating existing logic or missing critical dependencies.

**Why this priority**: All other stories depend on confirmed architectural decisions. Beginning implementation without S01 risks creating a module that conflicts with or duplicates existing infrastructure.

**Independent Test**: Can be tested by verifying that ADR-E48-001 and ADR-E48-002 are written, reviewed, and accepted; that the dependency map accurately lists reusable components from E1000, E06, E07, E08, and E25; and that the module namespace `App\Modules\Payment` is established.

**Acceptance Scenarios**:

1. **Given** the E48 epic begins, **When** S01 completes, **Then** ADR-E48-001 (Payment Module Ownership) exists under `docs/architecture/decisions/` and is status `Accepted`.
2. **Given** ADR-E48-001 is accepted, **When** engineers review the dependency map, **Then** it identifies: `ProviderAdapterInterface` (E1000), `IdempotencyServiceInterface` (Platform), `WalletModuleContract` (E06), `LedgerModuleContract` (E07), `OutboxEventPublisher` (Platform), `AuditService` (Platform), `ConfigurationService` (Platform) as reused components.
3. **Given** the assessment is complete, **When** the architecture gap list is reviewed, **Then** it documents what the Payment module must add that does not yet exist: `PaymentAdapterInterface`, `PaymentOrchestrationService`, `PaymentModuleContract`, `payment.*` DB schema, callback inbox, refund service, reconciliation exception queue.
4. **Given** the module namespace decision is made, **When** a new PHP file is created under `App\Modules\Payment\`, **Then** it is registered in the module service provider and resolvable from the Laravel container.

---

### Story S02 — Common Payment Contracts and Data Model (Priority: P1)

A developer building any horizontal module needs stable, well-typed data structures to represent a payment request, its lifecycle, and potential refunds — so that all modules share a common language and financial data is stored with integrity.

**Why this priority**: Every subsequent story (S03–S14) depends on the core entities, status enumerations, and `payment.*` schema existing. Without this, nothing else can be built or tested.

**Independent Test**: Can be tested by creating a `PaymentIntent` record through the application service, confirming it is stored in `payment.payment_intents` with a UUID v7 ID and integer minor-unit amount, and confirming that the status transitions are enforced by the domain model.

**Acceptance Scenarios**:

1. **Given** a horizontal module creates a payment intent, **When** it calls `createIntent()` with a valid `PaymentIntentRequest`, **Then** a `PaymentIntent` is persisted with: UUID v7 ID, `purpose_type`, `purpose_reference`, `organisation_id`, `payer_actor_id`, `currency_code`, `amount_minor` (integer), `status = pending`, `idempotency_key`, `correlation_id`, and `expires_at`.
2. **Given** the same `idempotency_key` and `payer_actor_id` are submitted twice, **When** the second request arrives, **Then** the service returns the existing `PaymentIntent` without creating a duplicate record.
3. **Given** a `PaymentIntent` is in status `pending`, **When** an attempt is initiated, **Then** a `PaymentAttempt` is created in status `initiated` linked by `payment_intent_id`.
4. **Given** a `PaymentAttempt` is in status `succeeded`, **When** a refund is requested, **Then** a `PaymentRefund` record is created in status `requested` linked by `payment_attempt_id` and `payment_intent_id`.
5. **Given** the amount field of a `PaymentIntent` or `PaymentAttempt`, **When** it is stored or retrieved, **Then** it is always an integer (minor units — e.g., 10000 for BWP 100.00) and floating-point values are rejected at the application boundary.
6. **Given** the `BusinessPurposeResolverInterface` is called with a `purpose_type` and `purpose_reference`, **When** the resolver succeeds, **Then** it returns a `PayableObligation` containing: server-calculated `amount_minor`, `currency_code`, `organisation_id`, `payer_actor_id`, and safe metadata — the mobile client's submitted amount is never used.

---

### Story S03 — Provider Registry and Capability Model (Priority: P1)

A platform operator needs to register and configure payment providers (OleKard Wallet, banks, MNOs) with their supported capabilities, currencies, and restrictions — so that the system can discover the correct options for any given payment context without hard-coding provider details in business logic.

**Why this priority**: Without the registry, provider selection is impossible. This story unblocks admin configuration (S04), orchestration (S05), and all adapter stories (S06–S08).

**Independent Test**: Can be tested by seeding a `PaymentProvider` record for `olekard_wallet` and a `PaymentProviderAccount` scoped to BWP, then calling the option-discovery service with a BWP payment in the seeded organisation — verifying that OleKard Wallet is returned as an available option.

**Acceptance Scenarios**:

1. **Given** a `PaymentProvider` is registered with `code = 'olekard_wallet'`, `is_enabled = true`, `is_suspended = false`, **When** the option-discovery service is called for a matching organisation, currency, and amount, **Then** OleKard Wallet appears in the available options list.
2. **Given** a `PaymentProvider` has `is_suspended = true`, **When** the option-discovery service is called, **Then** the suspended provider does NOT appear in the available options — but existing in-flight attempts, callbacks, and refunds for that provider continue to be processed.
3. **Given** a `PaymentProviderAccount` has `min_amount_minor = 500` and `max_amount_minor = 100000`, **When** the option-discovery service is called with `amount_minor = 300`, **Then** that provider account is excluded from the available options.
4. **Given** a `PaymentProvider` has `capability_flags` that does not include `partial_refund`, **When** a partial refund is requested through that provider, **Then** the system returns an explicit `CapabilityUnsupportedException` — not a silent failure.
5. **Given** an adapter is registered with `getProviderIdentifier() = 'mock_bank'`, **When** `APP_ENV = production`, **Then** the adapter throws `ProductionBlockedException` on any method call, preventing mock payments in production.
6. **Given** provider credentials need to be stored, **When** the admin saves a provider account, **Then** only a secret reference (e.g., a Vault path or AWS Secrets Manager ARN) is stored in the database — the actual secret value is NEVER stored in any database column, log, or API response.

---

### Story S04 — Secure Admin Configuration (Priority: P2)

A platform administrator needs a UI to register, configure, enable/disable, and monitor payment providers — with an approval workflow for high-risk changes such as updating a merchant account or settlement destination.

**Why this priority**: Provider configuration must be in place before the wallet adapter and mock bank adapter can be activated. However, the admin UI can be developed in parallel with backend stories S05–S08 and integrated afterward.

**Independent Test**: Can be tested by navigating to `/admin/payment/providers` in the admin UI, creating a provider record for OleKard Wallet, saving it, and confirming the record appears in the list — and that the audit log records the creation event.

**Acceptance Scenarios**:

1. **Given** an admin user with `payment.providers.manage` permission, **When** they navigate to `/admin/payment/providers`, **Then** they see a list of all registered providers with status badges (active, suspended, sandbox, production mock) and connection health indicators.
2. **Given** an admin creates or edits a provider, **When** they save the form, **Then** the system validates configuration completeness via `PaymentProviderConfigValidator` and shows inline errors for any missing required fields.
3. **Given** an admin changes a provider's merchant account reference or settlement destination, **When** they submit the change, **Then** the system requires a second approval from a user with `payment.config.approve_high_risk` permission before the change takes effect.
4. **Given** an admin rotates credentials for a provider, **When** the rotation is saved, **Then** the new secret reference is stored; the old reference is marked rotated; no secret value is ever returned in the API response; the audit log records the rotation with actor, timestamp, and which reference changed.
5. **Given** an admin disables a provider (sets `is_enabled = false`), **When** a new payment intent is requested for that provider, **Then** the request is rejected with a clear error — existing in-flight attempts, pending callbacks, and refunds for that provider are NOT affected.
6. **Given** a mock/sandbox provider is registered, **When** it is displayed in the admin UI, **Then** it has a prominent "DEVELOPMENT MOCK — NOT FOR PRODUCTION" label that cannot be suppressed.
7. **Given** any configuration change, **When** it is saved, **Then** the existing `AuditService` records: actor ID, action, changed fields (redacted for secrets), timestamp, and correlation ID.

---

### Story S05 — Payment Orchestration and State Machines (Priority: P1)

A horizontal module needs a reliable orchestration layer that creates payment intents, initiates provider attempts, handles outcomes, and manages the full lifecycle — ensuring no payment intent ever produces duplicate financial effects even under concurrent requests, retries, or provider timeouts.

**Why this priority**: This is the central coordination service that all other stories depend on. Without S05, neither the wallet adapter (S06) nor bank adapters (S07–S08) nor callbacks (S10) can function correctly.

**Independent Test**: Can be tested by creating a payment intent, initiating an attempt, simulating a successful callback, and confirming: (a) the intent transitions to `succeeded`; (b) the same callback replayed does not create a second financial effect; (c) attempting to initiate a second attempt while the first is active is rejected.

**Acceptance Scenarios**:

1. **Given** a horizontal module calls `createIntent()` with a valid `PaymentIntentRequest`, **When** the request succeeds, **Then** a `PaymentIntent` is returned in status `pending` with a stable `payment_intent_reference`, `correlation_id`, and `expires_at`.
2. **Given** a `PaymentIntent` is in status `pending`, **When** `initiateAttempt()` is called, **Then** the intent transitions to `processing`; a `PaymentAttempt` is created in status `initiated`; the selected provider adapter's `initiatePayment()` is called; the response contains the next required action for the consumer.
3. **Given** a `PaymentIntent` already has an active attempt (status not terminal), **When** `initiateAttempt()` is called again with a different provider, **Then** the system returns `DuplicateAttemptException` — no new attempt is created until the active one reaches a terminal state.
4. **Given** a provider callback arrives confirming payment success, **When** `handleOutcome()` processes it, **Then** the `PaymentAttempt` transitions to `succeeded`; the `PaymentIntent` transitions to `succeeded`; a `payment.confirmed` outbox event is published; any additional success callback for the same attempt is discarded (idempotent).
5. **Given** a `PaymentAttempt` has been in status `initiated` or `processing` past its timeout threshold, **When** `PaymentAttemptTimeoutJob` runs, **Then** the adapter's `queryPaymentStatus()` is called; if the provider confirms failure the attempt transitions to `timed_out`; if the outcome is ambiguous a reconciliation exception is raised — the attempt is NEVER automatically marked failed without provider confirmation.
6. **Given** a `PaymentIntent` reaches its `expires_at` time while no attempt has succeeded, **When** `PaymentIntentExpiredJob` runs, **Then** the intent transitions to `expired`; any active hold on the wallet is released; the consuming module receives a `payment.failed` event.
7. **Given** a provider returns a late success for an already-expired intent, **When** the callback arrives, **Then** the system records the late arrival as a reconciliation exception for manual review — it does NOT automatically apply the financial effect to the expired intent.
8. **Given** all money amounts in the system, **When** they are computed, stored, or compared, **Then** they are always integer minor units — no floating-point arithmetic is ever used in financial calculations.

---

### Story S06 — OleKard Wallet Adapter (Priority: P1)

A consumer who has an OleKard Wallet wants to pay for a funeral contribution, event ticket, or any other horizontal module service using their wallet balance — using the same checkout flow as any other payment option.

**Why this priority**: OleKard Wallet is the primary payment option for most consumers. It uses existing, verified infrastructure (WalletModuleContract, LedgerModuleContract) and can be fully functional as soon as S05 is complete.

**Independent Test**: Can be tested by initiating a wallet payment for a BWP 50.00 item, confirming the hold is placed via `WalletModuleContract`, the ledger debit is posted via `LedgerModuleContract`, the `PaymentAttempt` transitions to `succeeded`, and the `payment.confirmed` event is published — then verifying a reversal correctly releases the hold and posts a compensating credit.

**Acceptance Scenarios**:

1. **Given** a consumer selects OleKard Wallet and initiates payment, **When** `WalletPaymentAdapter.initiatePayment()` is called, **Then** it verifies the wallet belongs to the authenticated payer (via `WalletModuleContract.ownerActorId()`), checks spendable balance (via `WalletModuleContract.availableBalance()`), places a hold (via `WalletModuleContract.createHold()`), posts a debit ledger entry (via `LedgerModuleContract`), and transitions the attempt to `succeeded` — all within an atomic transaction.
2. **Given** the consumer's wallet has insufficient funds, **When** `WalletPaymentAdapter.initiatePayment()` is called, **Then** it returns an explicit `InsufficientFundsResult` — no hold is placed, no ledger posting occurs, and the `PaymentAttempt` transitions to `failed` with reason `insufficient_funds`.
3. **Given** the payment amount exceeds the step-up authentication threshold (from `ConfigurationService`), **When** initiatePayment is called without a step-up token, **Then** the adapter returns `StepUpRequiredResult` — no financial effect occurs until step-up is verified.
4. **Given** a wallet payment attempt is in status `succeeded`, **When** a refund is initiated, **Then** the hold is released (via `WalletModuleContract.releaseHold()`) and a compensating credit ledger entry is posted (via `LedgerModuleContract`) — the `PaymentRefund` transitions to `succeeded` and a `refund.confirmed` outbox event is published.
5. **Given** two concurrent payment requests are submitted for the same wallet, **When** both arrive simultaneously, **Then** only one succeeds — the second receives `InsufficientFundsException` or a serialization error; no overdraft or double-debit occurs.
6. **Given** `WalletPaymentAdapter.initiatePayment()` is called with the same idempotency key twice, **When** the second call arrives, **Then** the adapter returns the existing result without placing a second hold or posting a second ledger entry.
7. **Given** the wallet adapter, **When** `getProviderIdentifier()` is called, **Then** it returns the string `'olekard_wallet'`; `getCapabilities()` declares: `initiate_payment`, `query_payment`, `full_refund`, `partial_refund`, `cancel_payment`; `authorise_capture` and `payout` are NOT declared.

---

### Story S07 — External Bank Adapter Framework (Priority: P2)

An engineer integrating a new bank or MNO needs a stable, well-documented abstract base class that handles the common concerns (HMAC verification, checkout flow type, documentation gap tracking, production guard) — so each new bank adapter only needs to implement the provider-specific details.

**Why this priority**: The framework is needed before any real or mock bank adapters can be built (S08). It is independent of the wallet adapter (S06).

**Independent Test**: Can be tested by implementing a concrete `TestBankAdapter` that extends `AbstractBankPaymentAdapter`, injecting it into the orchestration service, confirming it satisfies the `PaymentAdapterInterface`, and verifying that `MockBankPaymentAdapter` throws `ProductionBlockedException` when instantiated with `APP_ENV=production`.

**Acceptance Scenarios**:

1. **Given** `AbstractBankPaymentAdapter` is implemented by a concrete bank adapter, **When** a payment is initiated, **Then** the base class handles correlation ID injection, audit event emission, and metric recording — the subclass only provides provider-specific request mapping and credential resolution.
2. **Given** a bank sends a callback, **When** `BankCallbackVerifier.verify()` is called, **Then** it validates the HMAC signature using the algorithm declared by the subclass and the secret resolved from the secrets manager reference — it throws `WebhookSignatureException` if the signature is absent or invalid.
3. **Given** `MockBankPaymentAdapter` is instantiated, **When** `APP_ENV = production`, **Then** every method call throws `ProductionBlockedException` with a clear message — it is impossible to accidentally process a real payment through the mock adapter in production.
4. **Given** `MockBankPaymentAdapter` is used in a test or development environment, **When** `initiatePayment()` is called, **Then** it returns a simulated redirect checkout URL containing a safe development-only domain — the URL is never an actual bank URL and the response is clearly labelled as mock.
5. **Given** a new bank slot (Bank 1, Bank 2, Bank 3) is required but documentation is not yet available, **When** the slot is added to the registry, **Then** a `BankProviderDocumentationGap` record is created listing the required documentation items (base URL, auth scheme, webhook signature algorithm, refund support, real-time confirmation flag) and the provider status is automatically set to `BLOCKED`.
6. **Given** a `BankProviderDocumentationGap` exists for a provider, **When** admin lists providers, **Then** the provider is displayed with a `BLOCKED — Documentation Required` status and the gap checklist is visible.

---

### Story S08 — First Mock Bank Integration (Priority: P2)

A developer building a horizontal module needs a realistic but safe mock bank integration they can use in local and test environments — including redirect checkout, HMAC callback verification, status query, and refund simulation — so they can build and test the full payment flow without a real bank.

**Why this priority**: The mock integration is needed for the Flutter checkout (S09) to demonstrate end-to-end bank flows, and for integration tests in all consuming stories.

**Independent Test**: Can be tested by initiating a mock bank payment, following the simulated redirect URL back to the app, receiving the mock callback, verifying HMAC-SHA256 signature, and confirming the `PaymentAttempt` transitions to `succeeded` — then requesting a full refund and confirming it transitions to `succeeded`.

**Acceptance Scenarios**:

1. **Given** `MockBankPaymentAdapter.initiatePayment()` is called in a non-production environment, **When** it processes the request, **Then** it returns a redirect URL to a safe local mock endpoint (e.g., `http://localhost/mock-bank/checkout?ref=...`), a provider reference ID, and attempt status `awaiting_action`.
2. **Given** the consumer completes the mock checkout flow, **When** the mock bank posts a callback, **Then** the callback HMAC-SHA256 signature is verified using a test secret configured in `callback_secret_reference` — an invalid signature is rejected with HTTP 401 before any processing occurs.
3. **Given** a mock bank callback is verified, **When** `PaymentCallbackProcessingJob` processes it, **Then** the `PaymentAttempt` transitions to `succeeded` and a `payment.confirmed` outbox event is published.
4. **Given** a mock bank payment is succeeded, **When** a full refund is requested via `MockBankPaymentAdapter.initiateRefund()`, **Then** the mock returns a refund reference ID and status `processing`; a subsequent mock confirmation transitions the `PaymentRefund` to `succeeded`.
5. **Given** a partial refund is requested for an amount less than the original, **When** `MockBankPaymentAdapter.initiateRefund()` processes it, **Then** the refund succeeds up to the original amount minus prior confirmed refunds — an attempt to exceed this returns `RefundLimitExceededException`.
6. **Given** any mock bank adapter, **When** it is displayed in the admin UI, **Then** its display name shows "DEVELOPMENT MOCK — NOT FOR PRODUCTION" prominently and cannot be made production-eligible through any admin action.
7. **Given** a real Bank 1 integration is required, **When** the documentation checklist is reviewed, **Then** it lists exactly: base URL, authentication scheme and credentials, webhook signature algorithm and header name, supported currencies, refund API endpoint and parameters, real-time confirmation behaviour, settlement timing, and sandbox access details.

---

### Story S09 — Shared Consumer Checkout (Priority: P2)

A consumer using any horizontal module app (Customer or Agent) should see a single, consistent payment selection and checkout experience — showing available providers retrieved from the server, payment purpose details, fee disclosure, and clear status feedback — without any hard-coded provider list.

**Why this priority**: The shared checkout is the consumer-facing value delivery of this entire epic. It depends on S06 (wallet option) and S08 (bank option), so it is naturally P2.

**Independent Test**: Can be tested by mounting `PaymentCheckoutSheet` in a Flutter test harness, mocking the `/v1/payment/options` endpoint to return both OleKard Wallet and MockBank options, selecting Wallet, and verifying the widget transitions through: loading → options displayed → wallet selected → processing → succeeded — and that redirecting back from mock bank correctly polls status rather than trusting the redirect.

**Acceptance Scenarios**:

1. **Given** a consumer opens a payment checkout for any horizontal module, **When** `PaymentCheckoutSheet` initialises, **Then** it calls `GET /v1/payment/options?purpose_type=&purpose_reference=&amount=&currency=` and displays the server-returned provider options — no provider names are hard-coded in the widget.
2. **Given** the options are loaded, **When** the checkout sheet is displayed, **Then** it shows: purpose description (e.g., "Funeral Contribution — Moeng Family"), recipient/organiser name, base amount, disclosed fees (from the fee service), final total, and each available provider with its display name and icon.
3. **Given** OleKard Wallet is in the options list, **When** the consumer views the wallet option, **Then** the displayable wallet balance is shown (server-side validated); if balance is insufficient for this payment amount, the wallet option shows a clear "Insufficient Balance" indicator and is disabled.
4. **Given** the consumer selects OleKard Wallet and confirms payment, **When** the payment is processed, **Then** the widget transitions through `processing` → `succeeded` states without any redirect — the entire flow is in-app.
5. **Given** the consumer selects an external bank option and taps "Pay", **When** the app opens the bank checkout flow (redirect or app-switch), **Then** the app saves the `payment_intent_id` to local storage before leaving; on return, it queries `GET /v1/payment/intents/:id/status` to determine the authoritative status — the deep-link return URL is NEVER treated as proof of payment.
6. **Given** the payment is in status `awaiting_action` after the bank redirect returns, **When** the widget polls status, **Then** it displays a "Confirming payment…" state and continues polling at a reasonable interval until the status reaches a terminal state.
7. **Given** the payment fails or is cancelled, **When** the widget receives a terminal failure status, **Then** it displays a clear failure message with the error reason, and offers a retry option that starts a new checkout flow for the same intent.
8. **Given** the consumer's session expires during checkout, **When** they return to the app, **Then** the checkout sheet can be restored from the saved `payment_intent_id` and the status is re-queried.

---

### Story S10 — Callback Processing and Internal Events (Priority: P1)

An external payment provider posts a webhook callback to notify OleKard of a payment outcome. The system must safely receive, verify, persist, and process callbacks — publishing normalised internal events that business modules consume for fulfilment — without risking data loss, duplicate processing, or coupling business modules to provider formats.

**Why this priority**: Callbacks are the primary mechanism by which external provider payments reach `succeeded`. Without S10, bank payments cannot complete.

**Independent Test**: Can be tested by posting a valid HMAC-signed mock bank callback to `POST /v1/payment/callbacks/mock_bank`, verifying it is persisted raw to `payment_callbacks`, then replaying the same callback and confirming no second financial effect is produced, then confirming the `payment.confirmed` outbox event was published exactly once.

**Acceptance Scenarios**:

1. **Given** a provider posts a callback to `POST /v1/payment/callbacks/:provider_code`, **When** the request arrives, **Then** the raw body is persisted to `payment_callbacks` table with status `received` BEFORE any processing occurs — if the queue is unavailable the HTTP response still acknowledges receipt.
2. **Given** a callback is received, **When** it is dispatched to `PaymentCallbackProcessingJob`, **Then** the job first calls `ProviderWebhookContract.parse()` which validates the signature; if the signature is invalid the callback is marked `signature_invalid` and an alert is raised — no financial state change occurs.
3. **Given** a callback with an `idempotency_key` that was already processed, **When** the duplicate arrives, **Then** the system discards it after marking the callback record as `duplicate` — no second financial effect is produced.
4. **Given** an out-of-order callback arrives for an attempt already in a terminal state, **When** it is processed, **Then** the system logs and discards it without modifying any financial state.
5. **Given** a callback confirms payment success, **When** `PaymentCallbackProcessingJob` completes successfully, **Then** the `payment.confirmed` event is published via the outbox with: `payment_intent_id`, `payment_attempt_id`, `purpose_type`, `purpose_reference`, `organisation_id`, `amount_minor`, `currency_code`, `correlation_id`.
6. **Given** business modules subscribe to `payment.confirmed`, **When** they receive the event, **Then** they execute their fulfilment logic (ticket issuance, contribution recording, raffle entry) idempotently — they MUST NOT query Payment module internals directly.
7. **Given** a callback cannot be processed after the maximum retry count, **When** it reaches the dead-letter queue, **Then** an alert is raised, the callback is marked `dead_lettered`, and an operations runbook is available for manual resolution.

---

### Story S11 — Refunds and Reconciliation (Priority: P2)

An administrator or support agent needs to process refunds for completed payments and investigate reconciliation exceptions — while the system prevents over-refunding, enforces provider capability checks, and maintains an immutable financial audit trail.

**Why this priority**: Refund and reconciliation capabilities are essential for operations but do not block the initial payment flow. They are P2 — important but can follow the core flow.

**Independent Test**: Can be tested by completing a BWP 100.00 payment, requesting a BWP 60.00 refund, confirming it succeeds, then requesting a second BWP 60.00 refund and confirming it fails with `RefundLimitExceededException` (cumulative: 60 + 60 = 120 > 100).

**Acceptance Scenarios**:

1. **Given** a `PaymentIntent` in status `succeeded`, **When** a refund is requested via `RefundService.request()`, **Then** the system checks: (a) provider supports refunds (capability flag); (b) requested amount ≤ original amount minus prior confirmed refunds; (c) requestor has `payment.refunds.request` permission — if any check fails, the refund is rejected with a specific reason.
2. **Given** the refund amount would cause cumulative refunds to exceed the original payment, **When** the refund is requested, **Then** `RefundLimitExceededException` is thrown — no partial over-refund is allowed.
3. **Given** a refund is approved and initiated, **When** the provider confirms the refund (via callback or sync response), **Then** the `PaymentRefund` transitions to `succeeded`; a compensating ledger credit is posted (for wallet payments); a `refund.confirmed` outbox event is published; the `PaymentIntent` status updates to `partially_refunded` or `refunded` based on remaining balance.
4. **Given** a reconciliation run executes, **When** it compares internal payment records against provider transaction statements, **Then** it identifies: missing callbacks, amount mismatches, orphan provider transactions, duplicate transactions, unmatched ledger records — and creates a `ReconciliationException` record for each discrepancy.
5. **Given** a `ReconciliationException` exists, **When** an administrator views the exception queue in the admin UI, **Then** they can add investigation notes, mark the exception as resolved (with a resolution reason), or escalate — they CANNOT mark a payment as paid without verified financial evidence.
6. **Given** financial history, **When** any historical ledger entry, `PaymentIntent`, `PaymentAttempt`, or `PaymentRefund` record is viewed, **Then** it is immutable — no edit is permitted; reversals use compensating records, not updates to historical rows.

---

### Story S12 — Horizontal Module Integration (Priority: P2)

A developer building a horizontal module (funeral contributions, event ticketing, raffles, fundraising) needs a clear, stable contract to initiate payments and receive outcomes — so their module has zero coupling to payment providers, adapter details, or internal payment state.

**Why this priority**: This story formalises the integration pattern and validates it with a reference implementation. It ensures the module design actually works for consuming modules before the first real horizontal module is built.

**Independent Test**: Can be tested by building a stub `FuneralContributionModule` that calls `PaymentModuleContract.createPaymentIntent()`, listening for `payment.confirmed`, and verifying: (a) the same module works with both wallet and mock bank providers; (b) duplicate `payment.confirmed` events are handled idempotently; (c) the module never accesses any `App\Modules\Payment\` internals directly.

**Acceptance Scenarios**:

1. **Given** a horizontal module calls `PaymentModuleContract.createPaymentIntent()` with a `PaymentIntentRequest`, **When** the intent is created, **Then** the module receives back: `payment_intent_id`, `payment_intent_reference`, `status = pending`, `expires_at`, `correlation_id` — it does not receive any provider credentials or internal payment state.
2. **Given** a horizontal module calls `PaymentModuleContract.getAvailableOptions()`, **When** the options are returned, **Then** the list reflects server-side configuration — the module passes this list to the consumer UI without modification.
3. **Given** a `payment.confirmed` event is published, **When** the horizontal module's event listener receives it, **Then** it executes its fulfilment logic using only the event payload — it does NOT query `App\Modules\Payment\` tables directly.
4. **Given** the same `payment.confirmed` event is delivered twice (retry scenario), **When** the module's listener processes the second delivery, **Then** it detects the duplicate via its own idempotency key and skips the fulfilment — no double ticket issuance, double contribution recording, or double entry allocation.
5. **Given** a single horizontal module uses two different providers for two payments, **When** both succeed, **Then** both produce `payment.confirmed` events that the module processes correctly — the module never branches on provider type.
6. **Given** two different horizontal modules share the same provider (e.g., both use OleKard Wallet), **When** both process payments concurrently, **Then** the payments are isolated by `organisation_id` and `purpose_reference` — neither module sees the other's payment data.

---

### Story S13 — Security and Operational Readiness (Priority: P1)

The payment module must enforce role-based access, tenant isolation, log redaction, audit trails, provider health monitoring, and circuit breakers before any story is released — so that no payment data leaks across organisations, no secrets appear in logs, and operations teams have the tools to respond to incidents.

**Why this priority**: Security and operations requirements are non-negotiable per Constitution Principle 6. They must be verified before release even though they cross-cut all other stories.

**Independent Test**: Can be tested by: (a) attempting to query payment intents of Organisation A while authenticated as Organisation B — confirming rejection; (b) triggering 5 consecutive provider failures and confirming the circuit breaker opens; (c) grepping all log output for credential or secret patterns and confirming zero matches.

**Acceptance Scenarios**:

1. **Given** a consumer or agent is authenticated, **When** they query payment intents, **Then** results are filtered to their own `organisation_id` — cross-organisation payment data is never returned regardless of the query parameters.
2. **Given** a staff user has `payment.refunds.request` but not `payment.refunds.approve`, **When** they attempt to approve a refund, **Then** the system returns `403 Forbidden` — server-side enforcement, not UI-only.
3. **Given** a high-risk action is attempted (provider config change or refund above threshold), **When** the acting user does not have a valid step-up authentication token, **Then** the action is blocked and step-up is required — consistent with the existing `auth.step_up_threshold` configuration.
4. **Given** any application log entry in the payment module, **When** it is inspected, **Then** it MUST NOT contain: provider credentials, API keys, secret references resolved to actual values, PINs, OTPs, card numbers, or raw account numbers — only redacted placeholders or reference IDs.
5. **Given** a payment provider returns 5 consecutive failures, **When** the circuit breaker trips, **Then** new payment attempts through that provider are immediately rejected with `ProviderCircuitOpenException`; an alert is sent to the operations team; existing in-flight attempts continue to be polled; the circuit resets after a configurable cooldown.
6. **Given** all RBAC permissions for the payment module, **When** the system boots, **Then** the following permissions are seeded and assignable: `payment.intents.read`, `payment.intents.manage`, `payment.refunds.request`, `payment.refunds.approve`, `payment.providers.read`, `payment.providers.manage`, `payment.config.approve_high_risk`, `payment.reconciliation.view`, `payment.reconciliation.manage`.
7. **Given** the codebase, **When** PHPStan level 8 analysis is run, **Then** it reports zero errors in all `App\Modules\Payment\` files.

---

### Story S14 — Migration, Regression, and Release Documentation (Priority: P2)

Before E48 is released, the team must verify that all existing payment-related modules (E08, E09, E10, E15, E16) continue to function correctly, that database migrations apply cleanly, and that operations teams have runbooks for all supported failure scenarios.

**Why this priority**: Release governance (Constitution Principle 15) requires documented regression evidence and operational readiness before any release.

**Independent Test**: Can be tested by running the existing regression suite for E08 (Funding), E09 (Transfer), E10 (Withdrawal), E15 (Service Payments), and E16 (QR Payments) against the E48 codebase and confirming all tests pass without modification.

**Acceptance Scenarios**:

1. **Given** E48 migrations are applied to a fresh test database, **When** `php artisan migrate` completes, **Then** all `payment.*` tables exist with correct columns, indexes, and foreign keys — no existing table is modified.
2. **Given** the existing E08 (Funding), E09 (Transfer), E10 (Withdrawal), E15 (Service), E16 (QR) test suites are run, **When** E48 code is present, **Then** all tests pass — no public contract of any existing module is broken.
3. **Given** the OpenAPI specification `payment.yaml` is generated, **When** it is validated, **Then** it documents all Payment module endpoints: intent creation, option discovery, status query, callback receiver, refund request, admin provider CRUD — with correct request/response schemas, error codes, and idempotency-key header documentation.
4. **Given** a provider becomes unavailable during an active payment, **When** an operations engineer follows the "Provider Suspension" runbook, **Then** they can safely suspend the provider, drain in-flight attempts, and re-enable it — all steps are documented with exact commands and expected outcomes.
5. **Given** the E48 Definition of Done checklist, **When** it is reviewed before release, **Then** all items are verified: PHPStan level 8 clean, unit tests pass, integration tests pass, OpenAPI complete, RBAC seeded, audit events verified, reconciliation tested, no live bank adapter enabled in production.

---

### Edge Cases

- **Concurrent wallet spending**: Two requests for the same wallet pocket arrive simultaneously — only one must succeed; the second receives a clear error.
- **Late provider success after intent expiry**: Provider confirms payment after `expires_at` — recorded as a reconciliation exception, NOT automatically applied.
- **Repeated callback delivery**: Same callback delivered multiple times — exactly one financial effect must result.
- **Out-of-order callbacks**: Failure callback arrives after success callback — terminal state must be preserved; late failure discarded.
- **Provider switching mid-checkout**: Consumer abandons Bank 1 checkout and selects Wallet — new attempt allowed only after Bank 1 attempt reaches a confirmed terminal state.
- **Provider disabled during active checkout**: A provider is suspended after a consumer initiates an attempt — the active attempt continues; new attempts through that provider are blocked.
- **Partial refund then full refund**: BWP 100 payment → BWP 30 partial refund (confirmed) → BWP 70 full refund (should succeed) → BWP 1 additional refund (should fail with `RefundLimitExceededException`).
- **Business module sends wrong amount**: Mobile client sends a tampered amount — `BusinessPurposeResolverInterface` returns the server-calculated amount; the mobile-supplied amount is ignored.
- **Queue unavailable during callback receipt**: HTTP endpoint must persist the raw callback to the database before enqueuing — callback is not lost even if the queue is temporarily down.
- **App restart during redirect checkout**: Consumer is in the middle of a bank redirect when their app is killed — on restart, the saved `payment_intent_id` allows status recovery.

---

## 7. Requirements *(mandatory)*

### Functional Requirements

**Payment Intent and Lifecycle**

- **FR-E48-001**: The system MUST create a `PaymentIntent` for every payment request initiated by a horizontal module, assigning a UUID v7 ID, idempotency key, and expiry time.
- **FR-E48-002**: The system MUST enforce that a `PaymentIntent` may have at most one active (non-terminal) `PaymentAttempt` at a time.
- **FR-E48-003**: The system MUST NOT allow a second financial effect from the same payment intent even if the same callback or event is delivered multiple times.
- **FR-E48-004**: The system MUST use integer minor units for all monetary values — floating-point arithmetic is prohibited in any financial calculation.
- **FR-E48-005**: The system MUST resolve payment amounts server-side using `BusinessPurposeResolverInterface` — mobile-supplied amounts MUST NOT be trusted for financial calculations.
- **FR-E48-006**: The system MUST transition a `PaymentIntent` to `expired` and release any wallet hold when the intent's `expires_at` is reached without a successful attempt.

**Provider Registry and Capability**

- **FR-E48-007**: The system MUST discover available payment options server-side based on: `organisation_id`, `module_type`, `currency_code`, `amount_minor`, `country_code`, and `actor_id` — the mobile client MUST NOT supply the option list.
- **FR-E48-008**: Every provider adapter MUST declare its capabilities explicitly via `getCapabilities()` — unsupported capabilities MUST return an explicit unsupported result, not a silent failure.
- **FR-E48-009**: Any adapter with `adapter_identifier = 'mock_*'` MUST throw `ProductionBlockedException` on any method call when `APP_ENV = production`.
- **FR-E48-010**: Provider credentials MUST be stored as secret manager references only — the actual secret value MUST NOT appear in any database column, API response, log entry, or export.

**Wallet Adapter**

- **FR-E48-011**: The Wallet Payment Adapter MUST verify wallet ownership via `WalletModuleContract.ownerActorId()` before any financial operation.
- **FR-E48-012**: The Wallet Payment Adapter MUST use `WalletModuleContract.createHold()` for balance reservation — it MUST NOT directly mutate wallet balances.
- **FR-E48-013**: All ledger postings for wallet payments MUST go through `LedgerModuleContract` — no direct writes to ledger tables.
- **FR-E48-014**: Wallet reversals MUST use `WalletModuleContract.releaseHold()` and a compensating `LedgerModuleContract` credit — they MUST NOT modify historical ledger entries.

**Callbacks and Events**

- **FR-E48-015**: The system MUST persist the raw callback body to `payment.payment_callbacks` BEFORE dispatching any queue job.
- **FR-E48-016**: Callback signatures MUST be verified using the raw request body and the provider's declared algorithm before any state change occurs.
- **FR-E48-017**: The system MUST publish `payment.confirmed`, `payment.failed`, `payment.reversed`, and `refund.confirmed` integration events via the outbox after confirmed state transitions.
- **FR-E48-018**: Business modules MUST consume `payment.*` events for fulfilment — they MUST NOT query `payment.*` database tables directly.

**Refunds and Reconciliation**

- **FR-E48-019**: Cumulative refunds for a single `PaymentIntent` MUST NOT exceed the original `amount_minor` — the system MUST enforce this check atomically.
- **FR-E48-020**: The system MUST NOT accept manual "mark as paid" actions as substitutes for verified provider evidence.
- **FR-E48-021**: Reconciliation exceptions MUST be recorded for: missing callbacks, unknown outcomes, amount mismatches, orphan provider transactions, duplicate transactions, failed refunds, and unmatched ledger records.

**Security and Operations**

- **FR-E48-022**: Payment intents and attempts MUST be scoped to `organisation_id` — cross-organisation access MUST be rejected server-side.
- **FR-E48-023**: All financial state changes, provider config changes, and refund decisions MUST be recorded via the existing `AuditService`.
- **FR-E48-024**: Sensitive information (credentials, PINs, OTPs, card details) MUST be redacted from all log entries.
- **FR-E48-025**: The module MUST implement circuit breakers per provider — after a configurable consecutive failure threshold, new payment attempts through that provider are blocked and an alert is raised.

### Key Entities

- **PaymentIntent**: Represents a consumer's intention to pay for a specific purpose. Owns the overall payment lifecycle. Linked to one business purpose (via `purpose_type` + `purpose_reference`).
- **PaymentAttempt**: One attempt to complete a `PaymentIntent` through a specific provider. A single intent may have multiple sequential (never concurrent active) attempts.
- **PaymentRefund**: A request to return funds for a succeeded attempt. Multiple partial refunds are allowed up to the original amount.
- **PaymentProvider**: Registry entry for a payment provider (wallet, bank, MNO). Owns display configuration and lifecycle flags (enabled, suspended).
- **PaymentProviderAccount**: A configured merchant/settlement account under a provider. Owns currency, amount limits, organisation scoping, capability flags, and fee rule references.
- **PaymentCallback**: Raw persisted provider webhook payload for replay protection and audit.
- **ReconciliationException**: A detected discrepancy between internal payment records and external provider data, requiring manual investigation.
- **BankProviderDocumentationGap**: Tracks what documentation is required before a bank slot can be activated for live use.
- **BusinessPurposeResolverInterface**: Contract that horizontal modules implement to allow the Payment module to validate and resolve the authoritative payable amount for a given purpose.

---

## 8. Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-E48-001**: A horizontal module developer can integrate payments through `PaymentModuleContract` without writing any provider-specific code — verified by code review showing zero imports from `App\Modules\Payment\Domain\Adapters\` in consuming modules.
- **SC-E48-002**: A new payment provider can be added by writing one adapter class and one admin configuration record — no changes to `PaymentOrchestrationService` or any horizontal module are required.
- **SC-E48-003**: The same payment intent cannot produce two successful financial effects even under concurrent duplicate submission — verified by a concurrency test with 50 simultaneous identical requests producing exactly one debit.
- **SC-E48-004**: Wallet payment checkout completes in under 3 seconds end-to-end for standard transactions (balance check → hold → ledger post → confirmed event).
- **SC-E48-005**: Zero credentials, API keys, PINs, OTPs, or secret values appear in any log entry — verified by log scanning in the test suite.
- **SC-E48-006**: All existing E08, E09, E10, E15, E16 module tests pass without modification after E48 is applied.
- **SC-E48-007**: PHPStan level 8 reports zero errors across all `App\Modules\Payment\` files.
- **SC-E48-008**: Flutter `PaymentCheckoutSheet` correctly handles all 11 `PaymentCheckoutState` subclasses (`LoadingOptions`, `OptionsLoaded`, `ConfirmingPayment`, `AwaitingRedirect`, `PollingStatus`, `PaymentSucceeded`, `PaymentFailed`, `PaymentCancelled`, `PaymentExpired`, `InsufficientFunds`, `StepUpRequired`) — verified by 10 widget test scenarios per `contracts/flutter-checkout.md` test contract. Note: `refunding`, `partially_refunded`, and `refunded` are backend `PaymentIntentStatus` values; they are NOT checkout widget states. Post-payment refund status display is handled by `PaymentStatusScreen`, not `PaymentCheckoutSheet`.
- **SC-E48-009**: Mock bank callback replay test delivers the same callback 10 times and produces exactly one `payment.confirmed` event — verified by event count assertion.
- **SC-E48-010**: The reconciliation exception queue detects a simulated missing callback within the scheduled reconciliation window — verified by reconciliation integration test.

---

## 9. Dependency Map

### External Dependencies (existing modules — reused, not modified)

| Component | Module | Used By |
|-----------|--------|---------|
| `ProviderAdapterInterface` | Platform (E1000) | S03, S06, S07 |
| `ProviderWebhookContract` | Platform (E1000) | S10 |
| `IdempotencyServiceInterface` | Platform | S02, S05, S06, S10 |
| `OutboxEventPublisher` | Platform | S10, S11 |
| `AuditService` | Platform | S04, S05, S06, S11, S13 |
| `ConfigurationService` | Platform (E28) | S05, S06, S13 |
| `WalletModuleContract` | Wallet (E06) | S06 |
| `LedgerModuleContract` | Ledger (E07) | S06, S11 |
| `ProviderHealthInterface` | Integration (E25) | S03, S13 |
| `WebhookEventRepository` | Integration (E25) | S10 |
| Flutter auth / Riverpod | Customer App (E31) | S09 |

### Story Dependency Order

```
S01 (Assessment + ADRs)
  └── S02 (Data Model)
        └── S03 (Provider Registry)
              ├── S04 (Admin Config) ──────────────────────── S13 (Security)
              └── S05 (Orchestration)
                    ├── S06 (Wallet Adapter)
                    │     └── S09 (Flutter Checkout) ◄── S08
                    ├── S07 (Bank Framework)
                    │     └── S08 (Mock Bank)
                    ├── S10 (Callbacks)
                    │     └── S11 (Refunds + Reconciliation)
                    │     └── S12 (Module Integration)
                    └── S13 (Security cross-cuts all)
S14 (Migration + Release) ── depends on ALL prior stories
```

### Parallel Work Available
- S04 (Admin UI) can be developed in parallel with S05–S08 and integrated at S09
- S07 (Bank Framework) is independent of S06 (Wallet Adapter)
- S13 (Security) cross-cuts all stories and can be validated incrementally

---

## 10. Financial Safety Rules (Non-Negotiable)

These rules are derived from the project Constitution (Principle 4) and are enforced by test assertions in addition to code review:

1. **No direct balance mutation**: All wallet balance effects go through `WalletModuleContract.createHold()` and `LedgerModuleContract`. Any code path that directly updates a wallet balance field is a critical defect.
2. **No second ledger**: All financial postings use the existing `LedgerModuleContract`. The `payment.*` schema records payment state, not ledger balances.
3. **Integer minor units only**: `amount_minor`, `fee_minor` are `BIGINT` columns. PHP code uses `int`. No `float`, `decimal`, `double`, or `Money::fromDecimal()` calls with floating literals.
4. **Idempotency on all state-changing operations**: Every API that transitions `PaymentIntent`, `PaymentAttempt`, or `PaymentRefund` state requires an idempotency key. The `IdempotencyServiceInterface` is used consistently.
5. **No automatic provider retry**: When a previous attempt's outcome is ambiguous, no new attempt may be created until the previous attempt is definitively resolved (terminal state confirmed by provider or reconciliation).
6. **Immutable history**: No UPDATE or DELETE is permitted on `PaymentIntent`, `PaymentAttempt`, or `PaymentRefund` records. All corrections use compensating records.
7. **Server-side amount authority**: `BusinessPurposeResolverInterface` is the sole source of truth for payable amounts. Mobile-submitted amounts are advisory hints only and are ignored in financial processing.

---

## 11. Assumptions

- E06 (Wallet), E07 (Ledger), E08 (Funding), E09 (Transfer), E10 (Withdrawal), E15 (Service), E16 (QR), E25 (Integration), E28 (Configuration) are all deployed and their module contracts are stable.
- `WalletModuleContract` provides sufficient interface for the Wallet Payment Adapter without requiring new methods. If a gap is found during S06 implementation, an additive extension with a dedicated ADR is required — not an ad-hoc addition.
- Bank 1, Bank 2, and Bank 3 are placeholder names. No real bank API documentation is available at the time of this specification. Live bank integration is BLOCKED pending that documentation.
- The existing Platform secrets manager integration is sufficient for storing provider credential references. No new secrets manager infrastructure is introduced.
- The Flutter mobile apps use the Riverpod state management and existing deep-link handling infrastructure from E31/E32.
- The `payment.*` PostgreSQL schema is a new schema added alongside existing schemas (e.g., `wallet.*`, `ledger.*`, `platform.*`). No existing schema is modified.
- Fee calculation for payment options reuses the existing Fees module (E21) fee rules — the Payment module calls fee resolution but does not own fee rules.
- The admin Next.js app has existing pattern libraries (table, form, badge, dialog, approval workflow) from E33–E41 that are reused for the payment provider configuration UI.
- Organisation and tenant boundaries from E04 (Roles & Permissions) are sufficient for scoping payment data. No new tenancy model is introduced.
- The step-up authentication threshold (`auth.step_up_threshold`) configured in E28 is the authority for when step-up is required for wallet payments — no new threshold configuration is introduced in E48.
