import 'package:wallet_agent/modules/cash_in/domain/entities/cash_in_result.dart';
import 'package:wallet_agent/modules/cash_in/domain/entities/customer_lookup.dart';

class CashInRequestDto {
  final String walletId;
  final int amountMinorUnits;
  final String currencyCode;
  final String pinHash;

  const CashInRequestDto({
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

class CustomerLookupDto {
  final String walletId;
  final String customerName;
  final String maskedWalletNumber;
  final String accountStatus;

  const CustomerLookupDto({
    required this.walletId,
    required this.customerName,
    required this.maskedWalletNumber,
    required this.accountStatus,
  });

  factory CustomerLookupDto.fromJson(Map<String, dynamic> json) {
    return CustomerLookupDto(
      walletId: json['wallet_id'] as String,
      customerName: json['customer_name'] as String,
      maskedWalletNumber: json['masked_wallet_number'] as String,
      accountStatus: json['account_status'] as String,
    );
  }

  CustomerLookup toEntity() => CustomerLookup(
        walletId: walletId,
        customerName: customerName,
        maskedWalletNumber: maskedWalletNumber,
        accountStatus: accountStatus,
      );
}

class CashInResultDto {
  final String referenceNumber;
  final int amountMinorUnits;
  final int feeMinorUnits;
  final String currencyCode;
  final String customerName;
  final String agentName;
  final String agentLocationCode;
  final DateTime timestamp;
  final String status;

  const CashInResultDto({
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

  factory CashInResultDto.fromJson(Map<String, dynamic> json) {
    return CashInResultDto(
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

  CashInResult toEntity() => CashInResult(
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
