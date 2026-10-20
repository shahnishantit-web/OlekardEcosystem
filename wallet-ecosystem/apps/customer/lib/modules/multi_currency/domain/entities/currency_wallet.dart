class CurrencyWallet {
  const CurrencyWallet({
    required this.id,
    required this.currencyCode,
    required this.balance,
    required this.symbol,
    required this.decimalPlaces,
    required this.isActive,
  });

  final String id;
  final String currencyCode;
  final String balance;
  final String symbol;
  final int decimalPlaces;
  final bool isActive;

  factory CurrencyWallet.fromJson(Map<String, dynamic> json) {
    return CurrencyWallet(
      id:            json['id'] as String,
      currencyCode:  json['currency_code'] as String,
      balance:       json['balance'] as String,
      symbol:        json['symbol'] as String? ?? '',
      decimalPlaces: json['decimal_places'] as int? ?? 2,
      isActive:      json['is_active'] as bool? ?? true,
    );
  }
}
