enum WalletStatus { active, suspended, closed }

class Wallet {
  final String id;
  final String walletNumber;
  final String currencyCode;
  final int balanceMinorUnits;
  final WalletStatus status;
  final DateTime updatedAt;

  const Wallet({
    required this.id,
    required this.walletNumber,
    required this.currencyCode,
    required this.balanceMinorUnits,
    required this.status,
    required this.updatedAt,
  });

  double get balanceMajor => balanceMinorUnits / 100.0;
}

class WalletLimits {
  final int minFundMinorUnits;
  final int maxFundMinorUnits;
  final int maxDailyTransferMinorUnits;
  final int minWithdrawalMinorUnits;
  final int dailyUsedMinorUnits;

  const WalletLimits({
    required this.minFundMinorUnits,
    required this.maxFundMinorUnits,
    required this.maxDailyTransferMinorUnits,
    required this.minWithdrawalMinorUnits,
    required this.dailyUsedMinorUnits,
  });
}
