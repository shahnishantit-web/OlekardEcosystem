class FloatBalance {
  final String walletId;
  final int balanceMinorUnits;
  final String currencyCode;
  final int? lowThresholdMinorUnits;

  const FloatBalance({
    required this.walletId,
    required this.balanceMinorUnits,
    required this.currencyCode,
    this.lowThresholdMinorUnits,
  });

  bool get isLow =>
      lowThresholdMinorUnits != null &&
      balanceMinorUnits <= lowThresholdMinorUnits!;
}

class FloatTransaction {
  final String id;
  final String referenceNumber;
  final String type;
  final int amountMinorUnits;
  final String currencyCode;
  final String status;
  final DateTime createdAt;

  const FloatTransaction({
    required this.id,
    required this.referenceNumber,
    required this.type,
    required this.amountMinorUnits,
    required this.currencyCode,
    required this.status,
    required this.createdAt,
  });
}

class FloatTopUpRequest {
  final int amountMinorUnits;
  final String currencyCode;

  const FloatTopUpRequest({
    required this.amountMinorUnits,
    required this.currencyCode,
  });
}

class FloatTopUpResult {
  final String referenceNumber;
  final int amountMinorUnits;
  final String currencyCode;
  final String status;
  final DateTime createdAt;

  const FloatTopUpResult({
    required this.referenceNumber,
    required this.amountMinorUnits,
    required this.currencyCode,
    required this.status,
    required this.createdAt,
  });
}
