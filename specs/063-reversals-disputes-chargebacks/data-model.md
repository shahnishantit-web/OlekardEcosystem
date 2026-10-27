# Data Model: P16 — Reversals, Disputes and Chargebacks

**Date**: 2026-10-24
**DB Connection**: `payment` (schema: `payment`)
**UUID strategy**: UUID v7 (ordered)

---

## New Tables

### `payment.payment_dispute_cases`

Primary case record for provider-originated reversals, disputes, and chargebacks.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | uuid | NO | gen_random_uuid() | UUID v7 |
| payment_attempt_id | uuid | NO | — | FK → payment.payment_attempts.id |
| provider_code | varchar(64) | NO | — | Provider that originated the event |
| provider_dispute_reference | varchar(255) | NO | — | UNIQUE — deduplication anchor |
| case_type | varchar(20) | NO | — | CHECK IN ('reversal','dispute','chargeback') |
| status | varchar(30) | NO | 'received' | CHECK (see state machine below) |
| disputed_amount_minor | bigint | NO | — | Full disputed amount in minor units |
| currency_code | char(3) | NO | — | ISO 4217 |
| reason_code | varchar(64) | YES | NULL | Provider reason code |
| response_deadline | timestamptz | YES | NULL | From provider event |
| net_exposure_minor | bigint | NO | 0 | disputed_amount_minor minus prior confirmed refunds |
| prior_refunds_minor | bigint | NO | 0 | Sum of confirmed refunds at time of exposure assessment |
| exposure_type | varchar(30) | YES | NULL | 'hold' or 'settled_funds_receivable' |
| hold_amount_minor | bigint | NO | 0 | Amount of authorised hold |
| entitlement_action | varchar(30) | NO | 'pending' | CHECK (see entitlement actions below) |
| liability_exception_type | varchar(64) | YES | NULL | 'post_draw_raffle','admitted_event_ticket', etc. |
| requires_finance_review | boolean | NO | false | Gate before closure for liability exceptions |
| finance_reviewed | boolean | NO | false | Set by finance operator |
| finance_reviewed_by | uuid | YES | NULL | Actor ID |
| finance_reviewed_at | timestamptz | YES | NULL | |
| evidence_submitted | boolean | NO | false | Set when first evidence reference recorded |
| accounting_status | varchar(40) | NO | 'pending' | See accounting statuses below |
| chargeback_ledger_journal_id | varchar(128) | YES | NULL | Idempotency key used: dispute:{id}:chargeback |
| fee_ledger_journal_id | varchar(128) | YES | NULL | Idempotency key used: dispute:{id}:fee |
| provider_decision_reference | varchar(255) | YES | NULL | From outcome event |
| correlation_id | varchar(128) | NO | — | For tracing |
| closed_at | timestamptz | YES | NULL | |
| created_at | timestamptz | NO | now() | |
| updated_at | timestamptz | NO | now() | |

**Indexes**:
- UNIQUE: `payment_dispute_cases_provider_ref_idx` ON (provider_code, provider_dispute_reference)
- INDEX: `payment_dispute_cases_attempt_idx` ON (payment_attempt_id)
- INDEX: `payment_dispute_cases_status_deadline_idx` ON (status, response_deadline)
- PARTIAL INDEX: `payment_dispute_cases_open_idx` ON (status) WHERE status NOT IN ('won','lost','closed_reversed')

**CHECK constraints**:
- `status` IN ('received','under_review','won','lost','closed_reversed','investigation_required')
- `case_type` IN ('reversal','dispute','chargeback')
- `entitlement_action` IN ('pending','blocked','consumed','post_draw_liability','no_handler','coordination_failed')
- `accounting_status` IN ('pending','correcting_journal_posted','fee_posted','hold_released','receivable_recorded','pending_chargeback_fee_policy')

---

### `payment.dispute_case_events`

Append-only audit timeline. Never updated after insert.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | uuid | NO | gen_random_uuid() | UUID v7 |
| dispute_case_id | uuid | NO | — | FK → payment.payment_dispute_cases.id |
| event_type | varchar(64) | NO | — | See event types below |
| actor_id | uuid | YES | NULL | Admin/system actor |
| payload | jsonb | NO | '{}' | Event-specific data |
| occurred_at | timestamptz | NO | now() | |
| correlation_id | varchar(128) | NO | — | |

**Event types**: `case_received`, `duplicate_detected`, `exposure_assessed`, `hold_authorised`, `entitlement_blocked`, `entitlement_consumed`, `entitlement_no_action`, `entitlement_coordination_failed`, `evidence_submitted`, `outcome_won`, `outcome_lost`, `outcome_reversed`, `contradictory_outcome`, `finance_review_acknowledged`, `case_closed`

**Indexes**:
- INDEX: `dispute_case_events_case_idx` ON (dispute_case_id, occurred_at)

---

### `payment.dispute_holds`

Records authorised financial holds linked to a case.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| id | uuid | NO | gen_random_uuid() | UUID v7 |
| dispute_case_id | uuid | NO | — | FK → payment.payment_dispute_cases.id |
| hold_amount_minor | bigint | NO | — | |
| currency_code | char(3) | NO | — | |
| settled_funds_receivable | boolean | NO | false | True if funds already paid out |
| authorised_by | uuid | NO | — | Actor ID |
| authorised_at | timestamptz | NO | now() | |
| released_at | timestamptz | YES | NULL | Set on won/reversed outcome |

**Indexes**:
- UNIQUE: `dispute_holds_case_idx` ON (dispute_case_id) — one hold per case

---

## State Machine: PaymentDisputeCaseStatus

```
received
  ↓ (operator assesses exposure, authorises hold)
under_review
  ↓ (provider communicates outcome)
  ├── won  [TERMINAL]
  ├── lost  [TERMINAL]
  ├── closed_reversed  [TERMINAL]
  └── investigation_required  (contradictory outcome)
       ↓ (manual operator resolution)
       ├── won  [TERMINAL]
       ├── lost  [TERMINAL]
       └── closed_reversed  [TERMINAL]
```

**Valid transitions** (enforced by `canTransitionTo()`):

| From | To | Trigger |
|------|----|---------|
| received | under_review | Exposure assessed / hold authorised |
| under_review | won | Provider outcome = won |
| under_review | lost | Provider outcome = lost |
| under_review | closed_reversed | Provider outcome = reversed |
| under_review | investigation_required | Contradictory late outcome |
| investigation_required | won | Manual operator resolution |
| investigation_required | lost | Manual operator resolution |
| investigation_required | closed_reversed | Manual operator resolution |

**Terminal states**: `won`, `lost`, `closed_reversed`
**`isTerminal()`**: returns true for terminal states
**`allowsFinancialActions()`**: returns true for `received`, `under_review`, `investigation_required`

---

## New Enum: DisputeEntitlementAction

```php
enum DisputeEntitlementAction: string {
    case BlockUnused             = 'blocked';
    case ConsumedEvidenceRecorded = 'consumed';
    case PostDrawLiability       = 'post_draw_liability';
    case NoAction                = 'no_handler';
    case CoordinationFailed      = 'coordination_failed';
    case Pending                 = 'pending';
}
```

---

## New Enum: DisputeAccountingStatus

```php
enum DisputeAccountingStatus: string {
    case Pending                    = 'pending';
    case CorrectingJournalPosted    = 'correcting_journal_posted';
    case FeePosted                  = 'fee_posted';
    case HoldReleased               = 'hold_released';
    case ReceivableRecorded         = 'receivable_recorded';
    case PendingChargebackFeePolicy = 'pending_chargeback_fee_policy';
}
```

---

## New Enum: DisputeCaseType

```php
enum DisputeCaseType: string {
    case Reversal    = 'reversal';
    case Dispute     = 'dispute';
    case Chargeback  = 'chargeback';
}
```

---

## New Interface: DisputeEntitlementHandlerInterface

```php
interface DisputeEntitlementHandlerInterface {
    public function supportedPurposeType(): string;
    
    public function handleDisputeEntitlement(
        string $caseId,
        string $purposeReference,
        string $correlationId,
    ): DisputeEntitlementResult;
}
```

---

## New Value Object: DisputeEntitlementResult

```php
final readonly class DisputeEntitlementResult {
    public function __construct(
        public readonly DisputeEntitlementAction $action,
        public readonly ?string                  $evidenceReference = null,
        public readonly ?string                  $notes             = null,
    ) {}
}
```

---

## ReconciliationExceptionType Extensions

Add to existing enum:
```php
case UnmatchedDisputeEvent = 'unmatched_dispute_event';
```

---

## Relationships

```
PaymentAttempt (1) ──< (0..n) PaymentDisputeCase
PaymentDisputeCase (1) ──< (0..n) DisputeCaseEvent  [append-only]
PaymentDisputeCase (1) ──> (0..1) DisputeHold
```

---

## Migration Files (new)

1. `2026_10_24_000011_create_payment_dispute_cases_table.php`
2. `2026_10_24_000012_create_dispute_case_events_table.php`
3. `2026_10_24_000013_create_dispute_holds_table.php`
