class FxQuote {
  const FxQuote({
    required this.id,
    required this.fromCurrencyCode,
    required this.toCurrencyCode,
    required this.fromAmount,
    required this.toAmount,
    required this.feeAmount,
    required this.rate,
    required this.spread,
    required this.status,
    required this.expiresAt,
    this.fxTransactionId,
  });

  final String id;
  final String fromCurrencyCode;
  final String toCurrencyCode;
  final String fromAmount;
  final String toAmount;
  final String feeAmount;
  final String rate;
  final String spread;
  final String status;
  final DateTime expiresAt;
  final String? fxTransactionId;

  bool get isExpired => DateTime.now().isAfter(expiresAt);

  factory FxQuote.fromJson(Map<String, dynamic> json) {
    return FxQuote(
      id:               json['id'] as String,
      fromCurrencyCode: json['from_currency_code'] as String,
      toCurrencyCode:   json['to_currency_code'] as String,
      fromAmount:       json['from_amount'] as String,
      toAmount:         json['to_amount'] as String,
      feeAmount:        json['fee_amount'] as String,
      rate:             json['rate'] as String,
      spread:           json['spread'] as String,
      status:           json['status'] as String,
      expiresAt:        DateTime.parse(json['expires_at'] as String),
      fxTransactionId:  json['fx_transaction_id'] as String?,
    );
  }
}
