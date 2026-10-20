import 'package:wallet_agent/modules/dashboard/domain/entities/dashboard_summary.dart';

class DashboardDto {
  final int floatBalanceMinorUnits;
  final String floatCurrencyCode;
  final int lowFloatThresholdMinorUnits;
  final int todayCashInCount;
  final int todayCashInAmountMinorUnits;
  final int todayCashOutCount;
  final int todayCashOutAmountMinorUnits;
  final DateTime updatedAt;

  const DashboardDto({
    required this.floatBalanceMinorUnits,
    required this.floatCurrencyCode,
    required this.lowFloatThresholdMinorUnits,
    required this.todayCashInCount,
    required this.todayCashInAmountMinorUnits,
    required this.todayCashOutCount,
    required this.todayCashOutAmountMinorUnits,
    required this.updatedAt,
  });

  factory DashboardDto.fromJson(Map<String, dynamic> json) {
    return DashboardDto(
      floatBalanceMinorUnits: json['float_balance_minor_units'] as int,
      floatCurrencyCode: json['float_currency_code'] as String,
      lowFloatThresholdMinorUnits:
          json['low_float_threshold_minor_units'] as int,
      todayCashInCount: json['today_cash_in_count'] as int,
      todayCashInAmountMinorUnits:
          json['today_cash_in_amount_minor_units'] as int,
      todayCashOutCount: json['today_cash_out_count'] as int,
      todayCashOutAmountMinorUnits:
          json['today_cash_out_amount_minor_units'] as int,
      updatedAt: DateTime.parse(json['updated_at'] as String),
    );
  }

  DashboardSummary toEntity() => DashboardSummary(
        floatBalanceMinorUnits: floatBalanceMinorUnits,
        floatCurrencyCode: floatCurrencyCode,
        lowFloatThresholdMinorUnits: lowFloatThresholdMinorUnits,
        todayCashInCount: todayCashInCount,
        todayCashInAmountMinorUnits: todayCashInAmountMinorUnits,
        todayCashOutCount: todayCashOutCount,
        todayCashOutAmountMinorUnits: todayCashOutAmountMinorUnits,
        updatedAt: updatedAt,
      );
}
