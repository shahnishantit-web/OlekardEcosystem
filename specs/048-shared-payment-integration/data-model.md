# Data Model: Shared Payment Integration and Orchestration (E48)

**Date**: 2026-10-18 | **Branch**: `048-shared-payment-integration`

All monetary amounts are **BIGINT in minor currency units** (e.g., 10000 = BWP 100.00). No DECIMAL, FLOAT, or DOUBLE columns exist in this schema.

---

## PostgreSQL Schema

```sql
CREATE SCHEMA IF NOT EXISTS payment;
```

---

## Tables

### `payment.payment_providers`

Registry of approved payment provider integrations.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | UUID | PK, default gen_random_uuid() | UUID v7 |
| `code` | VARCHAR(64) | UNIQUE NOT NULL | Machine identifier: `olekard_wallet`, `mock_bank`, `bank_1_slot` |
| `display_name` | VARCHAR(255) | NOT NULL | Human-readable: "OleKard Wallet", "Development Mock Bank" |
| `adapter_identifier` | VARCHAR(128) | NOT NULL | Class binding key: `olekard_wallet`, `mock_bank` |
| `adapter_version` | VARCHAR(32) | NOT NULL | Semver: `1.0.0` |
| `environment` | VARCHAR(32) | NOT NULL CHECK IN ('sandbox','production','mock') | |
| `is_enabled` | BOOLEAN | NOT NULL DEFAULT false | Admin-toggled |
| `is_suspended` | BOOLEAN | NOT NULL DEFAULT false | Operational suspension |
| `sort_order` | SMALLINT | NOT NULL DEFAULT 0 | Display order in checkout |
| `is_default` | BOOLEAN | NOT NULL DEFAULT false | Pre-select in checkout |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**: `(code)` UNIQUE, `(is_enabled, is_suspended, sort_order)`

---

### `payment.payment_provider_accounts`

Configured merchant/settlement account for a provider, scoped to organisations and currencies.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | UUID | PK | UUID v7 |
| `provider_id` | UUID | FK → payment_providers.id NOT NULL | |
| `merchant_account_reference` | VARCHAR(255) | NOT NULL | Provider-side merchant/settlement ID |
| `supported_currencies` | JSONB | NOT NULL DEFAULT '[]' | e.g., `["BWP","USD"]` |
| `supported_payment_methods` | JSONB | NOT NULL DEFAULT '[]' | e.g., `["wallet","redirect"]` |
| `min_amount_minor` | BIGINT | NOT NULL DEFAULT 100 | Minimum transaction in minor units |
| `max_amount_minor` | BIGINT | NOT NULL DEFAULT 100000000 | Maximum transaction in minor units |
| `enabled_organisations` | JSONB | NOT NULL DEFAULT '[]' | Empty = all organisations |
| `enabled_modules` | JSONB | NOT NULL DEFAULT '[]' | Empty = all modules |
| `country_restrictions` | JSONB | NOT NULL DEFAULT '[]' | ISO 3166-1 alpha-2; empty = all |
| `callback_url` | VARCHAR(500) | NULLABLE | Override callback URL if needed |
| `callback_secret_reference` | VARCHAR(500) | NULLABLE | Secret manager reference (NOT the secret itself) |
| `capability_flags` | JSONB | NOT NULL DEFAULT '{}' | `{"full_refund":true,"partial_refund":false,...}` |
| `fee_rule_ids` | JSONB | NOT NULL DEFAULT '[]' | References to E21 fee rule IDs |
| `daily_limit_minor` | BIGINT | NULLABLE | Configurable daily cap in minor units |
| `is_active` | BOOLEAN | NOT NULL DEFAULT true | |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**: `(provider_id)`, `(is_active)`

---

### `payment.payment_intents`

Represents a consumer's intention to pay for a specific purpose. Owns the overall payment lifecycle.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | UUID | PK | UUID v7 |
| `reference` | VARCHAR(32) | UNIQUE NOT NULL | Human-readable: `PAY-20261018-XXXX` |
| `purpose_type` | VARCHAR(128) | NOT NULL | e.g., `funeral_contribution`, `event_ticket`, `raffle_entry` |
| `purpose_reference` | UUID | NOT NULL | FK into the purpose module's primary entity |
| `organisation_id` | UUID | NOT NULL | Scoping tenant isolation |
| `payer_actor_id` | UUID | NOT NULL | FK → identity actors |
| `currency_code` | VARCHAR(3) | NOT NULL | ISO 4217 |
| `amount_minor` | BIGINT | NOT NULL CHECK > 0 | Server-resolved amount |
| `status` | VARCHAR(32) | NOT NULL CHECK IN enum | See PaymentIntentStatus |
| `idempotency_key` | VARCHAR(255) | NOT NULL | |
| `correlation_id` | UUID | NOT NULL | |
| `metadata` | JSONB | NOT NULL DEFAULT '{}' | Safe approved metadata from purpose module |
| `expires_at` | TIMESTAMPTZ | NOT NULL | Intent expiry |
| `succeeded_at` | TIMESTAMPTZ | NULLABLE | Set when status = succeeded |
| `failed_at` | TIMESTAMPTZ | NULLABLE | |
| `cancelled_at` | TIMESTAMPTZ | NULLABLE | |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**: `(organisation_id, status)`, `(payer_actor_id, status)`, `(purpose_type, purpose_reference)`, `(idempotency_key, payer_actor_id)` UNIQUE, `(reference)` UNIQUE, `(expires_at)` (for expiry job)

**Status enum** — `PaymentIntentStatus`:

| Value | Meaning |
|-------|---------|
| `pending` | Created, no attempt started |
| `processing` | Active attempt in progress |
| `awaiting_action` | Awaiting consumer action (redirect return) |
| `requires_confirmation` | Awaiting provider webhook confirmation |
| `succeeded` | Payment confirmed |
| `failed` | All attempts failed; terminal |
| `cancelled` | Cancelled by consumer or system |
| `expired` | Passed expires_at without success |
| `refunding` | Partial or full refund in progress |
| `partially_refunded` | Some funds returned, not all |
| `refunded` | Full amount returned |

**Valid intent transitions**:
```
pending → processing
pending → cancelled
pending → expired
processing → awaiting_action
processing → requires_confirmation
processing → succeeded
processing → failed
processing → cancelled
awaiting_action → requires_confirmation
awaiting_action → processing  (retry after returning)
awaiting_action → cancelled
awaiting_action → expired
requires_confirmation → succeeded
requires_confirmation → failed
succeeded → refunding
refunding → partially_refunded
refunding → refunded
partially_refunded → refunding  (additional refund)
partially_refunded → refunded
```

---

### `payment.payment_attempts`

One attempt to fulfil a `PaymentIntent` through a specific provider.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | UUID | PK | UUID v7 |
| `payment_intent_id` | UUID | FK → payment_intents.id NOT NULL | |
| `provider_code` | VARCHAR(64) | NOT NULL | e.g., `olekard_wallet`, `mock_bank` |
| `provider_account_id` | UUID | FK → payment_provider_accounts.id NOT NULL | |
| `status` | VARCHAR(32) | NOT NULL CHECK IN enum | See PaymentAttemptStatus |
| `idempotency_key` | VARCHAR(255) | UNIQUE NOT NULL | Per-attempt idempotency |
| `provider_reference_id` | VARCHAR(500) | NULLABLE | External provider's transaction ID |
| `wallet_hold_id` | UUID | NULLABLE | For wallet payments: hold ID from WalletModuleContract |
| `ledger_journal_id` | UUID | NULLABLE | Journal ID from LedgerModuleContract |
| `next_action_type` | VARCHAR(64) | NULLABLE | e.g., `redirect`, `app_switch`, `poll` |
| `next_action_data` | JSONB | NULLABLE | Safe data for consumer (redirect URL, etc.) |
| `fee_minor` | BIGINT | NOT NULL DEFAULT 0 | Fee applied on this attempt |
| `initiated_at` | TIMESTAMPTZ | NOT NULL | |
| `confirmed_at` | TIMESTAMPTZ | NULLABLE | |
| `failed_at` | TIMESTAMPTZ | NULLABLE | |
| `failure_reason` | VARCHAR(128) | NULLABLE | Normalised code: `insufficient_funds`, `provider_error`, etc. |
| `timeout_at` | TIMESTAMPTZ | NULLABLE | When timeout job should fire |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**: `(payment_intent_id, status)`, `(idempotency_key)` UNIQUE, `(provider_code, provider_reference_id)`, `(timeout_at)` WHERE status NOT IN terminal

**Status enum** — `PaymentAttemptStatus`:

| Value | Meaning |
|-------|---------|
| `initiated` | Adapter called; awaiting consumer action |
| `processing` | Consumer completed action; awaiting provider confirmation |
| `awaiting_action` | Consumer must complete redirect/app-switch |
| `succeeded` | Provider confirmed payment |
| `failed` | Provider confirmed failure |
| `cancelled` | Cancelled explicitly |
| `expired` | Timed out before confirmation |
| `timed_out` | Timeout job fired; status re-query attempted |

**Terminal states**: `succeeded`, `failed`, `cancelled`, `expired`

---

### `payment.payment_refunds`

A request to return funds for a succeeded attempt.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | UUID | PK | UUID v7 |
| `payment_intent_id` | UUID | FK → payment_intents.id NOT NULL | |
| `payment_attempt_id` | UUID | FK → payment_attempts.id NOT NULL | The succeeded attempt being refunded |
| `amount_minor` | BIGINT | NOT NULL CHECK > 0 | Refund amount (≤ original minus prior confirmed) |
| `status` | VARCHAR(32) | NOT NULL CHECK IN enum | See PaymentRefundStatus |
| `idempotency_key` | VARCHAR(255) | UNIQUE NOT NULL | |
| `requested_by` | UUID | NOT NULL | Actor who requested |
| `approved_by` | UUID | NULLABLE | Actor who approved (if threshold exceeded) |
| `provider_refund_reference` | VARCHAR(500) | NULLABLE | External refund ID |
| `reason` | VARCHAR(500) | NULLABLE | Admin-supplied reason |
| `failure_reason` | VARCHAR(128) | NULLABLE | |
| `requested_at` | TIMESTAMPTZ | NOT NULL | |
| `confirmed_at` | TIMESTAMPTZ | NULLABLE | |
| `failed_at` | TIMESTAMPTZ | NULLABLE | |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**: `(payment_intent_id, status)`, `(payment_attempt_id)`, `(idempotency_key)` UNIQUE

**Status enum** — `PaymentRefundStatus`:

| Value | Meaning |
|-------|---------|
| `requested` | Submitted; pending approval check |
| `pending_approval` | Requires approver sign-off (above threshold) |
| `processing` | Sent to provider |
| `succeeded` | Provider confirmed |
| `failed` | Provider rejected |
| `cancelled` | Cancelled before processing |

---

### `payment.payment_callbacks`

Raw persisted provider webhook payloads. Immutable after creation.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | UUID | PK | UUID v7 |
| `provider_code` | VARCHAR(64) | NOT NULL | |
| `raw_body` | TEXT | NOT NULL | Raw HTTP request body bytes |
| `headers` | JSONB | NOT NULL DEFAULT '{}' | Forwarded HTTP headers |
| `signature_header` | VARCHAR(500) | NULLABLE | Value of signature header |
| `idempotency_key` | VARCHAR(500) | NULLABLE | Provider-supplied deduplication key |
| `status` | VARCHAR(32) | NOT NULL DEFAULT 'received' | `received`, `processing`, `processed`, `signature_invalid`, `duplicate`, `dead_lettered`, `failed` |
| `payment_attempt_id` | UUID | NULLABLE | Set after successful processing |
| `error_message` | TEXT | NULLABLE | Set on processing failure |
| `received_at` | TIMESTAMPTZ | NOT NULL | |
| `processed_at` | TIMESTAMPTZ | NULLABLE | |
| `created_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**: `(provider_code, idempotency_key)` UNIQUE WHERE idempotency_key IS NOT NULL, `(status)`, `(received_at)`

---

### `payment.reconciliation_exceptions`

Detected discrepancies between internal payment records and external provider data.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | UUID | PK | UUID v7 |
| `exception_type` | VARCHAR(64) | NOT NULL | `missing_callback`, `unknown_outcome`, `amount_mismatch`, `duplicate_transaction`, `orphan_provider`, `failed_refund`, `unmatched_ledger` |
| `payment_intent_id` | UUID | NULLABLE | FK if known |
| `payment_attempt_id` | UUID | NULLABLE | FK if known |
| `provider_code` | VARCHAR(64) | NOT NULL | |
| `provider_reference_id` | VARCHAR(500) | NULLABLE | |
| `detected_amount_minor` | BIGINT | NULLABLE | Amount seen from provider |
| `expected_amount_minor` | BIGINT | NULLABLE | Amount from internal record |
| `currency_code` | VARCHAR(3) | NULLABLE | |
| `description` | TEXT | NOT NULL | Human-readable summary |
| `status` | VARCHAR(32) | NOT NULL DEFAULT 'open' | `open`, `under_investigation`, `resolved`, `escalated` |
| `investigation_notes` | TEXT | NULLABLE | Admin-added notes |
| `resolved_by` | UUID | NULLABLE | |
| `resolved_at` | TIMESTAMPTZ | NULLABLE | |
| `resolution_reason` | VARCHAR(500) | NULLABLE | |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

**Indexes**: `(status, created_at)`, `(provider_code, provider_reference_id)`, `(payment_intent_id)`, `(payment_attempt_id)`

---

### `payment.bank_provider_documentation_gaps`

Tracks what documentation is required before a bank slot can be activated for live use.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | UUID | PK | UUID v7 |
| `provider_code` | VARCHAR(64) | UNIQUE NOT NULL | e.g., `bank_1_slot`, `bank_2_slot` |
| `bank_display_name` | VARCHAR(255) | NOT NULL | e.g., "Bank 1 (Placeholder)" |
| `required_items` | JSONB | NOT NULL | List of required documentation items |
| `received_items` | JSONB | NOT NULL DEFAULT '[]' | Items received |
| `status` | VARCHAR(32) | NOT NULL DEFAULT 'blocked' | `blocked`, `partial`, `complete` |
| `notes` | TEXT | NULLABLE | |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

---

## PHP Enums

### `PaymentIntentStatus` (backed: string)

```php
enum PaymentIntentStatus: string {
    case Pending = 'pending';
    case Processing = 'processing';
    case AwaitingAction = 'awaiting_action';
    case RequiresConfirmation = 'requires_confirmation';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunding = 'refunding';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function isTerminal(): bool { ... }
    public function canTransitionTo(self $target): bool { ... }
}
```

### `PaymentAttemptStatus` (backed: string)

```php
enum PaymentAttemptStatus: string {
    case Initiated = 'initiated';
    case Processing = 'processing';
    case AwaitingAction = 'awaiting_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case TimedOut = 'timed_out';

    public function isTerminal(): bool { ... }
}
```

### `PaymentRefundStatus` (backed: string)

```php
enum PaymentRefundStatus: string {
    case Requested = 'requested';
    case PendingApproval = 'pending_approval';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
```

### `ProviderCapabilityEnum` (backed: string)

```php
enum ProviderCapabilityEnum: string {
    case InitiatePayment = 'initiate_payment';
    case QueryPayment = 'query_payment';
    case VerifyCallback = 'verify_callback';
    case FullRefund = 'full_refund';
    case PartialRefund = 'partial_refund';
    case CancelPayment = 'cancel_payment';
    case AuthoriseCapture = 'authorise_capture';
    case Payout = 'payout';
}
```

---

## TypeScript Types (Admin)

```typescript
// lib/api/payment.ts

export type PaymentProviderEnvironment = 'sandbox' | 'production' | 'mock';

export interface PaymentProvider {
  id: string;
  code: string;
  displayName: string;
  adapterIdentifier: string;
  adapterVersion: string;
  environment: PaymentProviderEnvironment;
  isEnabled: boolean;
  isSuspended: boolean;
  sortOrder: number;
  isDefault: boolean;
  isMock: boolean;       // derived: environment === 'mock'
  createdAt: string;
  updatedAt: string;
}

export interface PaymentProviderAccount {
  id: string;
  providerId: string;
  merchantAccountReference: string;
  supportedCurrencies: string[];
  supportedPaymentMethods: string[];
  minAmountMinor: number;
  maxAmountMinor: number;
  enabledOrganisations: string[];
  enabledModules: string[];
  countryRestrictions: string[];
  callbackUrl: string | null;
  callbackSecretReference: string | null;   // reference only, never actual secret
  capabilityFlags: Record<string, boolean>;
  feeRuleIds: string[];
  dailyLimitMinor: number | null;
  isActive: boolean;
}

export type ReconciliationExceptionStatus = 'open' | 'under_investigation' | 'resolved' | 'escalated';

export interface ReconciliationException {
  id: string;
  exceptionType: string;
  paymentIntentId: string | null;
  providerCode: string;
  providerReferenceId: string | null;
  description: string;
  status: ReconciliationExceptionStatus;
  createdAt: string;
  resolvedAt: string | null;
}
```

---

## Dart Models (Flutter)

```dart
// domain/models/payment_intent.dart
enum PaymentIntentStatus {
  pending, processing, awaitingAction, requiresConfirmation,
  succeeded, failed, cancelled, expired,
  refunding, partiallyRefunded, refunded;

  bool get isTerminal => [
    PaymentIntentStatus.succeeded,
    PaymentIntentStatus.failed,
    PaymentIntentStatus.cancelled,
    PaymentIntentStatus.expired,
    PaymentIntentStatus.refunded,
  ].contains(this);
}

class PaymentIntent {
  final String id;
  final String reference;
  final PaymentIntentStatus status;
  final int amountMinor;
  final String currencyCode;
  final String? nextActionType;      // 'redirect' | 'app_switch' | 'poll' | null
  final Map<String, dynamic>? nextActionData;
  final DateTime expiresAt;
}

// domain/models/payment_option.dart
class PaymentOption {
  final String providerCode;
  final String displayName;
  final bool isWallet;
  final int? walletBalanceMinor;    // only for wallet option (server-validated)
  final bool isDefault;
  final List<String> supportedMethods;
}
```

---

## Entity Relationship Summary

```
PaymentProvider ─┬─< PaymentProviderAccount
                 │
PaymentIntent ──<│── PaymentAttempt ──── PaymentCallback
                 │        │
                 │        └─────────────── WalletHold (via WalletModuleContract)
                 │        └─────────────── LedgerJournal (via LedgerModuleContract)
                 │
                 └──<── PaymentRefund
                 └──<── ReconciliationException
```

---

## Seeding Requirements

### Provider Seed (migration-seeded for dev/test)

```php
// Seeded in: 2026_10_18_000002_create_payment_providers_table.php
// Dev-only: MockBank
[
    'code'               => 'mock_bank',
    'display_name'       => 'Development Mock Bank — NOT FOR PRODUCTION',
    'adapter_identifier' => 'mock_bank',
    'adapter_version'    => '1.0.0',
    'environment'        => 'mock',
    'is_enabled'         => false,  // requires admin to enable
]

// All envs: OleKard Wallet
[
    'code'               => 'olekard_wallet',
    'display_name'       => 'OleKard Wallet',
    'adapter_identifier' => 'olekard_wallet',
    'adapter_version'    => '1.0.0',
    'environment'        => 'production',
    'is_enabled'         => false,  // requires admin to configure and enable
]
```

### Permission Seed

```php
// Seeded in: 2026_10_18_000004_create_payment_intents_table.php
[
    'payment.intents.read',
    'payment.intents.manage',
    'payment.refunds.request',
    'payment.refunds.approve',
    'payment.providers.read',
    'payment.providers.manage',
    'payment.config.approve_high_risk',
    'payment.reconciliation.view',
    'payment.reconciliation.manage',
]
```
