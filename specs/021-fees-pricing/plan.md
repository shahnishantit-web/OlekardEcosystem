# Implementation Plan — E21 Fees & Pricing

## Constitution Check
- [x] Approved Laravel/Next.js/Flutter stack respected — no deviations; Laravel modular monolith pattern
- [x] No TBD value invented — all fee amounts are admin-configurable; seeder bootstraps a default-zero settlement rule with a comment
- [x] Authorization remains server-side — `fees.manage` permission enforced on all write endpoints; `fees.calculate` on preview; `identity.require-permission` middleware
- [x] Financial integrity preserved — US-FEE-009 uses `LedgerModuleContract::postEntry(entry_type='fee')`; fee posting atomic with parent transaction; UNIQUE on `fees.transaction_id`
- [x] Security/audit requirements incorporated — `FeeRuleCreated`, `FeeRuleDeactivated`, `FeeRuleUpdated` audit events emitted; fee rule mutations audit-logged
- [x] UX/design-system requirements incorporated — fee preview API (US-FEE-008) is the UX touchpoint; consuming modules display the result using the shared design system
- [x] QA strategy incorporated — 12 quickstart scenarios; unit tests for FeeCalculationService (rounding, min/max, precedence); feature tests for all endpoints

## Technical Context
- Backend: Laravel / PHP 8.4+, modular monolith
- Module namespace: `App\Modules\Fees\`
- DB: `fees` schema on the `platform` PostgreSQL connection
- Queue/cache: Redis + Laravel Horizon (outbox for FeePosted/FeeReversed events)
- Web: Next.js + TypeScript (admin fee rule management UI)
- Mobile: Flutter (consumers of fee preview — calls existing transaction confirmation screens)
- API: REST/OpenAPI; routes at `routes/modules/fees.php`
- Testing: PHPUnit/Pest; unit tests for FeeCalculationService; feature tests for HTTP endpoints; quickstart integration tests via Docker

## Research Findings (see research.md for full decisions)

Key resolved decisions:
1. `JournalEntryType::Fee = 'fee'` already in E07 enum AND DB CHECK — no migration needed
2. Fee reversals use compensating `postEntry(entry_type='fee')` with reversed lines — no contract extension needed
3. Module: `App\Modules\Fees\`; schema: `fees`; routes: `routes/modules/fees.php`
4. Fee rule resolution precedence: agent-specific > customer-specific > global
5. `config('reconciliation.default_fee_rate')` left with `@deprecated` comment; seeder bootstraps settlement rule
6. `FeeCalculationResult` readonly value object returned by `FeeCalculationService`
7. `FeesModuleContract` exposes `calculateFee()`, `recordFee()`, `reverseFee()` to consuming modules
8. `transaction_type` enum values align with E11 TransactionType
9. Idempotency: platform-wide `idempotency_keys` table (same pattern as E08/E09/E10)
10. `PricingVersion` is optional organizational grouping; nullable FK on `fee_rules`

## Phase 0 — Research
**Status: COMPLETE** — See research.md. No NEEDS CLARIFICATION items remain.

## Phase 1 — Design
**Status: COMPLETE** — See data-model.md, contracts/, quickstart.md.

## Implementation Approach

### Module Structure

```
app/Modules/Fees/
├── Domain/
│   ├── Contracts/
│   │   └── FeesModuleContract.php
│   ├── Enums/
│   │   ├── FeeType.php              (fixed|percentage)
│   │   ├── FeeRuleStatus.php        (active|expired|deactivated|superseded)
│   │   ├── FeeSubjectType.php       (global|customer|agent)
│   │   ├── FeeTransactionType.php   (transfer|funding|withdrawal|bill_payment|qr_payment|cash_in|cash_out|settlement)
│   │   └── FeeStatus.php            (posted|reversed)
│   ├── Events/
│   │   ├── FeeRuleCreatedEvent.php
│   │   ├── FeeRuleDeactivatedEvent.php
│   │   ├── FeeRuleUpdatedEvent.php
│   │   ├── FeePostedEvent.php
│   │   └── FeeReversedEvent.php
│   ├── ValueObjects/
│   │   └── FeeCalculationResult.php
│   └── Exceptions/
│       ├── FeeRuleConflictException.php
│       ├── FeeRuleNotFoundException.php
│       └── InvalidFeeRateException.php
├── Application/
│   └── Services/
│       ├── FeeRuleService.php           (CRUD for FeeRule; handles versioning/supersession)
│       ├── FeeCalculationService.php    (rule resolution + fee computation)
│       └── FeeLedgerService.php         (recordFee, reverseFee via LedgerModuleContract)
├── Infrastructure/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── FeeRuleController.php    (index, store, show, update, destroy)
│   │   │   └── FeeCalculateController.php  (calculate)
│   │   ├── Requests/
│   │   │   ├── CreateFeeRuleRequest.php
│   │   │   ├── UpdateFeeRuleRequest.php
│   │   │   └── CalculateFeeRequest.php
│   │   └── Resources/
│   │       ├── FeeRuleResource.php
│   │       └── FeeCalculationResource.php
│   ├── Models/
│   │   ├── FeeRuleModel.php
│   │   ├── FeeModel.php
│   │   └── PricingVersionModel.php
│   └── Migrations/
│       ├── 2026_09_28_021000_create_fee_schema.php
│       ├── 2026_09_28_021100_create_fee_rules.php
│       ├── 2026_09_28_021200_create_fees.php
│       ├── 2026_09_28_021300_create_pricing_versions.php
│       └── 2026_09_28_021400_seed_fees_permissions.php
└── Providers/
    └── FeesServiceProvider.php
```

### Key Implementation Notes

**FeeCalculationService — rule resolution:**
1. If subject_type is 'agent' and subject_id given: lookup (transaction_type, currency, 'agent', subject_id) WHERE status='active'
2. If subject_type is 'customer' and subject_id given: lookup (transaction_type, currency, 'customer', subject_id) WHERE status='active'
3. Fallback: lookup (transaction_type, currency, 'global', NULL) WHERE status='active'
4. Apply valid_from/valid_to filter (rule must be effective at NOW())
5. Return FeeCalculationResult with fee_amount_minor, rule_id, fee_type, min/max applied flags

**FeeCalculationService — percentage calculation:**
```
raw_fee = (int) bcadd(bcdiv(bcmul((string)$amountMinor, (string)$rate, 10), '100', 10), '0.5', 0)  // bcmath half-up; avoids IEEE 754 float drift on large amounts
if min_fee_minor set: fee = max(raw_fee, min_fee_minor)
if max_fee_minor set: fee = min(fee, max_fee_minor)
```

**FeeLedgerService — recordFee:**
```
// Signature: recordFee(string $transactionId, int $amountMinor, string $feeRuleId, string $currency,
//                      string $debitAccountRef, string $creditAccountRef,
//                      string $idempotencyKey, string $correlationId): string
// Caller passes wallet:{customerId} as $debitAccountRef and revenue:fees:{currency} as $creditAccountRef.
// $idempotencyKey must be a UUID; callers derive: Uuid::uuid5(Uuid::NAMESPACE_URL, $transactionId.':fee')
1. Check fees.fees UNIQUE(transaction_id) → if exists, return existing fee ID (idempotent)
2. DB::transaction():
   a. $entry = postEntry(entry_type='fee', lines=[
        {account=$debitAccountRef, direction='debit', amount=$amountMinor},
        {account=$creditAccountRef, direction='credit', amount=$amountMinor}
      ], idempotencyKey=$idempotencyKey, correlationId=$correlationId)
   b. INSERT INTO fees.fees (transaction_id, fee_rule_id, amount_minor, ledger_entry_id=$entry->id, status='posted')
3. Dispatch FeePostedEvent (outbox) after transaction commit
```

**FeeLedgerService — reverseFee:**
```
// Signature: reverseFee(string $transactionId, string $debitAccountRef, string $creditAccountRef,
//                       string $idempotencyKey, string $correlationId): void
// Caller passes revenue:fees:{currency} as $debitAccountRef and wallet:{customerId} as $creditAccountRef.
// Account order is the inverse of recordFee — the reversal credits the wallet back.
1. Load Fee record by transaction_id
2. If already reversed: return (idempotent)
3. DB::transaction():
   a. $entry = postEntry(entry_type='fee', lines=[
        {account=$debitAccountRef, direction='debit', amount=$fee->amount_minor},
        {account=$creditAccountRef, direction='credit', amount=$fee->amount_minor}
      ], idempotencyKey=$idempotencyKey, correlationId=$correlationId)
   b. UPDATE fees.fees SET status='reversed', reversal_ledger_entry_id=$entry->id
4. Dispatch FeeReversedEvent (outbox) after transaction commit
```

**FeeRuleService — create rule (conflict guard):**
```
1. Within DB::transaction():
   a. lockForUpdate query: SELECT WHERE (transaction_type, currency, subject_type, subject_id) AND status='active'
   b. If exists: throw FeeRuleConflictException → 409
   c. INSERT new rule with status='active'
2. Dispatch FeeRuleCreatedEvent
```

**E20 reconciliation.default_fee_rate migration:**
- Add `@deprecated` comment to `config/reconciliation.php`
- Add seeder task in `2026_09_28_021400_seed_fees_permissions.php` to create `transaction_type='settlement'` rule
- The settlement fee amount is TBD business config; seeder creates `amount_minor=1` (minimum valid) as placeholder

## Data / Financial Controls

- Immutable `fees.fees` records (no updates to posted fees except status transition to 'reversed')
- UNIQUE constraint on `fees.fees.transaction_id` as idempotency guard for recordFee()
- Fee posting wrapped in `DB::transaction()` with parent transaction
- `lockForUpdate()` on FeeRule conflict check during create

## Observability

- Structured logs on all fee rule mutations and fee postings
- FeePosted/FeeReversed events via outbox for reliable delivery
- Correlation ID propagated from incoming request through to ledger posting

## Complexity Tracking

- No architecture deviation from approved stack; no ADR required
- FeesModuleContract is a new cross-module interface; registered in FeesServiceProvider
- E08/E09/E10/E15/E16 need to call FeesModuleContract — integration is additive (no breaking changes)
