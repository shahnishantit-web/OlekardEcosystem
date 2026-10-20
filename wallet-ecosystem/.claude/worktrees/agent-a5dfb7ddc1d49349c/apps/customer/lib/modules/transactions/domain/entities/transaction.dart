enum TransactionType {
  fundingBankTransfer,
  fundingAgentCashIn,
  transfer,
  withdrawal,
  servicePayment,
  fxConversion,
}

enum TransactionStatus { pending, processing, completed, failed, reversed }

class Transaction {
  final String id;
  final String referenceNumber;
  final TransactionType type;
  final TransactionStatus status;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String? counterpartyName;
  final String? counterpartyWalletNumber;
  final String? description;
  final DateTime createdAt;
  final DateTime? completedAt;

  const Transaction({
    required this.id,
    required this.referenceNumber,
    required this.type,
    required this.status,
    required this.amountMinorUnits,
    required this.feeMinorUnits,
    required this.currencyCode,
    this.counterpartyName,
    this.counterpartyWalletNumber,
    this.description,
    required this.createdAt,
    this.completedAt,
  });

  double get amountMajor => amountMinorUnits / 100.0;
  double get feeMajor => feeMinorUnits / 100.0;
  double get netMajor => (amountMinorUnits + feeMinorUnits) / 100.0;
}
