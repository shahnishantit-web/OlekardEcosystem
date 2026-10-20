class CommissionBalance {
  final int availableMinorUnits;
  final int pendingMinorUnits;
  final String currencyCode;

  const CommissionBalance({
    required this.availableMinorUnits,
    required this.pendingMinorUnits,
    required this.currencyCode,
  });
}

class CommissionEntry {
  final String id;
  final String referenceNumber;
  final String transactionType;
  final int amountMinorUnits;
  final String currencyCode;
  final String status;
  final double rateApplied;
  final DateTime createdAt;

  const CommissionEntry({
    required this.id,
    required this.referenceNumber,
    required this.transactionType,
    required this.amountMinorUnits,
    required this.currencyCode,
    required this.status,
    required this.rateApplied,
    required this.createdAt,
  });
}
