import 'package:wallet_agent/modules/transactions/domain/entities/agent_transaction.dart';

class AgentTransactionDto {
  final String id;
  final String referenceNumber;
  final String type;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String counterpartyName;
  final String status;
  final DateTime createdAt;

  const AgentTransactionDto({
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

  factory AgentTransactionDto.fromJson(Map<String, dynamic> json) {
    return AgentTransactionDto(
      id: json['id'] as String,
      referenceNumber: json['reference_number'] as String,
      type: json['type'] as String,
      amountMinorUnits: json['amount_minor_units'] as int,
      feeMinorUnits: json['fee_minor_units'] as int,
      currencyCode: json['currency_code'] as String,
      counterpartyName: json['counterparty_name'] as String,
      status: json['status'] as String,
      createdAt: DateTime.parse(json['created_at'] as String),
    );
  }

  AgentTransaction toEntity() => AgentTransaction(
        id: id,
        referenceNumber: referenceNumber,
        type: _parseType(type),
        amountMinorUnits: amountMinorUnits,
        feeMinorUnits: feeMinorUnits,
        currencyCode: currencyCode,
        counterpartyName: counterpartyName,
        status: status,
        createdAt: createdAt,
      );

  static AgentTransactionType _parseType(String t) {
    switch (t) {
      case 'cash_in':
        return AgentTransactionType.cashIn;
      case 'cash_out':
        return AgentTransactionType.cashOut;
      case 'float_top_up':
        return AgentTransactionType.floatTopUp;
      case 'commission':
        return AgentTransactionType.commission;
      case 'reversal':
        return AgentTransactionType.reversal;
      default:
        return AgentTransactionType.other;
    }
  }
}
