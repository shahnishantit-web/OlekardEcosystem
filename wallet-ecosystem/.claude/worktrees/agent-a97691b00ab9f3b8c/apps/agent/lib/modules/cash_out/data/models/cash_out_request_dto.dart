import 'package:wallet_agent/modules/cash_out/domain/entities/cash_out_result.dart';
import 'package:wallet_agent/modules/cash_out/domain/entities/pickup_code_lookup.dart';

class CashOutRequestDto {
  final String walletId;
  final int amountMinorUnits;
  final String currencyCode;
  final String pinHash;

  const CashOutRequestDto({
    required this.walletId,
    required this.amountMinorUnits,
    required this.currencyCode,
    required this.pinHash,
  });

  Map<String, dynamic> toJson() => {
        'wallet_id': walletId,
        'amount_minor_units': amountMinorUnits,
        'currency_code': currencyCode,
        'pin_hash': pinHash,
      };
}

class PickupCodeLookupDto {
  final String pickupCode;
  final String customerName;
  final String maskedWalletNumber;
  final String walletId;
  final int amountMinorUnits;
  final String currencyCode;
  final DateTime expiresAt;

  const PickupCodeLookupDto({
    required this.pickupCode,
    required this.customerName,
    required this.maskedWalletNumber,
    required this.walletId,
    required this.amountMinorUnits,
    required this.currencyCode,
    required this.expiresAt,
  });

  factory PickupCodeLookupDto.fromJson(Map<String, dynamic> json) {
    return PickupCodeLookupDto(
      pickupCode: json['pickup_code'] as String,
      customerName: json['customer_name'] as String,
      maskedWalletNumber: json['masked_wallet_number'] as String,
      walletId: json['wallet_id'] as String,
      amountMinorUnits: json['amount_minor_units'] as int,
      currencyCode: json['currency_code'] as String,
      expiresAt: DateTime.parse(json['expires_at'] as String),
    );
  }

  PickupCodeLookup toEntity() => PickupCodeLookup(
        pickupCode: pickupCode,
        customerName: customerName,
        maskedWalletNumber: maskedWalletNumber,
        walletId: walletId,
        amountMinorUnits: amountMinorUnits,
        currencyCode: currencyCode,
        expiresAt: expiresAt,
      );
}

class CashOutResultDto {
  final String referenceNumber;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String customerName;
  final String agentName;
  final String agentLocationCode;
  final DateTime timestamp;
  final String status;

  const CashOutResultDto({
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

  factory CashOutResultDto.fromJson(Map<String, dynamic> json) {
    return CashOutResultDto(
      referenceNumber: json['reference_number'] as String,
      amountMinorUnits: json['amount_minor_units'] as int,
      feeMinorUnits: json['fee_minor_units'] as int,
      currencyCode: json['currency_code'] as String,
      customerName: json['customer_name'] as String,
      agentName: json['agent_name'] as String,
      agentLocationCode: json['agent_location_code'] as String,
      timestamp: DateTime.parse(json['timestamp'] as String),
      status: json['status'] as String,
    );
  }

  CashOutResult toEntity() => CashOutResult(
        referenceNumber: referenceNumber,
        amountMinorUnits: amountMinorUnits,
        feeMinorUnits: feeMinorUnits,
        currencyCode: currencyCode,
        customerName: customerName,
        agentName: agentName,
        agentLocationCode: agentLocationCode,
        timestamp: timestamp,
        status: status,
      );
}
