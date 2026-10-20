import 'package:wallet_agent/modules/float/domain/entities/float_balance.dart';

class FloatBalanceDto {
  final String walletId;
  final int balanceMinorUnits;
  final String currencyCode;
  final int? lowThresholdMinorUnits;

  const FloatBalanceDto({
    required this.walletId,
    required this.balanceMinorUnits,
    required this.currencyCode,
    this.lowThresholdMinorUnits,
  });

  factory FloatBalanceDto.fromJson(Map<String, dynamic> json) {
    return FloatBalanceDto(
      walletId: json['wallet_id'] as String,
      balanceMinorUnits: json['balance_minor_units'] as int,
      currencyCode: json['currency_code'] as String,
      lowThresholdMinorUnits:
          json['low_threshold_minor_units'] as int?,
    );
  }

  FloatBalance toEntity() => FloatBalance(
        walletId: walletId,
        balanceMinorUnits: balanceMinorUnits,
        currencyCode: currencyCode,
        lowThresholdMinorUnits: lowThresholdMinorUnits,
      );
}

class FloatTransactionDto {
  final String id;
  final String referenceNumber;
  final String type;
  final int amountMinorUnits;
  final String currencyCode;
  final String status;
  final DateTime createdAt;

  const FloatTransactionDto({
    required this.id,
    required this.referenceNumber,
    required this.type,
    required this.amountMinorUnits,
    required this.currencyCode,
    required this.status,
    required this.createdAt,
  });

  factory FloatTransactionDto.fromJson(Map<String, dynamic> json) {
    return FloatTransactionDto(
      id: json['id'] as String,
      referenceNumber: json['reference_number'] as String,
      type: json['type'] as String,
      amountMinorUnits: json['amount_minor_units'] as int,
      currencyCode: json['currency_code'] as String,
      status: json['status'] as String,
      createdAt: DateTime.parse(json['created_at'] as String),
    );
  }

  FloatTransaction toEntity() => FloatTransaction(
        id: id,
        referenceNumber: referenceNumber,
        type: type,
        amountMinorUnits: amountMinorUnits,
        currencyCode: currencyCode,
        status: status,
        createdAt: createdAt,
      );
}

class FloatTopUpResultDto {
  final String referenceNumber;
  final int amountMinorUnits;
  final String currencyCode;
  final String status;
  final DateTime createdAt;

  const FloatTopUpResultDto({
    required this.referenceNumber,
    required this.amountMinorUnits,
    required this.currencyCode,
    required this.status,
    required this.createdAt,
  });

  factory FloatTopUpResultDto.fromJson(Map<String, dynamic> json) {
    return FloatTopUpResultDto(
      referenceNumber: json['reference_number'] as String,
      amountMinorUnits: json['amount_minor_units'] as int,
      currencyCode: json['currency_code'] as String,
      status: json['status'] as String,
      createdAt: DateTime.parse(json['created_at'] as String),
    );
  }

  FloatTopUpResult toEntity() => FloatTopUpResult(
        referenceNumber: referenceNumber,
        amountMinorUnits: amountMinorUnits,
        currencyCode: currencyCode,
        status: status,
        createdAt: createdAt,
      );
}
