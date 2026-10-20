class DashboardSummary {
  final int floatBalanceMinorUnits;
  final String floatCurrencyCode;
  final int lowFloatThresholdMinorUnits;
  final int todayCashInCount;
  final int todayCashInAmountMinorUnits;
  final int todayCashOutCount;
  final int todayCashOutAmountMinorUnits;
  final DateTime updatedAt;

  const DashboardSummary({
    required this.floatBalanceMinorUnits,
    required this.floatCurrencyCode,
    required this.lowFloatThresholdMinorUnits,
    required this.todayCashInCount,
    required this.todayCashInAmountMinorUnits,
    required this.todayCashOutCount,
    required this.todayCashOutAmountMinorUnits,
    required this.updatedAt,
  });

  bool get isLow => floatBalanceMinorUnits <= lowFloatThresholdMinorUnits;
}
