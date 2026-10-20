# Data Model — E31 Customer Application

Flutter domain entities and DTOs. Entities live in `domain/entities/`; DTOs live in `data/models/`.

---

## AuthToken

**Entity**: `lib/modules/auth/domain/entities/auth_token.dart`

```dart
class AuthToken {
  final String accessToken;
  final String refreshToken;
  final DateTime expiresAt;

  bool get isExpired => DateTime.now().isAfter(expiresAt);
}
```

**Stored in**: `flutter_secure_storage` under keys `access_token`, `refresh_token`.

---

## Customer

**Entity**: `lib/modules/auth/domain/entities/customer.dart`

```dart
class Customer {
  final String id;           // UUID v7
  final String phone;        // E.164 format
  final String? email;
  final String firstName;
  final String lastName;
  final String? avatarUrl;
  final KycStatus kycStatus; // none | pending | approved | rejected
  final String locale;       // e.g. "en-KE"
  final DateTime createdAt;
}

enum KycStatus { none, pending, approved, rejected }
```

**DTO**: `lib/modules/auth/data/models/auth_response_dto.dart` — `fromJson(Map<String, dynamic> json)`

---

## Wallet

**Entity**: `lib/modules/wallet/domain/entities/wallet.dart`

```dart
class Wallet {
  final String id;           // UUID v7
  final String walletNumber; // human-readable identifier
  final String currencyCode; // ISO 4217, e.g. "KES"
  final int balanceMinorUnits; // e.g. 100000 = KES 1,000.00
  final WalletStatus status;
  final DateTime updatedAt;

  double get balanceMajor => balanceMinorUnits / 100.0;
}

enum WalletStatus { active, suspended, closed }
```

**DTO**: `lib/modules/wallet/data/models/wallet_dto.dart`

---

## Transaction

**Entity**: `lib/modules/transactions/domain/entities/transaction.dart`

```dart
class Transaction {
  final String id;
  final String referenceNumber;  // e.g. "TXN-20261001-XXXXX"
  final TransactionType type;
  final TransactionStatus status;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String? counterpartyName;
  final String? counterpartyWalletNumber;
  final String? description;
  final DateTime createdAt;
  final DateTime? completedAt;

  double get amountMajor => amountMinorUnits / 100.0;
  double get feeMajor => feeMinorUnits / 100.0;
  double get netMajor => (amountMinorUnits + feeMinorUnits) / 100.0;
}

enum TransactionType {
  fundingBankTransfer,
  fundingAgentCashIn,
  transfer,
  withdrawal,
  servicePayment,
  fxConversion,
}

enum TransactionStatus { pending, processing, completed, failed, reversed }
```

**DTO**: `lib/modules/transactions/data/models/transaction_dto.dart`

---

## FundingResult

**Entity**: `lib/modules/funding/domain/entities/funding_result.dart`

```dart
class FundingResult {
  final String transactionId;
  final String referenceNumber;
  final FundingResultStatus status;
  final int amountMinorUnits;
  final String currencyCode;
  final String? redirectUrl;    // for bank-transfer flows
  final DateTime createdAt;
}

enum FundingResultStatus { initiated, awaitingPayment, completed, failed }
```

**Request DTO**: `lib/modules/funding/data/models/fund_request_dto.dart`

```dart
class FundRequestDto {
  final String idempotencyKey;   // UUID v4, generated client-side
  final FundingMethod method;    // bankTransfer | agentCashIn
  final int amountMinorUnits;
  final String currencyCode;

  Map<String, dynamic> toJson() { ... }
}
```

---

## TransferResult

**Entity**: `lib/modules/transfer/domain/entities/transfer_result.dart`

```dart
class TransferResult {
  final String transactionId;
  final String referenceNumber;
  final TransferStatus status;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String recipientName;
  final String recipientWalletNumber;
  final DateTime createdAt;
}

enum TransferStatus { pending, completed, failed }
```

**Request DTO**: `lib/modules/transfer/data/models/transfer_request_dto.dart`

```dart
class TransferRequestDto {
  final String idempotencyKey;
  final String recipientWalletNumber;
  final int amountMinorUnits;
  final String currencyCode;
  final String? note;

  Map<String, dynamic> toJson() { ... }
}
```

---

## WithdrawalResult

**Entity**: `lib/modules/withdrawal/domain/entities/withdrawal_result.dart`

```dart
class WithdrawalResult {
  final String transactionId;
  final String referenceNumber;
  final WithdrawalStatus status;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String agentName;
  final DateTime createdAt;
}

enum WithdrawalStatus { pending, readyForPickup, completed, failed }
```

**Request DTO**: `lib/modules/withdrawal/data/models/withdraw_request_dto.dart`

```dart
class WithdrawRequestDto {
  final String idempotencyKey;
  final String agentWalletNumber;
  final int amountMinorUnits;
  final String currencyCode;

  Map<String, dynamic> toJson() { ... }
}
```

---

## ServiceCategory

**Entity**: `lib/modules/services/domain/entities/service_category.dart`

```dart
class ServiceCategory {
  final String id;
  final String name;
  final String iconUrl;
  final String description;
  final int providerCount;
}
```

---

## ServiceProvider

**Entity**: `lib/modules/services/domain/entities/service_provider.dart`

```dart
class ServiceProvider {
  final String id;
  final String categoryId;
  final String name;
  final String logoUrl;
  final String description;
  final List<ServiceField> requiredFields;
  final int feeMinorUnits;
  final String currencyCode;
  final bool isActive;
}

class ServiceField {
  final String key;
  final String label;
  final ServiceFieldType type;    // text | numeric | phone | select
  final bool required;
  final String? hint;
  final List<String>? options;    // for select type
}
```

---

## ServicePayment

**Entity**: `lib/modules/services/domain/entities/service_payment.dart`

```dart
class ServicePayment {
  final String id;
  final String referenceNumber;
  final String providerId;
  final String providerName;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final ServicePaymentStatus status;
  final Map<String, String> fieldValues;
  final DateTime createdAt;
}

enum ServicePaymentStatus { pending, processing, completed, failed }
```

**Request DTO**: `lib/modules/services/data/models/service_dto.dart`

```dart
class ServicePurchaseRequestDto {
  final String idempotencyKey;
  final String providerId;
  final int amountMinorUnits;
  final Map<String, String> fieldValues;

  Map<String, dynamic> toJson() { ... }
}
```

---

## Profile

**Entity**: `lib/modules/profile/domain/entities/profile.dart`

```dart
class Profile {
  final String id;
  final String phone;
  final String? email;
  final String firstName;
  final String lastName;
  final String? avatarUrl;
  final KycStatus kycStatus;
  final String locale;
  final ProfileNotificationPreferences notifications;
}

class ProfileNotificationPreferences {
  final bool push;
  final bool sms;
  final bool email;
}
```

---

## AppNotification

**Entity**: `lib/modules/notifications/domain/entities/notification.dart`

```dart
class AppNotification {
  final String id;
  final NotificationType type;
  final String title;
  final String body;
  final bool isRead;
  final Map<String, String> metadata;  // e.g. {transaction_id: "..."}
  final DateTime createdAt;
}

enum NotificationType {
  transaction,
  transfer,
  kycUpdate,
  alert,
  supportReply,
  marketing,
}
```

---

## SupportTicket

**Entity**: `lib/modules/support/domain/entities/support_ticket.dart`

```dart
class SupportTicket {
  final String id;
  final String referenceNumber;
  final String subject;
  final TicketCategory category;
  final TicketStatus status;
  final List<TicketMessage> messages;
  final DateTime createdAt;
  final DateTime updatedAt;
}

class TicketMessage {
  final String id;
  final TicketMessageSender sender;  // customer | support
  final String body;
  final List<String> attachmentUrls;
  final DateTime sentAt;
}

enum TicketCategory { payment, account, kyc, other }
enum TicketStatus { open, inProgress, resolved, closed }
enum TicketMessageSender { customer, support }
```

---

## Dispute

**Entity**: `lib/modules/support/domain/entities/dispute.dart`

```dart
class Dispute {
  final String id;
  final String referenceNumber;
  final String transactionId;
  final String transactionReference;
  final DisputeReason reason;
  final DisputeStatus status;
  final String? resolutionNotes;
  final DateTime createdAt;
  final DateTime updatedAt;
}

enum DisputeReason {
  unauthorizedTransaction,
  incorrectAmount,
  serviceNotDelivered,
  duplicateCharge,
  other,
}

enum DisputeStatus { submitted, underReview, resolved, rejected }
```

---

## Device (existing — domain/entities/device.dart)

Already implemented in `lib/modules/device/`. No changes required.

```dart
class Device {
  final String id;
  final String userId;
  final String deviceName;
  final DevicePlatform platform;  // android | ios
  final DeviceStatus status;      // active | blocked
  final DateTime? trustedAt;
  final DateTime? blockedAt;
  final DateTime lastSeenAt;
  final DateTime createdAt;
}
```

---

## HomeSummary

**Entity**: `lib/modules/home/domain/entities/home_summary.dart`

Aggregates data for the home screen in a single backend call.

```dart
class HomeSummary {
  final Wallet primaryWallet;
  final List<Transaction> recentTransactions; // last 5
  final int unreadNotificationCount;
}
```

---

## State Transitions

### Authentication Flow
```
unauthenticated
  → registering (OTP sent)
  → profileSetup
  → pinCreation
  → authenticated

unauthenticated
  → loggingIn (OTP/PIN check)
  → authenticated
```

### Financial Transaction (all types)
```
idle
  → formEntry
  → previewing (confirmation screen shown)
  → pinAuthorizing
  → submitting (idempotency key sent)
  → success | failure
```

### KYC
```
none → pending → approved
none → pending → rejected → pending (retry)
```
