# Research: P02 — Payment Schema and API Contracts

**Date**: 2026-10-21

**Method**: Repository code inspection of E48 Payment module

---

## Finding R01: Migration Location and Pattern

**Decision**: New P02 migrations go in `app/Modules/Payment/Infrastructure/Migrations/` (not global `database/migrations/`)

**Evidence**: All 8 E48 migrations are in that directory. They use `protected $connection = 'payment'` and `Schema::connection('payment')->create/table(...)` for Blueprint operations and `DB::connection('payment')->statement(...)` for raw SQL (constraints, indices).

**Naming convention**: `YYYY_MM_DD_NNNNNN_<description>.php` — P02 migrations use `2026_10_21_*` timestamp prefix. Numbers: P02 adds 3 migration files:
- `2026_10_21_000001_add_multi_state_to_payment_intents.php`
- `2026_10_21_000002_add_fee_snapshot_and_version_to_payment_intents.php`
- `2026_10_21_000003_create_payment_allocations_table.php`

**Rollback pattern**: All E48 `down()` methods use `Schema::connection('payment')->dropIfExists(...)`. For additive column migrations, `down()` uses `Schema::connection('payment')->table(..., fn($t) => $t->dropColumn([...]))`.

---

## Finding R02: PHP Enum Pattern for New Status Columns

**Decision**: Create two new PHP 8.1 backed string enums: `PaymentAccountingStatus` and `PaymentFulfilmentStatus`

**Evidence**: `PaymentIntentStatus.php` pattern:
- `namespace App\Modules\Payment\Domain\Enums`
- `enum PaymentIntentStatus: string`
- Each case has a string value matching the database CHECK constraint value
- Methods: `isTerminal(): bool`, `canTransitionTo(self $target): bool`

**New enums required**:
```
PaymentAccountingStatus: pending_ledger | ledger_posted | ledger_failed
PaymentFulfilmentStatus: pending | fulfilled | fulfilment_failed
PaymentAllocationStatus: allocated | settled | refunded | cancelled
```

Each enum needs `isTerminal()` and `canTransitionTo()` methods following the same pattern.

---

## Finding R03: PaymentIntentModel — Missing Fields to Add

**Decision**: Update `PaymentIntentModel.$fillable` and `casts()` to include 5 new columns

**Evidence**: Current `PaymentIntentModel.php` `$fillable` contains:
`id, reference, purpose_type, purpose_reference, organisation_id, payer_actor_id, currency_code, amount_minor, status, idempotency_key, correlation_id, metadata, expires_at, succeeded_at, failed_at, cancelled_at`

**Missing from $fillable** (P02 adds):
- `accounting_status` → cast: `PaymentAccountingStatus::class`
- `fulfilment_status` → cast: `PaymentFulfilmentStatus::class`
- `version` → cast: `'integer'`
- `fee_quote_minor` → cast: `'integer'`
- `fee_quote_provider_code` → no cast (string)

---

## Finding R04: New PaymentAllocationModel Required

**Decision**: Create `PaymentAllocationModel` following the same Eloquent pattern as other Payment models

**Evidence**: No `PaymentAllocationModel.php` exists in `Infrastructure/Models/`. Pattern from `PaymentIntentModel`:
- Final class, `$connection = 'payment'`, `$table = 'payment.payment_allocations'`
- `$keyType = 'string'`, `$incrementing = false`
- Explicit `$fillable` and `casts()` method
- `belongsTo(PaymentIntentModel::class)` relation

---

## Finding R05: OpenAPI Current State — Gaps

**Decision**: Extend `openapi/v1/payment.yaml` in-place (additive); create new `openapi/v1/payment-events.yaml`

**Evidence from reading payment.yaml (lines 1–250)**:
- `NextAction` schema currently has 3 types: `redirect, app_switch, sdk_init` (flat object, not discriminated union)
- `PaymentIntentResponse` has `status` but NO `accounting_status`, `fulfilment_status`, `fee_quote_minor`
- No `PaginatedMeta` or `PaginatedLinks` components defined
- No `ErrorCode` enum defined (individual endpoints use inline error descriptions)
- No event schemas

**Required extensions**:
1. Replace flat `NextAction` with discriminated union using `oneOf` + `discriminator.propertyName: type`
2. Add 4 new next_action types: otp_verification, polling_required, wallet_step_up (keep redirect + app_switch; add missing ones from domain enum)
3. Add `PaginatedMeta` and `PaginatedLinks` components
4. Add `ErrorCode` enum component
5. Add `accounting_status` and `fulfilment_status` to `PaymentIntentResponse`
6. Create `payment-events.yaml` with event payload schemas

---

## Finding R06: Domain Next Action Types

**Decision**: The 4 next_action types to document in OpenAPI are: `redirect`, `otp_verification`, `polling_required`, `wallet_step_up`

**Evidence**: `app_switch` and `sdk_init` in current payment.yaml don't correspond to any type used by `WalletPaymentAdapter` or `BankCheckoutFlow.php`. The domain's `next_action_type` field (varchar(64) on payment_attempts) accepts any string. The 4 types above are the ones actually returned by current adapters/controllers:
- `redirect` — bank adapters return a redirect URL
- `wallet_step_up` — WalletPaymentAdapter returns this when step-up auth is required
- `otp_verification` — for future mobile-OTP flows (documented as supported type)
- `polling_required` — when the result is async and the client must poll

---

## Finding R07: PHPStan Level 8 Constraints

**Decision**: New enum classes and models must be fully typed. Return types, property types, constructor parameters all required.

**Evidence**: E48 modules pass PHPStan level 8. `PaymentIntentStatus` has typed return values (`bool`, `array<self>`). `PayableObligation` is `final readonly class` with typed constructor.

**Requirements for new code**:
- New enum files: typed method signatures
- New model: typed `casts()` return `array<string, string|class-string>`
- Any repository interface additions: typed parameters and returns

---

## Finding R08: Additive Migration Safety

**Decision**: Use `Schema::connection('payment')->table(...)` with `$table->string()/bigInteger()` for additive columns; new columns must have defaults or be nullable

**Evidence**: E48 migrations use `notNullable()` with `->default(...)` for all columns that shouldn't be null. New columns on payment_intents must have defaults to be safe on live data:
- `accounting_status` → default `'pending_ledger'`
- `fulfilment_status` → default `'pending'`
- `version` → default `1`
- `fee_quote_minor` → default `0`
- `fee_quote_provider_code` → nullable

PostgreSQL `ALTER TABLE ... ADD COLUMN ... DEFAULT ...` with a constant default is instantaneous (no rewrite) even on large tables — this is safe for live data.

---

## Resolved Unknowns

| Unknown | Resolved Decision |
|---------|-------------------|
| Migration location | `app/Modules/Payment/Infrastructure/Migrations/` |
| Enum pattern | PHP 8.1 backed string enum in `Domain/Enums/` |
| Model cast pattern | `'column' => EnumClass::class` in `casts()` method |
| OpenAPI file | `openapi/v1/payment.yaml` — extend in-place |
| Event schemas | New file: `openapi/v1/payment-events.yaml` |
| next_action types | 4 types: redirect, otp_verification, polling_required, wallet_step_up |
| Additive migration safety | DEFAULT constant on new columns — instantaneous on PostgreSQL |
