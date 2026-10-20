/// T117: PaymentOption domain model — represents one available payment method.
class PaymentOption {
  final String providerCode;
  final String displayName;
  final String? iconUrl;
  final bool isDefault;
  final bool isWallet;
  final int? walletBalanceMinor;
  final bool? hasSufficientBalance;
  final String? minAmountMessage;
  final List<String> supportedCurrencies;

  const PaymentOption({
    required this.providerCode,
    required this.displayName,
    this.iconUrl,
    this.isDefault = false,
    this.isWallet = false,
    this.walletBalanceMinor,
    this.hasSufficientBalance,
    this.minAmountMessage,
    this.supportedCurrencies = const [],
  });

  factory PaymentOption.fromJson(Map<String, dynamic> json) {
    return PaymentOption(
      providerCode:       json['provider_code'] as String,
      displayName:        json['display_name'] as String,
      iconUrl:            json['icon_url'] as String?,
      isDefault:          (json['is_default'] as bool?) ?? false,
      isWallet:           (json['is_wallet'] as bool?) ?? false,
      walletBalanceMinor: json['wallet_balance_minor'] as int?,
      hasSufficientBalance: json['has_sufficient_balance'] as bool?,
      minAmountMessage:   json['min_amount_message'] as String?,
      supportedCurrencies: (json['supported_currencies'] as List<dynamic>?)
              ?.map((e) => e as String)
              .toList() ??
          const [],
    );
  }
}
