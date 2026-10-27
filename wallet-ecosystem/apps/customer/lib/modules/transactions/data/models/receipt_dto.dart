import '../../domain/entities/payment_receipt.dart';

class ReceiptDto {
  static PaymentReceipt fromJson(Map<String, dynamic> json) {
    final lineItemsJson = json['line_items'] as List<dynamic>? ?? [];
    return PaymentReceipt(
      intentId: json['intent_id'] as String,
      referenceNumber: json['reference_number'] as String,
      issuedAt: DateTime.parse(json['issued_at'] as String),
      totalAmountMinorUnits:
          json['total_amount_minor_units'] as int? ?? 0,
      feeMinorUnits: json['fee_minor_units'] as int? ?? 0,
      currencyCode: json['currency_code'] as String,
      payerName: json['payer_name'] as String?,
      payeeName: json['payee_name'] as String?,
      providerReference: json['provider_reference'] as String?,
      providerName: json['provider_name'] as String?,
      lineItems: lineItemsJson
          .map((e) => _lineItemFromJson(e as Map<String, dynamic>))
          .toList(),
      refundSummary: json['refund_summary'] as String?,
    );
  }

  static ReceiptLineItem _lineItemFromJson(Map<String, dynamic> json) {
    return ReceiptLineItem(
      description: json['description'] as String,
      amountMinorUnits: json['amount_minor_units'] as int,
      currencyCode: json['currency_code'] as String,
      category: json['category'] as String?,
    );
  }
}

class ReceiptDownloadTokenDto {
  static ReceiptDownloadToken fromJson(Map<String, dynamic> json) {
    return ReceiptDownloadToken(
      token: json['token'] as String,
      downloadUrl: json['download_url'] as String,
      expiresAt: DateTime.parse(json['expires_at'] as String),
    );
  }
}
