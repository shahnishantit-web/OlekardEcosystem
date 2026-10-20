# Implementation Plan: Shared Payment Integration and Orchestration (E48)

**Branch**: `048-shared-payment-integration` | **Date**: 2026-10-18 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/048-shared-payment-integration/spec.md`

---

## Summary

E48 creates `App\Modules\Payment` — a new, additive modular-monolith module that provides a single provider-agnostic payment orchestration layer for all OleKard horizontal modules. It introduces the `payment.*` PostgreSQL schema, `PaymentOrchestrationService`, a `WalletPaymentAdapter` (delegating exclusively to existing `WalletModuleContract` and `LedgerModuleContract`), an `AbstractBankPaymentAdapter` framework with a production-blocked `MockBankPaymentAdapter`, a Flutter `PaymentCheckoutSheet` widget, an async callback inbox, a refund service with cumulative protection, a reconciliation exception queue, and a Next.js admin UI for provider configuration. No existing module is modified. Live bank integrations are BLOCKED pending real bank documentation.

---

## Technical Context

**Language/Version**: PHP 8.4+ (Laravel 11) for backend; TypeScript 5.x / Next.js 14 App Router for admin; Dart / Flutter 3.x for mobile

**Primary Dependencies**:
- Backend: Laravel, Eloquent (UUID v7 casts), `App\Modules\Platform\Application\Services\IdempotencyService`, `OutboxEventPublisher`, `AuditService`, `ConfigurationService`; existing `WalletModuleContract` (E06), `LedgerModuleContract` (E07), `ProviderAdapterInterface` (E1000)
- Admin: existing component library (Card, Badge, Button, Table, Dialog, ApprovalWorkflow from E33–E41)
- Mobile: Riverpod, `core/api` HTTP client, `core/auth` state, `core/router` deep-link handler (E31/E32)

**Storage**: PostgreSQL — new `payment.*` schema with 8 tables; no modifications to existing schemas

**Testing**: PHPUnit (Laravel feature + unit tests); Jest + @testing-library/react (admin); Flutter test package (widget + integration)

**Target Platform**: Linux container (API), desktop-first browser (admin), Android/iOS (Flutter)

**Project Type**: Multi-tier — Laravel API module + Next.js admin module + Flutter shared widget

**Performance Goals**: Wallet payment round-trip < 3 s; option-discovery response < 500 ms; callback processing < 5 s after receipt (queue dispatch); no financial operation blocks HTTP thread

**Constraints**:
- No direct wallet balance mutation
- No second ledger
- No float arithmetic
- No live bank adapters until documentation supplied
- PHPStan level 8 clean
- No new npm packages in admin (use existing component library)
- No new Flutter packages beyond existing in pubspec

**Scale/Scope**: ~8 DB migrations, ~40 PHP domain files, ~15 PHP infrastructure files, ~10 admin pages/components, ~1 Flutter widget + provider + repository, ~80 tests (PHP) + ~25 (admin) + ~20 (Flutter)

---

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|-------|
| P1 — Source of truth | ✅ PASS | Additive epic; no silent change to existing module contracts |
| P2 — TBD governance | ✅ PASS | No TBD values invented; bank integrations explicitly BLOCKED with documentation gap tracking |
| P3 — Configuration over hard-coding | ✅ PASS | Provider list, limits, capabilities, fees all server-side configurable |
| P4 — Financial integrity | ✅ PASS | Integer minor units; immutable history; idempotency on all state-changing operations; no float; double-entry via LedgerModuleContract only |
| P5 — Identity and authorization | ✅ PASS | RBAC permissions seeded; step-up for wallet payments and high-risk actions via existing threshold config |
| P6 — Zero trust / security | ✅ PASS | Provider credentials stored as secret references only; log redaction required; circuit breakers per provider |
| P7 — Domain boundaries | ✅ PASS | `App\Modules\Payment` is explicit, extraction-ready; delegates to Wallet/Ledger via contracts; `PaymentModuleContract` is the only external interface |
| P8 — API contracts | ✅ PASS | Versioned `/v1/payment/*` REST endpoints; idempotency-key header on mutations; OpenAPI `payment.yaml` required |
| P9 — Event reliability | ✅ PASS | Outbox pattern for `payment.confirmed`, `payment.failed`, `payment.reversed`, `refund.confirmed`; inbox/raw-persist for callbacks before processing |
| P10 — UX design system | ✅ PASS | Flutter checkout uses design tokens from E35; admin uses existing component library |
| P11 — Accessibility | ✅ PASS | Admin UI inherits accessible component library; Flutter widget uses Semantics widget |
| P12 — Test-first quality | ✅ PASS | Unit, integration, concurrency, widget, accessibility, regression tests required per story |
| P13 — Observability | ✅ PASS | Structured logging, correlation IDs, metrics per provider call, audit events |
| P14 — No silent arch changes | ✅ PASS | ADR-E48-001 and ADR-E48-002 required before implementation begins; no stack changes |
| P15 — Release governance | ✅ PASS | S14 defines Definition of Done; live bank gating documented |

**No violations. Proceed to Phase 1.**

---

## Project Structure

### Documentation (this feature)

```text
specs/048-shared-payment-integration/
├── plan.md              ← this file
├── research.md          ← key decisions (Phase 0 output)
├── data-model.md        ← entity/DB schema reference (Phase 1 output)
├── quickstart.md        ← smoke-test scenarios (Phase 1 output)
├── checklists/
│   └── requirements.md
├── contracts/
│   ├── payment-api.md         ← REST API contract (Phase 1 output)
│   ├── payment-events.md      ← outbox integration event contract (Phase 1 output)
│   ├── flutter-checkout.md    ← Flutter widget contract (Phase 1 output)
│   └── module-contract.md     ← PaymentModuleContract PHP interface (Phase 1 output)
└── tasks.md             ← generated by /speckit-tasks (NOT created by /speckit-plan)
```

### Source Code

```text
# Backend: Laravel API module (new — additive)
wallet-ecosystem/services/api/app/Modules/Payment/
├── Domain/
│   ├── Contracts/
│   │   ├── PaymentModuleContract.php
│   │   ├── PaymentAdapterInterface.php
│   │   ├── BusinessPurposeResolverInterface.php
│   │   ├── PaymentIntentRepositoryInterface.php
│   │   ├── PaymentAttemptRepositoryInterface.php
│   │   ├── PaymentRefundRepositoryInterface.php
│   │   └── PaymentProviderRepositoryInterface.php
│   ├── Enums/
│   │   ├── PaymentIntentStatus.php
│   │   ├── PaymentAttemptStatus.php
│   │   ├── PaymentRefundStatus.php
│   │   └── ProviderCapabilityEnum.php
│   ├── ValueObjects/
│   │   ├── PaymentIntentRequest.php      ← replaces Application/DTOs/CreatePaymentIntentDTO
│   │   ├── PaymentInitiateRequest.php
│   │   ├── PaymentAttemptResult.php
│   │   ├── PayableObligation.php
│   │   ├── OptionsQuery.php
│   │   ├── PaymentProviderOption.php
│   │   └── PaymentRefundRequest.php      ← replaces Application/DTOs/InitiateRefundDTO
│   ├── Events/
│   │   ├── PaymentConfirmedEvent.php
│   │   ├── PaymentFailedEvent.php
│   │   ├── PaymentReversedEvent.php
│   │   └── RefundConfirmedEvent.php
│   ├── Exceptions/
│   │   ├── DuplicateAttemptException.php
│   │   ├── InsufficientFundsException.php
│   │   ├── StepUpRequiredException.php
│   │   ├── RefundLimitExceededException.php
│   │   ├── CapabilityUnsupportedException.php
│   │   └── ProductionBlockedException.php
│   └── StateMachines/
│       ├── PaymentIntentStateMachine.php
│       └── PaymentAttemptStateMachine.php
├── Application/
│   ├── PaymentOrchestrationService.php
│   ├── PaymentApplicationService.php    ← implements PaymentModuleContract
│   ├── Services/
│   │   ├── ProviderRegistryService.php
│   │   ├── RefundService.php
│   │   ├── ReconciliationExceptionService.php
│   │   ├── PaymentCallbackService.php
│   │   └── BusinessPurposeResolverRegistry.php
│   └── Jobs/
│       ├── PaymentCallbackProcessingJob.php
│       ├── PaymentAttemptTimeoutJob.php
│       └── PaymentIntentExpiredJob.php
├── Infrastructure/
│   ├── Adapters/
│   │   ├── WalletPaymentAdapter.php
│   │   ├── AbstractBankPaymentAdapter.php
│   │   ├── MockBankPaymentAdapter.php
│   │   ├── MockBankCallbackParser.php
│   │   └── BankCallbackVerifier.php
│   ├── Repositories/
│   │   ├── EloquentPaymentIntentRepository.php
│   │   ├── EloquentPaymentAttemptRepository.php
│   │   ├── EloquentPaymentRefundRepository.php
│   │   └── EloquentPaymentProviderRepository.php
│   └── Models/
│       ├── PaymentIntentModel.php
│       ├── PaymentAttemptModel.php
│       ├── PaymentRefundModel.php
│       ├── PaymentProviderModel.php
│       ├── PaymentProviderAccountModel.php
│       ├── PaymentCallbackModel.php
│       ├── ReconciliationExceptionModel.php
│       └── BankProviderDocumentationGapModel.php
├── Http/
│   ├── Controllers/
│   │   ├── PaymentIntentController.php
│   │   ├── PaymentOptionsController.php
│   │   ├── PaymentCallbackController.php
│   │   ├── PaymentRefundController.php
│   │   └── Admin/
│   │       ├── PaymentProviderAdminController.php
│   │       └── ReconciliationExceptionAdminController.php
│   ├── Requests/
│   └── Resources/
└── Providers/
    └── PaymentServiceProvider.php

# Backend: Migrations
wallet-ecosystem/services/api/database/migrations/
├── 2026_10_18_000001_create_payment_schema.php
├── 2026_10_18_000002_create_payment_providers_table.php
├── 2026_10_18_000003_create_payment_provider_accounts_table.php
├── 2026_10_18_000004_create_payment_intents_table.php
├── 2026_10_18_000005_create_payment_attempts_table.php
├── 2026_10_18_000006_create_payment_refunds_table.php
├── 2026_10_18_000007_create_payment_callbacks_table.php
└── 2026_10_18_000008_create_reconciliation_exceptions_table.php

# Backend: Tests
wallet-ecosystem/services/api/tests/
├── Unit/Modules/Payment/
│   ├── StateMachines/
│   │   ├── PaymentIntentStateMachineTest.php
│   │   └── PaymentAttemptStateMachineTest.php
│   ├── Adapters/
│   │   ├── WalletPaymentAdapterTest.php
│   │   └── MockBankPaymentAdapterTest.php
│   └── Services/
│       ├── RefundServiceTest.php          ← T173
│       └── ProviderRegistryServiceTest.php
└── Feature/Modules/Payment/
    ├── PaymentOrchestrationTest.php
    ├── PaymentCallbackProcessingTest.php
    ├── WalletPaymentConcurrencyTest.php
    ├── RefundCumulativeProtectionTest.php
    ├── PaymentIdempotencyTest.php
    ├── HorizontalModuleIntegrationTest.php
    ├── PaymentSecurityTest.php
    └── ReconciliationExceptionTest.php    ← SC-E48-010 (scheduled reconciliation run)

# Admin: Next.js module (new — additive)
wallet-ecosystem/apps/admin/
├── src/modules/payment/
│   ├── api/
│   │   └── payment-api-client.ts
│   ├── pages/
│   │   ├── payment-providers.tsx
│   │   ├── payment-provider-detail.tsx
│   │   ├── payment-provider-create.tsx
│   │   ├── payment-provider-edit.tsx
│   │   └── reconciliation-exceptions.tsx
│   └── components/
│       ├── ProviderStatusBadge.tsx
│       ├── CapabilityFlagsEditor.tsx
│       ├── CredentialRotationForm.tsx
│       ├── MockBannerAlert.tsx
│       └── ReconciliationExceptionList.tsx
├── lib/api/
│   └── payment.ts           ← type definitions
└── __tests__/features/payment/
    └── payment-providers.test.tsx

# Mobile: Flutter shared module (new — additive to customer + agent apps)
wallet-ecosystem/apps/customer/lib/modules/payment/
├── data/
│   ├── payment_repository.dart
│   └── payment_api_client.dart
├── domain/
│   ├── models/
│   │   ├── payment_intent.dart
│   │   ├── payment_option.dart
│   │   └── payment_status.dart
│   └── payment_notifier.dart
└── presentation/
    ├── payment_checkout_sheet.dart
    ├── payment_status_screen.dart
    └── widgets/
        ├── payment_option_tile.dart
        ├── wallet_balance_tile.dart
        └── payment_amount_summary.dart

wallet-ecosystem/apps/customer/test/modules/payment/
└── payment_checkout_sheet_test.dart

# Agent app mirrors customer structure
wallet-ecosystem/apps/agent/lib/modules/payment/  (same structure)

# OpenAPI
wallet-ecosystem/services/api/openapi/v1/payment.yaml

# ADRs
docs/architecture/decisions/
├── ADR-E48-001-payment-module-ownership.md
└── ADR-E48-002-wallet-payment-adapter-delegation.md
```

**Structure Decision**: Multi-project (Laravel API + Next.js admin + Flutter mobile) following established E31/E32/E33/E35/E36 patterns. The `App\Modules\Payment` module is structurally identical to existing modules (Domain/Application/Infrastructure/Http/Providers). Flutter module follows the `lib/modules/<feature>/` pattern established in E31.

---

## Complexity Tracking

No constitution violations. No complexity justification required.

---

## Story Implementation Order

Based on the dependency analysis in spec.md §9:

| Wave | Stories | Can Start When |
|------|---------|---------------|
| Wave 1 | S01 | Immediately |
| Wave 2 | S02 | S01 accepted |
| Wave 3 | S03 | S02 complete |
| Wave 4 (parallel) | S04, S05 | S03 complete |
| Wave 5 (parallel) | S06, S07 | S05 complete |
| Wave 6 | S08 | S07 complete |
| Wave 7 (parallel) | S09, S10 | S06+S08 (S09), S05 (S10) |
| Wave 8 (parallel) | S11, S12 | S10 complete |
| Wave 9 | S13 | S02+S03+S04+S05 (ongoing validation) |
| Wave 10 | S14 | All prior stories complete |
