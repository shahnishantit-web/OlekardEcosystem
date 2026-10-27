# Data Model: P22 — Shared Flutter Checkout and Recovery

**Date**: 2026-10-26

---

## Domain Entities

### PaymentCheckoutRequest (record type — unchanged from existing)

Input to the checkout entry point. The calling feature provides a payable reference and estimated context; it does NOT provide the authoritative amount (server resolves that).

| Field | Type | Description |
|-------|------|-------------|
| `purposeType` | `String` | Category of the payable (e.g., `bill_payment`, `transfer`, `top_up`) |
| `purposeReference` | `String` | Server-issued reference to the thing being paid for |
| `currencyCode` | `String` | ISO 4217 code (e.g., `BWP`) |
| `estimatedAmountMinor` | `int` | Hint for server option-filtering only; NOT used as charge amount |
| `returnRoute` | `String?` | Go Router route name for post-checkout navigation (optional) |

---

### PaymentOption (from server — extends existing model)

Server-returned available payment method for the current context. All fields are server-authoritative.

| Field | Type | Description |
|-------|------|-------------|
| `providerCode` | `String` | Unique provider identifier |
| `displayName` | `String` | Provider display name |
| `displayLabel` | `String?` | Short label (overrides displayName if present) |
| `iconUrl` | `String?` | Provider logo URL |
| `isDefault` | `bool` | Whether this is the recommended option |
| `isWallet` | `bool` | Whether this is the internal Wallet option |
| `walletBalanceMinor` | `int?` | Current wallet balance (Wallet option only) |
| `hasSufficientBalance` | `bool?` | Server-computed balance sufficiency flag |
| `minAmountMessage` | `String?` | Human-readable minimum amount restriction |
| `supportedCurrencies` | `List<String>` | Currencies this provider accepts |
| `paymentMethod` | `String?` | Underlying method type |
| `feeDisclosure` | `FeeDisclosure?` | Fee breakdown |
| `supportedNextAction` | `String?` | Expected next action type for this provider |
| `restrictions` | `List<String>` | Restriction reasons (non-empty = option unavailable) |

**Derived getters**:
- `effectiveLabel` → `displayLabel ?? displayName`
- `isRestricted` → `restrictions.isNotEmpty`

---

### FeeDisclosure

Fee breakdown for a PaymentOption. All values in minor currency units (e.g., Thebe for BWP).

| Field | Type | Description |
|-------|------|-------------|
| `serviceFeeMinor` | `int?` | OleKard service fee |
| `providerFeeMinor` | `int?` | Provider pass-through fee |
| `totalFeeMinor` | `int?` | Total fee (service + provider) |
| `totalPayableMinor` | `int?` | Total charged amount (base + fee) |

---

### PaymentIntent (from server — unchanged)

Server-created authoritative record. Created once per checkout; immutable after creation.

| Field | Type | Description |
|-------|------|-------------|
| `id` | `String` | UUID v7 intent identifier |
| `purposeType` | `String` | |
| `purposeReference` | `String` | |
| `organisationId` | `String` | |
| `payerActorId` | `String` | |
| `currencyCode` | `String` | |
| `amountMinor` | `int` | **Authoritative** charge amount (server-resolved) |
| `status` | `PaymentIntentStatus` | Current intent status |
| `idempotencyKey` | `String` | Key used for deduplication |
| `correlationId` | `String` | Trace correlation ID |
| `createdAt` | `DateTime` | UTC |
| `expiresAt` | `DateTime?` | UTC; null if no expiry |

---

### PaymentIntentStatus (enum — extended)

Mirrors server-side status values. Added `fulfilling` for P22 recovery state mapping.

```
pending → processing → awaitingAction | requiresConfirmation → succeeded
                                     → fulfilling → succeeded
                                     → failed
                                     → cancelled
                                     → expired
```

| Value | Terminal | Recovery Mapping |
|-------|----------|-----------------|
| `pending` | No | RecoveringPending |
| `processing` | No | RecoveringPending |
| `awaitingAction` | No | RecoveringUnknown |
| `requiresConfirmation` | No | RecoveringUnknown |
| `fulfilling` | No | RecoveringFulfilling |
| `succeeded` | **Yes** | — |
| `failed` | **Yes** | — |
| `cancelled` | **Yes** | — |
| `expired` | **Yes** | — |
| `refunding` | No | — (post-terminal, not checkout concern) |
| `partiallyRefunded` | No | — |
| `refunded` | Yes | — |

---

### NextAction (sealed class — NEW)

Typed instruction from server after attempt initiation. Replaces raw `next_action_type` string comparison.

```
NextAction
├── WalletPinAction          — show PIN/biometric step-up
├── RedirectAction           — open URL in external browser
│     redirectUrl: String
│     allowedReturnSchemes: List<String>   # from server allowlist
├── SdkLaunchAction          — launch bank SDK
│     sdkId: String
│     sdkParams: Map<String,String>
├── AppSwitchAction          — deep link to bank app
│     deepLinkUrl: String
│     fallbackUrl: String?
└── ProviderUnavailableAction — attempt rejected by provider
      reason: String
```

**Factory from JSON**:
```dart
static NextAction fromJson(Map<String, dynamic> json) {
  final type = json['next_action_type'] as String?;
  final data = json['next_action_data'] as Map<String, dynamic>? ?? {};
  return switch (type) {
    'wallet_pin'       => const WalletPinAction(),
    'redirect'         => RedirectAction.fromJson(data),
    'sdk_launch'       => SdkLaunchAction.fromJson(data),
    'app_switch'       => AppSwitchAction.fromJson(data),
    'provider_unavailable' => ProviderUnavailableAction(
        reason: data['reason'] as String? ?? 'unavailable'),
    _ => ProviderUnavailableAction(reason: 'unknown_next_action: $type'),
  };
}
```

---

### CheckoutResult (was PaymentCheckoutResult)

Typed result returned to the calling business feature. Must NOT include raw provider references or credentials.

| Field | Type | Description |
|-------|------|-------------|
| `succeeded` | `bool` | True iff payment reached `succeeded` terminal status |
| `paymentIntentId` | `String?` | Intent UUID (present if intent was created) |
| `errorCode` | `String?` | Server error code (present on failure) |

**Note**: `errorCode` is a domain code (e.g., `INSUFFICIENT_FUNDS`), not a provider reference.

---

### RecoveryRecord (NEW)

Durable local record of an in-flight intent. Stored in SharedPreferences.

| Field | Type | Storage Key | Notes |
|-------|------|------------|-------|
| `intentId` | `String` | `payment_pending_intent_id` | Written before redirect; cleared on terminal |

**Lifecycle**:
1. Written: When `AwaitingRedirect` state entered (before `launchUrl`)
2. Read: On `PaymentCheckoutSheet.initState()` — if present, skip options and enter recovery
3. Cleared: When polling resolves to terminal status, or user explicitly cancels

---

## State Machine

### PaymentCheckoutState (sealed class — extended)

All 13 states. States added in P22 are marked NEW.

```
PaymentCheckoutState
├── LoadingOptions               — fetching options from server
├── OptionsLoaded                — options list displayed; user selecting
│     options: List<PaymentOption>
│     selectedOption: PaymentOption?
│     intent: PaymentIntent?
├── ConfirmingPayment            — creating intent + initiating attempt
│     selectedOption: PaymentOption
│     intent: PaymentIntent
├── AwaitingRedirect             — intent persisted; bank URL opened
│     redirectUrl: String
│     paymentIntentId: String
├── PollingStatus                — in-session status polling after redirect
│     paymentIntentId: String
│     lastKnownStatus: PaymentIntentStatus
├── InsufficientFunds            — wallet balance below required amount
│     requiredMinor: int
│     availableMinor: int
├── StepUpRequired               — server requires PIN/biometric verification
│     paymentIntentId: String
├── RecoveringUnknown (NEW)      — cold-start; outcome not yet determinable
│     paymentIntentId: String
│     supportReference: String
├── RecoveringPending (NEW)      — cold-start; payment being processed
│     paymentIntentId: String
├── RecoveringFulfilling (NEW)   — cold-start; captured; wallet credit pending
│     paymentIntentId: String
├── PaymentSucceeded             — terminal: payment confirmed
│     paymentIntentId: String
│     amountMinor: int
│     currencyCode: String
├── PaymentFailed                — terminal: payment failed
│     errorCode: String
│     message: String
│     canRetry: bool
├── PaymentCancelled             — terminal: user or timeout cancelled
└── PaymentExpired               — terminal: intent expired (server-confirmed)
```

**State transition rules**:
- `RecoveringUnknown` → no retry button; support reference shown
- `RecoveringPending` → "payment is being processed"; continues polling
- `RecoveringFulfilling` → "payment confirmed, crediting your wallet"; continues polling
- Polling timeout (30 polls without terminal) → stays in `PollingStatus(processing)` — NOT `PaymentExpired`
- Provider replacement blocked once `ConfirmingPayment` entered and attempt submitted (`_attemptSubmitted = true`)

---

## Validation Rules

| Rule | Source |
|------|--------|
| `estimatedAmountMinor > 0` | Client-side; prevents nonsensical API call |
| `purposeReference.isNotEmpty` | Client-side; required by server |
| `currencyCode.length == 3` | Client-side; ISO 4217 |
| `redirectUrl` scheme must match `allowedReturnSchemes` | FR-006; checked on resume |
| PIN/step-up token must NEVER be persisted | NFR-003; used and discarded |
| `amount_minor` on PaymentIntent is the charge amount | FR-002; displayed to user as fee total |
