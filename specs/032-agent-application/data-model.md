# Data Model — E32 Agent Application

This document describes the client-side entity model. All entities are populated from backend API responses; there is no local database. Amounts are stored as minor units (integer) in the entity and formatted using `intl` for display.

---

## Agent (auth module)

Represents the authenticated agent. Populated from `GET /v1/agent/profile` or the login response.

| Field | Type | Notes |
|-------|------|-------|
| id | String | UUID v7 |
| phone | String | E.164 format |
| firstName | String | |
| lastName | String | |
| fullName | String | Getter: `$firstName $lastName` |
| locationCode | String | Agent's assigned location/branch code |
| status | AgentStatus | `active`, `suspended`, `pending` |
| kycStatus | KycStatus | `verified`, `pending`, `rejected` |
| createdAt | DateTime | UTC |

```dart
enum AgentStatus { active, suspended, pending }
enum KycStatus { verified, pending, rejected }
```

---

## AuthToken (auth module)

Stored in `flutter_secure_storage`.

| Field | Type | Notes |
|-------|------|-------|
| accessToken | String | JWT; never logged |
| refreshToken | String | Longer-lived; never logged |
| expiresAt | DateTime | UTC; used to proactive-refresh |

---

## DashboardSummary (dashboard module)

Populated from `GET /v1/agent/dashboard`.

| Field | Type | Notes |
|-------|------|-------|
| floatBalance | int | Minor units |
| floatCurrencyCode | String | e.g., "BWP" |
| lowFloatThreshold | int | Minor units; from server config |
| todayCashInCount | int | |
| todayCashInAmountMinorUnits | int | |
| todayCashOutCount | int | |
| todayCashOutAmountMinorUnits | int | |
| updatedAt | DateTime | UTC; shown as "last updated" |

---

## CustomerLookup (cash_in module)

Populated from `GET /v1/agent/customers?q={phone_or_wallet}`.

| Field | Type | Notes |
|-------|------|-------|
| walletId | String | UUID v7; used in cash-in POST body |
| customerName | String | Shown for identity confirmation |
| maskedWalletNumber | String | e.g., `****-1234` |
| accountStatus | String | `active`, `suspended` |

---

## CashInResult (cash_in module)

Populated from `POST /v1/agent/cash-in` response.

| Field | Type | Notes |
|-------|------|-------|
| referenceNumber | String | |
| amountMinorUnits | int | Amount deposited |
| feeMinorUnits | int | Agent fee deducted from float |
| currencyCode | String | |
| customerName | String | For receipt |
| agentName | String | For receipt |
| agentLocationCode | String | For receipt |
| timestamp | DateTime | UTC |
| status | String | `completed` |

---

## PickupCodeLookup (cash_out module)

Populated from `GET /v1/agent/cash-out/lookup?code={code}`.

| Field | Type | Notes |
|-------|------|-------|
| code | String | The validated pickup code |
| customerName | String | Shown for identity confirmation |
| maskedWalletNumber | String | |
| amountMinorUnits | int | Amount to pay out |
| feeMinorUnits | int | |
| currencyCode | String | |
| expiresAt | DateTime | UTC; shown as countdown |

---

## CashOutResult (cash_out module)

Populated from `POST /v1/agent/cash-out` response.

| Field | Type | Notes |
|-------|------|-------|
| referenceNumber | String | |
| amountMinorUnits | int | Amount paid out |
| feeMinorUnits | int | |
| currencyCode | String | |
| customerName | String | For receipt |
| agentName | String | For receipt |
| agentLocationCode | String | For receipt |
| timestamp | DateTime | UTC |
| status | String | `completed` |

---

## CustomerProfile (customer_onboarding module)

Used both for search results and newly registered customers. Populated from `GET /v1/agent/customers/{id}`.

| Field | Type | Notes |
|-------|------|-------|
| id | String | UUID v7 |
| phone | String | |
| firstName | String | |
| lastName | String | |
| fullName | String | Getter |
| nationalIdNumber | String | Masked for display (show last 4 digits) |
| dateOfBirth | DateTime | |
| accountStatus | String | `active`, `suspended`, `pending` |
| kycStatus | String | `verified`, `pending_review`, `rejected` |
| walletNumber | String | Masked for display |
| createdAt | DateTime | UTC |

---

## RegistrationRequest (customer_onboarding module)

Sent via `POST /v1/agent/customers`.

| Field | Type | Notes |
|-------|------|-------|
| firstName | String | Required |
| lastName | String | Required |
| phone | String | Required; E.164 |
| dateOfBirth | String | ISO 8601 date; `yyyy-MM-dd` |
| nationalIdNumber | String | Required |

---

## AgentTransaction (transactions module)

Populated from `GET /v1/agent/transactions` (paginated) and `GET /v1/agent/transactions/{id}`.

| Field | Type | Notes |
|-------|------|-------|
| id | String | UUID v7 |
| referenceNumber | String | |
| type | AgentTransactionType | |
| status | String | `completed`, `failed`, `reversed` |
| amountMinorUnits | int | |
| feeMinorUnits | int | |
| netMinorUnits | int | amount - fee for cash-in; amount + fee for cash-out |
| currencyCode | String | |
| customerName | String | Nullable for float transactions |
| createdAt | DateTime | UTC |

```dart
enum AgentTransactionType { cashIn, cashOut, floatTopUp, commission }
```

---

## FloatBalance (float module)

Populated from `GET /v1/agent/float`.

| Field | Type | Notes |
|-------|------|-------|
| balanceMinorUnits | int | Current float balance |
| currencyCode | String | |
| lowFloatThreshold | int | Minor units; from server |
| lastTopUpAt | DateTime? | UTC; null if never topped up |
| updatedAt | DateTime | UTC |

```dart
bool get isLow => balanceMinorUnits <= lowFloatThreshold;
```

---

## FloatTransaction (float module)

Represents a single float ledger movement. Populated from `GET /v1/agent/float/history`.

| Field | Type | Notes |
|-------|------|-------|
| id | String | UUID v7 |
| type | FloatTransactionType | `cashInCredit`, `cashOutDebit`, `topUpCredit` |
| amountMinorUnits | int | Absolute value; sign determined by type |
| currencyCode | String | |
| referenceNumber | String | |
| createdAt | DateTime | UTC |

```dart
enum FloatTransactionType { cashInCredit, cashOutDebit, topUpCredit }
```

---

## FloatTopUpRequest (float module)

Populated from `POST /v1/agent/float/requests` response and subsequent `GET /v1/agent/float/requests/pending`.

| Field | Type | Notes |
|-------|------|-------|
| id | String | UUID v7 |
| requestedAmountMinorUnits | int | |
| currencyCode | String | |
| status | FloatRequestStatus | |
| note | String? | Optional agent note |
| submittedAt | DateTime | UTC |
| resolvedAt | DateTime? | UTC; null if still pending |

```dart
enum FloatRequestStatus { pending, approved, rejected }
```

---

## CommissionBalance (commission module)

Populated from `GET /v1/agent/commissions/balance`.

| Field | Type | Notes |
|-------|------|-------|
| totalMinorUnits | int | All-time total |
| cashInMinorUnits | int | Breakdown by type |
| cashOutMinorUnits | int | |
| registrationMinorUnits | int | |
| currencyCode | String | |

---

## CommissionEntry (commission module)

Populated from `GET /v1/agent/commissions` (paginated).

| Field | Type | Notes |
|-------|------|-------|
| id | String | UUID v7 |
| type | CommissionType | `cashIn`, `cashOut`, `registration` |
| amountMinorUnits | int | Earned amount |
| rateApplied | String | e.g., `1.5%` or `5.00 BWP flat` |
| linkedTransactionReference | String | The source transaction reference |
| createdAt | DateTime | UTC |

```dart
enum CommissionType { cashIn, cashOut, registration }
```

---

## AppNotification (notifications module)

Mirrors E31 notification entity.

| Field | Type | Notes |
|-------|------|-------|
| id | String | UUID v7 |
| type | AgentNotificationType | |
| title | String | |
| body | String | |
| isRead | bool | |
| deepLink | String? | GoRouter path for navigation on tap |
| createdAt | DateTime | UTC |

```dart
enum AgentNotificationType { floatApproved, floatRejected, commissionCredited, securityAlert, system }
```

---

## ReceiptData (core/receipt module)

Passed to `ReceiptService.generateReceipt()`.

| Field | Type | Notes |
|-------|------|-------|
| referenceNumber | String | |
| transactionType | String | Human-readable: "Cash In" / "Cash Out" |
| customerName | String | |
| amountMinorUnits | int | |
| feeMinorUnits | int | |
| netMinorUnits | int | |
| currencyCode | String | |
| agentName | String | |
| agentLocationCode | String | |
| timestamp | DateTime | UTC |

---

## Entity State Transitions

### FloatTopUpRequest Status
```
[submitted] → pending → approved / rejected
```
- `pending`: visible to agent as "awaiting approval"
- `approved`: float balance increases; agent notified via push
- `rejected`: agent notified via push with reason

### AgentTransaction Status
```
completed ← normal success
failed    ← backend rejection (insufficient float, invalid code, etc.)
reversed  ← compensating entry posted
```
Reversed transactions show a distinct visual state in the list; tapping shows the reversal reference.
