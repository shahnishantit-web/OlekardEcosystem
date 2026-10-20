# Research — E21 Fees & Pricing

## Summary

No NEEDS CLARIFICATION items remain. All technical unknowns were resolved by reading the existing codebase (E07 LedgerModuleContract, E14 Commission module structure, E20 reconciliation config).

---

## Decision 1 — JournalEntryType::Fee is already live in E07

**Decision**: Use existing `JournalEntryType::Fee = 'fee'` and existing DB CHECK constraint — no migration required.

**Rationale**: The original `ledger.journals` table creation migration (`2026_09_17_001200_create_ledger_journals_table.php`) already includes `'fee'` in `journals_entry_type_check`:
```sql
CHECK (entry_type IN ('debit','credit','fee','revenue','commission','reversal'))
```
The PHP enum at `App\Modules\Ledger\Domain\Enums\JournalEntryType` already has `case Fee = 'fee'`. The `LedgerModuleContract::postEntry()` docblock explicitly lists `'fee'` as an accepted `entry_type`. E21 can post fee entries immediately without any E07 changes.

**Alternatives considered**: Writing a new `2026_09_28_021nnn_extend_ledger_entry_type_for_fee.php` migration (E20 pattern for `settlement`). Unnecessary since `fee` is already present.

---

## Decision 2 — Fee reversals use compensating postEntry calls (no contract extension)

**Decision**: Fee reversals post a compensating `entry_type = 'fee'` journal via the existing `LedgerModuleContract::postEntry()`, with debit/credit lines reversed from the original fee journal.

**Rationale**: The `JournalEntryType::Reversal = 'reversal'` case is documented as "Internal only — created by LedgerReverseService; not accepted via `postEntry()`." Adding a `reverseEntry()` method to `LedgerModuleContract` would be an E07 contract extension requiring cross-module coordination. The simpler and already-supported approach is a compensating entry: the original fee debits `wallet:{customerId}` and credits `revenue:fees:{currency}`; the compensating entry debits `revenue:fees:{currency}` and credits `wallet:{customerId}` with the same `entry_type = 'fee'`. Both debit=credit, both idempotency-keyed, no contract extension needed.

The spec's scenario 5 description (`entry_type = fee_reversal`) was aspirational — the implementation uses `entry_type = fee` with reversed line directions.

**Alternatives considered**: (1) Adding `reverseEntry(string $journalId)` to LedgerModuleContract — rejected as an unnecessary cross-module contract change. (2) Using `entry_type = 'reversal'` — rejected because that type is internal to LedgerReverseService.

---

## Decision 3 — Module name and namespace: App\Modules\Fees

**Decision**: Module lives at `App\Modules\Fees\`, schema `fees`, routes at `routes/modules/fees.php`.

**Rationale**: Consistent with all existing modules (Commission, Reconciliation, Withdrawal, etc.). No `Fees` module directory exists yet — confirmed by `ls app/Modules/`. Module registers via `FeesServiceProvider` loaded from `app/Providers/ModuleServiceProviders.php` (following the pattern of `CommissionServiceProvider`).

---

## Decision 4 — Fee rule resolution precedence

**Decision**: Agent-specific > Customer-specific > Global. Lookup order: first query for (transaction_type, currency, subject_type='agent', subject_id=agent_id) where status='active'; if none, query for (transaction_type, currency, subject_type='customer', subject_id=customer_id) where status='active'; if none, query for (transaction_type, currency, subject_type='global') where status='active'. If none matches, fee_amount = 0.

**Rationale**: The spec establishes that customer-specific and agent-specific rules "take precedence over the global rule." Three targeted queries (each hitting the partial unique index) is acceptable — the index `WHERE status = 'active'` makes all three lookups efficient. The `FeeCalculationService` encapsulates this resolution logic so callers never implement it.

**Alternatives considered**: Storing a priority integer on each rule — rejected as unnecessary complexity given the three fixed subject_type levels.

---

## Decision 5 — E20 `default_fee_rate` deprecation strategy

**Decision**: Leave `config('reconciliation.default_fee_rate')` in place with a deprecation comment. The reconciliation module's `NullSettlementAdapter` (current default) does not use this value. When E21 is deployed, the seeder creates a `fee_rules` record with `transaction_type = 'settlement'`, `fee_type = 'fixed'`, `amount_minor = 0` as the bootstrapping default (matching the current `'0'` value). Any future real settlement adapter reads from FeeService instead of the PHP config.

**Rationale**: The current settlement adapter is `NullSettlementAdapter` — it performs no real settlement and ignores the fee rate. Removing the PHP config would be safe but is low-risk to leave with a `@deprecated` comment. The seeder-based migration is the minimum viable path.

---

## Decision 6 — FeeCalculationResult as a value object

**Decision**: `FeeCalculationResult` is a PHP readonly class (value object) returned by `FeeCalculationService::calculate()` and by `FeesModuleContract::calculateFee()`.

Fields:
- `int $feeAmountMinor`
- `string|null $feeRuleId`
- `string $feeType` ('fixed'|'percentage')
- `bool $hasApplicableRule`

**Rationale**: Using a typed value object prevents callers from pattern-matching on array keys. Consistent with E14's approach for commission calculation results. Serialized to JSON for `POST /v1/fees/calculate` response.

---

## Decision 7 — FeesModuleContract interface for cross-module callers

**Decision**: E21 exposes `FeesModuleContract` at `App\Modules\Fees\Domain\Contracts\FeesModuleContract` with three methods:

```php
public function calculateFee(
    string $transactionType,
    int    $amountMinor,
    string $currency,
    string $subjectType,
    ?string $subjectId
): FeeCalculationResult;

public function recordFee(
    string $transactionId,
    int    $amountMinor,
    string $feeRuleId,
    string $currency,
    string $idempotencyKey,
    string $correlationId
): string;  // returns Fee UUID

public function reverseFee(
    string $transactionId,
    string $idempotencyKey,
    string $correlationId
): void;
```

**Rationale**: Consuming modules (E08, E09, E10, E15, E16) must not reference Fees Eloquent models directly (same principle as E07's LedgerModuleContract boundary). The contract is bound in `FeesServiceProvider` via `$app->bind(FeesModuleContract::class, FeeServiceImplementation::class)`.

---

## Decision 8 — `fees.transaction_type` enum values

**Decision**: `transaction_type` maps to the `TransactionType` enum values from E11:
`transfer | funding | withdrawal | bill_payment | qr_payment | cash_in | cash_out | settlement`

**Rationale**: Checked `App\Modules\Transaction\Domain\Enums\TransactionType` (E11 module). E11 defines these values. E21 should use the same strings for alignment. The DB CHECK constraint uses string literals matching these values.

---

## Decision 9 — Idempotency storage for fee rule mutations

**Decision**: Use the platform-wide `idempotency_keys` table (E04/E08 pattern) rather than a fees-specific store. `POST /v1/fees/rules` and `PATCH /v1/fees/rules/{id}` check the `Idempotency-Key` header via the `idempotency` middleware already registered on the `platform` DB connection.

**Rationale**: Same pattern used in E08 (FundingTransaction), E09 (Transfer), E10 (Withdrawal). No new infrastructure needed.

---

## Decision 10 — PricingVersion is optional / organizational

**Decision**: `PricingVersion` is an optional organizational grouping for fee rules. `fee_rules.pricing_version_id` is nullable. Rules can be created independently of a pricing version. The admin UI may surface pricing versions as "named fee schedules," but the fee calculation engine ignores the version when resolving which rule to apply (it uses `status = 'active'` and `valid_from/valid_to` directly).

**Rationale**: Forcing all rules to belong to a version would add unnecessary friction for routine fee updates. Version grouping is an audit/rollback convenience feature, not a computation input.
