# Implementation Plan: P04 — Purpose Validation, Quotes and Fee Snapshots

**Branch**: `053-purpose-validation-fee-snapshots` | **Date**: 2026-10-21 | **Spec**: [spec.md](spec.md)

**Input**: [spec.md](spec.md)

---

## Summary

Extends the Payment module (E48) to: (1) enrich resolved obligations with expiry/hold/beneficiary fields; (2) create an immutable `PaymentQuote` record with a self-contained fee snapshot on every intent creation; (3) revalidate stale quotes synchronously before payment attempts; (4) expose a `GET /payment/intents/{id}/quote` endpoint. The `FeesModuleContract::calculateFee()` contract is reused directly. No new DB connections, modules, or external dependencies are introduced.

---

## Technical Context

**Language/Version**: PHP 8.4+, Laravel 11

**Primary Dependencies**: Existing `FeesModuleContract`, `BusinessPurposeResolverRegistry`, `PaymentOrchestrationService`, `AuditServiceInterface`, `ConfigurationServiceInterface`

**Storage**: PostgreSQL, `payment.*` schema (schema-per-module). New table: `payment.payment_quotes`. New column on `payment.payment_intents`: `active_quote_id`.

**Testing**: PHPUnit (Laravel), PHPStan level 8

**Target Platform**: Linux server (Docker Compose for dev)

**Performance Goals**: Quote creation must add < 5ms to intent creation p95 latency (single DB write + in-process fee calculation)

**Constraints**: No wallet balance mutations. No ledger postings. No external API calls in P04. Synchronous only. One currency, one provider option per quote.

**Scale/Scope**: Same as P02/P03 — single-tenant Payment module context

---

## Constitution Check

| Principle | Gate | Status |
|-----------|------|--------|
| P2 — TBD Governance | `beneficiary_allocations` and `hold_reference` stored as AWAITING_DOCUMENT. No invented values. | PASS |
| P3 — Configuration | `payment.quote_ttl_seconds` seeded; not hard-coded. | PASS |
| P4 — Financial Integrity | No wallet mutations, no ledger postings. Quote records resolved amounts. | PASS |
| P4 — Idempotency | Quote creation is idempotent (same intent + same TTL window = same active quote). | PASS |
| P5 — Authorization | Quote endpoint uses `payment.intents.read` permission. | PASS |
| P7 — Domain Boundaries | Business logic in `QuoteService`, not in controllers. `EnrichedPayableObligation` is a Payment-module VO; domain resolvers supply the values without importing domain internals. | PASS |
| P8 — API Contracts | New endpoint at `/v1/payment/intents/{id}/quote`. OpenAPI updated. | PASS |
| P9 — Event Reliability | `payment.quote.created`, `payment.quote.superseded`, `payment.quote.consumed` audited. | PASS |
| P13 — Observability | Audit log entries for all quote lifecycle events. | PASS |
| P14 — No Silent Arch Changes | Stack unchanged. No new modules. | PASS |

---

## Project Structure

### Documentation (this feature)

```text
specs/053-purpose-validation-fee-snapshots/
├── plan.md              ← this file
├── research.md          ← Phase 0 output
├── data-model.md        ← Phase 1 output
├── quickstart.md        ← Phase 1 output
├── contracts/
│   └── quote-api.md     ← Phase 1 output
├── checklists/
│   └── requirements.md
└── tasks.md             ← /speckit-tasks output (next step)
```

### Source Code (repository root)

```text
wallet-ecosystem/services/api/
├── app/Modules/Payment/
│   ├── Domain/
│   │   ├── Contracts/
│   │   │   ├── BusinessPurposeResolverInterface.php      ← modify: return type → EnrichedPayableObligation
│   │   │   └── PaymentQuoteRepositoryInterface.php       ← NEW
│   │   ├── Enums/
│   │   │   └── QuoteStatus.php                          ← NEW
│   │   ├── Exceptions/
│   │   │   ├── PurposeExpiredException.php               ← NEW (extends PurposeNotPayableException)
│   │   │   ├── QuoteStaleConfirmRequiredException.php    ← NEW
│   │   │   └── PurposeNoLongerPayableException.php       ← NEW
│   │   └── ValueObjects/
│   │       └── EnrichedPayableObligation.php             ← NEW (extends/replaces PayableObligation return)
│   ├── Application/
│   │   ├── Services/
│   │   │   └── QuoteService.php                         ← NEW (createQuote, refreshQuote, consumeQuote)
│   │   └── PaymentOrchestrationService.php              ← modify: createIntent + initiateAttempt
│   ├── Infrastructure/
│   │   ├── Migrations/
│   │   │   ├── 2026_10_21_000006_create_payment_quotes_table.php   ← NEW
│   │   │   └── 2026_10_21_000007_add_active_quote_id_to_payment_intents.php ← NEW
│   │   ├── Models/
│   │   │   └── PaymentQuoteModel.php                    ← NEW
│   │   ├── Repositories/
│   │   │   └── EloquentPaymentQuoteRepository.php       ← NEW
│   │   └── Stubs/
│   │       └── DevPurposeStubResolver.php               ← NEW (production-blocked dev resolver)
│   ├── Http/
│   │   └── Controllers/
│   │       └── PaymentQuoteController.php               ← NEW (GET /payment/intents/{id}/quote)
│   ├── Providers/
│   │   └── PaymentServiceProvider.php                   ← modify: bind QuoteService, repository, DevStub
│   └── Tests/
│       ├── StubPurposeResolver.php                      ← modify: return EnrichedPayableObligation
│       └── Unit/
│           ├── EnrichedObligationValidationTest.php     ← NEW
│           ├── QuoteArithmeticTest.php                  ← NEW
│           ├── QuoteStaleRevalidationTest.php            ← NEW
│           ├── DevStubProductionGuardTest.php           ← NEW
│           └── FeeSnapshotRoundingTest.php              ← NEW
├── routes/modules/
│   └── payment.php                                       ← modify: add GET /intents/{id}/quote route
├── openapi/v1/
│   └── payment.yaml                                      ← modify: add PaymentQuote schema + endpoint
└── database/seeders/
    └── PlatformConfigurationSeeder.php                  ← modify: add payment.quote_ttl_seconds = 300
```

---

## Implementation Phases

### Phase 1 — Foundation: Value Objects and Enums (no DB)

1. Create `EnrichedPayableObligation` — new final readonly class with original 5 fields + `expiresAt`, `holdReference`, `beneficiaryAllocations`, `metadata`
2. Update `BusinessPurposeResolverInterface::resolve()` return type from `PayableObligation` to `EnrichedPayableObligation`
3. Update `StubPurposeResolver` to return `EnrichedPayableObligation` (null expiry, null hold, empty allocations)
4. Create `QuoteStatus` PHP enum with `isTerminal()` and `canTransitionTo()` methods
5. Create `QuoteStaleConfirmRequiredException` (carries new `PaymentQuoteModel`)
6. Create `PurposeNoLongerPayableException`

### Phase 2 — Schema: Migration and Model

7. Create migration `2026_10_21_000006_create_payment_quotes_table.php`
8. Create migration `2026_10_21_000007_add_active_quote_id_to_payment_intents.php`
9. Create `PaymentQuoteModel` with `$connection = 'payment'`, correct `$fillable`, casts, and relationship to intent
10. Create `PaymentQuoteRepositoryInterface` with: `save()`, `findById()`, `findActiveForIntent()`, `findAllForIntent()`
11. Create `EloquentPaymentQuoteRepository` implementing the interface

### Phase 3 — Service: Quote Lifecycle

12. Create `QuoteService` with:
    - `createQuote(intent, obligation, feeResult, feeRuleDetails, providerCode, correlationId): PaymentQuoteModel`
    - `revalidateQuote(intent, quoteService, resolver, correlationId): RevalidationResult`
    - `consumeQuote(quote, correlationId): void`
    - `cancelQuotesForIntent(intentId, correlationId): void`
13. Update `PaymentOrchestrationService::createIntent()` to:
    - Resolve `EnrichedPayableObligation` (validate expiry + ownership)
    - Call `FeesModuleContract::calculateFee()` for the fee
    - Read `FeeRuleModel` details by `feeRuleId` to build snapshot
    - Call `QuoteService::createQuote()`
    - Update `intent.active_quote_id`, `intent.fee_quote_minor`, `intent.fee_quote_provider_code`, `intent.version`
14. Update `PaymentOrchestrationService::initiateAttempt()` to:
    - Load active quote
    - If expired: call `QuoteService::revalidateQuote()`
    - On stale total: throw `QuoteStaleConfirmRequiredException`
    - On purpose no longer payable: throw `PurposeNoLongerPayableException`, cancel intent
    - On same total (or fresh): call `QuoteService::consumeQuote()`

### Phase 4 — Dev Stub

15. Create `DevPurposeStubResolver` in `Infrastructure/Stubs/` — returns fixed BWP 100.00 (10000 minor), no expiry, empty allocations, null hold. Guarded by `ProductionBlockedException` in production/staging.
16. Update `PaymentServiceProvider` to:
    - Bind `PaymentQuoteRepositoryInterface` → `EloquentPaymentQuoteRepository`
    - Register singleton `QuoteService`
    - Register `DevPurposeStubResolver` for `dev_test_purpose` only when `app()->environment('local', 'development')`
    - Add `payment.quote_ttl_seconds` to Platform Config seeder

### Phase 5 — HTTP Layer

17. Create `PaymentQuoteController` with `show(Request $request, string $intentId)` — thin, delegates to repository lookup + ownership check
18. Update `routes/modules/payment.php` to add: `Route::get('/intents/{id}/quote', [PaymentQuoteController::class, 'show'])->middleware('identity.require-permission:payment.intents.read')`
19. Update `openapi/v1/payment.yaml`:
    - Add `PaymentQuoteResponse`, `FeeSnapshotObject`, `QuoteStaleContext` schemas
    - Add `GET /payment/intents/{id}/quote` path
    - Add `PURPOSE_NOT_PAYABLE`, `PURPOSE_NO_LONGER_PAYABLE`, `QUOTE_STALE_CONFIRM_REQUIRED`, `NO_ACTIVE_QUOTE` to ErrorCode enum
    - Add `quote` field to `PaymentIntentCreateResponse`

### Phase 6 — Tests

20. `EnrichedObligationValidationTest`: expired obligation rejected, ownership mismatch rejected, null expiry accepted
21. `QuoteArithmeticTest`: total = base + fee; zero fee accepted; invariant error on arithmetic mismatch
22. `FeeSnapshotRoundingTest`: floor rounding, round_half_up rounding, min fee applied, max fee cap applied
23. `QuoteStaleRevalidationTest`: fresh quote → proceed; stale same total → refresh; stale changed total → 409; stale purpose gone → cancel
24. `DevStubProductionGuardTest`: production env throws `ProductionBlockedException`; local env succeeds

### Phase 7 — Config and Seeding

25. Update `PlatformConfigurationSeeder` to add `payment.quote_ttl_seconds = 300`

### Phase 8 — PHPStan and OpenAPI (Docker-gated)

26. PHPStan level 8 on Payment module → 0 errors
27. Redocly lint on `openapi/v1/payment.yaml` → 0 errors
28. Docker quickstart SC-01–SC-03 pass

---

## Complexity Tracking

No constitution violations.

---

## Open Decisions

| ID | Decision | Status |
|----|----------|--------|
| D-P04-01 | beneficiary_allocations content schema | AWAITING_DOCUMENT: FD02 |
| D-P04-02 | hold_reference activation protocol | AWAITING_DOCUMENT: LC02 |
| D-P04-03 | FeeTransactionType enum value for 'payment' type | Verify existing enum or add new case |
