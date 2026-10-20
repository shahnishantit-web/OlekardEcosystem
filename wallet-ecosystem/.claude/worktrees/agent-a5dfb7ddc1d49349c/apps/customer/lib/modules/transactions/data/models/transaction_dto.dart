import '../../domain/entities/transaction.dart';

class TransactionDto {
  static Transaction fromJson(Map<String, dynamic> json) {
    final typeStr = json['type'] as String? ?? 'transfer';
    final type = _typeFromString(typeStr);
    final statusStr = json['status'] as String? ?? 'pending';
    final status = TransactionStatus.values.firstWhere(
      (e) => e.name == statusStr,
      orElse: () => TransactionStatus.pending,
    );
    return Transaction(
      id: json['id'] as String,
      referenceNumber: json['reference_number'] as String,
      type: type,
      status: status,
      amountMinorUnits: json['amount_minor_units'] as int,
      feeMinorUnits: json['fee_minor_units'] as int? ?? 0,
      currencyCode: json['currency_code'] as String,
      counterpartyName: json['counterparty_name'] as String?,
      counterpartyWalletNumber: json['counterparty_wallet_number'] as String?,
      description: json['description'] as String?,
      createdAt: DateTime.parse(json['created_at'] as String),
      completedAt: json['completed_at'] != null
          ? DateTime.parse(json['completed_at'] as String)
          : null,
    );
  }

  static TransactionType _typeFromString(String s) {
    return switch (s) {
      'funding_bank_transfer' => TransactionType.fundingBankTransfer,
      'funding_agent_cash_in' => TransactionType.fundingAgentCashIn,
      'transfer' => TransactionType.transfer,
      'withdrawal' => TransactionType.withdrawal,
      'service_payment' => TransactionType.servicePayment,
      'fx_conversion' => TransactionType.fxConversion,
      _ => TransactionType.transfer,
    };
  }
}
