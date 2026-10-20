# Research: Shared Payment Integration and Orchestration (E48)

**Date**: 2026-10-18 | **Branch**: `048-shared-payment-integration`

All architectural questions resolved by inspecting existing codebase. No external research required.

---

## Decision 1: Module Namespace and Registration Pattern

**Decision**: `App\Modules\Payment\` — registered via `PaymentServiceProvider` following the existing pattern used by all 29 current modules.

**Rationale**: All existing modules (Wallet, Ledger, Funding, Qr, Service, Integration, etc.) live under `app/Modules/{ModuleName}/` and are bootstrapped by a `{ModuleName}ServiceProvider`. Deviating would break discoverability and container binding conventions.

**Alternatives considered**: Standalone microservice — rejected because the existing architecture is a modular monolith; extraction to a service is the enterprise target-state, not the current baseline (Constitution Principle 14).

---

## Decision 2: PaymentAdapterInterface Extension Strategy

**Decision**: `PaymentAdapterInterface` extends `ProviderAdapterInterface` (from E1000/Platform). It adds payment-specific methods: `initiatePayment(PaymentInitiateRequest): PaymentAttemptResult`, `queryPaymentStatus(string $providerRef): PaymentStatusResult`, `initiateRefund(PaymentRefundRequest): PaymentRefundResult`, `cancelPayment(string $providerRef): PaymentCancelResult`. The `send()` method from `ProviderAdapterInterface` is retained as the generic low-level transport.

**Rationale**: Extending rather than replacing `ProviderAdapterInterface` means the Platform module's adapter registry, audit emission rules, and metric obligations (FR-E1000-020, FR-E1000-021) are automatically inherited. No platform changes required.

**Alternatives considered**: Separate interface not extending `ProviderAdapterInterface` — rejected because it would bypass the established audit and metric obligations documented in `ProviderAdapterInterface` PHPDoc.

---

## Decision 3: Wallet Adapter — Hold-then-Debit vs Direct Debit

**Decision**: Use **hold-then-debit** for wallet payments. `WalletPaymentAdapter.initiatePayment()` calls `WalletModuleContract.createHold()` to reserve funds atomically, then immediately calls `LedgerModuleContract.postEntry()` to debit the pocket. For wallet payments (synchronous), hold and debit happen in the same database transaction. The hold is only released explicitly on reversal/refund.

**Rationale**: `WalletModuleContract.createHold()` uses `SELECT FOR UPDATE` internally, preventing concurrent overdrafts. Calling `createHold()` before `postEntry()` ensures balance is not over-committed even if the ledger post briefly fails and is retried. This matches the existing pattern used by E09 (Transfer) and E10 (Withdrawal).

**Alternatives considered**: Direct debit without hold — rejected because two concurrent wallet payments would both pass the balance check before either is posted, enabling overdraft.

---

## Decision 4: Payment Callback Inbox Pattern

**Decision**: Follow the established **inbox** pattern: HTTP controller persists the raw callback body + headers + provider_code + received_at to `payment.payment_callbacks` (status = `received`) synchronously BEFORE dispatching `PaymentCallbackProcessingJob`. The job performs signature verification, deduplication, state transition, and outbox event publishing asynchronously.

**Rationale**: Existing modules (Funding via `ProviderCallbackService`) use this same receive-first-process-later pattern. It guarantees callback durability even if the queue is momentarily unavailable.

**Alternatives considered**: Synchronous inline processing — rejected because provider HTTP connections time out; long processing blocks callback endpoints and risks provider retry storms.

---

## Decision 5: State Machine Implementation

**Decision**: Implement `PaymentIntentStateMachine` and `PaymentAttemptStateMachine` as PHP classes with static transition tables. Use a DB-level check constraint on `status` columns (via migration) and an application-level guard method `assertValidTransition(currentStatus, targetStatus)` that throws `InvalidStateTransitionException`.

**Rationale**: The existing codebase uses this pattern in the Funding module (`FundingStatus` enum + service-layer checks). DB constraints provide a hard safety net; application-layer state machines provide clear code-path traceability.

**Alternatives considered**: Spatie/laravel-model-states package — rejected because it adds a dependency; the existing project uses manual state machine implementation consistently.

---

## Decision 6: Idempotency Strategy for Payment Intents

**Decision**: Reuse `IdempotencyServiceInterface` (from Platform) on the HTTP layer for API request idempotency. Additionally, apply DB-level `UNIQUE(idempotency_key, payer_actor_id)` on `payment.payment_intents` and `UNIQUE(idempotency_key)` on `payment.payment_attempts`. The two-layer approach (HTTP middleware + DB unique constraint) matches the existing pattern used by Funding, Transfer, and Withdrawal modules.

**Rationale**: `IdempotencyServiceInterface.acquireLock()` + `recordResponse()` handles HTTP-level replay. The DB unique constraint is the final safety net for race conditions between parallel requests that pass the lock check simultaneously.

**Alternatives considered**: Application-layer idempotency only (no DB constraint) — rejected because the DB constraint is the only absolute prevention of duplicates under concurrent load.

---

## Decision 7: Mock Bank Production Guard

**Decision**: `MockBankPaymentAdapter` reads `app()->environment()` in its constructor and throws `ProductionBlockedException` immediately if the environment is `production` or `staging`. It cannot be registered in `PaymentServiceProvider` as a valid adapter for production environments — the service provider's provider registry rejects adapters with `adapter_identifier` prefix `mock_` when `APP_ENV != local|testing`.

**Rationale**: Two-layer protection: constructor guard (prevents instantiation) + service provider guard (prevents registration). This matches the requirement that mock adapters be "impossible to enable in production."

**Alternatives considered**: Feature flag only — rejected because feature flags can be misconfigured; a hard `APP_ENV` check in the constructor cannot be bypassed by configuration.

---

## Decision 8: Flutter Checkout Architecture

**Decision**: `PaymentCheckoutSheet` is a bottom sheet (`showModalBottomSheet`) driven by a Riverpod `AsyncNotifier<PaymentCheckoutState>` (`PaymentNotifier`). The notifier calls `PaymentRepository.getOptions()` on init, and `PaymentRepository.createIntent() + initiateAttempt()` on confirmation. Status polling uses Riverpod's `ref.listen` with a timer-based refresh every 3 seconds when status is non-terminal, with exponential backoff.

**Rationale**: The existing customer and agent apps already use Riverpod `AsyncNotifier` for all data-fetching screens (E31/E32). `showModalBottomSheet` is the established UX pattern for transient payment flows (used in funding and transfer screens).

**Alternatives considered**: Navigator 2.0 full-screen page — available as fallback if the provider's checkout requires a full-screen redirect that cannot be embedded. The widget supports both modes via a `PaymentCheckoutMode.sheet | .page` enum.

---

## Decision 9: Admin UI Pattern

**Decision**: Create `src/modules/payment/` following the exact same structure as `src/modules/fees/` (established in E38). Use existing `Card`, `Badge`, `Table`, `Button`, `Dialog`, `ApprovalWorkflow` components from the admin component library. No new npm packages.

**Rationale**: E38 (Fee Rules Management) established the pattern for a configuration-focused admin module with list/detail/create/edit pages and approval workflows. E48 admin replicates this pattern.

**Alternatives considered**: None — this is a standards compliance requirement (Constitution Principle 10, no new npm packages rule).

---

## Decision 10: Reconciliation Exception Queue

**Decision**: `ReconciliationException` records are created by `ReconciliationExceptionService` and stored in `payment.reconciliation_exceptions`. The admin UI displays them with filtering, investigation notes, and resolution status. Resolution is documented (notes + resolved_by + resolved_at) but never "mark as paid" — financial state is only changed by verified provider evidence.

**Rationale**: This matches the existing `Reconciliation` module (E20) approach for exception management. The distinction between investigation tracking and financial state change is a hard requirement per FR-E48-020 and Constitution Principle 4.

**Alternatives considered**: Reusing E20's reconciliation exception model — rejected because E20 is scoped to settlement/funding reconciliation; E48 needs its own reconciliation scope for payment intents, attempts, and provider callbacks.

---

## Decision 11: outbox Event Naming Convention

**Decision**: Follow the established repository event naming convention (verified in existing modules):
- `payment.confirmed` (dot-separated, past tense, no module prefix)
- `payment.failed`
- `payment.reversed`
- `refund.confirmed`

These are published via `OutboxEventPublisher` after DB state commit, following the outbox pattern in Platform.

**Rationale**: The existing event naming convention (verified across E06–E28 modules) uses lowercase dot-separated names. `OutboxEventPublisher` is the standard mechanism for durable event delivery.

---

## Decision 12: Database Schema Strategy

**Decision**: All E48 tables live in the `payment` PostgreSQL schema. A first migration creates the schema (`CREATE SCHEMA IF NOT EXISTS payment`). Subsequent migrations create tables with the `payment.` prefix. Eloquent models use `protected $table = 'payment.payment_intents'` etc., following the pattern established by `platform.*`, `wallet.*`, `ledger.*` schemas.

**Rationale**: Schema-per-module is the established pattern (verified in Platform, Wallet, Ledger modules). It provides namespace isolation and clear module boundaries.

---

## Unresolved Items

None. All architectural questions are resolved from codebase inspection. Bank 1, Bank 2, Bank 3 live integrations remain **BLOCKED** pending real bank documentation — this is a known external dependency, not an internal unknow.
