class FeeDisclosure {
  final int? serviceFeeMinor;
  final int? providerFeeMinor;
  final int? totalFeeMinor;
  final int? totalPayableMinor;

  const FeeDisclosure({
    this.serviceFeeMinor,
    this.providerFeeMinor,
    this.totalFeeMinor,
    this.totalPayableMinor,
  });

  factory FeeDisclosure.fromJson(Map<String, dynamic> json) {
    return FeeDisclosure(
      serviceFeeMinor:   json['service_fee_minor'] as int?,
      providerFeeMinor:  json['provider_fee_minor'] as int?,
      totalFeeMinor:     json['total_fee_minor'] as int?,
      totalPayableMinor: json['total_payable_minor'] as int?,
    );
  }
}

class PaymentOption {
  final String providerCode;
  final String displayName;
  final String? displayLabel;
  final String? iconUrl;
  final bool isDefault;
  final bool isWallet;
  final int? walletBalanceMinor;
  final bool? hasSufficientBalance;
  final String? minAmountMessage;
  final List<String> supportedCurrencies;
  final String? paymentMethod;
  final FeeDisclosure? feeDisclosure;
  final String? supportedNextAction;
  final List<String> restrictions;
  // Human-readable bank contact shown when redirect URL cannot be opened.
  final String? fallbackMessage;

  const PaymentOption({
    required this.providerCode,
    required this.displayName,
    this.displayLabel,
    this.iconUrl,
    this.isDefault = false,
    this.isWallet = false,
    this.walletBalanceMinor,
    this.hasSufficientBalance,
    this.minAmountMessage,
    this.supportedCurrencies = const [],
    this.paymentMethod,
    this.feeDisclosure,
    this.supportedNextAction,
    this.restrictions = const [],
    this.fallbackMessage,
  });

  String get effectiveLabel => displayLabel ?? displayName;
  bool get isRestricted => restrictions.isNotEmpty;

  factory PaymentOption.fromJson(Map<String, dynamic> json) {
    final feeJson = json['fee_disclosure'] as Map<String, dynamic>?;
    final avail   = json['availability'] as Map<String, dynamic>?;
    return PaymentOption(
      providerCode:        json['provider_code'] as String,
      displayName:         json['display_name'] as String,
      displayLabel:        json['display_label'] as String?,
      iconUrl:             json['icon_url'] as String?,
      isDefault:           (json['is_default'] as bool?) ?? false,
      isWallet:            (json['is_wallet'] as bool?) ?? false,
      walletBalanceMinor:  json['wallet_balance_minor'] as int?,
      hasSufficientBalance: json['has_sufficient_balance'] as bool?,
      minAmountMessage:    json['min_amount_message'] as String?,
      supportedCurrencies: (json['supported_currencies'] as List<dynamic>?)
              ?.map((e) => e as String)
              .toList() ??
          const [],
      paymentMethod:       json['payment_method'] as String?,
      feeDisclosure:       feeJson != null ? FeeDisclosure.fromJson(feeJson) : null,
      supportedNextAction: json['supported_next_action'] as String?,
      restrictions:        (avail?['restrictions'] as List<dynamic>?)
              ?.map((e) => e as String)
              .toList() ??
          const [],
      fallbackMessage:     json['fallback_message'] as String?,
    );
  }
}
