class CashOutResult {
  final String referenceNumber;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String customerName;
  final String agentName;
  final String agentLocationCode;
  final DateTime timestamp;
  final String status;

  const CashOutResult({
    required this.referenceNumber,
    required this.amountMinorUnits,
    required this.feeMinorUnits,
    required this.currencyCode,
    required this.customerName,
    required this.agentName,
    required this.agentLocationCode,
    required this.timestamp,
    required this.status,
  });

  int get netMinorUnits => amountMinorUnits + feeMinorUnits;
}
