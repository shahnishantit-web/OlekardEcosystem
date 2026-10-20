enum WithdrawalStatus { pending, readyForPickup, completed, failed }

class WithdrawalPreview {
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String agentName;
  const WithdrawalPreview({
    required this.amountMinorUnits,
    required this.feeMinorUnits,
    required this.currencyCode,
    required this.agentName,
  });
}

class WithdrawalResult {
  final String transactionId;
  final String referenceNumber;
  final WithdrawalStatus status;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String agentName;
  final String? pickupCode;
  final DateTime createdAt;

  const WithdrawalResult({
    required this.transactionId,
    required this.referenceNumber,
    required this.status,
    required this.amountMinorUnits,
    required this.feeMinorUnits,
    required this.currencyCode,
    required this.agentName,
    this.pickupCode,
    required this.createdAt,
  });
}

class WithdrawRequestDto {
  final String idempotencyKey;
  final String agentWalletNumber;
  final int amountMinorUnits;
  final String currencyCode;

  const WithdrawRequestDto({
    required this.idempotencyKey,
    required this.agentWalletNumber,
    required this.amountMinorUnits,
    required this.currencyCode,
  });

  Map<String, dynamic> toJson() => {
        'agent_wallet_number': agentWalletNumber,
        'amount_minor_units': amountMinorUnits,
        'currency_code': currencyCode,
      };
}
