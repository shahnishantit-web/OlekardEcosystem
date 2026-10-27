import 'domain_deep_link.dart';
export 'domain_deep_link.dart';

enum PaymentPurposeType {
  fundingBankTransfer,
  fundingAgentCashIn,
  walletTransfer,
  withdrawal,
  servicePayment,
  billPayment,
  merchantPayment,
  fxConversion,
  refund,
  reversal,
  unknown,
}

enum PaymentStatus {
  pending,
  processing,
  completed,
  failed,
  reversed,
  cancelled,
}

enum PaymentDirection { credit, debit }

/// Capture status from the payment intent state machine.
enum CaptureStatus { pending, authorized, captured, voided, failed }

/// Accounting status — ledger acknowledgement state.
enum AccountingStatus { pending, posted, rejected }

/// Service/fulfilment status — downstream provider state.
enum ServiceStatus { pending, submitted, confirmed, failed }

/// Refund status (nullable — only present when a refund has been requested).
enum RefundStatus { requested, pending, approved, rejected, reversed }

/// Unified payment history item — replaces the old Transaction entity.
///
/// Returned by P19 GET /v1/payment/history and GET /v1/payment/history/{id}.
class PaymentHistoryItem {
  final String id;
  final String referenceNumber;
  final PaymentPurposeType purposeType;
  final PaymentStatus status;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String? counterpartyName;
  final String? counterpartyWalletNumber;
  final String? description;
  final DateTime createdAt;
  final DateTime? completedAt;

  // P19 extended fields
  final CaptureStatus captureStatus;
  final AccountingStatus accountingStatus;
  final ServiceStatus serviceStatus;
  final RefundStatus? refundStatus;
  final bool refundEligible;
  final DomainDeepLink? domainDeepLink;
  final bool receiptAvailable;
  final String? providerName;
  final String? providerReference;
  final PaymentDirection direction;

  const PaymentHistoryItem({
    required this.id,
    required this.referenceNumber,
    required this.purposeType,
    required this.status,
    required this.amountMinorUnits,
    required this.feeMinorUnits,
    required this.currencyCode,
    this.counterpartyName,
    this.counterpartyWalletNumber,
    this.description,
    required this.createdAt,
    this.completedAt,
    required this.captureStatus,
    required this.accountingStatus,
    required this.serviceStatus,
    this.refundStatus,
    required this.refundEligible,
    this.domainDeepLink,
    required this.receiptAvailable,
    this.providerName,
    this.providerReference,
    required this.direction,
  });

  String get displayTitle {
    if (counterpartyName != null && counterpartyName!.isNotEmpty) {
      return counterpartyName!;
    }
    if (providerName != null && providerName!.isNotEmpty) {
      return providerName!;
    }
    return purposeType.name;
  }

  /// Last 4 chars preceded by asterisks; never exposes full reference.
  String? get maskedProviderReference {
    if (providerReference == null || providerReference!.length <= 4) {
      return providerReference;
    }
    final last4 = providerReference!.substring(providerReference!.length - 4);
    return '****$last4';
  }
}

// Backward-compatible aliases for modules not yet migrated.
typedef Transaction = PaymentHistoryItem;
typedef TransactionType = PaymentPurposeType;
typedef TransactionStatus = PaymentStatus;
