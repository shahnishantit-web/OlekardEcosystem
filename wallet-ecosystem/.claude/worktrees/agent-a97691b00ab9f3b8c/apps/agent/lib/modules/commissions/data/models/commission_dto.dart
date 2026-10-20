import 'package:wallet_agent/modules/commissions/domain/entities/commission_balance.dart';

class CommissionBalanceDto {
  final int availableMinorUnits;
  final int pendingMinorUnits;
  final String currencyCode;

  const CommissionBalanceDto({
    required this.availableMinorUnits,
    required this.pendingMinorUnits,
    required this.currencyCode,
  });

  factory CommissionBalanceDto.fromJson(Map<String, dynamic> json) {
    return CommissionBalanceDto(
      availableMinorUnits: json['available_minor_units'] as int,
      pendingMinorUnits: json['pending_minor_units'] as int,
      currencyCode: json['currency_code'] as String,
    );
  }

  CommissionBalance toEntity() => CommissionBalance(
        availableMinorUnits: availableMinorUnits,
        pendingMinorUnits: pendingMinorUnits,
        currencyCode: currencyCode,
      );
}

class CommissionEntryDto {
  final String id;
  final String referenceNumber;
  final String transactionType;
  final int amountMinorUnits;
  final String currencyCode;
  final String status;
  final double rateApplied;
  final DateTime createdAt;

  const CommissionEntryDto({
    required this.id,
    required this.referenceNumber,
    required this.transactionType,
    required this.amountMinorUnits,
    required this.currencyCode,
    required this.status,
    required this.rateApplied,
    required this.createdAt,
  });

  factory CommissionEntryDto.fromJson(Map<String, dynamic> json) {
    return CommissionEntryDto(
      id: json['id'] as String,
      referenceNumber: json['reference_number'] as String,
      transactionType: json['transaction_type'] as String,
      amountMinorUnits: json['amount_minor_units'] as int,
      currencyCode: json['currency_code'] as String,
      status: json['status'] as String,
      rateApplied: (json['rate_applied'] as num?)?.toDouble() ?? 0.0,
      createdAt: DateTime.parse(json['created_at'] as String),
    );
  }

  CommissionEntry toEntity() => CommissionEntry(
        id: id,
        referenceNumber: referenceNumber,
        transactionType: transactionType,
        amountMinorUnits: amountMinorUnits,
        currencyCode: currencyCode,
        status: status,
        rateApplied: rateApplied,
        createdAt: createdAt,
      );
}
