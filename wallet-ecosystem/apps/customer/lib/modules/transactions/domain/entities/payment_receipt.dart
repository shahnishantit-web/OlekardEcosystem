class ReceiptLineItem {
  final String description;
  final int amountMinorUnits;
  final String currencyCode;
  final String? category;

  const ReceiptLineItem({
    required this.description,
    required this.amountMinorUnits,
    required this.currencyCode,
    this.category,
  });
}

class PaymentReceipt {
  final String intentId;
  final String referenceNumber;
  final DateTime issuedAt;
  final int totalAmountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String? payerName;
  final String? payeeName;
  final String? providerReference;
  final String? providerName;
  final List<ReceiptLineItem> lineItems;
  final String? refundSummary;

  const PaymentReceipt({
    required this.intentId,
    required this.referenceNumber,
    required this.issuedAt,
    required this.totalAmountMinorUnits,
    required this.feeMinorUnits,
    required this.currencyCode,
    this.payerName,
    this.payeeName,
    this.providerReference,
    this.providerName,
    required this.lineItems,
    this.refundSummary,
  });

  /// Last 4 chars only — never expose the full provider reference in UI.
  String? get maskedProviderReference {
    if (providerReference == null || providerReference!.length <= 4) {
      return providerReference;
    }
    final last4 = providerReference!.substring(providerReference!.length - 4);
    return '****$last4';
  }
}

/// Ephemeral single-use download token.
///
/// MUST NOT be serialised to storage, logged, or included in analytics.
/// The [downloadUrl] must be consumed immediately and discarded.
class ReceiptDownloadToken {
  final String token;
  final String downloadUrl;
  final DateTime expiresAt;

  const ReceiptDownloadToken({
    required this.token,
    required this.downloadUrl,
    required this.expiresAt,
  });

  bool get isExpired => DateTime.now().isAfter(expiresAt);
}
