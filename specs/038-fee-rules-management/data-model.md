# Data Model: ADM-P1-014 — Fee Rules Management

> **Note**: No new database tables or migrations are created by this frontend epic. All data is owned by the E21 backend. This document captures the TypeScript interfaces used by the frontend.

## Frontend Entities

### FeeRule

Fetched from `GET /v1/fees/rules` (list) and `GET /v1/fees/rules/{id}` (detail).

```typescript
interface FeeRule {
  id: string;                            // UUID v7
  transaction_type: TransactionType;
  fee_type: 'fixed' | 'percentage';
  rate: number | null;                   // percentage rules; e.g. 0.5 for 0.5%
  amount_minor: number | null;           // fixed rules; integer minor units
  min_fee_minor: number | null;          // percentage rules only; nullable
  max_fee_minor: number | null;          // percentage rules only; nullable
  currency: string;                      // ISO 4217; e.g. "BWP"
  subject_type: 'global' | 'customer' | 'agent';
  subject_id: string | null;             // null when subject_type = global
  valid_from: string;                    // ISO 8601 datetime
  valid_to: string | null;               // ISO 8601 datetime; null = open-ended
  status: FeeRuleStatus;
  pricing_version_id: string | null;
  created_by: string;                    // UUID of creating admin
  created_at: string;
  updated_at: string;
}

type TransactionType =
  | 'transfer' | 'funding' | 'withdrawal' | 'bill_payment'
  | 'qr_payment' | 'cash_in' | 'cash_out' | 'settlement';

type FeeRuleStatus = 'active' | 'expired' | 'deactivated' | 'superseded';
```

### FeeCalculationResult

Returned by `POST /v1/fees/calculate`. Transient — never persisted on the frontend.

```typescript
interface FeeCalculationResult {
  fee_amount_minor: number;        // 0 if no applicable rule
  fee_type: 'fixed' | 'percentage' | null;
  rule_id: string | null;          // which rule was applied; null if none
  has_applicable_rule: boolean;
  min_fee_applied: boolean;        // true if min floor was used
  max_fee_applied: boolean;        // true if max ceiling was used
}
```

### CalculateFeeRequest

Sent to `POST /v1/fees/calculate`.

```typescript
interface CalculateFeeRequest {
  transaction_type: TransactionType;
  amount_minor: number;     // integer; minimum 1
  currency: string;         // defaults to "BWP"
  subject_type: 'global' | 'customer' | 'agent';
  subject_id?: string;      // required when subject_type != global
}
```

### CreateFeeRulePayload

Sent to `POST /v1/fees/rules` (already implemented; included for completeness).

```typescript
interface CreateFeeRulePayload {
  transaction_type: TransactionType;
  fee_type: 'fixed' | 'percentage';
  rate?: number;              // percentage rules
  amount_minor?: number;      // fixed rules
  min_fee_minor?: number;
  max_fee_minor?: number;
  currency: string;
  subject_type: 'global' | 'customer' | 'agent';
  subject_id?: string;
  valid_from: string;
  valid_to?: string;
  pricing_version_id?: string;
}
```

## State Transitions

```
FeeRule.status:

  (created)
      │
      ▼
   active ──── DELETE /v1/fees/rules/{id} ──────► deactivated
      │
      └─── PATCH valid_to = past datetime ──────► expired
      
   superseded  (set by backend when a new active rule replaces this one)
```

**UI rules**:
- Show "Deactivate" button only when `status === 'active'` AND user has `fees.rules.delete` permission
- Show read-only "Deactivated / Expired" state for all other statuses
- Simulation panel available for any status (simulates against the **currently active** rule, not this specific rule)
