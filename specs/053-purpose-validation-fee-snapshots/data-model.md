# Data Model: P04 — Purpose Validation, Quotes and Fee Snapshots

**Feature**: [spec.md](spec.md)
**Date**: 2026-10-21

---

## Entities

### EnrichedPayableObligation (Value Object — no DB table)

Extension of `PayableObligation`. Created by domain resolvers, stored as a JSONB snapshot inside `PaymentQuote.obligation_snapshot`. Never mutable after creation.

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| amountMinor | int | Yes | Must be > 0. Server-authoritative. |
| currencyCode | string(3) | Yes | ISO 4217 |
| organisationId | UUID v7 | Yes | |
| payerActorId | UUID v7 | Yes | |
| expiresAt | DateTimeImmutable\|null | No | null = no expiry |
| holdReference | string\|null | No | AWAITING_DOCUMENT:LC02; P05 activates |
| beneficiaryAllocations | array | No | AWAITING_DOCUMENT:FD02; opaque JSONB |
| metadata | array | No | Passthrough from resolver |

**Validation rules**:
- If `expiresAt` is set and is in the past → `PurposeNotPayableException` with reason `expired`
- `payerActorId` must match authenticated actor → `PurposeNotPayableException` with reason `ownership_mismatch`

---

### PaymentQuote (DB table: `payment.payment_quotes`)

Immutable, version-stamped record. One row per quote version. Rows are never updated.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | UUID v7 | No | — | PK |
| payment_intent_id | UUID v7 | No | — | FK → payment.payment_intents.id |
| version | bigint | No | 1 | Increments with each refresh/supersession |
| quote_status | varchar(32) | No | 'active' | See QuoteStatus enum |
| base_amount_minor | bigint | No | — | From obligation. CHECK > 0 |
| fee_amount_minor | bigint | No | — | From fee computation. CHECK >= 0 |
| total_amount_minor | bigint | No | — | = base + fee. CHECK = base + fee enforced at service layer |
| currency_code | varchar(3) | No | — | |
| provider_code | varchar(64) | No | — | Provider option selected |
| rounding_mode | varchar(32) | No | — | `floor` or `round_half_up` |
| fee_rule_snapshot | jsonb | No | `'{}'` | Self-contained fee rule copy (see FeeRuleSnapshot below) |
| obligation_snapshot | jsonb | No | `'{}'` | Copy of EnrichedPayableObligation (excluding hold_reference) |
| expires_at | timestampTz | No | — | now() + payment.quote_ttl_seconds |
| superseded_at | timestampTz | Null | — | Set when this version is superseded |
| consumed_at | timestampTz | Null | — | Set when a payment attempt is initiated |
| cancelled_at | timestampTz | Null | — | Set when parent intent is cancelled |
| created_at | timestampTz | No | now() | |

**Indexes**:
- `payment_quotes_intent_status_idx` ON (payment_intent_id, quote_status)
- `payment_quotes_expires_idx` ON (expires_at) WHERE quote_status = 'active'

**Constraints**:
- `chk_quote_total` CHECK (total_amount_minor = base_amount_minor + fee_amount_minor) — enforced at service layer (DB CHECK is advisory)
- `chk_quote_amounts` CHECK (base_amount_minor > 0 AND fee_amount_minor >= 0 AND total_amount_minor > 0)

---

### FeeRuleSnapshot (embedded JSONB inside PaymentQuote.fee_rule_snapshot)

Not a DB table — a typed JSONB object stored inside `PaymentQuote.fee_rule_snapshot`. Self-contained; no FK to live fee rules.

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| rule_id | UUID\|null | No | null when no E21 rule matches (zero fee) |
| rule_name | string\|null | No | |
| fee_type | string | Yes | `percentage` or `flat` |
| rate_basis_points | int\|null | No | Non-null for percentage type |
| flat_amount_minor | int\|null | No | Non-null for flat type |
| minimum_fee_minor | int | Yes | 0 when no minimum |
| maximum_fee_minor | int\|null | No | null when no cap |
| rounding_mode | string | Yes | `floor` or `round_half_up` |
| effective_date | date string | Yes | From fee rule |
| computed_fee_minor | int | Yes | Final fee after min/max clamping and rounding |
| min_fee_applied | bool | Yes | Whether minimum was applied |
| max_fee_applied | bool | Yes | Whether maximum cap was applied |
| computation_inputs | object | Yes | `{amount_minor, currency_code, transaction_type, subject_type}` |

---

### payment_intents (existing table — new column added by P04 migration)

One new column added:

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| active_quote_id | UUID v7 | Null | FK → payment.payment_quotes.id; updated on each new quote version |

The existing columns `fee_quote_minor`, `fee_quote_provider_code`, and `version` (from P02) are retained as denormalized cache fields.

---

### QuoteStatus (PHP Enum)

```
Active        — quote is live; consumer can proceed
Expired       — TTL elapsed; re-resolution required before proceeding
Superseded    — a newer version has been created; this row is historical
Consumed      — a payment attempt was initiated using this quote
Cancelled     — parent intent cancelled; quote void
```

**Terminal cases**: `Superseded`, `Consumed`, `Cancelled`

**Transitions**:
- `Active` → `Expired` (system clock check on read / revalidation)
- `Active` → `Superseded` (when a newer version is created)
- `Active` or `Expired` → `Consumed` (when attempt is initiated)
- `Active` → `Cancelled` (when intent is cancelled)

---

## Sequence: Intent Creation with Quote

```
Consumer → createPaymentIntent(purpose_type, purpose_reference, provider_code, idempotency_key)
  → BusinessPurposeResolverRegistry::resolverFor(purpose_type)
  → resolver.resolve(purpose_reference, actor_id, correlation_id): EnrichedPayableObligation
  → validate: expiresAt not in past, payerActorId matches authenticated actor
  → FeesModuleContract::calculateFee('payment', amountMinor, currency, 'customer', actorId): FeeCalculationResult
  → read FeeRuleModel by feeRuleId to build FeeRuleSnapshot
  → compute total = base + fee, verify arithmetic
  → DB transaction:
      save PaymentIntentModel (amount_minor = obligation.amountMinor)
      save PaymentQuoteModel (version=1, status=active, ...)
      update intent.active_quote_id, intent.fee_quote_minor, intent.fee_quote_provider_code, intent.version
      audit: payment.intent.created + payment.quote.created
  → return intent shape with quote
```

## Sequence: Attempt Initiation with Stale-Quote Check

```
Consumer → initiateAttempt(payment_intent_id, provider_code, idempotency_key)
  → load intent (lock)
  → load active quote
  → if quote.expires_at < now():
      re-resolve obligation
      re-compute fee
      if total unchanged: refresh quote (new version, status=superseded on old, status=active on new)
      if total changed:
          mark old quote superseded
          create new active quote
          throw QuoteStaleConfirmRequiredException(newQuote)
  → if purpose no longer payable: cancel intent, throw PurposeNoLongerPayableException
  → mark quote as consumed
  → proceed with adapter call
  → audit: payment.quote.consumed
```

---

## Relationships

```
payment_intents  1 — N  payment_quotes        (one intent, many quote versions)
payment_intents  N — 1  payment_quotes        (active_quote_id FK — current active version)
payment_quotes   is-embedded  FeeRuleSnapshot  (fee_rule_snapshot JSONB)
payment_quotes   is-embedded  ObligationSnapshot (obligation_snapshot JSONB)
```
