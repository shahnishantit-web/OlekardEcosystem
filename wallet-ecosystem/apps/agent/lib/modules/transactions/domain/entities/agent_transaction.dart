enum AgentTransactionType {
  cashIn,
  cashOut,
  floatTopUp,
  commission,
  reversal,
  other,
}

class AgentTransaction {
  final String id;
  final String referenceNumber;
  final AgentTransactionType type;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String counterpartyName;
  final String status;
  final DateTime createdAt;

  const AgentTransaction({
    required this.id,
    required this.referenceNumber,
    required this.type,
    required this.amountMinorUnits,
    required this.feeMinorUnits,
    required this.currencyCode,
    required this.counterpartyName,
    required this.status,
    required this.createdAt,
  });

  int get netMinorUnits => amountMinorUnits - feeMinorUnits;
}
